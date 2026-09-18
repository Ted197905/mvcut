<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateJobs extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'              => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'user_id'         => ['type' => 'INT', 'unsigned' => true],
            'media_id'        => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'type'            => ['type' => 'VARCHAR', 'constraint' => 20],
            'params'          => ['type' => 'TEXT', 'null' => true],
            'status'          => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'queued'],
            'progress'        => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0],
            'result_media_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'error'           => ['type' => 'TEXT', 'null' => true],
            'started_at'      => ['type' => 'DATETIME', 'null' => true],
            'finished_at'     => ['type' => 'DATETIME', 'null' => true],
            'created_at'      => ['type' => 'DATETIME', 'null' => true],
            'updated_at'      => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['status', 'id']);
        $this->forge->addKey('user_id');
        $this->forge->createTable('jobs');
    }

    public function down()
    {
        $this->forge->dropTable('jobs');
    }
}
