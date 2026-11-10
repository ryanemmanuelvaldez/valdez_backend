<?php

class Create_rentals_table
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->dbforge();
    }

    public function up()
    {
        if ($this->_lava->dbforge->table_exists('rentals')) {
            return;
        }

        $this->_lava->dbforge
            ->add_field([
                'id' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'unsigned' => TRUE,
                    'auto_increment' => TRUE,
                    'null' => FALSE,
                ],
                'owner_id' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'unsigned' => TRUE,
                    'null' => FALSE,
                ],
                'title' => [
                    'type' => 'VARCHAR',
                    'constraint' => 120,
                    'null' => FALSE,
                ],
                'description' => [
                    'type' => 'TEXT',
                    'null' => FALSE,
                ],
                'property_type' => [
                    'type' => 'VARCHAR',
                    'constraint' => 20,
                    'null' => FALSE,
                ],
                'price' => [
                    'type' => 'DECIMAL',
                    'constraint' => '10,2',
                    'null' => FALSE,
                ],
                'location' => [
                    'type' => 'VARCHAR',
                    'constraint' => 180,
                    'null' => FALSE,
                ],
                'status' => [
                    'type' => 'VARCHAR',
                    'constraint' => 20,
                    'null' => FALSE,
                    'default' => 'pending',
                ],
                'is_featured' => [
                    'type' => 'TINYINT',
                    'constraint' => 1,
                    'unsigned' => TRUE,
                    'null' => FALSE,
                    'default' => 0,
                ],
                'created_at' => [
                    'type' => 'DATETIME',
                    'null' => FALSE,
                    'default' => 'CURRENT_TIMESTAMP',
                ],
                'updated_at' => [
                    'type' => 'DATETIME',
                    'null' => TRUE,
                    'default' => NULL,
                ],
            ])
            ->add_key('id', primary: TRUE)
            ->add_key('owner_id', name: 'rentals_owner_idx')
            ->add_key('status', name: 'rentals_status_idx')
            ->add_key('is_featured', name: 'rentals_featured_idx')
            ->create_table('rentals');
    }

    public function down()
    {
        $this->_lava->dbforge->drop_table('rentals');
    }
}
