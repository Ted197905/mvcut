<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Per-user keyword tree (group -> members) for tagging X posts; collapsed state is kept here too. */
class CreateKeywords extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'user_id'    => ['type' => 'INT', 'unsigned' => true],
            'parent_id'  => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'name'       => ['type' => 'VARCHAR', 'constraint' => 255],
            'sort'       => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'collapsed'  => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['user_id', 'parent_id', 'sort']);
        $this->forge->createTable('keywords');
    }

    public function down()
    {
        $this->forge->dropTable('keywords');
    }
}
