<?php

namespace App\Libraries;

/**
 * Validates and normalizes editor parameters sent by the browser.
 *
 * {
 *   "keep":   [[start, end], ...]        seconds, sorted, non-overlapping (segments to keep)
 *   "transform": {"rotate":0|90|180|270, "flipH":bool, "flipV":bool}
 *             applied first, so every coordinate below is in the rotated frame
 *   "crop":   {"x":0,"y":0,"w":W,"h":H} | null   source pixels
 *   "masks":  [{"x","y","w","h","style":"black|blur|fill|ai"}]
 *             or {"style":"track","x","y","at"}   click point, tracked by SAM 2
 *   "speed":  1.0                        0.25 .. 4
 *   "enhance": {"sharpen":"off|low|mid|high", "denoise":bool}
 *   "smooth":  "off|x2|x4|slow"          RIFE frame generation
 *   "restore": {"mode":"off|ai|ai2x", "model":"general|anime"}   Real-ESRGAN detail restore
 *   "expand":  {"w":1.0,"h":1.0}           ProPainter outpainting, 1.0 .. 2.0
 *   "erase":   {"quality":"fast|normal|fine"}   how finely the repaint runs
 *   "subtitles": [{"template","font","size","color","anchor","x","y",
 *                  "cues":[{"start","end","text"}]}]   up to 2 layers, times in source seconds
 *   "keepAudio": true
 *   "output": {"format":"mp4|webm|gif", "audio":"auto|aac|mp3|opus|none",
 *              "height": 0|1080|720|480, "quality":"high|medium"}
 * }
 */
class EditParams
{
    /** Subtitle designs the editor offers; see JobRunner::subtitleStyle(). */
    public const TEMPLATES = [
        'outline', 'plain', 'box', 'whitebox', 'highlight',
        'heavy', 'blackbox', 'grayline', 'glow', 'softglow',
        'yellowline', 'invert', 'softbox', 'drop', 'neon',
    ];

    /** Anchors shared by the watermark and the subtitles. */
    private const ANCHORS = ['nw', 'n', 'ne', 'w', 'c', 'e', 'sw', 's', 'se'];

    /**
     * Validates a watermark the user wants kept for next time. It has no video to
     * belong to, so the size is stored as a fraction of the frame height and the
     * position as an anchor; both are turned back into pixels by the editor.
     */
    public static function watermarkPreset(array $in): ?array
    {
        $text = trim(preg_replace('/[\r\n\t]+/u', ' ', (string) ($in['text'] ?? '')));
        if ($text === '') return null;
        $font = (string) ($in['font'] ?? '');
        if (! \App\Libraries\Fonts::has($font)) $font = \App\Libraries\Fonts::default();

        return [
            'text'      => mb_substr($text, 0, 120),
            'font'      => $font,
            'sizeRatio' => round(max(0.005, min(0.5, (float) ($in['sizeRatio'] ?? 0.05))), 5),
            'color'     => self::color($in['color'] ?? '#ffffff'),
            'opacity'   => round(max(0.05, min(1.0, (float) ($in['opacity'] ?? 0.85))), 3),
            'anchor'    => in_array($in['anchor'] ?? 'se', ['nw', 'n', 'ne', 'w', 'c', 'e', 'sw', 's', 'se'], true)
                           ? $in['anchor'] : 'se',
            'style'     => in_array($in['style'] ?? 'shadow', ['none', 'shadow', 'outline', 'box'], true)
                           ? $in['style'] : 'shadow',
        ];
    }

    /** True when the job would run one of the GPU models (RIFE, Real-ESRGAN, ProPainter, SAM 2). */
    public static function usesAi(array $p): bool
    {
        if (($p['smooth'] ?? 'off') !== 'off') return true;
        if (($p['restore']['mode'] ?? 'off') !== 'off') return true;
        if (($p['expand']['w'] ?? 1) > 1 || ($p['expand']['h'] ?? 1) > 1) return true;
        foreach ($p['masks'] ?? [] as $m) {
            if (in_array($m['style'] ?? '', ['ai', 'track'], true)) return true;
        }
        return false;
    }

