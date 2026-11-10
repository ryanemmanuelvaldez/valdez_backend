<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class User_model extends Model
{
    protected $table = 'users';

    public function __construct()
    {
        parent::__construct();
        $this->call->database();
    }

    public function findByEmail(string $email): ?array
    {
        return $this->query()
            ->where('email', $email)
            ->row_array() ?: null;
    }

    public function findById(int $id): ?array
    {
        return $this->query()
            ->where('id', $id)
            ->row_array() ?: null;
    }

    public function createAccount(array $data)
    {
        return $this->insert($data);
    }

    public function updatePassword(int $id, string $password_hash): int
    {
        return (int) $this->query()
            ->where('id', $id)
            ->update(['password' => $password_hash]);
    }
}
