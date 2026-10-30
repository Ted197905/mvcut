<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** 게시물 정리 form values kept per account (preset, dates, 끝까지, keyword, kind, edited query). */
class AddXWatchPurgePrefs extends Migration
{
    public function up()
    {
        $this->forge->addColumn('x_watch', ['purge_prefs' => ['type' => 'TEXT', 'null' => true, 'after' => 'slot_type']]);
    }

    public function down()
    {
        $this->forge->dropColumn('x_watch', 'purge_prefs');
    }
}
