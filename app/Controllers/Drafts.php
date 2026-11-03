<?php

namespace App\Controllers;

use App\Libraries\Drafts as D;
use CodeIgniter\Exceptions\PageNotFoundException;

/**
 * /drafts - admin only. The collector's X post drafts: edit, approve (-> posting slot, the
 * extension schedules it on X), reject, or ask the model for another take.
 */
class Drafts extends BaseController
{
    private const FILTERS = ['new' => '검토 대기', 'done' => '승인됨', 'out' => '제외', 'all' => '전체'];

    private function uid(): int
    {
        return (int) session()->get('user_id');
    }

    private function draft(int $id): array
    {
        $d = D::find($id);
        if (! $d) throw PageNotFoundException::forPageNotFound();
        return $d;
    }

    private function back(int $id)
    {
        $q = (string) $this->request->getPost('back');
        return redirect()->to(site_url('drafts' . ($q !== '' && preg_match('/^[\w=&%-]*$/', $q) ? '?' . $q : '')) . '#d' . $id);
    }

    public function index()
    {
        $db = db_connect();
        $batches = $db->table(D::t('draft_batches'))->orderBy('batch', 'DESC')->get(14)->getResultArray();
        $batch = (string) $this->request->getGet('batch');
        if (! preg_match('/^\d{8}(am|pm|h\d{2})$/', $batch)) $batch = $batches[0]['batch'] ?? '';
        $filter = (string) $this->request->getGet('f');
        if (! isset(self::FILTERS[$filter])) $filter = 'new';

        $b = $db->table(D::t('drafts') . ' d')
            ->select('d.*, i.source_id, i.url, i.title, i.title_ko, i.views, i.comments, i.likes, i.posted_at, i.first_seen, i.thumb_url, i.relevant, i.issue, i.lang')
            ->join(D::t('items') . ' i', 'i.id = d.item_id')
            ->where('d.batch', $batch);
        match ($filter) {
            'new'  => $b->whereIn('d.status', ['new', 'gen', 'regen', 'error', 'failed']),
            'done' => $b->whereIn('d.status', ['approved', 'sending', 'scheduled', 'posted']),
            'out'  => $b->whereIn('d.status', ['rejected', 'skipped']),
            default => null,
        };
        // a copy sits right after its original (same score, then the original's id)
        $rows = $b->orderBy($filter === 'done' ? 'd.slot_at' : 'd.score', $filter === 'done' ? 'ASC' : 'DESC')
                  ->orderBy('COALESCE(d.copy_of, d.id)', '', false)->orderBy('d.id')->get()->getResultArray();
        foreach ($rows as &$d) { $d['files'] = D::media($d); $d['yt'] = D::youtube($d); }
        unset($d);

        $counts = [];
        foreach ($db->table(D::t('drafts'))->select('status, COUNT(*) AS n')->where('batch', $batch)->groupBy('status')->get()->getResultArray() as $r) {
            $counts[$r['status']] = (int) $r['n'];
        }
        $names = array_column($db->table(D::t('sources'))->select('id, name')->get()->getResultArray(), 'name', 'id');
        $next  = D::nextSlot($this->uid(), $batch !== '' ? D::publishFrom($batch) : null);
        return view('drafts/index', ['title' => '초안', 'rows' => $rows, 'batches' => $batches, 'batch' => $batch, 'filter' => $filter,
                                     'filters' => self::FILTERS, 'counts' => $counts, 'names' => $names, 'next' => $next,
                                     'publishFrom' => $batch !== '' ? D::publishFrom($batch) : null,
                                     'usage' => D::usageText($this->uid(), $batch !== '' ? $batch : null),
                                     'xwatch' => Library::xwatchPanel($this->uid()), 'mode' => D::mode($this->uid()), 'slot' => D::slotType($this->uid()),
                                     'off' => array_keys(array_filter(['drafts' => ! \App\Libraries\Ops::on('drafts'), 'collect' => ! \App\Libraries\Ops::on('collect'), 'judge' => ! \App\Libraries\Ops::on('judge')]))]);
    }

    /** Text / thread / media from the card's form (approve and save both take it). Returns an error or null. */
    private function take(array $d): ?string
    {
        $text = str_replace("\r", '', trim((string) $this->request->getPost('text')));
        if (mb_strlen($text) > 4000) return '본문이 너무 깁니다 (4000자).';
        $data = ['text' => $text];
        if ($d['style'] === 'story') {
            $body = str_replace("\r", '', trim((string) $this->request->getPost('body')));
            $data['thread'] = $body === '' ? null : json_encode([mb_substr($body, 0, 4000)], JSON_UNESCAPED_UNICODE);
        }
        $pick = array_values(array_unique(array_map('intval', (array) $this->request->getPost('media'))));
        if ($e = D::checkMedia(D::media($d), $pick)) return $e;
        $data['media'] = json_encode($pick);
        D::update((int) $d['id'], $data);
        return null;
    }

