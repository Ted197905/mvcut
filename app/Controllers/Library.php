<?php

namespace App\Controllers;

use App\Libraries\Ffmpeg;
use App\Libraries\MediaSupport;
use App\Models\JobModel;
use App\Models\MediaModel;

class Library extends BaseController
{
    private const ALLOWED_EXT = ['mp4', 'mov', 'm4v', 'mkv', 'webm', 'avi', 'wmv', 'flv', 'ts', 'mts', 'm2ts', '3gp', 'gif', 'jpg', 'jpeg', 'png', 'webp', 'heic', 'mp3', 'm4a', 'wav', 'aac'];

    public function index()
    {
        $media = new MediaModel();
        return view('library/index', [
            'title' => '라이브러리',
            'items' => $media->forUser((int) session()->get('user_id')),
            'ffmpeg' => Ffmpeg::available(),
        ]);
    }

    public function upload()
    {
        $file = $this->request->getFile('file');
        if (! $file || ! $file->isValid()) {
            return $this->response->setStatusCode(400)->setJSON(['error' => $file ? $file->getErrorString() : '파일이 없습니다.']);
        }
        $ext = strtolower($file->getClientExtension() ?: $file->guessExtension());
        if (! in_array($ext, self::ALLOWED_EXT, true)) {
            return $this->response->setStatusCode(415)->setJSON(['error' => '지원하지 않는 파일 형식입니다: .' . $ext]);
        }

        $userId = (int) session()->get('user_id');
        $media  = new MediaModel();
        $title  = pathinfo($file->getClientName(), PATHINFO_FILENAME) ?: 'untitled';
        $id = $media->insert([
            'user_id'    => $userId,
            'kind'       => 'original',
            'source'     => 'upload',
            'title'      => mb_substr($title, 0, 255),
            'filename'   => 'original.' . $ext,
            'mime'       => $file->getClientMimeType(),
            'size'       => $file->getSize(),
            'status'     => 'processing',
        ]);
        $row = $media->find($id);
        $dir = MediaModel::dir($row);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true)) {
            $media->delete($id);
            return $this->response->setStatusCode(500)->setJSON(['error' => '저장 디렉토리를 만들 수 없습니다.']);
        }
        $file->move($dir, 'original.' . $ext, true);
        $path = $dir . '/original.' . $ext;

        $update = ['status' => 'ready', 'mime' => mime_content_type($path) ?: $row['mime']];
        $meta = Ffmpeg::probe($path);
        if ($meta) {
            $update += $meta;
            if (in_array($meta['media_type'], ['video', 'image'], true) && Ffmpeg::thumbnail($path, $dir . '/thumb.jpg', $meta['duration'])) {
                $update['has_thumb'] = 1;
            }
            if ($meta['media_type'] === 'video' && $meta['duration']) {
                Ffmpeg::filmstrip($path, $dir . '/strip2.jpg', (float) $meta['duration']);
            }
        } else {
            $update['media_type'] = str_starts_with($update['mime'], 'video/') ? 'video'
                : (str_starts_with($update['mime'], 'image/') ? 'image'
                : (str_starts_with($update['mime'], 'audio/') ? 'audio' : 'unknown'));
        }
        $media->update($id, $update);
        $item = $media->find($id);
        if (MediaSupport::needsProxy($item)) {
            (new JobModel())->insert(['user_id' => $userId, 'media_id' => $id, 'type' => 'proxy', 'params' => '{}', 'status' => 'queued']);
        }
        return $this->response->setJSON(['ok' => true, 'item' => $item]);
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
        $media = new MediaModel();
        $item  = $media->findOwned($id, (int) session()->get('user_id'));
        if ($item) {
            $dir = MediaModel::dir($item);
            if (is_dir($dir)) {
                foreach (glob($dir . '/*') ?: [] as $f) @unlink($f);
                @rmdir($dir);
            }
            $media->delete($id);
        }
        return redirect()->to('/library')->with('flash', '삭제했습니다.');
    }
}
