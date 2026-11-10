<?php

class Create_rental_payments_table
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->dbforge();
    }

    public function up()
    {
        if ($this->_lava->dbforge->table_exists('rental_payments')) {
            return;
        }

        $this->_lava->dbforge
            ->add_field([
                'id' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'unsigned' => TRUE,
                    'auto_increment' => TRUE,
                    'null' => FALSE,
                ],
                'rental_request_id' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'unsigned' => TRUE,
                    'null' => FALSE,
                ],
                'tenant_id' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'unsigned' => TRUE,
                    'null' => FALSE,
                ],
                'rental_id' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'unsigned' => TRUE,
                    'null' => FALSE,
                ],
                'reference_number' => [
                    'type' => 'VARCHAR',
                    'constraint' => 100,
                    'null' => FALSE,
                ],
                'checkout_session_id' => [
                    'type' => 'VARCHAR',
                    'constraint' => 100,
                    'null' => TRUE,
                ],
                'provider_payment_id' => [
                    'type' => 'VARCHAR',
                    'constraint' => 100,
                    'null' => TRUE,
                ],
                'checkout_url' => [
                    'type' => 'TEXT',
                    'null' => TRUE,
                ],
                'amount_centavos' => [
                    'type' => 'BIGINT',
                    'unsigned' => TRUE,
                    'null' => FALSE,
                ],
                'currency' => [
                    'type' => 'CHAR',
                    'constraint' => 3,
                    'null' => FALSE,
                    'default' => 'PHP',
                ],
                'status' => [
                    'type' => 'VARCHAR',
                    'constraint' => 20,
                    'null' => FALSE,
                    'default' => 'creating',
                ],
                'paid_at' => [
                    'type' => 'DATETIME',
                    'null' => TRUE,
                    'default' => NULL,
                ],
                'created_at' => [
                    'type' => 'DATETIME',
                    'null' => FALSE,
                    'default' => 'CURRENT_TIMESTAMP',
                ],
                'updated_at' => [
                    'type' => 'DATETIME',
                    'null' => TRUE,
                    'default' => NULL,
                ],
            ])
            ->add_key('id', primary: TRUE)
            ->add_key('rental_request_id', unique: TRUE, name: 'rental_payments_request_unique')
            ->add_key('reference_number', unique: TRUE, name: 'rental_payments_reference_unique')
            ->add_key('checkout_session_id', unique: TRUE, name: 'rental_payments_session_unique')
            ->add_key('provider_payment_id', unique: TRUE, name: 'rental_payments_provider_payment_unique')
            ->add_key('tenant_id', name: 'rental_payments_tenant_idx')
            ->add_key('rental_id', name: 'rental_payments_rental_idx')
            ->add_key('status', name: 'rental_payments_status_idx')
            ->create_table('rental_payments');
    }

    public function down()
    {
        $this->_lava->dbforge->drop_table('rental_payments');
    }
}
