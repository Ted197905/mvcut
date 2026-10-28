<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * When the probe first said "clear" in restricted mode. While set, the other checkers run one
 * a minute; "[제한 풀림]" goes out after the last one, with this as the release time.
 */
class AddXWatchReleaseAt extends Migration
{
    public function up()
    {
        $this->forge->addColumn('x_watch', [
            'release_at' => ['type' => 'DATETIME', 'null' => true, 'after' => 'restricted_at'],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('x_watch', 'release_at');
    }
}
