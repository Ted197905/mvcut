<?php

namespace App\Controllers;

use App\Libraries\MediaSupport;
use App\Libraries\XWatch;
use App\Models\JobModel;
use App\Models\MediaModel;

/**
 * Endpoints for the MV Cut X Chrome extension. Authenticated by the per-user token
 * (Authorization: Bearer mvx_...), not by the session, and exempt from CSRF.
 */
class XApi extends BaseController
{
    private function watch(): ?array
    {
        $h = (string) $this->request->getHeaderLine('Authorization');
        $bearer = preg_match('/^Bearer\s+(\S+)$/i', $h, $m) ? $m[1] : '';
        return XWatch::byToken($bearer);
    }

    private function deny()
    {
        return $this->response->setStatusCode(401)->setJSON(['error' => 'invalid token']);
    }

    /** GET /xapi/status */
    public function status()
    {
        $w = $this->watch();
        if (! $w) return $this->deny();
        return $this->response->setJSON(XWatch::status($w));
    }

    /** POST /xapi/posts  {"posts":[{id,handle,time,kind,text,reply_to}]} */
    public function posts()
    {
        $w = $this->watch();
        if (! $w) return $this->deny();
        $body = json_decode((string) $this->request->getBody(), true);
        $posts = is_array($body['posts'] ?? null) ? $body['posts'] : [];
        // first contact: adopt the logged-in X account the extension reports
        if ($w['handle'] === '' && ($viewer = XWatch::cleanHandle((string) ($body['viewer'] ?? ''))) !== '') {
            XWatch::update((int) $w['user_id'], ['handle' => $viewer, 'next_check_at' => null]);
            $w = XWatch::forUser((int) $w['user_id']);
        }
        $new = XWatch::savePosts($w, $posts);
        return $this->response->setJSON(['saved' => $new] + XWatch::status($w));
    }

    /**
     * POST /xapi/import  {"url": "...", "title": "..."} - the extension's SAVE button on a post page.
     * Queues the same import job the library's "영상 바로 다운" does; a link already in the
     * library is reported instead of imported twice.
     */
    public function import()
    {
        $w = $this->watch();
        if (! $w) return $this->deny();
        $body = json_decode((string) $this->request->getBody(), true) ?: [];
        $url  = trim((string) ($body['url'] ?? ''));
        $platform = MediaSupport::platformOf($url);
        if (! $platform) return $this->response->setStatusCode(422)->setJSON(['error' => '허용되지 않은 링크입니다.']);
        $userId = (int) $w['user_id'];
        $key = MediaSupport::urlKey($url);
        $rows = (new MediaModel())->select('id, title, source_url')->where('user_id', $userId)->where('kind', 'original')
            ->where('source_url IS NOT NULL', null, false)->where('source_url !=', '')->orderBy('id', 'DESC')->findAll(2000);
        foreach ($rows as $r) {
            if (MediaSupport::urlKey((string) $r['source_url']) === $key) {
                return $this->response->setJSON(['ok' => true, 'duplicate' => true, 'id' => (int) $r['id'], 'title' => $r['title']]);
            }
        }
        $base = ['url' => mb_substr($url, 0, 1000), 'platform' => $platform,
                 'title' => mb_substr(trim((string) ($body['title'] ?? '')), 0, 255),
                 'post_key' => bin2hex(random_bytes(8)), 'x_ready' => true];
        $desc = mb_substr(trim((string) ($body['text'] ?? '')), 0, 5000);
        $jobs = new JobModel(); $ids = []; $order = 0;
        // images the extension read off the page (X posts: pbs.twimg.com/media/...?name=orig)
        $images = array_values(array_filter((array) ($body['images'] ?? []),
            static fn ($u) => is_string($u) && MediaSupport::imageUrlAllowed($u)));
        foreach (array_slice($images, 0, 20) as $img) {
            $p = $base + ['index' => $order + 1, 'post_order' => $order++, 'image_url' => $img];
            if ($desc !== '') $p['description'] = $desc;
            $ids[] = (int) $jobs->insert(['user_id' => $userId, 'type' => 'import',
                                          'params' => json_encode($p, JSON_UNESCAPED_UNICODE), 'status' => 'queued']);
        }
        // the post's video, unless the extension said the post has none (images only)
        if ($images === [] || ! empty($body['video'])) {
            $p = $base + ['index' => 1, 'post_order' => $order++];
            $ids[] = (int) $jobs->insert(['user_id' => $userId, 'type' => 'import',
                                          'params' => json_encode($p, JSON_UNESCAPED_UNICODE), 'status' => 'queued']);
        }
        return $this->response->setJSON(['ok' => true, 'job' => $ids[0], 'jobs' => $ids]);
    }

    /** GET /xapi/jobs/{id} - progress of an import the extension queued. */
    public function job(int $id)
    {
        $w = $this->watch();
        if (! $w) return $this->deny();
        $job = (new JobModel())->where('id', $id)->where('user_id', (int) $w['user_id'])->first();
        if (! $job) return $this->response->setStatusCode(404)->setJSON(['error' => 'not found']);
        $out = ['status' => $job['status'], 'progress' => (int) $job['progress'], 'error' => $job['error']];
        if ($job['status'] === 'done' && $job['result_media_id']) {
            $m = (new MediaModel())->find($job['result_media_id']);
            if ($m) $out += ['id' => (int) $m['id'], 'title' => $m['title']];
        }
        return $this->response->setJSON($out);
    }
}
