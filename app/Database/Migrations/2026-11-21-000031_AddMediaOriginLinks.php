<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Library: how an item came in (manual = imported/uploaded by the user, auto = copied from the collector by
 * Libraries\AutoLib), and the links of deleted items, so a link collected before is noticed again.
 */
class AddMediaOriginLinks extends Migration
{
    public function up()
    {
        $this->forge->addColumn('media', ['origin' => ['type' => 'VARCHAR', 'constraint' => 8, 'default' => 'manual', 'after' => 'source']]);
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'user_id'    => ['type' => 'INT', 'unsigned' => true],
            'url_key'    => ['type' => 'VARCHAR', 'constraint' => 190],
            'source_url' => ['type' => 'VARCHAR', 'constraint' => 1000],
            'origin'     => ['type' => 'VARCHAR', 'constraint' => 8, 'default' => 'manual'],
            'deleted_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['user_id', 'url_key']);
        $this->forge->createTable('media_links');
    }

    public function down()
    {
        $this->forge->dropTable('media_links');
        $this->forge->dropColumn('media', 'origin');
    }
}
