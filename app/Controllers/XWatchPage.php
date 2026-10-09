<?php

namespace App\Controllers;

use App\Libraries\Cookies;
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
            'token'   => session()->getFlashdata('xtoken'),
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

    public function deletePost(string $id)
    {
        XWatch::deletePost($this->uid(), $id);
        return redirect()->to('/xwatch')->with('flash', '기록에서 삭제했습니다.');
    }
}
