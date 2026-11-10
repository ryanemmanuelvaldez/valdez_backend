<?php

class Add_account_profile_fields
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->dbforge();
    }

    public function up()
    {
        if (!$this->_lava->dbforge->table_exists('users')) {
            throw new RuntimeException('The users table must exist before account profile fields can be added.');
        }

        $fields = [
            'full_name' => [
                'type' => 'VARCHAR',
                'constraint' => 100,
                'null' => TRUE,
            ],
            'phone' => [
                'type' => 'VARCHAR',
                'constraint' => 30,
                'null' => TRUE,
            ],
            'account_type' => [
                'type' => 'VARCHAR',
                'constraint' => 20,
                'null' => FALSE,
                'default' => 'tenant',
            ],
        ];

        foreach ($fields as $name => $definition) {
            if (!$this->_lava->dbforge->column_exists('users', $name)) {
                $this->_lava->dbforge->add_column('users', [$name => $definition]);
            }
        }
    }

    public function down()
    {
        foreach (['account_type', 'phone', 'full_name'] as $column) {
            if ($this->_lava->dbforge->column_exists('users', $column)) {
                $this->_lava->dbforge->drop_column('users', $column);
            }
        }
    }
}
