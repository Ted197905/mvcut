<?php

namespace App\Commands;

use App\Libraries\EditParams;
use App\Libraries\Fonts;
use App\Libraries\JobRunner;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/** Renders one watermark sample per installed font, for visual checking. */
class WatermarkTest extends BaseCommand
{
    protected $group       = 'mvcut';
    protected $name        = 'watermark:test';
    protected $description = 'Render a watermark sample clip for each installed font into /tmp.';

    public function run(array $params)
    {
        $src = $params[0] ?? '/tmp/portrait.mp4';
        if (! is_file($src)) { CLI::error('source missing: ' . $src); return; }
        $media  = ['duration' => 8, 'width' => 720, 'height' => 1280, 'acodec' => 'aac', 'fps' => 30];
        $runner = new JobRunner();
        foreach (array_keys(Fonts::available()) as $key) {
            $p = EditParams::normalize([
                'keep'      => [[0, 2]],
                'watermark' => [
                    'text' => 'MV Cut 워터마크 "' . $key . '" 100%', 'font' => $key, 'size' => 44,
                    'color' => '#ffcc00', 'opacity' => 0.9, 'x' => 360, 'y' => 1200, 'anchor' => 's', 'style' => 'outline',
                ],
                'output' => ['format' => 'mp4'],
            ], $media);
            $out = '/tmp/wm_' . $key . '.mp4';
            $cmd = $runner->buildEditCommand($src, $out, $p, $media);
            if (($params[1] ?? '') === 'dump') {
                $i = array_search('-filter_complex', $cmd, true);
                CLI::write('FILTER: ' . $cmd[$i + 1]);
            }
            $r = \App\Libraries\Ffmpeg::run($cmd, 120);
            CLI::write(($r['code'] === 0 && is_file($out) ? 'ok   ' : 'FAIL ') . $key . ($r['code'] !== 0 ? ' :: ' . mb_substr(trim($r['stderr']), -220) : ''));
        }
    }
}
