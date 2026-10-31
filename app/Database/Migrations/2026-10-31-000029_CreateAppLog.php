<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Site-wide action/error log (App\Libraries\AppLog): web requests, worker steps, PHP errors, browser errors. */
class CreateAppLog extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'      => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'ts'      => ['type' => 'DATETIME'],
            'level'   => ['type' => 'VARCHAR', 'constraint' => 8],
            'area'    => ['type' => 'VARCHAR', 'constraint' => 24],
            'action'  => ['type' => 'VARCHAR', 'constraint' => 120],
            'user_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'req'     => ['type' => 'CHAR', 'constraint' => 12, 'null' => true],
            'ip'      => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true],
            'msg'     => ['type' => 'VARCHAR', 'constraint' => 600],
            'detail'  => ['type' => 'MEDIUMTEXT', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('ts');
        $this->forge->addKey(['level', 'ts']);
        $this->forge->addKey(['area', 'ts']);
        $this->forge->addKey(['user_id', 'ts']);
        $this->forge->addKey('req');
        $this->forge->createTable('app_log');
    }

    public function down()
    {
        $this->forge->dropTable('app_log');
    }
}
