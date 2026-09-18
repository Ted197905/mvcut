<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateMedia extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'user_id'     => ['type' => 'INT', 'unsigned' => true],
            'parent_id'   => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'kind'        => ['type' => 'ENUM', 'constraint' => ['original', 'result'], 'default' => 'original'],
            'source'      => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'upload'],
            'source_url'  => ['type' => 'VARCHAR', 'constraint' => 1000, 'null' => true],
            'title'       => ['type' => 'VARCHAR', 'constraint' => 255],
            'filename'    => ['type' => 'VARCHAR', 'constraint' => 255],
            'media_type'  => ['type' => 'ENUM', 'constraint' => ['video', 'image', 'audio', 'unknown'], 'default' => 'unknown'],
            'mime'        => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'container'   => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
            'vcodec'      => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
            'acodec'      => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
            'width'       => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'height'      => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'duration'    => ['type' => 'DECIMAL', 'constraint' => '10,3', 'null' => true],
            'fps'         => ['type' => 'DECIMAL', 'constraint' => '7,3', 'null' => true],
            'size'        => ['type' => 'BIGINT', 'unsigned' => true, 'default' => 0],
            'has_thumb'   => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'has_proxy'   => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'edit_params' => ['type' => 'TEXT', 'null' => true],
            'status'      => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'ready'],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['user_id', 'created_at']);
        $this->forge->addKey('parent_id');
        $this->forge->createTable('media');
    }

    public function down()
    {
        $this->forge->dropTable('media');
    }
}
