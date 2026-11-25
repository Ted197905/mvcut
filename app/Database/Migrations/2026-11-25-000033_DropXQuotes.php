<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** X 인용 대상 comes from the collector's probe-account search only (user, 2026-11-25): the extension's table goes. */
class DropXQuotes extends Migration
{
    public function up()
    {
        $this->forge->dropTable('x_quotes', true);
    }

    public function down()
    {
    }
}
