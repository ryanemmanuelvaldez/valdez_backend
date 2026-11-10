<?php

class Create_rental_requests_table
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->dbforge();
    }

    public function up()
    {
        if ($this->_lava->dbforge->table_exists('rental_requests')) {
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
                'rental_id' => [
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
                'owner_id' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'unsigned' => TRUE,
                    'null' => FALSE,
                ],
                'move_in' => [
                    'type' => 'DATE',
                    'null' => FALSE,
                ],
                'move_out' => [
                    'type' => 'DATE',
                    'null' => FALSE,
                ],
                'occupants' => [
                    'type' => 'SMALLINT',
                    'constraint' => 5,
                    'unsigned' => TRUE,
                    'null' => FALSE,
                ],
                'term_months' => [
                    'type' => 'SMALLINT',
                    'constraint' => 5,
                    'unsigned' => TRUE,
                    'null' => FALSE,
                ],
                'message' => [
                    'type' => 'TEXT',
                    'null' => FALSE,
                ],
                'status' => [
                    'type' => 'VARCHAR',
                    'constraint' => 20,
                    'null' => FALSE,
                    'default' => 'pending',
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
            ->add_key('rental_id', name: 'rental_requests_rental_idx')
            ->add_key('tenant_id', name: 'rental_requests_tenant_idx')
            ->add_key('owner_id', name: 'rental_requests_owner_idx')
            ->add_key('status', name: 'rental_requests_status_idx')
            ->create_table('rental_requests');
    }

    public function down()
    {
        $this->_lava->dbforge->drop_table('rental_requests');
    }
}
