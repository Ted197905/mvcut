<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Replies count toward the cadence limits only when the user opts in. */
class AddXWatchCountReplies extends Migration
{
    public function up()
    {
        $this->forge->addColumn('x_watch', [
            'count_replies' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0, 'after' => 'day_max'],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('x_watch', 'count_replies');
    }
}
