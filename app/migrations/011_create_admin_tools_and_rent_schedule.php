<?php

class Create_admin_tools_and_rent_schedule
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

        if (!$forge->table_exists('rental_installments')) {
            $forge->add_field([
                'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => TRUE, 'auto_increment' => TRUE, 'null' => FALSE],
                'rental_request_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => TRUE, 'null' => FALSE],
                'tenant_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => TRUE, 'null' => FALSE],
                'rental_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => TRUE, 'null' => FALSE],
                'installment_number' => ['type' => 'SMALLINT', 'constraint' => 5, 'unsigned' => TRUE, 'null' => FALSE],
                'due_date' => ['type' => 'DATE', 'null' => FALSE],
                'amount_centavos' => ['type' => 'BIGINT', 'unsigned' => TRUE, 'null' => FALSE],
                'status' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => FALSE, 'default' => 'due'],
                'payment_method' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => TRUE],
                'reference_number' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => TRUE],
                'checkout_session_id' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => TRUE],
                'checkout_url' => ['type' => 'TEXT', 'null' => TRUE],
                'provider_payment_id' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => TRUE],
                'paid_at' => ['type' => 'DATETIME', 'null' => TRUE, 'default' => NULL],
                'created_at' => ['type' => 'DATETIME', 'null' => FALSE, 'default' => 'CURRENT_TIMESTAMP'],
                'updated_at' => ['type' => 'DATETIME', 'null' => TRUE, 'default' => NULL],
            ])
                ->add_key('id', primary: TRUE)
                ->add_key(['rental_request_id', 'installment_number'], unique: TRUE, name: 'rental_installment_request_number_unique')
                ->add_key('reference_number', unique: TRUE, name: 'rental_installment_reference_unique')
                ->add_key('checkout_session_id', unique: TRUE, name: 'rental_installment_session_unique')
                ->add_key('tenant_id', name: 'rental_installment_tenant_idx')
                ->add_key('rental_id', name: 'rental_installment_rental_idx')
                ->add_key(['status', 'due_date'], name: 'rental_installment_status_due_idx')
                ->create_table('rental_installments');
        }

        if (!$forge->table_exists('rental_reviews')) {
            $forge->add_field([
                'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => TRUE, 'auto_increment' => TRUE, 'null' => FALSE],
                'rental_request_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => TRUE, 'null' => FALSE],
                'tenant_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => TRUE, 'null' => FALSE],
                'rental_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => TRUE, 'null' => FALSE],
                'rating' => ['type' => 'TINYINT', 'constraint' => 1, 'unsigned' => TRUE, 'null' => FALSE],
                'body' => ['type' => 'TEXT', 'null' => FALSE],
                'status' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => FALSE, 'default' => 'pending'],
                'moderation_note' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => TRUE],
                'created_at' => ['type' => 'DATETIME', 'null' => FALSE, 'default' => 'CURRENT_TIMESTAMP'],
                'updated_at' => ['type' => 'DATETIME', 'null' => TRUE, 'default' => NULL],
            ])
                ->add_key('id', primary: TRUE)
                ->add_key('rental_request_id', unique: TRUE, name: 'rental_reviews_request_unique')
                ->add_key('tenant_id', name: 'rental_reviews_tenant_idx')
                ->add_key('rental_id', name: 'rental_reviews_rental_idx')
                ->add_key('status', name: 'rental_reviews_status_idx')
                ->create_table('rental_reviews');
        }

        if (!$forge->table_exists('support_tickets')) {
            $forge->add_field([
                'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => TRUE, 'auto_increment' => TRUE, 'null' => FALSE],
                'user_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => TRUE, 'null' => FALSE],
                'rental_request_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => TRUE, 'null' => TRUE, 'default' => NULL],
                'subject' => ['type' => 'VARCHAR', 'constraint' => 160, 'null' => FALSE],
                'message' => ['type' => 'TEXT', 'null' => FALSE],
                'priority' => ['type' => 'VARCHAR', 'constraint' => 12, 'null' => FALSE, 'default' => 'normal'],
                'status' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => FALSE, 'default' => 'open'],
                'admin_reply' => ['type' => 'TEXT', 'null' => TRUE],
                'created_at' => ['type' => 'DATETIME', 'null' => FALSE, 'default' => 'CURRENT_TIMESTAMP'],
                'updated_at' => ['type' => 'DATETIME', 'null' => TRUE, 'default' => NULL],
            ])
                ->add_key('id', primary: TRUE)
                ->add_key('user_id', name: 'support_tickets_user_idx')
                ->add_key(['status', 'priority'], name: 'support_tickets_status_priority_idx')
                ->create_table('support_tickets');
        }

        if (!$forge->table_exists('announcements')) {
            $forge->add_field([
                'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => TRUE, 'auto_increment' => TRUE, 'null' => FALSE],
                'created_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => TRUE, 'null' => FALSE],
                'title' => ['type' => 'VARCHAR', 'constraint' => 160, 'null' => FALSE],
                'body' => ['type' => 'TEXT', 'null' => FALSE],
                'audience' => ['type' => 'VARCHAR', 'constraint' => 12, 'null' => FALSE, 'default' => 'all'],
                'status' => ['type' => 'VARCHAR', 'constraint' => 12, 'null' => FALSE, 'default' => 'draft'],
                'publish_at' => ['type' => 'DATETIME', 'null' => TRUE, 'default' => NULL],
                'created_at' => ['type' => 'DATETIME', 'null' => FALSE, 'default' => 'CURRENT_TIMESTAMP'],
                'updated_at' => ['type' => 'DATETIME', 'null' => TRUE, 'default' => NULL],
            ])
                ->add_key('id', primary: TRUE)
                ->add_key(['audience', 'status', 'publish_at'], name: 'announcements_audience_status_idx')
                ->add_key('created_by', name: 'announcements_creator_idx')
                ->create_table('announcements');
        }

        if (!$forge->table_exists('admin_audit_logs')) {
            $forge->add_field([
                'id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => TRUE, 'auto_increment' => TRUE, 'null' => FALSE],
                'admin_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => TRUE, 'null' => TRUE, 'default' => NULL],
                'action' => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => FALSE],
                'entity_type' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => FALSE],
                'entity_id' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => TRUE],
                'details' => ['type' => 'TEXT', 'null' => TRUE],
                'ip_address' => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => TRUE],
                'created_at' => ['type' => 'DATETIME', 'null' => FALSE, 'default' => 'CURRENT_TIMESTAMP'],
            ])
                ->add_key('id', primary: TRUE)
                ->add_key(['admin_id', 'created_at'], name: 'admin_audit_actor_date_idx')
                ->add_key(['entity_type', 'entity_id'], name: 'admin_audit_entity_idx')
                ->create_table('admin_audit_logs');
        }

        if (!$forge->table_exists('platform_settings')) {
            $forge->add_field([
                'setting_key' => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => FALSE],
                'setting_value' => ['type' => 'TEXT', 'null' => FALSE],
                'updated_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => TRUE, 'null' => TRUE, 'default' => NULL],
                'updated_at' => ['type' => 'DATETIME', 'null' => FALSE, 'default' => 'CURRENT_TIMESTAMP'],
            ])
                ->add_key('setting_key', primary: TRUE)
                ->create_table('platform_settings');
        }
    }

    public function down()
    {
        foreach (['platform_settings', 'admin_audit_logs', 'announcements', 'support_tickets', 'rental_reviews', 'rental_installments'] as $table) {
            $this->_lava->dbforge->drop_table($table, TRUE);
        }
    }
}
