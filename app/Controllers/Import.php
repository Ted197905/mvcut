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
        if ($reason = MediaSupport::unsupportedReason($platform)) {
            // the image scraper is still worth a try for platforms that expose og:image
            $images = MediaSupport::scrapeImages($url);
            if ($images !== []) {
                return $this->response->setJSON(['ok' => true, 'platform' => $platform, 'entries' => $this->imageEntries($images), 'notice' => $reason]);
            }
            return $this->response->setStatusCode(422)->setJSON(['error' => $reason]);
        }
        $bin = MediaSupport::ytdlp();
        if (! $bin) return $this->response->setStatusCode(500)->setJSON(['error' => '서버에 yt-dlp가 없습니다.']);

        $out = Ffmpeg::run([$bin, '-J', '--no-warnings', '--flat-playlist', '--no-playlist', '--socket-timeout', '20', $url], 90);
        $j = trim($out['stdout']) !== '' ? json_decode($out['stdout'], true) : null;
        if ($out['code'] !== 0 || ! is_array($j)) {
            // no video: the post may be images only -> read og:image / twitter:image
            $images = MediaSupport::scrapeImages($url);
            if ($images !== []) {
                return $this->response->setJSON(['ok' => true, 'platform' => $platform, 'entries' => $this->imageEntries($images)]);
            }
            $msg = trim(preg_replace('/^ERROR:\s*/m', '', $out['stderr'])) ?: '리소스를 찾을 수 없습니다.';
            if (stripos($msg, 'empty media response') !== false || stripos($msg, 'login') !== false || stripos($msg, 'cookies') !== false) {
                $msg = '이 게시물은 로그인해야 볼 수 있어 가져올 수 없습니다. 비공개 계정이거나 플랫폼이 비로그인 접근을 막은 경우입니다.';
            } elseif (stripos($msg, 'Unsupported URL') !== false) {
                $msg = '지원하지 않는 주소입니다. 게시물(영상) 링크가 맞는지 확인해 주세요.';
            } else {
                $msg = mb_substr($msg, 0, 240);
            }
            return $this->response->setStatusCode(422)->setJSON(['error' => '가져올 수 없습니다. ' . $msg]);
        }

        $entries = [];
        $list = ($j['_type'] ?? '') === 'playlist' ? ($j['entries'] ?? []) : [$j];
        foreach ($list as $i => $e) {
            if (! is_array($e)) continue;
            $isImage = empty($e['duration']) && empty($e['formats']) && ! empty($e['thumbnails']) && ($e['ext'] ?? '') !== 'mp4';
            $entries[] = [
                'index'     => $i + 1,
                'id'        => (string) ($e['id'] ?? $i),
                'title'     => MediaSupport::tidyTitle((string) ($e['title'] ?? ''), (string) ($e['id'] ?? 'item ' . ($i + 1))),
                'duration'  => isset($e['duration']) ? (float) $e['duration'] : null,
                'thumbnail' => $e['thumbnail'] ?? ($e['thumbnails'][0]['url'] ?? null),
                'width'     => $e['width'] ?? null,
                'height'    => $e['height'] ?? null,
                'kind'      => $isImage ? 'image' : 'video',
                'url'       => $e['webpage_url'] ?? $e['url'] ?? $url,
            ];
        }
        if ($entries === []) {
            $images = MediaSupport::scrapeImages($url);
            if ($images !== []) $entries = $this->imageEntries($images);
        }
        return $this->response->setJSON(['ok' => true, 'platform' => $platform, 'title' => $j['title'] ?? null, 'entries' => $entries]);
    }

    /** @param string[] $urls */
    private function imageEntries(array $urls): array
    {
        $entries = [];
        foreach (array_values($urls) as $i => $u) {
            $entries[] = [
                'index' => $i + 1, 'id' => (string) ($i + 1),
                'title' => '이미지 ' . ($i + 1), 'duration' => null,
                'thumbnail' => $u, 'width' => null, 'height' => null,
                'kind' => 'image', 'url' => $u, 'image_url' => $u,
            ];
        }
        return $entries;
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
        $images = (array) ($in['images'] ?? []);
        $jobs = new JobModel(); $ids = [];
        foreach ($items as $idx) {
            $params = ['url' => $url, 'platform' => $platform, 'index' => $idx, 'title' => (string) ($in['titles'][$idx] ?? '')];
            $img = (string) ($images[$idx] ?? '');
            if ($img !== '') {
                if (! MediaSupport::imageUrlAllowed($img)) continue;
                $params['image_url'] = $img;
            }
            $ids[] = $jobs->insert([
                'user_id' => (int) session()->get('user_id'),
                'type'    => 'import',
                'params'  => json_encode($params, JSON_UNESCAPED_UNICODE),
                'status'  => 'queued',
            ]);
        }
        if ($ids === []) return $this->response->setStatusCode(422)->setJSON(['error' => '가져올 수 있는 항목이 없습니다.']);
        return $this->response->setJSON(['ok' => true, 'job_ids' => $ids]);
    }
}
