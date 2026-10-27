<?php

namespace App\Controllers;

use App\Libraries\MediaIntake;
use App\Models\JobModel;
use App\Models\MediaModel;
use CodeIgniter\Exceptions\PageNotFoundException;

/**
 * /collect - admin only. Browses what the hallyuwire collector (separate service and DB) gathered
 * and copies chosen files into the MVCut library. The app DB user only has SELECT on that DB.
 */
class Collect extends BaseController
{
    private const PER_PAGE  = 30;
    private const SORTS     = ['new' => 'i.id', 'views' => 'i.views', 'comments' => 'i.comments', 'likes' => 'i.likes'];
    public const COUNTRIES  = ['KR' => '한국', 'JP' => '일본', 'GLOBAL' => '글로벌', 'TH' => '태국', 'TW' => '대만', 'CN' => '중국'];

    private function t(string $table): string
    {
        return env('hallyuwire.db', 'hallyuwire') . '.' . $table;
    }

    private function mediaDir(): string
    {
        return rtrim((string) env('hallyuwire.media', '/srv/hallyuwire/media'), '/');
    }

    /** UTC "Y-m-d H:i:s" from the collector -> short KST label ("5분 전", "10/26 23:41"). */
    public static function when(?string $utc): string
    {
        if (! $utc) return '';
        $t = new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
        $s = time() - $t->getTimestamp();
        if ($s < 60) return '방금';
        if ($s < 3600) return intdiv($s, 60) . '분 전';
        if ($s < 86400) return intdiv($s, 3600) . '시간 전';
        $k = $t->setTimezone(new \DateTimeZone('Asia/Seoul'));
        return $k->format($k->format('Y') === date('Y') ? 'n/j H:i' : 'Y/n/j H:i');
    }

