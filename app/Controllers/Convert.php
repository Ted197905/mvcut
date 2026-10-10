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

        $r = $this->queue($item, $params);
        return $this->response->setJSON(isset($r['media'])
            ? ['ok' => true, 'cached' => true, 'media' => $r['media']]
            : ['ok' => true, 'job' => $r['job']]);
    }

    /** POST /library/convert  ids[]=1&ids[]=2&format=mp4 - converts the selected videos at original size */
    public function bulk()
    {
        $userId = (int) session()->get('user_id');
        $format = strtolower((string) $this->request->getPost('format'));
        $back   = site_url('library') . (($qs = (string) session()->get('library_back')) !== '' ? '?' . $qs : '');
        if (! in_array($format, self::VIDEO_FORMATS, true)) return redirect()->to($back)->with('flash', '지원하지 않는 포맷입니다.');
        $ids = array_values(array_filter(array_map('intval', (array) $this->request->getPost('ids')), static fn ($i) => $i > 0));
        $rows = $ids === [] ? [] : (new MediaModel())->whereIn('id', $ids)->where('user_id', $userId)->findAll();
        $drop   = (bool) $this->request->getPost('delete_source');
        $queued = $cached = $skipped = $removed = 0;
        foreach ($rows as $item) {
            if ($item['media_type'] !== 'video') { $skipped++; continue; }
            $r = $this->queue($item, ['format' => $format, 'height' => 0, 'quality' => 'high'], $drop);
            if (isset($r['media'])) {
                $cached++;
                if ($drop && (new MediaModel())->removeSource($item)) $removed++;
            } else {
                $queued++;
            }
        }
        $msg = strtoupper($format) . ' 변환: ' . $queued . '개 작업 등록';
        if ($cached)  $msg .= ', ' . $cached . '개는 이미 변환됨' . ($removed ? '(원본 ' . $removed . '개 삭제)' : '');
        if ($skipped) $msg .= ', 영상이 아닌 ' . $skipped . '개 제외';
        if ($drop && $queued) $msg .= '. 원본은 변환이 끝나면 삭제됩니다';
        return redirect()->to($back)->with('flash', $msg . '. 완료되면 라이브러리에 결과가 추가됩니다.');
    }

    /** Existing result for the same settings (['media' => row]) or a new queued job (['job' => row]). */
    private function queue(array $item, array $params, bool $dropSource = false): array
    {
        $media  = new MediaModel();
        $key    = json_encode(['convert' => $params]);
        $cached = $media->where('parent_id', $item['id'])->where('source', 'convert')->where('status', 'ready')->where('edit_params', $key)->first();
        if ($cached) {
            if ($cached['category'] !== $item['category']) {
                $media->update($cached['id'], ['category' => $item['category']]);
                $cached['category'] = $item['category'];
            }
            return ['media' => $cached];
        }
        $jobs  = new JobModel();
        $jobId = $jobs->insert(['user_id' => $item['user_id'], 'media_id' => $item['id'], 'type' => 'convert',
                                'params' => json_encode($params + ($dropSource ? ['delete_source' => true] : [])), 'status' => 'queued']);
        return ['job' => $jobs->find($jobId)];
    }
}
