<?php

class Add_wallet_payment_receipts
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
        foreach (['rental_payments', 'rental_installments'] as $table) {
            if ($forge->table_exists($table) && !$forge->column_exists($table, 'receipt_image')) {
                $forge->add_column($table, [
                    'receipt_image' => ['type' => 'MEDIUMBLOB', 'null' => TRUE, 'default' => NULL],
                ]);
            }
        }
    }

    public function down()
    {
        $forge = $this->_lava->dbforge;
        foreach (['rental_payments', 'rental_installments'] as $table) {
            if ($forge->table_exists($table) && $forge->column_exists($table, 'receipt_image')) {
                $forge->drop_column($table, 'receipt_image');
            }
        }
    }
}
