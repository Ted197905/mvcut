<?php

namespace App\Controllers;

use App\Models\UserModel;

class Account extends BaseController
{
    public function edit()
    {
        $users = new UserModel();
        return view('account/edit', [
            'title' => '계정',
            'user'  => $users->find(session()->get('user_id')),
        ]);
    }

    public function update()
    {
        $users  = new UserModel();
        $userId = (int) session()->get('user_id');
        $user   = $users->find($userId);

        $rules = ['display_name' => 'required|min_length[2]|max_length[60]'];
        $newPassword = (string) $this->request->getPost('new_password');
        if ($newPassword !== '') {
            $rules['current_password']    = 'required';
            $rules['new_password']        = 'min_length[8]|max_length[200]';
            $rules['new_password_confirm'] = 'matches[new_password]';
        }
        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $data = ['display_name' => trim($this->request->getPost('display_name'))];
        if ($newPassword !== '') {
            if (! password_verify($this->request->getPost('current_password'), $user['password_hash'])) {
                return redirect()->back()->withInput()->with('errors', ['current_password' => '현재 비밀번호가 올바르지 않습니다.']);
            }
            $data['password_hash'] = UserModel::hash($newPassword);
        }
        $users->update($userId, $data);
        session()->set('display_name', $data['display_name']);
        return redirect()->to('/account')->with('flash', '저장했습니다.');
    }
}
