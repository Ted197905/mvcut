<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** 게시물 정리: one row per run of the extension's date-range delete (X 관리 page), with what it deleted. */
class CreateXPurges extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'user_id'    => ['type' => 'INT', 'unsigned' => true],
            'mode'       => ['type' => 'VARCHAR', 'constraint' => 8],     // scan | delete
            'from_at'    => ['type' => 'DATETIME'],
            'to_at'      => ['type' => 'DATETIME'],
            'kinds'      => ['type' => 'VARCHAR', 'constraint' => 32],
            'status'     => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'running'],   // running | done | stopped | error
            'found'      => ['type' => 'INT', 'default' => 0],
            'deleted'    => ['type' => 'INT', 'default' => 0],
            'failed'     => ['type' => 'INT', 'default' => 0],
            'error'      => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'items'      => ['type' => 'MEDIUMTEXT', 'null' => true],   // JSON [{id, time, kind, text, ok, error}]
            'created_at' => ['type' => 'DATETIME'],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['user_id', 'id']);
        $this->forge->createTable('x_purges');
    }

    public function down()
    {
        $this->forge->dropTable('x_purges');
    }
}
