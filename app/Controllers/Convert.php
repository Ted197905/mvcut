<?php

namespace App\Controllers;

use App\Models\JobModel;
use App\Models\MediaModel;

class Convert extends BaseController
{
    private const VIDEO_FORMATS = ['mp4', 'webm', 'gif'];
    private const IMAGE_FORMATS = ['jpg', 'png', 'webp'];

    /** POST /api/convert/{id}  {format, height?, quality?} -> convert job (or cached result) */
    public function submit(int $id)
    {
        $userId = (int) session()->get('user_id');
        $media  = new MediaModel();
        $item   = $media->findOwned($id, $userId);
        if (! $item) return $this->response->setStatusCode(404)->setJSON(['error' => 'not found']);
        $in = $this->request->getJSON(true) ?: [];
        $format = strtolower((string) ($in['format'] ?? ''));
        $isImage = $item['media_type'] === 'image';
        if (! in_array($format, $isImage ? self::IMAGE_FORMATS : self::VIDEO_FORMATS, true)) {
            return $this->response->setStatusCode(422)->setJSON(['error' => '지원하지 않는 포맷입니다.']);
        }
        if ($isImage) {
            // images: long edge in pixels (0 = keep original) and a JPEG quality percentage
            $long = (int) ($in['long'] ?? 0);
            $max  = max((int) $item['width'], (int) $item['height']);
            if ($long < 16 || ($max > 0 && $long >= $max)) $long = 0;
            $long = min($long, 20000);
            $params = ['format' => $format, 'long' => $long];
            if ($format === 'jpg') {
                $pct = (int) ($in['quality'] ?? 60);
                $params['quality'] = max(10, min(100, $pct));
            }
        } else {
            $height = (int) ($in['height'] ?? 0);
            if (! in_array($height, [0, 1080, 720, 480, 360], true)) $height = 0;
            $quality = ($in['quality'] ?? 'high') === 'medium' ? 'medium' : 'high';
            $params  = ['format' => $format, 'height' => $height, 'quality' => $quality];
        }

        // cached?
        $key = json_encode(['convert' => $params]);
        $cached = $media->where('parent_id', $item['id'])->where('source', 'convert')->where('status', 'ready')->where('edit_params', $key)->first();
        if ($cached) {
            return $this->response->setJSON(['ok' => true, 'cached' => true, 'media' => $cached]);
        }
        $jobs  = new JobModel();
        $jobId = $jobs->insert(['user_id' => $userId, 'media_id' => $item['id'], 'type' => 'convert', 'params' => json_encode($params), 'status' => 'queued']);
        return $this->response->setJSON(['ok' => true, 'job' => $jobs->find($jobId)]);
    }
}
