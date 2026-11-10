<?php

class Add_rental_details
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
            throw new RuntimeException('The rentals table must exist before adding rental details.');
        }

        $fields = [
            'address' => ['type' => 'VARCHAR', 'constraint' => 180, 'null' => FALSE, 'default' => 'Not provided'],
            'unit_number' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => FALSE, 'default' => ''],
            'bedrooms' => ['type' => 'TINYINT', 'constraint' => 3, 'unsigned' => TRUE, 'null' => FALSE, 'default' => 0],
            'bathrooms' => ['type' => 'TINYINT', 'constraint' => 3, 'unsigned' => TRUE, 'null' => FALSE, 'default' => 0],
            'max_occupants' => ['type' => 'SMALLINT', 'constraint' => 5, 'unsigned' => TRUE, 'null' => FALSE, 'default' => 1],
            'floor_area' => ['type' => 'DECIMAL', 'constraint' => '8,2', 'null' => TRUE],
            'furnishing' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => FALSE, 'default' => 'unfurnished'],
            'utilities_included' => ['type' => 'TINYINT', 'constraint' => 1, 'unsigned' => TRUE, 'null' => FALSE, 'default' => 0],
            'available_from' => ['type' => 'DATE', 'null' => TRUE],
            'security_deposit' => ['type' => 'DECIMAL', 'constraint' => '10,2', 'null' => FALSE, 'default' => 0],
            'amenities' => ['type' => 'TEXT', 'null' => TRUE],
            'contact_phone' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => FALSE, 'default' => ''],
            'photo_url' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => FALSE, 'default' => ''],
        ];

        foreach ($fields as $name => $definition) {
            if (!$this->_lava->dbforge->column_exists('rentals', $name)) {
                $this->_lava->dbforge->add_column('rentals', [$name => $definition]);
            }
        }
    }

    public function down()
    {
        foreach ([
            'address', 'unit_number', 'bedrooms', 'bathrooms', 'max_occupants',
            'floor_area', 'furnishing', 'utilities_included', 'available_from',
            'security_deposit', 'amenities', 'contact_phone', 'photo_url',
        ] as $column) {
            if ($this->_lava->dbforge->column_exists('rentals', $column)) {
                $this->_lava->dbforge->drop_column('rentals', $column);
            }
        }
    }
}
