<?php

namespace App\Libraries;

/**
 * A collected post's plain-text body as HTML, the way the boards themselves show it: a line that is
 * just a link to an X post, an Instagram post, a TikTok video or a YouTube video becomes an embedded
 * player; a line that is just one of the post's collected images is dropped (the picture is shown
 * in the media grid); other links are clickable.
 */
class CollectBody
{
    private const URL = '~https?://[^\s<>"\']+~u';

    /**
     * @param string[] $skip   URLs already shown elsewhere (collected media source URLs, embedded YouTube links)
     */
    public static function render(string $text, array $skip = []): string
    {
        $skipSet = [];
        foreach ($skip as $u) $skipSet[self::norm($u)] = true;
        $ytDone = [];
        foreach ($skip as $u) if (preg_match('~(?:youtube\.com/(?:embed/|watch\?v=|shorts/)|youtu\.be/)([A-Za-z0-9_-]{11})~', $u, $m)) $ytDone[$m[1]] = true;
        $out = [];
        foreach (preg_split('/\R/u', $text) as $line) {
            $t = trim($line);
            if ($t !== '' && preg_match(self::URL, $t, $m) && $m[0] === rtrim($t, '.,)]')) {
                $u = $m[0];
                if (isset($skipSet[self::norm($u)])) continue;
                $embed = self::embed($u, $ytDone);
                if ($embed !== null) { $out[] = $embed; continue; }
            }
            $out[] = self::linkify($line);
        }
        return implode("\n", $out);
    }

    private static function norm(string $u): string
    {
        return rtrim(preg_replace('~^https?://(www\.)?~', '', $u), '/');
    }

    /** An iframe block for a link the boards embed, or null. */
    private static function embed(string $u, array &$ytDone): ?string
    {
        $a = '<a class="cl-embed-src" href="' . esc($u, 'attr') . '" target="_blank" rel="noopener noreferrer">' . esc($u) . '</a>';
        if (preg_match('~^https?://(?:www\.|mobile\.)?(?:x|twitter)\.com/[^/]+/status/(\d+)~', $u, $m)) {
            return '<div class="cl-embed x">' . $a . '<iframe src="https://platform.twitter.com/embed/Tweet.html?id=' . $m[1]
                 . '&theme=light&width=550&dnt=true" allowfullscreen loading="lazy" title="X"></iframe></div>';
        }
        if (preg_match('~^https?://(?:www\.)?instagram\.com/(?:[^/]+/)?(p|reel|reels)/([A-Za-z0-9_-]+)~', $u, $m)) {
            return '<div class="cl-embed ig">' . $a . '<iframe src="https://www.instagram.com/' . ($m[1] === 'p' ? 'p' : 'reel') . '/' . esc($m[2], 'attr')
                 . '/embed/" allowfullscreen loading="lazy" title="Instagram"></iframe></div>';
        }
        if (preg_match('~^https?://(?:www\.)?tiktok\.com/@[^/]+/video/(\d+)~', $u, $m)) {
            return '<div class="cl-embed tt">' . $a . '<iframe src="https://www.tiktok.com/embed/v2/' . $m[1] . '" allowfullscreen loading="lazy" title="TikTok"></iframe></div>';
        }
        if (preg_match('~(?:youtube\.com/(?:embed/|watch\?v=|shorts/)|youtu\.be/)([A-Za-z0-9_-]{11})~', $u, $m)) {
            if (isset($ytDone[$m[1]])) return '';   // already in the media grid
            $ytDone[$m[1]] = true;
            return '<div class="cl-embed yt">' . $a . '<iframe src="https://www.youtube-nocookie.com/embed/' . $m[1] . '" allowfullscreen loading="lazy" title="YouTube"></iframe></div>';
        }
        return null;
    }

    private static function linkify(string $line): string
    {
        $parts = preg_split(self::URL, $line, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $html = '';
        foreach ($parts as $p) {
            if (preg_match('~^https?://~', $p)) {
                $tail = '';
                if (preg_match('~[.,)\]]+$~', $p, $m)) { $tail = $m[0]; $p = substr($p, 0, -strlen($tail)); }
                $html .= '<a href="' . esc($p, 'attr') . '" target="_blank" rel="noopener noreferrer">' . esc($p) . '</a>' . esc($tail);
            } else {
                $html .= esc($p);
            }
        }
        return $html . '<br>';
    }
}
