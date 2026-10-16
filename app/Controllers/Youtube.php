<?php

namespace App\Controllers;

use App\Libraries\Ollama;
use App\Libraries\MediaIntake;
use App\Libraries\MediaSupport;
use App\Libraries\YouTubeTool;
use App\Models\JobModel;
use App\Models\YtVideoModel;
use CodeIgniter\Exceptions\PageNotFoundException;

/** /youtube - public page (no login): download a YouTube video with its subtitles and summarize it. */
class Youtube extends BaseController
{
    private const PER_PAGE = 24;

    public function index()
    {
        $videos = new YtVideoModel();
        $q = trim((string) $this->request->getGet('q'));
        $builder = $videos->builder();
        if ($q !== '') {
            $builder->groupStart()->like('title', $q)->orLike('channel', $q)->orLike('cast', $q)->orLike('summary', $q)->groupEnd();
        }
        $matched = (clone $builder)->countAllResults();
        $pages   = max(1, (int) ceil($matched / self::PER_PAGE));
        $page    = min(max(1, (int) $this->request->getGet('page')), $pages);
        $items   = $builder->orderBy('id', 'DESC')->get(self::PER_PAGE, ($page - 1) * self::PER_PAGE)->getResultArray();
        return view('youtube/index', [
            'title' => 'YouTube', 'items' => $items, 'q' => $q, 'page' => $page, 'pages' => $pages, 'matched' => $matched,
            'ytdlp' => MediaSupport::ytdlp() !== null, 'ollama' => Ollama::available(),
        ]);
    }

    public function add()
    {
        $url = trim((string) $this->request->getPost('url'));
        $vid = $url !== '' ? YouTubeTool::videoId($url) : null;
        if (! $vid) {
            return redirect()->to(site_url('youtube'))->with('error', '유튜브 영상 링크가 아닙니다.');
        }
        $videos = new YtVideoModel();
        if ($row = $videos->where('video_id', $vid)->first()) {
            if ($row['status'] === 'failed') {
                $this->queue((int) $row['id'], []);
                $videos->update($row['id'], ['status' => 'queued', 'error' => null]);
                return redirect()->to(site_url('youtube/' . $row['id']))->with('flash', '다시 가져옵니다.');
            }
            return redirect()->to(site_url('youtube/' . $row['id']))->with('flash', '이미 가져온 영상입니다.');
        }
        $id = $videos->insert(['video_id' => $vid, 'url' => 'https://www.youtube.com/watch?v=' . $vid, 'status' => 'queued']);
        $this->queue((int) $id, []);
        return redirect()->to(site_url('youtube/' . $id));
    }

    private function queue(int $id, array $extra): void
    {
        (new JobModel())->insert(['user_id' => 0, 'type' => 'youtube', 'params' => json_encode(['yt_id' => $id] + $extra), 'status' => 'queued']);
    }

    private function row(int $id): array
    {
        $row = (new YtVideoModel())->find($id);
        if (! $row) throw PageNotFoundException::forPageNotFound();
        return $row;
    }

    public function show(int $id)
    {
        $row = (new YtVideoModel())->find($id);
        if (! $row) return redirect()->to(site_url('youtube'))->with('flash', '존재하지 않는 영상입니다.');
        return view('youtube/show', ['title' => $row['title'] ?: 'YouTube', 'item' => $row, 'subs' => YtVideoModel::subs($row),
                                     'cast' => YtVideoModel::cast($row), 'transcript' => YouTubeTool::transcriptSub($row)]);
    }

    /** GET /youtube/{id}/status - polled while the worker runs. */
    public function status(int $id)
    {
        $row = $this->row($id);
        $busy = in_array($row['status'], ['queued', 'downloading', 'analyzing'], true);
        $ahead = 0;
        if ($row['status'] === 'queued') {
            $ahead = (new JobModel())->where('status', 'queued')->where('type', 'youtube')
                ->where("params NOT LIKE '%\"yt_id\":{$id}%'", null, false)->countAllResults();
        }
        return $this->response->setJSON(['status' => $row['status'], 'busy' => $busy, 'ahead' => $ahead,
                                         'error' => $row['error'], 'title' => $row['title'], 'updated_at' => $row['updated_at']]);
    }

