<?php

class RentReminderService
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function generate()
    {
        $today = new DateTimeImmutable(date('Y-m-d'));
        $statement = $this->db->raw(
            'SELECT i.id, i.rental_request_id, i.installment_number, i.due_date, i.amount_centavos, i.status, '
            . 'r.owner_id, r.tenant_id, rental.title '
            . 'FROM rental_installments i '
            . 'INNER JOIN rental_requests r ON r.id = i.rental_request_id '
            . 'INNER JOIN rentals rental ON rental.id = i.rental_id '
            . 'WHERE r.status = ? AND i.status <> ? '
            . 'AND i.due_date <= ? ORDER BY i.due_date ASC',
            ['approved', 'paid', $today->modify('+3 days')->format('Y-m-d')]
        );
        $installments = $statement->fetchAll(PDO::FETCH_ASSOC);
        $created = 0;

        foreach ($installments as $installment) {
            $due_date = new DateTimeImmutable($installment['due_date']);
            $days_until_due = (int) $today->diff($due_date)->format('%r%a');
            if (in_array($installment['status'], ['upcoming', 'due', 'overdue'], true)) {
                $effective_status = $days_until_due < 0 ? 'overdue' : ($days_until_due === 0 ? 'due' : 'upcoming');
                if ($installment['status'] !== $effective_status) {
                    $this->db->table('rental_installments')
                        ->where('id', (int) $installment['id'])
                        ->update(['status' => $effective_status, 'updated_at' => date('Y-m-d H:i:s')]);
                }
            }
            $type = $days_until_due < 0 ? 'overdue' : ($days_until_due === 0 ? 'due_today' : 'due_soon');
            $due_date_text = $due_date->format('M j, Y');
            $amount = number_format(((int) $installment['amount_centavos']) / 100, 2);
            $title = $type === 'overdue' ? 'Rent payment overdue' : ($type === 'due_today' ? 'Rent payment due today' : 'Rent payment due soon');
            $body = $type === 'overdue'
                ? '₱' . $amount . ' for ' . $installment['title'] . ' was due on ' . $due_date_text . '.'
                : '₱' . $amount . ' for ' . $installment['title'] . ' is due ' . ($type === 'due_today' ? 'today.' : 'on ' . $due_date_text . '.');
            $dedupe_key = 'installment-' . (int) $installment['id'] . '-' . $type;

            foreach ([(int) $installment['owner_id'], (int) $installment['tenant_id']] as $user_id) {
                $result = $this->db->raw(
                    'INSERT INTO rent_notifications '
                    . '(user_id, rental_request_id, installment_id, notification_type, dedupe_key, title, body, due_date, created_at) '
                    . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE id = id',
                    [
                        $user_id,
                        (int) $installment['rental_request_id'],
                        (int) $installment['id'],
                        $type,
                        $dedupe_key,
                        $title,
                        $body,
                        $installment['due_date'],
                        date('Y-m-d H:i:s'),
                    ]
                );
                $created += $result->rowCount();
            }
        }

        return $created;
    }
}
