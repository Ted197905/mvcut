<?php

namespace App\Commands;

use App\Libraries\Ffmpeg;
use App\Libraries\MediaSupport;
use App\Models\MediaModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/** Backfills description / uploader / stats for media imported before those fields existed. */
class ImportRefresh extends BaseCommand
{
    protected $group       = 'mvcut';
    protected $name        = 'import:refresh';
    protected $description = 'Re-read source metadata (description, channel, counts) for imported media.';
    protected $usage       = 'import:refresh [<media id>|all]';

    public function run(array $params)
    {
        $media = new MediaModel();
        $arg   = $params[0] ?? 'all';
        $rows  = $arg === 'all'
            ? $media->where('source_url IS NOT NULL')->where('description IS NULL')->findAll()
            : array_filter([$media->find((int) $arg)]);
        if (! $rows) { CLI::write('nothing to refresh'); return; }
        $bin = MediaSupport::ytdlp();
        if (! $bin) { CLI::error('yt-dlp not installed'); return; }

        foreach ($rows as $row) {
            if (empty($row['source_url'])) continue;
            $platform = MediaSupport::platformOf($row['source_url']);
            if (! $platform) { CLI::write("#{$row['id']} skipped (host not allowed)"); continue; }
            $out = Ffmpeg::run([$bin, '-J', '--no-warnings', '--no-playlist', '--skip-download', '--socket-timeout', '20', $row['source_url']], 90);
            $info = trim($out['stdout']) !== '' ? json_decode($out['stdout'], true) : null;
            if (! is_array($info)) { CLI::write("#{$row['id']} failed"); continue; }
            $stats = array_filter([
                'view_count'    => isset($info['view_count']) ? (int) $info['view_count'] : null,
                'like_count'    => isset($info['like_count']) ? (int) $info['like_count'] : null,
                'comment_count' => isset($info['comment_count']) ? (int) $info['comment_count'] : null,
                'upload_date'   => $info['upload_date'] ?? null,
            ], static fn ($v) => $v !== null);
            $media->update($row['id'], [
                'description' => isset($info['description']) ? mb_substr((string) $info['description'], 0, 20000) : null,
                'uploader'    => mb_substr((string) ($info['uploader'] ?? $info['channel'] ?? ''), 0, 190) ?: null,
                'stats'       => $stats ? json_encode($stats, JSON_UNESCAPED_UNICODE) : null,
            ]);
            CLI::write("#{$row['id']} updated (" . mb_strlen((string) ($info['description'] ?? '')) . ' chars)');
        }
    }
}
