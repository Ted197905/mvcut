<?php

namespace App\Controllers;

use App\Libraries\Cookies;
use App\Libraries\Ffmpeg;
use App\Libraries\MediaSupport;
use App\Models\JobModel;

class Import extends BaseController
{
    /** Platforms whose pages only exist after JavaScript runs. */
    private const RENDER_FIRST = ['instagram', 'threads'];

    /** POST /api/import/inspect {url} -> list of downloadable entries */
    public function inspect()
    {
        $in  = $this->request->getJSON(true) ?: [];
        $url = trim((string) ($in['url'] ?? ''));
        $platform = MediaSupport::platformOf($url);
        if (! $platform) return $this->response->setStatusCode(422)->setJSON(['error' => 'Instagram, Facebook, X, Threads, YouTube 링크만 지원합니다.']);
        // Instagram and Threads serve JavaScript-only pages that yt-dlp reads poorly or not at
        // all, so the headless renderer is the first attempt for them, not the fallback.
        $renderFirst = $this->renderFirst($platform, $url);
        if ($renderFirst) {
            $why = null;
            if ($r = $this->renderEntries($url, $why)) {
                return $this->response->setJSON($r + ['platform' => $platform]);
            }
            if ($why) return $this->response->setStatusCode(422)->setJSON(['error' => $this->cookieHint($platform, $why)]);
        }
        if ($reason = MediaSupport::unsupportedReason($platform)) {
            // the image scraper is still worth a try for platforms that expose og:image
            $images = MediaSupport::scrapeImages($url);
            if ($images !== []) {
                $e = $this->imageEntries($images);
                return $this->response->setJSON(['ok' => true, 'platform' => $platform, 'entries' => $e, 'notice' => $reason]);
            }
            return $this->response->setStatusCode(422)->setJSON(['error' => $reason]);
        }
        $bin = MediaSupport::ytdlp();
        if (! $bin) return $this->response->setStatusCode(500)->setJSON(['error' => '서버에 yt-dlp가 없습니다.']);

        $probe = [$bin, '-J', '--no-warnings', '--flat-playlist', '--no-playlist', '--socket-timeout', '20'];
        if ($c = MediaSupport::cookieFile($platform)) array_push($probe, '--cookies', $c);
        $probe[] = $url;
        $out = Ffmpeg::run($probe, 90);
        $j = trim($out['stdout']) !== '' ? json_decode($out['stdout'], true) : null;
        if ($out['code'] !== 0 || ! is_array($j)) {
            // no video: try the renderer (unless it already ran), then og:image / twitter:image
            $why = null;
            if (! $renderFirst && ($r = $this->renderEntries($url, $why))) {
                return $this->response->setJSON($r + ['platform' => $platform]);
            }
            if ($why) return $this->response->setStatusCode(422)->setJSON(['error' => $this->cookieHint($platform, $why)]);
            $images = MediaSupport::scrapeImages($url);
            if ($images !== []) {
                $e = $this->imageEntries($images);
                return $this->response->setJSON(['ok' => true, 'platform' => $platform, 'entries' => $e]);
            }
            $msg = trim(preg_replace('/^ERROR:\s*/m', '', $out['stderr'])) ?: '리소스를 찾을 수 없습니다.';
            if (stripos($msg, 'empty media response') !== false || stripos($msg, 'login') !== false || stripos($msg, 'cookies') !== false) {
                $msg = '이 게시물은 로그인해야 볼 수 있어 가져올 수 없습니다. 비공개 계정이거나 플랫폼이 비로그인 접근을 막은 경우입니다.';
            } elseif (stripos($msg, 'No video formats found') !== false) {
                $msg = '영상이 없는 게시물입니다. 이미지만 있는 글은 로그인 쿠키를 등록해야 가져올 수 있습니다.';
            } elseif (stripos($msg, 'Unsupported URL') !== false) {
                $msg = '지원하지 않는 주소입니다. 게시물(영상) 링크가 맞는지 확인해 주세요.';
            } else {
                $msg = mb_substr($msg, 0, 240);
            }
            return $this->response->setStatusCode(422)->setJSON(['error' => $this->cookieHint($platform, '가져올 수 없습니다. ' . $msg)]);
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

    /**
     * Facebook posts (not reels or videos) go to the renderer first: yt-dlp answers a post link
     * with whichever video it finds on the page, often not the post's own.
     */
    private function renderFirst(string $platform, string $url): bool
    {
        if (in_array($platform, self::RENDER_FIRST, true)) return true;
        return $platform === 'facebook' && preg_match('#/share/p/|/posts/|/permalink\.php|story_fbid=#', $url) === 1;
    }

    /**
     * Headless-browser fallback: render the page and turn its media into import entries.
     * Returns null when the renderer is unavailable or found nothing.
     */
    private function renderEntries(string $url, ?string &$why = null): ?array
    {
        $r = MediaSupport::render($url);
        if (! $r) return null;
        $platform = (string) MediaSupport::platformOf($url);
        // a login or age gate carries its own images (tracking pixels), so check it before the media
        if ($wall = $this->loginWall($platform, (string) $r['text'])) {
            $why = $wall;
            return null;
        }
        if ($r['videos'] === [] && $r['images'] === []) {
            $t = $r['text'];
            if ($r['redirected']) {
                $why = '작성자가 공개 대상을 제한한 게시물이라 열 수 없습니다. 로그인 쿠키를 넣어도 공개 대상에 포함된 계정이어야 보입니다.';
            } elseif (str_contains($t, '일부 사용자만') || str_contains($t, 'Limited') || str_contains($t, '볼 수 없습니다')) {
                $why = '작성자가 공개 대상을 제한한 게시물이라 가져올 수 없습니다. 게시물 공개 범위를 전체 공개로 바꾸거나, 영상을 직접 저장한 뒤 업로드해 주세요.';
            } elseif (str_contains($t, '로그인') && mb_strlen($t) < 400) {
                $why = '로그인해야 볼 수 있는 게시물이라 가져올 수 없습니다.';
            }
            return null;
        }
        // the rendered page title is the site chrome ("Threads"), so build one from the post itself
        $post   = $r['post'] ?? null;
        $handle = preg_match('#/@([A-Za-z0-9._]+)#', $url, $m) ? $m[1] : (string) ($post['author'] ?? '');
        $body   = trim((string) ($post['body'] ?? '')) !== '' ? (string) $post['body'] : $this->postBody($r, $handle);
        $lead = $body !== '' ? mb_substr(trim(explode("\n", $body)[0]), 0, 60) : '';
        $title = MediaSupport::tidyTitle(
            trim(($handle !== '' ? '@' . $handle : '') . ($lead !== '' ? ' ' . $lead : '')),
            '가져온 게시물'
        );
        // Instagram serves reels as fragmented streams that a plain download cannot reassemble,
        // so let yt-dlp fetch the video from the post URL whenever it can read this platform.
        // Facebook gets whole files from the renderer, and yt-dlp can pick another video of a shared post.
        $useYtdlp = $platform === 'instagram' && MediaSupport::ytdlp() && MediaSupport::cookieFile($platform);

        $entries = []; $i = 0;
        foreach (array_slice($r['videos'], 0, 10) as $u) {
            $i++;
            $entry = [
                'index' => $i, 'id' => (string) $i, 'title' => $title . ($i > 1 ? ' ' . $i : ''),
                'duration' => null, 'thumbnail' => $r['images'][0] ?? null, 'width' => null, 'height' => null,
                'kind' => 'video', 'url' => $url,
            ];
            if (! $useYtdlp) {
                $entry['media_url'] = $u;
                if (! empty($r['audio'][$u])) $entry['audio_url'] = $r['audio'][$u];
            }
            $entries[] = $entry;
        }
        // a reel's images are just cover frames of the video already listed above
        $coverOnly = $entries !== [] && preg_match('#/(reel|reels)/#', $url) === 1;
        $imgs  = $coverOnly ? [] : array_slice($r['images'], 0, 20);
        $sizes = MediaSupport::imageSizes($imgs);   // so the list can show 1080 x 1350
        foreach ($imgs as $k => $u) {
            if (isset($sizes[$k]) && min($sizes[$k]['w'], $sizes[$k]['h']) < 100) unset($imgs[$k]);   // pixels, icons
        }
        $n = 0;
        foreach ($imgs as $k => $u) {
            $i++; $n++;
            $imgTitle = $title !== '가져온 게시물' ? $title . ' (' . $n . ')' : '이미지 ' . $n;
            $entries[] = [
                'index' => $i, 'id' => (string) $i, 'title' => $imgTitle,
                'duration' => null, 'thumbnail' => $u,
                'width' => $sizes[$k]['w'] ?? null, 'height' => $sizes[$k]['h'] ?? null,
                'kind' => 'image', 'url' => $u, 'image_url' => $u,
            ];
        }
        if ($entries === []) return null;
        Cookies::clearFailure($platform);
        $notice = MediaSupport::cookieFile($platform)
            ? '로그인 세션으로 읽은 화면에서 찾은 미디어입니다. 원본보다 화질이 낮을 수 있습니다.'
            : '로그인 없이 읽을 수 있는 화면에서 찾은 미디어입니다. 원본보다 화질이 낮을 수 있습니다.';
        return ['ok' => true, 'entries' => $entries, 'title' => $title,
                'desc' => mb_substr($body, 0, 5000), 'notice' => $notice,
                'post' => ['uploader' => $handle, 'stats' => $this->postStats($post)]];
    }

    /** The page is a login or age gate instead of the post. Returns the user-facing reason. */
    private function loginWall(string $platform, string $text): ?string
    {
        // Instagram and Threads are handled by the empty-media checks. Public Facebook posts also
        // show a login form and a "Facebook에서 더 많은 콘텐츠 보기" popup, so match the gate's own wording only
        if ($platform !== 'facebook') return null;
        $gate = false;
        foreach (['Log in to view', 'You must log in', '로그인해야'] as $p) {
            if (stripos($text, $p) !== false) { $gate = true; break; }
        }
        if (! $gate) return null;
        $msg = str_contains($text, '18+')
            ? '연령 제한(18+) 게시물이라 로그인해야 볼 수 있습니다.'
            : '로그인해야 볼 수 있는 게시물이라 가져올 수 없습니다.';
        if (Cookies::known($platform) && ! MediaSupport::cookieFile($platform)) {
            $msg .= ' 설정 화면에서 ' . Cookies::PLATFORMS[$platform]['label'] . ' 로그인 쿠키를 등록하면 가져올 수 있습니다.';
        }
        return $msg;
    }

    /**
     * Records the failure against the platform's cookies and appends the renewal hint,
     * so the settings page and this message both say the same thing.
     */
    private function cookieHint(string $platform, string $message): string
    {
        // an audience-restricted post is the author's setting, not a stale session
        if (str_contains($message, '공개 대상')) return $message;
        $login = str_contains($message, '로그인') || str_contains($message, 'cookies') || str_contains($message, 'empty media response');
        if (! $login || ! Cookies::known($platform)) return $message;
        if (! Cookies::path($platform) || ! is_file((string) Cookies::path($platform))) return $message;
        Cookies::markFailure($platform, $message);
        return $message . ' 등록된 쿠키가 만료되었을 수 있습니다. 설정 화면에서 쿠키를 다시 등록해 주세요.';
    }

    /** Threads prints like / reply / repost / share counts under a post, in that order. */
    private function postStats(?array $post): array
    {
        $keys = ['like_count', 'comment_count', 'repost_count', 'share_count'];
        $out  = [];
        foreach (array_values((array) ($post['counts'] ?? [])) as $i => $v) {
            if (! isset($keys[$i])) break;
            $n = MediaSupport::parseCountKo((string) $v);
            if ($n !== null) $out[$keys[$i]] = $n;
        }
        return $out;
    }

    /** Drops the author handle and the relative timestamp the feed renders above the post text. */
    private function postBody(array $r, string $handle): string
    {
        $text = $r['description'] !== '' ? $r['description'] : $r['text'];
        $lines = explode("\n", trim($text));
        while ($lines !== []) {
            $l = trim($lines[0]);
            if ($l === '' || ($handle !== '' && $l === $handle) || preg_match('/^\d+\s*(초|분|시간|일|주|개월|년)$/u', $l)) {
                array_shift($lines);
                continue;
            }
            break;
        }
        return trim(implode("\n", $lines));
    }

    /** @param string[] $urls */
    private function imageEntries(array $urls): array
    {
        $entries = [];
        $urls    = array_values($urls);
        $sizes   = MediaSupport::imageSizes($urls);
        foreach ($urls as $i => $u) {
            $entries[] = [
                'index' => $i + 1, 'id' => (string) ($i + 1),
                'title' => '이미지 ' . ($i + 1), 'duration' => null,
                'thumbnail' => $u,
                'width' => $sizes[$i]['w'] ?? null, 'height' => $sizes[$i]['h'] ?? null,
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
        $media  = (array) ($in['media'] ?? []);
        $audio  = (array) ($in['audio'] ?? []);
        $desc   = mb_substr(trim((string) ($in['desc'] ?? '')), 0, 5000);
        $post   = is_array($in['post'] ?? null) ? $in['post'] : [];
        $uploader = mb_substr(trim((string) ($post['uploader'] ?? '')), 0, 190);
        $stats  = [];
        foreach (['like_count', 'comment_count', 'repost_count', 'share_count', 'view_count'] as $k) {
            $v = $post['stats'][$k] ?? null;
            if (is_numeric($v) && $v >= 0 && $v < 1e12) $stats[$k] = (int) $v;
        }
        $jobs = new JobModel(); $ids = [];
        $postKey = bin2hex(random_bytes(8));   // everything from this link is one post
        foreach ($items as $pos => $idx) {
            $params = ['url' => $url, 'platform' => $platform, 'index' => $idx, 'title' => (string) ($in['titles'][$idx] ?? ''),
                       'post_key' => $postKey, 'post_order' => $pos];
            $img = (string) ($images[$idx] ?? '');
            $vid = (string) ($media[$idx] ?? '');
            if ($img !== '') {
                if (! MediaSupport::imageUrlAllowed($img)) continue;
                $params['image_url'] = $img;
                if ($desc !== '') $params['description'] = $desc;
                if ($uploader !== '') $params['uploader'] = $uploader;
                if ($stats !== []) $params['stats'] = $stats;
            } elseif ($vid !== '') {
                if (! MediaSupport::imageUrlAllowed($vid)) continue;
                $params['video_url'] = $vid;
                $aud = (string) ($audio[$idx] ?? '');
                if ($aud !== '' && MediaSupport::imageUrlAllowed($aud)) $params['audio_url'] = $aud;
                if ($desc !== '') $params['description'] = $desc;
                if ($uploader !== '') $params['uploader'] = $uploader;
                if ($stats !== []) $params['stats'] = $stats;
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
