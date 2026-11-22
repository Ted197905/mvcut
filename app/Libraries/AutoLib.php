<?php

namespace App\Libraries;

use App\Models\MediaModel;

/**
 * Single-video posts the collector took from the followed TikTok / Instagram / Facebook Reels accounts go into the
 * library as origin 'auto' (the worker runs this between jobs): the user adds a take of their own and posts them
 * by hand. A link already in the library, or one deleted from it before (media_links), is not copied again.
 * Everything else the collector finds stays with the drafts.
 */
class AutoLib
{
    public const SOURCES = ['tt_idols' => 'tiktok', 'ig_idols' => 'instagram', 'fb_reels' => 'facebook'];

    /** Copies up to $limit posts. Returns [copied, skipped as duplicates]. */
    public static function run(int $limit = 5): array
    {
        $db = db_connect();
        $u  = $db->table('x_watch')->select('user_id')->where('handle !=', '')->orderBy('id')->get(1)->getRowArray();
        if (! $u) return [0, 0];
        $userId = (int) $u['user_id'];
        $hw = env('hallyuwire.db', 'hallyuwire');
        // sound-only items copied before the probe check existed
        foreach ((new MediaModel())->where('user_id', $userId)->where('origin', 'auto')->where('status', 'ready')->where('media_type !=', 'video')->findAll() as $old) {
            MediaIntake::removeDir(MediaModel::dir($old));
            (new MediaModel())->delete($old['id']);
        }
        $rows = $db->query("SELECT i.id, i.source_id, i.url, i.title, i.body, i.author, i.views, i.likes, i.comments, i.posted_at,
                (SELECT COUNT(*) FROM {$hw}.media m WHERE m.item_id = i.id AND m.kind = 'video') AS vids,
                (SELECT COUNT(*) FROM {$hw}.media m WHERE m.item_id = i.id AND m.kind = 'image') AS imgs
              FROM {$hw}.items i
              WHERE i.source_id IN ('tt_idols', 'ig_idols', 'fb_reels') AND i.used_at IS NULL AND i.deleted_at IS NULL
                AND i.first_seen > UTC_TIMESTAMP() - INTERVAL 2 DAY
              HAVING vids = 1 AND imgs <= 1 ORDER BY i.id LIMIT 60")->getResultArray();
        if ($rows === []) return [0, 0];

        $have = [];   // link keys in the library now or deleted from it before
        foreach ($db->table('media')->select('source_url')->where('user_id', $userId)->where('source_url IS NOT NULL', null, false)
                     ->where('source_url !=', '')->get()->getResultArray() as $r) {
            $have[MediaSupport::urlKey((string) $r['source_url'])] = true;
        }
        foreach ($db->table('media_links')->select('url_key')->where('user_id', $userId)->get()->getResultArray() as $r) $have[$r['url_key']] = true;

        $root  = rtrim((string) env('hallyuwire.media', '/srv/hallyuwire/media'), '/');
        $model = new MediaModel();
        $used  = static fn (int $id) => $db->table($hw . '.items')->where('id', $id)->update(['used_at' => gmdate('Y-m-d H:i:s')]);
        $copied = $dups = 0;
        foreach ($rows as $it) {
            if ($copied >= $limit) break;
            $key = MediaSupport::urlKey((string) $it['url']);
            if (isset($have[$key])) { $used((int) $it['id']); $dups++; continue; }
            $m = $db->table($hw . '.media')->where('item_id', (int) $it['id'])->where('kind', 'video')->get()->getRowArray();
            if (! $m || $m['status'] !== 'done') continue;   // still downloading (or failed): looked at again next time
            $path = $root . '/' . $m['path'];
            $ext  = MediaIntake::extOf((string) $m['path']);
            if (! is_file($path) || ! MediaIntake::allowed($ext)) continue;
            // a TikTok photo slideshow comes down as sound only: not a video, left out
            if ((Ffmpeg::probe($path)['media_type'] ?? '') !== 'video') { $used((int) $it['id']); continue; }
            try {
                [$mid, , $target] = MediaIntake::create($userId, 'auto.' . $ext, $ext, (int) filesize($path), $m['mime']);
                if (! copy($path, $target)) { $model->delete($mid); continue; }
                $stats = array_filter(['view_count' => $it['views'], 'like_count' => $it['likes'], 'comment_count' => $it['comments'],
                                       'upload_date' => $it['posted_at'] ? date('Ymd', strtotime($it['posted_at'] . ' UTC')) : null],
                                      static fn ($v) => $v !== null && $v !== '');
                $handle = ltrim((string) $it['author'], '@');
                $model->update($mid, [
                    'source' => self::SOURCES[$it['source_id']], 'origin' => 'auto', 'source_url' => mb_substr((string) $it['url'], 0, 1000),
                    'title' => mb_substr((string) $it['title'], 0, 255), 'description' => mb_substr((string) ($it['body'] ?? ''), 0, 20000) ?: null,
                    'uploader' => $handle !== '' ? mb_substr($handle, 0, 190) : null,
                    'stats' => $stats ? json_encode($stats) : null,
                    'meta' => json_encode(['channel' => $handle, 'handle' => $handle], JSON_UNESCAPED_UNICODE),
                ]);
                MediaIntake::finish($mid);
                $used((int) $it['id']);
                $have[$key] = true;
                $copied++;
            } catch (\Throwable $e) {
                AppLog::warn('autolib', 'copy', 'item ' . $it['id'] . ': ' . $e->getMessage());
            }
        }
        if ($copied) AppLog::info('autolib', 'copy', $copied . ' video(s) to the library' . ($dups ? ', ' . $dups . ' duplicate(s) skipped' : ''));
        return [$copied, $dups];
    }
}
