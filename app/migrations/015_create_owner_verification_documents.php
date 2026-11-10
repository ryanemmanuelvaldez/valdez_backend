<?php

class Create_owner_verification_documents
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
        if (!$forge->table_exists('owner_verification_documents')) {
            $forge->add_field([
                'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => TRUE, 'auto_increment' => TRUE],
                'owner_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => TRUE, 'null' => FALSE],
                'file_name' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => FALSE],
                'mime_type' => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => FALSE],
                'file_data' => ['type' => 'MEDIUMBLOB', 'null' => FALSE],
                'created_at' => ['type' => 'DATETIME', 'null' => FALSE, 'default' => 'CURRENT_TIMESTAMP'],
            ])
                ->add_key('id', primary: TRUE)
                ->add_key('owner_id', name: 'owner_verification_documents_owner_idx')
                ->create_table('owner_verification_documents');
        }

        $this->ensure_foreign_key($this->_lava->call->database());
    }

    public function down()
    {
        $db = $this->_lava->call->database();
        if ($this->_lava->dbforge->table_exists('owner_verification_documents')) {
            if ($this->foreign_key_exists($db)) {
                $db->raw('ALTER TABLE `owner_verification_documents` DROP FOREIGN KEY `fk_owner_verification_documents_owner`');
            }
            $this->_lava->dbforge->drop_table('owner_verification_documents');
        }
    }

    private function ensure_foreign_key($db)
    {
        if ($this->foreign_key_exists($db)) return;
        $db->raw(
            'ALTER TABLE `owner_verification_documents` ADD CONSTRAINT `fk_owner_verification_documents_owner` '
            . 'FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE'
        );
    }

    private function foreign_key_exists($db)
    {
        $statement = $db->raw(
            'SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS '
            . 'WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            ['owner_verification_documents', 'fk_owner_verification_documents_owner']
        );
        return (bool) $statement->fetchColumn();
    }
}