    /** One subtitle line's own look; only the keys the editor actually set are kept. */
    private static function cueStyle(array $in, int $W, int $H): array
    {
        $ov = [];
        if (isset($in['template']) && in_array($in['template'], self::TEMPLATES, true)) $ov['template'] = $in['template'];
        if (isset($in['font']) && \App\Libraries\Fonts::has((string) $in['font'])) $ov['font'] = (string) $in['font'];
        if (isset($in['size'])) {
            $size = (int) round((float) $in['size']);
            if ($size >= 8) $ov['size'] = min(400, $size);
        }
        if (isset($in['color'])) $ov['color'] = self::color($in['color']);
        if (isset($in['anchor']) && in_array($in['anchor'], self::ANCHORS, true)) $ov['anchor'] = $in['anchor'];
        if (isset($in['x'])) $ov['x'] = max(0, min($W, (int) round((float) $in['x'])));
        if (isset($in['y'])) $ov['y'] = max(0, min($H, (int) round((float) $in['y'])));
        return $ov;
    }

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

        // rotation and flips come first in the pipeline, so everything below - crop, masks,
        // watermark, subtitles - is measured on the rotated frame the editor shows
        $rot = (int) ($in['transform']['rotate'] ?? 0);
        if ($rot < 0) $rot += 360;
        if (! in_array($rot, [0, 90, 180, 270], true)) $rot = 0;
        $transform = ['rotate' => $rot,
                      'flipH'  => (bool) ($in['transform']['flipH'] ?? false),
                      'flipV'  => (bool) ($in['transform']['flipV'] ?? false)];
        if ($rot === 90 || $rot === 270) [$W, $H] = [$H, $W];

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
            if (($m['style'] ?? '') === 'track') {
                $masks[] = [
                    'style' => 'track',
                    'x'     => max(0, min($W, (int) round((float) ($m['x'] ?? 0)))),
                    'y'     => max(0, min($H, (int) round((float) ($m['y'] ?? 0)))),
                    'at'    => max(0.0, min($dur, round((float) ($m['at'] ?? 0), 3))),
                ];
                continue;
            }
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

        $eq = (string) ($in['erase']['quality'] ?? 'normal');
        if (! in_array($eq, ['fast', 'normal', 'fine'], true)) $eq = 'normal';
        $erase = ['quality' => $eq];

        $ew = round(max(1.0, min(2.0, (float) ($in['expand']['w'] ?? 1))), 2);
        $eh = round(max(1.0, min(2.0, (float) ($in['expand']['h'] ?? 1))), 2);
        $expand = ['w' => $ew, 'h' => $eh];

        // subtitles: at most two layers, each a styled list of timed cues
        $subs = [];
        foreach (array_slice((array) ($in['subtitles'] ?? []), 0, 2) as $layer) {
            if (! is_array($layer)) continue;
            $cues = [];
            foreach (array_slice((array) ($layer['cues'] ?? []), 0, 200) as $c) {
                $text = trim(preg_replace('/[\r\t]+/u', ' ', (string) ($c['text'] ?? '')));
                if ($text === '') continue;
                $cs = max(0.0, min($dur, (float) ($c['start'] ?? 0)));
                $ce = max(0.0, min($dur, (float) ($c['end'] ?? 0)));
                if ($ce - $cs < 0.05) continue;
                $cue = ['start' => round($cs, 3), 'end' => round($ce, 3),
                        'text' => mb_substr($text, 0, 200)];
                // a single line may override the layer's look
                $ov = self::cueStyle((array) ($c['style'] ?? []), $W, $H);
                if ($ov !== []) $cue['style'] = $ov;
                $cues[] = $cue;
            }
            if ($cues === []) continue;
            usort($cues, static fn ($a, $b) => $a['start'] <=> $b['start']);

            $font = (string) ($layer['font'] ?? '');
            if (! \App\Libraries\Fonts::has($font)) $font = \App\Libraries\Fonts::default();
            $size = (int) round((float) ($layer['size'] ?? 0));
            if ($size < 8) $size = max(20, (int) round($H * 0.045));
            $subs[] = [
                'template' => in_array($layer['template'] ?? 'outline',
                                       self::TEMPLATES, true)
                              ? $layer['template'] : 'outline',
                'font'   => $font,
                'size'   => max(8, min(400, $size)),
                'color'  => self::color($layer['color'] ?? '#ffffff'),
                'anchor' => in_array($layer['anchor'] ?? 's', self::ANCHORS, true) ? $layer['anchor'] : 's',
                'x'      => max(0, min($W, (int) round((float) ($layer['x'] ?? $W / 2)))),
                'y'      => max(0, min($H, (int) round((float) ($layer['y'] ?? $H * 0.85)))),
                'cues'   => $cues,
            ];
        }

