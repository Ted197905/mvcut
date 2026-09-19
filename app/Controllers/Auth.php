<?php

namespace App\Controllers;

use App\Models\UserModel;

class Auth extends BaseController
{
    public function login()
    {
        if (session()->get('user_id')) {
            return redirect()->to('/library');
        }
        return view('auth/login', ['title' => '로그인']);
    }

    public function attemptLogin()
    {
        $rules = [
            'email'    => 'required|valid_email',
            'password' => 'required',
        ];
        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }
        $users = new UserModel();
        $user  = $users->findByEmail($this->request->getPost('email'));
        if (! $user || ! password_verify($this->request->getPost('password'), $user['password_hash'])) {
            return redirect()->back()->withInput()->with('errors', ['auth' => '이메일 또는 비밀번호가 올바르지 않습니다.']);
        }
        if (($user['status'] ?? 'active') !== 'active') {
            return redirect()->back()->withInput()->with('errors', ['auth' => $user['status'] === 'blocked'
                ? '사용이 중지된 계정입니다. 관리자에게 문의하세요.'
                : '관리자 승인 대기 중인 계정입니다. 승인 후 로그인할 수 있습니다.']);
        }
        $this->startSession($user);
        $to = session()->get('redirect_after_login') ?: '/library';
        session()->remove('redirect_after_login');
        return redirect()->to($to);
    }

    public function register()
    {
        if (session()->get('user_id')) {
            return redirect()->to('/library');
        }
        return view('auth/register', ['title' => '회원가입']);
    }

    public function attemptRegister()
    {
        $rules = [
            'display_name'     => 'required|min_length[2]|max_length[60]',
            'email'            => 'required|valid_email|max_length[190]|is_unique[users.email]',
            'password'         => 'required|min_length[8]|max_length[200]',
            'password_confirm' => 'required|matches[password]',
        ];
        $messages = [
            'email' => [
                'is_unique' => '이미 가입된 이메일입니다.',
            ],
            'password_confirm' => [
                'matches' => '비밀번호 확인이 일치하지 않습니다.',
            ],
        ];
        if (! $this->validate($rules, $messages)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }
        $users = new UserModel();
        // sign-ups wait for an admin, and AI stays off until one turns it on
        $users->insert([
            'email'         => strtolower(trim($this->request->getPost('email'))),
            'password_hash' => UserModel::hash($this->request->getPost('password')),
            'display_name'  => trim($this->request->getPost('display_name')),
            'role'          => 'user',
            'status'        => 'pending',
            'ai_enabled'    => 0,
        ]);
        return redirect()->to('/login')->with('flash',
            '가입 신청이 접수되었습니다. 관리자 승인 후 로그인할 수 있습니다.');
    }

    public function logout()
    {
        session()->destroy();
        return redirect()->to('/');
    }

    private function startSession(array $user): void
    {
        session()->regenerate(true);
        session()->set([
            'user_id'      => $user['id'],
            'user_email'   => $user['email'],
            'display_name' => $user['display_name'],
            'role'         => $user['role'] ?? 'user',
            'ai_enabled'   => (bool) ($user['ai_enabled'] ?? false),
        ]);
    }
}
