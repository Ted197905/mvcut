<?php

namespace App\Commands;

use App\Libraries\Cleanup;
use App\Libraries\JobRunner;
use App\Libraries\XWatch;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class WorkerRun extends BaseCommand
{
    protected $group       = 'mvcut';
    protected $name        = 'worker:run';
    protected $description = 'Process queued media jobs (ffmpeg). Use --once to process a single job and exit.';
    protected $options     = ['--once' => 'Process at most one job then exit', '--sleep' => 'Idle poll interval seconds (default 2)'];

    /** Seconds between two imports from the same platform. */
    private const IMPORT_GAP = 5;

    public function run(array $params)
    {
        $once   = array_key_exists('once', $params);
        $sleep  = (int) ($params['sleep'] ?? 2) ?: 2;
        $runner = new JobRunner();
        $log    = static fn (string $m) => CLI::write('[' . date('H:i:s') . '] ' . $m);
        $log('worker started (pid ' . getmypid() . ')');
        // only one worker runs: anything still 'running' was cut off when the previous one stopped
        $db = db_connect();
        $db->table('jobs')->whereIn('status', ['running', 'cancelled'])->update([
            'status' => 'failed', 'error' => '워커가 재시작되어 중단됨 (재시도 가능)', 'finished_at' => date('Y-m-d H:i:s')]);
        if ($db->affectedRows() > 0) $log('marked ' . $db->affectedRows() . ' interrupted job(s) as failed');
        $nextCleanup = time() + 300;
        $nextXWatch  = time() + 20;
        $nextDrafts  = time() + 30;
        $lastImport  = ['platform' => '', 'at' => 0];
        while (true) {
            $job = $runner->claim();
            if ($job) {
                $log("job {$job['id']} ({$job['type']}) media {$job['media_id']}");
                // consecutive downloads from the same site are spaced out a little (rate limits)
                if ($job['type'] === 'import') {
                    $platform = (string) (json_decode((string) $job['params'], true)['platform'] ?? '');
                    $wait = self::IMPORT_GAP - (time() - $lastImport['at']);
                    if ($platform !== '' && $platform === $lastImport['platform'] && $wait > 0) {
                        $log("waiting {$wait}s before the next {$platform} download");
                        sleep($wait);
                    }
                    $lastImport = ['platform' => $platform, 'at' => time()];
                }
                $runner->run($job, $log);
                if ($job['type'] === 'import') $lastImport['at'] = time();
                if ($once) return;
                continue;
            }
            if ($once) { $log('no queued jobs'); return; }
            // housekeeping runs here so the app needs no cron entry
            if (time() >= $nextCleanup) {
                $nextCleanup = time() + 6 * 3600;
                try {
                    // switched off in 설정 > 자동 기능: nothing removed
                    $r = \App\Libraries\Ops::on('mvcut_cleanup') ? Cleanup::run() : [];
                    if (array_sum($r) > 0) {
                        $log(sprintf('cleanup: stale %d, orphans %d, chunks %d, jobs %d', $r['stale'], $r['orphans'], $r['chunks'], $r['jobs']));
                    }
                } catch (\Throwable $e) {
                    $log('cleanup failed: ' . $e->getMessage());
                }
            }
            // X watch restriction checks (every 10 minutes per account, probe/yuzurisa/banchecker by slot; "check now" sets the due time to now)
            if (time() >= $nextXWatch) {
                $nextXWatch = time() + 15;
                try {
                    XWatch::runDue($log);
                } catch (\Throwable $e) {
                    $log('xwatch failed: ' . $e->getMessage());
                }
            }
            // a draft batch the collector finished writing -> Telegram
            if (time() >= $nextDrafts) {
                $nextDrafts = time() + 60;
                try {
                    \App\Libraries\Drafts::notifyReady($log);
                } catch (\Throwable $e) {
                    $log('drafts notify failed: ' . $e->getMessage());
                }
            }
            sleep($sleep);
        }
    }
}
