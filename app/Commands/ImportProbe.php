<?php

namespace App\Commands;

use App\Libraries\MediaSupport;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class ImportProbe extends BaseCommand
{
    protected $group       = 'mvcut';
    protected $name        = 'import:probe';
    protected $description = 'Debug: list image candidates a URL exposes via og:image / twitter:image.';
    protected $usage       = 'import:probe <url>';

    public function run(array $params)
    {
        $url = $params[0] ?? '';
        if ($url === '') { CLI::error('usage: import:probe <url>'); return; }
        CLI::write('platform: ' . (MediaSupport::platformOf($url) ?? 'NOT ALLOWED'));
        $imgs = MediaSupport::scrapeImages($url);
        CLI::write('images: ' . count($imgs));
        foreach ($imgs as $i) CLI::write('  ' . mb_substr($i, 0, 140));
    }
}
