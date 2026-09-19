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
        ]);
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

    /** POST /settings/cookies/{platform}/delete */
    public function deleteCookie(string $platform)
    {
        if (! Cookies::known($platform)) return redirect()->to('/settings');
        Cookies::delete($platform);
        return redirect()->to('/settings')->with('flash', Cookies::PLATFORMS[$platform]['label'] . ' 쿠키를 삭제했습니다.');
    }
}
