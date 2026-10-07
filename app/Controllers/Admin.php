<?php

namespace App\Controllers;

use App\Models\CategoryModel;
use App\Models\JobModel;
use App\Models\MediaModel;
use App\Models\UserModel;

/** Account management (approve sign-ups, block accounts, allow the AI features) and library categories. */
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
            'categories' => (new CategoryModel())->ordered(),
            'catUse'  => array_column($db->table('media')->select('category, COUNT(*) AS n')->where('category !=', '')
                                         ->groupBy('category')->get()->getResultArray(), 'n', 'category'),
        ]);
    }

    /** POST /admin/categories  name=... */
    public function addCategory()
    {
        $name = CategoryModel::clean((string) $this->request->getPost('name'), $err);
        $cats = new CategoryModel();
        if (! $err && $cats->where('name', $name)->first()) $err = '이미 있는 카테고리입니다.';
        if ($err) return redirect()->to('/admin')->with('flash', $err);
        $max = (int) ($cats->selectMax('sort')->first()['sort'] ?? 0);
        $cats->insert(['name' => $name, 'sort' => $max + 1]);
        return redirect()->to('/admin')->with('flash', '카테고리 "' . $name . '"을(를) 추가했습니다.');
    }

    /** POST /admin/categories/{id}  action=rename (name=...) | delete | up | down */
    public function category(int $id)
    {
        $cats = new CategoryModel();
        $row  = $cats->find($id);
        if (! $row) return redirect()->to('/admin')->with('flash', '없는 카테고리입니다.');
        $media  = db_connect()->table('media');
        $action = (string) $this->request->getPost('action');

        if ($action === 'rename') {
            $name = CategoryModel::clean((string) $this->request->getPost('name'), $err);
            if (! $err && $name !== $row['name'] && $cats->where('name', $name)->first()) $err = '이미 있는 카테고리입니다.';
            if ($err) return redirect()->to('/admin')->with('flash', $err);
            if ($name === $row['name']) return redirect()->to('/admin');
            $cats->update($id, ['name' => $name]);
            $media->where('category', $row['name'])->update(['category' => $name]);
            $this->renamePrefs($row['name'], $name);
            return redirect()->to('/admin')->with('flash', '카테고리 "' . $row['name'] . '"을(를) "' . $name . '"(으)로 바꿨습니다.');
        }
        if ($action === 'delete') {
            $cats->delete($id);
            $media->where('category', $row['name'])->update(['category' => '']);
            $this->renamePrefs($row['name'], null);
            return redirect()->to('/admin')->with('flash', '카테고리 "' . $row['name'] . '"을(를) 삭제했습니다. 해당 미디어는 미지정으로 바뀝니다.');
        }
        if ($action === 'up' || $action === 'down') {
            $list = $cats->ordered();
            $pos  = array_search($id, array_map('intval', array_column($list, 'id')), true);
            $to   = $action === 'up' ? $pos - 1 : $pos + 1;
            if ($pos !== false && isset($list[$to])) {
                [$list[$pos], $list[$to]] = [$list[$to], $list[$pos]];
                foreach ($list as $i => $c) $cats->update($c['id'], ['sort' => $i + 1]);
            }
            return redirect()->to('/admin');
        }
        return redirect()->to('/admin')->with('flash', '알 수 없는 요청입니다.');
    }

    /** Accounts whose remembered library filter is the old name follow the rename (null = back to ALL). */
    private function renamePrefs(string $old, ?string $new): void
    {
        $users = new UserModel();
        foreach ($users->select('id, prefs')->like('prefs', 'library_category')->findAll() as $u) {
            if (($users->prefs((int) $u['id'])['library_category'] ?? null) === $old) {
                $users->savePref((int) $u['id'], 'library_category', $new);
            }
        }
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

        // rejecting a sign-up removes it, but only while the account owns nothing
        if ($action === 'delete') {
            $db = db_connect();
            $has = $db->table('media')->where('user_id', $id)->countAllResults()
                 + $db->table('jobs')->where('user_id', $id)->countAllResults();
            if ($id === $me || $user['status'] === 'active' || $has > 0) {
                return redirect()->to('/admin')->with('flash', '사용한 적이 있거나 사용 중인 계정은 삭제할 수 없습니다. 사용 중지를 쓰세요.');
            }
            $users->delete($id);
            return redirect()->to('/admin')->with('flash', $user['email'] . ' - 가입을 거절하고 삭제했습니다.');
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
