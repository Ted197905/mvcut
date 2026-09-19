<?php

namespace App\Controllers;

use App\Models\MediaModel;
use CodeIgniter\Exceptions\PageNotFoundException;

/**
 * Serves files from writable/media via nginx X-Accel-Redirect so that nginx
 * handles Range requests (video seeking) and streaming efficiently.
 * Requires in nginx:  location /internal-media/ { internal; alias /var/www/mvcut/writable/media/; }
 */
class Media extends BaseController
{
    private function owned(int $id): array
    {
        $item = (new MediaModel())->findOwned($id, (int) session()->get('user_id'));
        if (! $item) {
            throw PageNotFoundException::forPageNotFound();
        }
        return $item;
    }

    private function accel(array $item, string $file, string $contentType, ?string $downloadName = null)
    {
        $path = MediaModel::dir($item) . '/' . $file;
        if (! is_file($path)) {
            throw PageNotFoundException::forPageNotFound();
        }
        $resp = $this->response
            ->setHeader('X-Accel-Redirect', '/internal-media/' . $item['user_id'] . '/' . $item['id'] . '/' . $file)
            ->setHeader('Content-Type', $contentType)
            ->setHeader('Cache-Control', 'private, max-age=3600');
        if ($downloadName !== null) {
            $resp->setHeader('Content-Disposition', 'attachment; filename="' . rawurlencode($downloadName) . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
        }
        return $resp;
    }

    /** GET /api/media/{id} */
    public function info(int $id)
    {
        $item = $this->owned($id);
        $item['playable'] = \App\Libraries\MediaSupport::browserPlayable($item) || (bool) $item['has_proxy'];
        return $this->response->setJSON(['media' => $item]);
    }

    /** GET /fonts/{key} - serves a watermark font for the editor preview. */
    public function font(string $key)
    {
        $path = \App\Libraries\Fonts::path($key);
        if (! $path) {
            throw PageNotFoundException::forPageNotFound();
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $type = match ($ext) { 'otf' => 'font/otf', 'woff' => 'font/woff', 'woff2' => 'font/woff2', default => 'font/ttf' };
        return $this->response
            ->setHeader('Content-Type', $type)
            ->setHeader('Cache-Control', 'private, max-age=604800, immutable')
            ->setBody(file_get_contents($path));
    }

    /** POST /api/media/{id}/rename {title} */
    public function rename(int $id)
    {
        $item  = $this->owned($id);
        $title = trim((string) (($this->request->getJSON(true) ?: [])['title'] ?? ''));
        $title = preg_replace('/\s+/u', ' ', $title);
        if ($title === '') {
            return $this->response->setStatusCode(422)->setJSON(['error' => '제목을 입력하세요.']);
        }
        $title = mb_substr($title, 0, 255);
        (new MediaModel())->update($item['id'], ['title' => $title]);
        return $this->response->setJSON(['ok' => true, 'title' => $title]);
    }

    public function thumb(int $id)
    {
        $item = $this->owned($id);
        if (! $item['has_thumb']) {
            throw PageNotFoundException::forPageNotFound();
        }
        return $this->accel($item, 'thumb.jpg', 'image/jpeg');
    }

    public function file(int $id)
    {
        $item = $this->owned($id);
        $name = $this->request->getGet('dl') !== null
            ? $item['title'] . '.' . pathinfo($item['filename'], PATHINFO_EXTENSION)
            : null;
        return $this->accel($item, $item['filename'], $item['mime'] ?: 'application/octet-stream', $name);
    }

    public function strip(int $id)
    {
        $item = $this->owned($id);
        $dir  = MediaModel::dir($item);
        if (! is_file($dir . '/strip2.jpg') && $item['media_type'] === 'video' && $item['duration']) {
            \App\Libraries\Ffmpeg::filmstrip($dir . '/' . $item['filename'], $dir . '/strip2.jpg', (float) $item['duration']);
        }
        return $this->accel($item, 'strip2.jpg', 'image/jpeg');
    }

    public function proxy(int $id)
    {
        $item = $this->owned($id);
        if (! $item['has_proxy']) {
            return $this->file($id);
        }
        return $this->accel($item, 'proxy.mp4', 'video/mp4');
    }
}
