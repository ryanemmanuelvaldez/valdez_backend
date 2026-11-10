<?php

class Add_rental_agreement_acceptance
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

        if (!$forge->column_exists('rental_requests', 'rental_agreement_accepted_at')) {
            $forge->add_column('rental_requests', [
                'rental_agreement_accepted_at' => ['type' => 'DATETIME', 'null' => TRUE, 'default' => NULL],
            ]);
        }
        if (!$forge->column_exists('rental_requests', 'rental_agreement_version')) {
            $forge->add_column('rental_requests', [
                'rental_agreement_version' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => TRUE, 'default' => NULL],
            ]);
        }
    }

    public function down()
    {
        $forge = $this->_lava->dbforge;
        if ($forge->table_exists('rental_requests') && $forge->column_exists('rental_requests', 'rental_agreement_version')) {
            $forge->drop_column('rental_requests', 'rental_agreement_version');
        }
        if ($forge->table_exists('rental_requests') && $forge->column_exists('rental_requests', 'rental_agreement_accepted_at')) {
            $forge->drop_column('rental_requests', 'rental_agreement_accepted_at');
        }
    }
}
