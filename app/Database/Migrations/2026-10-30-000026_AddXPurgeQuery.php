<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** 게시물 정리 runs walk an X search (from:handle until: since: filter:...) instead of the profile: the query used. */
class AddXPurgeQuery extends Migration
{
    public function up()
    {
        $this->forge->addColumn('x_purges', ['query' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true, 'after' => 'kinds']]);
    }

    public function down()
    {
        $this->forge->dropColumn('x_purges', 'query');
    }
}