    public function analyze(int $id)
    {
        $row = $this->row($id);
        if (in_array($row['status'], ['queued', 'downloading', 'analyzing'], true)) {
            return redirect()->back()->with('error', '아직 처리 중입니다.');
        }
        if (! $row['filename']) {
            return redirect()->back()->with('error', '영상을 먼저 가져와야 합니다.');
        }
        (new YtVideoModel())->update($id, ['status' => 'analyzing', 'error' => null]);
        $this->queue($id, ['step' => 'analyze']);
        return redirect()->to(site_url('youtube/' . $id))->with('flash', '다시 분석합니다.');
    }

    public function retry(int $id)
    {
        $row = $this->row($id);
        if (in_array($row['status'], ['queued', 'downloading', 'analyzing'], true)) {
            return redirect()->back()->with('error', '아직 처리 중입니다.');
        }
        (new YtVideoModel())->update($id, ['status' => 'queued', 'error' => null]);
        $this->queue($id, []);
        return redirect()->to(site_url('youtube/' . $id))->with('flash', '다시 가져옵니다.');
    }

    public function delete(int $id)
    {
        $row = $this->row($id);
        MediaIntake::removeDir(YtVideoModel::dir($row));
        (new YtVideoModel())->delete($id);
        return redirect()->to(site_url('youtube'))->with('flash', '삭제했습니다.');
    }

    /** A file name the browser keeps: the title, minus what file systems reject. */
    private static function downloadName(array $row, string $suffix): string
    {
        $t = preg_replace('/[\\\\\/:*?"<>|\x00-\x1f]+/u', ' ', (string) $row['title']);
        $t = trim(preg_replace('/\s+/u', ' ', $t));
        if (mb_strlen($t) > 80) $t = rtrim(mb_substr($t, 0, 80));
        return ($t !== '' ? $t : $row['video_id']) . $suffix;
    }

    private function accel(array $row, string $file, string $contentType, ?string $downloadName = null)
    {
        $path = YtVideoModel::dir($row) . '/' . $file;
        if (! is_file($path)) throw PageNotFoundException::forPageNotFound();
        $resp = $this->response
            ->setHeader('X-Accel-Redirect', '/internal-media/yt/' . $row['id'] . '/' . $file)
            ->setHeader('Content-Type', $contentType)
            ->setHeader('Cache-Control', 'private, max-age=3600');
        if ($downloadName !== null) {
            $resp->setHeader('Content-Disposition', 'attachment; filename="' . rawurlencode($downloadName) . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
        }
        return $resp;
    }

    public function file(int $id)
    {
        $row = $this->row($id);
        if (! $row['filename']) throw PageNotFoundException::forPageNotFound();
        $ext = strtolower(pathinfo($row['filename'], PATHINFO_EXTENSION)) ?: 'mp4';
        return $this->accel($row, $row['filename'], $ext === 'mp4' ? 'video/mp4' : 'application/octet-stream',
                            $this->request->getGet('dl') !== null ? self::downloadName($row, '.' . $ext) : null);
    }

    public function thumb(int $id)
    {
        return $this->accel($this->row($id), 'thumb.jpg', 'image/jpeg');
    }

    /** GET /youtube/{id}/sub/{lang}.{vtt|srt|txt} */
    public function sub(int $id, string $lang, string $fmt)
    {
        $row = $this->row($id);
        $sub = null;
        foreach (YtVideoModel::subs($row) as $s) if ($s['lang'] === $lang) { $sub = $s; break; }
        if (! $sub) throw PageNotFoundException::forPageNotFound();
        $path = YtVideoModel::dir($row) . '/' . $sub['file'];
        if (! is_file($path)) throw PageNotFoundException::forPageNotFound();
        $vtt = (string) file_get_contents($path);
        [$body, $mime] = match ($fmt) {
            'srt'   => [YouTubeTool::vttToSrt($vtt), 'application/x-subrip'],
            'txt'   => [YouTubeTool::vttText($vtt), 'text/plain'],
            default => [$vtt, 'text/vtt'],
        };
        $fmt = in_array($fmt, ['srt', 'txt'], true) ? $fmt : 'vtt';
        $name = self::downloadName($row, '.' . $lang . '.' . $fmt);
        return $this->response->setHeader('Content-Type', $mime . '; charset=UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . rawurlencode($name) . '"; filename*=UTF-8\'\'' . rawurlencode($name))
            ->setBody($body);
    }
}
