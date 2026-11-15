<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Gmail_smtp
{
    public function send_code($recipient, $code, $purpose, $expires_minutes = 10)
    {
        $provider = strtolower(trim((string) rentease_env('MAIL_PROVIDER', 'smtp')));
        if ($provider === 'gmail_api') {
            $this->send_with_gmail_api($recipient, $code, $purpose, $expires_minutes);
            return;
        }
        if (!in_array($provider, ['smtp', 'brevo'], true)) {
            throw new RuntimeException('Email provider configuration is invalid.');
        }

        $host = trim((string) rentease_env(
            'MAIL_HOST',
            $provider === 'brevo' ? 'smtp-relay.brevo.com' : rentease_env('GMAIL_SMTP_HOST', 'smtp.gmail.com')
        ));
        $port = (int) rentease_env(
            'MAIL_PORT',
            $provider === 'brevo' ? '2525' : rentease_env('GMAIL_SMTP_PORT', '587')
        );
        $username = trim((string) rentease_env('MAIL_USERNAME', rentease_env('GMAIL_SMTP_USERNAME')));
        $password = preg_replace('/\s+/', '', trim((string) rentease_env('MAIL_PASSWORD', rentease_env('GMAIL_SMTP_APP_PASSWORD'))));
        $sender = trim((string) rentease_env('MAIL_FROM_ADDRESS', rentease_env('GMAIL_FROM_ADDRESS', $username)));
        $sender_name = trim((string) rentease_env('MAIL_FROM_NAME', 'RentEase'));
        $encryption = strtolower(trim((string) rentease_env(
            'MAIL_ENCRYPTION',
            $port === 465 ? 'ssl' : 'tls'
        )));
        $timeout = max(5, min(30, (int) rentease_env('MAIL_TIMEOUT', rentease_env('GMAIL_SMTP_TIMEOUT', '15'))));
        $sender_email = $this->sender_email($sender);

        if ($host === '' || $username === '' || $password === ''
            || !filter_var($sender_email, FILTER_VALIDATE_EMAIL)
            || !filter_var($recipient, FILTER_VALIDATE_EMAIL)
            || $port < 1 || $port > 65535
            || !in_array($encryption, ['tls', 'ssl', 'none'], true)) {
            throw new RuntimeException('SMTP configuration is incomplete or invalid.');
        }

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'peer_name' => $host,
                'SNI_enabled' => true,
            ],
        ]);
        $transport = $encryption === 'ssl' ? 'ssl://' : 'tcp://';
        $socket = @stream_socket_client(
            $transport . $host . ':' . $port,
            $error_number,
            $error_message,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if (!$socket) {
            throw new RuntimeException('Could not connect to the SMTP server: ' . $error_message . ' (' . $error_number . ').');
        }

        stream_set_timeout($socket, $timeout);
        try {
            $this->expect($socket, [220]);
            $this->command($socket, 'EHLO rentease.local', [250]);
            if ($encryption === 'tls') {
                $this->command($socket, 'STARTTLS', [220]);
                if (stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                    throw new RuntimeException('Could not establish a secure TLS connection to the SMTP server.');
                }
                $this->command($socket, 'EHLO rentease.local', [250]);
            }

            $this->command($socket, 'AUTH LOGIN', [334]);
            $this->command($socket, base64_encode($username), [334]);
            $this->command($socket, base64_encode($password), [235]);
            $this->command($socket, 'MAIL FROM:<' . $sender_email . '>', [250]);
            $this->command($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
            $this->command($socket, 'DATA', [354]);
            $this->write_message($socket, $sender_email, $sender_name, $recipient, $code, $purpose, $expires_minutes);
            $this->expect($socket, [250]);
            $this->command($socket, 'QUIT', [221]);
        } finally {
            fclose($socket);
        }
    }

    private function send_with_gmail_api($recipient, $code, $purpose, $expires_minutes)
    {
        $client_id = trim((string) rentease_env('GMAIL_API_CLIENT_ID'));
        $client_secret = trim((string) rentease_env('GMAIL_API_CLIENT_SECRET'));
        $refresh_token = trim((string) rentease_env('GMAIL_API_REFRESH_TOKEN'));
        $sender = trim((string) rentease_env('GMAIL_API_FROM_ADDRESS'));
        $sender_name = trim((string) rentease_env('MAIL_FROM_NAME', 'RentEase'));
        if ($client_id === '' || $client_secret === '' || $refresh_token === ''
            || !filter_var($sender, FILTER_VALIDATE_EMAIL)
            || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Gmail API configuration is incomplete or invalid.');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The PHP cURL extension is required for the Gmail API.');
        }

        $access_token = $this->gmail_api_access_token($client_id, $client_secret, $refresh_token);
        $message = $this->build_email_message(
            $sender,
            $sender_name,
            $recipient,
            $code,
            $purpose,
            $expires_minutes
        );
        $payload = json_encode([
            'raw' => rtrim(strtr(base64_encode($message), '+/', '-_'), '='),
        ], JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            throw new RuntimeException('Could not prepare the email for the Gmail API.');
        }

        $response = $this->post_http(
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/send',
            $payload,
            [
                'Authorization: Bearer ' . $access_token,
                'Content-Type: application/json',
                'Accept: application/json',
            ]
        );
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException('Gmail API rejected the email (HTTP ' . $response['status'] . ').');
        }

        $result = json_decode($response['body'], true);
        if (!is_array($result) || empty($result['id'])) {
            throw new RuntimeException('Gmail API returned an invalid email response.');
        }
    }

    private function gmail_api_access_token($client_id, $client_secret, $refresh_token)
    {
        $response = $this->post_http(
            'https://oauth2.googleapis.com/token',
            http_build_query([
                'client_id' => $client_id,
                'client_secret' => $client_secret,
                'refresh_token' => $refresh_token,
                'grant_type' => 'refresh_token',
            ]),
            [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ]
        );
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException('Google OAuth token refresh failed (HTTP ' . $response['status'] . ').');
        }

        $result = json_decode($response['body'], true);
        if (!is_array($result) || empty($result['access_token'])) {
            throw new RuntimeException('Google OAuth returned an invalid access token response.');
        }
        return $result['access_token'];
    }

    private function post_http($url, $body, array $headers)
    {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('Could not initialize the HTTPS email request.');
        }
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => max(5, min(30, (int) rentease_env('EMAIL_API_TIMEOUT', '15'))),
        ]);
        $response_body = curl_exec($curl);
        $http_status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curl_error_number = curl_errno($curl);
        curl_close($curl);

        if ($response_body === false) {
            throw new RuntimeException('Could not connect to the Google API (cURL error ' . $curl_error_number . ').');
        }
        return ['status' => $http_status, 'body' => $response_body];
    }

    private function build_email_message($sender, $sender_name, $recipient, $code, $purpose, $expires_minutes)
    {
        $is_reset = $purpose === 'password_reset';
        $subject = $is_reset ? 'RentEase password reset code' : 'RentEase email verification code';
        $action = $is_reset ? 'reset your password' : 'verify your email address';
        $text = "Your RentEase code to {$action} is:\n\n{$code}\n\n"
            . "This code expires in {$expires_minutes} minutes. If you did not request it, you can ignore this email.\n";
        $html = '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#173b30">'
            . '<h2>RentEase account code</h2><p>Use this code to ' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . ':</p>'
            . '<p style="font-size:32px;font-weight:bold;letter-spacing:8px">'
            . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</p><p>This code expires in '
            . (int) $expires_minutes . ' minutes. If you did not request it, you can ignore this email.</p>'
            . '</body></html>';
        $safe_sender_name = str_replace(["\r", "\n"], '', $sender_name);
        $from_header = $safe_sender_name !== ''
            ? 'From: =?UTF-8?B?' . base64_encode($safe_sender_name) . '?= <' . $sender . '>'
            : 'From: <' . $sender . '>';
        $boundary = 'rentease_' . bin2hex(random_bytes(16));
        $headers = [
            $from_header,
            'To: <' . $recipient . '>',
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@rentease.local>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];
        return implode("\r\n", $headers) . "\r\n\r\n"
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($text), 76, "\r\n")
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html), 76, "\r\n")
            . '--' . $boundary . "--\r\n";
    }

    private function sender_email($sender)
    {
        if (preg_match('/<([^<>]+)>/', $sender, $matches)) {
            return trim($matches[1]);
        }
        return $sender;
    }

    private function write_message($socket, $sender, $sender_name, $recipient, $code, $purpose, $expires_minutes)
    {
        $message = $this->build_email_message($sender, $sender_name, $recipient, $code, $purpose, $expires_minutes);
        $payload = preg_replace('/(^|\r\n)\./', '$1..', $message) . ".\r\n";
        $written = 0;
        $length = strlen($payload);
        while ($written < $length) {
            $bytes = fwrite($socket, substr($payload, $written));
            if ($bytes === false || $bytes === 0) {
                throw new RuntimeException('Could not write the email message to Gmail SMTP.');
            }
            $written += $bytes;
        }
    }

    private function command($socket, $command, array $expected_codes)
    {
        if (fwrite($socket, $command . "\r\n") === false) {
            throw new RuntimeException('Could not write to the Gmail SMTP connection.');
        }
        $this->expect($socket, $expected_codes);
    }

    private function expect($socket, array $expected_codes)
    {
        $response = '';
        for ($line_count = 0; $line_count < 50; $line_count++) {
            $line = fgets($socket, 2048);
            if ($line === false) {
                $meta = stream_get_meta_data($socket);
                throw new RuntimeException(!empty($meta['timed_out'])
                    ? 'SMTP connection timed out.'
                    : 'SMTP server closed the connection unexpectedly.');
            }
            $response .= $line;
            if (!preg_match('/^(\d{3})([ -])/', $line, $matches)) {
                throw new RuntimeException('SMTP server returned an invalid response.');
            }
            if ($matches[2] === ' ') {
                $response_code = (int) $matches[1];
                if (!in_array($response_code, $expected_codes, true)) {
                        throw new RuntimeException('SMTP server rejected the email (response ' . $response_code . ').');
                }
                return $response;
            }
        }
        throw new RuntimeException('SMTP response contained too many lines.');
    }
}