    /** POST /drafts/{id}/save */
    public function save(int $id)
    {
        $d = $this->draft($id);
        if (! in_array($d['status'], ['new', 'error', 'failed', 'rejected', 'skipped'], true)) return $this->back($id)->with('error', '지금 상태에서는 고칠 수 없습니다.');
        $e = $this->take($d);
        return $this->back($id)->with($e ? 'error' : 'flash', $e ?: '저장했습니다.');
    }

    /** POST /drafts/{id}/approve - saves the edits, takes the next free slot */
    public function approve(int $id)
    {
        $d = $this->draft($id);
        if (! in_array($d['status'], ['new', 'error', 'rejected', 'skipped', 'failed'], true)) return $this->answer($id, '지금 상태에서는 승인할 수 없습니다.');
        if ($e = $this->take($d)) return $this->answer($id, $e);
        $d = $this->draft($id);
        if (in_array($d['status'], ['skipped', 'failed'], true)) D::update($id, ['status' => 'new']);
        $r = D::approve($this->uid(), $this->draft($id));
        if (is_string($r)) return $this->answer($id, $r);
        $at = \App\Libraries\XWatch::kst($r, 'n/j H:i');
        $semi = D::mode($this->uid()) !== 'auto';
        $msg = $at . ' 슬롯에 넣었습니다. ' . ($semi ? 'X 작성창에 본문과 예약 시각을 넣어 엽니다: "예약하기"만 누르세요.' : '확장이 승인 순서대로 10분 간격으로 X 예약에 등록합니다.');
        if ($this->request->isAJAX()) {
            return $this->response->setJSON(['ok' => true, 'msg' => $msg, 'label' => $at . ' 예약 대기', 'fill' => $semi ? $id : 0]);
        }
        $res = $this->back($id)->with('flash', $msg);
        return $semi ? $res->with('fill', $id) : $res;
    }

    /** An error for the card: JSON for the page's in-place approve / reject, a redirect otherwise. */
    private function answer(int $id, string $error)
    {
        if ($this->request->isAJAX()) return $this->response->setJSON(['ok' => false, 'error' => $error]);
        return $this->back($id)->with('error', $error);
    }

    /** POST /drafts/mode  mode=auto|semi */
    public function mode()
    {
        $m = $this->request->getPost('mode') === 'auto' ? 'auto' : 'semi';
        \App\Libraries\XWatch::update($this->uid(), ['draft_mode' => $m]);
        return $this->back(0)->with('flash', $m === 'auto' ? '자동 등록: 확장이 승인한 초안을 10분 간격으로 X 예약에 등록합니다 (실패하면 ' . D::MAX_TRIES . '번까지 다시 시도).'
                                                          : '반자동: 승인하면 X 작성창을 채워 엽니다. 예약은 직접 합니다.');
    }

    /** POST /drafts/slots  type=q15|q20|rand - the posting grid for drafts approved from now on */
    public function slots()
    {
        $t = (string) $this->request->getPost('type');
        if (! isset(D::SLOT_TYPES[$t])) return $this->back(0)->with('error', '알 수 없는 슬롯 방식입니다.');
        \App\Libraries\XWatch::update($this->uid(), ['slot_type' => $t]);
        return $this->back(0)->with('flash', '슬롯: ' . D::SLOT_TYPES[$t]['label'] . '. 이미 승인한 초안의 시각은 그대로입니다.');
    }

    /** POST /drafts/{id}/xfill - text, signed file links and the slot, for the extension to fill X's composer */
    public function xfill(int $id)
    {
        $d = $this->draft($id);
        if (! in_array($d['status'], ['approved', 'error'], true) || ! $d['slot_at']) {
            return $this->response->setStatusCode(409)->setJSON(['ok' => false, 'error' => '승인된 초안이 아닙니다.']);
        }
        $at = \App\Libraries\XWatch::kst(strtotime($d['slot_at'] . ' UTC'), 'n/j H:i');
        return $this->response->setJSON(['ok' => true, 'text' => D::fullText($d), 'files' => D::files($d), 'at' => $at, 'ts' => strtotime($d['slot_at'] . ' UTC'),
            'note' => '예약 시각 ' . $at . '로 지정하고 예약하세요 (작성창 아래 달력 아이콘).']);
    }

