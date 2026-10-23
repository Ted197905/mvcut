<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** A reference photo per keyword: the uploaded original plus a 500x500 crop the user picked. */
class AddKeywordImages extends Migration
{
    public function up()
    {
        $this->forge->addColumn('keywords', [
            'image'    => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true, 'after' => 'collapsed'],   // original file name
            'crop'     => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true, 'after' => 'image'],    // {"x","y","w","h"} in source pixels
            'image_at' => ['type' => 'DATETIME', 'null' => true, 'after' => 'crop'],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('keywords', ['image', 'crop', 'image_at']);
    }
}
