<?php

class Expand_payment_statuses_and_reconcile_paid_rentals
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->dbforge();
    }

    public function up()
    {
        $forge = $this->_lava->dbforge;
        $db = $this->_lava->call->database();

        if ($forge->table_exists('rental_payments') && $forge->column_exists('rental_payments', 'status')) {
            $forge->modify_column('rental_payments', [
                'status' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => FALSE, 'default' => 'creating'],
            ]);
            $db->table('rental_payments')
                ->where('status', 'pending_owner_confir')
                ->update(['status' => 'pending_owner_confirmation']);
        }

        if ($forge->table_exists('rental_installments') && $forge->column_exists('rental_installments', 'status')) {
            $forge->modify_column('rental_installments', [
                'status' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => FALSE, 'default' => 'due'],
            ]);
            $db->table('rental_installments')
                ->where('status', 'pending_owner_confir')
                ->update(['status' => 'pending_owner_confirmation']);
        }

        if ($forge->table_exists('rental_payments')
            && $forge->table_exists('rental_requests')
            && $forge->table_exists('rentals')) {
            $db->raw(
                'UPDATE rental_requests rr '
                . 'INNER JOIN rental_payments rp ON rp.rental_request_id = rr.id '
                . 'SET rr.status = ?, rr.updated_at = ? '
                . 'WHERE rp.status = ? AND rr.status = ?',
                ['approved', date('Y-m-d H:i:s'), 'paid', 'awaiting_payment']
            );
            $db->raw(
                'UPDATE rentals r '
                . 'INNER JOIN rental_payments rp ON rp.rental_id = r.id '
                . 'SET r.status = ?, r.updated_at = ? '
                . 'WHERE rp.status = ? AND r.status = ?',
                ['rented', date('Y-m-d H:i:s'), 'paid', 'published']
            );
            $db->raw(
                'UPDATE rental_requests rr '
                . 'INNER JOIN rental_payments rp ON rp.rental_id = rr.rental_id AND rp.status = ? '
                . 'SET rr.status = ?, rr.updated_at = ? '
                . 'WHERE rr.status = ? AND rr.id <> rp.rental_request_id',
                ['paid', 'declined', date('Y-m-d H:i:s'), 'pending']
            );
        }
    }

    public function down()
    {
        // Keep the widened columns; shrinking them would truncate valid confirmation statuses.
    }
}
