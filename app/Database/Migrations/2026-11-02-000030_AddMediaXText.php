<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Library: the X post text the user edited for an item (Send to X uses it instead of title + description). */
class AddMediaXText extends Migration
{
    public function up()
    {
        $this->forge->addColumn('media', ['x_text' => ['type' => 'TEXT', 'null' => true, 'after' => 'description']]);
    }

    public function down()
    {
        $this->forge->dropColumn('media', 'x_text');
    }
}
