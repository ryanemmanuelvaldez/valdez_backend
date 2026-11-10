<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Community extends Controller
{
    public function rent_notifications()
    {
        $this->require_method('GET');
        $user = $this->current_user();
        if (!$user || $user['role'] === 'admin') {
            $this->respond($user ? 403 : 401, ['success' => false, 'message' => 'Sign in as an owner or tenant to view rent reminders.']);
        }

        require_once APP_DIR . 'libraries' . DIRECTORY_SEPARATOR . 'RentReminderService.php';
        (new RentReminderService($this->call->database()))->generate();
        $items = $this->call->database()->table('rent_notifications')
            ->where('user_id', (int) $user['id'])
            ->order_by('created_at', 'DESC')
            ->limit(20)
            ->get_all();
        $this->respond(200, ['success' => true, 'notifications' => $items]);
    }

    public function mark_rent_notification_read($notification_id)
    {
        $this->require_method('POST');
        $user = $this->current_user();
        if (!$user || $user['role'] === 'admin') {
            $this->respond($user ? 403 : 401, ['success' => false, 'message' => 'Sign in as an owner or tenant to update reminders.']);
        }

        $db = $this->call->database();
        $notification = $db->table('rent_notifications')
            ->where('id', (int) $notification_id)
            ->where('user_id', (int) $user['id'])
            ->row_array();
        if (!$notification) $this->respond(404, ['success' => false, 'message' => 'Rent reminder was not found.']);
        if (empty($notification['read_at'])) {
            $db->table('rent_notifications')
                ->where('id', (int) $notification_id)
                ->where('user_id', (int) $user['id'])
                ->update(['read_at' => date('Y-m-d H:i:s')]);
        }
        $this->respond(200, ['success' => true, 'message' => 'Reminder marked as read.']);
    }

    public function announcements()
    {
        $this->require_method('GET');
        $user = $this->current_user();
        if (!$user) $this->respond(401, ['success' => false, 'message' => 'Sign in to view announcements.']);
        if ($user['role'] === 'admin') $this->respond(403, ['success' => false, 'message' => 'Use the admin announcement workspace.']);
        $audience = $user['account_type'];
        $items = $this->call->database()->table('announcements')
            ->where('status', 'published')
            ->where('publish_at', '<=', date('Y-m-d H:i:s'))
            ->order_by('publish_at', 'DESC')
            ->limit(100)
            ->get_all();
        $items = array_values(array_filter($items, static function ($item) use ($audience) {
            return $item['audience'] === 'all' || $item['audience'] === $audience;
        }));
        $this->respond(200, ['success' => true, 'announcements' => $items]);
    }

    public function reviews()
    {
        $this->require_method('GET');
        $user = $this->current_user();
        if (!$user) $this->respond(401, ['success' => false, 'message' => 'Sign in to view your reviews.']);
        if ($user['role'] === 'admin' || $user['account_type'] !== 'tenant') {
            $this->respond(403, ['success' => false, 'message' => 'Only tenants can manage rental reviews.']);
        }
        $db = $this->call->database();
        $eligible_requests = $db->table('rental_requests')
            ->where('tenant_id', (int) $user['id'])
            ->where('status', 'approved')
            ->order_by('updated_at', 'DESC')
            ->limit(100)
            ->get_all();
        foreach ($eligible_requests as &$rental_request) {
            $rental = $db->table('rentals')->select('title')->where('id', (int) $rental_request['rental_id'])->row_array();
            $rental_request['rental_title'] = $rental['title'] ?? 'Unavailable rental';
            $rental_request['review'] = $db->table('rental_reviews')
                ->where('rental_request_id', (int) $rental_request['id'])
                ->row_array();
        }
        unset($rental_request);
        $this->respond(200, ['success' => true, 'eligible_requests' => $eligible_requests]);
    }

    public function create_review()
    {
        $this->require_method('POST');
        $user = $this->current_user();
        if (!$user) $this->respond(401, ['success' => false, 'message' => 'Sign in to write a review.']);
        if ($user['role'] === 'admin' || $user['account_type'] !== 'tenant') {
            $this->respond(403, ['success' => false, 'message' => 'Only tenants can review a rental.']);
        }
        $input = $this->json_input();
        $request_id = filter_var($input['rental_request_id'] ?? null, FILTER_VALIDATE_INT);
        $rating = filter_var($input['rating'] ?? null, FILTER_VALIDATE_INT);
        $body = trim((string) ($input['body'] ?? ''));
        if ($request_id === false || $request_id < 1 || $rating === false || $rating < 1 || $rating > 5
            || strlen($body) < 10 || strlen($body) > 3000) {
            $this->respond(422, ['success' => false, 'message' => 'Choose a rating from 1 to 5 and write a review between 10 and 3000 characters.']);
        }
        $db = $this->call->database();
        $request = $db->table('rental_requests')
            ->where('id', $request_id)
            ->where('tenant_id', (int) $user['id'])
            ->where('status', 'approved')
            ->row_array();
        if (!$request) $this->respond(403, ['success' => false, 'message' => 'A completed rental is required before posting a review.']);
        if ($db->table('rental_reviews')->where('rental_request_id', $request_id)->row_array()) {
            $this->respond(409, ['success' => false, 'message' => 'A review has already been submitted for this rental.']);
        }
        $id = $db->table('rental_reviews')->insert([
            'rental_request_id' => $request_id,
            'tenant_id' => (int) $user['id'],
            'rental_id' => (int) $request['rental_id'],
            'rating' => $rating,
            'body' => $body,
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $this->respond(201, ['success' => true, 'message' => 'Review submitted for admin moderation.', 'id' => (int) $id]);
    }

    public function tickets()
    {
        $this->require_method('GET');
        $user = $this->current_user();
        if (!$user) $this->respond(401, ['success' => false, 'message' => 'Sign in to view support requests.']);
        if ($user['role'] === 'admin') $this->respond(403, ['success' => false, 'message' => 'Use the admin support workspace.']);
        $tickets = $this->call->database()->table('support_tickets')
            ->where('user_id', (int) $user['id'])
            ->order_by('created_at', 'DESC')
            ->limit(100)
            ->get_all();
        $this->respond(200, ['success' => true, 'tickets' => $tickets]);
    }

    public function create_ticket()
    {
        $this->require_method('POST');
        $user = $this->current_user();
        if (!$user) $this->respond(401, ['success' => false, 'message' => 'Sign in to contact support.']);
        if ($user['role'] === 'admin') $this->respond(403, ['success' => false, 'message' => 'Administrators cannot create customer support tickets.']);
        $input = $this->json_input();
        $subject = trim((string) ($input['subject'] ?? ''));
        $message = trim((string) ($input['message'] ?? ''));
        $request_id = empty($input['rental_request_id']) ? null : filter_var($input['rental_request_id'], FILTER_VALIDATE_INT);
        if (strlen($subject) < 4 || strlen($subject) > 160 || strlen($message) < 10 || strlen($message) > 5000
            || ($request_id !== null && ($request_id === false || $request_id < 1))) {
            $this->respond(422, ['success' => false, 'message' => 'Enter a subject, message, and optional valid rental request.']);
        }
        if ($request_id !== null) {
            $request = $this->call->database()->table('rental_requests')
                ->where('id', $request_id)
                ->where('tenant_id', (int) $user['id'])
                ->row_array();
            if ($user['account_type'] === 'owner') {
                $request = $this->call->database()->table('rental_requests')
                    ->where('id', $request_id)->where('owner_id', (int) $user['id'])->row_array();
            }
            if (!$request) $this->respond(403, ['success' => false, 'message' => 'You cannot attach a ticket to that rental request.']);
        }
        $id = $this->call->database()->table('support_tickets')->insert([
            'user_id' => (int) $user['id'],
            'rental_request_id' => $request_id,
            'subject' => $subject,
            'message' => $message,
            'priority' => 'normal',
            'status' => 'open',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $this->respond(201, ['success' => true, 'message' => 'Support request submitted.', 'id' => (int) $id]);
    }

    private function current_user()
    {
        $this->call->library('session');
        $user_id = (int) load_class('session', 'libraries')->userdata('user_id');
        if ($user_id < 1) return null;
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
