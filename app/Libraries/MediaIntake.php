<?php

namespace App\Libraries;

use App\Models\JobModel;
use App\Models\MediaModel;

/** Registers a finished upload/download as a media row (probe, thumbnail, filmstrip, proxy job). */
class MediaIntake
{
    public const ALLOWED_EXT = ['mp4', 'mov', 'm4v', 'mkv', 'webm', 'avi', 'wmv', 'flv', 'ts', 'mts', 'm2ts', '3gp',
        'gif', 'jpg', 'jpeg', 'png', 'webp', 'heic', 'mp3', 'm4a', 'wav', 'aac'];

    public static function extOf(string $name): string
    {
        return strtolower(pathinfo($name, PATHINFO_EXTENSION));
    }

    public static function allowed(string $ext): bool
    {
        return in_array($ext, self::ALLOWED_EXT, true);
    }

    /**
     * Creates the media row and its directory. Returns [id, dir, targetPath].
     * The caller must then place the file at targetPath and call finish().
     */
    public static function create(int $userId, string $clientName, string $ext, int $size, ?string $mime = null): array
    {
        $media = new MediaModel();
        $title = pathinfo($clientName, PATHINFO_FILENAME) ?: 'untitled';
        $id = $media->insert([
            'user_id'  => $userId,
            'kind'     => 'original',
            'source'   => 'upload',
            'title'    => mb_substr($title, 0, 255),
            'filename' => 'original.' . $ext,
            'mime'     => $mime,
            'size'     => $size,
            'status'   => 'processing',
        ]);
        $row = $media->find($id);
        $dir = MediaModel::dir($row);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true)) {
            $media->delete($id);
            throw new \RuntimeException('저장 디렉토리를 만들 수 없습니다.');
        }
        self::relax(dirname($dir));
        self::relax($dir);
        return [(int) $id, $dir, $dir . '/original.' . $ext];
    }

    /** Probes the placed file, generates thumbnail/filmstrip, queues a proxy job when needed. */
    public static function finish(int $id): array
    {
        $media = new MediaModel();
        $row   = $media->find($id);
        $dir   = MediaModel::dir($row);
        $path  = $dir . '/' . $row['filename'];
        if (! is_file($path)) {
            $media->delete($id);
            throw new \RuntimeException('업로드된 파일을 찾을 수 없습니다.');
        }
        $update = ['status' => 'ready', 'size' => filesize($path), 'mime' => mime_content_type($path) ?: $row['mime']];
        if ($meta = Ffmpeg::probe($path)) {
            $update += $meta;
            if (in_array($meta['media_type'], ['video', 'image'], true) && Ffmpeg::thumbnail($path, $dir . '/thumb.jpg', $meta['duration'])) {
                $update['has_thumb'] = 1;
            }
            if ($meta['media_type'] === 'video' && $meta['duration']) {
                Ffmpeg::filmstrip($path, $dir . '/strip2.jpg', (float) $meta['duration']);
            }
        } else {
            $m = (string) $update['mime'];
            $update['media_type'] = str_starts_with($m, 'video/') ? 'video'
                : (str_starts_with($m, 'image/') ? 'image'
                : (str_starts_with($m, 'audio/') ? 'audio' : 'unknown'));
        }
        $media->update($id, $update);
        self::relax($dir);
        $item = $media->find($id);
        if (MediaSupport::needsProxy($item)) {
            (new JobModel())->insert(['user_id' => $item['user_id'], 'media_id' => $id, 'type' => 'proxy', 'params' => '{}', 'status' => 'queued']);
        }
        return $item;
    }

    /**
     * Makes a path group-writable so both the web user and the deploy account can manage it.
     * php-fpm's umask (022) would otherwise leave 0755/0644 and lock the other account out.
     */
    public static function relax(string $path): void
    {
        if (is_dir($path)) {
            @chmod($path, 0775);
            foreach (glob($path . '/*') ?: [] as $f) {
                @chmod($f, is_dir($f) ? 0775 : 0664);
            }
        } elseif (is_file($path)) {
            @chmod($path, 0664);
        }
    }

    public static function removeDir(string $dir): void
    {
        if (! is_dir($dir)) return;
        foreach (glob($dir . '/*') ?: [] as $f) @unlink($f);
        @rmdir($dir);
    }
}
