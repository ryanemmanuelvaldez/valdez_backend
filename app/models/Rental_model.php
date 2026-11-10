<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Rental_model extends Model
{
    protected $table = 'rentals';

    public function __construct()
    {
        parent::__construct();
        $this->call->database();
    }

    public function publishedListings(string $property_type = ''): array
    {
        $query = $this->query()->where('status', 'published');
        if ($property_type !== '') {
            $query->where('property_type', $property_type);
        }

        return $query
            ->order_by('is_featured', 'DESC')
            ->order_by('created_at', 'DESC')
            ->limit(100)
            ->get_all();
    }

    public function findOwnedListings(int $owner_id): array
    {
        return $this->query()
            ->where('owner_id', $owner_id)
            ->order_by('created_at', 'DESC')
            ->limit(100)
            ->get_all();
    }

    public function findOwnedListing(int $id, int $owner_id): ?array
    {
        return $this->query()
            ->where('id', $id)
            ->where('owner_id', $owner_id)
            ->row_array() ?: null;
    }

    public function updateOwnedListing(int $id, int $owner_id, array $data): int
    {
        return (int) $this->query()
            ->where('id', $id)
            ->where('owner_id', $owner_id)
            ->update($data);
    }

    public function createListing(array $data)
    {
        return $this->query()->insert($data);
    }
}
