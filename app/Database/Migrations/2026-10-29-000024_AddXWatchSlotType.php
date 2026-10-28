<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Posting grid of approved drafts (Drafts::SLOT_TYPES): q15, q20 or rand. */
class AddXWatchSlotType extends Migration
{
    public function up()
    {
        $this->forge->addColumn('x_watch', [
            'slot_type' => ['type' => 'VARCHAR', 'constraint' => 8, 'default' => 'q15', 'after' => 'draft_queue_at'],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('x_watch', 'slot_type');
    }
}
