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

    /**
     * What each platform can actually do right now.
     *  full    - yt-dlp extractor works without credentials
     *  images  - no video extractor, but og:image scraping works
     *  login   - extractor exists but the site demands cookies
     *  none    - no extractor and the page is JS-only
     */
    public const PLATFORM_SUPPORT = [
        'youtube'   => ['label' => 'YouTube',   'level' => 'full'],
        'x'         => ['label' => 'X',         'level' => 'full'],
        'facebook'  => ['label' => 'Facebook',  'level' => 'full'],
        'instagram' => ['label' => 'Instagram', 'level' => 'login'],
        'threads'   => ['label' => 'Threads',   'level' => 'none'],
    ];

    /**
     * Netscape cookies.txt for a platform, placed on the server by the operator.
     * Never in git; see docs/deploy.html.
     */
    public static function cookieFile(string $platform): ?string
    {
        if (! preg_match('/^[a-z]+$/', $platform)) return null;
        $p = ROOTPATH . 'secrets/cookies_' . $platform . '.txt';
        return is_readable($p) && filesize($p) > 0 ? $p : null;
    }

    /** Support level for a platform: full | login | none. Cookies raise a login-gated platform to full. */
    public static function supportLevel(string $platform): string
    {
        $level = (string) (self::PLATFORM_SUPPORT[$platform]['level'] ?? 'full');
        if ($level === 'login' && self::cookieFile($platform)) return 'full';
        return $level;
    }

    /** User-facing explanation when a platform cannot be imported. */
    public static function unsupportedReason(string $platform): ?string
    {
        $level = self::supportLevel($platform);
        return match ($level) {
            'none'  => 'Threads는 게시물 내용을 로그인 없이 내려주지 않아 자동 가져오기를 지원하지 않습니다. 영상을 직접 저장한 뒤 위 업로드 영역에 올려 주세요. 같은 게시물이 Instagram에도 올라와 있다면 Instagram 링크로 시도해 볼 수 있습니다.',
            'login' => 'Instagram은 현재 로그인 없이는 게시물을 내려주지 않습니다. 영상을 직접 저장한 뒤 업로드하거나, 공개 링크가 있는 다른 플랫폼 주소를 사용해 주세요.',
            default => null,
        };
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
        'ytimg.com', 'ggpht.com', 'googleusercontent.com', 'youtube.com',
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
    /**
     * Curates the fields worth keeping out of a yt-dlp info dict.
     * Everything is optional: the extractors differ per platform.
     */
    public static function curateInfo(array $info): array
    {
        $pick = static function (array $keys) use ($info) {
            foreach ($keys as $k) {
                if (isset($info[$k]) && $info[$k] !== '' && $info[$k] !== []) return $info[$k];
            }
            return null;
        };
        $meta = [
            'channel'      => $pick(['channel', 'uploader']),
            'handle'       => $pick(['uploader_id']),
            'channel_url'  => $pick(['channel_url', 'uploader_url']),
            'subscribers'  => isset($info['channel_follower_count']) ? (int) $info['channel_follower_count'] : null,
            'categories'   => isset($info['categories']) && is_array($info['categories']) ? array_slice($info['categories'], 0, 6) : null,
            'tags'         => isset($info['tags']) && is_array($info['tags']) ? array_slice($info['tags'], 0, 30) : null,
            'language'     => $pick(['language']),
            'availability' => $pick(['availability']),
            'age_limit'    => isset($info['age_limit']) && $info['age_limit'] > 0 ? (int) $info['age_limit'] : null,
            'media_type'   => $pick(['media_type']),
            'live_status'  => $pick(['live_status']),
            'source_res'   => $pick(['resolution']),
            'source_fps'   => isset($info['fps']) ? (float) $info['fps'] : null,
            'format_note'  => $pick(['format_note']),
            'dynamic_range'=> $pick(['dynamic_range']),
            'thumbnail'    => $pick(['thumbnail']),
            'webpage_url'  => $pick(['webpage_url', 'original_url']),
            'timestamp'    => isset($info['timestamp']) ? (int) $info['timestamp'] : null,
            'extractor'    => $pick(['extractor_key', 'extractor']),
        ];
        // the avatar is not in the video info dict; ask the channel page (cached per channel)
        if (! empty($meta['channel_url'])) {
            $avatar = self::channelAvatar((string) $meta['channel_url'], (string) ($info['channel_id'] ?? $meta['channel_url']));
            if ($avatar) $meta['avatar'] = $avatar;
        }
        return array_filter($meta, static fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    /** Largest square channel thumbnail via yt-dlp, cached for a week. */
    public static function channelAvatar(string $channelUrl, string $cacheKey): ?string
    {
        $key = 'avatar_' . md5($cacheKey);
        $hit = cache($key);
        if ($hit !== null) return $hit ?: null;

        $found = '';
        $bin   = self::ytdlp();
        if ($bin) {
            $out = Ffmpeg::run([$bin, '-J', '--no-warnings', '--playlist-items', '0', '--socket-timeout', '15', $channelUrl], 60);
            $j   = trim($out['stdout']) !== '' ? json_decode($out['stdout'], true) : null;
            if (is_array($j)) {
                $best = null;
                foreach ($j['thumbnails'] ?? [] as $t) {
                    $url = (string) ($t['url'] ?? '');
                    if ($url === '' || ! self::imageUrlAllowed($url)) continue;
                    $id = (string) ($t['id'] ?? '');
                    $w  = (int) ($t['width'] ?? 0);
                    $h  = (int) ($t['height'] ?? 0);
                    if ($id === 'avatar_uncropped') { $best = $url; break; }
                    if ($w > 0 && $w === $h && (! $best || $w > 0)) $best = $url;
                }
                $found = (string) ($best ?? '');
            }
        }
        cache()->save($key, $found, 604800);
        return $found ?: null;
    }

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

    /** True when the headless renderer is installed on this server. */
    public static function rendererReady(): bool
    {
        return is_file(ROOTPATH . 'bin/render_media.py') && is_dir(ROOTPATH . 'browsers');
    }

    /**
     * Renders a JavaScript-only post in headless Chromium and returns the media it exposes.
     * Returns null when the renderer is missing or produced no usable JSON.
     *
     * @return array{ok:bool,redirected:bool,title:string,description:string,text:string,videos:string[],images:string[]}|null
     */
    public static function render(string $url, int $timeout = 40): ?array
    {
        if (! self::rendererReady()) return null;
        $env = [
            'PLAYWRIGHT_BROWSERS_PATH' => rtrim(ROOTPATH, '/') . '/browsers',
            'HOME'                     => rtrim(WRITEPATH, '/'),
            'LANG'                     => 'C.UTF-8',
        ];
        $args = ['python3', ROOTPATH . 'bin/render_media.py', $url, '--timeout', (string) $timeout];
        if ($c = self::cookieFile((string) self::platformOf($url))) array_push($args, '--cookies', $c);
        $r = Ffmpeg::run($args, $timeout + 25, $env);
        $j = json_decode(trim($r['stdout']), true);
        if (! is_array($j)) return null;
        $keep = static fn (array $list): array => array_values(array_filter(
            array_map('strval', $list),
            static fn (string $u) => self::imageUrlAllowed($u)
        ));
        return [
            'ok'          => (bool) ($j['ok'] ?? false),
            'redirected'  => ($j['error'] ?? '') === 'redirected',
            'title'       => (string) ($j['title'] ?? ''),
            'description' => (string) ($j['description'] ?? ''),
            'text'        => (string) ($j['text'] ?? ''),
            'videos'      => $keep((array) ($j['videos'] ?? [])),
            'images'      => $keep((array) ($j['images'] ?? [])),
        ];
    }
}
