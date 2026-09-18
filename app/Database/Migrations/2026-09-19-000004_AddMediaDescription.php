<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddMediaDescription extends Migration
{
    public function up()
    {
        $this->forge->addColumn('media', [
            'description' => ['type' => 'TEXT', 'null' => true, 'after' => 'source_url'],
            'uploader'    => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true, 'after' => 'description'],
            'stats'       => ['type' => 'TEXT', 'null' => true, 'after' => 'uploader'],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('media', ['description', 'uploader', 'stats']);
    }
}
