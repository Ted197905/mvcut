<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** The account's scheduled posts as the extension last read them from X (JSON list). */
class AddXWatchScheduled extends Migration
{
    public function up()
    {
        $this->forge->addColumn('x_watch', [
            'scheduled'    => ['type' => 'MEDIUMTEXT', 'null' => true],
            'scheduled_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('x_watch', ['scheduled', 'scheduled_at']);
    }
}
