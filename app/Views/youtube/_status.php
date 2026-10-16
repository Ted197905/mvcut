<?php
// shared: status label + class for a yt_videos row
$ytStatus = static fn (string $s): array => match ($s) {
    'queued'      => ['대기 중', 'wait'],
    'downloading' => ['다운로드 중', 'busy'],
    'analyzing'   => ['분석 중', 'busy'],
    'ready'       => ['완료', 'ok'],
    'failed'      => ['실패', 'bad'],
    default       => [$s, ''],
};
