<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** X account watch: settings per user, posts reported by the Chrome extension, restriction checks. */
class CreateXWatch extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'            => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'user_id'       => ['type' => 'INT', 'unsigned' => true],
            'handle'        => ['type' => 'VARCHAR', 'constraint' => 15, 'default' => ''],
            'token_hash'    => ['type' => 'CHAR', 'constraint' => 64, 'null' => true],
            'enabled'       => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'gap_min'       => ['type' => 'INT', 'default' => 15],
            'hour_max'      => ['type' => 'INT', 'default' => 4],
            'day_max'       => ['type' => 'INT', 'default' => 30],
            'next_check_at' => ['type' => 'DATETIME', 'null' => true],
            'last_check_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
            'updated_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('user_id');
        $this->forge->addUniqueKey('token_hash');
        $this->forge->createTable('x_watch');

        $this->forge->addField([
            'id'          => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'user_id'     => ['type' => 'INT', 'unsigned' => true],
            'tweet_id'    => ['type' => 'VARCHAR', 'constraint' => 24],
            'handle'      => ['type' => 'VARCHAR', 'constraint' => 15],
            'kind'        => ['type' => 'VARCHAR', 'constraint' => 10, 'default' => 'post'], // post | reply | quote | repost
            'posted_at'   => ['type' => 'DATETIME'],                                         // UTC
            'text'        => ['type' => 'VARCHAR', 'constraint' => 300, 'default' => ''],
            'reply_to'    => ['type' => 'VARCHAR', 'constraint' => 15, 'default' => ''],
            'captured_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['user_id', 'tweet_id']);
        $this->forge->addKey(['user_id', 'posted_at']);
        $this->forge->createTable('x_posts');

        $this->forge->addField([
            'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'user_id'    => ['type' => 'INT', 'unsigned' => true],
            'handle'     => ['type' => 'VARCHAR', 'constraint' => 15],
            'status'     => ['type' => 'VARCHAR', 'constraint' => 10], // ok | banned | error
            'summary'    => ['type' => 'VARCHAR', 'constraint' => 200, 'default' => ''],
            'result'     => ['type' => 'TEXT', 'null' => true],     // xcheck.py JSON
            'checked_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['user_id', 'checked_at']);
        $this->forge->createTable('x_checks');
    }

    public function down()
    {
        $this->forge->dropTable('x_checks');
        $this->forge->dropTable('x_posts');
        $this->forge->dropTable('x_watch');
    }
}
