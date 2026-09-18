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
        return view('edit/index', [
            'title'  => '편집: ' . $item['title'],
            'item'   => $item,
            'params' => $params,
        ]);
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
