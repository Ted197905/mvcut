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
        $rows = $b->orderBy($filter === 'done' ? 'd.slot_at' : 'd.score', $filter === 'done' ? 'ASC' : 'DESC')->orderBy('d.id')->get()->getResultArray();
        foreach ($rows as &$d) $d['files'] = D::media($d);
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
                                     'xwatch' => Library::xwatchPanel($this->uid()), 'mode' => D::mode($this->uid()), 'slot' => D::slotType($this->uid())]);
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

    /** POST /drafts/{id}/unapprove - back to review while the extension has not scheduled it yet */
    public function unapprove(int $id)
    {
        $d = $this->draft($id);
        if (! in_array($d['status'], ['approved', 'error'], true)) return $this->back($id)->with('error', '이미 X에 예약되어 여기서는 뺄 수 없습니다. X의 예약 목록에서 지우세요.');
        D::update($id, ['status' => 'new', 'slot_at' => null, 'error' => null, 'tries' => 0]);
        return $this->back($id)->with('flash', '승인을 취소했습니다.');
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
