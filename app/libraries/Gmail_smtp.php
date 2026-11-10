<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Gmail_smtp
{
    public function send_code($recipient, $code, $purpose, $expires_minutes = 10)
    {
        $host = trim((string) rentease_env('GMAIL_SMTP_HOST', 'smtp.gmail.com'));
        $port = (int) rentease_env('GMAIL_SMTP_PORT', '587');
        $username = trim((string) rentease_env('GMAIL_SMTP_USERNAME'));
        $password = preg_replace('/\s+/', '', trim((string) rentease_env('GMAIL_SMTP_APP_PASSWORD')));
        $sender = trim((string) rentease_env('GMAIL_FROM_ADDRESS', $username));
        $timeout = max(5, min(30, (int) rentease_env('GMAIL_SMTP_TIMEOUT', '15')));

        if ($host === '' || !filter_var($username, FILTER_VALIDATE_EMAIL)
            || !filter_var($sender, FILTER_VALIDATE_EMAIL) || $password === ''
            || !filter_var($recipient, FILTER_VALIDATE_EMAIL) || !in_array($port, [465, 587], true)) {
            throw new RuntimeException('Gmail SMTP configuration is incomplete or invalid.');
        }

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'peer_name' => $host,
                'SNI_enabled' => true,
            ],
        ]);
        $transport = $port === 465 ? 'ssl://' : 'tcp://';
        $socket = @stream_socket_client(
            $transport . $host . ':' . $port,
            $error_number,
            $error_message,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if (!$socket) {
            throw new RuntimeException('Could not connect to Gmail SMTP: ' . $error_message . ' (' . $error_number . ').');
        }

        stream_set_timeout($socket, $timeout);
        try {
            $this->expect($socket, [220]);
            $this->command($socket, 'EHLO rentease.local', [250]);
            if ($port === 587) {
                $this->command($socket, 'STARTTLS', [220]);
                if (stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                    throw new RuntimeException('Could not establish a secure TLS connection to Gmail SMTP.');
                }
                $this->command($socket, 'EHLO rentease.local', [250]);
            }

            $this->command($socket, 'AUTH LOGIN', [334]);
            $this->command($socket, base64_encode($username), [334]);
            $this->command($socket, base64_encode($password), [235]);
            $this->command($socket, 'MAIL FROM:<' . $sender . '>', [250]);
            $this->command($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
            $this->command($socket, 'DATA', [354]);
            $this->write_message($socket, $sender, $recipient, $code, $purpose, $expires_minutes);
            $this->expect($socket, [250]);
            $this->command($socket, 'QUIT', [221]);
        } finally {
            fclose($socket);
        }
    }

    private function write_message($socket, $sender, $recipient, $code, $purpose, $expires_minutes)
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
        $headers = [
            'From: RentEase <' . $sender . '>',
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
                    ? 'Gmail SMTP connection timed out.'
                    : 'Gmail SMTP closed the connection unexpectedly.');
            }
            $response .= $line;
            if (!preg_match('/^(\d{3})([ -])/', $line, $matches)) {
                throw new RuntimeException('Gmail SMTP returned an invalid response.');
            }
            if ($matches[2] === ' ') {
                $response_code = (int) $matches[1];
                if (!in_array($response_code, $expected_codes, true)) {
                    throw new RuntimeException('Gmail SMTP rejected the email (response ' . $response_code . ').');
                }
                return $response;
            }
        }
        throw new RuntimeException('Gmail SMTP response contained too many lines.');
    }
}
