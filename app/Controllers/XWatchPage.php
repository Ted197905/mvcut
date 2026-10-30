<?php

namespace App\Controllers;

use App\Libraries\Cookies;
use App\Libraries\Telegram;
use App\Libraries\XWatch;

/** /xwatch - X account cadence and restriction dashboard. */
class XWatchPage extends BaseController
{
    private function uid(): int
    {
        return (int) session()->get('user_id');
    }

    public function index()
    {
        $w = XWatch::forUser($this->uid());
        return view('xwatch/index', [
            'title'   => 'X 계정 관리',
            'w'       => $w,
            'st'      => XWatch::status($w),
            'history' => XWatch::history($this->uid(), 48),
            'probe'   => Cookies::status(XWatch::PROBE),
            'tg'      => Telegram::config($this->uid()),
            'token'   => session()->getFlashdata('xtoken'),
            'purges'  => db_connect()->table('x_purges')->where('user_id', $this->uid())->orderBy('id', 'DESC')->get(8)->getResultArray(),
            'purgeRun' => XWatch::purgeRunning($this->uid()),
            'purgePrefs' => json_decode((string) ($w['purge_prefs'] ?? ''), true) ?: [],
        ]);
    }

    /**
     * POST /xwatch/purge {mode: scan|delete, from, to (unix), kinds[]} - 게시물 정리: records the run the
     * extension is about to make on x.com (the page hands the same numbers to the extension). JSON {id}.
     */
    public function purgeStart()
    {
        $from = (int) $this->request->getPost('from');
        $to   = (int) $this->request->getPost('to');
        $kinds = array_values(array_intersect((array) $this->request->getPost('kinds'), ['post', 'reply', 'quote']));
        // from = older bound (0 = down to the account's first post), to = newer bound
        if ($to <= 0 || $to > time() || ($from && $to <= $from)) return $this->response->setStatusCode(422)->setJSON(['ok' => false, 'error' => '기간이 잘못됐습니다.']);
        if ($kinds === []) return $this->response->setStatusCode(422)->setJSON(['ok' => false, 'error' => '종류를 하나는 골라야 합니다.']);
        $db = db_connect();
        $db->table('x_purges')->insert(['user_id' => $this->uid(), 'mode' => $this->request->getPost('mode') === 'scan' ? 'scan' : 'delete',
            'from_at' => gmdate('Y-m-d H:i:s', $from ?: 0), 'to_at' => gmdate('Y-m-d H:i:s', $to),
            'query' => mb_substr(trim((string) $this->request->getPost('query')), 0, 500), 'kinds' => implode(',', $kinds),
            'status' => 'running', 'created_at' => gmdate('Y-m-d H:i:s')]);
        return $this->response->setJSON(['ok' => true, 'id' => (int) $db->insertID()]);
    }

    /** POST /xwatch/purge/{id} - the page reports the extension's progress / result; deleted posts leave x_posts. */
    public function purgeReport(int $id)
    {
        $row = XWatch::purgeUpdate($this->uid(), $id, $this->request->getJSON(true) ?: []);
        return $row ? $this->response->setJSON(['ok' => true]) : $this->response->setStatusCode(404)->setJSON(['ok' => false]);
    }

    /** GET /xwatch/purge/{id} - the run's state for the page (the extension reports through /xapi/purge/{id}) */
    public function purgeState(int $id)
    {
        $row = db_connect()->table('x_purges')->where(['id' => $id, 'user_id' => $this->uid()])->get()->getRowArray();
        if (! $row) return $this->response->setStatusCode(404)->setJSON(['ok' => false]);
        $items = json_decode((string) $row['items'], true) ?: [];
        return $this->response->setJSON(['ok' => true, 'id' => (int) $row['id'], 'mode' => $row['mode'], 'status' => $row['status'], 'found' => (int) $row['found'],
            'deleted' => (int) $row['deleted'], 'failed' => (int) $row['failed'], 'error' => $row['error'], 'updated_at' => strtotime($row['updated_at'] . ' UTC'),
            'items' => array_slice(array_reverse($items), 0, 30)]);
    }

    /** GET /xwatch/purge/{id}/log - the run's step log as plain text */
    public function purgeLog(int $id)
    {
        $row = db_connect()->table('x_purges')->select('log, query, status, found, deleted, failed, created_at')->where(['id' => $id, 'user_id' => $this->uid()])->get()->getRowArray();
        if (! $row) throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        $head = "# 게시물 정리 #{$id} " . XWatch::kst(strtotime($row['created_at'] . ' UTC'), 'Y-m-d H:i') . " | {$row['query']} | {$row['status']} found {$row['found']} deleted {$row['deleted']} failed {$row['failed']}\n\n";
        return $this->response->setContentType('text/plain; charset=UTF-8')->setBody($head . ((string) $row['log'] ?: '(기록 없음: 확장 1.9.3 이전 실행)'));
    }

