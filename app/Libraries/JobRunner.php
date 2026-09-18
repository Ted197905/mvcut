<?php

namespace App\Libraries;

use App\Libraries\MediaIntake;
use App\Libraries\MediaSupport;
use App\Models\JobModel;
use App\Models\MediaModel;

/** Executes one job row. Currently: type=edit. */
class JobRunner
{
    private JobModel $jobs;
    private MediaModel $media;

    public function __construct()
    {
        $this->jobs  = new JobModel();
        $this->media = new MediaModel();
    }

    /** Atomically claim the oldest queued job. Returns the row or null. */
    public function claim(): ?array
    {
        $db = $this->jobs->db;
        $db->query("UPDATE jobs SET id = LAST_INSERT_ID(id), status = 'running', started_at = NOW(), updated_at = NOW() WHERE status = 'queued' ORDER BY id ASC LIMIT 1");
        if ($db->affectedRows() < 1) {
            return null;
        }
        $id = (int) $db->query('SELECT LAST_INSERT_ID() AS id')->getRow()->id;
        return $this->jobs->find($id);
    }

    public function run(array $job, ?callable $log = null): void
    {
        $log ??= static function (string $m): void {};
        try {
            $result = match ($job['type']) {
                'edit'    => $this->runEdit($job, $log),
                'convert' => $this->runConvert($job, $log),
                'proxy'   => $this->runProxy($job, $log),
                'import'  => $this->runImport($job, $log),
                default   => throw new \RuntimeException('unknown job type ' . $job['type']),
            };
            $this->jobs->update($job['id'], ['status' => 'done', 'progress' => 100, 'result_media_id' => $result, 'finished_at' => date('Y-m-d H:i:s')]);
            $log("job {$job['id']} done -> media {$result}");
        } catch (\Throwable $e) {
            $this->jobs->update($job['id'], ['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 2000), 'finished_at' => date('Y-m-d H:i:s')]);
            $log("job {$job['id']} FAILED: " . $e->getMessage());
        }
    }

    private function runEdit(array $job, callable $log): int
    {
        $src = $this->media->find($job['media_id']);
        if (! $src) throw new \RuntimeException('source media missing');
        $p = EditParams::normalize(json_decode($job['params'], true) ?: [], $src);
        return $this->encode($job, $src, $p, 'edit', ' - edit', json_encode($p, JSON_UNESCAPED_UNICODE), $log);
    }

    private function runConvert(array $job, callable $log): int
    {
        $src = $this->media->find($job['media_id']);
        if (! $src) throw new \RuntimeException('source media missing');
        $c = json_decode($job['params'], true) ?: [];
        $key = json_encode(['convert' => $c]);
        if ($src['media_type'] === 'image') {
            return $this->convertImage($job, $src, $c, $key, $log);
        }
        $p = EditParams::normalize([
            'keep' => [[0, (float) $src['duration']]], 'crop' => null, 'masks' => [], 'speed' => 1, 'keepAudio' => true,
            'output' => ['format' => $c['format'] ?? 'mp4', 'height' => (int) ($c['height'] ?? 0), 'quality' => $c['quality'] ?? 'high'],
        ], $src);
        $suffix = ' - ' . strtoupper($p['output']['format']) . ($p['output']['height'] ? ' ' . $p['output']['height'] . 'p' : '');
        return $this->encode($job, $src, $p, 'convert', $suffix, $key, $log);
    }

    private function convertImage(array $job, array $src, array $c, string $key, callable $log): int
    {
        $fmt = in_array($c['format'] ?? '', ['jpg', 'png', 'webp'], true) ? $c['format'] : 'jpg';
        $srcPath = MediaModel::dir($src) . '/' . $src['filename'];
        $resultId = $this->media->insert([
            'user_id' => $src['user_id'], 'parent_id' => $src['id'], 'kind' => 'result', 'source' => 'convert',
            'title' => mb_substr($src['title'] . ' - ' . strtoupper($fmt), 0, 255), 'filename' => 'result.' . $fmt,
            'media_type' => 'image', 'edit_params' => $key, 'status' => 'processing',
        ]);
        $row = $this->media->find($resultId); $dir = MediaModel::dir($row);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true)) throw new \RuntimeException('cannot create ' . $dir);
        MediaIntake::relax(dirname($dir)); MediaIntake::relax($dir);
        $out = $dir . '/result.' . $fmt;
        $args = [Ffmpeg::bin('ffmpeg'), '-y', '-v', 'error', '-i', $srcPath, '-frames:v', '1'];
        if (! empty($c['height'])) array_push($args, '-vf', "scale=-2:'min(ih," . (int) $c['height'] . ")'");
        if ($fmt === 'jpg') array_push($args, '-q:v', ($c['quality'] ?? 'high') === 'high' ? '2' : '5');
        if ($fmt === 'webp') array_push($args, '-quality', ($c['quality'] ?? 'high') === 'high' ? '90' : '75');
        $args[] = $out;
        $r = Ffmpeg::run($args, 120);
        if ($r['code'] !== 0 || ! is_file($out)) { $this->media->delete($resultId); @rmdir($dir); throw new \RuntimeException('ffmpeg failed: ' . mb_substr($r['stderr'], -800)); }
        $this->finishMedia($resultId, $out, $dir, $log);
        return $resultId;
    }

