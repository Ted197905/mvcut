<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** /youtube: public (no login) YouTube downloads with subtitles and an LLM summary. */
class CreateYtVideos extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'video_id'    => ['type' => 'VARCHAR', 'constraint' => 32],
            'url'         => ['type' => 'VARCHAR', 'constraint' => 500],
            'title'       => ['type' => 'VARCHAR', 'constraint' => 255, 'default' => ''],
            'channel'     => ['type' => 'VARCHAR', 'constraint' => 255, 'default' => ''],
            'description' => ['type' => 'MEDIUMTEXT', 'null' => true],
            'uploaded_at' => ['type' => 'DATE', 'null' => true],
            'duration'    => ['type' => 'FLOAT', 'null' => true],
            'width'       => ['type' => 'INT', 'null' => true],
            'height'      => ['type' => 'INT', 'null' => true],
            'size'        => ['type' => 'BIGINT', 'unsigned' => true, 'default' => 0],
            'filename'    => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'has_thumb'   => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'subs'        => ['type' => 'TEXT', 'null' => true],       // JSON: [{lang, name, auto, file}]
            'cast'        => ['type' => 'TEXT', 'null' => true],       // JSON: [{name, role}]
            'summary'     => ['type' => 'MEDIUMTEXT', 'null' => true],
            'status'      => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'queued'], // queued | downloading | analyzing | ready | failed
            'error'       => ['type' => 'TEXT', 'null' => true],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('video_id');
        $this->forge->createTable('yt_videos');
    }

    public function down()
    {
        $this->forge->dropTable('yt_videos');
    }
}
