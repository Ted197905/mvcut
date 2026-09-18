<?php

namespace App\Commands;

use App\Libraries\JobRunner;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class WorkerRun extends BaseCommand
{
    protected $group       = 'mvcut';
    protected $name        = 'worker:run';
    protected $description = 'Process queued media jobs (ffmpeg). Use --once to process a single job and exit.';
    protected $options     = ['--once' => 'Process at most one job then exit', '--sleep' => 'Idle poll interval seconds (default 2)'];

    public function run(array $params)
    {
        $once   = array_key_exists('once', $params);
        $sleep  = (int) ($params['sleep'] ?? 2) ?: 2;
        $runner = new JobRunner();
        $log    = static fn (string $m) => CLI::write('[' . date('H:i:s') . '] ' . $m);
        $log('worker started (pid ' . getmypid() . ')');
        while (true) {
            $job = $runner->claim();
            if ($job) {
                $log("job {$job['id']} ({$job['type']}) media {$job['media_id']}");
                $runner->run($job, $log);
                if ($once) return;
                continue;
            }
            if ($once) { $log('no queued jobs'); return; }
            sleep($sleep);
        }
    }
}
