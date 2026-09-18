<?php

namespace App\Libraries;

class MediaSupport
{
    public const IMPORT_HOSTS = [
        'instagram.com', 'www.instagram.com',
        'facebook.com', 'www.facebook.com', 'm.facebook.com', 'fb.watch', 'fb.com',
        'x.com', 'twitter.com', 'www.x.com', 'www.twitter.com', 'mobile.twitter.com',
        'threads.net', 'www.threads.net', 'threads.com', 'www.threads.com',
        'youtube.com', 'www.youtube.com', 'youtu.be', 'm.youtube.com',
    ];

    /** Can the browser <video> play the original as-is? */
    public static function browserPlayable(array $m): bool
    {
        if ($m['media_type'] !== 'video') return false;
        $containerOk = in_array($m['container'], ['mov', 'mp4', 'm4a', '3gp', '3g2', 'mj2', 'matroska', 'webm'], true);
        $codecOk     = in_array($m['vcodec'], ['h264', 'vp8', 'vp9', 'av1'], true);
        $audioOk     = empty($m['acodec']) || in_array($m['acodec'], ['aac', 'mp3', 'opus', 'vorbis', 'flac'], true);
        return $containerOk && $codecOk && $audioOk;
    }

    /** Should we build a lightweight proxy for editing? */
    public static function needsProxy(array $m): bool
    {
        if ($m['media_type'] !== 'video') return false;
        if (! self::browserPlayable($m)) return true;
        if ((int) $m['height'] > 1080 || (int) $m['width'] > 1920) return true;
        $dur = (float) $m['duration'];
        if ($dur > 0 && ((int) $m['size'] * 8 / $dur) > 12_000_000) return true; // > 12 Mbps
        return false;
    }

    /** Platform key from URL, or null when not allowed. */
    public static function platformOf(string $url): ?string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($host === '' || ! in_array($scheme, ['http', 'https'], true) || ! in_array($host, self::IMPORT_HOSTS, true)) return null;
        if (str_contains($host, 'instagram')) return 'instagram';
        if (str_contains($host, 'facebook') || str_starts_with($host, 'fb.')) return 'facebook';
        if (str_contains($host, 'twitter') || $host === 'x.com' || $host === 'www.x.com') return 'x';
        if (str_contains($host, 'threads')) return 'threads';
        if (str_contains($host, 'youtu')) return 'youtube';
        return null;
    }

    public static function ytdlp(): ?string
    {
        foreach ([ROOTPATH . 'bin/yt-dlp', '/usr/local/bin/yt-dlp', '/usr/bin/yt-dlp'] as $p) {
            if (is_executable($p)) return $p;
        }
        return null;
    }
}
