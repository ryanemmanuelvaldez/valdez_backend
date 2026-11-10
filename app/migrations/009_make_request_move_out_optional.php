<?php

class Make_request_move_out_optional
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->dbforge();
    }

    public function up()
    {
        if ($this->_lava->dbforge->table_exists('rental_requests')
            && $this->_lava->dbforge->column_exists('rental_requests', 'move_out')) {
            $this->_lava->dbforge->modify_column('rental_requests', [
                'move_out' => [
                    'type' => 'DATE',
                    'null' => TRUE,
                    'default' => NULL,
                ],
            ]);
        }
    }

    public function down()
    {
        if ($this->_lava->dbforge->table_exists('rental_requests')
            && $this->_lava->dbforge->column_exists('rental_requests', 'move_out')) {
            $db = $this->_lava->call->database();
            foreach ($db->table('rental_requests')->get_all() as $request) {
                if ($request['move_out'] !== null || empty($request['move_in'])) {
                    continue;
                }
                $move_out = new DateTime($request['move_in']);
                $move_out->modify('+' . max(1, (int) $request['term_months']) . ' months');
                $db->table('rental_requests')
                    ->where('id', (int) $request['id'])
                    ->update(['move_out' => $move_out->format('Y-m-d')]);
            }
            $this->_lava->dbforge->modify_column('rental_requests', [
                'move_out' => [
                    'type' => 'DATE',
                    'null' => FALSE,
                ],
            ]);
        }
    }
}
