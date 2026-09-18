<?php

namespace App\Commands;

use App\Models\JobModel;
use App\Models\MediaModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class MediaCleanup extends BaseCommand
{
    protected $group       = 'mvcut';
    protected $name        = 'media:cleanup';
    protected $description = 'Remove stale processing media (>24h), orphan media dirs, and old finished jobs (>30d).';

    public function run(array $params)
    {
        $media = new MediaModel();
        $jobs  = new JobModel();
        $n = 0;
        foreach ($media->where('status', 'processing')->where('created_at <', date('Y-m-d H:i:s', time() - 86400))->findAll() as $m) {
            $this->rmdir(MediaModel::dir($m));
            $media->delete($m['id']);
            $n++;
        }
        CLI::write("stale media removed: {$n}");

        $orphans = 0;
        foreach (glob(WRITEPATH . 'media/*/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $id = (int) basename($dir);
            if ($id > 0 && ! $media->find($id)) { $this->rmdir($dir); $orphans++; }
        }
        CLI::write("orphan dirs removed: {$orphans}");

        $old = $jobs->whereIn('status', ['done', 'failed'])->where('updated_at <', date('Y-m-d H:i:s', time() - 30 * 86400))->delete();
        CLI::write('old jobs deleted: ' . $jobs->db->affectedRows());
    }

    private function rmdir(string $dir): void
    {
        if (! is_dir($dir)) return;
        foreach (glob($dir . '/*') ?: [] as $f) @unlink($f);
        @rmdir($dir);
    }
}
