<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** X 인용 대상 seen in the user's own X session (the extension reads the timelines the browser loads). */
class CreateXQuotes extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'        => ['type' => 'BIGINT', 'unsigned' => true],   // the post id
            'user_id'   => ['type' => 'INT', 'unsigned' => true],
            'handle'    => ['type' => 'VARCHAR', 'constraint' => 32],
            'name'      => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'text'      => ['type' => 'TEXT', 'null' => true],
            'thumb'     => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'posted_at' => ['type' => 'DATETIME'],
            'views'     => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'likes'     => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'replies'   => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'reposts'   => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'bookmarks' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'seen_at'   => ['type' => 'DATETIME'],
            'used_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('posted_at');
        $this->forge->createTable('x_quotes');
    }

    public function down()
    {
        $this->forge->dropTable('x_quotes');
    }
}
