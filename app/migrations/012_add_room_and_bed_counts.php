<?php

class Add_room_and_bed_counts
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
        if (!$forge->table_exists('rentals')) {
            throw new RuntimeException('The rentals table must exist before adding room and bed counts.');
        }

        if (!$forge->column_exists('rentals', 'rooms_count')) {
            $forge->add_column('rentals', [
                'rooms_count' => ['type' => 'SMALLINT', 'constraint' => 5, 'unsigned' => TRUE, 'null' => TRUE, 'default' => NULL],
            ]);
        }
        if (!$forge->column_exists('rentals', 'bed_count')) {
            $forge->add_column('rentals', [
                'bed_count' => ['type' => 'SMALLINT', 'constraint' => 5, 'unsigned' => TRUE, 'null' => FALSE, 'default' => 0],
            ]);
        }
    }

    public function down()
    {
        $forge = $this->_lava->dbforge;
        foreach (['rooms_count', 'bed_count'] as $column) {
            if ($forge->column_exists('rentals', $column)) {
                $forge->drop_column('rentals', $column);
            }
        }
    }
}
