<?php

namespace App\Libraries;

/**
 * Validates and normalizes editor parameters sent by the browser.
 *
 * {
 *   "keep":   [[start, end], ...]        seconds, sorted, non-overlapping (segments to keep)
 *   "crop":   {"x":0,"y":0,"w":W,"h":H} | null   source pixels
 *   "masks":  [{"x","y","w","h","style":"black|blur|fill|ai"}]
 *   "speed":  1.0                        0.25 .. 4
 *   "enhance": {"sharpen":"off|low|mid|high", "denoise":bool}
 *   "smooth":  "off|x2|x4|slow"          RIFE frame generation
 *   "restore": {"mode":"off|ai|ai2x", "model":"general|anime"}   Real-ESRGAN detail restore
 *   "keepAudio": true
 *   "output": {"format":"mp4|webm|gif", "height": 0|1080|720|480, "quality":"high|medium"}
 * }
 */
class EditParams
{
    public static function normalize(array $in, array $media): array
    {
        $dur = (float) $media['duration'];
        $W   = (int) $media['width'];
        $H   = (int) $media['height'];
        if ($dur <= 0 || $W <= 0 || $H <= 0) {
            throw new \InvalidArgumentException('원본 메타데이터가 없어 편집할 수 없습니다.');
        }

        // keep segments
        $keep = [];
        foreach ((array) ($in['keep'] ?? [[0, $dur]]) as $seg) {
            if (! is_array($seg) || count($seg) < 2) continue;
            $s = max(0.0, min($dur, (float) $seg[0]));
            $e = max(0.0, min($dur, (float) $seg[1]));
            if ($e - $s >= 0.04) $keep[] = [round($s, 3), round($e, 3)];
        }
        usort($keep, static fn ($a, $b) => $a[0] <=> $b[0]);
        for ($i = 1; $i < count($keep); $i++) {
            if ($keep[$i][0] < $keep[$i - 1][1]) throw new \InvalidArgumentException('구간이 겹칩니다.');
        }
        if ($keep === []) throw new \InvalidArgumentException('남길 구간이 없습니다.');

        // crop
        $crop = null;
        if (! empty($in['crop']) && is_array($in['crop'])) {
            $crop = self::rect($in['crop'], $W, $H);
            if ($crop['x'] === 0 && $crop['y'] === 0 && $crop['w'] === $W && $crop['h'] === $H) $crop = null;
        }

        // masks
        $masks = [];
        foreach ((array) ($in['masks'] ?? []) as $m) {
            if (! is_array($m)) continue;
            $r = self::rect($m, $W, $H);
            $style = (string) ($m['style'] ?? 'black');
            $r['style'] = in_array($style, ['black', 'blur', 'fill', 'ai'], true) ? $style : 'black';
            $masks[] = $r;
        }

        // watermark
        $wm = null;
        if (! empty($in['watermark']) && is_array($in['watermark']) && trim((string) ($in['watermark']['text'] ?? '')) !== '') {
            $w = $in['watermark'];
            $font = (string) ($w['font'] ?? '');
            if (! \App\Libraries\Fonts::has($font)) $font = \App\Libraries\Fonts::default();
            $box = $crop ?: ['x' => 0, 'y' => 0, 'w' => $W, 'h' => $H];
            $size = (int) round((float) ($w['size'] ?? 0));
            if ($size < 8) $size = max(16, (int) round($box['h'] * 0.06));
            $size = max(8, min(400, $size));
            $wm = [
                'text'    => mb_substr(preg_replace('/[\r\n\t]+/u', ' ', (string) $w['text']), 0, 120),
                'font'    => $font,
                'size'    => $size,
                'color'   => self::color($w['color'] ?? '#ffffff'),
                'opacity' => max(0.05, min(1.0, (float) ($w['opacity'] ?? 0.85))),
                'x'       => max(0, min($box['w'], (int) round((float) ($w['x'] ?? 0)))),
                'y'       => max(0, min($box['h'], (int) round((float) ($w['y'] ?? 0)))),
                'anchor'  => in_array($w['anchor'] ?? 'nw', ['nw', 'n', 'ne', 'w', 'c', 'e', 'sw', 's', 'se'], true) ? $w['anchor'] : 'nw',
                'style'   => in_array($w['style'] ?? 'shadow', ['none', 'shadow', 'outline', 'box'], true) ? $w['style'] : 'shadow',
            ];
        }

        // enhance: compression cleanup + contrast adaptive sharpening
        $sharpen = (string) ($in['enhance']['sharpen'] ?? 'off');
        if (! in_array($sharpen, ['off', 'low', 'mid', 'high'], true)) $sharpen = 'off';
        $enhance = ['sharpen' => $sharpen, 'denoise' => (bool) ($in['enhance']['denoise'] ?? ($sharpen !== 'off'))];

        $rMode = (string) ($in['restore']['mode'] ?? 'off');
        if (! in_array($rMode, ['off', 'ai', 'ai2x'], true)) $rMode = 'off';
        $rModel = ($in['restore']['model'] ?? 'general') === 'anime' ? 'anime' : 'general';
        $restore = ['mode' => $rMode, 'model' => $rModel];

        $smooth = (string) ($in['smooth'] ?? 'off');
        if (! in_array($smooth, ['off', 'x2', 'x4', 'slow'], true)) $smooth = 'off';

        $speed = (float) ($in['speed'] ?? 1);
        if ($speed < 0.25 || $speed > 4) throw new \InvalidArgumentException('속도는 0.25x~4x 사이여야 합니다.');

        $fmt = $in['output']['format'] ?? 'mp4';
        if (! in_array($fmt, ['mp4', 'webm', 'gif'], true)) $fmt = 'mp4';
        $height = (int) ($in['output']['height'] ?? 0);
        if (! in_array($height, [0, 1080, 720, 480, 360], true)) $height = 0;
        $quality = ($in['output']['quality'] ?? 'high') === 'medium' ? 'medium' : 'high';

        return [
            'keep'      => $keep,
            'crop'      => $crop,
            'masks'     => $masks,
            'watermark' => $wm,
            'enhance'   => $enhance,
            'smooth'    => $smooth,
            'restore'   => $restore,
            'speed'     => round($speed, 3),
            'keepAudio' => (bool) ($in['keepAudio'] ?? true),
            'output'    => ['format' => $fmt, 'height' => $height, 'quality' => $quality],
        ];
    }

    private static function color(string $c): string
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? strtolower($c) : '#ffffff';
    }

    private static function rect(array $r, int $W, int $H): array
    {
        $x = (int) round((float) ($r['x'] ?? 0));
        $y = (int) round((float) ($r['y'] ?? 0));
        $w = (int) round((float) ($r['w'] ?? $W));
        $h = (int) round((float) ($r['h'] ?? $H));
        $x = max(0, min($W - 2, $x));
        $y = max(0, min($H - 2, $y));
        $w = max(2, min($W - $x, $w));
        $h = max(2, min($H - $y, $h));
        // even dimensions for yuv420p encoders
        $w -= $w % 2; $h -= $h % 2; $x -= $x % 2; $y -= $y % 2;
        return ['x' => $x, 'y' => $y, 'w' => $w, 'h' => $h];
    }

    /** Total output duration in seconds. */
    public static function outputDuration(array $p): float
    {
        $t = 0.0;
        foreach ($p['keep'] as [$s, $e]) $t += $e - $s;
        return $t / $p['speed'];
    }
}
