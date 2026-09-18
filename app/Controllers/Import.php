<?php

namespace App\Controllers;

use App\Libraries\Ffmpeg;
use App\Libraries\MediaSupport;
use App\Models\JobModel;

class Import extends BaseController
{
    /** POST /api/import/inspect {url} -> list of downloadable entries */
    public function inspect()
    {
        $in  = $this->request->getJSON(true) ?: [];
        $url = trim((string) ($in['url'] ?? ''));
        $platform = MediaSupport::platformOf($url);
        if (! $platform) return $this->response->setStatusCode(422)->setJSON(['error' => 'Instagram, Facebook, X, Threads, YouTube 링크만 지원합니다.']);
        $bin = MediaSupport::ytdlp();
        if (! $bin) return $this->response->setStatusCode(500)->setJSON(['error' => '서버에 yt-dlp가 없습니다.']);

        $out = Ffmpeg::run([$bin, '-J', '--no-warnings', '--flat-playlist', '--no-playlist', '--socket-timeout', '20', $url], 90);
        if ($out['code'] !== 0 || trim($out['stdout']) === '') {
            $msg = trim(preg_replace('/^ERROR:\s*/m', '', $out['stderr'])) ?: '리소스를 찾을 수 없습니다.';
            return $this->response->setStatusCode(422)->setJSON(['error' => '가져올 수 없습니다: ' . mb_substr($msg, 0, 300)]);
        }
        $j = json_decode($out['stdout'], true);
        if (! is_array($j)) return $this->response->setStatusCode(500)->setJSON(['error' => '응답 해석 실패']);

        $entries = [];
        $list = ($j['_type'] ?? '') === 'playlist' ? ($j['entries'] ?? []) : [$j];
        foreach ($list as $i => $e) {
            if (! is_array($e)) continue;
            $isImage = empty($e['duration']) && empty($e['formats']) && ! empty($e['thumbnails']) && ($e['ext'] ?? '') !== 'mp4';
            $entries[] = [
                'index'     => $i + 1,
                'id'        => (string) ($e['id'] ?? $i),
                'title'     => mb_substr((string) ($e['title'] ?? $e['id'] ?? 'item ' . ($i + 1)), 0, 120),
                'duration'  => isset($e['duration']) ? (float) $e['duration'] : null,
                'thumbnail' => $e['thumbnail'] ?? ($e['thumbnails'][0]['url'] ?? null),
                'width'     => $e['width'] ?? null,
                'height'    => $e['height'] ?? null,
                'kind'      => $isImage ? 'image' : 'video',
                'url'       => $e['webpage_url'] ?? $e['url'] ?? $url,
            ];
        }
        return $this->response->setJSON(['ok' => true, 'platform' => $platform, 'title' => $j['title'] ?? null, 'entries' => $entries]);
    }

    /** POST /api/import {url, items:[index,...]} -> one import job per item */
    public function submit()
    {
        $in  = $this->request->getJSON(true) ?: [];
        $url = trim((string) ($in['url'] ?? ''));
        $platform = MediaSupport::platformOf($url);
        if (! $platform) return $this->response->setStatusCode(422)->setJSON(['error' => '허용되지 않은 링크입니다.']);
        $items = array_values(array_unique(array_map('intval', (array) ($in['items'] ?? [1]))));
        $items = array_filter($items, static fn ($i) => $i >= 1 && $i <= 100);
        if ($items === []) return $this->response->setStatusCode(422)->setJSON(['error' => '선택된 항목이 없습니다.']);
        $jobs = new JobModel(); $ids = [];
        foreach ($items as $idx) {
            $ids[] = $jobs->insert([
                'user_id' => (int) session()->get('user_id'),
                'type'    => 'import',
                'params'  => json_encode(['url' => $url, 'platform' => $platform, 'index' => $idx, 'title' => (string) ($in['titles'][$idx] ?? '')], JSON_UNESCAPED_UNICODE),
                'status'  => 'queued',
            ]);
        }
        return $this->response->setJSON(['ok' => true, 'job_ids' => $ids]);
    }
}
