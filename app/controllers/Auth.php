<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Auth extends Controller
{
    public function register()
    {
        $this->require_method('POST');
        $input = $this->json_input();

        $full_name = trim((string) ($input['full_name'] ?? ''));
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $phone = trim((string) ($input['phone'] ?? ''));
        $phone_digits = preg_replace('/\D/', '', $phone);
        $password = (string) ($input['password'] ?? '');
        $password_confirmation = (string) ($input['password_confirmation'] ?? '');
        $account_type = (string) ($input['account_type'] ?? '');

        if (!preg_match('/^.{2,100}$/u', $full_name)) {
            $this->respond(422, ['success' => false, 'message' => 'Enter a name between 2 and 100 characters.']);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
            $this->respond(422, ['success' => false, 'message' => 'Enter a valid email address.']);
        }

        if (strlen($phone) > 30 || strlen($phone_digits) < 7 || strlen($phone_digits) > 20 || !preg_match('/^[0-9+().\-\s]+$/', $phone)) {
            $this->respond(422, ['success' => false, 'message' => 'Enter a valid mobile number.']);
        }

        if (strlen($password) < 8 || strlen($password) > 72) {
            $this->respond(422, ['success' => false, 'message' => 'Use a password between 8 and 72 characters.']);
        }

        if (!hash_equals($password, $password_confirmation)) {
            $this->respond(422, ['success' => false, 'message' => 'The passwords do not match.']);
        }

        if (!in_array($account_type, ['tenant', 'owner'], true)) {
            $this->respond(422, ['success' => false, 'message' => 'Choose tenant or property owner.']);
        }

        $this->verify_recaptcha($input['recaptcha_token'] ?? '');

        $this->call->model('User_model');
        $users = $this->User_model;
        $existing_user = $users->findByEmail($email);

        if ($existing_user) {
            $this->respond(409, ['success' => false, 'message' => 'An account with this email already exists.']);
        }

        $user_id = $users->createAccount([
            'username' => 'user_' . bin2hex(random_bytes(16)),
            'email' => $email,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'role' => 'user',
            'is_active' => 1,
            'full_name' => $full_name,
            'phone' => $phone,
            'account_type' => $account_type,
            'owner_status' => $account_type === 'owner' ? 'pending' : 'not_required',
        ]);

        $session = $this->session();
        $session->sess_regenerate(true);
        $session->set_userdata('user_id', (int) $user_id);

        $this->respond(201, [
            'success' => true,
            'user' => [
                'id' => (int) $user_id,
                'full_name' => $full_name,
                'email' => $email,
                'phone' => $phone,
                'account_type' => $account_type,
                'role' => 'user',
                'owner_status' => $account_type === 'owner' ? 'pending' : 'not_required',
                'email_verified_at' => null,
            ],
        ]);
    }

    public function login()
    {
        $this->require_method('POST');
        $input = $this->json_input();
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $password = (string) ($input['password'] ?? '');

        if ($email === '' || $password === '') {
            $this->respond(422, ['success' => false, 'message' => 'Enter your email address and password.']);
        }

        $this->verify_recaptcha($input['recaptcha_token'] ?? '');

        $this->call->model('User_model');
        $users = $this->User_model;
        $user = $users->findByEmail($email);

        if (!$user || !(bool) $user['is_active'] || !password_verify($password, $user['password'])) {
            $this->respond(401, ['success' => false, 'message' => 'Email or password is incorrect.']);
        }

        if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
            $users->updatePassword((int) $user['id'], password_hash($password, PASSWORD_DEFAULT));
        }

        $session = $this->session();
        $session->sess_regenerate(true);
        $session->set_userdata('user_id', (int) $user['id']);

        $this->respond(200, [
            'success' => true,
            'user' => $this->public_user($user),
        ]);
    }

    public function me()
    {
        $this->require_method('GET');
        $session = $this->session();
        $user_id = (int) $session->userdata('user_id');

        if ($user_id < 1) {
            $this->respond(401, ['success' => false, 'message' => 'You are not signed in.']);
        }

        $this->call->model('User_model');
        $user = $this->User_model->findById($user_id);

        if (!$user || !(bool) $user['is_active']) {
            $session->unset_userdata('user_id');
            $this->respond(401, ['success' => false, 'message' => 'Your session is no longer active.']);
        }

        $this->respond(200, ['success' => true, 'user' => $this->public_user($user)]);
    }

    public function profile_photo()
    {
        $this->require_method('POST');
        $session = $this->session();
        $user_id = (int) $session->userdata('user_id');
        if ($user_id < 1) {
            $this->respond(401, ['success' => false, 'message' => 'Sign in to update your profile photo.']);
        }

        $file = $_FILES['profile_photo'] ?? [];
        $upload_error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($upload_error !== UPLOAD_ERR_OK) {
            $message = $upload_error === UPLOAD_ERR_INI_SIZE || $upload_error === UPLOAD_ERR_FORM_SIZE
                ? 'Choose a JPEG or PNG photo no larger than 2 MB.'
                : 'Choose a profile photo from your device.';
            $this->respond(422, ['success' => false, 'message' => $message]);
        }

        $temporary_path = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        if ($size < 1 || $size > 2 * 1024 * 1024 || !is_uploaded_file($temporary_path)) {
            $this->respond(422, ['success' => false, 'message' => 'Choose a JPEG or PNG photo no larger than 2 MB.']);
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($temporary_path);
        if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
            $this->respond(422, ['success' => false, 'message' => 'Only JPEG and PNG profile photos are accepted.']);
        }

        $image_data = file_get_contents($temporary_path);
        if ($image_data === false) {
            $this->respond(500, ['success' => false, 'message' => 'The uploaded profile photo could not be read.']);
        }
        $image_info = getimagesizefromstring($image_data);
        $width = (int) ($image_info[0] ?? 0);
        $height = (int) ($image_info[1] ?? 0);
        if (!$image_info || ($image_info['mime'] ?? '') !== $mime || $width < 1 || $height < 1 || $width > 6000 || $height > 6000 || $width * $height > 36000000) {
            $this->respond(422, ['success' => false, 'message' => 'The file must be a valid JPEG or PNG photo with reasonable image dimensions.']);
        }

        $db = $this->call->database();
        $db->table('users')
            ->where('id', $user_id)
            ->update([
                'profile_photo_data' => base64_encode($image_data),
                'profile_photo_mime' => $mime,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        $user = $db->table('users')->where('id', $user_id)->row_array();
        if (!$user || !(bool) $user['is_active']) {
            $session->unset_userdata('user_id');
            $this->respond(401, ['success' => false, 'message' => 'Your session is no longer active.']);
        }

        $this->respond(200, [
            'success' => true,
            'message' => 'Profile photo updated.',
            'user' => $this->public_user($user),
        ]);
    }

    public function logout()
    {
        $this->require_method('POST');
        $session = $this->session();
        $session->unset_userdata('user_id');
        $session->sess_regenerate(true);

        $this->respond(200, ['success' => true, 'message' => 'You have been signed out.']);
    }

    public function forgot_password()
    {
        $this->require_method('POST');
        $input = $this->json_input();
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
            $this->respond(422, ['success' => false, 'message' => 'Enter a valid email address.']);
        }

        $user = $this->find_active_user_by_email($email);
        if ($user) {
            $this->issue_email_code($user, 'password_reset');
        }
        $this->respond(200, [
            'success' => true,
            'message' => 'If an active account uses that email, a password reset code may be sent. For security, we cannot confirm account existence or email delivery here.',
        ]);
    }

    public function verify()
    {
        $this->require_method('POST');
        $input = $this->json_input();
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $code = trim((string) ($input['code'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255
            || !preg_match('/^[0-9]{6}$/', $code)) {
            $this->respond(422, ['success' => false, 'message' => 'Enter your email address and the 6-digit code.']);
        }

        $user = $this->find_active_user_by_email($email);
        if (!$user) {
            $this->respond(422, ['success' => false, 'message' => 'The code is invalid or expired. Request a new code and try again.']);
        }
        if (!empty($user['email_verified_at'])) {
            $this->respond(200, ['success' => true, 'verified' => true, 'message' => 'Your email is already verified.']);
        }

        $record = $this->find_valid_email_code((int) $user['id'], 'verification');
        if (!$record || (int) $record['attempts'] >= 5) {
            $this->respond(422, ['success' => false, 'message' => 'The code is invalid or expired. Request a new code and try again.']);
        }
        if (!password_verify($code, $record['code_hash'])) {
            $this->call->database()->raw(
                'UPDATE `email_verification_codes` SET `attempts` = `attempts` + 1 '
                . 'WHERE `id` = ? AND `attempts` < 5 AND `consumed_at` IS NULL',
                [(int) $record['id']]
            );
            $this->respond(422, ['success' => false, 'message' => 'The code is invalid or expired. Request a new code and try again.']);
        }

        $db = $this->call->database();
        $now = date('Y-m-d H:i:s');
        $db->transaction();
        if (!$this->consume_email_code($db, (int) $record['id'], $now)) {
            $db->roll_back();
            $this->respond(422, ['success' => false, 'message' => 'The code is invalid or expired. Request a new code and try again.']);
        }
        $db->table('users')
            ->where('id', (int) $user['id'])
            ->update(['email_verified_at' => $now, 'updated_at' => $now]);
        $db->commit();
        $this->respond(200, ['success' => true, 'message' => 'Your email address has been verified.']);
    }

    public function send_verification_code()
    {
        $this->require_method('POST');
        $input = $this->json_input();
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
            $this->respond(422, ['success' => false, 'message' => 'Enter a valid email address.']);
        }

        $user = $this->find_active_user_by_email($email);
        if (!$user) {
            $this->respond(200, [
                'success' => true,
                'message' => 'If an active account needs verification, a code has been sent.',
            ]);
        }
        if (!empty($user['email_verified_at'])) {
            $this->respond(200, [
                'success' => true,
                'message' => 'If an active account needs verification, a code has been sent.',
            ]);
        }
        $this->issue_email_code($user, 'verification');
        $this->respond(200, [
            'success' => true,
            'message' => 'If an active account needs verification, a code has been sent.',
        ]);
    }

    public function reset_password()
    {
        $this->require_method('POST');
        $input = $this->json_input();
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $code = trim((string) ($input['code'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $password_confirmation = (string) ($input['password_confirmation'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255
            || !preg_match('/^[0-9]{6}$/', $code)) {
            $this->respond(422, ['success' => false, 'message' => 'Enter your email address and the 6-digit reset code.']);
        }
        if (strlen($password) < 8 || strlen($password) > 72) {
            $this->respond(422, ['success' => false, 'message' => 'Use a password between 8 and 72 characters.']);
        }
        if (!hash_equals($password, $password_confirmation)) {
            $this->respond(422, ['success' => false, 'message' => 'The passwords do not match.']);
        }

        $user = $this->find_active_user_by_email($email);
        $record = $user ? $this->find_valid_email_code((int) $user['id'], 'password_reset') : null;
        if (!$record || (int) $record['attempts'] >= 5) {
            $this->respond(422, ['success' => false, 'message' => 'The code is invalid or expired. Request a new code and try again.']);
        }
        if (!password_verify($code, $record['code_hash'])) {
            $this->call->database()->raw(
                'UPDATE `email_verification_codes` SET `attempts` = `attempts` + 1 '
                . 'WHERE `id` = ? AND `attempts` < 5 AND `consumed_at` IS NULL',
                [(int) $record['id']]
            );
            $this->respond(422, ['success' => false, 'message' => 'The code is invalid or expired. Request a new code and try again.']);
        }

        $db = $this->call->database();
        $now = date('Y-m-d H:i:s');
        $this->call->model('User_model');
        $db->transaction();
        if (!$this->consume_email_code($db, (int) $record['id'], $now)) {
            $db->roll_back();
            $this->respond(422, ['success' => false, 'message' => 'The code is invalid or expired. Request a new code and try again.']);
        }
        $this->User_model->updatePassword((int) $user['id'], password_hash($password, PASSWORD_DEFAULT));
        $db->commit();
        $this->respond(200, ['success' => true, 'message' => 'Your password has been reset. You can now sign in.']);
    }

    private function find_active_user_by_email($email)
    {
        $this->call->model('User_model');
        $user = $this->User_model->findByEmail($email);
        return $user && (bool) $user['is_active'] ? $user : null;
    }

    private function issue_email_code(array $user, $purpose)
    {
        $db = $this->call->database();
        $recent = $db->table('email_verification_codes')
            ->where('user_id', (int) $user['id'])
            ->where('purpose', $purpose)
            ->order_by('created_at', 'DESC')
            ->row_array();
        if ($recent && empty($recent['consumed_at']) && strtotime($recent['created_at']) > time() - 60) {
            return false;
        }

        $code = (string) random_int(100000, 999999);
        $now = date('Y-m-d H:i:s');
        $db->raw(
            'UPDATE `email_verification_codes` SET `consumed_at` = ? '
            . 'WHERE `user_id` = ? AND `purpose` = ? AND `consumed_at` IS NULL',
            [$now, (int) $user['id'], $purpose]
        );
        $db->table('email_verification_codes')->insert([
            'user_id' => (int) $user['id'],
            'purpose' => $purpose,
            'code_hash' => password_hash($code, PASSWORD_DEFAULT),
            'attempts' => 0,
            'expires_at' => date('Y-m-d H:i:s', time() + 600),
            'consumed_at' => null,
            'created_at' => $now,
        ]);

        try {
            $mailer = $this->call->library('gmail_smtp');
            $mailer->send_code($user['email'], $code, $purpose, 10);
            return true;
        } catch (Throwable $exception) {
            $db->raw(
                'UPDATE `email_verification_codes` SET `consumed_at` = ? '
                . 'WHERE `user_id` = ? AND `purpose` = ? AND `created_at` = ?',
                [date('Y-m-d H:i:s'), (int) $user['id'], $purpose, $now]
            );
            $provider = strtolower(trim((string) rentease_env('MAIL_PROVIDER', 'smtp')));
            error_log('RentEase email code delivery failed for user ' . (int) $user['id'] . ' via ' . $provider . ': ' . $exception->getMessage());
            if ($provider === 'gmail_api' && strpos($exception->getMessage(), 'Gmail API configuration') !== false) {
                $message = 'Gmail API environment settings are incomplete. Check the OAuth client, refresh token, and sender address.';
            } elseif ($provider === 'gmail_api' && strpos($exception->getMessage(), 'Google OAuth token refresh failed (HTTP 400)') !== false) {
                $message = 'Google rejected the refresh token. Check the OAuth credentials and generate a new token if needed.';
            } elseif ($provider === 'gmail_api' && strpos($exception->getMessage(), 'Google OAuth token refresh failed') !== false) {
                $message = 'Google rejected the OAuth credentials. Check the OAuth client and refresh token in the backend environment.';
            } elseif ($provider === 'gmail_api' && strpos($exception->getMessage(), 'Gmail API rejected') !== false) {
                $message = 'Gmail API denied sending. Check that the API is enabled, the token has gmail.send scope, and the sender matches the authorized account.';
            } elseif (in_array($provider, ['smtp', 'brevo'], true) && strpos($exception->getMessage(), '(response 535)') !== false) {
                $message = $provider === 'brevo'
                    ? 'Brevo rejected the SMTP login. Check MAIL_USERNAME and MAIL_PASSWORD in the backend environment.'
                    : 'Gmail rejected the sender login. Replace the App Password in the backend environment.';
            } else {
                $message = 'Could not send the email code. Check the email provider settings and backend logs, then try again.';
            }
            $this->respond(503, ['success' => false, 'message' => $message]);
        }
    }

    private function find_valid_email_code($user_id, $purpose)
    {
        $statement = $this->call->database()->raw(
            'SELECT * FROM `email_verification_codes` '
            . 'WHERE `user_id` = ? AND `purpose` = ? AND `consumed_at` IS NULL AND `expires_at` > ? '
            . 'ORDER BY `created_at` DESC LIMIT 1',
            [(int) $user_id, $purpose, date('Y-m-d H:i:s')]
        );
        $record = $statement->fetch(PDO::FETCH_ASSOC);
        return $record ?: null;
    }

    private function consume_email_code($db, $record_id, $now)
    {
        $statement = $db->raw(
            'UPDATE `email_verification_codes` SET `consumed_at` = ? '
            . 'WHERE `id` = ? AND `consumed_at` IS NULL AND `expires_at` > ? AND `attempts` < 5',
            [$now, (int) $record_id, $now]
        );
        return $statement->rowCount() === 1;
    }

    private function session()
    {
        $this->call->library('session');
        return load_class('session', 'libraries');
    }

    private function verify_recaptcha($token)
    {
        $token = trim((string) $token);
        $secret = trim((string) rentease_env('RECAPTCHA_SECRET_KEY'));
        if ($secret === '') {
            $this->respond(503, ['success' => false, 'message' => 'reCAPTCHA is not configured on the server.']);
        }
        if ($token === '' || strlen($token) > 4096) {
            $this->respond(422, ['success' => false, 'message' => 'Complete the reCAPTCHA check and try again.']);
        }
        if (!function_exists('curl_init')) {
            error_log('reCAPTCHA verification is unavailable because PHP cURL is not installed.');
            $this->respond(503, ['success' => false, 'message' => 'reCAPTCHA verification is temporarily unavailable. Try again later.']);
        }

        $curl = curl_init('https://www.google.com/recaptcha/api/siteverify');
        if ($curl === false) {
            error_log('Could not initialize the reCAPTCHA verification request.');
            $this->respond(503, ['success' => false, 'message' => 'reCAPTCHA verification is temporarily unavailable. Try again later.']);
        }
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'secret' => $secret,
                'response' => $token,
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($curl);
        $http_status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curl_error_number = curl_errno($curl);
        curl_close($curl);

        if ($body === false) {
            error_log('reCAPTCHA verification request failed (cURL error ' . $curl_error_number . ').');
            $this->respond(503, ['success' => false, 'message' => 'reCAPTCHA verification is temporarily unavailable. Try again later.']);
        }
        if ($http_status !== 200) {
            error_log('reCAPTCHA verification returned HTTP ' . $http_status . '.');
            $this->respond(503, ['success' => false, 'message' => 'reCAPTCHA verification is temporarily unavailable. Try again later.']);
        }

        $verification = json_decode($body, true);
        if (!is_array($verification) || !array_key_exists('success', $verification)) {
            error_log('reCAPTCHA returned an invalid verification response.');
            $this->respond(503, ['success' => false, 'message' => 'reCAPTCHA verification is temporarily unavailable. Try again later.']);
        }

        $allowed_hostnames = array_values(array_filter(array_map(
            static function ($hostname) {
                return strtolower(trim($hostname));
            },
            explode(',', (string) rentease_env('RECAPTCHA_ALLOWED_HOSTNAMES', 'ryvaldez-frontend.onrender.com,localhost,127.0.0.1'))
        )));
        $verified_hostname = strtolower(trim((string) ($verification['hostname'] ?? '')));
        if (empty($verification['success']) || $verified_hostname === '' || !in_array($verified_hostname, $allowed_hostnames, true)) {
            $this->respond(422, ['success' => false, 'message' => 'The reCAPTCHA check failed. Complete it again and retry.']);
        }
    }

    private function json_input()
    {
        $content_type = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($content_type, 'application/json') === false) {
            $this->respond(415, ['success' => false, 'message' => 'Send the request as JSON.']);
        }

        $raw_input = file_get_contents('php://input');
        $input = json_decode($raw_input, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($input)) {
            $this->respond(400, ['success' => false, 'message' => 'The request body must be valid JSON.']);
        }

        return $input;
    }

    private function public_user(array $user)
    {
        $profile_photo_mime = (string) ($user['profile_photo_mime'] ?? '');
        $profile_photo_data = (string) ($user['profile_photo_data'] ?? '');
        $profile_photo = $profile_photo_data !== '' && in_array($profile_photo_mime, ['image/jpeg', 'image/png'], true)
            ? 'data:' . $profile_photo_mime . ';base64,' . $profile_photo_data
            : null;
        return [
            'id' => (int) $user['id'],
            'full_name' => $user['full_name'] ?: $user['username'],
            'email' => $user['email'],
            'profile_photo' => $profile_photo,
            'phone' => $user['phone'] ?? '',
            'account_type' => $user['account_type'] ?? 'tenant',
            'owner_status' => $user['owner_status'] ?? 'not_required',
            'email_verified_at' => $user['email_verified_at'] ?? null,
            'role' => $user['role'],
        ];
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