        $smooth = (string) ($in['smooth'] ?? 'off');
        if (! in_array($smooth, ['off', 'x2', 'x4', 'slow'], true)) $smooth = 'off';

        $speed = (float) ($in['speed'] ?? 1);
        if ($speed < 0.25 || $speed > 4) throw new \InvalidArgumentException('속도는 0.25x~4x 사이여야 합니다.');

        $fmt = $in['output']['format'] ?? 'mp4';
        if (! in_array($fmt, ['mp4', 'webm', 'gif'], true)) $fmt = 'mp4';
        $height = (int) ($in['output']['height'] ?? 0);
        if (! in_array($height, [0, 1080, 720, 480, 360], true)) $height = 0;
        $quality = ($in['output']['quality'] ?? 'high') === 'medium' ? 'medium' : 'high';

        // audio is picked apart from the video format; the container limits what fits in it
        $audio = (string) ($in['output']['audio'] ?? 'auto');
        if (! in_array($audio, ['auto', 'aac', 'mp3', 'opus', 'none'], true)) $audio = 'auto';
        if (isset($in['keepAudio']) && ! $in['keepAudio']) $audio = 'none';   // older payloads
        $audio = match ($fmt) {
            'gif'  => 'none',
            'webm' => in_array($audio, ['auto', 'opus', 'none'], true) ? $audio : 'auto',
            default => $audio === 'opus' ? 'auto' : $audio,
        };

