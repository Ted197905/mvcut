<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Background music tracks an admin uploads; files live in writable/bgm/{id}.{ext}. */
class CreateBgm extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'name'       => ['type' => 'VARCHAR', 'constraint' => 120],
            'filename'   => ['type' => 'VARCHAR', 'constraint' => 40],
            'duration'   => ['type' => 'DECIMAL', 'constraint' => '9,3', 'default' => 0],
            'size'       => ['type' => 'BIGINT', 'unsigned' => true, 'default' => 0],
            'sort'       => ['type' => 'INT', 'default' => 0],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('bgm');
    }

    public function down()
    {
        $this->forge->dropTable('bgm');
    }
}
