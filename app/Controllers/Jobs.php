<?php

namespace App\Controllers;

use App\Models\JobModel;

class Jobs extends BaseController
{
    public function index()
    {
        $jobs = new JobModel();
        $rows = $jobs->where('user_id', (int) session()->get('user_id'))->orderBy('id', 'DESC')->findAll(20);
        return $this->response->setJSON(['jobs' => $rows]);
    }

    public function show(int $id)
    {
        $jobs = new JobModel();
        $job  = $jobs->where('id', $id)->where('user_id', (int) session()->get('user_id'))->first();
        if (! $job) {
            return $this->response->setStatusCode(404)->setJSON(['error' => 'not found']);
        }
        return $this->response->setJSON(['job' => $job]);
    }
}
