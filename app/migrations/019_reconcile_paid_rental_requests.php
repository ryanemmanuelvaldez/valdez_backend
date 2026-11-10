<?php

class Reconcile_paid_rental_requests
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
        if (!$forge->table_exists('rental_requests') || !$forge->table_exists('rental_payments')) {
            return;
        }

        $this->_lava->call->database()->raw(
            'UPDATE rental_requests rr '
            . 'INNER JOIN rental_payments rp ON rp.rental_request_id = rr.id '
            . 'SET rr.status = ?, rr.updated_at = ? '
            . 'WHERE rp.status = ? AND rr.status = ?',
            ['approved', date('Y-m-d H:i:s'), 'paid', 'awaiting_payment']
        );
    }

    public function down()
    {
        // Paid requests must remain approved; reverting them would hide completed payments.
    }
}
