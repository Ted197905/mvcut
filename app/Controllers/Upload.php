<?php

namespace App\Controllers;

use App\Libraries\MediaIntake;

/**
 * Chunked upload. The browser slices the file and posts fixed-size parts, so a
 * single request never carries more than CHUNK_MAX bytes and an interrupted
 * upload can resume from the last stored part.
 */
class Upload extends BaseController
{
    private const CHUNK_MAX  = 16 * 1024 * 1024;        // hard cap per part
    private const TOTAL_MAX  = 4 * 1024 * 1024 * 1024;  // per file
    private const TTL        = 86400;

    private function tmpDir(string $uploadId): string
    {
        return WRITEPATH . 'chunks/' . (int) session()->get('user_id') . '/' . $uploadId;
    }

    private static function validId(string $id): bool
    {
        return (bool) preg_match('/^[a-f0-9]{32}$/', $id);
    }

    /** POST /api/upload/init {name, size} */
    public function init()
    {
        $in   = $this->request->getJSON(true) ?: [];
        $name = (string) ($in['name'] ?? '');
        $size = (int) ($in['size'] ?? 0);
        $ext  = MediaIntake::extOf($name);
        if (! MediaIntake::allowed($ext)) {
            return $this->response->setStatusCode(415)->setJSON(['error' => '지원하지 않는 파일 형식입니다: .' . $ext]);
        }
        if ($size <= 0 || $size > self::TOTAL_MAX) {
            return $this->response->setStatusCode(413)->setJSON(['error' => '파일 크기가 허용 범위를 벗어났습니다.']);
        }
        $uploadId = bin2hex(random_bytes(16));
        $dir = $this->tmpDir($uploadId);
        if (! mkdir($dir, 0775, true)) {
            return $this->response->setStatusCode(500)->setJSON(['error' => '임시 디렉토리를 만들 수 없습니다.']);
        }
        file_put_contents($dir . '/meta.json', json_encode(['name' => $name, 'size' => $size, 'ext' => $ext]));
        return $this->response->setJSON(['ok' => true, 'uploadId' => $uploadId, 'chunkSize' => 8 * 1024 * 1024, 'received' => []]);
    }

    /** POST /api/upload/chunk  multipart: uploadId, index, chunk */
    public function chunk()
    {
        $uploadId = (string) $this->request->getPost('uploadId');
        $index    = (int) $this->request->getPost('index');
        if (! self::validId($uploadId) || $index < 0 || $index > 100000) {
            return $this->response->setStatusCode(400)->setJSON(['error' => 'bad request']);
        }
        $dir = $this->tmpDir($uploadId);
        if (! is_dir($dir)) {
            return $this->response->setStatusCode(404)->setJSON(['error' => '업로드 세션이 없습니다. 다시 시도하세요.']);
        }
        $file = $this->request->getFile('chunk');
        if (! $file || ! $file->isValid()) {
            return $this->response->setStatusCode(400)->setJSON(['error' => $file ? $file->getErrorString() : '조각이 없습니다.']);
        }
        if ($file->getSize() > self::CHUNK_MAX) {
            return $this->response->setStatusCode(413)->setJSON(['error' => '조각이 너무 큽니다.']);
        }
        $file->move($dir, sprintf('%06d.part', $index), true);
        return $this->response->setJSON(['ok' => true, 'index' => $index]);
    }

    /** POST /api/upload/finish {uploadId, total} */
    public function finish()
    {
        $in       = $this->request->getJSON(true) ?: [];
        $uploadId = (string) ($in['uploadId'] ?? '');
        $total    = (int) ($in['total'] ?? 0);
        if (! self::validId($uploadId) || $total < 1) {
            return $this->response->setStatusCode(400)->setJSON(['error' => 'bad request']);
        }
        $dir  = $this->tmpDir($uploadId);
        $meta = @json_decode((string) @file_get_contents($dir . '/meta.json'), true);
        if (! is_dir($dir) || ! is_array($meta)) {
            return $this->response->setStatusCode(404)->setJSON(['error' => '업로드 세션이 없습니다.']);
        }
        $parts = [];
        for ($i = 0; $i < $total; $i++) {
            $p = $dir . '/' . sprintf('%06d.part', $i);
            if (! is_file($p)) {
                MediaIntake::removeDir($dir);
                return $this->response->setStatusCode(422)->setJSON(['error' => '누락된 조각이 있습니다 (' . $i . ').']);
            }
            $parts[] = $p;
        }
        try {
            [$id, , $target] = MediaIntake::create((int) session()->get('user_id'), $meta['name'], $meta['ext'], (int) $meta['size']);
        } catch (\RuntimeException $e) {
            MediaIntake::removeDir($dir);
            return $this->response->setStatusCode(500)->setJSON(['error' => $e->getMessage()]);
        }
        $out = fopen($target, 'wb');
        foreach ($parts as $p) {
            $in_ = fopen($p, 'rb');
            stream_copy_to_stream($in_, $out);
            fclose($in_);
        }
        fclose($out);
        MediaIntake::removeDir($dir);
        try {
            $item = MediaIntake::finish($id);
        } catch (\RuntimeException $e) {
            return $this->response->setStatusCode(500)->setJSON(['error' => $e->getMessage()]);
        }
        return $this->response->setJSON(['ok' => true, 'item' => $item]);
    }

    /** POST /api/upload/abort {uploadId} */
    public function abort()
    {
        $in = $this->request->getJSON(true) ?: [];
        $id = (string) ($in['uploadId'] ?? '');
        if (self::validId($id)) MediaIntake::removeDir($this->tmpDir($id));
        return $this->response->setJSON(['ok' => true]);
    }
}
