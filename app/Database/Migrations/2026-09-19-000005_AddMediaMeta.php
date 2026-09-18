<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddMediaMeta extends Migration
{
    public function up()
    {
        $this->forge->addColumn('media', [
            'meta' => ['type' => 'TEXT', 'null' => true, 'after' => 'stats'],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('media', 'meta');
    }
}
