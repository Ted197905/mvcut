<?php

namespace App\Commands;

use App\Libraries\Cleanup;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class MediaCleanup extends BaseCommand
{
    protected $group       = 'mvcut';
    protected $name        = 'media:cleanup';
    protected $description = 'Remove stale processing media, orphan directories, abandoned upload chunks and old job rows.';

    public function run(array $params)
    {
        $r = Cleanup::run();
        CLI::write(sprintf('stale media %d, orphan dirs %d, chunk dirs %d, old jobs %d', $r['stale'], $r['orphans'], $r['chunks'], $r['jobs']));
    }
}
