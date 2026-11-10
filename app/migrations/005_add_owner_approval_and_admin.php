<?php

class Add_owner_approval_and_admin
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
            throw new RuntimeException('The users table must exist before the admin account can be initialized.');
        }

        if (!$this->_lava->dbforge->column_exists('users', 'owner_status')) {
            $this->_lava->dbforge->add_column('users', [
                'owner_status' => [
                    'type' => 'VARCHAR',
                    'constraint' => 20,
                    'null' => FALSE,
                    'default' => 'not_required',
                ],
            ]);
        }

        $email = strtolower(trim((string) rentease_env('ADMIN_EMAIL')));
        $password = (string) rentease_env('ADMIN_PASSWORD');

        $valid_email = filter_var($email, FILTER_VALIDATE_EMAIL)
            || preg_match('/^[A-Z0-9._%+-]+@[A-Z0-9-]+$/iD', $email) === 1;
        if (!$valid_email || strlen($email) > 255) {
            throw new RuntimeException('Set ADMIN_EMAIL in BackendLL/.env to initialize the single administrator account.');
        }
        if (strlen($password) < 8 || strlen($password) > 72) {
            throw new RuntimeException('Set ADMIN_PASSWORD in BackendLL/.env to a value between 8 and 72 characters.');
        }

        $db = $this->_lava->call->database();
        $admins = $db->table('users')
            ->where('role', 'admin')
            ->get_all();

        if (count($admins) > 1) {
            throw new RuntimeException('More than one administrator exists. Keep exactly one admin account before migrating.');
        }

        if (count($admins) === 1 && strtolower($admins[0]['email']) !== $email) {
            throw new RuntimeException('An administrator already exists with a different email. Keep the existing admin account.');
        }

        $account = $db->table('users')
            ->where('email', $email)
            ->row_array();

        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        if ($password_hash === FALSE) {
            throw new RuntimeException('The administrator password could not be hashed.');
        }

        $profile = [
            'role' => 'admin',
            'is_active' => 1,
            'password' => $password_hash,
            'owner_status' => 'not_required',
        ];

        if ($account) {
            $db->table('users')
                ->where('id', $account['id'])
                ->update($profile);
            return;
        }

        $db->table('users')->insert(array_merge($profile, [
            'username' => 'admin_' . bin2hex(random_bytes(16)),
            'email' => $email,
            'full_name' => 'RentEase Administrator',
            'phone' => '',
            'account_type' => 'tenant',
        ]));
    }

    public function down()
    {
        if ($this->_lava->dbforge->column_exists('users', 'owner_status')) {
            $this->_lava->dbforge->drop_column('users', 'owner_status');
        }
    }
}