    /**
     * POST /drafts/{id}/copy - a second draft of the same post (same text, photos and research; the media
     * files are the post's, so nothing is copied but the row), so one post with several stories or more
     * than 4 photos can go out as several X posts, each reviewed and approved on its own.
     */
    public function copy(int $id)
    {
        $d = $this->draft($id);
        if (in_array($d['status'], ['gen', 'regen'], true)) return $this->back($id)->with('error', '모델이 쓰는 중인 초안은 복사할 수 없습니다.');
        $db  = db_connect();
        $now = gmdate('Y-m-d H:i:s');
        $db->table(D::t('drafts'))->insert([
            'item_id' => $d['item_id'], 'copy_of' => $d['copy_of'] ?: $d['id'], 'batch' => $d['batch'], 'style' => $d['style'],
            'text' => $d['text'], 'thread' => $d['thread'], 'research' => $d['research'], 'media' => $d['media'], 'score' => $d['score'],
            'status' => 'new', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $new = (int) $db->insertID();
        \App\Libraries\AppLog::info('drafts', 'copy', 'draft ' . $id . ' -> ' . $new);
        return $this->back($new)->with('flash', '초안을 복사했습니다. 복사본은 원본 바로 아래에 있습니다.');
    }

    /** GET /drafts/{id}/source - the collected post behind a draft (title, body, Korean translation, comments) for the "원문 보기" layer. */
    public function source(int $id)
    {
        $d = $this->draft($id);
        $i = db_connect()->table(D::t('items'))->select('title, title_ko, body, body_ko, excerpt, comment_text, lang, url, source_id')
            ->where('id', (int) $d['item_id'])->get()->getRowArray();
        if (! $i) return $this->response->setStatusCode(404)->setJSON(['ok' => false, 'error' => '수집 글이 없습니다.']);
        $names = array_column(db_connect()->table(D::t('sources'))->select('id, name')->where('id', $i['source_id'])->get()->getResultArray(), 'name', 'id');
        return $this->response->setJSON(['ok' => true, 'title' => $i['title'], 'title_ko' => $i['title_ko'], 'body' => $i['body'] ?: $i['excerpt'],
            'body_ko' => $i['body_ko'], 'comments' => $i['comment_text'], 'lang' => $i['lang'], 'url' => $i['url'], 'source' => $names[$i['source_id']] ?? $i['source_id']]);
    }

    /** POST /drafts/{id}/unapprove - back to review while the extension has not scheduled it yet */
    public function unapprove(int $id)
    {
        $d = $this->draft($id);
        if (! in_array($d['status'], ['approved', 'error', 'rejected'], true)) return $this->answer($id, '이미 X에 예약되어 여기서는 뺄 수 없습니다. X의 예약 목록에서 지우세요.');
        $was = $d['status'];
        D::update($id, ['status' => 'new', 'slot_at' => null, 'error' => null, 'tries' => 0]);
        \App\Libraries\AppLog::info('drafts', $was === 'rejected' ? 'unreject' : 'unapprove', 'draft ' . $id);
        $msg = $was === 'rejected' ? '제외를 취소했습니다.' : '승인을 취소했습니다. 고친 뒤 다시 승인하세요.';
        if ($this->request->isAJAX()) return $this->response->setJSON(['ok' => true, 'msg' => $msg, 'label' => '검토 대기']);
        return $this->back($id)->with('flash', $msg);
    }

    /**
     * POST /drafts/{id}/photos  photo[] - photos added by hand: stored as media rows of the post
     * (hallyuwire media dir, manual/YYYY/MM/<sha1>.<ext>) and chosen for the draft while it has room
     * (4 photos, no video). JSON for the page, a redirect otherwise.
     */
    public function photos(int $id)
    {
        $d = $this->draft($id);
        if (! in_array($d['status'], ['new', 'error', 'failed', 'rejected', 'skipped'], true)) return $this->answer($id, '지금 상태에서는 사진을 추가할 수 없습니다.');
        $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        $root  = rtrim((string) env('hallyuwire.media', '/srv/hallyuwire/media'), '/');
        $db    = db_connect();
        $chosen = array_map('intval', json_decode((string) ($d['media'] ?? '[]'), true) ?: []);
        $kinds  = array_column(D::media($d), 'kind', 'id');
        $added  = [];
        foreach ($this->request->getFileMultiple('photo') ?? [] as $f) {
            if (! $f->isValid()) return $this->answer($id, '업로드 실패: ' . $f->getErrorString());
            $info = @getimagesize($f->getTempName());
            $mime = $info['mime'] ?? '';
            if (! isset($types[$mime])) return $this->answer($id, '사진 파일(JPG, PNG, WEBP, GIF)만 넣을 수 있습니다: ' . $f->getClientName());
            if ($f->getSize() > 5 * 1024 * 1024) return $this->answer($id, 'X 사진은 5MB까지입니다: ' . $f->getClientName());
            $sha = sha1_file($f->getTempName());
            $old = $db->table(D::t('media'))->select('id')->where('item_id', (int) $d['item_id'])->where('sha1', $sha)->get()->getRowArray();
            if ($old) {
                $mid = (int) $old['id'];
            } else {
                $rel = 'manual/' . gmdate('Y/m') . '/' . $sha . '.' . $types[$mime];
                if (! is_dir(dirname($root . '/' . $rel)) && ! @mkdir(dirname($root . '/' . $rel), 02775, true)) return $this->answer($id, '저장 폴더를 만들 수 없습니다.');
                if (! @copy($f->getTempName(), $root . '/' . $rel)) return $this->answer($id, '파일을 저장할 수 없습니다.');
                @chmod($root . '/' . $rel, 0664);
                $now = gmdate('Y-m-d H:i:s');
                $db->table(D::t('media'))->insert(['item_id' => (int) $d['item_id'], 'kind' => 'image', 'src_url' => 'manual:' . $f->getClientName(),
                    'src_hash' => $sha, 'status' => 'done', 'path' => $rel, 'bytes' => $f->getSize(), 'mime' => $mime, 'sha1' => $sha,
                    'width' => $info[0] ?? null, 'height' => $info[1] ?? null, 'created_at' => $now, 'done_at' => $now]);
                $mid = (int) $db->insertID();
                $kinds[$mid] = 'image';
            }
            $imgs = array_filter($chosen, static fn ($c) => ($kinds[$c] ?? '') === 'image');
            $vid  = array_filter($chosen, static fn ($c) => ($kinds[$c] ?? '') === 'video');
            $on   = in_array($mid, $chosen, true) || (! $vid && count($imgs) < 4);
            if ($on && ! in_array($mid, $chosen, true)) $chosen[] = $mid;
            $added[] = ['id' => $mid, 'chosen' => $on, 'url' => site_url('collect/media/' . $mid)];
        }
        if ($added === []) return $this->answer($id, '사진을 골라 주세요.');
        D::update($id, ['media' => json_encode(array_values($chosen))]);
        if ($this->request->isAJAX()) return $this->response->setJSON(['ok' => true, 'items' => $added]);
        return $this->back($id)->with('flash', '사진 ' . count($added) . '장을 추가했습니다.');
    }

    /** POST /drafts/{id}/reject */
    public function reject(int $id)
    {
        $d = $this->draft($id);
        if (in_array($d['status'], ['sending', 'scheduled', 'posted'], true)) return $this->answer($id, '이미 예약된 초안입니다.');
        D::update($id, ['status' => 'rejected', 'slot_at' => null]);
        if ($this->request->isAJAX()) return $this->response->setJSON(['ok' => true, 'label' => '제외']);
        return $this->back($id)->with('flash', '제외했습니다.');
    }

    /** POST /drafts/{id}/regen  style=info|story|quote - the collector rewrites it within a minute */
    public function regen(int $id)
    {
        $d = $this->draft($id);
        if (in_array($d['status'], ['sending', 'scheduled', 'posted', 'gen', 'regen'], true)) return $this->back($id)->with('error', '지금 상태에서는 다시 쓸 수 없습니다.');
        $style = (string) $this->request->getPost('style');
        if (! isset(D::STYLES[$style])) $style = $d['style'];
        D::update($id, ['status' => 'regen', 'style' => $style, 'slot_at' => null, 'error' => null]);
        return $this->back($id)->with('flash', D::STYLES[$style] . ' 스타일로 다시 씁니다 (1분 안팎).');
    }

    /** GET /drafts/status?ids=1,2&batch=... - the cards' states and the slot line, polled by the page */
    public function status()
    {
        $ids = array_filter(array_map('intval', explode(',', (string) $this->request->getGet('ids'))));
        $out = [];
        if ($ids) {
            foreach (db_connect()->table(D::t('drafts'))->select('id, status, slot_at, error')->whereIn('id', $ids)->get()->getResultArray() as $r) {
                [$label, $cls] = D::badge($r);
                $out[(int) $r['id']] = ['status' => $r['status'], 'label' => $label, 'cls' => $cls, 'error' => (string) $r['error']];
            }
        }
        $batch = (string) $this->request->getGet('batch');
        return $this->response->setJSON(['items' => $out, 'usage' => D::usageText($this->uid(), preg_match('/^\d{8}(am|pm|h\d{2})$/', $batch) ? $batch : null)]);
    }
}