    /** POST /xwatch/purge/prefs - the form's values, kept per account (x_watch.purge_prefs) */
    public function purgePrefs()
    {
        $j = $this->request->getJSON(true);
        if (! is_array($j)) return $this->response->setStatusCode(422)->setJSON(['ok' => false]);
        $keep = array_intersect_key($j, array_flip(['preset', 'from', 'to', 'all', 'kw', 'kind', 'query']));
        XWatch::update($this->uid(), ['purge_prefs' => mb_substr(json_encode($keep, JSON_UNESCAPED_UNICODE), 0, 2000)]);
        return $this->response->setJSON(['ok' => true]);
    }

    /** GET /xwatch/checks?status=ok|banned|error&page=N - every stored restriction check (30 days) */
    public function checks()
    {
        $uid    = $this->uid();
        $status = (string) $this->request->getGet('status');
        if (! in_array($status, ['ok', 'banned', 'error'], true)) $status = '';
        $db     = db_connect();
        $counts = array_column($db->table('x_checks')->select('status, COUNT(*) AS n')->where('user_id', $uid)
                                  ->groupBy('status')->get()->getResultArray(), 'n', 'status');
        $b = $db->table('x_checks')->where('user_id', $uid);
        if ($status !== '') $b->where('status', $status);
        $matched = $b->countAllResults(false);
        $per     = 50;
        $pages   = max(1, (int) ceil($matched / $per));
        $page    = min(max(1, (int) $this->request->getGet('page')), $pages);
        $rows    = $b->orderBy('checked_at', 'DESC')->orderBy('id', 'DESC')->limit($per, ($page - 1) * $per)->get()->getResultArray();
        return view('xwatch/checks', [
            'title'  => '제한 검사 기록',
            'handle' => XWatch::forUser($uid)['handle'] ?? '',
            'rows'   => array_map([XWatch::class, 'decodeCheck'], $rows),
            'counts' => $counts, 'total' => array_sum($counts),
            'status' => $status, 'page' => $page, 'pages' => $pages,
        ]);
    }

    public function save()
    {
        $handle = XWatch::cleanHandle((string) $this->request->getPost('handle'));
        if ($handle === '') return redirect()->to('/xwatch#settings')->with('errors', ['handle' => 'X 아이디(영문/숫자/_ 15자 이하)를 입력하세요.']);
        $int = fn (string $k, int $lo, int $hi, int $def) => max($lo, min($hi, (int) ($this->request->getPost($k) ?? $def)));
        $old = XWatch::forUser($this->uid());
        $data = [
            'handle'   => $handle,
            'enabled'  => $this->request->getPost('enabled') ? 1 : 0,
            'count_replies' => $this->request->getPost('count_replies') ? 1 : 0,
            'gap_min'  => $int('gap_min', 0, 720, 15),
            'gap_warn' => $int('gap_warn', 0, 720, 10),
            'hour_max' => $int('hour_max', 1, 100, 4),
            'day_max'  => $int('day_max', 1, 500, 30),
        ];
        if (strcasecmp($old['handle'], $handle) !== 0) $data['next_check_at'] = null; // check the new handle right away
        XWatch::update($this->uid(), $data);
        return redirect()->to('/xwatch#settings')->with('flash', '설정을 저장했습니다.');
    }

    public function token()
    {
        $issued = XWatch::issueToken($this->uid());
        return redirect()->to('/xwatch#settings')->with('xtoken', $issued)->with('flash', '확장용 토큰을 새로 발급했습니다. 이전 토큰은 더 이상 동작하지 않습니다.');
    }

    /** Queues a check; the worker picks it up within a few seconds. */
    public function checkNow()
    {
        $w = XWatch::forUser($this->uid());
        if ($w['handle'] === '') return redirect()->to('/xwatch#settings')->with('errors', ['handle' => '먼저 X 아이디를 저장하세요.']);
        XWatch::update($this->uid(), ['next_check_at' => gmdate('Y-m-d H:i:s', time() - 1)]);
        return redirect()->to('/xwatch#checks')->with('flash', '검사를 예약했습니다. 1~2분 뒤 새로고침하세요.');
    }

    /** POST /xwatch/telegram  action=token (token=...) | link | test | off */
    public function telegram()
    {
        $uid = $this->uid();
        $err = match ((string) $this->request->getPost('action')) {
            'token' => Telegram::setToken($uid, (string) $this->request->getPost('token')),
            'link'  => Telegram::linkChat($uid),
            'test'  => Telegram::send($uid, 'MV Cut 테스트 알림입니다.'),
            'off'   => (Telegram::forget($uid) ?? null),
            default => '알 수 없는 요청입니다.',
        };
        if ($err) return redirect()->to('/xwatch#telegram')->with('errors', ['telegram' => $err]);
        $msg = match ((string) $this->request->getPost('action')) {
            'token' => '봇 토큰을 저장했습니다. 텔레그램에서 봇에게 "시작"을 누른 뒤 "채팅 연결"을 누르세요.',
            'link'  => '텔레그램 채팅을 연결하고 확인 메시지를 보냈습니다.',
            'test'  => '테스트 메시지를 보냈습니다.',
            default => '텔레그램 알림을 해제했습니다.',
        };
        return redirect()->to('/xwatch#telegram')->with('flash', $msg);
    }

    public function deletePost(string $id)
    {
        XWatch::deletePost($this->uid(), $id);
        return redirect()->to('/xwatch')->with('flash', '기록에서 삭제했습니다.');
    }
}
