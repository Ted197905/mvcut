<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddMediaPost extends Migration
{
    public function up()
    {
        // everything imported from one link belongs to one post
        $this->forge->addColumn('media', [
            'post_key'   => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true, 'after' => 'source_url'],
            'post_order' => ['type' => 'INT', 'null' => false, 'default' => 0, 'after' => 'post_key'],
        ]);
        $this->db->query('CREATE INDEX media_post_key ON media (post_key)');
    }

    public function down()
    {
        $this->db->query('DROP INDEX media_post_key ON media');
        $this->forge->dropColumn('media', ['post_key', 'post_order']);
    }
}
