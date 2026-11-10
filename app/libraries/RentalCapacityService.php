<?php

class RentalCapacityService
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function capacity(array $rental)
    {
        $rooms = (int) ($rental['rooms_count'] ?? $rental['bedrooms'] ?? 0);
        $per_room = (int) ($rental['bed_count'] ?? 0);
        if ($per_room < 1) {
            $per_room = (int) ($rental['max_occupants'] ?? 0);
        }
        if ($rooms < 1) {
            return max(0, $per_room);
        }
        return max(0, $rooms * $per_room);
    }

    public function occupiedSlots($rental_id)
    {
        return (int) $this->db->raw(
            'SELECT COALESCE(SUM(occupants), 0) FROM rental_requests '
            . 'WHERE rental_id = ? AND status IN (?, ?, ?) AND COALESCE(tenancy_status, ?) <> ?',
            [(int) $rental_id, 'pending', 'awaiting_payment', 'approved', 'active', 'ended']
        )->fetchColumn();
    }

    public function paidSlots($rental_id)
    {
        return (int) $this->db->raw(
            'SELECT COALESCE(SUM(rr.occupants), 0) FROM rental_requests rr '
            . 'INNER JOIN rental_payments rp ON rp.rental_request_id = rr.id '
            . 'WHERE rr.rental_id = ? AND rr.status = ? AND rp.status = ? AND COALESCE(rr.tenancy_status, ?) <> ?',
            [(int) $rental_id, 'approved', 'paid', 'active', 'ended']
        )->fetchColumn();
    }

    public function occupiedSlotsByRentalIds(array $rental_ids)
    {
        $rental_ids = array_values(array_unique(array_filter(array_map('intval', $rental_ids), static function ($id) {
            return $id > 0;
        })));
        if (!$rental_ids) return [];

        $placeholders = implode(',', array_fill(0, count($rental_ids), '?'));
        $statement = $this->db->raw(
            'SELECT rental_id, COALESCE(SUM(occupants), 0) AS occupied_slots '
            . 'FROM rental_requests WHERE rental_id IN (' . $placeholders . ') '
            . 'AND status IN (?, ?, ?) AND COALESCE(tenancy_status, ?) <> ? GROUP BY rental_id',
            array_merge($rental_ids, ['pending', 'awaiting_payment', 'approved', 'active', 'ended'])
        );
        $occupied = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $occupied[(int) $row['rental_id']] = (int) $row['occupied_slots'];
        }
        return $occupied;
    }

    public function lockRental($rental_id)
    {
        return $this->db->raw(
            'SELECT id, rooms_count, bedrooms, bed_count, max_occupants, status '
            . 'FROM rentals WHERE id = ? FOR UPDATE',
            [(int) $rental_id]
        )->fetch(PDO::FETCH_ASSOC);
    }
}
