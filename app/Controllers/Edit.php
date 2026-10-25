<?php

namespace App\Controllers;

use App\Libraries\EditParams;
use App\Models\JobModel;
use App\Models\MediaModel;
use CodeIgniter\Exceptions\PageNotFoundException;

class Edit extends BaseController
{
    public function index(int $id)
    {
        $media = new MediaModel();
        $item  = $media->findOwned($id, (int) session()->get('user_id'));
        if (! $item || $item['media_type'] !== 'video') {
            throw PageNotFoundException::forPageNotFound();
        }
        $params = $item['edit_params'] ? json_decode($item['edit_params'], true) : null;
        $prefs  = (new \App\Models\UserModel())->prefs((int) session()->get('user_id'));
        return view('edit/index', [
            'title'    => '편집',
            'item'     => $item,
            'params'   => $params,
            'wmPreset' => $prefs['watermark'] ?? null,
            'aiEnabled' => (bool) session()->get('ai_enabled'),
            'fonts'    => \App\Libraries\Fonts::available(),
            'bgm'      => (new \App\Models\BgmModel())->ordered(),
        ]);
    }

    /** GET /bgm/{id}/file - streams a background track for preview (any signed-in user). */
    public function bgmFile(int $id)
    {
        $row = (new \App\Models\BgmModel())->find($id);
        $path = $row ? \App\Models\BgmModel::path($row) : '';
        if (! $row || ! is_file($path)) throw PageNotFoundException::forPageNotFound();
        return $this->response->setHeader('Content-Type', mime_content_type($path) ?: 'audio/mpeg')
            ->setHeader('Cache-Control', 'private, max-age=86400')->setHeader('Content-Length', (string) filesize($path))
            ->setBody((string) file_get_contents($path));
    }

    /** POST /api/edit/{id}/autosub  {"lang": "auto|en|ja|zh|vi|..."} -> queues an 'autosub' job on the source video. */
    public function autosub(int $id)
    {
        $userId = (int) session()->get('user_id');
        $item   = (new MediaModel())->findOwned($id, $userId);
        if (! $item || $item['media_type'] !== 'video') {
            return $this->response->setStatusCode(404)->setJSON(['error' => 'not found']);
        }
        if (! session()->get('ai_enabled')) {
            return $this->response->setStatusCode(403)->setJSON(['error' => 'AI 기능은 관리자 승인이 필요합니다. 관리자에게 문의하세요.']);
        }
        $raw  = $this->request->getJSON(true) ?: [];
        $lang = preg_match('/^[a-z]{2,3}$/', (string) ($raw['lang'] ?? '')) ? $raw['lang'] : 'auto';
        $jobs = new JobModel();
        // one at a time per video
        $dup = $jobs->where('user_id', $userId)->where('media_id', $item['id'])->where('type', 'autosub')
            ->whereIn('status', ['queued', 'running'])->first();
        if ($dup) return $this->response->setJSON(['ok' => true, 'job' => (int) $dup['id']]);
        $jobId = $jobs->insert(['user_id' => $userId, 'media_id' => $item['id'], 'type' => 'autosub',
                                'params' => json_encode(['lang' => $lang]), 'status' => 'queued']);
        return $this->response->setJSON(['ok' => true, 'job' => (int) $jobId]);
    }

    /** GET /api/edit/{id}/autosub/{job} -> job state, and the cues once it is done. */
    public function autosubStatus(int $id, int $jobId)
    {
        $userId = (int) session()->get('user_id');
        $item   = (new MediaModel())->findOwned($id, $userId);
        $job    = $item ? (new JobModel())->where('id', $jobId)->where('user_id', $userId)->where('media_id', $item['id'])->first() : null;
        if (! $job) return $this->response->setStatusCode(404)->setJSON(['error' => 'not found']);
        $out = ['status' => $job['status'], 'progress' => (int) $job['progress'], 'error' => $job['error']];
        if ($job['status'] === 'done') {
            $f = MediaModel::dir($item) . '/autosub.json';
            $j = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
            $out['cues'] = $j['cues'] ?? [];
            $out['language'] = $j['language'] ?? '';
            $out['source'] = $j['source'] ?? '';
        }
        return $this->response->setJSON($out);
    }

    /** POST /api/edit/{id}  body: JSON edit params -> creates an 'edit' job. */
    public function submit(int $id)
    {
        $userId = (int) session()->get('user_id');
        $media  = new MediaModel();
        $item   = $media->findOwned($id, $userId);
        if (! $item || $item['media_type'] !== 'video') {
            return $this->response->setStatusCode(404)->setJSON(['error' => 'not found']);
        }
        $raw = $this->request->getJSON(true);
        if (! is_array($raw)) {
            return $this->response->setStatusCode(400)->setJSON(['error' => 'invalid json']);
        }
        try {
            $params = EditParams::normalize($raw, $item);
        } catch (\InvalidArgumentException $e) {
            return $this->response->setStatusCode(422)->setJSON(['error' => $e->getMessage()]);
        }
        // the GPU features run only for accounts an admin has allowed
        if (! session()->get('ai_enabled') && EditParams::usesAi($params)) {
            return $this->response->setStatusCode(403)->setJSON([
                'error' => 'AI 기능은 관리자 승인이 필요합니다. 관리자에게 문의하세요.']);
        }
        $jobs  = new JobModel();
        $jobId = $jobs->insert([
            'user_id'  => $userId,
            'media_id' => $item['id'],
            'type'     => 'edit',
            'params'   => json_encode($params, JSON_UNESCAPED_UNICODE),
            'status'   => 'queued',
        ]);
        return $this->response->setJSON(['ok' => true, 'job' => $jobs->find($jobId)]);
    }
}
