<?php

class Add_relational_foreign_keys
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->dbforge();
    }

    public function up()
    {
        $db = $this->_lava->call->database();
        foreach ($this->foreign_keys() as $key) {
            $orphan_count = (int) $db->raw($key['orphan_query'])->fetchColumn();
            if ($orphan_count > 0) {
                throw new RuntimeException(
                    'Cannot add ' . $key['name'] . ': ' . $orphan_count . ' orphaned records exist in ' . $key['table'] . '.'
                );
            }

            if (!$this->foreign_key_exists($db, $key['table'], $key['name'])) {
                $db->raw(
                    'ALTER TABLE `' . $key['table'] . '` ADD CONSTRAINT `' . $key['name'] . '` '
                    . 'FOREIGN KEY (`' . $key['column'] . '`) REFERENCES `' . $key['references'] . '` (`id`) '
                    . 'ON DELETE ' . $key['on_delete'] . ' ON UPDATE CASCADE'
                );
            }
        }
    }

    public function down()
    {
        $db = $this->_lava->call->database();
        foreach (array_reverse($this->foreign_keys()) as $key) {
            if ($this->foreign_key_exists($db, $key['table'], $key['name'])) {
                $db->raw('ALTER TABLE `' . $key['table'] . '` DROP FOREIGN KEY `' . $key['name'] . '`');
            }
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

    private function foreign_keys()
    {
        return [
            [
                'name' => 'fk_refresh_tokens_user',
                'table' => 'refresh_tokens',
                'column' => 'user_id',
                'references' => 'users',
                'on_delete' => 'CASCADE',
                'orphan_query' => 'SELECT COUNT(*) FROM `refresh_tokens` c LEFT JOIN `users` p ON p.id = c.user_id WHERE p.id IS NULL',
            ],
            [
                'name' => 'fk_rentals_owner',
                'table' => 'rentals',
                'column' => 'owner_id',
                'references' => 'users',
                'on_delete' => 'RESTRICT',
                'orphan_query' => 'SELECT COUNT(*) FROM `rentals` c LEFT JOIN `users` p ON p.id = c.owner_id WHERE p.id IS NULL',
            ],
            [
                'name' => 'fk_rental_requests_rental',
                'table' => 'rental_requests',
                'column' => 'rental_id',
                'references' => 'rentals',
                'on_delete' => 'RESTRICT',
                'orphan_query' => 'SELECT COUNT(*) FROM `rental_requests` c LEFT JOIN `rentals` p ON p.id = c.rental_id WHERE p.id IS NULL',
            ],
            [
                'name' => 'fk_rental_requests_tenant',
                'table' => 'rental_requests',
                'column' => 'tenant_id',
                'references' => 'users',
                'on_delete' => 'RESTRICT',
                'orphan_query' => 'SELECT COUNT(*) FROM `rental_requests` c LEFT JOIN `users` p ON p.id = c.tenant_id WHERE p.id IS NULL',
            ],
            [
                'name' => 'fk_rental_requests_owner',
                'table' => 'rental_requests',
                'column' => 'owner_id',
                'references' => 'users',
                'on_delete' => 'RESTRICT',
                'orphan_query' => 'SELECT COUNT(*) FROM `rental_requests` c LEFT JOIN `users` p ON p.id = c.owner_id WHERE p.id IS NULL',
            ],
            [
                'name' => 'fk_rental_payments_request',
                'table' => 'rental_payments',
                'column' => 'rental_request_id',
                'references' => 'rental_requests',
                'on_delete' => 'CASCADE',
                'orphan_query' => 'SELECT COUNT(*) FROM `rental_payments` c LEFT JOIN `rental_requests` p ON p.id = c.rental_request_id WHERE p.id IS NULL',
            ],
            [
                'name' => 'fk_rental_payments_tenant',
                'table' => 'rental_payments',
                'column' => 'tenant_id',
                'references' => 'users',
                'on_delete' => 'RESTRICT',
                'orphan_query' => 'SELECT COUNT(*) FROM `rental_payments` c LEFT JOIN `users` p ON p.id = c.tenant_id WHERE p.id IS NULL',
            ],
            [
                'name' => 'fk_rental_payments_rental',
                'table' => 'rental_payments',
                'column' => 'rental_id',
                'references' => 'rentals',
                'on_delete' => 'RESTRICT',
                'orphan_query' => 'SELECT COUNT(*) FROM `rental_payments` c LEFT JOIN `rentals` p ON p.id = c.rental_id WHERE p.id IS NULL',
            ],
            [
                'name' => 'fk_rental_installments_request',
                'table' => 'rental_installments',
                'column' => 'rental_request_id',
                'references' => 'rental_requests',
                'on_delete' => 'CASCADE',
                'orphan_query' => 'SELECT COUNT(*) FROM `rental_installments` c LEFT JOIN `rental_requests` p ON p.id = c.rental_request_id WHERE p.id IS NULL',
            ],
            [
                'name' => 'fk_rental_installments_tenant',
                'table' => 'rental_installments',
                'column' => 'tenant_id',
                'references' => 'users',
                'on_delete' => 'RESTRICT',
                'orphan_query' => 'SELECT COUNT(*) FROM `rental_installments` c LEFT JOIN `users` p ON p.id = c.tenant_id WHERE p.id IS NULL',
            ],
            [
                'name' => 'fk_rental_installments_rental',
                'table' => 'rental_installments',
                'column' => 'rental_id',
                'references' => 'rentals',
                'on_delete' => 'RESTRICT',
                'orphan_query' => 'SELECT COUNT(*) FROM `rental_installments` c LEFT JOIN `rentals` p ON p.id = c.rental_id WHERE p.id IS NULL',
            ],
            [
                'name' => 'fk_rental_reviews_request',
                'table' => 'rental_reviews',
                'column' => 'rental_request_id',
                'references' => 'rental_requests',
                'on_delete' => 'CASCADE',
                'orphan_query' => 'SELECT COUNT(*) FROM `rental_reviews` c LEFT JOIN `rental_requests` p ON p.id = c.rental_request_id WHERE p.id IS NULL',
            ],
            [
                'name' => 'fk_rental_reviews_tenant',
                'table' => 'rental_reviews',
                'column' => 'tenant_id',
                'references' => 'users',
                'on_delete' => 'RESTRICT',
                'orphan_query' => 'SELECT COUNT(*) FROM `rental_reviews` c LEFT JOIN `users` p ON p.id = c.tenant_id WHERE p.id IS NULL',
            ],
            [
                'name' => 'fk_rental_reviews_rental',
                'table' => 'rental_reviews',
                'column' => 'rental_id',
                'references' => 'rentals',
                'on_delete' => 'RESTRICT',
                'orphan_query' => 'SELECT COUNT(*) FROM `rental_reviews` c LEFT JOIN `rentals` p ON p.id = c.rental_id WHERE p.id IS NULL',
            ],
            [
                'name' => 'fk_support_tickets_user',
                'table' => 'support_tickets',
                'column' => 'user_id',
                'references' => 'users',
                'on_delete' => 'CASCADE',
                'orphan_query' => 'SELECT COUNT(*) FROM `support_tickets` c LEFT JOIN `users` p ON p.id = c.user_id WHERE p.id IS NULL',
            ],
            [
                'name' => 'fk_support_tickets_request',
                'table' => 'support_tickets',
                'column' => 'rental_request_id',
                'references' => 'rental_requests',
                'on_delete' => 'SET NULL',
                'orphan_query' => 'SELECT COUNT(*) FROM `support_tickets` c LEFT JOIN `rental_requests` p ON p.id = c.rental_request_id WHERE c.rental_request_id IS NOT NULL AND p.id IS NULL',
            ],
            [
                'name' => 'fk_announcements_creator',
                'table' => 'announcements',
                'column' => 'created_by',
                'references' => 'users',
                'on_delete' => 'RESTRICT',
                'orphan_query' => 'SELECT COUNT(*) FROM `announcements` c LEFT JOIN `users` p ON p.id = c.created_by WHERE p.id IS NULL',
            ],
            [
                'name' => 'fk_admin_audit_logs_admin',
                'table' => 'admin_audit_logs',
                'column' => 'admin_id',
                'references' => 'users',
                'on_delete' => 'SET NULL',
                'orphan_query' => 'SELECT COUNT(*) FROM `admin_audit_logs` c LEFT JOIN `users` p ON p.id = c.admin_id WHERE c.admin_id IS NOT NULL AND p.id IS NULL',
            ],
            [
                'name' => 'fk_platform_settings_updater',
                'table' => 'platform_settings',
                'column' => 'updated_by',
                'references' => 'users',
                'on_delete' => 'SET NULL',
                'orphan_query' => 'SELECT COUNT(*) FROM `platform_settings` c LEFT JOIN `users` p ON p.id = c.updated_by WHERE c.updated_by IS NOT NULL AND p.id IS NULL',
            ],
        ];
    }
}
