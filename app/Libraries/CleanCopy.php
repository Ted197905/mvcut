<?php

namespace App\Libraries;

use App\Models\MediaModel;

/**
 * Video handed out by download / Send to X: all metadata stripped, and for MP4/MOV the
 * camera metadata from secrets/camera_template.json (made by `bin/camera_meta.py extract`).
 * Cached next to the original as clean.<ext>, rebuilt when the source or template changes.
 */
class CleanCopy
{
    public const TEMPLATE = 'secrets/camera_template.json';

    /** File name inside the media dir, or null when it could not be made. */
    public static function file(array $item): ?string
    {
        if ($item['media_type'] !== 'video') return $item['filename'];
        $dir = MediaModel::dir($item);
        $src = $dir . '/' . $item['filename'];
        $out = 'clean.' . strtolower(pathinfo($item['filename'], PATHINFO_EXTENSION));
        $dst = $dir . '/' . $out;
        $tpl = ROOTPATH . self::TEMPLATE;

        clearstatcache();
        if (is_file($dst) && filemtime($dst) >= filemtime($src) && (! is_file($tpl) || filemtime($dst) >= filemtime($tpl))) {
            return $out;
        }
        $r = Ffmpeg::run(['/usr/bin/python3', ROOTPATH . 'bin/camera_meta.py', 'apply', $src, $dst, is_file($tpl) ? $tpl : '-'], 600);
        if ($r['code'] !== 0 || ! is_file($dst)) {
            log_message('error', 'clean copy failed for media {id}: {err}', ['id' => $item['id'], 'err' => trim($r['stderr'])]);
            return null;
        }
        MediaIntake::relax($dst);
        return $out;
    }
}
