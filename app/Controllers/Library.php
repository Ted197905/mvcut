<?php

namespace App\Controllers;

use App\Libraries\Ffmpeg;
use App\Libraries\MediaIntake;
use App\Libraries\MediaSupport;
use App\Models\JobModel;
use App\Models\MediaModel;

class Library extends BaseController
{
    public function index()
    {
        $userId = (int) session()->get('user_id');
        $q      = trim((string) $this->request->getGet('q'));
        $sort   = (string) $this->request->getGet('sort');
        $kind   = (string) $this->request->getGet('kind');

        $media   = new MediaModel();
        $builder = $media->where('user_id', $userId);
        if ($q !== '') {
            $builder->groupStart()->like('title', $q)->orLike('source_url', $q)->groupEnd();
        }
        match ($kind) {
            'original' => $builder->where('kind', 'original'),
            'result'   => $builder->where('kind', 'result'),
            'video'    => $builder->where('media_type', 'video'),
            'image'    => $builder->where('media_type', 'image'),
            default    => null,
        };
        match ($sort) {
            'oldest'  => $builder->orderBy('id', 'ASC'),
            'largest' => $builder->orderBy('size', 'DESC'),
            'longest' => $builder->orderBy('duration', 'DESC'),
            'title'   => $builder->orderBy('title', 'ASC'),
            default   => $builder->orderBy('id', 'DESC'),
        };

        return view('library/index', [
            'title'  => '라이브러리',
            'items'  => $builder->findAll(),
            'ffmpeg' => Ffmpeg::available(),
            'q'      => $q,
            'sort'   => $sort ?: 'newest',
            'kind'   => $kind ?: 'all',
            'total'  => $media->where('user_id', $userId)->countAllResults(),
        ]);
    }

    public function upload()
    {
        $file = $this->request->getFile('file');
        if (! $file || ! $file->isValid()) {
            return $this->response->setStatusCode(400)->setJSON(['error' => $file ? $file->getErrorString() : '파일이 없습니다.']);
        }
        $ext = MediaIntake::extOf($file->getClientName()) ?: strtolower((string) $file->guessExtension());
        if (! MediaIntake::allowed($ext)) {
            return $this->response->setStatusCode(415)->setJSON(['error' => '지원하지 않는 파일 형식입니다: .' . $ext]);
        }
        try {
            [$id, $dir] = MediaIntake::create((int) session()->get('user_id'), $file->getClientName(), $ext, $file->getSize(), $file->getClientMimeType());
            $file->move($dir, 'original.' . $ext, true);
            return $this->response->setJSON(['ok' => true, 'item' => MediaIntake::finish($id)]);
        } catch (\RuntimeException $e) {
            return $this->response->setStatusCode(500)->setJSON(['error' => $e->getMessage()]);
        }
    }

    public function show(int $id)
    {
        $media = new MediaModel();
        $item  = $media->findOwned($id, (int) session()->get('user_id'));
        if (! $item) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }
        $playable = MediaSupport::browserPlayable($item) || (bool) $item['has_proxy'];
        $pending  = (new JobModel())->where('media_id', $id)->whereIn('status', ['queued', 'running'])->orderBy('id', 'DESC')->first();
        return view('library/show', ['title' => $item['title'], 'item' => $item, 'playable' => $playable, 'pending' => $pending]);
    }

    public function delete(int $id)
    {
        $this->removeOwned([$id]);
        return redirect()->to('/library')->with('flash', '삭제했습니다.');
    }

    /** POST /library/delete  ids[]=1&ids[]=2 */
    public function bulkDelete()
    {
        $ids = array_map('intval', (array) $this->request->getPost('ids'));
        $n   = $this->removeOwned($ids);
        return redirect()->to('/library')->with('flash', $n . '개를 삭제했습니다.');
    }

    private function removeOwned(array $ids): int
    {
        $ids = array_values(array_filter(array_unique($ids), static fn ($i) => $i > 0));
        if ($ids === []) return 0;
        $media  = new MediaModel();
        $userId = (int) session()->get('user_id');
        $rows   = $media->whereIn('id', $ids)->where('user_id', $userId)->findAll();
        foreach ($rows as $row) {
            MediaIntake::removeDir(MediaModel::dir($row));
            $media->delete($row['id']);
        }
        return count($rows);
    }
}
