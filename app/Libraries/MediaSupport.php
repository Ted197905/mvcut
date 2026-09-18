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

    /** Host suffixes the image fetcher may download from (social CDNs). */
    public const IMAGE_HOSTS = [
        'cdninstagram.com', 'fbcdn.net', 'instagram.com', 'facebook.com',
        'twimg.com', 'twitter.com', 'x.com',
        'threads.net', 'threads.com',
        'ytimg.com', 'ggpht.com', 'youtube.com',
    ];

    /** True when the URL is https, on an allowed CDN host, and not pointing at a private address. */
    public static function imageUrlAllowed(string $url): bool
    {
        $parts = parse_url($url);
        if (! $parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) return false;
        $host = strtolower($parts['host']);
        $ok = false;
        foreach (self::IMAGE_HOSTS as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.' . $suffix)) { $ok = true; break; }
        }
        if (! $ok) return false;
        return self::hostIsPublic($host);
    }

    public static function hostIsPublic(string $host): bool
    {
        $ips = @gethostbynamel($host) ?: [];
        if ($ips === []) return false;
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
        }
        return true;
    }

    /** Fetches a page and extracts og:image / twitter:image candidates. */
    public static function scrapeImages(string $url): array
    {
        $out = Ffmpeg::run(['curl', '-sL', '--max-redirs', '3', '--max-time', '25', '--compressed',
            '-A', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36',
            '-H', 'Accept-Language: en-US,en;q=0.9', $url], 30);
        if ($out['code'] !== 0 || $out['stdout'] === '') return [];
        $html = $out['stdout'];
        $found = [];
        foreach (['og:image', 'og:image:secure_url', 'twitter:image', 'twitter:image:src'] as $prop) {
            if (preg_match_all('/<meta[^>]+(?:property|name)=["\']' . preg_quote($prop, '/') . '["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) {
                foreach ($m[1] as $u) $found[] = html_entity_decode($u, ENT_QUOTES | ENT_HTML5);
            }
            if (preg_match_all('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+(?:property|name)=["\']' . preg_quote($prop, '/') . '["\']/i', $html, $m)) {
                foreach ($m[1] as $u) $found[] = html_entity_decode($u, ENT_QUOTES | ENT_HTML5);
            }
        }
        $urls = [];
        foreach (array_unique($found) as $u) {
            if (self::imageUrlAllowed($u)) $urls[] = $u;
        }
        return array_slice($urls, 0, 20);
    }

    /** Social titles are often a whole caption; keep the first clause and a sane length. */
    /** 3200 -> "3.2천", 128811 -> "12.9만" (Korean compact) */
    public static function countKo(int $n): string
    {
        if ($n < 1000) return number_format($n);
        $trim = static fn (float $v) => rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
        if ($n < 10000) return $trim($n / 1000) . '천';
        if ($n < 100000000) return $trim($n / 10000) . '만';
        return $trim($n / 100000000) . '억';
    }

    /** yt-dlp upload_date "20260901" -> "2026년 9월 1일" */
    public static function uploadDateKo(?string $ymd): ?string
    {
        if (! $ymd || ! preg_match('/^(\d{4})(\d{2})(\d{2})$/', $ymd, $m)) return null;
        return sprintf('%d년 %d월 %d일', (int) $m[1], (int) $m[2], (int) $m[3]);
    }

    /** Escapes text, then turns URLs and #hashtags into links. Keeps line breaks. */
    public static function richText(string $text, string $platform = ''): string
    {
        $hashBase = match ($platform) {
            'youtube'  => 'https://www.youtube.com/hashtag/',
            'x'        => 'https://x.com/hashtag/',
            'instagram'=> 'https://www.instagram.com/explore/tags/',
            'threads'  => 'https://www.threads.com/search?q=%23',
            'facebook' => 'https://www.facebook.com/hashtag/',
            default    => null,
        };
        $out = esc($text);
        $out = preg_replace_callback('~https?://[^\s<]+~u', static function ($m) {
            $url = rtrim($m[0], '.,)');
            $tail = substr($m[0], strlen($url));
            $label = mb_strlen($url) > 60 ? mb_substr($url, 0, 57) . '…' : $url;
            return '<a href="' . $url . '" target="_blank" rel="noopener noreferrer nofollow">' . $label . '</a>' . $tail;
        }, $out);
        $out = preg_replace_callback('/(^|[\s(])#([\p{L}\p{N}_]{1,60})/u', static function ($m) use ($hashBase) {
            if (! $hashBase) return $m[1] . '<span class="tag">#' . $m[2] . '</span>';
            return $m[1] . '<a class="tag" href="' . $hashBase . rawurlencode($m[2]) . '" target="_blank" rel="noopener noreferrer nofollow">#' . $m[2] . '</a>';
        }, $out);
        return nl2br($out, false);
    }

    public static function size(int $bytes): string
    {
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1048576) return number_format($bytes / 1024) . ' KB';
        if ($bytes < 1073741824) return number_format($bytes / 1048576, 1) . ' MB';
        return number_format($bytes / 1073741824, 2) . ' GB';
    }

    public static function tidyTitle(string $raw, string $fallback = 'imported'): string
    {
        $t = preg_replace('/\s+/u', ' ', trim($raw));
        foreach ([' | ', ' — ', ' · '] as $sep) {
            $pos = mb_strpos($t, $sep);
            if ($pos !== false && $pos >= 8) { $t = mb_substr($t, 0, $pos); break; }
        }
        $t = preg_replace('/\s*(https?:\/\/\S+)\s*/u', ' ', $t);
        $t = trim(preg_replace('/\s+/u', ' ', $t));
        if (mb_strlen($t) > 90) {
            $cut = mb_substr($t, 0, 90);
            $sp  = mb_strrpos($cut, ' ');
            $t   = rtrim($sp > 40 ? mb_substr($cut, 0, $sp) : $cut) . '…';
        }
        return $t !== '' ? $t : $fallback;
    }

    public static function ytdlp(): ?string
    {
        foreach ([ROOTPATH . 'bin/yt-dlp', '/usr/local/bin/yt-dlp', '/usr/bin/yt-dlp'] as $p) {
            if (is_executable($p)) return $p;
        }
        return null;
    }
}
