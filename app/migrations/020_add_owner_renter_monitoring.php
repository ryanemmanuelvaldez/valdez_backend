<?php

class Add_owner_renter_monitoring
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
        if (!$forge->table_exists('rental_requests')) {
            return;
        }

        $columns = [
            'tenancy_status' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => FALSE, 'default' => 'active'],
            'owner_notes' => ['type' => 'TEXT', 'null' => TRUE, 'default' => NULL],
            'renter_display_name' => ['type' => 'VARCHAR', 'constraint' => 160, 'null' => TRUE, 'default' => NULL],
            'renter_contact_phone' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => TRUE, 'default' => NULL],
        ];
        foreach ($columns as $name => $definition) {
            if (!$forge->column_exists('rental_requests', $name)) {
                $forge->add_column('rental_requests', [$name => $definition]);
            }
        }
    }

    public function down()
    {
        $forge = $this->_lava->dbforge;
        foreach (['renter_contact_phone', 'renter_display_name', 'owner_notes', 'tenancy_status'] as $column) {
            if ($forge->table_exists('rental_requests') && $forge->column_exists('rental_requests', $column)) {
                $forge->drop_column('rental_requests', $column);
            }
        }
    }
}
