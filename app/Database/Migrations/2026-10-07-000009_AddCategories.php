<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddCategories extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'name'       => ['type' => 'VARCHAR', 'constraint' => 32],
            'sort'       => ['type' => 'INT', 'default' => 0],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('name');
        $this->forge->createTable('categories');

        $now = date('Y-m-d H:i:s');
        foreach (['IDOL', 'FOOD', 'ISSUE'] as $i => $name) {
            $this->db->table('categories')->insert(['name' => $name, 'sort' => $i + 1, 'created_at' => $now, 'updated_at' => $now]);
        }

        // media.category holds the category name; '' = not set
        $this->forge->addColumn('media', [
            'category' => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false, 'default' => '', 'after' => 'kind'],
        ]);
        $this->db->query('CREATE INDEX media_user_category ON media (user_id, category)');
    }

    public function down()
    {
        $this->db->query('DROP INDEX media_user_category ON media');
        $this->forge->dropColumn('media', 'category');
        $this->forge->dropTable('categories');
    }
}
