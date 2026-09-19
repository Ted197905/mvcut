<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddUserRoles extends Migration
{
    public function up()
    {
        $this->forge->addColumn('users', [
            // new sign-ups wait for an admin, and AI runs only once an admin turns it on
            'role'       => ['type' => 'VARCHAR', 'constraint' => 10, 'default' => 'user', 'after' => 'display_name'],
            'status'     => ['type' => 'VARCHAR', 'constraint' => 10, 'default' => 'pending', 'after' => 'role'],
            'ai_enabled' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0, 'after' => 'status'],
        ]);
        // accounts that existed before approval was introduced keep working
        $this->db->query("UPDATE users SET status = 'active', ai_enabled = 1");
        $this->db->query("UPDATE users SET role = 'admin' WHERE id = (SELECT * FROM (SELECT MIN(id) FROM users) t)");
    }

    public function down()
    {
        $this->forge->dropColumn('users', ['role', 'status', 'ai_enabled']);
    }
}
