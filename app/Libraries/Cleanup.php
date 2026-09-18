<?php

namespace App\Libraries;

use App\Models\JobModel;
use App\Models\MediaModel;

/** Housekeeping: stale uploads, orphan directories, old job rows. Run from the worker loop or spark. */
class Cleanup
{
    public static function run(): array
    {
        $media = new MediaModel();
        $jobs  = new JobModel();
        $out   = ['stale' => 0, 'orphans' => 0, 'chunks' => 0, 'jobs' => 0];

        foreach ($media->where('status', 'processing')->where('created_at <', date('Y-m-d H:i:s', time() - 86400))->findAll() as $m) {
            MediaIntake::removeDir(MediaModel::dir($m));
            $media->delete($m['id']);
            $out['stale']++;
        }

        foreach (glob(WRITEPATH . 'media/*/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $id = (int) basename($dir);
            if ($id > 0 && ! $media->find($id)) { MediaIntake::removeDir($dir); $out['orphans']++; }
        }

        foreach (glob(WRITEPATH . 'chunks/*/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (filemtime($dir) < time() - 86400) { MediaIntake::removeDir($dir); $out['chunks']++; }
        }

        $jobs->whereIn('status', ['done', 'failed'])->where('updated_at <', date('Y-m-d H:i:s', time() - 30 * 86400))->delete();
        $out['jobs'] = $jobs->db->affectedRows();

        return $out;
    }
}
