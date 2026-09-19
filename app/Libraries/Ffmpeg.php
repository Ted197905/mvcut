<?php

namespace App\Libraries;

/**
 * Thin wrapper around ffprobe / ffmpeg. All arguments are passed as arrays
 * (no shell string interpolation).
 */
class Ffmpeg
{
    public static function available(): bool
    {
        return self::bin('ffprobe') !== null && self::bin('ffmpeg') !== null;
    }

    public static function bin(string $name): ?string
    {
        static $cache = [];
        if (! array_key_exists($name, $cache)) {
            $out = self::run(['which', $name]);
            $cache[$name] = ($out['code'] === 0 && trim($out['stdout']) !== '') ? trim($out['stdout']) : null;
        }
        return $cache[$name];
    }

    /** Returns normalized metadata, or null if ffprobe is unavailable/failed. */
    public static function probe(string $path): ?array
    {
        $bin = self::bin('ffprobe');
        if (! $bin) {
            return null;
        }
        $out = self::run([$bin, '-v', 'error', '-print_format', 'json', '-show_format', '-show_streams', $path]);
        if ($out['code'] !== 0) {
            return null;
        }
        $j = json_decode($out['stdout'], true);
        if (! is_array($j)) {
            return null;
        }
        $v = null; $a = null;
        foreach ($j['streams'] ?? [] as $s) {
            if ($s['codec_type'] === 'video' && $v === null) $v = $s;
            if ($s['codec_type'] === 'audio' && $a === null) $a = $s;
        }
        $fmt = $j['format'] ?? [];
        $formatName = explode(',', $fmt['format_name'] ?? '')[0];
        $duration = isset($fmt['duration']) ? (float) $fmt['duration'] : null;
        $isImage = $v !== null && ($a === null) && (($duration === null || $duration < 0.05) || in_array($formatName, ['image2', 'png_pipe', 'jpeg_pipe', 'webp_pipe', 'gif'], true));
        if ($formatName === 'gif' && ($duration ?? 0) > 0.05) $isImage = false;

        $fps = null;
        if ($v && ! empty($v['avg_frame_rate']) && $v['avg_frame_rate'] !== '0/0') {
            [$n, $d] = array_pad(explode('/', $v['avg_frame_rate']), 2, 1);
            if ((float) $d > 0) $fps = round((float) $n / (float) $d, 3);
        }
        return [
            'media_type' => $v ? ($isImage ? 'image' : 'video') : ($a ? 'audio' : 'unknown'),
            'container'  => $formatName ?: null,
            'vcodec'     => $v['codec_name'] ?? null,
            'acodec'     => $a['codec_name'] ?? null,
            'width'      => $v['width'] ?? null,
            'height'     => $v['height'] ?? null,
            'duration'   => $isImage ? null : $duration,
            'fps'        => $isImage ? null : $fps,
        ];
    }

    /** Writes a 480px-wide JPEG thumbnail. Returns true on success. */
    public static function thumbnail(string $src, string $dest, ?float $duration = null): bool
    {
        $bin = self::bin('ffmpeg');
        if (! $bin) {
            return false;
        }
        $args = [$bin, '-v', 'error', '-y'];
        if ($duration !== null && $duration > 2) {
            $args[] = '-ss'; $args[] = (string) min(3, $duration / 4);
        }
        array_push($args, '-i', $src, '-frames:v', '1', '-vf', 'scale=480:-2', '-q:v', '4', $dest);
        $out = self::run($args, 60);
        return $out['code'] === 0 && is_file($dest) && filesize($dest) > 0;
    }

    /** Writes a horizontal filmstrip sprite with evenly spaced frames. Returns frame count or 0. */
    public static function filmstrip(string $src, string $dest, float $duration, int $frames = 40, int $frameW = 160): int
    {
        $bin = self::bin('ffmpeg');
        if (! $bin || $duration <= 0) {
            return 0;
        }
        $frames = max(8, min($frames, (int) ceil($duration * 2)));
        // select one frame nearest each of N evenly spaced timestamps (uniform even for sparse keyframes)
        $step = $duration / $frames * 0.95; // slightly under so frame-time quantization never leaves tiles empty
        $args = [$bin, '-v', 'error', '-y', '-i', $src,
            '-vf', sprintf("select='isnan(prev_selected_t)+gte(t-prev_selected_t\\,%.6f)',scale=%d:-2,tile=%dx1", $step, $frameW, $frames),
            '-vsync', '0', '-frames:v', '1', '-q:v', '5', $dest];
        $out = self::run($args, 600);
        return ($out['code'] === 0 && is_file($dest)) ? $frames : 0;
    }

    public static function hasNvenc(): bool
    {
        static $ok = null;
        if ($ok === null) {
            $bin = self::bin('ffmpeg');
            $out = $bin ? self::run([$bin, '-hide_banner', '-encoders']) : ['stdout' => ''];
            $ok = str_contains($out['stdout'], 'h264_nvenc') && (file_exists('/dev/dxg') || file_exists('/dev/nvidia0'));
        }
        return $ok;
    }

    public static function run(array $args, int $timeout = 30, array $env = []): array
    {
        $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $env = array_merge(['PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'], $env);
        $p = proc_open($args, $spec, $pipes, null, $env);
        if (! is_resource($p)) {
            return ['code' => -1, 'stdout' => '', 'stderr' => 'proc_open failed'];
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = ''; $stderr = ''; $start = time();
        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            $st = proc_get_status($p);
            if (! $st['running']) break;
            if (time() - $start > $timeout) { proc_terminate($p, 9); $stderr .= "\ntimeout"; break; }
            usleep(50000);
        }
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $code = proc_close($p);
        if (isset($st) && ! $st['running']) $code = $st['exitcode'];
        return ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
