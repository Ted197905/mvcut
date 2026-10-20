<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Restriction verdicts from checkers still on trial (xautodm, taishin) are kept here instead of
 * acting on them, next to the latest verdict of the established checkers, for later analysis.
 */
class CreateXCheckDiffs extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'user_id'     => ['type' => 'INT', 'unsigned' => true],
            'handle'      => ['type' => 'VARCHAR', 'constraint' => 15],
            'source'      => ['type' => 'VARCHAR', 'constraint' => 16],
            'summary'     => ['type' => 'VARCHAR', 'constraint' => 200, 'default' => ''],
            'result'      => ['type' => 'TEXT', 'null' => true],
            'ref_check_id'=> ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'ref_source'  => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => ''],
            'ref_status'  => ['type' => 'VARCHAR', 'constraint' => 10, 'default' => ''],
            'ref_summary' => ['type' => 'VARCHAR', 'constraint' => 200, 'default' => ''],
            'checked_at'  => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['user_id', 'checked_at']);
        $this->forge->createTable('x_check_diffs');
    }

    public function down()
    {
        $this->forge->dropTable('x_check_diffs');
    }
}
