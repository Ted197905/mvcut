<?php

namespace App\Controllers;

use App\Models\JobModel;

class Jobs extends BaseController
{
    public function index()
    {
        if ($this->request->getGet('active') !== null) return $this->active();
        $jobs = new JobModel();
        $rows = $jobs->where('user_id', (int) session()->get('user_id'))->orderBy('id', 'DESC')->findAll(20);
        return $this->response->setJSON(['jobs' => $rows]);
    }

    /** Edit/convert jobs still waiting or running, oldest first (the worker's order), with the source title. */
    private function active()
    {
        $rows = db_connect()->table('jobs j')
            ->select('j.id, j.type, j.status, j.progress, j.params, j.media_id, m.title')
            ->join('media m', 'm.id = j.media_id', 'left')
            ->where('j.user_id', (int) session()->get('user_id'))
            ->whereIn('j.type', ['edit', 'convert'])->whereIn('j.status', ['queued', 'running'])
            ->orderBy('j.id', 'ASC')->get()->getResultArray();
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
