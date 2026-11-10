<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Payments extends Controller
{
    public function checkout($request_id)
    {
        $this->require_method('POST');
        $user = $this->current_user();
        if (!$user) {
            $this->respond(401, ['success' => false, 'message' => 'Sign in to pay for your rental.']);
        }
        if ($user['role'] === 'admin' || $user['account_type'] !== 'tenant') {
            $this->respond(403, ['success' => false, 'message' => 'Only tenants can pay for a rental request.']);
        }
        $input = $this->json_input();
        $payment_method = $this->validate_online_method($input['payment_method'] ?? '');
        [$secret_key] = $this->xendit_configuration();

        $db = $this->call->database();
        $rental_request = $db->table('rental_requests')
            ->where('id', (int) $request_id)
            ->where('tenant_id', (int) $user['id'])
            ->row_array();
        if (!$rental_request || $rental_request['status'] !== 'awaiting_payment') {
            $this->respond(409, ['success' => false, 'message' => 'The owner must approve this request before payment is available.']);
        }

        $rental = $db->table('rentals')
            ->where('id', (int) $rental_request['rental_id'])
            ->where('status', 'published')
            ->row_array();
        if (!$rental) {
            $this->respond(409, ['success' => false, 'message' => 'This rental is no longer available for payment. Contact RentEase support.']);
        }

        $amount_centavos = $this->money_to_centavos($rental['price'])
            + $this->money_to_centavos($rental['security_deposit'] ?? '0.00');
        if ($amount_centavos < 1 || $amount_centavos > 999999999999) {
            $this->respond(422, ['success' => false, 'message' => 'The payment amount is outside the supported range. Contact RentEase support.']);
        }

        $payment = $db->table('rental_payments')
            ->where('rental_request_id', (int) $request_id)
            ->row_array();
        if ($payment && $payment['status'] === 'paid') {
            $this->respond(409, ['success' => false, 'message' => 'This rental request has already been paid.']);
        }
        if ($payment && $payment['status'] === 'pending' && !empty($payment['checkout_url'])) {
            if ($payment['payment_method'] !== $payment_method) {
                $this->respond(409, ['success' => false, 'message' => 'A ' . strtoupper((string) $payment['payment_method']) . ' checkout is already open. Continue it or wait for it to expire before choosing another method.']);
            }
            $this->respond(200, [
                'success' => true,
                'checkout_url' => $payment['checkout_url'],
                'reused' => true,
                'message' => 'Continue with your existing secure checkout.',
            ]);
        }
        if ($payment && $payment['status'] === 'pending_owner_confirmation') {
            $this->respond(409, ['success' => false, 'message' => 'Your physical payment is waiting for the owner to confirm it.']);
        }
        $reference_number = $payment && $payment['status'] === 'creating'
            ? $payment['reference_number']
            : 'RENT-' . (int) $request_id . '-' . strtoupper(bin2hex(random_bytes(6)));
        $payment_data = [
            'rental_request_id' => (int) $request_id,
            'tenant_id' => (int) $user['id'],
            'rental_id' => (int) $rental['id'],
            'reference_number' => $reference_number,
            'checkout_session_id' => null,
            'provider_payment_id' => null,
            'checkout_url' => null,
            'amount_centavos' => $amount_centavos,
            'currency' => 'PHP',
            'payment_method' => $payment_method,
            'status' => 'creating',
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($payment) {
            $db->table('rental_payments')
                ->where('id', (int) $payment['id'])
                ->update($payment_data);
        } else {
            try {
                $payment_data['created_at'] = date('Y-m-d H:i:s');
                $db->table('rental_payments')->insert($payment_data);
            } catch (PDOException $exception) {
                $payment = $db->table('rental_payments')
                    ->where('rental_request_id', (int) $request_id)
                    ->row_array();
                if ($payment && $payment['status'] === 'pending' && !empty($payment['checkout_url'])) {
                    $this->respond(200, ['success' => true, 'checkout_url' => $payment['checkout_url'], 'reused' => true]);
                }
                if ($payment) {
                    $this->respond(409, ['success' => false, 'message' => 'Your checkout is already being prepared. Please try again shortly.']);
                }
                throw $exception;
            }
        }

        $frontend_url = rtrim(trim((string) rentease_env('FRONTEND_URL')), '/') . '/';
        $frontend_parts = parse_url($frontend_url);
        if (!$frontend_parts || !in_array(strtolower((string) ($frontend_parts['scheme'] ?? '')), ['http', 'https'], true)
            || empty($frontend_parts['host'])) {
            $db->table('rental_payments')
                ->where('rental_request_id', (int) $request_id)
                ->update(['status' => 'failed', 'updated_at' => date('Y-m-d H:i:s')]);
            $this->respond(503, ['success' => false, 'message' => 'The payment return URL is not configured correctly. Contact RentEase support.']);
        }

        $payload = [
                    'type' => 'PAY',
                    'country' => 'PH',
                    'currency' => 'PHP',
                    'request_amount' => $amount_centavos / 100,
                    'capture_method' => 'AUTOMATIC',
                    'channel_code' => $payment_method === 'gcash' ? 'GCASH' : 'PAYMAYA',
                    'channel_properties' => [
                        'success_return_url' => $frontend_url . '?payment=success&request_id=' . (int) $request_id . '#tenant/payments',
                        'failure_return_url' => $frontend_url . '?payment=cancelled&request_id=' . (int) $request_id . '#tenant/payments',
                    ],
                    'reference_id' => $reference_number,
                    'description' => 'First month rent and deposit - ' . $rental['title'],
                    'metadata' => [
                        'rental_request_id' => (string) (int) $request_id,
                        'tenant_id' => (string) (int) $user['id'],
                        'rental_id' => (string) (int) $rental['id'],
                    ],
        ];
        $checkout = $this->xendit_request(
            'POST',
            'https://api.xendit.co/v3/payment_requests',
            $secret_key,
            $payload,
            $reference_number
        );
        if (!$checkout['success']) {
            error_log('Xendit checkout creation failed for rental request ' . (int) $request_id . ' (HTTP ' . $checkout['http_status'] . ').');
            if ($checkout['http_status'] >= 400 && $checkout['http_status'] < 500) {
                $db->table('rental_payments')
                    ->where('rental_request_id', (int) $request_id)
                    ->update(['status' => 'failed', 'updated_at' => date('Y-m-d H:i:s')]);
            }
            $this->respond(502, ['success' => false, 'message' => 'The payment provider could not start checkout. Please try again.']);
        }

        $session = $checkout['body'];
        $session_id = (string) ($session['payment_request_id'] ?? '');
        $checkout_url = $this->xendit_redirect_url($session['actions'] ?? []);
        $checkout_parts = parse_url($checkout_url);
        if ($session_id === '' || !$checkout_parts
            || strtolower((string) ($checkout_parts['scheme'] ?? '')) !== 'https'
            || !preg_match('/(^|\.)xendit\.co$/i', strtolower((string) ($checkout_parts['host'] ?? '')))) {
            error_log('Xendit returned an invalid payment redirect for rental request ' . (int) $request_id . '.');
            $this->respond(502, ['success' => false, 'message' => 'Xendit did not return a valid GCash/Maya checkout link. Please try again.']);
        }

        $db->table('rental_payments')
            ->where('rental_request_id', (int) $request_id)
            ->update([
                'checkout_session_id' => $session_id,
                'checkout_url' => $checkout_url,
                'status' => 'pending',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        $this->respond(200, [
            'success' => true,
            'checkout_url' => $checkout_url,
            'reused' => false,
            'message' => 'Secure ' . strtoupper($payment_method) . ' checkout is ready.',
        ]);
    }

    public function mine()
    {
        $this->require_method('GET');
        $user = $this->current_user();
        if (!$user) {
            $this->respond(401, ['success' => false, 'message' => 'Sign in to view your payments.']);
        }
        if ($user['role'] === 'admin' || $user['account_type'] !== 'tenant') {
            $this->respond(403, ['success' => false, 'message' => 'Only tenants can view rental payments.']);
        }

        $payments = $this->call->database()
            ->table('rental_payments')
            ->select('id, rental_request_id, tenant_id, rental_id, reference_number, amount_centavos, currency, payment_method, payment_note, status, paid_at, created_at, updated_at')
            ->where('tenant_id', (int) $user['id'])
            ->order_by('created_at', 'DESC')
            ->limit(100)
            ->get_all();
        foreach ($payments as &$payment) {
            $reference = (string) ($payment['reference_number'] ?? '');
            $payment['owner_confirmed'] = $payment['status'] === 'paid'
                && ($payment['payment_method'] === 'cash' || strpos($reference, 'OFFLINE-') === 0 || strpos($reference, 'CASH-') === 0);
        }
        unset($payment);
        $this->respond(200, ['success' => true, 'payments' => $payments]);
    }

    public function installments()
    {
        $this->require_method('GET');
        $user = $this->current_user();
        if (!$user) $this->respond(401, ['success' => false, 'message' => 'Sign in to view your rent schedule.']);
        if ($user['role'] === 'admin' || $user['account_type'] !== 'tenant') {
            $this->respond(403, ['success' => false, 'message' => 'Only tenants can view a rent schedule.']);
        }

        $db = $this->call->database();
        $requests = $db->table('rental_requests')
            ->where('tenant_id', (int) $user['id'])
            ->where('status', 'approved')
            ->order_by('created_at', 'DESC')
            ->limit(100)
            ->get_all();
        $schedule = [];
        $payments = [];
        $total_due = 0;
        $total_outstanding = 0;
        $total_paid = 0;
        foreach ($requests as $rental_request) {
            $rental = $db->table('rentals')->where('id', (int) $rental_request['rental_id'])->row_array();
            if (!$rental) continue;
            $owner_payment_profile = $db->table('owner_payment_profiles')
                ->where('owner_id', (int) $rental_request['owner_id'])
                ->row_array();
            $owner = $db->table('users')->select('phone')->where('id', (int) $rental_request['owner_id'])->row_array();
            $installments = $this->ensure_installments($rental_request, $rental);
            $initial_payment = $db->table('rental_payments')
                ->select('id, amount_centavos, payment_method, reference_number, paid_at')
                ->where('rental_request_id', (int) $rental_request['id'])
                ->where('status', 'paid')
                ->row_array();
            foreach ($installments as $installment) {
                $installment['rental_title'] = $rental['title'];
                $installment['location'] = $rental['location'];
                $installment['monthly_rent'] = $rental['price'];
                $installment['receipt_available'] = !empty($installment['receipt_available']);
                $reference = (string) ($installment['reference_number'] ?? '');
                $installment['owner_confirmed'] = $installment['status'] === 'paid'
                    && ($installment['payment_method'] === 'cash' || strpos($reference, 'OFFLINE-') === 0 || strpos($reference, 'CASH-') === 0);
                if ((int) $installment['installment_number'] === 1 && $initial_payment) {
                    $initial_reference = (string) ($initial_payment['reference_number'] ?? '');
                    $installment['owner_confirmed'] = $installment['status'] === 'paid'
                        && (($initial_payment['payment_method'] ?? '') === 'cash'
                            || strpos($initial_reference, 'OFFLINE-') === 0
                            || strpos($initial_reference, 'CASH-') === 0);
                }
                $installment['owner_gcash_qr_data'] = $owner_payment_profile['gcash_qr_data'] ?? null;
                $installment['owner_maya_qr_data'] = $owner_payment_profile['maya_qr_data'] ?? null;
                $installment['owner_phone'] = $owner['phone'] ?? '';
                $schedule[] = $installment;
                if ($installment['status'] === 'paid') {
                    if ((int) $installment['installment_number'] === 1) {
                        if ($initial_payment) {
                            $payments[] = [
                                'id' => 'initial-' . $initial_payment['id'],
                                'rental_request_id' => (int) $rental_request['id'],
                                'rental_title' => $rental['title'],
                                'description' => 'First month rent + security deposit',
                                'amount_centavos' => (int) $initial_payment['amount_centavos'],
                                'payment_method' => $initial_payment['payment_method'] ?? 'online',
                                'reference_number' => $initial_payment['reference_number'],
                                'paid_at' => $initial_payment['paid_at'],
                                'owner_confirmed' => ($initial_payment['payment_method'] ?? '') === 'cash'
                                    || strpos((string) ($initial_payment['reference_number'] ?? ''), 'OFFLINE-') === 0
                                    || strpos((string) ($initial_payment['reference_number'] ?? ''), 'CASH-') === 0,
                            ];
                            $total_paid += (int) $initial_payment['amount_centavos'];
                            continue;
                        }
                    }
                    if ((int) $installment['installment_number'] > 1) {
                        $payments[] = [
                            'id' => 'installment-' . $installment['id'],
                            'rental_request_id' => (int) $rental_request['id'],
                            'rental_title' => $rental['title'],
                            'description' => 'Rent installment #' . $installment['installment_number'],
                            'amount_centavos' => (int) $installment['amount_centavos'],
                            'payment_method' => $installment['payment_method'] ?? 'online',
                            'reference_number' => $installment['reference_number'],
                            'paid_at' => $installment['paid_at'],
                            'owner_confirmed' => ($installment['payment_method'] ?? '') === 'cash'
                                || strpos((string) ($installment['reference_number'] ?? ''), 'OFFLINE-') === 0
                                || strpos((string) ($installment['reference_number'] ?? ''), 'CASH-') === 0,
                        ];
                        $total_paid += (int) $installment['amount_centavos'];
                    }
                } else {
                    $amount = (int) $installment['amount_centavos'];
                    $total_outstanding += $amount;
                    if ((string) $installment['due_date'] <= date('Y-m-d')) $total_due += $amount;
                }
            }
        }
        usort($schedule, static function ($a, $b) {
            return [$a['due_date'], $a['id']] <=> [$b['due_date'], $b['id']];
        });
        usort($payments, static function ($a, $b) {
            return strcmp((string) $b['paid_at'], (string) $a['paid_at']);
        });
        $this->respond(200, [
            'success' => true,
            'schedule' => $schedule,
            'payments' => $payments,
            'summary' => [
                'due_centavos' => $total_due,
                'outstanding_centavos' => $total_outstanding,
                'paid_centavos' => $total_paid,
            ],
        ]);
    }

    public function checkout_installment($installment_id)
    {
        $this->require_method('POST');
        $user = $this->current_user();
        if (!$user) $this->respond(401, ['success' => false, 'message' => 'Sign in to pay rent.']);
        if ($user['role'] === 'admin' || $user['account_type'] !== 'tenant') {
            $this->respond(403, ['success' => false, 'message' => 'Only tenants can pay rent installments.']);
        }
        $input = $this->json_input();
        $payment_method = $this->validate_online_method($input['payment_method'] ?? '');
        [$secret_key] = $this->xendit_configuration();

        $db = $this->call->database();
        $installment = $db->table('rental_installments')
            ->where('id', (int) $installment_id)
            ->where('tenant_id', (int) $user['id'])
            ->row_array();
        if (!$installment) $this->respond(404, ['success' => false, 'message' => 'Rent installment not found.']);
        if ($installment['status'] === 'paid') $this->respond(409, ['success' => false, 'message' => 'This rent installment is already paid.']);
        if ($installment['status'] === 'pending' && !empty($installment['checkout_url'])) {
            if ($installment['payment_method'] !== $payment_method) {
                $this->respond(409, ['success' => false, 'message' => 'A ' . strtoupper((string) $installment['payment_method']) . ' checkout is already open. Continue it or wait for it to expire before choosing another method.']);
            }
            $this->respond(200, ['success' => true, 'checkout_url' => $installment['checkout_url'], 'reused' => true]);
        }
        if ($installment['status'] === 'pending_owner_confirmation') {
            $this->respond(409, ['success' => false, 'message' => 'Your physical payment is waiting for the owner to confirm it.']);
        }
        if (!in_array($installment['status'], ['due', 'overdue', 'upcoming', 'creating', 'pending'], true)) {
            $this->respond(409, ['success' => false, 'message' => 'This rent installment cannot be paid in its current state.']);
        }
        $rental_request = $db->table('rental_requests')
            ->where('id', (int) $installment['rental_request_id'])
            ->where('tenant_id', (int) $user['id'])
            ->where('status', 'approved')
            ->row_array();
        $rental = $db->table('rentals')->where('id', (int) $installment['rental_id'])->row_array();
        if (!$rental_request || !$rental) $this->respond(409, ['success' => false, 'message' => 'The rental agreement is no longer active.']);

        $reference = !empty($installment['reference_number'])
            ? $installment['reference_number']
            : 'RENT-' . (int) $installment['id'] . '-' . strtoupper(bin2hex(random_bytes(6)));
        $db->table('rental_installments')->where('id', (int) $installment['id'])->update([
            'status' => 'creating',
            'payment_method' => $payment_method,
            'reference_number' => $reference,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $frontend_url = rtrim(trim((string) rentease_env('FRONTEND_URL')), '/') . '/';
        $frontend_parts = parse_url($frontend_url);
        if (!$frontend_parts || !in_array(strtolower((string) ($frontend_parts['scheme'] ?? '')), ['http', 'https'], true)
            || empty($frontend_parts['host'])) {
            $db->table('rental_installments')->where('id', (int) $installment['id'])->update([
                'status' => $this->installment_status_for_due_date($installment['due_date']),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $this->respond(503, ['success' => false, 'message' => 'The payment return URL is not configured correctly.']);
        }
        $payload = [
                'type' => 'PAY',
                'country' => 'PH',
                'currency' => 'PHP',
                'request_amount' => (int) $installment['amount_centavos'] / 100,
                'capture_method' => 'AUTOMATIC',
                'channel_code' => $payment_method === 'gcash' ? 'GCASH' : 'PAYMAYA',
                'channel_properties' => [
                    'success_return_url' => $frontend_url . '?payment=success&request_id=' . (int) $rental_request['id'] . '&installment_id=' . (int) $installment['id'] . '#tenant/payments',
                    'failure_return_url' => $frontend_url . '?payment=cancelled&request_id=' . (int) $rental_request['id'] . '&installment_id=' . (int) $installment['id'] . '#tenant/payments',
                ],
                'reference_id' => $reference,
                'description' => 'Rent installment ' . (int) $installment['installment_number'] . ' - ' . $rental['title'],
                'metadata' => [
                    'rental_request_id' => (string) (int) $rental_request['id'],
                    'installment_id' => (string) (int) $installment['id'],
                    'tenant_id' => (string) (int) $user['id'],
                ],
        ];
        $checkout = $this->xendit_request('POST', 'https://api.xendit.co/v3/payment_requests', $secret_key, $payload, $reference);
        if (!$checkout['success']) {
            error_log('Xendit rent installment checkout failed for installment ' . (int) $installment['id'] . ' (HTTP ' . $checkout['http_status'] . ').');
            if ($checkout['http_status'] >= 400 && $checkout['http_status'] < 500) {
                $db->table('rental_installments')->where('id', (int) $installment['id'])->update([
                    'status' => $this->installment_status_for_due_date($installment['due_date']),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
            $this->respond(502, ['success' => false, 'message' => 'The payment provider could not start rent checkout. Please try again.']);
        }
        $session = $checkout['body'];
        $session_id = (string) ($session['payment_request_id'] ?? '');
        $checkout_url = $this->xendit_redirect_url($session['actions'] ?? []);
        $checkout_parts = parse_url($checkout_url);
        if ($session_id === '' || !$checkout_parts
            || strtolower((string) ($checkout_parts['scheme'] ?? '')) !== 'https'
            || !preg_match('/(^|\.)xendit\.co$/i', strtolower((string) ($checkout_parts['host'] ?? '')))) {
            $db->table('rental_installments')->where('id', (int) $installment['id'])->update([
                'status' => $this->installment_status_for_due_date($installment['due_date']),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $this->respond(502, ['success' => false, 'message' => 'The payment provider returned an invalid checkout link.']);
        }
        $db->table('rental_installments')->where('id', (int) $installment['id'])->update([
            'checkout_session_id' => $session_id,
            'checkout_url' => $checkout_url,
            'status' => 'pending',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->respond(200, ['success' => true, 'checkout_url' => $checkout_url, 'reused' => false, 'message' => 'Secure ' . strtoupper($payment_method) . ' checkout is ready.']);
    }

    public function owner_payment_profile()
    {
        $this->require_method('GET');
        $user = $this->require_owner();
        $profile = $this->call->database()->table('owner_payment_profiles')
            ->where('owner_id', (int) $user['id'])
            ->row_array();
        $this->respond(200, [
            'success' => true,
            'profile' => [
                'gcash_qr_data' => $profile['gcash_qr_data'] ?? null,
                'maya_qr_data' => $profile['maya_qr_data'] ?? null,
                'owner_phone' => $user['phone'] ?? '',
            ],
        ]);
    }

    public function save_owner_payment_profile()
    {
        $this->require_method('POST');
        $user = $this->require_owner();
        $input = $this->json_input();
        $gcash_qr = $this->validate_qr_image($input['gcash_qr_data'] ?? null, 'GCash');
        $maya_qr = $this->validate_qr_image($input['maya_qr_data'] ?? null, 'Maya');
        if ($gcash_qr === null && $maya_qr === null && trim((string) ($user['phone'] ?? '')) === '') {
            $this->respond(422, ['success' => false, 'message' => 'Add a mobile number to your account or upload an official wallet QR code.']);
        }

        $db = $this->call->database();
        $profile = $db->table('owner_payment_profiles')
            ->where('owner_id', (int) $user['id'])
            ->row_array();
        $values = [
            'gcash_qr_data' => $gcash_qr,
            'maya_qr_data' => $maya_qr,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if ($profile) {
            $db->table('owner_payment_profiles')->where('owner_id', (int) $user['id'])->update($values);
        } else {
            $values['owner_id'] = (int) $user['id'];
            $values['created_at'] = date('Y-m-d H:i:s');
            $db->table('owner_payment_profiles')->insert($values);
        }
        $this->respond(200, ['success' => true, 'message' => 'Your wallet payment details have been saved.']);
    }

    public function request_physical_payment($request_id)
    {
        $this->require_method('POST');
        $user = $this->require_tenant();
        $input = $this->json_input();
        $method = $this->validate_wallet_method($input['payment_method'] ?? '');
        $receipt_image = $this->validate_receipt_image($input['receipt_image_data'] ?? null);
        $db = $this->call->database();
        $request = $db->table('rental_requests')
            ->where('id', (int) $request_id)
            ->where('tenant_id', (int) $user['id'])
            ->where('status', 'awaiting_payment')
            ->row_array();
        if (!$request) $this->respond(409, ['success' => false, 'message' => 'The owner must approve this request before you can pay.']);
        $profile = $db->table('owner_payment_profiles')->where('owner_id', (int) $request['owner_id'])->row_array();
        $owner = $db->table('users')->select('phone')->where('id', (int) $request['owner_id'])->row_array();
        if (empty($profile[$method . '_qr_data']) && empty($owner['phone'])) {
            $this->respond(409, ['success' => false, 'message' => 'The owner has not set up a wallet QR code or mobile payment number yet.']);
        }
        $payment = $db->table('rental_payments')
            ->select('id, amount_centavos, status, payment_method, payment_note, paid_at')
            ->where('rental_request_id', (int) $request['id'])
            ->row_array();
        if ($payment && $payment['status'] === 'paid') $this->respond(409, ['success' => false, 'message' => 'This move-in payment is already recorded as paid.']);
        if ($payment && in_array($payment['status'], ['pending', 'creating'], true)) {
            $this->respond(409, ['success' => false, 'message' => 'An online checkout is already in progress. Finish or cancel it before reporting a wallet transfer.']);
        }
        if ($payment && $payment['status'] === 'pending_owner_confirmation'
            && $this->receipt_is_attached('rental_payments', (int) $payment['id'])) {
            $this->respond(200, ['success' => true, 'message' => 'Your wallet transfer is already waiting for the owner to confirm it.']);
        }

        $rental = $db->table('rentals')->where('id', (int) $request['rental_id'])->row_array();
        if (!$rental) $this->respond(409, ['success' => false, 'message' => 'The rental listing is no longer available.']);
        $values = [
            'rental_request_id' => (int) $request['id'],
            'tenant_id' => (int) $user['id'],
            'rental_id' => (int) $rental['id'],
            'reference_number' => 'OFFLINE-' . (int) $request['id'] . '-' . strtoupper(bin2hex(random_bytes(5))),
            'checkout_session_id' => null,
            'provider_payment_id' => null,
            'checkout_url' => null,
            'amount_centavos' => $this->money_to_centavos($rental['price']) + $this->money_to_centavos($rental['security_deposit'] ?? '0.00'),
            'currency' => 'PHP',
            'payment_method' => $method,
            'payment_note' => null,
            'receipt_image' => $receipt_image,
            'status' => 'pending_owner_confirmation',
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if ($payment) {
            $db->table('rental_payments')->where('id', (int) $payment['id'])->update($values);
        } else {
            $values['created_at'] = date('Y-m-d H:i:s');
            $db->table('rental_payments')->insert($values);
        }
        $this->respond(200, ['success' => true, 'message' => 'Payment reported. The rental owner must confirm receipt before the rental is activated.']);
    }

    public function request_cash_payment($request_id)
    {
        $this->require_method('POST');
        $user = $this->require_tenant();
        $input = $this->json_input();
        $receipt_image = $this->validate_receipt_image($input['receipt_image_data'] ?? null);
        $db = $this->call->database();
        $request = $db->table('rental_requests')
            ->where('id', (int) $request_id)
            ->where('tenant_id', (int) $user['id'])
            ->where('status', 'awaiting_payment')
            ->row_array();
        if (!$request) $this->respond(409, ['success' => false, 'message' => 'The owner must approve this request before you can report a cash payment.']);
        $payment = $db->table('rental_payments')
            ->select('id, status, payment_method')
            ->where('rental_request_id', (int) $request['id'])
            ->row_array();
        if ($payment && $payment['status'] === 'paid') $this->respond(409, ['success' => false, 'message' => 'This move-in payment is already recorded as paid.']);
        if ($payment && in_array($payment['status'], ['pending', 'creating'], true)) {
            $this->respond(409, ['success' => false, 'message' => 'An online checkout is in progress. Finish or cancel it before reporting a cash payment.']);
        }
        if ($payment && $payment['status'] === 'pending_owner_confirmation'
            && $this->receipt_is_attached('rental_payments', (int) $payment['id'])) {
            $this->respond(409, ['success' => false, 'message' => 'A payment report is already waiting for owner confirmation.']);
        }
        $rental = $db->table('rentals')->where('id', (int) $request['rental_id'])->row_array();
        if (!$rental) $this->respond(409, ['success' => false, 'message' => 'The rental listing is no longer available.']);
        $values = [
            'rental_request_id' => (int) $request['id'],
            'tenant_id' => (int) $user['id'],
            'rental_id' => (int) $rental['id'],
            'reference_number' => 'OFFLINE-CASH-' . (int) $request['id'] . '-' . strtoupper(bin2hex(random_bytes(5))),
            'checkout_session_id' => null,
            'provider_payment_id' => null,
            'checkout_url' => null,
            'amount_centavos' => $this->money_to_centavos($rental['price']) + $this->money_to_centavos($rental['security_deposit'] ?? '0.00'),
            'currency' => 'PHP',
            'payment_method' => 'cash',
            'payment_note' => null,
            'receipt_image' => $receipt_image,
            'status' => 'pending_owner_confirmation',
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if ($payment) {
            $db->table('rental_payments')->where('id', (int) $payment['id'])->update($values);
        } else {
            $values['created_at'] = date('Y-m-d H:i:s');
            $db->table('rental_payments')->insert($values);
        }
        $this->respond(200, ['success' => true, 'message' => 'Cash payment receipt submitted. The owner must review the receipt and confirm they received the cash before the rental is activated.']);
    }

    public function request_installment_physical_payment($installment_id)
    {
        $this->require_method('POST');
        $user = $this->require_tenant();
        $input = $this->json_input();
        $method = $this->validate_wallet_method($input['payment_method'] ?? '');
        $receipt_image = $this->validate_receipt_image($input['receipt_image_data'] ?? null);
        $db = $this->call->database();
        $installment = $db->table('rental_installments')
            ->select('id, rental_request_id, tenant_id, rental_id, installment_number, due_date, amount_centavos, status, payment_method')
            ->where('id', (int) $installment_id)
            ->where('tenant_id', (int) $user['id'])
            ->row_array();
        if (!$installment) $this->respond(404, ['success' => false, 'message' => 'Rent installment not found.']);
        if ($installment['status'] === 'paid') $this->respond(409, ['success' => false, 'message' => 'This rent installment is already paid.']);
        if ($installment['status'] === 'pending_owner_confirmation'
            && $this->receipt_is_attached('rental_installments', (int) $installment['id'])) {
            $this->respond(200, ['success' => true, 'message' => 'Your wallet transfer is already waiting for the owner to confirm it.']);
        }
        if (!in_array($installment['status'], ['due', 'overdue', 'upcoming', 'pending_owner_confirmation'], true)) {
            $this->respond(409, ['success' => false, 'message' => 'Finish or cancel the current checkout before reporting a physical payment.']);
        }
        $request = $db->table('rental_requests')
            ->where('id', (int) $installment['rental_request_id'])
            ->where('tenant_id', (int) $user['id'])
            ->where('status', 'approved')
            ->row_array();
        if (!$request) $this->respond(409, ['success' => false, 'message' => 'This rental agreement is not active.']);
        $profile = $db->table('owner_payment_profiles')->where('owner_id', (int) $request['owner_id'])->row_array();
        $owner = $db->table('users')->select('phone')->where('id', (int) $request['owner_id'])->row_array();
        if (empty($profile[$method . '_qr_data']) && empty($owner['phone'])) {
            $this->respond(409, ['success' => false, 'message' => 'The owner has not set up a wallet QR code or mobile payment number yet.']);
        }
        $db->table('rental_installments')->where('id', (int) $installment['id'])->update([
            'status' => 'pending_owner_confirmation',
            'payment_method' => $method,
            'payment_note' => null,
            'receipt_image' => $receipt_image,
            'reference_number' => 'OFFLINE-' . (int) $installment['id'] . '-' . strtoupper(bin2hex(random_bytes(5))),
            'checkout_session_id' => null,
            'checkout_url' => null,
            'provider_payment_id' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->respond(200, ['success' => true, 'message' => 'Payment reported. The rental owner must confirm receipt before it is marked paid.']);
    }

    public function request_installment_cash_payment($installment_id)
    {
        $this->require_method('POST');
        $user = $this->require_tenant();
        $input = $this->json_input();
        $receipt_image = $this->validate_receipt_image($input['receipt_image_data'] ?? null);
        $db = $this->call->database();
        $installment = $db->table('rental_installments')
            ->select('id, rental_request_id, tenant_id, rental_id, due_date, status, payment_method')
            ->where('id', (int) $installment_id)
            ->where('tenant_id', (int) $user['id'])
            ->row_array();
        if (!$installment) $this->respond(404, ['success' => false, 'message' => 'Rent installment not found.']);
        if ($installment['status'] === 'paid') $this->respond(409, ['success' => false, 'message' => 'This rent installment is already paid.']);
        if ($installment['status'] === 'pending_owner_confirmation'
            && $this->receipt_is_attached('rental_installments', (int) $installment['id'])) {
            $this->respond(409, ['success' => false, 'message' => 'A payment report is already waiting for owner confirmation.']);
        }
        if (!in_array($installment['status'], ['due', 'overdue', 'upcoming', 'pending_owner_confirmation'], true)) {
            $this->respond(409, ['success' => false, 'message' => 'This installment cannot accept a cash payment report while another checkout is in progress.']);
        }
        $request = $db->table('rental_requests')
            ->where('id', (int) $installment['rental_request_id'])
            ->where('tenant_id', (int) $user['id'])
            ->where('status', 'approved')
            ->row_array();
        if (!$request) $this->respond(409, ['success' => false, 'message' => 'This rental agreement is not active.']);
        $db->table('rental_installments')->where('id', (int) $installment['id'])->update([
            'status' => 'pending_owner_confirmation',
            'payment_method' => 'cash',
            'payment_note' => null,
            'receipt_image' => $receipt_image,
            'reference_number' => 'OFFLINE-CASH-' . (int) $installment['id'] . '-' . strtoupper(bin2hex(random_bytes(5))),
            'checkout_session_id' => null,
            'checkout_url' => null,
            'provider_payment_id' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->respond(200, ['success' => true, 'message' => 'Cash payment receipt submitted. The owner must review the receipt and confirm they received the cash before this installment is marked paid.']);
    }

    public function owner_payments()
    {
        $this->require_method('GET');
        $owner = $this->require_owner();
        $db = $this->call->database();
        $requests = $db->table('rental_requests')
            ->where('owner_id', (int) $owner['id'])
            ->order_by('created_at', 'DESC')
            ->limit(200)
            ->get_all();
        $items = [];
        foreach ($requests as $request) {
            $rental = $db->table('rentals')->where('id', (int) $request['rental_id'])->row_array();
            $tenant = $db->table('users')->select('full_name')->where('id', (int) $request['tenant_id'])->row_array();
            if (!$rental) continue;
            if ($request['status'] === 'awaiting_payment') {
                $payment = $db->raw(
                    'SELECT amount_centavos, status, payment_method, payment_note, paid_at, receipt_image IS NOT NULL AS receipt_available '
                    . 'FROM rental_payments WHERE rental_request_id = ?',
                    [(int) $request['id']]
                )->fetch(PDO::FETCH_ASSOC);
                if (!$payment || $payment['status'] !== 'paid') {
                    $items[] = [
                        'record_type' => 'initial',
                        'record_id' => (int) $request['id'],
                        'request_id' => (int) $request['id'],
                        'rental_title' => $rental['title'],
                        'tenant_name' => $tenant['full_name'] ?? 'Tenant',
                        'amount_centavos' => $payment['amount_centavos'] ?? ($this->money_to_centavos($rental['price']) + $this->money_to_centavos($rental['security_deposit'] ?? '0.00')),
                        'status' => $payment['status'] ?? 'awaiting_payment',
                        'payment_method' => $payment['payment_method'] ?? null,
                        'receipt_available' => !empty($payment['receipt_available']),
                        'paid_at' => $payment['paid_at'] ?? null,
                    ];
                }
            } elseif ($request['status'] === 'approved') {
                $initial_payment = $db->raw(
                    'SELECT amount_centavos, status, payment_method, payment_note, paid_at, receipt_image IS NOT NULL AS receipt_available '
                    . 'FROM rental_payments WHERE rental_request_id = ? AND status = ?',
                    [(int) $request['id'], 'paid']
                )->fetch(PDO::FETCH_ASSOC);
                if ($initial_payment) {
                    $items[] = [
                        'record_type' => 'initial',
                        'record_id' => (int) $request['id'],
                        'request_id' => (int) $request['id'],
                        'rental_title' => $rental['title'],
                        'tenant_name' => $tenant['full_name'] ?? 'Tenant',
                        'amount_centavos' => (int) $initial_payment['amount_centavos'],
                        'status' => 'paid',
                        'payment_method' => $initial_payment['payment_method'] ?? null,
                        'receipt_available' => !empty($initial_payment['receipt_available']),
                        'paid_at' => $initial_payment['paid_at'] ?? null,
                    ];
                }
                $installments = $this->ensure_installments($request, $rental);
                foreach ($installments as $installment) {
                    if ((int) $installment['installment_number'] > 1 && $installment['status'] === 'paid') {
                        $items[] = [
                            'record_type' => 'installment',
                            'record_id' => (int) $installment['id'],
                            'request_id' => (int) $request['id'],
                            'installment_number' => (int) $installment['installment_number'],
                            'due_date' => $installment['due_date'],
                            'rental_title' => $rental['title'],
                            'tenant_name' => $tenant['full_name'] ?? 'Tenant',
                            'amount_centavos' => (int) $installment['amount_centavos'],
                            'status' => $installment['status'],
                            'payment_method' => $installment['payment_method'],
                            'receipt_available' => !empty($installment['receipt_available']),
                            'paid_at' => $installment['paid_at'],
                        ];
                        continue;
                    }
                    if (!in_array($installment['status'], ['due', 'overdue', 'upcoming', 'pending_owner_confirmation', 'pending', 'creating'], true)) continue;
                    $items[] = [
                        'record_type' => 'installment',
                        'record_id' => (int) $installment['id'],
                        'request_id' => (int) $request['id'],
                        'installment_number' => (int) $installment['installment_number'],
                        'due_date' => $installment['due_date'],
                        'rental_title' => $rental['title'],
                        'tenant_name' => $tenant['full_name'] ?? 'Tenant',
                        'amount_centavos' => (int) $installment['amount_centavos'],
                        'status' => $installment['status'],
                        'payment_method' => $installment['payment_method'],
                        'receipt_available' => !empty($installment['receipt_available']),
                        'paid_at' => $installment['paid_at'],
                    ];
                }
            }
        }
        $profile = $db->table('owner_payment_profiles')->where('owner_id', (int) $owner['id'])->row_array();
        $this->respond(200, [
            'success' => true,
            'payments' => $items,
            'profile' => [
                'gcash_qr_data' => $profile['gcash_qr_data'] ?? null,
                'maya_qr_data' => $profile['maya_qr_data'] ?? null,
                'owner_phone' => $owner['phone'] ?? '',
            ],
        ]);
    }

    public function owner_payment_receipt($id)
    {
        $this->require_method('GET');
        $owner = $this->require_owner();
        $record_type = (string) ($_GET['record_type'] ?? '');
        if ($record_type === 'initial') {
            $request = $this->call->database()->table('rental_requests')
                ->where('id', (int) $id)
                ->where('owner_id', (int) $owner['id'])
                ->row_array();
            $record = $request
                ? $this->call->database()->table('rental_payments')
                    ->where('rental_request_id', (int) $request['id'])
                    ->row_array()
                : null;
        } elseif ($record_type === 'installment') {
            $record = $this->call->database()->table('rental_installments')
                ->where('id', (int) $id)
                ->row_array();
            $request = $record
                ? $this->call->database()->table('rental_requests')
                    ->where('id', (int) $record['rental_request_id'])
                    ->where('owner_id', (int) $owner['id'])
                    ->row_array()
                : null;
            if (!$request) $record = null;
        } else {
            $this->respond(422, ['success' => false, 'message' => 'Choose a valid payment receipt.']);
        }
        if (!$record || empty($record['receipt_image'])) {
            $this->respond(404, ['success' => false, 'message' => 'The JPG payment receipt was not found.']);
        }
        header('Content-Type: image/jpeg');
        header('Content-Length: ' . strlen($record['receipt_image']));
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        echo $record['receipt_image'];
        exit;
    }

    public function settle_owner_payment($id)
    {
        $this->require_method('POST');
        $owner = $this->require_owner();
        $input = $this->json_input();
        $record_type = (string) ($input['record_type'] ?? '');
        $action = (string) ($input['action'] ?? '');
        if (!in_array($record_type, ['initial', 'installment'], true)
            || !in_array($action, ['confirm', 'cash', 'reject'], true)) {
            $this->respond(422, ['success' => false, 'message' => 'Choose a valid payment record and action.']);
        }
        if ($action === 'cash') {
            $this->respond(409, ['success' => false, 'message' => 'A cash payment cannot be recorded without the tenant’s JPG receipt. Ask the tenant to submit the receipt, then review and confirm it.']);
        }

        $db = $this->call->database();
        if ($record_type === 'initial') {
            $request = $db->table('rental_requests')->where('id', (int) $id)->where('owner_id', (int) $owner['id'])->row_array();
            if (!$request || $request['status'] !== 'awaiting_payment') {
                $this->respond(409, ['success' => false, 'message' => 'This move-in payment is no longer awaiting settlement.']);
            }
            $payment = $db->raw(
                'SELECT id, rental_request_id, tenant_id, rental_id, reference_number, amount_centavos, currency, payment_method, payment_note, status, paid_at, '
                . 'receipt_image IS NOT NULL AS receipt_available FROM rental_payments WHERE rental_request_id = ?',
                [(int) $request['id']]
            )->fetch(PDO::FETCH_ASSOC);
            if ($action === 'confirm') {
                if (!$payment || $payment['status'] !== 'pending_owner_confirmation' || !in_array($payment['payment_method'], ['gcash', 'maya', 'cash'], true) || empty($payment['receipt_available'])) {
                    $this->respond(409, ['success' => false, 'message' => 'The tenant must attach a valid JPG receipt before you can confirm this payment.']);
                }
                $schedule_ready = $this->finalize_initial_payment($payment, $payment['payment_method']);
            } elseif ($action === 'reject') {
                if (!$payment || $payment['status'] !== 'pending_owner_confirmation') {
                    $this->respond(409, ['success' => false, 'message' => 'There is no reported physical payment to reject.']);
                }
                $db->table('rental_payments')->where('id', (int) $payment['id'])->update([
                    'status' => 'failed',
                    'payment_note' => null,
                    'receipt_image' => null,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
            $message = $action === 'reject'
                ? 'Reported payment rejected. The tenant can submit a new payment.'
                : ($payment['payment_method'] === 'cash'
                    ? 'Cash receipt reviewed and payment confirmed. The rental is now activated.'
                    : (!empty($schedule_ready)
                        ? 'Receipt reviewed and wallet payment confirmed. The rental is now activated.'
                        : 'Receipt reviewed and wallet payment confirmed. The rental is now activated and the rent schedule is still being prepared; refresh the tenant payment page shortly.'));
            $this->respond(200, ['success' => true, 'message' => $message]);
        }

        $installment = $db->raw(
            'SELECT id, rental_request_id, installment_number, due_date, amount_centavos, status, payment_method, payment_note, reference_number, '
            . 'receipt_image IS NOT NULL AS receipt_available FROM rental_installments WHERE id = ?',
            [(int) $id]
        )->fetch(PDO::FETCH_ASSOC);
        $request = $installment
            ? $db->table('rental_requests')->where('id', (int) $installment['rental_request_id'])->where('owner_id', (int) $owner['id'])->row_array()
            : null;
        if (!$installment || !$request) $this->respond(404, ['success' => false, 'message' => 'This rent installment was not found for your account.']);
        if ($action === 'confirm') {
            if ($installment['status'] !== 'pending_owner_confirmation' || !in_array($installment['payment_method'], ['gcash', 'maya', 'cash'], true) || empty($installment['receipt_available'])) {
                $this->respond(409, ['success' => false, 'message' => 'The tenant must attach a valid JPG receipt before you can confirm this payment.']);
            }
            $method = $installment['payment_method'];
        } elseif ($action === 'reject') {
            if ($installment['status'] !== 'pending_owner_confirmation') {
                $this->respond(409, ['success' => false, 'message' => 'There is no reported physical payment to reject.']);
            }
            $db->table('rental_installments')->where('id', (int) $installment['id'])->update([
                'status' => $this->installment_status_for_due_date($installment['due_date']),
                'payment_method' => null,
                'payment_note' => null,
                'receipt_image' => null,
                'reference_number' => null,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $this->respond(200, ['success' => true, 'message' => 'Reported payment rejected. The tenant can submit a new payment.']);
        }
        $db->table('rental_installments')->where('id', (int) $installment['id'])->update([
            'status' => 'paid',
            'payment_method' => $method,
            'payment_note' => $action === 'confirm' ? $installment['payment_note'] : null,
            'provider_payment_id' => null,
            'paid_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->respond(200, ['success' => true, 'message' => $method === 'cash'
            ? 'Cash receipt reviewed and payment confirmed.'
            : 'Receipt reviewed and wallet payment confirmed.']);
    }

    public function owner_income()
    {
        $this->require_method('GET');
        $owner = $this->require_owner();
        $this->call->model('Sales_model');
        $income = $this->Sales_model->ownerSales((int) $owner['id']);
        $this->respond(200, [
            'success' => true,
            'income' => $income,
        ]);
    }

    public function owner_sales_analytics()
    {
        $this->require_method('GET');
        $owner = $this->require_owner();
        $this->call->model('Sales_model');
        $this->respond(200, [
            'success' => true,
            'sales' => $this->Sales_model->ownerSales((int) $owner['id']),
        ]);
    }

    public function owner_ratings()
    {
        $this->require_method('GET');
        $owner = $this->require_owner();
        $ratings = $this->call->database()->raw(
            'SELECT rental.id AS rental_id, rental.title AS rental_title, rental.property_type, '
            . 'AVG(review.rating) AS average_rating, COUNT(review.id) AS review_count '
            . 'FROM rental_reviews review '
            . 'INNER JOIN rentals rental ON rental.id = review.rental_id '
            . 'WHERE rental.owner_id = ? AND review.status = ? '
            . 'GROUP BY rental.id, rental.title, rental.property_type '
            . 'ORDER BY average_rating DESC, review_count DESC, rental.title ASC LIMIT 20',
            [(int) $owner['id'], 'approved']
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($ratings as &$rating) {
            $rating['rental_id'] = (int) $rating['rental_id'];
            $rating['average_rating'] = round((float) $rating['average_rating'], 2);
            $rating['review_count'] = (int) $rating['review_count'];
        }
        unset($rating);
        $this->respond(200, ['success' => true, 'top_rentals' => $ratings]);
    }

    public function xendit_webhook()
    {
        $this->require_method('POST');
        $webhook_token = trim((string) rentease_env('XENDIT_WEBHOOK_TOKEN'));
        if ($webhook_token === '') {
            $this->respond(503, ['success' => false, 'message' => 'Xendit webhook is not configured.']);
        }
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $provided_token = (string) ($_SERVER['HTTP_X_CALLBACK_TOKEN'] ?? '');
        foreach ($headers as $name => $value) {
            if (strcasecmp($name, 'x-callback-token') === 0) {
                $provided_token = (string) $value;
                break;
            }
        }
        if ($provided_token === '' || !hash_equals($webhook_token, $provided_token)) {
            $this->respond(401, ['success' => false, 'message' => 'Invalid Xendit webhook token.']);
        }

        $event = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($event)) {
            $this->respond(400, ['success' => false, 'message' => 'Invalid Xendit webhook payload.']);
        }
        $event_type = (string) ($event['event'] ?? '');
        if (!in_array($event_type, ['payment.capture', 'payment_request.capture'], true)) {
            $this->respond(200, ['success' => true, 'message' => 'Event ignored.']);
        }
        $webhook_payment = $event['data'] ?? $event;
        $payment_id = (string) ($webhook_payment['payment_id'] ?? $webhook_payment['id'] ?? '');
        $reference = (string) ($webhook_payment['reference_id'] ?? '');
        if ($payment_id === '' || $reference === '') {
            $this->respond(422, ['success' => false, 'message' => 'Webhook is missing the Xendit payment ID or reference.']);
        }

        [$secret_key] = $this->xendit_configuration();
        $lookup = $this->xendit_request('GET', 'https://api.xendit.co/v3/payments/' . rawurlencode($payment_id), $secret_key);
        if (!$lookup['success']) {
            error_log('Xendit payment verification failed for payment ' . $payment_id . ' (HTTP ' . $lookup['http_status'] . ').');
            $this->respond(502, ['success' => false, 'message' => 'Xendit payment could not be verified.']);
        }
        $verified = $lookup['body'];
        if (($verified['status'] ?? '') !== 'SUCCEEDED'
            || (string) ($verified['reference_id'] ?? '') !== $reference
            || strtoupper((string) ($verified['currency'] ?? '')) !== 'PHP') {
            $this->respond(422, ['success' => false, 'message' => 'Xendit payment status, reference, or currency does not match.']);
        }
        $capture_amount = 0.0;
        foreach (($verified['captures'] ?? []) as $capture) {
            $capture_amount += (float) ($capture['capture_amount'] ?? 0);
        }
        if ($capture_amount <= 0) {
            $capture_amount = (float) ($verified['request_amount'] ?? 0);
        }
        $paid_centavos = (int) round($capture_amount * 100);

        $db = $this->call->database();
        $installment = $db->table('rental_installments')
            ->where('reference_number', $reference)
            ->where('checkout_session_id', (string) ($verified['payment_request_id'] ?? ''))
            ->row_array();
        if ($installment) {
            if ((int) $installment['amount_centavos'] !== $paid_centavos) {
                error_log('Xendit rent installment amount mismatch for reference ' . $reference . '.');
                $this->respond(422, ['success' => false, 'message' => 'Xendit amount does not match the rent installment.']);
            }
            if ($installment['status'] === 'paid') $this->respond(200, ['success' => true, 'message' => 'Rent installment was already recorded.']);
            if ($installment['status'] !== 'pending') $this->respond(409, ['success' => false, 'message' => 'Rent installment is not awaiting online payment.']);
            $db->table('rental_installments')->where('id', (int) $installment['id'])->where('status', 'pending')->update([
                'status' => 'paid',
                'payment_method' => $installment['payment_method'] === 'maya' ? 'maya' : 'gcash',
                'provider_payment_id' => $payment_id,
                'paid_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $this->respond(200, ['success' => true, 'message' => 'Rent installment recorded.']);
        }

        $payment = $db->table('rental_payments')
            ->where('reference_number', $reference)
            ->where('checkout_session_id', (string) ($verified['payment_request_id'] ?? ''))
            ->row_array();
        if (!$payment) $this->respond(404, ['success' => false, 'message' => 'Payment reference was not found.']);
        if ((int) $payment['amount_centavos'] !== $paid_centavos) {
            error_log('Xendit initial payment amount mismatch for reference ' . $reference . '.');
            $this->respond(422, ['success' => false, 'message' => 'Xendit amount does not match the rental request.']);
        }
        if ($payment['status'] === 'paid') {
            $this->finalize_initial_payment($payment, $payment['payment_method'] === 'maya' ? 'maya' : 'gcash', $payment_id);
            $this->respond(200, ['success' => true, 'message' => 'Payment was already recorded and the rental status was synchronized.']);
        }
        if ($payment['status'] !== 'pending') $this->respond(409, ['success' => false, 'message' => 'Payment is not awaiting online confirmation.']);
        $schedule_ready = $this->finalize_initial_payment($payment, $payment['payment_method'] === 'maya' ? 'maya' : 'gcash', $payment_id);
        $this->respond(200, [
            'success' => true,
            'message' => $schedule_ready
                ? 'Payment recorded and rental confirmed.'
                : 'Payment recorded and rental confirmed. The rent schedule is still being prepared; refresh shortly.',
        ]);
    }

    private function finalize_initial_payment(array $payment, $method, $provider_payment_id = null, $manage_transaction = true)
    {
        $db = $this->call->database();
        $request = $db->table('rental_requests')
            ->where('id', (int) $payment['rental_request_id'])
            ->where('tenant_id', (int) $payment['tenant_id'])
            ->where('status', 'awaiting_payment')
            ->row_array();
        if (!$request) {
            $existing = $db->table('rental_payments')->where('id', (int) $payment['id'])->row_array();
            if ($existing && $existing['status'] === 'paid') {
                $approved_request = $db->table('rental_requests')
                    ->where('id', (int) $payment['rental_request_id'])
                    ->where('tenant_id', (int) $payment['tenant_id'])
                    ->where('status', 'approved')
                    ->row_array();
                $rental = $db->table('rentals')->where('id', (int) $payment['rental_id'])->row_array();
                return $approved_request && $rental
                    ? $this->create_installments_safely($approved_request, $rental)
                    : true;
            }
            $this->respond(409, ['success' => false, 'message' => 'The rental request is no longer awaiting its first payment.']);
        }

        if ($manage_transaction) $db->transaction();
        require_once APP_DIR . 'libraries' . DIRECTORY_SEPARATOR . 'RentalCapacityService.php';
        $capacity_service = new RentalCapacityService($db);
        $rental = $capacity_service->lockRental((int) $payment['rental_id']);
        if (!$rental) {
            if ($manage_transaction) $db->roll_back();
            $this->respond(409, ['success' => false, 'message' => 'The rental listing could not be found while confirming payment.']);
        }
        $db->table('rental_payments')->where('id', (int) $payment['id'])->update([
            'status' => 'paid',
            'payment_method' => $method,
            'provider_payment_id' => $provider_payment_id,
            'paid_at' => $payment['paid_at'] ?? date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $db->table('rental_requests')
            ->where('id', (int) $request['id'])
            ->where('status', 'awaiting_payment')
            ->update(['status' => 'approved', 'updated_at' => date('Y-m-d H:i:s')]);
        if ($rental['status'] === 'published'
            && $capacity_service->paidSlots((int) $payment['rental_id']) >= $capacity_service->capacity($rental)) {
            $db->table('rentals')
                ->where('id', (int) $payment['rental_id'])
                ->where('status', 'published')
                ->update(['status' => 'rented', 'updated_at' => date('Y-m-d H:i:s')]);
        }
        $rental = $db->table('rentals')->where('id', (int) $payment['rental_id'])->row_array();
        if ($manage_transaction) $db->commit();
        return $manage_transaction && $rental
            ? $this->create_installments_safely($request, $rental, true)
            : true;
    }

    private function create_installments_safely(array $rental_request, array $rental, $confirmed_initial_payment = false)
    {
        try {
            $this->create_installments($rental_request, $rental, $confirmed_initial_payment);
            return true;
        } catch (PDOException $error) {
            error_log(
                'RentEase could not prepare installments for rental request '
                . (int) $rental_request['id'] . ': ' . $error->getMessage()
            );
            return false;
        }
    }

    private function validate_online_method($method)
    {
        $method = strtolower(trim((string) $method));
        if (!in_array($method, ['gcash', 'maya'], true)) {
            $this->respond(422, ['success' => false, 'message' => 'Choose GCash or Maya for online payment.']);
        }
        return $method;
    }

    private function validate_wallet_method($method)
    {
        return $this->validate_online_method($method);
    }

    private function validate_receipt_image($data)
    {
        if (!is_string($data)
            || strlen($data) > 2800000
            || !preg_match('#^data:image/jpeg;base64,([A-Za-z0-9+/]+={0,2})$#', $data, $matches)) {
            $this->respond(422, ['success' => false, 'message' => 'Upload a JPG screenshot of the GCash or Maya receipt (up to 2 MB).']);
        }
        $image = base64_decode($matches[1], true);
        if ($image === false || strlen($image) < 1 || strlen($image) > 2 * 1024 * 1024) {
            $this->respond(422, ['success' => false, 'message' => 'The JPG receipt is empty, invalid, or larger than 2 MB.']);
        }
        $image_info = getimagesizefromstring($image);
        if (($image_info['mime'] ?? '') !== 'image/jpeg') {
            $this->respond(422, ['success' => false, 'message' => 'Only valid JPG/JPEG payment receipts are accepted.']);
        }
        return $image;
    }

    private function validate_qr_image($data, $wallet)
    {
        if ($data === null || $data === '') return null;
        if (!is_string($data) || strlen($data) > 1500000
            || !preg_match('#^data:image/(png|jpeg|webp);base64,([A-Za-z0-9+/=]+)$#', $data, $matches)) {
            $this->respond(422, ['success' => false, 'message' => $wallet . ' QR must be a PNG, JPEG, or WebP image under 1 MB.']);
        }
        $image = base64_decode($matches[2], true);
        if ($image === false || strlen($image) > 1048576) {
            $this->respond(422, ['success' => false, 'message' => $wallet . ' QR image exceeds the 1 MB limit or is invalid.']);
        }
        $image_info = getimagesizefromstring($image);
        $mime = $image_info['mime'] ?? '';
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            $this->respond(422, ['success' => false, 'message' => $wallet . ' QR must be a valid PNG, JPEG, or WebP image.']);
        }
        return 'data:' . $mime . ';base64,' . base64_encode($image);
    }

    private function require_tenant()
    {
        $user = $this->current_user();
        if (!$user) $this->respond(401, ['success' => false, 'message' => 'Sign in as a tenant to continue.']);
        if ($user['role'] === 'admin' || $user['account_type'] !== 'tenant') {
            $this->respond(403, ['success' => false, 'message' => 'Only tenants can submit physical rent payments.']);
        }
        return $user;
    }

    private function require_owner()
    {
        $user = $this->current_user();
        if (!$user) $this->respond(401, ['success' => false, 'message' => 'Sign in as a property owner to continue.']);
        if ($user['role'] === 'admin' || $user['account_type'] !== 'owner' || $user['owner_status'] !== 'verified') {
            $this->respond(403, ['success' => false, 'message' => 'A verified property owner account is required.']);
        }
        return $user;
    }

    private function xendit_configuration()
    {
        $mode = (string) rentease_env('XENDIT_MODE', 'test');
        $secret_key = trim((string) rentease_env('XENDIT_SECRET_KEY'));
        $webhook_token = trim((string) rentease_env('XENDIT_WEBHOOK_TOKEN'));
        if (!in_array($mode, ['test', 'live'], true) || $secret_key === '' || $webhook_token === '') {
            $this->respond(503, ['success' => false, 'message' => 'Online GCash/Maya is not configured yet. Add the Xendit test key and webhook token in BackendLL/.env.']);
        }
        return [$secret_key, $webhook_token, $mode];
    }

    private function xendit_redirect_url($actions)
    {
        foreach ($actions as $action) {
            if (($action['type'] ?? '') !== 'REDIRECT_CUSTOMER') continue;
            $url = (string) ($action['value'] ?? '');
            $parts = parse_url($url);
            if ($parts && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
                && preg_match('/(^|\.)xendit\.co$/i', strtolower((string) ($parts['host'] ?? '')))) {
                return $url;
            }
        }
        return '';
    }

    private function ensure_installments(array $rental_request, array $rental)
    {
        $db = $this->call->database();
        $existing = $db->table('rental_installments')
            ->select('id, status, due_date')
            ->where('rental_request_id', (int) $rental_request['id'])
            ->order_by('installment_number', 'ASC')
            ->get_all();
        if ($existing) {
            $today = date('Y-m-d');
            foreach ($existing as $installment) {
                if (!in_array($installment['status'], ['upcoming', 'due', 'overdue'], true)) continue;
                $effective_status = $installment['due_date'] < $today
                    ? 'overdue'
                    : ($installment['due_date'] > $today ? 'upcoming' : 'due');
                if ($installment['status'] !== $effective_status) {
                    $db->table('rental_installments')
                        ->where('id', (int) $installment['id'])
                        ->update(['status' => $effective_status, 'updated_at' => date('Y-m-d H:i:s')]);
                }
            }
            return $this->get_installments_with_receipt_flags((int) $rental_request['id']);
        }
        $this->create_installments($rental_request, $rental);
        return $this->get_installments_with_receipt_flags((int) $rental_request['id']);
    }

    private function installment_status_for_due_date($due_date)
    {
        $date = (string) $due_date;
        $today = date('Y-m-d');
        if ($date < $today) return 'overdue';
        return $date > $today ? 'upcoming' : 'due';
    }

    private function create_installments(array $rental_request, array $rental, $confirmed_initial_payment = false)
    {
        $db = $this->call->database();
        $db->transaction();
        try {
            $db->raw('SELECT id FROM rental_requests WHERE id = ? FOR UPDATE', [
                (int) $rental_request['id'],
            ])->fetchColumn();
            $initial_payment = $db->table('rental_payments')
                ->select('payment_method, paid_at')
                ->where('rental_request_id', (int) $rental_request['id'])
                ->where('status', 'paid')
                ->row_array();
            $first_is_paid = $confirmed_initial_payment || (bool) $initial_payment;
            $existing_installments = $db->table('rental_installments')
                ->select('id, installment_number, status')
                ->where('rental_request_id', (int) $rental_request['id'])
                ->get_all();
            $existing_by_number = [];
            foreach ($existing_installments as $installment) {
                $existing_by_number[(int) $installment['installment_number']] = $installment;
            }
            $rent_centavos = $this->money_to_centavos($rental['price']);
            $deposit_centavos = $this->money_to_centavos($rental['security_deposit'] ?? '0.00');
            $term_months = max(1, min(36, (int) $rental_request['term_months']));
            $due_date = new DateTime($rental_request['move_in']);
            $due_day_setting = $db->table('platform_settings')->where('setting_key', 'rent_due_day')->row_array();
            $day_of_month = max(1, min(28, (int) ($due_day_setting['setting_value'] ?? 1)));
            $rows = [];
            for ($month = 1; $month <= $term_months; $month++) {
                if ($month > 1) {
                    $due_date->modify('first day of next month');
                    $last_day = (int) $due_date->format('t');
                    $due_date->setDate((int) $due_date->format('Y'), (int) $due_date->format('n'), min($day_of_month, $last_day));
                }
                $installment_due_date = $due_date->format('Y-m-d');
                $installment_status = $month === 1 && $first_is_paid
                    ? 'paid'
                    : ($installment_due_date < date('Y-m-d') ? 'overdue' : ($installment_due_date > date('Y-m-d') ? 'upcoming' : 'due'));
                if (isset($existing_by_number[$month])) {
                    if ($month === 1 && $first_is_paid && $existing_by_number[$month]['status'] !== 'paid') {
                        $db->table('rental_installments')
                            ->where('id', (int) $existing_by_number[$month]['id'])
                            ->update([
                                'status' => 'paid',
                                'payment_method' => $initial_payment['payment_method'] ?? 'online',
                                'paid_at' => $initial_payment['paid_at'] ?? date('Y-m-d H:i:s'),
                                'updated_at' => date('Y-m-d H:i:s'),
                            ]);
                    }
                    continue;
                }
                $rows[] = [
                    'rental_request_id' => (int) $rental_request['id'],
                    'tenant_id' => (int) $rental_request['tenant_id'],
                    'rental_id' => (int) $rental_request['rental_id'],
                    'installment_number' => $month,
                    'due_date' => $installment_due_date,
                    'amount_centavos' => $month === 1 && !$first_is_paid ? $rent_centavos + $deposit_centavos : $rent_centavos,
                    'status' => $installment_status,
                    'payment_method' => $month === 1 && $first_is_paid ? ($initial_payment['payment_method'] ?? 'online') : null,
                    'paid_at' => $month === 1 && $first_is_paid ? ($initial_payment['paid_at'] ?? date('Y-m-d H:i:s')) : null,
                    'created_at' => date('Y-m-d H:i:s'),
                ];
            }
            foreach ($rows as $row) $db->table('rental_installments')->insert($row);
            $installments = $db->table('rental_installments')
                ->where('rental_request_id', (int) $rental_request['id'])
                ->order_by('installment_number', 'ASC')
                ->get_all();
            $db->commit();
            return $installments;
        } catch (PDOException $error) {
            $db->roll_back();
            throw $error;
        }
    }

    private function get_installments_with_receipt_flags($request_id)
    {
        return $this->call->database()->raw(
            'SELECT id, rental_request_id, tenant_id, rental_id, installment_number, due_date, amount_centavos, status, payment_method, payment_note, '
            . 'reference_number, checkout_session_id, checkout_url, provider_payment_id, paid_at, created_at, updated_at, '
            . 'receipt_image IS NOT NULL AS receipt_available FROM rental_installments WHERE rental_request_id = ? ORDER BY installment_number ASC',
            [(int) $request_id]
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    private function receipt_is_attached($table, $id)
    {
        if (!in_array($table, ['rental_payments', 'rental_installments'], true)) {
            throw new InvalidArgumentException('Unsupported payment receipt table.');
        }
        return (bool) $this->call->database()->raw(
            'SELECT receipt_image IS NOT NULL FROM `' . $table . '` WHERE id = ?',
            [(int) $id]
        )->fetchColumn();
    }

    private function xendit_request($method, $url, $secret_key, array $payload = [], $idempotency_key = '')
    {
        $curl = curl_init($url);
        if ($curl === false) {
            return ['success' => false, 'http_status' => 0, 'body' => []];
        }
        $headers = [
            'Authorization: Basic ' . base64_encode($secret_key . ':'),
            'Accept: application/json',
        ];
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
        ];
        if ($method !== 'GET') {
            $headers[] = 'Content-Type: application/json';
            if ($idempotency_key !== '') $headers[] = 'Idempotency-Key: ' . $idempotency_key;
            $options[CURLOPT_HTTPHEADER] = $headers;
            $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_SLASHES);
        }
        curl_setopt_array($curl, $options);
        $response_body = curl_exec($curl);
        $http_status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($curl);
        curl_close($curl);

        if ($response_body === false) {
            error_log('Xendit API connection failed: ' . $curl_error);
            return ['success' => false, 'http_status' => $http_status, 'body' => []];
        }
        $decoded = json_decode($response_body, true);
        return [
            'success' => $http_status >= 200 && $http_status < 300 && is_array($decoded),
            'http_status' => $http_status,
            'body' => is_array($decoded) ? $decoded : [],
        ];
    }

    private function money_to_centavos($amount)
    {
        $amount = trim((string) $amount);
        if (!preg_match('/^([0-9]+)(?:\.([0-9]{1,2}))?$/', $amount, $matches)) {
            return 0;
        }
        $fraction = str_pad($matches[2] ?? '', 2, '0');
        return ((int) $matches[1] * 100) + (int) $fraction;
    }

    private function current_user()
    {
        $this->call->library('session');
        $user_id = (int) load_class('session', 'libraries')->userdata('user_id');
        if ($user_id < 1) {
            return null;
        }
        $user = $this->call->database()->table('users')->where('id', $user_id)->row_array();
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