        return [
            'keep'      => $keep,
            'transform' => $transform,
            'crop'      => $crop,
            'masks'     => $masks,
            'watermark' => $wm,
            'enhance'   => $enhance,
            'smooth'    => $smooth,
            'restore'   => $restore,
            'expand'    => $expand,
            'erase'     => $erase,
            'subtitles' => $subs,
            'speed'     => round($speed, 3),
            'keepAudio' => $audio !== 'none',
            'output'    => ['format' => $fmt, 'audio' => $audio, 'height' => $height, 'quality' => $quality],
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

    /**
     * Plain-language list of what was applied, stored as the result's description so the
     * library shows how a file was made without reading the parameter JSON.
     */
    public static function summary(array $p, array $src): string
    {
        $fmtTime = static function (float $t): string {
            $m = (int) floor($t / 60); $sec = $t - $m * 60;
            return $m > 0 ? sprintf('%d:%05.2f', $m, $sec) : sprintf('%.2f초', $sec);
        };
        $lines = [];

        $n = count($p['keep']);
        $kept = array_sum(array_map(static fn ($seg) => $seg[1] - $seg[0], $p['keep']));
        $whole = $n === 1 && $p['keep'][0][0] <= 0.05 && abs($p['keep'][0][1] - (float) $src['duration']) <= 0.05;
        if (! $whole) {
            $parts = array_map(static fn ($seg) => $fmtTime($seg[0]) . ' ~ ' . $fmtTime($seg[1]), $p['keep']);
            $lines[] = '구간: ' . $n . '개 남김 (' . implode(', ', array_slice($parts, 0, 4))
                     . ($n > 4 ? ' 외 ' . ($n - 4) . '개' : '') . ') · 합계 ' . $fmtTime($kept);
        }

        if ($p['crop']) {
            $c = $p['crop'];
            $lines[] = '화면 자르기: ' . $c['w'] . '×' . $c['h'] . ' (원본 ' . $src['width'] . '×' . $src['height']
                     . ' 중 ' . $c['x'] . ',' . $c['y'] . ' 위치)';
        }

        $byStyle = [];
        foreach ($p['masks'] as $m) {
            $label = match ($m['style']) {
                'blur' => '블러', 'fill' => '배경 채우기', 'ai' => 'AI 지우기',
                'track' => '대상 추적 지우기', default => '검정',
            };
            $byStyle[$label] = ($byStyle[$label] ?? 0) + 1;
        }
        if ($byStyle) {
            $parts = [];
            foreach ($byStyle as $label => $cnt) $parts[] = $label . ' ' . $cnt . '개';
            $ai = isset($byStyle['AI 지우기']) || isset($byStyle['대상 추적 지우기']);
            $q  = $p['erase']['quality'] ?? 'normal';
            $lines[] = '가리기: ' . implode(', ', $parts)
                     . ($ai ? ' · 정밀도 ' . match ($q) { 'fast' => '빠르게', 'fine' => '정밀', default => '보통' } : '');
        }

        $t = $p['transform'] ?? [];
        if (! empty($t['rotate']) || ! empty($t['flipH']) || ! empty($t['flipV'])) {
            $bits = [];
            if (! empty($t['rotate'])) $bits[] = match ((int) $t['rotate']) {
                90 => '오른쪽 90도', 180 => '180도', 270 => '왼쪽 90도', default => '',
            };
            if (! empty($t['flipH'])) $bits[] = '좌우 반전';
            if (! empty($t['flipV'])) $bits[] = '상하 반전';
            $lines[] = '회전: ' . implode(' · ', array_filter($bits));
        }

        if (! empty($p['watermark'])) {
            $w = $p['watermark'];
            $font = \App\Libraries\Fonts::label($w['font']);
            $lines[] = '워터마크: "' . $w['text'] . '" · ' . $font . ' ' . $w['size'] . 'px · '
                     . $w['color'] . ' · 불투명도 ' . (int) round($w['opacity'] * 100) . '%';
        }

        foreach ($p['subtitles'] ?? [] as $i => $sub) {
            $tpl = match ($sub['template']) {
                'plain' => '기본', 'box' => '반투명 박스', 'whitebox' => '흰 박스',
                'highlight' => '노란 강조', 'heavy' => '굵은 외곽선', 'blackbox' => '검정 박스',
                'grayline' => '회색 외곽선', 'glow' => '외곽선 + 번짐', 'softglow' => '번짐',
                'yellowline' => '노랑 굵은 외곽선', 'invert' => '검정 글자 + 흰 외곽선',
                'softbox' => '흰 반투명 박스', 'drop' => '큰 그림자', 'neon' => '네온',
                default => '외곽선',
            };
            $own = count(array_filter($sub['cues'], static fn ($c) => ! empty($c['style'])));
            $lines[] = '자막 ' . ($i + 1) . ': ' . count($sub['cues']) . '개 · ' . $tpl . ' · '
                     . \App\Libraries\Fonts::label($sub['font']) . ' ' . $sub['size'] . 'px · ' . $sub['color']
                     . ($own ? ' · 개별 스타일 ' . $own . '개' : '');
        }

        if ($p['speed'] != 1.0) {
            $lines[] = '속도: ' . rtrim(rtrim(number_format($p['speed'], 2), '0'), '.') . '배'
                     . ($p['speed'] < 1 ? ' (슬로우)' : '') . ' · ' . ($p['keepAudio'] ? '오디오 유지' : '무음');
        }

        $sharpen = $p['enhance']['sharpen'] ?? 'off';
        if ($sharpen !== 'off') {
            $lines[] = '화질: ' . match ($sharpen) { 'low' => '약하게', 'high' => '강하게', default => '보통' }
                     . ' 선명화' . (! empty($p['enhance']['denoise']) ? ' · 압축 노이즈 정리' : '');
        }

        $mode = $p['restore']['mode'] ?? 'off';
        if ($mode !== 'off') {
            $lines[] = 'AI 복원: ' . ($mode === 'ai2x' ? '2배 확대' : '디테일 복원')
                     . ' · ' . (($p['restore']['model'] ?? 'general') === 'anime' ? '애니메이션 모델' : '실사 모델');
        }

        $smooth = $p['smooth'] ?? 'off';
        if ($smooth !== 'off') {
            $lines[] = '프레임 생성: ' . match ($smooth) {
                'x2' => '2배 (부드럽게)', 'x4' => '4배 (부드럽게)', default => '슬로우 보정',
            };
        }

        $ex = $p['expand'] ?? ['w' => 1, 'h' => 1];
        if ($ex['w'] > 1 || $ex['h'] > 1) {
            $lines[] = '프레임 확장: 가로 ' . rtrim(rtrim(number_format($ex['w'], 2), '0'), '.') . '배 · 세로 '
                     . rtrim(rtrim(number_format($ex['h'], 2), '0'), '.') . '배 (바깥 영역 AI 생성)';
        }

        $o = $p['output'];
        $lines[] = '출력: ' . strtoupper($o['format'])
                 . ' · ' . ($o['height'] > 0 ? $o['height'] . 'p' : '원본 해상도')
                 . ' · ' . ($o['quality'] === 'medium' ? '작은 용량' : '높은 품질');

        return "[적용한 처리]\n" . implode("\n", $lines);
    }

    /** Total output duration in seconds. */
    public static function outputDuration(array $p): float
    {
        $t = 0.0;
        foreach ($p['keep'] as [$s, $e]) $t += $e - $s;
        return $t / $p['speed'];
    }
}
