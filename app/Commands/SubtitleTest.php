<?php

namespace App\Commands;

use App\Libraries\EditParams;
use App\Libraries\JobRunner;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/** Renders one subtitle sample per design template, for visual checking. */
class SubtitleTest extends BaseCommand
{
    protected $group       = 'mvcut';
    protected $name        = 'subtitle:test';
    protected $description = 'Render a sample clip and a still frame for each subtitle template into /tmp.';

    /** colour that suits each design, so the sample looks like the template is meant to */
    private const COLORS = [
        'blackbox' => '#ffd60a', 'glow' => '#ff2d2d', 'softglow' => '#ff7ab6', 'neon' => '#30d5ff',
    ];

    public function run(array $params)
    {
        $src = $params[0] ?? '/tmp/portrait.mp4';
        if (! is_file($src)) { CLI::error('source missing: ' . $src); return; }
        $media  = ['duration' => 8, 'width' => 720, 'height' => 1280, 'acodec' => 'aac', 'fps' => 30];
        $runner = new JobRunner();

        // one clip where the second line overrides the layer, to check per-line styling
        $p = EditParams::normalize([
            'keep'      => [[0, 3]],
            'subtitles' => [[
                'template' => 'outline', 'size' => 44, 'color' => '#ffffff',
                'anchor' => 's', 'x' => 360, 'y' => 1100,
                'cues' => [
                    ['start' => 0, 'end' => 1.4, 'text' => '레이어 스타일'],
                    ['start' => 1.5, 'end' => 3, 'text' => '이 문장만 다르게',
                     'style' => ['template' => 'blackbox', 'color' => '#ffd60a', 'size' => 64, 'anchor' => 'n', 'y' => 260]],
                ],
            ]],
            'output' => ['format' => 'mp4'],
        ], $media);
        $out = '/tmp/sub_mixed.mp4';
        $r = \App\Libraries\Ffmpeg::run($runner->buildEditCommand($src, $out, $p, $media), 120);
        if ($r['code'] === 0) {
            foreach ([['0.7', 'a'], ['2.0', 'b']] as [$at, $tag]) {
                \App\Libraries\Ffmpeg::run([\App\Libraries\Ffmpeg::bin('ffmpeg'), '-y', '-v', 'error',
                    '-ss', $at, '-i', $out, '-frames:v', '1', '/tmp/sub_mixed_' . $tag . '.png'], 60);
            }
        }
        CLI::write(($r['code'] === 0 ? 'ok   ' : 'FAIL ') . 'mixed (per-line style)'
            . ($r['code'] !== 0 ? ' :: ' . mb_substr(trim($r['stderr']), -220) : ''));

        foreach (EditParams::TEMPLATES as $tpl) {
            $p = EditParams::normalize([
                'keep'      => [[0, 2]],
                'subtitles' => [[
                    'template' => $tpl, 'size' => 54, 'color' => self::COLORS[$tpl] ?? '#ffffff',
                    'anchor' => 's', 'x' => 360, 'y' => 1100,
                    'cues' => [['start' => 0, 'end' => 2, 'text' => '자막 ' . $tpl]],
                ]],
                'output' => ['format' => 'mp4'],
            ], $media);
            $out = '/tmp/sub_' . $tpl . '.mp4';
            $cmd = $runner->buildEditCommand($src, $out, $p, $media);
            if (($params[1] ?? '') === 'dump') {
                $i = array_search('-filter_complex', $cmd, true);
                CLI::write('FILTER: ' . $cmd[$i + 1]);
            }
            $r = \App\Libraries\Ffmpeg::run($cmd, 120);
            if ($r['code'] === 0 && is_file($out)) {
                \App\Libraries\Ffmpeg::run([\App\Libraries\Ffmpeg::bin('ffmpeg'), '-y', '-v', 'error',
                    '-ss', '1', '-i', $out, '-frames:v', '1', '/tmp/sub_' . $tpl . '.png'], 60);
            }
            CLI::write(($r['code'] === 0 && is_file($out) ? 'ok   ' : 'FAIL ') . $tpl
                . ($r['code'] !== 0 ? ' :: ' . mb_substr(trim($r['stderr']), -220) : ''));
        }
    }
}
