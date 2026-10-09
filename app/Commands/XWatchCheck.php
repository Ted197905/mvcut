<?php

namespace App\Commands;

use App\Libraries\XWatch;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class XWatchCheck extends BaseCommand
{
    protected $group       = 'mvcut';
    protected $name        = 'xwatch:check';
    protected $description = 'Run the X restriction check now for one user (default: every enabled watch).';
    protected $usage       = 'xwatch:check [user_id]';

    public function run(array $params)
    {
        $db = \Config\Database::connect();
        $q = $db->table('x_watch')->where('handle !=', '');
        if (isset($params[0])) $q->where('user_id', (int) $params[0]); else $q->where('enabled', 1);
        foreach ($q->get()->getResultArray() as $w) {
            CLI::write("@{$w['handle']} (user {$w['user_id']}) ...");
            $c = XWatch::check($w);
            CLI::write("  {$c['status']}: {$c['summary']}");
            CLI::write('  ' . $c['result']);
        }
    }
}
