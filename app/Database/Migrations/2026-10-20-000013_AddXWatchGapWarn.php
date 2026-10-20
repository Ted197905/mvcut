<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Second gap threshold: below gap_warn a post is flagged red, below gap_min orange. */
class AddXWatchGapWarn extends Migration
{
    public function up()
    {
        $this->forge->addColumn('x_watch', [
            'gap_warn' => ['type' => 'INT', 'default' => 10, 'after' => 'gap_min'],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('x_watch', 'gap_warn');
    }
}
