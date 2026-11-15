<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Gmail_smtp
{
    public function send_code($recipient, $code, $purpose, $expires_minutes = 10)
    {
        $provider = strtolower(trim((string) rentease_env('MAIL_PROVIDER', 'smtp')));
        if ($provider === 'resend') {
            $this->send_with_resend($recipient, $code, $purpose, $expires_minutes);
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

    private function send_with_resend($recipient, $code, $purpose, $expires_minutes)
    {
        $api_key = trim((string) rentease_env('RESEND_API_KEY'));
        $sender = trim((string) rentease_env('RESEND_FROM_ADDRESS'));
        if ($api_key === '' || $sender === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Resend email configuration is incomplete or invalid.');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The PHP cURL extension is required for the Resend email API.');
        }

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
        $payload = json_encode([
            'from' => $sender,
            'to' => [$recipient],
            'subject' => $subject,
            'text' => $text,
            'html' => $html,
        ], JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            throw new RuntimeException('Could not prepare the email for the Resend API.');
        }

        $curl = curl_init('https://api.resend.com/emails');
        if ($curl === false) {
            throw new RuntimeException('Could not initialize the Resend email API request.');
        }
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $api_key,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => max(5, min(30, (int) rentease_env('EMAIL_API_TIMEOUT', '15'))),
        ]);
        $response_body = curl_exec($curl);
        $http_status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curl_error_number = curl_errno($curl);
        curl_close($curl);

        if ($response_body === false) {
            throw new RuntimeException('Could not connect to the Resend email API (cURL error ' . $curl_error_number . ').');
        }
        if ($http_status < 200 || $http_status >= 300) {
            throw new RuntimeException('Resend API rejected the email (HTTP ' . $http_status . ').');
        }

        $result = json_decode($response_body, true);
        if (!is_array($result) || empty($result['id'])) {
            throw new RuntimeException('Resend API returned an invalid email response.');
        }
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
        $is_reset = $purpose === 'password_reset';
        $subject = $is_reset ? 'RentEase password reset code' : 'RentEase email verification code';
        $action = $is_reset ? 'reset your password' : 'verify your email address';
        $boundary = 'rentease_' . bin2hex(random_bytes(16));
        $text_body = "Your RentEase code to {$action} is:\n\n{$code}\n\n"
            . "This code expires in {$expires_minutes} minutes. If you did not request it, you can ignore this email.\n";
        $html_body = '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#173b30">'
            . '<h2>RentEase account code</h2><p>Use this code to ' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . ':</p>'
            . '<p style="font-size:32px;font-weight:bold;letter-spacing:8px">'
            . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</p><p>This code expires in '
            . (int) $expires_minutes . ' minutes. If you did not request it, you can ignore this email.</p>'
            . '</body></html>';
        $safe_sender_name = str_replace(["\r", "\n"], '', $sender_name);
        $from_header = $safe_sender_name !== ''
            ? 'From: ' . $safe_sender_name . ' <' . $sender . '>'
            : 'From: <' . $sender . '>';
        $headers = [
            $from_header,
            'To: <' . $recipient . '>',
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@rentease.local>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];
        $message = implode("\r\n", $headers) . "\r\n\r\n"
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($text_body), 76, "\r\n")
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html_body), 76, "\r\n")
            . '--' . $boundary . "--\r\n";
        $message = preg_replace('/(^|\r\n)\./', '$1..', $message);
        $payload = $message . ".\r\n";
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
