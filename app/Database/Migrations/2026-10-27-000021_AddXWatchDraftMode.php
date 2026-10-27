<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * How approved drafts reach X: 'semi' (X's composer filled, the user schedules) or 'auto' (the
 * extension schedules them, one every 10 minutes); when the extension was last handed one.
 */
class AddXWatchDraftMode extends Migration
{
    public function up()
    {
        $this->forge->addColumn('x_watch', [
            'draft_mode'     => ['type' => 'VARCHAR', 'constraint' => 8, 'default' => 'semi', 'after' => 'alerted_at'],
            'draft_queue_at' => ['type' => 'DATETIME', 'null' => true, 'after' => 'draft_mode'],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('x_watch', ['draft_mode', 'draft_queue_at']);
    }
}
