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
        ]);
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
        if ($handle === '') return redirect()->to('/xwatch')->with('errors', ['handle' => 'X 아이디(영문/숫자/_ 15자 이하)를 입력하세요.']);
        $int = fn (string $k, int $lo, int $hi, int $def) => max($lo, min($hi, (int) ($this->request->getPost($k) ?? $def)));
        $old = XWatch::forUser($this->uid());
        $data = [
            'handle'   => $handle,
            'enabled'  => $this->request->getPost('enabled') ? 1 : 0,
            'count_replies' => $this->request->getPost('count_replies') ? 1 : 0,
            'gap_min'  => $int('gap_min', 0, 720, 15),
            'hour_max' => $int('hour_max', 1, 100, 4),
            'day_max'  => $int('day_max', 1, 500, 30),
        ];
        if (strcasecmp($old['handle'], $handle) !== 0) $data['next_check_at'] = null; // check the new handle right away
        XWatch::update($this->uid(), $data);
        return redirect()->to('/xwatch')->with('flash', '설정을 저장했습니다.');
    }

    public function token()
    {
        $issued = XWatch::issueToken($this->uid());
        return redirect()->to('/xwatch')->with('xtoken', $issued)->with('flash', '확장용 토큰을 새로 발급했습니다. 이전 토큰은 더 이상 동작하지 않습니다.');
    }

    /** Queues a check; the worker picks it up within a few seconds. */
    public function checkNow()
    {
        $w = XWatch::forUser($this->uid());
        if ($w['handle'] === '') return redirect()->to('/xwatch')->with('errors', ['handle' => '먼저 X 아이디를 저장하세요.']);
        XWatch::update($this->uid(), ['next_check_at' => gmdate('Y-m-d H:i:s', time() - 1)]);
        return redirect()->to('/xwatch')->with('flash', '검사를 예약했습니다. 1~2분 뒤 새로고침하세요.');
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
