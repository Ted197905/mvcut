<?php

namespace App\Libraries;

use App\Models\YtVideoModel;

/**
 * /youtube pipeline: download the video with every subtitle the video offers (the auto-generated
 * one when it offers none), keep title/description, then let the local LLM list the people in it
 * and summarize it.
 */
class YouTubeTool
{
    private const PREFER = ['ko', 'ko-orig', 'en', 'en-orig', 'ja', 'ja-orig'];

    /** Characters of transcript one LLM call gets; longer transcripts are summarized in parts first. */
    private const CHUNK = 7000;
    private const MAX_CHUNKS = 6;

    public function __construct(private YtVideoModel $videos = new YtVideoModel())
    {
    }

    public static function videoId(string $url): ?string
    {
        $key = MediaSupport::urlKey($url);
        return str_starts_with($key, 'yt:') && preg_match('/^[\w-]{6,32}$/', substr($key, 3)) ? substr($key, 3) : null;
    }

    /** Runs the whole pipeline for one row; the worker calls this. */
    public function fetch(int $id, callable $log): void
    {
        $row = $this->videos->find($id);
        if (! $row) throw new \RuntimeException('yt row missing');
        $bin = MediaSupport::ytdlp();
        if (! $bin) throw new \RuntimeException('yt-dlp not installed');
        $dir = YtVideoModel::dir($row);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true)) throw new \RuntimeException('cannot create ' . $dir);
        MediaIntake::relax(dirname($dir)); MediaIntake::relax($dir);
        $this->videos->update($id, ['status' => 'downloading', 'error' => null]);

        try {
            $cookies = MediaSupport::cookieFile('youtube');
            // 1. metadata first: title, text and which subtitles exist
            $args = [$bin, '--no-warnings', '--no-playlist', '--skip-download', '--dump-single-json', '--socket-timeout', '30'];
            if ($cookies) array_push($args, '--cookies', $cookies);
            $args[] = $row['url'];
            $r = Ffmpeg::run($args, 180);
            $info = $r['code'] === 0 ? json_decode($r['stdout'], true) : null;
            if (! is_array($info)) {
                throw new \RuntimeException('정보를 가져오지 못했습니다: ' . mb_substr(trim(preg_replace('/^ERROR:\s*/m', '', $r['stderr'])), -600));
            }
            $up = (string) ($info['upload_date'] ?? '');
            $this->videos->update($id, [
                'title'       => mb_substr(trim((string) ($info['title'] ?? '')), 0, 255),
                'channel'     => mb_substr(trim((string) ($info['channel'] ?? $info['uploader'] ?? '')), 0, 255),
                'description' => (string) ($info['description'] ?? ''),
                'uploaded_at' => preg_match('/^\d{8}$/', $up) ? substr($up, 0, 4) . '-' . substr($up, 4, 2) . '-' . substr($up, 6, 2) : null,
                'duration'    => isset($info['duration']) ? (float) $info['duration'] : null,
            ]);

            // 2. the video, with its subtitles: every real one, or the auto-generated original
            [$subArgs, $names] = $this->subtitleArgs($info, $log);
            $args = [$bin, '--no-warnings', '--no-playlist', '--socket-timeout', '30', '--retries', '3',
                     // up to 1080p: a 4K source is many times the size for a page that is about the subtitles
                     '-f', 'bv*[height<=1080][ext=mp4]+ba[ext=m4a]/bv*[height<=1080]+ba/b[height<=1080]/b', '--merge-output-format', 'mp4', '--no-mtime',
                     '-o', $dir . '/original.%(ext)s', '-o', 'subtitle:' . $dir . '/sub.%(ext)s',
                     '--sub-format', 'vtt/best', '--convert-subs', 'vtt', ...$subArgs];
            if ($cookies) array_push($args, '--cookies', $cookies);
            $args[] = $row['url'];
            $log('yt-dlp ' . implode(' ', array_map('escapeshellarg', array_slice($args, 1))));
            $r = Ffmpeg::run($args, 1800);
            $files = array_values(array_filter(glob($dir . '/original.*') ?: [], static fn ($f) => ! str_ends_with($f, '.part')));
            if ($r['code'] !== 0 && $files === []) {
                throw new \RuntimeException('다운로드 실패: ' . mb_substr(trim(preg_replace('/^ERROR:\s*/m', '', $r['stderr'])), -600));
            }
            if ($files === []) throw new \RuntimeException('yt-dlp produced no media file');
            $file = $files[0];
            $update = ['filename' => basename($file), 'size' => filesize($file)];
            if ($meta = Ffmpeg::probe($file)) {
                foreach (['duration', 'width', 'height'] as $k) if (! empty($meta[$k])) $update[$k] = $meta[$k];
                if (Ffmpeg::thumbnail($file, $dir . '/thumb.jpg', $meta['duration'] ?? null)) $update['has_thumb'] = 1;
            }
            $subs = [];
            foreach (glob($dir . '/sub.*.vtt') ?: [] as $f) {
                $lang = substr(basename($f), 4, -4);
                $subs[] = ['lang' => $lang, 'name' => $names[$lang]['name'] ?? $lang, 'auto' => (bool) ($names[$lang]['auto'] ?? false), 'file' => basename($f)];
            }
            // the language the video is in first, then our preferred ones
            usort($subs, fn ($a, $b) => $this->rank($a['lang'], $info) <=> $this->rank($b['lang'], $info));
            $update['subs'] = json_encode($subs, JSON_UNESCAPED_UNICODE);
            $update['status'] = 'analyzing';
            $this->videos->update($id, $update);
            MediaIntake::relax($dir);
            $log(count($subs) . ' subtitle file(s): ' . implode(', ', array_column($subs, 'lang')));

            // 3. who is in it and what it says
            $this->analyze($id, $log);
            $this->videos->update($id, ['status' => 'ready']);
        } catch (\Throwable $e) {
            $this->videos->update($id, ['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 2000)]);
            throw $e;
        }
    }

    /** @return array{0: string[], 1: array<string, array{name: string, auto: bool}>} yt-dlp args and lang → label */
    private function subtitleArgs(array $info, callable $log): array
    {
        $ok = static fn ($l) => is_string($l) && preg_match('/^[\w.-]+$/', $l) && $l !== 'live_chat';
        $names = [];
        $manual = array_values(array_filter(array_keys((array) ($info['subtitles'] ?? [])), $ok));
        foreach ($manual as $l) $names[$l] = ['name' => (string) ($info['subtitles'][$l][0]['name'] ?? $l), 'auto' => false];
        if ($manual !== []) {
            $log('subtitles offered: ' . implode(', ', $manual));
            return [['--write-subs', '--sub-langs', implode(',', $manual)], $names];
        }
        $auto = array_values(array_filter(array_keys((array) ($info['automatic_captions'] ?? [])), $ok));
        if ($auto === []) {
            $log('no subtitles on this video');
            return [[], []];
        }
        // YouTube auto-generates captions in the spoken language and machine-translates the rest:
        // take the spoken one only ("xx-orig" when yt-dlp marks it, else the video's language)
        $orig = array_values(array_filter($auto, static fn ($l) => str_ends_with($l, '-orig')));
        if ($orig === []) {
            $lang = (string) ($info['language'] ?? '');
            $orig = array_values(array_filter($auto, static fn ($l) => $l === $lang || ($lang !== '' && str_starts_with($l, $lang . '-'))));
        }
        if ($orig === []) $orig = array_values(array_intersect(['ko', 'en', 'ja'], $auto)) ?: [$auto[0]];
        $orig = array_slice($orig, 0, 2);
        foreach ($orig as $l) $names[$l] = ['name' => (string) ($info['automatic_captions'][$l][0]['name'] ?? $l), 'auto' => true];
        $log('no real subtitles; auto-generated: ' . implode(', ', $orig));
        return [['--write-auto-subs', '--sub-langs', implode(',', $orig)], $names];
    }

    private function rank(string $lang, array $info): int
    {
        $spoken = (string) ($info['language'] ?? '');
        if ($spoken !== '' && ($lang === $spoken || str_starts_with($lang, $spoken . '-'))) return 0;
        $i = array_search($lang, self::PREFER, true);
        return $i === false ? 10 : 1 + $i;
    }

    /** The subtitle used for analysis and the plain-text transcript download. */
    public static function transcriptSub(array $row): ?array
    {
        $subs = YtVideoModel::subs($row);
        if ($subs === []) return null;
        foreach (self::PREFER as $l) {
            foreach ($subs as $s) if ($s['lang'] === $l) return $s;
        }
        return $subs[0];
    }

    /** WebVTT → plain text: no timing, no tags, no rolling duplicates from auto captions. */
    public static function vttText(string $vtt): string
    {
        $out  = [];
        $last = '';
        foreach (preg_split('/\R/u', $vtt) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, 'WEBVTT') || str_starts_with($line, 'Kind:') || str_starts_with($line, 'Language:')
                || str_starts_with($line, 'NOTE') || str_starts_with($line, 'STYLE') || preg_match('/^\d+$/', $line)
                || preg_match('/^\d{1,2}:\d{2}(:\d{2})?[.,]\d{3}\s*-->/', $line)) continue;
            $line = trim(html_entity_decode(preg_replace('/<[^>]*>/u', '', $line), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $line = preg_replace('/\s+/u', ' ', $line);
            if ($line === '' || $line === $last) continue;
            $out[] = $line;
            $last  = $line;
        }
        return implode("\n", $out);
    }

    /** VTT → SRT for players that want it. */
    public static function vttToSrt(string $vtt): string
    {
        $blocks = [];
        $n = 0;
        foreach (preg_split('/\R{2,}/u', trim($vtt)) as $block) {
            $lines = preg_split('/\R/u', trim($block));
            $ti = null;
            foreach ($lines as $i => $l) if (str_contains($l, '-->')) { $ti = $i; break; }
            if ($ti === null) continue;
            $time = preg_replace_callback('/(\d{1,2}:)?(\d{2}):(\d{2})\.(\d{3})/', static function ($m) {
                return ($m[1] !== '' ? str_pad(rtrim($m[1], ':'), 2, '0', STR_PAD_LEFT) : '00') . ':' . $m[2] . ':' . $m[3] . ',' . $m[4];
            }, preg_replace('/\s+(align|position|line|size):\S+/', '', $lines[$ti]));
            $text = trim(implode("\n", array_map(static fn ($l) => preg_replace('/<[^>]*>/u', '', $l), array_slice($lines, $ti + 1))));
            if ($text === '') continue;
            $blocks[] = (++$n) . "\n" . $time . "\n" . $text;
        }
        return implode("\n\n", $blocks) . "\n";
    }

    /** Fills cast and summary from title, description and the transcript. Safe to run again. */
    public function analyze(int $id, callable $log): void
    {
        $row = $this->videos->find($id);
        if (! $row) throw new \RuntimeException('yt row missing');
        if (! Ollama::available()) {
            $log('Ollama is not running; skipping analysis');
            $this->videos->update($id, ['summary' => null, 'cast' => null, 'error' => 'Ollama 서버가 응답하지 않아 분석을 건너뛰었습니다.']);
            return;
        }
        $transcript = '';
        if ($sub = self::transcriptSub($row)) {
            $transcript = self::vttText((string) @file_get_contents(YtVideoModel::dir($row) . '/' . $sub['file']));
        }
        $desc = trim((string) $row['description']);
        $head = "제목: {$row['title']}\n채널: {$row['channel']}\n\n설명:\n" . ($desc !== '' ? mb_substr($desc, 0, 3000) : '(없음)');

        $system = '당신은 유튜브 영상의 자막과 설명을 읽고 내용을 정리하는 분석가입니다. 반드시 한국어로 답합니다. '
                . '자막은 음성 인식 결과라 오타와 끊김이 있을 수 있으니 문맥으로 바로잡아 읽습니다. 추측으로 사실을 지어내지 않습니다.';

        // long transcripts: notes per part first, then one pass over the notes
        $notes = [];
        if (mb_strlen($transcript) > self::CHUNK) {
            $chunks = $this->chunks($transcript);
            $log('transcript ' . mb_strlen($transcript) . ' chars → ' . count($chunks) . ' part(s)');
            foreach ($chunks as $i => $c) {
                $r = Ollama::generate($system, $head . "\n\n자막 (" . ($i + 1) . '/' . count($chunks) . " 부분):\n" . $c
                    . "\n\n이 부분에서 다룬 내용을 한국어로 5~10줄로 메모하세요. 등장하는 사람 이름과 역할이 있으면 그대로 적으세요. 메모만 출력합니다.");
                $notes[] = (string) $r;
            }
        }
        $body = $notes !== []
            ? "자막 요약 메모 (" . count($notes) . " 부분):\n" . implode("\n\n---\n\n", $notes)
            : ($transcript !== '' ? "자막 전문:\n" . $transcript : '자막: (없음) - 제목과 설명에 적힌 내용만 정리하고, 거기에 없는 줄거리나 장면은 쓰지 마세요.');
        $ask = $head . "\n\n" . $body . "\n\n위 자료로 다음 JSON 객체 하나만 출력하세요. 키: "
             . '"cast" - 영상에 출연하거나 자막·설명에서 이름이 확인되는 인물의 배열, 각 원소는 {"name": 이름, "role": 역할이나 소속 한 줄}. 확인되지 않으면 빈 배열. '
             . '"summary" - 영상 전체 내용을 한국어로 정리한 요약. 흐름이 보이도록 3~6개 문단으로, 문단 사이는 빈 줄 하나.';
        $r = Ollama::generate($system, $ask, true);
        if (! is_array($r)) throw new \RuntimeException('분석 결과를 해석하지 못했습니다.');
        $cast = [];
        foreach ((array) ($r['cast'] ?? []) as $c) {
            $name = trim((string) (is_array($c) ? ($c['name'] ?? '') : $c));
            if ($name === '') continue;
            $cast[] = ['name' => mb_substr($name, 0, 80), 'role' => mb_substr(trim((string) (is_array($c) ? ($c['role'] ?? '') : '')), 0, 160)];
        }
        $summary = is_array($r['summary'] ?? null) ? implode("\n\n", array_map('strval', $r['summary'])) : trim((string) ($r['summary'] ?? ''));
        $this->videos->update($id, [
            'cast'    => json_encode($cast, JSON_UNESCAPED_UNICODE),
            'summary' => $summary !== '' ? $summary : null,
            'error'   => null,
        ]);
        $log('analysis done: ' . count($cast) . ' people, summary ' . mb_strlen($summary) . ' chars');
    }

    /** @return string[] */
    private function chunks(string $text): array
    {
        $lines = preg_split('/\R/u', $text);
        $chunks = []; $cur = '';
        foreach ($lines as $l) {
            if ($cur !== '' && mb_strlen($cur) + mb_strlen($l) + 1 > self::CHUNK) { $chunks[] = $cur; $cur = ''; }
            $cur .= ($cur === '' ? '' : "\n") . $l;
        }
        if ($cur !== '') $chunks[] = $cur;
        if (count($chunks) <= self::MAX_CHUNKS) return $chunks;
        // very long videos: evenly spaced parts keep the whole arc within one model budget
        $pick = [];
        for ($i = 0; $i < self::MAX_CHUNKS; $i++) $pick[] = $chunks[(int) floor($i * (count($chunks) - 1) / (self::MAX_CHUNKS - 1))];
        return $pick;
    }
}
