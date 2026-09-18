<?php

namespace App\Commands;

use App\Libraries\Cleanup;
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
        $nextCleanup = time() + 300;
        while (true) {
            $job = $runner->claim();
            if ($job) {
                $log("job {$job['id']} ({$job['type']}) media {$job['media_id']}");
                $runner->run($job, $log);
                if ($once) return;
                continue;
            }
            if ($once) { $log('no queued jobs'); return; }
            // housekeeping runs here so the app needs no cron entry
            if (time() >= $nextCleanup) {
                $nextCleanup = time() + 6 * 3600;
                try {
                    $r = Cleanup::run();
                    if (array_sum($r) > 0) {
                        $log(sprintf('cleanup: stale %d, orphans %d, chunks %d, jobs %d', $r['stale'], $r['orphans'], $r['chunks'], $r['jobs']));
                    }
                } catch (\Throwable $e) {
                    $log('cleanup failed: ' . $e->getMessage());
                }
            }
            sleep($sleep);
        }
    }
}
