<?php

class Add_rental_coordinates
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->dbforge();
    }

    public function up()
    {
        if (!$this->_lava->dbforge->table_exists('rentals')) {
            throw new RuntimeException('The rentals table must exist before adding map coordinates.');
        }

        foreach ([
            'latitude' => ['type' => 'DECIMAL', 'constraint' => '10,7', 'null' => TRUE, 'default' => NULL],
            'longitude' => ['type' => 'DECIMAL', 'constraint' => '10,7', 'null' => TRUE, 'default' => NULL],
        ] as $name => $definition) {
            if (!$this->_lava->dbforge->column_exists('rentals', $name)) {
                $this->_lava->dbforge->add_column('rentals', [$name => $definition]);
            }
        }
    }

    public function down()
    {
        foreach (['latitude', 'longitude'] as $column) {
            if ($this->_lava->dbforge->column_exists('rentals', $column)) {
                $this->_lava->dbforge->drop_column('rentals', $column);
            }
        }
    }
}
