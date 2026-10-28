<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Since when the account is in restricted mode (the "[제한 감지]" alert went out), kept so that
 * "[제한 풀림]" is sent whenever the episode ends, whatever ended it (a worker restart in between,
 * a checker other than the probe calling it clear).
 */
class AddXWatchRestrictedAt extends Migration
{
    public function up()
    {
        $this->forge->addColumn('x_watch', [
            'restricted_at' => ['type' => 'DATETIME', 'null' => true, 'after' => 'alerted_at'],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('x_watch', 'restricted_at');
    }
}
