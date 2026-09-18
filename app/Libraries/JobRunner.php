<?php

namespace App\Libraries;

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
                'edit'  => $this->runEdit($job, $log),
                default => throw new \RuntimeException('unknown job type ' . $job['type']),
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
        $srcPath = MediaModel::dir($src) . '/' . $src['filename'];
        if (! is_file($srcPath)) throw new \RuntimeException('source file missing');

        $fmt = $p['output']['format'];
        $ext = $fmt;
        $resultId = $this->media->insert([
            'user_id'     => $src['user_id'],
            'parent_id'   => $src['id'],
            'kind'        => 'result',
            'source'      => 'edit',
            'title'       => mb_substr($src['title'] . ' - edit', 0, 255),
            'filename'    => 'result.' . $ext,
            'media_type'  => $fmt === 'gif' ? 'video' : 'video',
            'edit_params' => json_encode($p, JSON_UNESCAPED_UNICODE),
            'status'      => 'processing',
        ]);
        $row = $this->media->find($resultId);
        $dir = MediaModel::dir($row);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true)) throw new \RuntimeException('cannot create ' . $dir);
        $out = $dir . '/result.' . $ext;

        $cmd = $this->buildEditCommand($srcPath, $out, $p, $src);
        $log('ffmpeg ' . implode(' ', array_map('escapeshellarg', array_slice($cmd, 1))));
        $expected = max(0.1, EditParams::outputDuration($p));
        $r = $this->runWithProgress($cmd, $expected, function (int $pct) use ($job) {
            $this->jobs->update($job['id'], ['progress' => $pct]);
        });
        if ($r['code'] !== 0 || ! is_file($out) || filesize($out) === 0) {
            @unlink($out);
            $this->media->delete($resultId);
            @rmdir($dir);
            throw new \RuntimeException('ffmpeg failed (' . $r['code'] . '): ' . mb_substr(trim($r['stderr']), -1500));
        }

        $update = ['status' => 'ready', 'size' => filesize($out), 'mime' => mime_content_type($out) ?: null];
        if ($meta = Ffmpeg::probe($out)) {
            $update += $meta;
            if ($fmt === 'gif') $update['media_type'] = 'video';
            if (Ffmpeg::thumbnail($out, $dir . '/thumb.jpg', $meta['duration'])) $update['has_thumb'] = 1;
            if ($meta['duration']) Ffmpeg::filmstrip($out, $dir . '/strip2.jpg', (float) $meta['duration']);
        }
        $this->media->update($resultId, $update);
        return $resultId;
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
