<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** When the "restriction suspected" Telegram alert of the current episode went out. */
class AddXWatchAlertedAt extends Migration
{
    public function up()
    {
        $this->forge->addColumn('x_watch', [
            'alerted_at' => ['type' => 'DATETIME', 'null' => true, 'after' => 'scheduled_at'],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('x_watch', 'alerted_at');
    }
}
