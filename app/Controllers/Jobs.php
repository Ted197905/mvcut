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

    /**
     * Edit/convert/import jobs still waiting or running, oldest first (the worker's order), with
     * the source title. Imports include the ones the browser extension's SAVE button queued.
     */
    private function active()
    {
        // failed jobs of the last week stay listed until the user retries or dismisses them
        $rows = db_connect()->table('jobs j')
            ->select('j.id, j.type, j.status, j.progress, j.params, j.media_id, j.error, m.title')
            ->join('media m', 'm.id = j.media_id', 'left')
            ->where('j.user_id', (int) session()->get('user_id'))
            ->whereIn('j.type', ['edit', 'convert', 'import'])
            ->groupStart()->whereIn('j.status', ['queued', 'running', 'cancelled'])
                ->orGroupStart()->where('j.status', 'failed')->where('j.updated_at >=', date('Y-m-d H:i:s', time() - 7 * 86400))->groupEnd()
            ->groupEnd()
            ->orderBy('j.id', 'ASC')->get()->getResultArray();
        return $this->response->setJSON(['jobs' => $rows]);
    }

    /**
     * POST /api/jobs/{id}/cancel - 중단. A queued job is simply removed; a running one is flagged
     * 'cancelled', the worker kills its process and leaves it as failed (재시도 / 삭제 then apply).
     */
    public function cancel(int $id)
    {
        $jobs = new JobModel();
        $job  = $jobs->where('id', $id)->where('user_id', (int) session()->get('user_id'))->whereIn('status', ['queued', 'running', 'cancelled'])->first();
        if (! $job) return $this->response->setStatusCode(404)->setJSON(['error' => 'not found']);
        if ($job['status'] === 'queued') { $jobs->delete($id); return $this->response->setJSON(['ok' => true, 'removed' => true]); }
        $jobs->update($id, ['status' => 'cancelled']);
        return $this->response->setJSON(['ok' => true]);
    }

    /** POST /api/jobs/{id}/retry - queues a failed job again (as a new job) and drops the failed one. */
    public function retry(int $id)
    {
        $jobs = new JobModel();
        $job  = $jobs->where('id', $id)->where('user_id', (int) session()->get('user_id'))->whereIn('status', ['failed', 'cancelled'])->first();
        if (! $job) return $this->response->setStatusCode(404)->setJSON(['error' => 'not found']);
        // an import creates its media row while running, so the old (deleted) one is not carried over
        $mediaId = $job['type'] === 'import' ? null : $job['media_id'];
        $newId = $jobs->insert(['user_id' => $job['user_id'], 'media_id' => $mediaId, 'type' => $job['type'],
                                'params' => $job['params'], 'status' => 'queued']);
        $jobs->delete($id);
        return $this->response->setJSON(['ok' => true, 'job' => (int) $newId]);
    }

    /** POST /api/jobs/{id}/dismiss - removes a failed job from the list. */
    public function dismiss(int $id)
    {
        $jobs = new JobModel();
        $job  = $jobs->where('id', $id)->where('user_id', (int) session()->get('user_id'))->whereIn('status', ['failed', 'cancelled'])->first();
        if (! $job) return $this->response->setStatusCode(404)->setJSON(['error' => 'not found']);
        $jobs->delete($id);
        return $this->response->setJSON(['ok' => true]);
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
