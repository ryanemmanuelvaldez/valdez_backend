<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class RentalRequests extends Controller
{
    public function create($rental_id)
    {
        $this->require_method('POST');
        $user = $this->current_user();
        if (!$user) {
            $this->respond(401, ['success' => false, 'message' => 'Sign in as a tenant to request a rental.']);
        }
        if ($user['role'] === 'admin' || $user['account_type'] !== 'tenant') {
            $this->respond(403, ['success' => false, 'message' => 'Only tenant accounts can request a rental.']);
        }

        $input = $this->json_input();
        $move_in = trim((string) ($input['move_in'] ?? ''));
        $occupants = filter_var($input['occupants'] ?? null, FILTER_VALIDATE_INT);
        $term_months = filter_var($input['term_months'] ?? null, FILTER_VALIDATE_INT);
        $message = trim((string) ($input['message'] ?? ''));
        $agreement_accepted = ($input['agreement_accepted'] ?? null) === true;
        $agreement_version = trim((string) ($input['agreement_version'] ?? ''));
        $current_agreement_version = 'rental-request-v1-2026-11';

        $move_in_date = DateTime::createFromFormat('!Y-m-d', $move_in);
        if (!$move_in_date || $move_in_date->format('Y-m-d') !== $move_in || $move_in < date('Y-m-d')) {
            $this->respond(422, ['success' => false, 'message' => 'Choose a valid move-in date today or later.']);
        }
        if ($occupants === false || $occupants < 1 || $occupants > 500) {
            $this->respond(422, ['success' => false, 'message' => 'Enter a valid number of occupants.']);
        }
        if ($term_months === false || $term_months < 1 || $term_months > 36) {
            $this->respond(422, ['success' => false, 'message' => 'Choose a rental term between 1 and 36 months.']);
        }
        if (strlen($message) > 2000) {
            $this->respond(422, ['success' => false, 'message' => 'Keep your message under 2000 characters.']);
        }
        if (!$agreement_accepted || $agreement_version !== $current_agreement_version) {
            $this->respond(422, ['success' => false, 'message' => 'Read and accept the rental request and privacy agreement before submitting.']);
        }

        $db = $this->call->database();
        $rental = $db->table('rentals')
            ->where('id', (int) $rental_id)
            ->where('status', 'published')
            ->row_array();
        if (!$rental) {
            $this->respond(404, ['success' => false, 'message' => 'This rental is no longer available.']);
        }
        if ((int) $rental['owner_id'] === (int) $user['id']) {
            $this->respond(403, ['success' => false, 'message' => 'You cannot request your own rental listing.']);
        }
        if ($occupants > (int) $rental['max_occupants']) {
            $this->respond(422, ['success' => false, 'message' => 'The number of occupants exceeds this listing’s capacity.']);
        }
        if (!empty($rental['available_from']) && $move_in < $rental['available_from']) {
            $this->respond(422, ['success' => false, 'message' => 'Choose a move-in date on or after the listing availability date.']);
        }

        require_once APP_DIR . 'libraries' . DIRECTORY_SEPARATOR . 'RentalCapacityService.php';
        $capacity_service = new RentalCapacityService($db);
        $db->transaction();
        $db->raw('SELECT id FROM users WHERE id = ? FOR UPDATE', [(int) $user['id']])->fetch(PDO::FETCH_ASSOC);
        $locked_rental = $capacity_service->lockRental((int) $rental_id);
        if (!$locked_rental || $locked_rental['status'] !== 'published') {
            $db->roll_back();
            $this->respond(409, ['success' => false, 'message' => 'This rental is no longer available.']);
        }

        $overdue_installment = $db->raw(
            'SELECT ri.id, r.title AS rental_title, ri.due_date FROM rental_requests rr '
            . 'INNER JOIN rental_payments rp ON rp.rental_request_id = rr.id AND rp.status = ? '
            . 'INNER JOIN rental_installments ri ON ri.rental_request_id = rr.id '
            . 'INNER JOIN rentals r ON r.id = rr.rental_id '
            . 'WHERE rr.tenant_id = ? AND rr.status = ? AND COALESCE(rr.tenancy_status, ?) <> ? '
            . 'AND ri.status <> ? AND ri.due_date <= ? LIMIT 1',
            ['paid', (int) $user['id'], 'approved', 'active', 'ended', 'paid', date('Y-m-d')]
        )->fetch(PDO::FETCH_ASSOC);
        if ($overdue_installment) {
            $db->roll_back();
            $this->respond(409, [
                'success' => false,
                'message' => 'You cannot request another rental while rent is unpaid on or after its contract due date. Pay the overdue installment and have the owner confirm receipt first.',
                'rental_title' => $overdue_installment['rental_title'],
                'due_date' => $overdue_installment['due_date'],
            ]);
        }

        $tenant_request = $db->raw(
            'SELECT id FROM rental_requests WHERE rental_id = ? AND tenant_id = ? '
            . 'AND status IN (?, ?, ?) AND COALESCE(tenancy_status, ?) <> ? LIMIT 1',
            [(int) $rental_id, (int) $user['id'], 'pending', 'awaiting_payment', 'approved', 'active', 'ended']
        )->fetch(PDO::FETCH_ASSOC);
        if ($tenant_request) {
            $db->roll_back();
            $this->respond(409, ['success' => false, 'message' => 'You already have an active request for this rental.']);
        }

        $capacity = $capacity_service->capacity($locked_rental);
        $occupied = $capacity_service->occupiedSlots((int) $rental_id);
        if ($capacity < 1 || $occupied + $occupants > $capacity) {
            $db->roll_back();
            $available_slots = max(0, $capacity - $occupied);
            $this->respond(409, [
                'success' => false,
                'message' => $available_slots > 0
                    ? 'Only ' . $available_slots . ' bed' . ($available_slots === 1 ? '' : 's') . ' remain available.'
                    : 'This rental is fully reserved.',
                'available_slots' => $available_slots,
            ]);
        }

        $id = $db->table('rental_requests')->insert([
            'rental_id' => (int) $rental_id,
            'tenant_id' => (int) $user['id'],
            'owner_id' => (int) $rental['owner_id'],
            'move_in' => $move_in,
            'move_out' => null,
            'occupants' => $occupants,
            'term_months' => $term_months,
            'message' => $message,
            'status' => 'pending',
            'rental_agreement_accepted_at' => date('Y-m-d H:i:s'),
            'rental_agreement_version' => $current_agreement_version,
        ]);
        $db->commit();

        $this->respond(201, [
            'success' => true,
            'message' => 'Rental request sent to the property owner.',
            'request_id' => (int) $id,
        ]);
    }

    public function mine()
    {
        $this->require_method('GET');
        $user = $this->current_user();
        if (!$user) {
            $this->respond(401, ['success' => false, 'message' => 'Sign in to view your rental requests.']);
        }
        if ($user['role'] === 'admin' || $user['account_type'] !== 'tenant') {
            $this->respond(403, ['success' => false, 'message' => 'Only tenant accounts can view tenant requests.']);
        }

        $requests = $this->call->database()
            ->table('rental_requests')
            ->where('tenant_id', (int) $user['id'])
            ->order_by('created_at', 'DESC')
            ->limit(100)
            ->get_all();
        foreach ($requests as &$request) {
            $rental = $this->call->database()
                ->table('rentals')
                ->where('id', (int) $request['rental_id'])
                ->row_array();
            $request['rental_title'] = $rental['title'] ?? 'Unavailable rental';
            $request['location'] = $rental['location'] ?? '';
            $request['price'] = $rental['price'] ?? '0.00';
            $request['security_deposit'] = $rental['security_deposit'] ?? '0.00';
            $request['property_type'] = $rental['property_type'] ?? '';
            $payment_profile = $this->call->database()
                ->table('owner_payment_profiles')
                ->where('owner_id', (int) $request['owner_id'])
                ->row_array();
            $request['owner_gcash_qr_data'] = $payment_profile['gcash_qr_data'] ?? null;
            $request['owner_maya_qr_data'] = $payment_profile['maya_qr_data'] ?? null;
            $owner = $this->call->database()->table('users')->select('phone')->where('id', (int) $request['owner_id'])->row_array();
            $request['owner_phone'] = $owner['phone'] ?? '';
            $initial_payment = $this->call->database()->table('rental_payments')
                ->select('id, status, payment_method')
                ->where('rental_request_id', (int) $request['id'])
                ->row_array();
            $request['initial_payment_status'] = $initial_payment['status'] ?? null;
            $request['initial_payment_method'] = $initial_payment['payment_method'] ?? null;
            $request['initial_payment_receipt_available'] = $initial_payment
                ? (bool) $this->call->database()->raw(
                    'SELECT receipt_image IS NOT NULL FROM rental_payments WHERE id = ?',
                    [(int) $initial_payment['id']]
                )->fetchColumn()
                : false;
            $request['review'] = $this->call->database()
                ->table('rental_reviews')
                ->where('rental_request_id', (int) $request['id'])
                ->row_array();
        }
        unset($request);

        $this->respond(200, ['success' => true, 'requests' => $requests]);
    }

    public function for_owner()
    {
        $this->require_method('GET');
        $user = $this->current_user();
        if (!$user) {
            $this->respond(401, ['success' => false, 'message' => 'Sign in to view rental requests.']);
        }
        if ($user['role'] === 'admin' || $user['account_type'] !== 'owner') {
            $this->respond(403, ['success' => false, 'message' => 'Only property owners can view rental requests.']);
        }

        $requests = $this->call->database()
            ->table('rental_requests')
            ->where('owner_id', (int) $user['id'])
            ->order_by('created_at', 'DESC')
            ->limit(100)
            ->get_all();
        foreach ($requests as &$request) {
            $rental = $this->call->database()
                ->table('rentals')
                ->where('id', (int) $request['rental_id'])
                ->row_array();
            $tenant = $this->call->database()
                ->table('users')
                ->select('full_name, email, phone')
                ->where('id', (int) $request['tenant_id'])
                ->row_array();
            $request['rental_title'] = $rental['title'] ?? 'Unavailable rental';
            $request['location'] = $rental['location'] ?? '';
            $request['price'] = $rental['price'] ?? '0.00';
            $request['tenant_name'] = $tenant['full_name'] ?? 'Tenant';
            $request['tenant_email'] = $tenant['email'] ?? '';
            $request['tenant_phone'] = $tenant['phone'] ?? '';
            $payment = $this->call->database()->raw(
                'SELECT status, amount_centavos, paid_at, payment_method, '
                . 'receipt_image IS NOT NULL AS receipt_available '
                . 'FROM rental_payments WHERE rental_request_id = ?',
                [(int) $request['id']]
            )->fetch(PDO::FETCH_ASSOC);
            $request['payment_status'] = $payment['status'] ?? null;
            $request['payment_amount_centavos'] = $payment['amount_centavos'] ?? null;
            $request['payment_paid_at'] = $payment['paid_at'] ?? null;
            $request['payment_method'] = $payment['payment_method'] ?? null;
            $request['payment_receipt_available'] = !empty($payment['receipt_available']);
        }
        unset($request);

        $this->respond(200, ['success' => true, 'requests' => $requests]);
    }

    public function renters()
    {
        $this->require_method('GET');
        $user = $this->current_user();
        if (!$user) $this->respond(401, ['success' => false, 'message' => 'Sign in to monitor your renters.']);
        if ($user['role'] === 'admin' || $user['account_type'] !== 'owner' || $user['owner_status'] !== 'verified') {
            $this->respond(403, ['success' => false, 'message' => 'A verified property owner account is required.']);
        }

        $renters = $this->call->database()->raw(
            'SELECT rr.id, rr.rental_id, rr.tenant_id, rr.move_in, rr.move_out, rr.occupants, rr.term_months, '
            . 'rr.tenancy_status, rr.owner_notes, rr.renter_display_name, rr.renter_contact_phone, rr.renter_contact_email, rr.created_at, '
            . 'r.title AS rental_title, r.location, r.price, '
            . 'u.full_name AS tenant_name, u.email AS tenant_email, u.phone AS tenant_phone, '
            . 'rp.status AS payment_status, rp.amount_centavos AS initial_amount_centavos, '
            . 'rp.payment_method AS payment_method, rp.paid_at AS initial_paid_at, '
            . '(SELECT COALESCE(SUM(i.amount_centavos), 0) FROM rental_installments i '
            . 'WHERE i.rental_request_id = rr.id AND i.status <> ?) AS outstanding_centavos, '
            . '(SELECT COUNT(*) FROM rental_installments i WHERE i.rental_request_id = rr.id) AS schedule_installments, '
            . '(SELECT COUNT(*) FROM rental_installments i WHERE i.rental_request_id = rr.id '
            . 'AND i.status <> ? AND i.due_date < ?) AS overdue_installments '
            . 'FROM rental_requests rr '
            . 'INNER JOIN rentals r ON r.id = rr.rental_id '
            . 'INNER JOIN users u ON u.id = rr.tenant_id '
            . 'INNER JOIN rental_payments rp ON rp.rental_request_id = rr.id AND rp.status = ? '
            . 'WHERE rr.owner_id = ? AND rr.status = ? '
            . 'ORDER BY CASE WHEN rr.tenancy_status = ? THEN 1 ELSE 0 END, rr.created_at DESC',
            ['paid', 'paid', date('Y-m-d'), 'paid', (int) $user['id'], 'approved', 'ended']
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($renters as &$renter) {
            $renter['id'] = (int) $renter['id'];
            $renter['occupants'] = (int) $renter['occupants'];
            $renter['term_months'] = (int) $renter['term_months'];
            $renter['initial_amount_centavos'] = (int) $renter['initial_amount_centavos'];
            $renter['outstanding_centavos'] = (int) $renter['outstanding_centavos'];
            $renter['schedule_installments'] = (int) $renter['schedule_installments'];
            $renter['overdue_installments'] = (int) $renter['overdue_installments'];
            $renter['display_name'] = $renter['renter_display_name'] ?: $renter['tenant_name'];
            $renter['contact_phone'] = $renter['renter_contact_phone'] ?: $renter['tenant_phone'];
            $renter['contact_email'] = $renter['renter_contact_email'] ?: $renter['tenant_email'];
            $renter['tenancy_status'] = $renter['tenancy_status'] ?: 'active';
        }
        unset($renter);

        $this->respond(200, ['success' => true, 'renters' => $renters]);
    }

    public function update_renter($request_id)
    {
        $this->require_method('POST');
        $user = $this->current_user();
        if (!$user) $this->respond(401, ['success' => false, 'message' => 'Sign in to update renter details.']);
        if ($user['role'] === 'admin' || $user['account_type'] !== 'owner' || $user['owner_status'] !== 'verified') {
            $this->respond(403, ['success' => false, 'message' => 'A verified property owner account is required.']);
        }

        $input = $this->json_input();
        $display_name = trim((string) ($input['display_name'] ?? ''));
        $contact_phone = trim((string) ($input['contact_phone'] ?? ''));
        $contact_email = trim((string) ($input['contact_email'] ?? ''));
        $owner_notes = trim((string) ($input['owner_notes'] ?? ''));
        $tenancy_status = (string) ($input['tenancy_status'] ?? '');
        if ($display_name === '' || strlen($display_name) > 160) {
            $this->respond(422, ['success' => false, 'message' => 'Enter a renter name up to 160 characters.']);
        }
        if (strlen($contact_phone) > 30) {
            $this->respond(422, ['success' => false, 'message' => 'Keep the renter contact number under 30 characters.']);
        }
        if ($contact_email !== '' && (strlen($contact_email) > 254 || !filter_var($contact_email, FILTER_VALIDATE_EMAIL))) {
            $this->respond(422, ['success' => false, 'message' => 'Enter a valid contact email address up to 254 characters.']);
        }
        if (strlen($owner_notes) > 2000) {
            $this->respond(422, ['success' => false, 'message' => 'Keep owner notes under 2000 characters.']);
        }
        if (!in_array($tenancy_status, ['active', 'notice', 'maintenance'], true)) {
            $this->respond(422, ['success' => false, 'message' => 'Choose Active, Move-out notice, or Maintenance.']);
        }

        $db = $this->call->database();
        $renter = $db->table('rental_requests')
            ->where('id', (int) $request_id)
            ->where('owner_id', (int) $user['id'])
            ->where('status', 'approved')
            ->row_array();
        if (!$renter) $this->respond(404, ['success' => false, 'message' => 'This renter record was not found.']);
        $paid_initial = $db->table('rental_payments')
            ->select('id')
            ->where('rental_request_id', (int) $request_id)
            ->where('status', 'paid')
            ->row_array();
        if (!$paid_initial) $this->respond(404, ['success' => false, 'message' => 'This renter record was not found.']);
        if (($renter['tenancy_status'] ?? 'active') === 'ended') {
            $this->respond(409, ['success' => false, 'message' => 'This tenancy has ended and can no longer be edited.']);
        }

        $db->table('rental_requests')->where('id', (int) $request_id)->update([
            'renter_display_name' => $display_name,
            'renter_contact_phone' => $contact_phone !== '' ? $contact_phone : null,
            'renter_contact_email' => $contact_email !== '' ? $contact_email : null,
            'owner_notes' => $owner_notes !== '' ? $owner_notes : null,
            'tenancy_status' => $tenancy_status,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->respond(200, ['success' => true, 'message' => 'Renter monitoring details updated.']);
    }

    public function end_renter($request_id)
    {
        $this->require_method('POST');
        $user = $this->current_user();
        if (!$user) $this->respond(401, ['success' => false, 'message' => 'Sign in to end a tenancy.']);
        if ($user['role'] === 'admin' || $user['account_type'] !== 'owner' || $user['owner_status'] !== 'verified') {
            $this->respond(403, ['success' => false, 'message' => 'A verified property owner account is required.']);
        }

        $db = $this->call->database();
        $renter = $db->table('rental_requests')
            ->where('id', (int) $request_id)
            ->where('owner_id', (int) $user['id'])
            ->where('status', 'approved')
            ->row_array();
        if (!$renter) $this->respond(404, ['success' => false, 'message' => 'This renter record was not found.']);
        $paid_initial = $db->table('rental_payments')
            ->select('id')
            ->where('rental_request_id', (int) $request_id)
            ->where('status', 'paid')
            ->row_array();
        if (!$paid_initial) $this->respond(404, ['success' => false, 'message' => 'This renter record was not found.']);
        if (($renter['tenancy_status'] ?? 'active') === 'ended') {
            $this->respond(409, ['success' => false, 'message' => 'This tenancy has already ended.']);
        }

        require_once APP_DIR . 'libraries' . DIRECTORY_SEPARATOR . 'RentalCapacityService.php';
        $capacity_service = new RentalCapacityService($db);
        $db->transaction();
        $rental = $capacity_service->lockRental((int) $renter['rental_id']);
        if (!$rental) {
            $db->roll_back();
            $this->respond(409, ['success' => false, 'message' => 'The rental listing could not be found.']);
        }
        $db->table('rental_requests')->where('id', (int) $request_id)->update([
            'tenancy_status' => 'ended',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        if ($rental['status'] === 'rented'
            && $capacity_service->occupiedSlots((int) $renter['rental_id']) < $capacity_service->capacity($rental)) {
            $db->table('rentals')
                ->where('id', (int) $renter['rental_id'])
                ->where('status', 'rented')
                ->update(['status' => 'published', 'updated_at' => date('Y-m-d H:i:s')]);
        }
        $db->commit();
        $this->respond(200, ['success' => true, 'message' => 'Tenancy ended. The tenant account and rental/payment history were kept.']);
    }

    public function review($request_id)
    {
        $this->require_method('POST');
        $user = $this->current_user();
        if (!$user) {
            $this->respond(401, ['success' => false, 'message' => 'Sign in to review rental requests.']);
        }
        if ($user['role'] === 'admin' || $user['account_type'] !== 'owner' || $user['owner_status'] !== 'verified') {
            $this->respond(403, ['success' => false, 'message' => 'A verified property owner account is required.']);
        }

        $input = $this->json_input();
        $decision = $input['decision'] ?? '';
        if (!in_array($decision, ['approve', 'decline'], true)) {
            $this->respond(422, ['success' => false, 'message' => 'Choose approve or decline.']);
        }

        $db = $this->call->database();
        $rental_request = $db->table('rental_requests')
            ->where('id', (int) $request_id)
            ->where('owner_id', (int) $user['id'])
            ->where('status', 'pending')
            ->row_array();
        if (!$rental_request) {
            $this->respond(404, ['success' => false, 'message' => 'This pending rental request was not found.']);
        }

        if ($decision === 'approve') {
            require_once APP_DIR . 'libraries' . DIRECTORY_SEPARATOR . 'RentalCapacityService.php';
            $capacity_service = new RentalCapacityService($db);
            $db->transaction();
            $locked_rental = $capacity_service->lockRental((int) $rental_request['rental_id']);
            $rental = $db->table('rentals')
                ->where('id', (int) $rental_request['rental_id'])
                ->where('owner_id', (int) $user['id'])
                ->where('status', 'published')
                ->row_array();
            if (!$rental || !$locked_rental || $locked_rental['status'] !== 'published') {
                $db->roll_back();
                $this->respond(409, ['success' => false, 'message' => 'This rental is no longer available.']);
            }
            if ($capacity_service->occupiedSlots((int) $rental_request['rental_id']) > $capacity_service->capacity($locked_rental)) {
                $db->roll_back();
                $this->respond(409, ['success' => false, 'message' => 'This listing has more pending reservations than its available bed capacity. Decline an extra request before approving another.']);
            }

            $db->table('rental_requests')
                ->where('id', (int) $request_id)
                ->where('status', 'pending')
                ->update(['status' => 'awaiting_payment', 'updated_at' => date('Y-m-d H:i:s')]);
            $db->commit();
            $this->respond(200, ['success' => true, 'message' => 'Request approved. The tenant can now pay the first month’s rent and security deposit. Other available beds remain open to additional tenants.']);
        }

        $db->table('rental_requests')
            ->where('id', (int) $request_id)
            ->update(['status' => 'declined', 'updated_at' => date('Y-m-d H:i:s')]);
        $this->respond(200, ['success' => true, 'message' => 'Rental request declined.']);
    }

    private function current_user()
    {
        $this->call->library('session');
        $user_id = (int) load_class('session', 'libraries')->userdata('user_id');
        if ($user_id < 1) {
            return null;
        }

        $user = $this->call->database()
            ->table('users')
            ->where('id', $user_id)
            ->row_array();
        return $user && (bool) $user['is_active'] ? $user : null;
    }

    private function json_input()
    {
        if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') === false) {
            $this->respond(415, ['success' => false, 'message' => 'Send the request as JSON.']);
        }
        $input = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($input)) {
            $this->respond(400, ['success' => false, 'message' => 'The request body must be valid JSON.']);
        }
        return $input;
    }

    private function require_method($method)
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {
            header('Allow: ' . $method);
            $this->respond(405, ['success' => false, 'message' => 'This request method is not allowed.']);
        }
    }

    private function respond($status, array $body)
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
}
