<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** The editor's last submitted state for a video, so "편집" opens where the last edit left off. */
class AddMediaLastEdit extends Migration
{
    public function up()
    {
        $this->forge->addColumn('media', ['last_edit' => ['type' => 'MEDIUMTEXT', 'null' => true, 'after' => 'edit_params']]);
    }

    public function down()
    {
        $this->forge->dropColumn('media', 'last_edit');
    }
}
