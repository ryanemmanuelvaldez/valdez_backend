<?php

class Add_renter_contact_email
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
        if ($forge->table_exists('rental_requests') && !$forge->column_exists('rental_requests', 'renter_contact_email')) {
            $forge->add_column('rental_requests', [
                'renter_contact_email' => ['type' => 'VARCHAR', 'constraint' => 254, 'null' => TRUE, 'default' => NULL],
            ]);
        }
    }

    public function down()
    {
        $forge = $this->_lava->dbforge;
        if ($forge->table_exists('rental_requests') && $forge->column_exists('rental_requests', 'renter_contact_email')) {
            $forge->drop_column('rental_requests', 'renter_contact_email');
        }
    }
}
