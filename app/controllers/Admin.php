<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Admin extends Controller
{
    public function overview()
    {
        $this->require_admin('GET');
        $db = $this->call->database();

        $this->respond(200, [
            'success' => true,
            'overview' => [
                'users' => $db->table('users')->count(),
                'owners_pending' => $db->table('users')
                    ->where('account_type', 'owner')
                    ->where('owner_status', 'pending')
                    ->count(),
                'listings_published' => $db->table('rentals')
                    ->where('status', 'published')
                    ->count(),
                'listings_pending' => $db->table('rentals')
                    ->where('status', 'pending')
                    ->count(),
                'listings_featured' => $db->table('rentals')
                    ->where('status', 'published')
                    ->where('is_featured', 1)
                    ->count(),
                'rental_requests' => $db->table('rental_requests')->count(),
                'requests_pending' => $db->table('rental_requests')
                    ->where('status', 'pending')
                    ->count(),
                'tickets_open' => $db->table('support_tickets')
                    ->where('status', 'open')
                    ->count(),
                'payments_recorded' => $db->table('rental_payments')
                    ->where('status', 'paid')
                    ->count() + $db->table('rental_installments')
                    ->where('status', 'paid')
                    ->where('installment_number', '>', 1)
                    ->count(),
            ],
        ]);
    }

    public function reservations()
    {
        $this->require_admin('GET');
        $db = $this->call->database();
        $requests = $db->table('rental_requests')
            ->order_by('created_at', 'DESC')
            ->limit(500)
            ->get_all();
        foreach ($requests as &$request) {
            $rental = $db->table('rentals')->where('id', (int) $request['rental_id'])->row_array();
            $tenant = $db->table('users')->select('full_name, email')->where('id', (int) $request['tenant_id'])->row_array();
            $owner = $db->table('users')->select('full_name, email')->where('id', (int) $request['owner_id'])->row_array();
            $initial_payment = $db->table('rental_payments')
                ->select('status, amount_centavos')
                ->where('rental_request_id', (int) $request['id'])
                ->row_array();
            $request['rental_title'] = $rental['title'] ?? 'Deleted rental';
            $request['tenant_name'] = $tenant['full_name'] ?? 'Deleted tenant';
            $request['tenant_email'] = $tenant['email'] ?? '';
            $request['owner_name'] = $owner['full_name'] ?? 'Deleted owner';
            $request['owner_email'] = $owner['email'] ?? '';
            $request['initial_payment_status'] = $initial_payment['status'] ?? 'not recorded';
            $request['initial_payment_centavos'] = $initial_payment['amount_centavos'] ?? null;
            $request['installments'] = $db->table('rental_installments')
                ->select('id, rental_request_id, installment_number, due_date, amount_centavos, status')
                ->where('rental_request_id', (int) $request['id'])
                ->order_by('installment_number', 'ASC')
                ->get_all();
        }
        unset($request);
        $this->respond(200, ['success' => true, 'reservations' => $requests]);
    }

    public function review_reservation($id)
    {
        $this->require_admin('POST');
        $input = $this->json_input();
        $decision = (string) ($input['decision'] ?? '');
        if (!in_array($decision, ['approve', 'decline'], true)) {
            $this->respond(422, ['success' => false, 'message' => 'Choose approve or decline.']);
        }
        $db = $this->call->database();
        $rental_request = $db->table('rental_requests')->where('id', (int) $id)->where('status', 'pending')->row_array();
        if (!$rental_request) $this->respond(404, ['success' => false, 'message' => 'Pending reservation was not found.']);
        if ($decision === 'approve') {
            require_once APP_DIR . 'libraries' . DIRECTORY_SEPARATOR . 'RentalCapacityService.php';
            $capacity_service = new RentalCapacityService($db);
            $db->transaction();
            $locked_rental = $capacity_service->lockRental((int) $rental_request['rental_id']);
            $rental = $db->table('rentals')
                ->where('id', (int) $rental_request['rental_id'])
                ->where('status', 'published')
                ->row_array();
            if (!$rental || !$locked_rental || $locked_rental['status'] !== 'published') {
                $db->roll_back();
                $this->respond(409, ['success' => false, 'message' => 'The listing is no longer available.']);
            }
            if ($capacity_service->occupiedSlots((int) $rental_request['rental_id']) > $capacity_service->capacity($locked_rental)) {
                $db->roll_back();
                $this->respond(409, ['success' => false, 'message' => 'This listing has more pending reservations than its available bed capacity.']);
            }
            $db->table('rental_requests')->where('id', (int) $id)->where('status', 'pending')
                ->update(['status' => 'awaiting_payment', 'updated_at' => date('Y-m-d H:i:s')]);
            $db->commit();
            $this->respond(200, ['success' => true, 'message' => 'Reservation approved and tenant payment requested. Other available beds remain open.']);
        }
        $db->table('rental_requests')->where('id', (int) $id)->update(['status' => 'declined', 'updated_at' => date('Y-m-d H:i:s')]);
        $this->respond(200, ['success' => true, 'message' => 'Reservation declined.']);
    }

    public function reviews()
    {
        $this->require_admin('GET');
        $db = $this->call->database();
        $reviews = $db->table('rental_reviews')
            ->order_by('rating', 'DESC')
            ->order_by('created_at', 'DESC')
            ->limit(500)
            ->get_all();
        foreach ($reviews as &$review) {
            $tenant = $db->table('users')->select('full_name, email')->where('id', (int) $review['tenant_id'])->row_array();
            $rental = $db->table('rentals')->select('title, owner_id')->where('id', (int) $review['rental_id'])->row_array();
            $owner = $rental
                ? $db->table('users')->select('full_name')->where('id', (int) $rental['owner_id'])->row_array()
                : null;
            $review['tenant_name'] = $tenant['full_name'] ?? 'Deleted tenant';
            $review['tenant_email'] = $tenant['email'] ?? '';
            $review['rental_title'] = $rental['title'] ?? 'Deleted rental';
            $review['owner_name'] = $owner['full_name'] ?? 'Deleted owner';
        }
        unset($review);
        $top_rentals = $db->raw(
            'SELECT rental.id AS rental_id, rental.title AS rental_title, rental.owner_id, '
            . 'COALESCE(owner.full_name, owner.username) AS owner_name, '
            . 'AVG(review.rating) AS average_rating, COUNT(review.id) AS review_count '
            . 'FROM rental_reviews review '
            . 'INNER JOIN rentals rental ON rental.id = review.rental_id '
            . 'INNER JOIN users owner ON owner.id = rental.owner_id '
            . 'WHERE review.status = ? '
            . 'GROUP BY rental.id, rental.title, rental.owner_id, owner.full_name, owner.username '
            . 'ORDER BY average_rating DESC, review_count DESC, rental.title ASC LIMIT 20',
            ['approved']
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($top_rentals as &$rental) {
            $rental['rental_id'] = (int) $rental['rental_id'];
            $rental['average_rating'] = round((float) $rental['average_rating'], 2);
            $rental['review_count'] = (int) $rental['review_count'];
        }
        unset($rental);
        $this->respond(200, ['success' => true, 'reviews' => $reviews, 'top_rentals' => $top_rentals]);
    }

    public function moderate_review($id)
    {
        $this->require_admin('POST');
        $input = $this->json_input();
        $status = (string) ($input['status'] ?? '');
        $note = trim((string) ($input['moderation_note'] ?? ''));
        if (!in_array($status, ['approved', 'hidden', 'rejected'], true) || strlen($note) > 500) {
            $this->respond(422, ['success' => false, 'message' => 'Choose a valid review status and a moderation note under 500 characters.']);
        }
        $db = $this->call->database();
        $review = $db->table('rental_reviews')->where('id', (int) $id)->row_array();
        if (!$review) {
            $this->respond(404, ['success' => false, 'message' => 'Review not found.']);
        }
        $db->table('rental_reviews')->where('id', (int) $id)->update([
            'status' => $status,
            'moderation_note' => $note,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->respond(200, ['success' => true, 'message' => 'Review moderation updated.']);
    }

    public function support_tickets()
    {
        $this->require_admin('GET');
        $db = $this->call->database();
        $tickets = $db->table('support_tickets')
            ->order_by('created_at', 'DESC')
            ->limit(500)
            ->get_all();
        foreach ($tickets as &$ticket) {
            $user = $db->table('users')->select('full_name, email, account_type')->where('id', (int) $ticket['user_id'])->row_array();
            $ticket['user_name'] = $user['full_name'] ?? 'Deleted account';
            $ticket['user_email'] = $user['email'] ?? '';
            $ticket['account_type'] = $user['account_type'] ?? '';
        }
        unset($ticket);
        $this->respond(200, ['success' => true, 'tickets' => $tickets]);
    }

    public function update_support_ticket($id)
    {
        $this->require_admin('POST');
        $input = $this->json_input();
        $status = (string) ($input['status'] ?? '');
        $priority = (string) ($input['priority'] ?? '');
        $reply = trim((string) ($input['admin_reply'] ?? ''));
        if (!in_array($status, ['open', 'in_progress', 'resolved', 'closed'], true)
            || !in_array($priority, ['low', 'normal', 'high'], true)
            || strlen($reply) > 5000) {
            $this->respond(422, ['success' => false, 'message' => 'Choose a valid ticket status, priority, and reply.']);
        }
        $db = $this->call->database();
        if (!$db->table('support_tickets')->where('id', (int) $id)->row_array()) {
            $this->respond(404, ['success' => false, 'message' => 'Support ticket not found.']);
        }
        $db->table('support_tickets')->where('id', (int) $id)->update([
            'status' => $status,
            'priority' => $priority,
            'admin_reply' => $reply,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->respond(200, ['success' => true, 'message' => 'Support ticket updated.']);
    }

    public function announcements()
    {
        $this->require_admin('GET');
        $announcements = $this->call->database()->table('announcements')
            ->order_by('created_at', 'DESC')->limit(300)->get_all();
        $this->respond(200, ['success' => true, 'announcements' => $announcements]);
    }

    public function save_announcement($id = null)
    {
        $this->require_admin('POST');
        $input = $this->json_input();
        $title = trim((string) ($input['title'] ?? ''));
        $body = trim((string) ($input['body'] ?? ''));
        $audience = (string) ($input['audience'] ?? 'all');
        $status = (string) ($input['status'] ?? 'draft');
        if (strlen($title) < 4 || strlen($title) > 160 || strlen($body) < 10 || strlen($body) > 10000
            || !in_array($audience, ['all', 'tenant', 'owner'], true)
            || !in_array($status, ['draft', 'published'], true)) {
            $this->respond(422, ['success' => false, 'message' => 'Enter a valid title/body and choose an audience and publish status.']);
        }
        $user_id = $this->admin_user_id();
        $db = $this->call->database();
        $data = [
            'title' => $title,
            'body' => $body,
            'audience' => $audience,
            'status' => $status,
            'publish_at' => $status === 'published' ? date('Y-m-d H:i:s') : null,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if ($id !== null) {
            if (!$db->table('announcements')->where('id', (int) $id)->row_array()) {
                $this->respond(404, ['success' => false, 'message' => 'Announcement not found.']);
            }
            $db->table('announcements')->where('id', (int) $id)->update($data);
            $this->respond(200, ['success' => true, 'message' => 'Announcement updated.']);
        }
        $data['created_by'] = $user_id;
        $data['created_at'] = date('Y-m-d H:i:s');
        $new_id = $db->table('announcements')->insert($data);
        $this->respond(201, ['success' => true, 'message' => 'Announcement saved.', 'id' => (int) $new_id]);
    }

    public function delete_announcement($id)
    {
        $this->require_admin('POST');
        $db = $this->call->database();
        if (!$db->table('announcements')->where('id', (int) $id)->row_array()) {
            $this->respond(404, ['success' => false, 'message' => 'Announcement not found.']);
        }
        $db->table('announcements')->where('id', (int) $id)->delete();
        $this->respond(200, ['success' => true, 'message' => 'Announcement deleted.']);
    }

    public function analytics()
    {
        $this->require_admin('GET');
        $db = $this->call->database();
        $this->call->model('Sales_model');
        $sales = $this->Sales_model->platformSales();
        $this->respond(200, [
            'success' => true,
            'analytics' => [
                'users' => $db->table('users')->count(),
                'tenants' => $db->table('users')->where('account_type', 'tenant')->count(),
                'owners' => $db->table('users')->where('account_type', 'owner')->count(),
                'listings' => $db->table('rentals')->count(),
                'published_listings' => $db->table('rentals')->where('status', 'published')->count(),
                'rented_listings' => $db->table('rentals')->where('status', 'rented')->count(),
                'requests' => $db->table('rental_requests')->count(),
                'requests_awaiting_payment' => $db->table('rental_requests')->where('status', 'awaiting_payment')->count(),
                'paid_total_centavos' => $sales['total_centavos'],
                'paid_transactions' => $sales['transactions'],
                'monthly_payments' => $sales['monthly'],
                'owner_payments' => $sales['owner_payments'],
                'forecast' => $sales['forecast'],
            ],
        ]);
    }

    public function sales_analytics()
    {
        $this->require_admin('GET');
        $this->call->model('Sales_model');
        $this->respond(200, [
            'success' => true,
            'sales' => $this->Sales_model->platformSales(),
        ]);
    }

    public function reports()
    {
        $this->require_admin('GET');
        $db = $this->call->database();
        $payments = array_merge(
            $db->table('rental_payments')->select('id, rental_request_id, amount_centavos, status, paid_at')->where('status', 'paid')->order_by('paid_at', 'DESC')->limit(500)->get_all(),
            $db->table('rental_installments')->select('id, rental_request_id, installment_number, amount_centavos, status, paid_at')->where('status', 'paid')->where('installment_number', '>', 1)->order_by('paid_at', 'DESC')->limit(500)->get_all()
        );
        $requests = $db->table('rental_requests')->order_by('created_at', 'DESC')->limit(500)->get_all();
        $this->respond(200, ['success' => true, 'reports' => ['payments' => $payments, 'reservations' => $requests]]);
    }

    public function settings()
    {
        $this->require_admin('GET');
        $defaults = [
            'platform_name' => 'RentEase',
            'support_email' => '',
            'support_phone' => '',
            'rent_due_day' => '1',
            'terms_text' => 'First month rent and security deposit are collected before move-in. Subsequent rent follows the schedule shown in the tenant account.',
        ];
        $stored = $this->call->database()->table('platform_settings')->get_all();
        $settings = $defaults;
        foreach ($stored as $row) $settings[$row['setting_key']] = $row['setting_value'];
        $this->respond(200, ['success' => true, 'settings' => $settings]);
    }

    public function update_settings()
    {
        $this->require_admin('POST');
        $input = $this->json_input();
        $allowed = [
            'platform_name' => [2, 100],
            'support_email' => [0, 255],
            'support_phone' => [0, 30],
            'rent_due_day' => [1, 2],
            'terms_text' => [10, 5000],
        ];
        $values = [];
        foreach ($allowed as $key => [$minimum, $maximum]) {
            $value = trim((string) ($input[$key] ?? ''));
            if (strlen($value) < $minimum || strlen($value) > $maximum) {
                $this->respond(422, ['success' => false, 'message' => 'Check each system setting and keep its value within the allowed length.']);
            }
            if ($key === 'support_email' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $this->respond(422, ['success' => false, 'message' => 'Enter a valid support email address.']);
            }
            if ($key === 'rent_due_day' && ((int) $value < 1 || (int) $value > 28)) {
                $this->respond(422, ['success' => false, 'message' => 'Rent due day must be between 1 and 28.']);
            }
            $values[$key] = $value;
        }
        $db = $this->call->database();
        $admin_id = $this->admin_user_id();
        foreach ($values as $key => $value) {
            $existing = $db->table('platform_settings')->where('setting_key', $key)->row_array();
            $data = ['setting_value' => $value, 'updated_by' => $admin_id, 'updated_at' => date('Y-m-d H:i:s')];
            if ($existing) $db->table('platform_settings')->where('setting_key', $key)->update($data);
            else $db->table('platform_settings')->insert(array_merge(['setting_key' => $key], $data));
        }
        $this->respond(200, ['success' => true, 'message' => 'System settings saved.']);
    }

    public function users()
    {
        $this->require_admin('GET');
        $users = $this->call->database()
            ->table('users')
            ->select('id, username, email, role, is_active, full_name, phone, account_type, owner_status, created_at')
            ->order_by('created_at', 'DESC')
            ->limit(100)
            ->get_all();

        $users = array_map(static function ($user) {
            $user['id'] = (int) $user['id'];
            $user['is_active'] = (bool) $user['is_active'];
            $user['full_name'] = $user['full_name'] ?: $user['username'];
            return $user;
        }, $users);

        $this->respond(200, ['success' => true, 'users' => $users]);
    }

    public function create_user()
    {
        $this->require_admin('POST');
        $input = $this->json_input();
        $profile = $this->validate_user_input($input);
        $password = (string) ($input['password'] ?? '');
        if (strlen($password) < 8 || strlen($password) > 72) {
            $this->respond(422, ['success' => false, 'message' => 'Use a password between 8 and 72 characters.']);
        }

        $db = $this->call->database();
        if ($db->table('users')->where('email', $profile['email'])->row_array()) {
            $this->respond(409, ['success' => false, 'message' => 'An account with this email already exists.']);
        }
        $id = $db->table('users')->insert(array_merge($profile, [
            'username' => 'user_' . bin2hex(random_bytes(16)),
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'role' => 'user',
            'is_active' => 1,
            'owner_status' => $profile['account_type'] === 'owner' ? 'pending' : 'not_required',
        ]));
        $this->respond(201, ['success' => true, 'message' => 'Account created.', 'id' => (int) $id]);
    }

    public function update_user($id)
    {
        $this->require_admin('POST');
        $input = $this->json_input();
        $db = $this->call->database();
        $account = $db->table('users')->where('id', (int) $id)->row_array();
        if (!$account || $account['role'] === 'admin') {
            $this->respond(404, ['success' => false, 'message' => 'This editable account was not found.']);
        }
        $profile = $this->validate_user_input($input);
        $duplicate = $db->table('users')
            ->where('email', $profile['email'])
            ->where('id', '<>', (int) $id)
            ->row_array();
        if ($duplicate) {
            $this->respond(409, ['success' => false, 'message' => 'An account with this email already exists.']);
        }
        $profile['owner_status'] = $profile['account_type'] === 'owner'
            ? ($input['owner_status'] ?? ($account['account_type'] === 'owner' ? $account['owner_status'] : 'pending'))
            : 'not_required';
        if ($profile['account_type'] === 'owner' && $profile['owner_status'] === 'not_required') {
            $profile['owner_status'] = 'verified';
        }
        if (!in_array($profile['owner_status'], ['pending', 'verified', 'rejected', 'not_required'], true)) {
            $this->respond(422, ['success' => false, 'message' => 'Choose a valid owner verification status.']);
        }
        if ($profile['account_type'] === 'owner' && $profile['owner_status'] === 'verified'
            && ($account['account_type'] !== 'owner' || $account['owner_status'] !== 'verified')) {
            $document_types = $db->table('owner_verification_documents')
                ->select('document_type')
                ->where('owner_id', (int) $id)
                ->where('mime_type', 'application/pdf')
                ->get_all();
            $submitted_types = array_column($document_types, 'document_type');
            if (!in_array('government_id', $submitted_types, true) || !in_array('ownership_proof', $submitted_types, true)) {
                $this->respond(422, ['success' => false, 'message' => 'The owner must submit a government-issued ID and property ownership/management proof PDF before verification.']);
            }
        }
        $is_active = $input['is_active'] ?? null;
        if (!is_bool($is_active)) {
            $this->respond(422, ['success' => false, 'message' => 'Choose whether the account is active.']);
        }
        $profile['is_active'] = $is_active ? 1 : 0;
        $password = (string) ($input['password'] ?? '');
        if ($password !== '') {
            if (strlen($password) < 8 || strlen($password) > 72) {
                $this->respond(422, ['success' => false, 'message' => 'Use a password between 8 and 72 characters.']);
            }
            $profile['password'] = password_hash($password, PASSWORD_DEFAULT);
        }
        $profile['updated_at'] = date('Y-m-d H:i:s');
        $db->table('users')->where('id', (int) $id)->update($profile);
        $this->respond(200, ['success' => true, 'message' => 'Account updated.']);
    }

    public function delete_user($id)
    {
        $this->require_admin('POST');
        $db = $this->call->database();
        $account = $db->table('users')->where('id', (int) $id)->row_array();
        if (!$account || $account['role'] === 'admin') {
            $this->respond(404, ['success' => false, 'message' => 'This deletable account was not found.']);
        }
        $rentals = $db->table('rentals')->select('id')->where('owner_id', (int) $id)->get_all();
        $requests = [];
        foreach ($rentals as $rental) {
            $rental_requests = $db->table('rental_requests')
                ->where('rental_id', (int) $rental['id'])
                ->get_all();
            foreach ($rental_requests as $rental_request) {
                $requests[(int) $rental_request['id']] = $rental_request;
            }
        }
        $owner_requests = $db->table('rental_requests')->where('owner_id', (int) $id)->get_all();
        foreach ($owner_requests as $rental_request) {
            $requests[(int) $rental_request['id']] = $rental_request;
        }
        $tenant_requests = $db->table('rental_requests')->where('tenant_id', (int) $id)->get_all();
        foreach ($tenant_requests as $rental_request) {
            $requests[(int) $rental_request['id']] = $rental_request;
        }
        foreach ($requests as $rental_request) {
            $payment = $db->table('rental_payments')
                ->where('rental_request_id', (int) $rental_request['id'])
                ->row_array();
            if ($payment && in_array($payment['status'], ['creating', 'pending', 'paid'], true)) {
                $this->respond(409, ['success' => false, 'message' => 'This account has an active or completed payment record. Disable the account instead to preserve the payment and rental history.']);
            }
        }
        foreach ($requests as $rental_request) {
            $db->table('rental_payments')
                ->where('rental_request_id', (int) $rental_request['id'])
                ->delete();
            $db->table('rental_requests')->where('id', (int) $rental_request['id'])->delete();
        }
        $db->table('rentals')->where('owner_id', (int) $id)->delete();
        $db->table('users')->where('id', (int) $id)->delete();
        $this->respond(200, ['success' => true, 'message' => 'Account and its rental records deleted.']);
    }

    public function owners()
    {
        $this->require_admin('GET');
        $db = $this->call->database();
        $owners = $db
            ->table('users')
            ->where('account_type', 'owner')
            ->where('owner_status', 'pending')
            ->order_by('created_at', 'ASC')
            ->limit(100)
            ->get_all();

        $owners = array_map([$this, 'owner_summary'], $owners);
        foreach ($owners as &$owner) {
            $owner['documents'] = $db->table('owner_verification_documents')
                ->select('id, document_type, file_name, mime_type, created_at')
                ->where('owner_id', (int) $owner['id'])
                ->order_by('created_at', 'ASC')
                ->get_all();
            foreach ($owner['documents'] as &$document) {
                $document['id'] = (int) $document['id'];
            }
            unset($document);
        }
        unset($owner);

        $this->respond(200, ['success' => true, 'owners' => $owners]);
    }

    public function owner_document($owner_id, $document_id)
    {
        $this->require_admin('GET');
        $document = $this->call->database()->table('owner_verification_documents')
            ->where('id', (int) $document_id)
            ->where('owner_id', (int) $owner_id)
            ->row_array();
        if (!$document) {
            $this->respond(404, ['success' => false, 'message' => 'Owner verification document was not found.']);
        }

        header('Content-Type: ' . $document['mime_type']);
        header('Content-Length: ' . strlen($document['file_data']));
        $disposition = $document['mime_type'] === 'application/pdf' ? 'attachment' : 'inline';
        header('Content-Disposition: ' . $disposition . '; filename="' . str_replace(['"', "\r", "\n"], '', $document['file_name']) . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        echo $document['file_data'];
        exit;
    }

    public function review_owner($id)
    {
        $this->require_admin('POST');
        $input = $this->json_input();
        $decision = $input['decision'] ?? '';
        if (!in_array($decision, ['approve', 'reject'], true)) {
            $this->respond(422, ['success' => false, 'message' => 'Choose approve or reject.']);
        }

        $db = $this->call->database();
        $owner = $db->table('users')
            ->where('id', (int) $id)
            ->where('account_type', 'owner')
            ->where('owner_status', 'pending')
            ->row_array();
        if (!$owner) {
            $this->respond(404, ['success' => false, 'message' => 'This pending owner request was not found.']);
        }
        $documents = $db->table('owner_verification_documents')
            ->select('document_type')
            ->where('owner_id', (int) $id)
            ->where('mime_type', 'application/pdf')
            ->get_all();
        $document_types = array_column($documents, 'document_type');
        if ($decision === 'approve'
            && (!in_array('government_id', $document_types, true) || !in_array('ownership_proof', $document_types, true))) {
            $this->respond(409, ['success' => false, 'message' => 'Review the owner’s required PDF documents before verifying this account. Both government ID and proof of property ownership/management are required.']);
        }

        $db->table('users')
            ->where('id', (int) $id)
            ->update(['owner_status' => $decision === 'approve' ? 'verified' : 'rejected']);
        $this->respond(200, ['success' => true, 'message' => $decision === 'approve' ? 'Owner verified.' : 'Owner request rejected.']);
    }

    public function listings()
    {
        $this->require_admin('GET');
        $listings = $this->call->database()
            ->table('rentals')
            ->order_by('created_at', 'ASC')
            ->limit(100)
            ->get_all();
        foreach ($listings as &$listing) {
            $owner = $this->call->database()
                ->table('users')
                ->select('full_name, email')
                ->where('id', (int) $listing['owner_id'])
                ->row_array();
            $listing['owner_name'] = $owner['full_name'] ?? '';
            $listing['owner_email'] = $owner['email'] ?? '';
        }
        unset($listing);

        $this->respond(200, ['success' => true, 'listings' => $listings]);
    }

    public function property_locations()
    {
        $this->require_admin('GET');
        $locations = $this->call->database()
            ->table('rentals')
            ->select('id, owner_id, title, description, property_type, price, location, address, unit_number, rooms_count, bedrooms, bed_count, bathrooms, max_occupants, floor_area, furnishing, utilities_included, available_from, security_deposit, amenities, contact_phone, photo_url, updated_at, status, latitude, longitude')
            ->order_by('created_at', 'ASC')
            ->get_all();
        $owners = $this->call->database()
            ->table('users')
            ->select('id, full_name, email')
            ->get_all();
        $owner_details = [];
        foreach ($owners as $owner) {
            $owner_details[(int) $owner['id']] = [
                'name' => $owner['full_name'] ?? '',
                'email' => $owner['email'] ?? '',
            ];
        }
        foreach ($locations as &$location) {
            $owner = $owner_details[(int) $location['owner_id']] ?? [];
            $location['owner_name'] = $owner['name'] ?? '';
            $location['owner_email'] = $owner['email'] ?? '';
        }
        unset($location);

        $this->respond(200, ['success' => true, 'locations' => $locations]);
    }

    public function create_listing()
    {
        $this->require_admin('POST');
        $input = $this->listing_input();
        $listing = $this->validate_listing_input($input, 'upload_pending');
        $photo_service = $this->call->library('property_photo');
        try {
            $listing['photo_url'] = $photo_service->store($_FILES['property_photo'] ?? []);
        } catch (InvalidArgumentException $exception) {
            $this->respond(422, ['success' => false, 'message' => $exception->getMessage()]);
        }
        $listing['created_at'] = date('Y-m-d H:i:s');
        $listing['updated_at'] = date('Y-m-d H:i:s');
        try {
            $id = $this->call->database()->table('rentals')->insert($listing);
        } catch (Throwable $exception) {
            $photo_service->delete($listing['photo_url']);
            throw $exception;
        }
        if (!$id) {
            $photo_service->delete($listing['photo_url']);
            $this->respond(500, ['success' => false, 'message' => 'The rental listing could not be saved.']);
        }
        $this->respond(201, ['success' => true, 'message' => 'Rental listing created.', 'id' => (int) $id]);
    }

    public function update_listing($id)
    {
        $this->require_admin('POST');
        $db = $this->call->database();
        $existing = $db->table('rentals')->where('id', (int) $id)->row_array();
        if (!$existing) {
            $this->respond(404, ['success' => false, 'message' => 'This rental listing was not found.']);
        }
        $input = $this->listing_input();
        unset($input['photo_url']);
        $has_new_photo = isset($_FILES['property_photo'])
            && (int) ($_FILES['property_photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        $existing_photo = (string) $existing['photo_url'];
        $listing = $this->validate_listing_input($input, $has_new_photo ? 'upload_pending' : $existing_photo);
        $photo_service = $this->call->library('property_photo');
        $new_photo = null;
        if ($has_new_photo) {
            try {
                $new_photo = $photo_service->store($_FILES['property_photo']);
            } catch (InvalidArgumentException $exception) {
                $this->respond(422, ['success' => false, 'message' => $exception->getMessage()]);
            }
        }
        $listing['photo_url'] = $new_photo ?? $existing['photo_url'];
        $listing['updated_at'] = date('Y-m-d H:i:s');
        try {
            $db->table('rentals')->where('id', (int) $id)->update($listing);
        } catch (Throwable $exception) {
            if ($new_photo !== null) $photo_service->delete($new_photo);
            throw $exception;
        }
        if ($new_photo !== null && strpos((string) $existing['photo_url'], 'local:') === 0) {
            $photo_service->delete($existing['photo_url']);
        }
        $this->respond(200, ['success' => true, 'message' => 'Rental listing updated.']);
    }

    public function delete_listing($id)
    {
        $this->require_admin('POST');
        $db = $this->call->database();
        $listing = $db->table('rentals')->where('id', (int) $id)->row_array();
        if (!$listing) {
            $this->respond(404, ['success' => false, 'message' => 'This rental listing was not found.']);
        }
        $requests = $db->table('rental_requests')->where('rental_id', (int) $id)->get_all();
        foreach ($requests as $rental_request) {
            $payment = $db->table('rental_payments')
                ->where('rental_request_id', (int) $rental_request['id'])
                ->row_array();
            if ($payment && in_array($payment['status'], ['creating', 'pending', 'paid'], true)) {
                $this->respond(409, ['success' => false, 'message' => 'This listing has an active or completed payment record. It cannot be deleted so the payment and rental history remain intact.']);
            }
        }
        foreach ($requests as $rental_request) {
            $db->table('rental_payments')
                ->where('rental_request_id', (int) $rental_request['id'])
                ->delete();
            $db->table('rental_requests')->where('id', (int) $rental_request['id'])->delete();
        }
        $db->table('rentals')->where('id', (int) $id)->delete();
        if (strpos((string) $listing['photo_url'], 'local:') === 0) {
            $photo_service = $this->call->library('property_photo');
            $photo_service->delete($listing['photo_url']);
        }
        $this->respond(200, ['success' => true, 'message' => 'Rental listing and its request history deleted.']);
    }

    public function review_listing($id)
    {
        $this->require_admin('POST');
        $input = $this->json_input();
        $decision = $input['decision'] ?? '';
        if (!in_array($decision, ['approve', 'reject'], true)) {
            $this->respond(422, ['success' => false, 'message' => 'Choose approve or reject.']);
        }
        $db = $this->call->database();
        $listing = $db        ->table('rentals')
        ->where('id', (int) $id)
        ->where('status', 'pending')
        ->row_array();
        if (!$listing) {
            $this->respond(404, ['success' => false, 'message' => 'This pending rental was not found.']);
        }
        $db->table('rentals')
            ->where('id', (int) $id)
            ->update([
                'status' => $decision === 'approve' ? 'published' : 'rejected',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $this->respond(200, ['success' => true, 'message' => $decision === 'approve' ? 'Rental published.' : 'Rental rejected.']);
    }

    public function set_featured($id)
    {
        $this->require_admin('POST');
        $input = $this->json_input();
        if (!array_key_exists('featured', $input) || !is_bool($input['featured'])) {
            $this->respond(422, ['success' => false, 'message' => 'Choose whether the published rental is featured.']);
        }
        $db = $this->call->database();
        $listing = $db        ->table('rentals')
        ->where('id', (int) $id)
        ->where('status', 'published')
        ->row_array();
        if (!$listing) {
            $this->respond(404, ['success' => false, 'message' => 'Only published rentals can be featured.']);
        }
        $db->table('rentals')
            ->where('id', (int) $id)
            ->update([
                'is_featured' => $input['featured'] ? 1 : 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        $this->respond(200, ['success' => true, 'message' => $input['featured'] ? 'Rental featured.' : 'Feature removed.']);
    }

    private function admin_user_id()
    {
        $this->call->library('session');
        return (int) load_class('session', 'libraries')->userdata('user_id');
    }

    private function owner_summary(array $owner)
    {
        return [
            'id' => (int) $owner['id'],
            'full_name' => $owner['full_name'] ?: $owner['username'],
            'email' => $owner['email'],
            'phone' => $owner['phone'] ?? '',
            'created_at' => $owner['created_at'],
        ];
    }

    private function validate_user_input(array $input)
    {
        $full_name = trim((string) ($input['full_name'] ?? ''));
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $phone = trim((string) ($input['phone'] ?? ''));
        $account_type = (string) ($input['account_type'] ?? '');
        $phone_digits = preg_replace('/\D/', '', $phone);
        if (!preg_match('/^.{2,100}$/u', $full_name)) {
            $this->respond(422, ['success' => false, 'message' => 'Enter a name between 2 and 100 characters.']);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
            $this->respond(422, ['success' => false, 'message' => 'Enter a valid email address.']);
        }
        if ($phone !== '' && (strlen($phone) > 30 || strlen($phone_digits) < 7
            || strlen($phone_digits) > 20 || !preg_match('/^[0-9+().\-\s]+$/', $phone))) {
            $this->respond(422, ['success' => false, 'message' => 'Enter a valid mobile number.']);
        }
        if (!in_array($account_type, ['tenant', 'owner'], true)) {
            $this->respond(422, ['success' => false, 'message' => 'Choose tenant or property owner.']);
        }
        return [
            'full_name' => $full_name,
            'email' => $email,
            'phone' => $phone,
            'account_type' => $account_type,
        ];
    }

    private function validate_listing_input(array $input, string $existing_photo_url = '')
    {
        $owner_id = filter_var($input['owner_id'] ?? null, FILTER_VALIDATE_INT);
        $title = trim((string) ($input['title'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $type = (string) ($input['property_type'] ?? '');
        $price = filter_var($input['price'] ?? null, FILTER_VALIDATE_FLOAT);
        $location = trim((string) ($input['location'] ?? ''));
        $address = trim((string) ($input['address'] ?? ''));
        $unit_number = trim((string) ($input['unit_number'] ?? ''));
        [$latitude, $longitude] = $this->validated_coordinates($input);
        $rooms_count = filter_var($input['rooms_count'] ?? $input['bedrooms'] ?? null, FILTER_VALIDATE_INT);
        $bed_count = filter_var($input['bed_count'] ?? 0, FILTER_VALIDATE_INT);
        $bathrooms = filter_var($input['bathrooms'] ?? null, FILTER_VALIDATE_INT);
        $max_occupants = filter_var($input['max_occupants'] ?? null, FILTER_VALIDATE_INT);
        $floor_area_raw = $input['floor_area'] ?? '';
        $floor_area = $floor_area_raw === '' ? null : filter_var($floor_area_raw, FILTER_VALIDATE_FLOAT);
        $furnishing = (string) ($input['furnishing'] ?? '');
        $utilities = $input['utilities_included'] ?? null;
        $available_from = trim((string) ($input['available_from'] ?? ''));
        $deposit = filter_var($input['security_deposit'] ?? null, FILTER_VALIDATE_FLOAT);
        $amenities = trim((string) ($input['amenities'] ?? ''));
        $contact_phone = trim((string) ($input['contact_phone'] ?? ''));
        $status = (string) ($input['status'] ?? 'published');
        $featured = $input['is_featured'] ?? false;
        $db = $this->call->database();

        if ($owner_id === false || $owner_id < 1 || !$db->table('users')->where('id', $owner_id)->where('account_type', 'owner')->row_array()) {
            $this->respond(422, ['success' => false, 'message' => 'Choose an existing property owner for this listing.']);
        }
        if (strlen($title) < 4 || strlen($title) > 120 || strlen($description) < 20 || strlen($description) > 5000) {
            $this->respond(422, ['success' => false, 'message' => 'Enter a title (4–120 characters) and description (20–5000 characters).']);
        }
        if (!in_array($type, ['bedspacer', 'room', 'apartment'], true)
            || !is_numeric($price) || $price < 1 || $price > 99999999.99) {
            $this->respond(422, ['success' => false, 'message' => 'Choose a rental type and valid monthly price.']);
        }
        if (strlen($location) < 3 || strlen($location) > 180 || strlen($address) < 5
            || strlen($address) > 180 || strlen($unit_number) > 40) {
            $this->respond(422, ['success' => false, 'message' => 'Enter a valid location, street address, and unit number.']);
        }
        if ($rooms_count === false || $rooms_count < 0 || $rooms_count > 50
            || $bed_count === false || $bed_count < 0 || $bed_count > 500
            || ($type === 'bedspacer' && $bed_count < 1)
            || $bathrooms === false || $bathrooms < 0 || $bathrooms > 50
            || $max_occupants === false || $max_occupants < 1 || $max_occupants > 500
            || ($floor_area !== null && (!is_numeric($floor_area) || $floor_area < 1 || $floor_area > 99999))) {
            $this->respond(422, ['success' => false, 'message' => 'Enter valid room, bed, bathroom, occupant, and floor area values. Bedspacers require at least one bed.']);
        }
        if (!in_array($furnishing, ['unfurnished', 'semi_furnished', 'fully_furnished'], true) || !is_bool($utilities)) {
            $this->respond(422, ['success' => false, 'message' => 'Choose valid furnishing and utilities options.']);
        }
        $date = DateTime::createFromFormat('!Y-m-d', $available_from);
        if (!$date || $date->format('Y-m-d') !== $available_from) {
            $this->respond(422, ['success' => false, 'message' => 'Choose a valid availability date.']);
        }
        if (!is_numeric($deposit) || $deposit < 0 || $deposit > 99999999.99 || strlen($amenities) > 1000) {
            $this->respond(422, ['success' => false, 'message' => 'Enter a valid security deposit and amenities list.']);
        }
        $phone_digits = preg_replace('/\D/', '', $contact_phone);
        if (strlen($contact_phone) > 30 || strlen($phone_digits) < 7 || strlen($phone_digits) > 20
            || !preg_match('/^[0-9+().\-\s]+$/', $contact_phone)) {
            $this->respond(422, ['success' => false, 'message' => 'Enter a valid contact phone number.']);
        }
        if ($existing_photo_url === '') {
            $this->respond(422, ['success' => false, 'message' => 'Choose a property photo from your device.']);
        }
        if (!in_array($status, ['pending', 'published', 'rented', 'rejected'], true) || !is_bool($featured)) {
            $this->respond(422, ['success' => false, 'message' => 'Choose a valid listing status and featured setting.']);
        }
        return [
            'owner_id' => (int) $owner_id,
            'title' => $title,
            'description' => $description,
            'property_type' => $type,
            'price' => number_format((float) $price, 2, '.', ''),
            'location' => $location,
            'address' => $address,
            'unit_number' => $unit_number,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'bedrooms' => $rooms_count,
            'rooms_count' => $rooms_count,
            'bed_count' => $bed_count,
            'bathrooms' => $bathrooms,
            'max_occupants' => $max_occupants,
            'floor_area' => $floor_area === null ? null : number_format((float) $floor_area, 2, '.', ''),
            'furnishing' => $furnishing,
            'utilities_included' => $utilities ? 1 : 0,
            'available_from' => $available_from,
            'security_deposit' => number_format((float) $deposit, 2, '.', ''),
            'amenities' => $amenities,
            'contact_phone' => $contact_phone,
            'photo_url' => $existing_photo_url,
            'status' => $status,
            'is_featured' => $featured ? 1 : 0,
        ];
    }

    private function require_admin($method)
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {
            header('Allow: ' . $method);
            $this->respond(405, ['success' => false, 'message' => 'This request method is not allowed.']);
        }

        $this->call->library('session');
        $user_id = (int) load_class('session', 'libraries')->userdata('user_id');
        $user = $user_id > 0
            ? $this->call->database()->table('users')->where('id', $user_id)->row_array()
            : null;
        if (!$user || !(bool) $user['is_active'] || $user['role'] !== 'admin') {
            $this->respond(403, ['success' => false, 'message' => 'Administrator access is required.']);
        }
    }

    private function validated_coordinates(array $input): array
    {
        $latitude = trim((string) ($input['latitude'] ?? ''));
        $longitude = trim((string) ($input['longitude'] ?? ''));
        if ($latitude === '' && $longitude === '') {
            return [null, null];
        }
        if ($latitude === '' || $longitude === '' || !is_numeric($latitude) || !is_numeric($longitude)) {
            $this->respond(422, ['success' => false, 'message' => 'Choose a property location on the map or enter both coordinates.']);
        }

        $latitude = (float) $latitude;
        $longitude = (float) $longitude;
        if (!is_finite($latitude) || !is_finite($longitude) || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            $this->respond(422, ['success' => false, 'message' => 'Enter valid map coordinates.']);
        }

        return [
            number_format($latitude, 7, '.', ''),
            number_format($longitude, 7, '.', ''),
        ];
    }

    private function listing_input()
    {
        if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data') !== false) {
            $input = $_POST;
            foreach (['utilities_included', 'is_featured'] as $field) {
                if (array_key_exists($field, $input)) {
                    $value = filter_var($input[$field], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    if ($value === null) {
                        $this->respond(422, ['success' => false, 'message' => 'Choose valid utilities and featured settings.']);
                    }
                    $input[$field] = $value;
                }
            }
            return $input;
        }
        $input = $this->json_input();
        unset($input['photo_url']);
        return $input;
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

    private function respond($status, array $body)
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
}
