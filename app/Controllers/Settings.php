<?php

namespace App\Controllers;

use App\Libraries\Cookies;
use App\Models\UserModel;

class Settings extends BaseController
{
    public function index()
    {
        $users = new UserModel();
        return view('settings/index', [
            'title'   => '설정',
            'user'    => $users->find(session()->get('user_id')),
            'cookies' => Cookies::all(),
            'auto'    => session()->get('role') === 'admin' ? $this->autoRows() : null,
        ]);
    }

    /**
     * Everything that runs by itself, for 설정 > 자동 기능: [key, group, label, about, on, state].
     * Collector / worker parts are switches in hallyuwire.switches (Libraries\Ops); the X check and the
     * drafts' X registration are the user's x_watch settings.
     */
    private function autoRows(): array
    {
        $uid  = (int) session()->get('user_id');
        $rows = [];
        $ago  = static fn (?int $s) => $s === null ? '' : ($s < 60 ? $s . '초' : ($s < 3600 ? intdiv($s, 60) . '분' : ($s < 86400 ? intdiv($s, 3600) . '시간' : intdiv($s, 86400) . '일'))) . ' 전';
        foreach (\App\Libraries\Ops::status($uid)['parts'] as $p) {
            $rows[] = ['key' => $p['name'], 'group' => $p['group'], 'label' => $p['label'], 'about' => $p['about'], 'on' => $p['on'],
                       'state' => ! $p['on'] ? '꺼짐' : ($p['busy'] ? '실행 중: ' . $p['detail'] : ($p['last'] !== null ? '대기 · 마지막 ' . $ago($p['last']) : '대기'))];
        }
        $w = \App\Libraries\XWatch::forUser($uid);
        $c = db_connect()->table('x_checks')->select('status, summary, checked_at')->where('user_id', $uid)->orderBy('id', 'DESC')->get(1)->getRowArray();
        $rows[] = ['key' => 'xcheck', 'group' => 'X', 'label' => 'X 제한 체크', 'on' => (bool) $w['enabled'],
                   'about' => '@' . ($w['handle'] ?: '-') . ' 서치밴 검사: 평소 10분, 제한 중엔 1시간마다 + 텔레그램 알림',
                   'state' => ! $w['enabled'] ? '꺼짐' : ($c ? $ago(time() - strtotime($c['checked_at'] . ' UTC')) . ': ' . $c['summary'] : '검사 기록 없음')];
        $rows[] = ['key' => 'draft_auto', 'group' => '초안', 'label' => '초안 X 자동 등록', 'on' => ($w['draft_mode'] ?? 'semi') === 'auto',
                   'about' => '켜면 확장이 승인한 초안을 10분에 1건씩 X 예약에 등록, 끄면 반자동(작성창을 채워 열고 "예약하기"는 직접)',
                   'state' => ($w['draft_mode'] ?? 'semi') === 'auto' ? '자동 등록' : '반자동'];
        $order = ['수집' => 0, '모델' => 1, '초안' => 2, 'X' => 3, 'MV Cut' => 4];
        usort($rows, static fn ($a, $b) => ($order[$a['group']] ?? 9) <=> ($order[$b['group']] ?? 9));
        return $rows;
    }

    /** POST /settings/auto  key, on=0|1 (admin) */
    public function auto()
    {
        if (session()->get('role') !== 'admin') return $this->response->setStatusCode(403)->setJSON(['ok' => false]);
        $key = (string) $this->request->getPost('key');
        $on  = $this->request->getPost('on') === '1';
        $uid = (int) session()->get('user_id');
        if (isset(\App\Libraries\Ops::PARTS[$key])) {
            \App\Libraries\Ops::setSwitch($key, $on);
        } elseif ($key === 'xcheck') {
            \App\Libraries\XWatch::update($uid, ['enabled' => $on ? 1 : 0] + ($on ? ['next_check_at' => null] : []));
        } elseif ($key === 'draft_auto') {
            \App\Libraries\XWatch::update($uid, ['draft_mode' => $on ? 'auto' : 'semi']);
        } else {
            return $this->response->setStatusCode(400)->setJSON(['ok' => false, 'error' => 'unknown']);
        }
        return $this->response->setJSON(['ok' => true]);
    }