    public function index()
    {
        $db  = db_connect();
        $get = fn (string $k) => trim((string) $this->request->getGet($k));
        $f = ['src' => $get('src'), 'cc' => $get('cc'), 'q' => $get('q'), 'sort' => $get('sort'),
              'all' => $get('all') === '1', 'media' => $get('media') === '1'];
        if (! isset(self::SORTS[$f['sort']])) $f['sort'] = 'new';

        $b = $db->table($this->t('items') . ' i')
            ->select('i.id, i.source_id, i.title, i.url, i.posted_at, i.first_seen, i.views, i.comments, i.likes, i.rank_pos, i.heat, i.thumb_url, i.country, i.event, i.entities, i.breaking');
        if (! $f['all']) $b->where('i.relevant', 1);
        if ($f['src'] !== '') $b->where('i.source_id', $f['src']);
        if (isset(self::COUNTRIES[$f['cc']])) $b->where('i.country', $f['cc']);
        if ($f['q'] !== '') $b->groupStart()->like('i.title', $f['q'])->orLike('i.entities', $f['q'])->groupEnd();
        if ($f['media']) {
            $b->where("EXISTS (SELECT 1 FROM {$this->t('media')} m WHERE m.item_id = i.id AND m.status IN ('done','embed'))", null, false);
        }
        $matched = (clone $b)->countAllResults();
        $pages   = max(1, (int) ceil($matched / self::PER_PAGE));
        $page    = min(max(1, (int) $this->request->getGet('page')), $pages);
        $items   = $b->orderBy(self::SORTS[$f['sort']], 'DESC')->orderBy('i.id', 'DESC')
                     ->get(self::PER_PAGE, ($page - 1) * self::PER_PAGE)->getResultArray();

        $media = [];
        if ($items !== []) {
            $ids = implode(',', array_map('intval', array_column($items, 'id')));
            foreach ($db->query("SELECT item_id,
                    SUM(status = 'done' AND kind = 'image') AS imgs, SUM(status = 'done' AND kind = 'video') AS vids,
                    SUM(status = 'embed') AS embeds, SUM(status = 'pending') AS pending,
                    MIN(CASE WHEN status = 'done' AND kind = 'image' THEN id END) AS first_img
                FROM {$this->t('media')} WHERE item_id IN ($ids) GROUP BY item_id")->getResultArray() as $r) {
                $media[$r['item_id']] = $r;
            }
        }

        $sources = $db->query("SELECT * FROM {$this->t('sources')} ORDER BY FIELD(country, 'KR','JP','GLOBAL','TH','TW','CN'), id")->getResultArray();
        $counts = [];
        foreach ($db->query("SELECT source_id, COUNT(*) AS n, SUM(relevant) AS rel,
                SUM(first_seen > UTC_TIMESTAMP() - INTERVAL 1 DAY) AS d1 FROM {$this->t('items')} GROUP BY source_id")->getResultArray() as $r) {
            $counts[$r['source_id']] = $r;
        }
        foreach ($db->query("SELECT i.source_id, SUM(m.status = 'pending') AS pending, SUM(IF(m.status = 'done', m.bytes, 0)) AS bytes
                FROM {$this->t('media')} m JOIN {$this->t('items')} i ON i.id = m.item_id GROUP BY i.source_id")->getResultArray() as $r) {
            $counts[$r['source_id']] = ($counts[$r['source_id']] ?? []) + $r;
        }

        return view('collect/index', [
            'title' => '수집', 'items' => $items, 'media' => $media, 'f' => $f, 'page' => $page, 'pages' => $pages,
            'matched' => $matched, 'sources' => $sources, 'counts' => $counts, 'names' => array_column($sources, 'name', 'id'),
        ]);
    }

    private function item(int $id): array
    {
        $item = db_connect()->table($this->t('items'))->where('id', $id)->get()->getRowArray();
        if (! $item) throw PageNotFoundException::forPageNotFound();
        return $item;
    }

    public function show(int $id)
    {
        $db   = db_connect();
        $item = $this->item($id);
        $src  = $db->table($this->t('sources'))->where('id', $item['source_id'])->get()->getRowArray() ?: ['name' => $item['source_id']];
        $media = $db->table($this->t('media'))->where('item_id', $id)->orderBy('id')->get()->getResultArray();
        $inLib = (new MediaModel())->where('user_id', (int) session()->get('user_id'))->where('source_url', $item['url'])->countAllResults();
        return view('collect/show', ['title' => $item['title'], 'item' => $item, 'src' => $src, 'media' => $media, 'inLib' => $inLib]);
    }

    /** GET /collect/media/{id} - a stored file, streamed by nginx (location /internal-hw/ -> media dir). */
    public function file(int $id)
    {
        $m = db_connect()->table($this->t('media'))->where('id', $id)->where('status', 'done')->get()->getRowArray();
        if (! $m || ! preg_match('#^[\w./-]+$#', (string) $m['path']) || str_contains($m['path'], '..')
            || ! is_file($this->mediaDir() . '/' . $m['path'])) {
            throw PageNotFoundException::forPageNotFound();
        }
        return $this->response
            ->setHeader('X-Accel-Redirect', '/internal-hw/' . $m['path'])
            ->setHeader('Content-Type', $m['mime'] ?: 'application/octet-stream')
            ->setHeader('Cache-Control', 'private, max-age=86400');
    }

    /** Signature for a short-lived link the extension reads a collected file through from x.com. */
    private static function shareSig(int $mediaId, int $exp): string
    {
        return hash_hmac('sha256', 'hw|' . $mediaId . '|' . $exp, (string) config('Encryption')->key);
    }

    /**
     * POST /collect/{id}/xlinks {media:[id...]} - signed links for Send to X: up to 4 photos,
     * or one video (X does not mix them), plus the post text.
     */
    public function xLinks(int $id)
    {
        $item = $this->item($id);
        $pick = array_values(array_filter(array_map('intval', (array) (($this->request->getJSON(true) ?: [])['media'] ?? []))));
        $rows = $pick === [] ? [] : db_connect()->table($this->t('media'))->where('item_id', $id)->whereIn('id', $pick)
            ->where('status', 'done')->whereIn('kind', ['image', 'video'])->orderBy('id')->get()->getResultArray();
        $kinds = array_count_values(array_column($rows, 'kind'));
        if ($rows === [] || count($rows) !== count($pick)) {
            return $this->response->setStatusCode(400)->setJSON(['ok' => false, 'error' => '보낼 사진이나 영상을 고르세요.']);
        }
        if (($kinds['video'] ?? 0) > 0 && count($rows) > 1) {
            return $this->response->setStatusCode(400)->setJSON(['ok' => false, 'error' => 'X는 영상과 사진을 함께 올릴 수 없습니다 (영상은 1개).']);
        }
        if (count($rows) > 4) {
            return $this->response->setStatusCode(400)->setJSON(['ok' => false, 'error' => 'X 사진은 4장까지입니다.']);
        }
        $exp   = time() + 1800;
        $files = [];
        foreach ($rows as $n => $m) {
            $ext = strtolower(pathinfo((string) $m['path'], PATHINFO_EXTENSION)) ?: ($m['kind'] === 'video' ? 'mp4' : 'jpg');
            $files[] = ['url'      => site_url('share-hw/' . $m['id'] . '/' . $exp . '/' . self::shareSig((int) $m['id'], $exp)),
                        'filename' => ($m['kind'] === 'video' ? 'video' : 'photo' . ($n + 1)) . '.' . $ext,
                        'mime'     => $m['mime'] ?: 'application/octet-stream'];
        }
        $text = \App\Libraries\MediaSupport::shareText(['title' => $item['title'], 'description' => (string) ($item['body'] ?? '')]);
        return $this->response->setJSON(['ok' => true, 'files' => $files, 'text' => $text]);
    }

    /** GET /share-hw/{id}/{exp}/{sig} - a collected file, readable from x.com by the extension. */
    public function share(int $id, int $exp, string $sig)
    {
        if ($exp < time() || ! hash_equals(self::shareSig($id, $exp), $sig)) throw PageNotFoundException::forPageNotFound();
        $origin = (string) $this->request->getHeaderLine('Origin');
        return $this->file($id)
            ->setHeader('Access-Control-Allow-Origin', in_array($origin, ['https://x.com', 'https://twitter.com'], true) ? $origin : 'https://x.com')
            ->setHeader('Vary', 'Origin');
    }

    /** POST /collect/{id}/library - copies the chosen files into the library as one post; YouTube embeds become import jobs. */
    public function toLibrary(int $id)
    {
        $item = $this->item($id);
        $pick = array_values(array_filter(array_map('intval', (array) $this->request->getPost('media'))));
        if ($pick === []) return redirect()->to(site_url('collect/' . $id))->with('error', '보낼 항목을 고르세요.');
        $rows = db_connect()->table($this->t('media'))->where('item_id', $id)->whereIn('id', $pick)->orderBy('id')->get()->getResultArray();

        $userId  = (int) session()->get('user_id');
        $postKey = bin2hex(random_bytes(8));
        $site    = substr(explode('_', $item['source_id'])[0], 0, 20);
        $desc    = mb_substr(trim($item['title'] . "\n\n" . ($item['body'] ?? '')), 0, 20000);
        $stats   = array_filter(['view_count' => $item['views'], 'comment_count' => $item['comments'], 'like_count' => $item['likes']],
                                static fn ($v) => $v !== null && $v >= 0);
        $fields  = ['source' => $site, 'source_url' => mb_substr($item['url'], 0, 1000), 'post_key' => $postKey,
                    'title' => mb_substr($item['title'], 0, 255), 'description' => $desc,
                    'uploader' => $item['author'] ? mb_substr($item['author'], 0, 190) : null,
                    'stats' => $stats ? json_encode(array_map('intval', $stats)) : null];
        $model = new MediaModel();
        $copied = $queued = $failed = 0;
        $pos = 0;
        foreach ($rows as $m) {
            try {
                if ($m['status'] === 'done' && in_array($m['kind'], ['image', 'video'], true)) {
                    $path = $this->mediaDir() . '/' . $m['path'];
                    $ext  = MediaIntake::extOf($m['path']);
                    if (! is_file($path) || ! MediaIntake::allowed($ext)) { $failed++; continue; }
                    [$mid, , $target] = MediaIntake::create($userId, 'collect.' . $ext, $ext, (int) filesize($path), $m['mime']);
                    if (! copy($path, $target)) { $model->delete($mid); $failed++; continue; }
                    $model->update($mid, $fields + ['post_order' => $pos++]);
                    MediaIntake::finish($mid);
                    $copied++;
                } elseif ($m['status'] === 'embed' && $m['kind'] === 'youtube') {
                    (new JobModel())->insert(['user_id' => $userId, 'type' => 'import', 'status' => 'queued',
                        'params' => json_encode(['url' => $m['src_url'], 'platform' => 'youtube', 'index' => 1, 'title' => '',
                                                 'post_key' => $postKey, 'post_order' => $pos++], JSON_UNESCAPED_UNICODE)]);
                    $queued++;
                }
            } catch (\Throwable $e) {
                log_message('error', 'collect->library ' . $m['id'] . ': ' . $e->getMessage());
                $failed++;
            }
        }
        $msg = '라이브러리에 ' . $copied . '개 추가' . ($queued ? ', YouTube ' . $queued . '개 가져오는 중' : '') . ($failed ? ', ' . $failed . '개 실패' : '');
        return redirect()->to(site_url('collect/' . $id))->with($copied + $queued > 0 ? 'flash' : 'error', $msg);
    }
}
