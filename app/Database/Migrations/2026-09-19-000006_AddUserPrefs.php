<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddUserPrefs extends Migration
{
    public function up()
    {
        $this->forge->addColumn('users', [
            'prefs' => ['type' => 'TEXT', 'null' => true, 'after' => 'display_name'],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('users', 'prefs');
    }
}
