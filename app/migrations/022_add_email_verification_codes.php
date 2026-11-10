<?php

class Add_email_verification_codes
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

        if (!$forge->column_exists('users', 'email_verified_at')) {
            $forge->add_column('users', [
                'email_verified_at' => ['type' => 'DATETIME', 'null' => TRUE, 'default' => NULL],
            ]);
        }

        if (!$forge->table_exists('email_verification_codes')) {
            $forge->add_field([
                'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => TRUE, 'auto_increment' => TRUE, 'null' => FALSE],
                'user_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => TRUE, 'null' => FALSE],
                'purpose' => ['type' => 'VARCHAR', 'constraint' => 24, 'null' => FALSE],
                'code_hash' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => FALSE],
                'attempts' => ['type' => 'TINYINT', 'constraint' => 3, 'unsigned' => TRUE, 'null' => FALSE, 'default' => 0],
                'expires_at' => ['type' => 'DATETIME', 'null' => FALSE],
                'consumed_at' => ['type' => 'DATETIME', 'null' => TRUE, 'default' => NULL],
                'created_at' => ['type' => 'DATETIME', 'null' => FALSE],
            ])
                ->add_key('id', primary: TRUE)
                ->add_key(['user_id', 'purpose', 'created_at'], name: 'email_codes_user_purpose_created_idx')
                ->add_key(['expires_at', 'consumed_at'], name: 'email_codes_expiration_idx')
                ->create_table('email_verification_codes');
        }

        $this->ensure_foreign_key($db);
    }

    public function down()
    {
        $forge = $this->_lava->dbforge;
        $db = $this->_lava->call->database();
        if ($forge->table_exists('email_verification_codes')) {
            if ($this->foreign_key_exists($db)) {
                $db->raw('ALTER TABLE `email_verification_codes` DROP FOREIGN KEY `fk_email_codes_user`');
            }
            $forge->drop_table('email_verification_codes');
        }
        if ($forge->table_exists('users') && $forge->column_exists('users', 'email_verified_at')) {
            $forge->drop_column('users', 'email_verified_at');
        }
    }

    private function ensure_foreign_key($db)
    {
        if ($this->foreign_key_exists($db)) return;
        $db->raw(
            'ALTER TABLE `email_verification_codes` ADD CONSTRAINT `fk_email_codes_user` '
            . 'FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE'
        );
    }

    private function foreign_key_exists($db)
    {
        $statement = $db->raw(
            'SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS '
            . 'WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            ['email_verification_codes', 'fk_email_codes_user']
        );
        return (bool) $statement->fetchColumn();
    }
}