    /** Build a browser-friendly 720p H.264 proxy next to the original. Returns the media id. */
    private function runProxy(array $job, callable $log): int
    {
        $src = $this->media->find($job['media_id']);
        if (! $src) throw new \RuntimeException('source media missing');
        $dir = MediaModel::dir($src);
        $srcPath = $dir . '/' . $src['filename'];
        $out = $dir . '/proxy.mp4';
        $hasAudio = ! empty($src['acodec']);
        $args = [Ffmpeg::bin('ffmpeg'), '-y', '-v', 'error', '-nostats', '-progress', 'pipe:1', '-i', $srcPath,
                 '-vf', "scale=-2:'min(ih,720)',format=yuv420p", '-map', '0:v:0'];
        if ($hasAudio) array_push($args, '-map', '0:a:0?', '-c:a', 'aac', '-b:a', '128k', '-ac', '2'); else $args[] = '-an';
        if (Ffmpeg::hasNvenc()) array_push($args, '-c:v', 'h264_nvenc', '-preset', 'p4', '-rc', 'vbr', '-cq', '28', '-b:v', '0', '-maxrate', '4M', '-bufsize', '8M', '-profile:v', 'main');
        else array_push($args, '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '26');
        array_push($args, '-g', '30', '-movflags', '+faststart', $out);
        $log('proxy ffmpeg ' . implode(' ', array_map('escapeshellarg', array_slice($args, 1))));
        $r = $this->runWithProgress($args, max(0.1, (float) $src['duration']), fn (int $pct) => $this->jobs->update($job['id'], ['progress' => $pct]));
        if ($r['code'] !== 0 || ! is_file($out) || filesize($out) === 0) { @unlink($out); throw new \RuntimeException('proxy failed: ' . mb_substr(trim($r['stderr']), -1200)); }
        $upd = ['has_proxy' => 1];
        if (! $src['has_thumb'] && Ffmpeg::thumbnail($out, $dir . '/thumb.jpg', (float) $src['duration'])) $upd['has_thumb'] = 1;
        if (! is_file($dir . '/strip2.jpg') && $src['duration']) Ffmpeg::filmstrip($out, $dir . '/strip2.jpg', (float) $src['duration']);
        $this->media->update($src['id'], $upd);
        MediaIntake::relax($dir);
        return (int) $src['id'];
    }

    /** Download one item from a social URL with yt-dlp into a new media row. */
    private function runImport(array $job, callable $log): int
    {
        $p = json_decode($job['params'], true) ?: [];
        $bin = MediaSupport::ytdlp();
        if (! $bin) throw new \RuntimeException('yt-dlp not installed');
        $platform = MediaSupport::platformOf($p['url'] ?? '');
        if (! $platform) throw new \RuntimeException('url not allowed');
        $idx = max(1, (int) ($p['index'] ?? 1));
        $mediaId = $this->media->insert([
            'user_id' => $job['user_id'], 'kind' => 'original', 'source' => $platform, 'source_url' => mb_substr($p['url'], 0, 1000),
            'title' => MediaSupport::tidyTitle((string) $p['title'], $platform . ' import'), 'filename' => 'original.mp4', 'status' => 'processing',
        ]);
        $this->jobs->update($job['id'], ['media_id' => $mediaId]);
        $row = $this->media->find($mediaId); $dir = MediaModel::dir($row);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true)) throw new \RuntimeException('cannot create ' . $dir);
        MediaIntake::relax(dirname($dir)); MediaIntake::relax($dir);
        if (! empty($p['image_url'])) {
            return $this->importImage($job, $mediaId, $dir, $p, $log);
        }
        $args = [$bin, '--no-warnings', '--no-playlist', '--playlist-items', (string) $idx, '--socket-timeout', '30', '--retries', '3',
                 '-f', 'bv*[ext=mp4]+ba[ext=m4a]/bv*+ba/b', '--merge-output-format', 'mp4', '--no-mtime', '--write-info-json',
                 '-o', $dir . '/original.%(ext)s', '--print', 'after_move:filepath', '--print', 'title', $p['url']];
        $log('yt-dlp ' . implode(' ', array_map('escapeshellarg', array_slice($args, 1))));
        $this->jobs->update($job['id'], ['progress' => 5]);
        $r = Ffmpeg::run($args, 900);
        $files = glob($dir . '/original.*') ?: [];
        if ($r['code'] !== 0 || $files === []) {
            foreach ($files as $f) @unlink($f);
            $this->media->delete($mediaId); @rmdir($dir);
            throw new \RuntimeException('yt-dlp failed: ' . mb_substr(trim(preg_replace('/^ERROR:\s*/m', '', $r['stderr'])), -800));
        }
        $files = array_values(array_filter($files, static fn ($f) => ! str_ends_with($f, '.info.json')));
        if ($files === []) {
            $this->media->delete($mediaId); MediaIntake::removeDir($dir);
            throw new \RuntimeException('yt-dlp produced no media file');
        }
        $file = $files[0];
        $info = $this->applyInfoJson($mediaId, $dir, (string) ($p['platform'] ?? ''));
        // --print output also contains the file path; never let that become a title
        $lines = array_values(array_filter(
            array_map('trim', explode("\n", $r['stdout'])),
            static fn ($l) => $l !== '' && ! str_starts_with($l, '/')
        ));
        $title = MediaSupport::tidyTitle(
            (string) ($p['title'] ?: ($info['title'] ?? $lines[0] ?? '')),
            $platform . ' import'
        );
        $this->media->update($mediaId, ['filename' => basename($file), 'title' => $title]);
        $this->finishMedia($mediaId, $file, $dir, $log);
        $m = $this->media->find($mediaId);
        if (MediaSupport::needsProxy($m)) {
            $this->jobs->insert(['user_id' => $job['user_id'], 'media_id' => $mediaId, 'type' => 'proxy', 'params' => '{}', 'status' => 'queued']);
        }
        return $mediaId;
    }

    /** Reads yt-dlp's .info.json (description, channel, counts) into the media row, then removes it. */
    private function applyInfoJson(int $mediaId, string $dir, string $platform): ?array
    {
        $json = glob($dir . '/*.info.json')[0] ?? null;
        if (! $json) return null;
        $info = json_decode((string) file_get_contents($json), true);
        @unlink($json);
        if (! is_array($info)) return null;
        $stats = array_filter([
            'view_count'    => isset($info['view_count']) ? (int) $info['view_count'] : null,
            'like_count'    => isset($info['like_count']) ? (int) $info['like_count'] : null,
            'comment_count' => isset($info['comment_count']) ? (int) $info['comment_count'] : null,
            'upload_date'   => $info['upload_date'] ?? null,
        ], static fn ($v) => $v !== null);
        $this->media->update($mediaId, [
            'description' => isset($info['description']) ? mb_substr((string) $info['description'], 0, 20000) : null,
            'uploader'    => isset($info['uploader']) || isset($info['channel'])
                ? mb_substr((string) ($info['uploader'] ?? $info['channel']), 0, 190) : null,
            'stats'       => $stats ? json_encode($stats, JSON_UNESCAPED_UNICODE) : null,
        ]);
        return $info;
    }

    /** Downloads a single image URL (already validated) into the media directory. */
    private function importImage(array $job, int $mediaId, string $dir, array $p, callable $log): int
    {
        $url = (string) $p['image_url'];
        $tmp = $dir . '/download.bin';
        $log('curl image ' . $url);
        $r = Ffmpeg::run(['curl', '-sL', '--max-redirs', '3', '--max-time', '120', '--max-filesize', '104857600',
            '-A', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36',
            '-w', '%{url_effective}', '-o', $tmp, $url], 130);
        $effective = trim($r['stdout']);
        if ($r['code'] !== 0 || ! is_file($tmp) || filesize($tmp) === 0 || ($effective !== '' && ! MediaSupport::imageUrlAllowed($effective))) {
            @unlink($tmp); $this->media->delete($mediaId); @rmdir($dir);
            throw new \RuntimeException('이미지를 내려받지 못했습니다.');
        }
        $meta = Ffmpeg::probe($tmp);
        $ext  = match ($meta['container'] ?? '') { 'png_pipe' => 'png', 'webp_pipe' => 'webp', 'gif' => 'gif', default => 'jpg' };
        $file = $dir . '/original.' . $ext;
        rename($tmp, $file);
        $this->media->update($mediaId, ['filename' => 'original.' . $ext]);
        $this->finishMedia($mediaId, $file, $dir, $log);
        return $mediaId;
    }

    /** Shared: run the edit pipeline and register the result media. */
    private function encode(array $job, array $src, array $p, string $source, string $titleSuffix, string $editParams, callable $log): int
    {
        $srcPath = MediaModel::dir($src) . '/' . $src['filename'];
        if (! is_file($srcPath)) throw new \RuntimeException('source file missing');
        $fmt = $p['output']['format'];
        $resultId = $this->media->insert([
            'user_id' => $src['user_id'], 'parent_id' => $src['id'], 'kind' => 'result', 'source' => $source,
            'title' => mb_substr($src['title'] . $titleSuffix, 0, 255), 'filename' => 'result.' . $fmt,
            'media_type' => 'video', 'edit_params' => $editParams, 'status' => 'processing',
        ]);
        $row = $this->media->find($resultId);
        $dir = MediaModel::dir($row);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true)) throw new \RuntimeException('cannot create ' . $dir);
        MediaIntake::relax(dirname($dir)); MediaIntake::relax($dir);
        $out = $dir . '/result.' . $fmt;

        $cmd = $this->buildEditCommand($srcPath, $out, $p, $src);
        $log('ffmpeg ' . implode(' ', array_map('escapeshellarg', array_slice($cmd, 1))));
        $expected = max(0.1, EditParams::outputDuration($p));
        $r = $this->runWithProgress($cmd, $expected, fn (int $pct) => $this->jobs->update($job['id'], ['progress' => $pct]));
        if ($r['code'] !== 0 || ! is_file($out) || filesize($out) === 0) {
            @unlink($out); $this->media->delete($resultId); @rmdir($dir);
            throw new \RuntimeException('ffmpeg failed (' . $r['code'] . '): ' . mb_substr(trim($r['stderr']), -1500));
        }
        $this->finishMedia($resultId, $out, $dir, $log);
        return $resultId;
    }

    /** Probe + thumbnail + filmstrip, mark ready. */
    private function finishMedia(int $id, string $file, string $dir, callable $log): void
    {
        $update = ['status' => 'ready', 'size' => filesize($file), 'mime' => mime_content_type($file) ?: null];
        if ($meta = Ffmpeg::probe($file)) {
            $update += $meta;
            if (in_array($meta['media_type'], ['video', 'image'], true) && Ffmpeg::thumbnail($file, $dir . '/thumb.jpg', $meta['duration'])) $update['has_thumb'] = 1;
            if ($meta['media_type'] === 'video' && $meta['duration']) Ffmpeg::filmstrip($file, $dir . '/strip2.jpg', (float) $meta['duration']);
        }
        $this->media->update($id, $update);
        MediaIntake::relax($dir);
    }

    /** @return string[] argv */
    public function buildEditCommand(string $src, string $out, array $p, array $srcMeta): array
    {
        $fmt      = $p['output']['format'];
        $hasAudio = ! empty($srcMeta['acodec']) && $p['keepAudio'] && $fmt !== 'gif';
        $n        = count($p['keep']);
        $f        = [];
        $vin = []; $ain = [];
        foreach ($p['keep'] as $i => [$s, $e]) {
            $f[] = sprintf('[0:v]trim=start=%.3f:end=%.3f,setpts=PTS-STARTPTS[v%d]', $s, $e, $i);
            $vin[] = "[v{$i}]";
            if ($hasAudio) {
                $f[] = sprintf('[0:a]atrim=start=%.3f:end=%.3f,asetpts=PTS-STARTPTS[a%d]', $s, $e, $i);
                $ain[] = "[a{$i}]";
            }
        }
        if ($hasAudio) {
            $pairs = '';
            for ($i = 0; $i < $n; $i++) $pairs .= $vin[$i] . $ain[$i];
            $f[] = $pairs . "concat=n={$n}:v=1:a=1[vc][ac]";
        } else {
            $f[] = implode('', $vin) . "concat=n={$n}:v=1:a=0[vc]";
        }

        // video chain
        $v = '[vc]';
        $chain = [];
        if ($p['crop']) {
            $c = $p['crop'];
            $chain[] = "crop={$c['w']}:{$c['h']}:{$c['x']}:{$c['y']}";
        }
        // masks are in source coordinates; after crop, shift them
        $ox = $p['crop']['x'] ?? 0; $oy = $p['crop']['y'] ?? 0;
        $label = 'vc';
        if ($chain) {
            $f[] = '[vc]' . implode(',', $chain) . '[v1]';
            $label = 'v1';
        }
        $mi = 0;
        foreach ($p['masks'] as $m) {
            $x = $m['x'] - $ox; $y = $m['y'] - $oy;
            if ($m['style'] === 'blur') {
                $f[] = "[{$label}]split[mb{$mi}][mm{$mi}]";
                $f[] = "[mm{$mi}]crop={$m['w']}:{$m['h']}:{$x}:{$y},boxblur=luma_radius=min(h\\,w)/12:luma_power=2[mr{$mi}]";
                $f[] = "[mb{$mi}][mr{$mi}]overlay={$x}:{$y}[vm{$mi}]";
            } else {
                $f[] = "[{$label}]drawbox=x={$x}:y={$y}:w={$m['w']}:h={$m['h']}:color=black@1:t=fill[vm{$mi}]";
            }
            $label = "vm{$mi}"; $mi++;
        }
        $post = [];
        if ($p['speed'] != 1.0) {
            $post[] = sprintf('setpts=PTS/%.4f', $p['speed']);
            // keep the source frame rate (duplicate/drop frames) instead of a fractional output rate
            if (! empty($srcMeta['fps'])) $post[] = sprintf('fps=%.3f', min(60, (float) $srcMeta['fps']));
        }
        if ($p['output']['height'] > 0) $post[] = "scale=-2:'min(ih,{$p['output']['height']})'";
        if ($fmt === 'gif') {
            $post[] = 'fps=15';
            $f[] = "[{$label}]" . implode(',', $post) . ",split[g1][g2];[g1]palettegen=stats_mode=diff[pal];[g2][pal]paletteuse=dither=bayer:bayer_scale=5:diff_mode=rectangle[vout]";
        } else {
            $post[] = 'format=yuv420p';
            $f[] = "[{$label}]" . implode(',', $post) . '[vout]';
        }
        // audio chain
        if ($hasAudio) {
            $a = [];
            $sp = $p['speed'];
            while ($sp < 0.5) { $a[] = 'atempo=0.5'; $sp /= 0.5; }
            while ($sp > 2.0) { $a[] = 'atempo=2.0'; $sp /= 2.0; }
            if (abs($sp - 1.0) > 0.001) $a[] = sprintf('atempo=%.4f', $sp);
            $f[] = '[ac]' . ($a ? implode(',', $a) : 'anull') . '[aout]';
        }

        $bin  = Ffmpeg::bin('ffmpeg');
        $args = [$bin, '-y', '-v', 'error', '-nostats', '-progress', 'pipe:1', '-i', $src,
                 '-filter_complex', implode(';', $f), '-map', '[vout]'];
        if ($hasAudio) { $args[] = '-map'; $args[] = '[aout]'; } else { $args[] = '-an'; }

        $hq = $p['output']['quality'] === 'high';
        switch ($fmt) {
            case 'mp4':
                if (Ffmpeg::hasNvenc()) {
                    array_push($args, '-c:v', 'h264_nvenc', '-preset', 'p5', '-tune', 'hq', '-rc', 'vbr', '-cq', $hq ? '21' : '26', '-b:v', '0', '-maxrate', $hq ? '20M' : '8M', '-bufsize', '40M', '-profile:v', 'high');
                } else {
                    array_push($args, '-c:v', 'libx264', '-preset', 'medium', '-crf', $hq ? '19' : '24', '-profile:v', 'high');
                }
                if ($hasAudio) array_push($args, '-c:a', 'aac', '-b:a', '160k');
                array_push($args, '-movflags', '+faststart');
                break;
            case 'webm':
                array_push($args, '-c:v', 'libvpx-vp9', '-crf', $hq ? '30' : '36', '-b:v', '0', '-row-mt', '1', '-deadline', 'good', '-cpu-used', '2');
                if ($hasAudio) array_push($args, '-c:a', 'libopus', '-b:a', '128k');
                break;
            case 'gif':
                break;
        }
        $args[] = $out;
        return $args;
    }

    /** Runs ffmpeg, parsing -progress output to report percent. */
    private function runWithProgress(array $args, float $expected, callable $onProgress): array
    {
        $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($args, $spec, $pipes, null, ['PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin']);
        if (! is_resource($proc)) return ['code' => -1, 'stderr' => 'proc_open failed'];
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $buf = ''; $stderr = ''; $last = -1; $lastAt = 0;
        while (true) {
            $buf    .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            while (($nl = strpos($buf, "\n")) !== false) {
                $line = substr($buf, 0, $nl); $buf = substr($buf, $nl + 1);
                if (str_starts_with($line, 'out_time_us=') || str_starts_with($line, 'out_time_ms=')) {
                    $us  = (float) substr($line, 12);
                    $pct = (int) max(0, min(99, floor($us / 1e6 / $expected * 100)));
                    if ($pct !== $last && microtime(true) - $lastAt > 0.5) { $onProgress($pct); $last = $pct; $lastAt = microtime(true); }
                }
            }
            $st = proc_get_status($proc);
            if (! $st['running']) break;
            usleep(100000);
        }
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $code = proc_close($proc);
        if (isset($st) && ! $st['running']) $code = $st['exitcode'];
        return ['code' => $code, 'stderr' => $stderr];
    }
}
