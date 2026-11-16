<?php

class Add_user_profile_photo
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
        if (!$forge->table_exists('users')) {
            return;
        }

        $columns = [];
        if (!$forge->column_exists('users', 'profile_photo_data')) {
            $columns['profile_photo_data'] = ['type' => 'MEDIUMTEXT', 'null' => TRUE, 'default' => NULL];
        }
        if (!$forge->column_exists('users', 'profile_photo_mime')) {
            $columns['profile_photo_mime'] = ['type' => 'VARCHAR', 'constraint' => 24, 'null' => TRUE, 'default' => NULL];
        }
        if ($columns) {
            $forge->add_column('users', $columns);
        }
    }

    public function down()
    {
        $forge = $this->_lava->dbforge;
        if (!$forge->table_exists('users')) {
            return;
        }

        if ($forge->column_exists('users', 'profile_photo_mime')) {
            $forge->drop_column('users', 'profile_photo_mime');
        }
        if ($forge->column_exists('users', 'profile_photo_data')) {
            $forge->drop_column('users', 'profile_photo_data');
        }
    }
}
