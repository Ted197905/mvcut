<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** 게시물 정리: the extension's step-by-step log of a run (menu items seen, results, scrolls, hops), for debugging. */
class AddXPurgeLog extends Migration
{
    public function up()
    {
        $this->forge->addColumn('x_purges', ['log' => ['type' => 'MEDIUMTEXT', 'null' => true, 'after' => 'items']]);
    }

    public function down()
    {
        $this->forge->dropColumn('x_purges', 'log');
    }
}
