<?php

class Add_document_types_and_rent_notifications
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

        if (!$forge->column_exists('owner_verification_documents', 'document_type')) {
            $forge->add_column('owner_verification_documents', [
                'document_type' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => TRUE, 'default' => NULL],
            ]);
        }

        if (!$forge->table_exists('rent_notifications')) {
            $forge->add_field([
                'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => TRUE, 'auto_increment' => TRUE, 'null' => FALSE],
                'user_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => TRUE, 'null' => FALSE],
                'rental_request_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => TRUE, 'null' => FALSE],
                'installment_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => TRUE, 'null' => FALSE],
                'notification_type' => ['type' => 'VARCHAR', 'constraint' => 24, 'null' => FALSE],
                'dedupe_key' => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => FALSE],
                'title' => ['type' => 'VARCHAR', 'constraint' => 140, 'null' => FALSE],
                'body' => ['type' => 'VARCHAR', 'constraint' => 1000, 'null' => FALSE],
                'due_date' => ['type' => 'DATE', 'null' => FALSE],
                'created_at' => ['type' => 'DATETIME', 'null' => FALSE, 'default' => 'CURRENT_TIMESTAMP'],
                'read_at' => ['type' => 'DATETIME', 'null' => TRUE, 'default' => NULL],
            ])
                ->add_key('id', primary: TRUE)
                ->add_key(['user_id', 'dedupe_key'], unique: TRUE, name: 'rent_notification_user_key_unique')
                ->add_key(['user_id', 'read_at', 'created_at'], name: 'rent_notification_user_read_idx')
                ->add_key(['installment_id', 'notification_type'], name: 'rent_notification_installment_idx')
                ->create_table('rent_notifications');
        }

        $this->ensure_foreign_key($db, 'owner_verification_documents', 'fk_owner_verification_documents_owner', 'owner_id', 'users');
        $this->ensure_foreign_key($db, 'rent_notifications', 'fk_rent_notifications_user', 'user_id', 'users');
        $this->ensure_foreign_key($db, 'rent_notifications', 'fk_rent_notifications_request', 'rental_request_id', 'rental_requests');
        $this->ensure_foreign_key($db, 'rent_notifications', 'fk_rent_notifications_installment', 'installment_id', 'rental_installments');
    }

    public function down()
    {
        $db = $this->_lava->call->database();
        if ($this->_lava->dbforge->table_exists('rent_notifications')) {
            foreach (['fk_rent_notifications_installment', 'fk_rent_notifications_request', 'fk_rent_notifications_user'] as $name) {
                if ($this->foreign_key_exists($db, 'rent_notifications', $name)) {
                    $db->raw('ALTER TABLE `rent_notifications` DROP FOREIGN KEY `' . $name . '`');
                }
            }
            $this->_lava->dbforge->drop_table('rent_notifications');
        }
        if ($this->_lava->dbforge->table_exists('owner_verification_documents')) {
            if ($this->foreign_key_exists($db, 'owner_verification_documents', 'fk_owner_verification_documents_owner')) {
                $db->raw('ALTER TABLE `owner_verification_documents` DROP FOREIGN KEY `fk_owner_verification_documents_owner`');
            }
            if ($this->_lava->dbforge->column_exists('owner_verification_documents', 'document_type')) {
                $this->_lava->dbforge->drop_column('owner_verification_documents', 'document_type');
            }
            $this->ensure_foreign_key($db, 'owner_verification_documents', 'fk_owner_verification_documents_owner', 'owner_id', 'users');
        }
    }

    private function ensure_foreign_key($db, $table, $name, $column, $references)
    {
        if ($this->foreign_key_exists($db, $table, $name)) return;
        $db->raw(
            'ALTER TABLE `' . $table . '` ADD CONSTRAINT `' . $name . '` '
            . 'FOREIGN KEY (`' . $column . '`) REFERENCES `' . $references . '` (`id`) '
            . 'ON DELETE CASCADE ON UPDATE CASCADE'
        );
    }

    private function foreign_key_exists($db, $table, $name)
    {
        $statement = $db->raw(
            'SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS '
            . 'WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            [$table, $name]
        );
        return (bool) $statement->fetchColumn();
    }
}
