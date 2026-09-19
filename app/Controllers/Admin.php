<?php

namespace App\Controllers;

use App\Models\JobModel;
use App\Models\MediaModel;
use App\Models\UserModel;

/** Account management: approve sign-ups, block accounts, allow the AI features. */
class Admin extends BaseController
{
    public function index()
    {
        $users = (new UserModel())->orderBy('status = "pending"', 'DESC', false)
                                  ->orderBy('id', 'ASC')->findAll();
        $db    = db_connect();
        $media = $db->table('media')->select('user_id, COUNT(*) AS n')->groupBy('user_id')->get()->getResultArray();
        $jobs  = $db->table('jobs')->select('user_id, COUNT(*) AS n')->groupBy('user_id')->get()->getResultArray();

        return view('admin/index', [
            'title'   => '관리자',
            'users'   => $users,
            'media'   => array_column($media, 'n', 'user_id'),
            'jobs'    => array_column($jobs, 'n', 'user_id'),
            'pending' => count(array_filter($users, static fn ($u) => $u['status'] === 'pending')),
        ]);
    }

    /** POST /admin/users/{id}  action=approve|block|activate|ai_on|ai_off|make_admin|drop_admin */
    public function update(int $id)
    {
        $users = new UserModel();
        $user  = $users->find($id);
        if (! $user) return redirect()->to('/admin')->with('flash', '없는 계정입니다.');

        $me     = (int) session()->get('user_id');
        $action = (string) $this->request->getPost('action');
        // the last admin must stay an admin, and nobody locks themselves out
        $admins = $users->where('role', 'admin')->where('status', 'active')->countAllResults();
        if ($id === $me && in_array($action, ['block', 'drop_admin'], true)) {
            return redirect()->to('/admin')->with('flash', '본인 계정에는 적용할 수 없습니다.');
        }
        if ($admins <= 1 && $user['role'] === 'admin' && in_array($action, ['block', 'drop_admin'], true)) {
            return redirect()->to('/admin')->with('flash', '마지막 관리자는 해제할 수 없습니다.');
        }

        $set = match ($action) {
            'approve'    => ['status' => 'active'],
            'activate'   => ['status' => 'active'],
            'block'      => ['status' => 'blocked'],
            'ai_on'      => ['ai_enabled' => 1],
            'ai_off'     => ['ai_enabled' => 0],
            'make_admin' => ['role' => 'admin'],
            'drop_admin' => ['role' => 'user'],
            default      => null,
        };
        if ($set === null) return redirect()->to('/admin')->with('flash', '알 수 없는 요청입니다.');

        $users->update($id, $set);
        $what = match ($action) {
            'approve'    => '승인했습니다',
            'activate'   => '사용 재개했습니다',
            'block'      => '사용을 중지했습니다',
            'ai_on'      => 'AI 기능을 켰습니다',
            'ai_off'     => 'AI 기능을 껐습니다',
            'make_admin' => '관리자로 지정했습니다',
            'drop_admin' => '관리자를 해제했습니다',
        };
        return redirect()->to('/admin')->with('flash', $user['email'] . ' - ' . $what . '.');
    }
}
