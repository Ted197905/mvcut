<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class AuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $id = (int) session()->get('user_id');
        if ($id > 0) {
            // an admin may have blocked or changed the account since login
            $user = (new \App\Models\UserModel())->find($id);
            if ($user && ($user['status'] ?? 'active') === 'active') {
                session()->set(['role' => $user['role'] ?? 'user', 'ai_enabled' => (bool) $user['ai_enabled']]);
                return;
            }
            session()->destroy();
        }
        if ($request->isAJAX() || str_starts_with($request->getUri()->getPath(), 'api/')) {
            return service('response')->setStatusCode(401)->setJSON(['error' => 'unauthorized']);
        }
        session()->set('redirect_after_login', current_url());
        return redirect()->to('/login');
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}
