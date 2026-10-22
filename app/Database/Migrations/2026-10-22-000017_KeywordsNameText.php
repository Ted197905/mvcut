<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Keywords may span several lines, so the name column grows from VARCHAR(255) to TEXT. */
class KeywordsNameText extends Migration
{
    public function up()
    {
        $this->forge->modifyColumn('keywords', ['name' => ['type' => 'TEXT']]);
    }

    public function down()
    {
        $this->forge->modifyColumn('keywords', ['name' => ['type' => 'VARCHAR', 'constraint' => 255]]);
    }
}
