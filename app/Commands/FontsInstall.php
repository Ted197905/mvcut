<?php

namespace App\Commands;

use App\Libraries\Fonts;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/** Downloads the watermark fonts (kept out of git because they are binaries). */
class FontsInstall extends BaseCommand
{
    protected $group       = 'mvcut';
    protected $name        = 'fonts:install';
    protected $description = 'Download the SIL OFL watermark fonts into fonts/.';

    public function run(array $params)
    {
        $dir = Fonts::dir();
        if (! is_dir($dir) && ! mkdir($dir, 0775, true)) {
            CLI::error('cannot create ' . $dir);
            return;
        }
        foreach (Fonts::LIST as $key => $f) {
            $dest = $dir . '/' . $f['file'];
            if (is_file($dest)) { CLI::write("skip {$key} (already installed)"); continue; }
            if (! empty($f['zipPath'])) {
                $zip = sys_get_temp_dir() . '/mvcut-font-' . $key . '.zip';
                $this->curl($f['url'], $zip);
                $za = new \ZipArchive();
                if ($za->open($zip) === true) {
                    $data = $za->getFromName($f['zipPath']);
                    if ($data !== false) file_put_contents($dest, $data);
                    $za->close();
                }
                @unlink($zip);
            } else {
                $this->curl($f['url'], $dest);
                if (! empty($f['licUrl'])) $this->curl($f['licUrl'], $dir . '/LICENSE-' . $key . '.txt');
            }
            @chmod($dest, 0664);
            CLI::write((is_file($dest) ? 'ok   ' : 'FAIL ') . $key . ' ' . $f['file']);
        }
    }

    private function curl(string $url, string $dest): void
    {
        \App\Libraries\Ffmpeg::run(['curl', '-sfL', '--max-time', '180', '-o', $dest, $url], 190);
    }
}
