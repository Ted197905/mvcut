<?php

namespace App\Controllers;

class Home extends BaseController
{
    public function index(): string|\CodeIgniter\HTTP\RedirectResponse
    {
        if (session()->get('user_id')) {
            return redirect()->to('/library');
        }
        return view('home', ['title' => 'MV Cut']);
    }
}