    /** POST /settings/account - display name, email, password */
    public function account()
    {
        $users  = new UserModel();
        $userId = (int) session()->get('user_id');
        $user   = $users->find($userId);

        $email       = strtolower(trim((string) $this->request->getPost('email')));
        $newPassword = (string) $this->request->getPost('new_password');
        $changing    = $newPassword !== '' || $email !== $user['email'];

        $rules = ['display_name' => 'required|min_length[2]|max_length[60]', 'email' => 'required|valid_email|max_length[190]'];
        if ($changing) $rules['current_password'] = 'required';
        if ($newPassword !== '') {
            $rules['new_password']         = 'min_length[8]|max_length[200]';
            $rules['new_password_confirm'] = 'matches[new_password]';
        }
        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }
        if ($changing && ! password_verify((string) $this->request->getPost('current_password'), $user['password_hash'])) {
            return redirect()->back()->withInput()->with('errors', ['current_password' => '현재 비밀번호가 올바르지 않습니다.']);
        }
        if ($email !== $user['email'] && $users->where('email', $email)->where('id !=', $userId)->first()) {
            return redirect()->back()->withInput()->with('errors', ['email' => '이미 사용 중인 이메일입니다.']);
        }

        $data = ['display_name' => trim((string) $this->request->getPost('display_name')), 'email' => $email];
        if ($newPassword !== '') $data['password_hash'] = UserModel::hash($newPassword);
        $users->update($userId, $data);
        session()->set('display_name', $data['display_name']);
        return redirect()->to('/settings')->with('flash', '계정 정보를 저장했습니다.');
    }

    /** POST /settings/cookies/{platform} - store an exported cookies.txt */
    public function uploadCookie(string $platform)
    {
        if ($this->request->getPost('back') === 'xwatch') {
            $r = $this->storeCookie($platform);
            return redirect()->to('/xwatch')->with($r[0] ? 'flash' : 'errors', $r[0] ? $r[1] : ['cookie' => $r[1]]);
        }
        if (! Cookies::known($platform)) return redirect()->to('/settings')->with('errors', ['cookie' => '알 수 없는 플랫폼입니다.']);
        $file = $this->request->getFile('cookies');
        if (! $file || ! $file->isValid()) {
            return redirect()->to('/settings')->with('errors', ['cookie' => '파일을 읽지 못했습니다. ' . ($file ? $file->getErrorString() : '')]);
        }
        if ($file->getSize() > 256 * 1024) {
            return redirect()->to('/settings')->with('errors', ['cookie' => '쿠키 파일이 너무 큽니다(최대 256KB).']);
        }
        $raw = (string) file_get_contents($file->getTempName());
        if ($err = Cookies::save($platform, $raw)) {
            return redirect()->to('/settings')->with('errors', ['cookie' => $err]);
        }
        return redirect()->to('/settings')->with('flash', Cookies::PLATFORMS[$platform]['label'] . ' 쿠키를 등록했습니다.');
    }

    /** @return array{0:bool,1:string} saved, message */
    private function storeCookie(string $platform): array
    {
        if (! Cookies::known($platform)) return [false, '알 수 없는 플랫폼입니다.'];
        $file = $this->request->getFile('cookies');
        if (! $file || ! $file->isValid()) return [false, '파일을 읽지 못했습니다.'];
        if ($file->getSize() > 256 * 1024) return [false, '쿠키 파일이 너무 큽니다(최대 256KB).'];
        if ($err = Cookies::save($platform, (string) file_get_contents($file->getTempName()))) return [false, $err];
        return [true, Cookies::PLATFORMS[$platform]['label'] . ' 쿠키를 등록했습니다.'];
    }

    /** POST /settings/cookies/{platform}/delete */
    public function deleteCookie(string $platform)
    {
        if (! Cookies::known($platform)) return redirect()->to('/settings');
        Cookies::delete($platform);
        return redirect()->to('/settings')->with('flash', Cookies::PLATFORMS[$platform]['label'] . ' 쿠키를 삭제했습니다.');
    }
}
