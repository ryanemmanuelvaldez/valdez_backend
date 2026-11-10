<?php

class Add_owner_wallet_qr_and_manual_payments
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

        if (!$forge->column_exists('rental_payments', 'payment_method')) {
            $forge->add_column('rental_payments', [
                'payment_method' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => TRUE, 'default' => NULL],
            ]);
        }
        if (!$forge->column_exists('rental_payments', 'payment_note')) {
            $forge->add_column('rental_payments', [
                'payment_note' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => TRUE, 'default' => NULL],
            ]);
        }
        if (!$forge->column_exists('rental_installments', 'payment_note')) {
            $forge->add_column('rental_installments', [
                'payment_note' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => TRUE, 'default' => NULL],
            ]);
        }
        if (!$forge->table_exists('owner_payment_profiles')) {
            $forge->add_field([
                'owner_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => TRUE, 'null' => FALSE],
                'gcash_qr_data' => ['type' => 'MEDIUMTEXT', 'null' => TRUE],
                'maya_qr_data' => ['type' => 'MEDIUMTEXT', 'null' => TRUE],
                'created_at' => ['type' => 'DATETIME', 'null' => FALSE, 'default' => 'CURRENT_TIMESTAMP'],
                'updated_at' => ['type' => 'DATETIME', 'null' => TRUE, 'default' => NULL],
            ])
                ->add_key('owner_id', primary: TRUE)
                ->create_table('owner_payment_profiles');
        }

        $this->ensure_foreign_key($db, 'owner_payment_profiles', 'fk_owner_payment_profiles_owner', 'owner_id', 'users', 'CASCADE');
    }

    public function down()
    {
        $forge = $this->_lava->dbforge;
        if ($forge->table_exists('owner_payment_profiles')) {
            $this->drop_foreign_key($this->_lava->call->database(), 'owner_payment_profiles', 'fk_owner_payment_profiles_owner');
            $forge->drop_table('owner_payment_profiles');
        }
        foreach ([
            ['rental_payments', 'payment_note'],
            ['rental_payments', 'payment_method'],
            ['rental_installments', 'payment_note'],
        ] as [$table, $column]) {
            if ($forge->table_exists($table) && $forge->column_exists($table, $column)) {
                $forge->drop_column($table, $column);
            }
        }
    }

    private function ensure_foreign_key($db, $table, $name, $column, $references, $on_delete)
    {
        if ($this->foreign_key_exists($db, $table, $name)) return;
        $db->raw(
            'ALTER TABLE `' . $table . '` ADD CONSTRAINT `' . $name . '` '
            . 'FOREIGN KEY (`' . $column . '`) REFERENCES `' . $references . '` (`id`) '
            . 'ON DELETE ' . $on_delete . ' ON UPDATE CASCADE'
        );
    }

    private function drop_foreign_key($db, $table, $name)
    {
        if ($this->foreign_key_exists($db, $table, $name)) {
            $db->raw('ALTER TABLE `' . $table . '` DROP FOREIGN KEY `' . $name . '`');
        }
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
