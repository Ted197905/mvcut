<?php

namespace App\Libraries;

/**
 * X account watch: posting cadence (from the Chrome extension) and restriction checks every 10 minutes
 * (rotating probe account / shadowban.yuzurisa.com / banchecker.org)
 * (bin/xcheck.py with the probe account's cookies). Times are stored in UTC.
 */
class XWatch
{
    public const PROBE = 'xprobe';            // Cookies platform key of the probe account
    public const INTERVAL = 600;              // a check on :00, :10, :20 ... (KST is a whole-hour offset)
    public const SOURCES  = ['probe' => '부계정', 'yuzurisa' => 'yuzurisa', 'banchecker' => 'banchecker'];
    private const YUZURISA   = 'https://shadowban-api.yuzurisa.com:444/';
    private const BANCHECKER = 'https://banchecker.org/api/check-twitter?username=';
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';
    public const KINDS = ['post', 'reply', 'quote', 'repost'];

    private static function db(): \CodeIgniter\Database\BaseConnection
    {
        return \Config\Database::connect();
    }

    public static function forUser(int $userId): array
    {
        $row = self::db()->table('x_watch')->where('user_id', $userId)->get()->getRowArray();
        if ($row) return $row;
        $now = gmdate('Y-m-d H:i:s');
        self::db()->table('x_watch')->insert(['user_id' => $userId, 'created_at' => $now, 'updated_at' => $now]);
        return self::db()->table('x_watch')->where('user_id', $userId)->get()->getRowArray();
    }

    public static function update(int $userId, array $data): void
    {
        $data['updated_at'] = gmdate('Y-m-d H:i:s');
        self::db()->table('x_watch')->where('user_id', $userId)->update($data);
    }

    /** New extension token; only its hash is kept, so the caller shows it once. */
    public static function issueToken(int $userId): string
    {
        self::forUser($userId);
        $token = 'mvx_' . bin2hex(random_bytes(24));
        self::update($userId, ['token_hash' => hash('sha256', $token)]);
        return $token;
    }

    public static function byToken(string $token): ?array
    {
        if (! preg_match('/^mvx_[a-f0-9]{48}$/', $token)) return null;
        return self::db()->table('x_watch')->where('token_hash', hash('sha256', $token))->get()->getRowArray() ?: null;
    }

    public static function cleanHandle(string $h): string
    {
        $h = ltrim(trim($h), '@');
        return preg_match('/^[A-Za-z0-9_]{1,15}$/', $h) ? $h : '';
    }

    /**
     * Stores posts the extension saw. Only the watched handle's posts are kept.
     * @param array<int,array<string,mixed>> $posts
     * @return int newly stored
     */
    public static function savePosts(array $watch, array $posts): int
    {
        $handle = strtolower((string) $watch['handle']);
        if ($handle === '') return 0;
        $new = 0;
        $now = gmdate('Y-m-d H:i:s');
        foreach (array_slice($posts, 0, 300) as $p) {
            if (! is_array($p)) continue;
            $id = (string) ($p['id'] ?? '');
            if (! preg_match('/^\d{5,24}$/', $id)) continue;
            if (strtolower((string) ($p['handle'] ?? '')) !== $handle) continue;
            $ts = strtotime((string) ($p['time'] ?? ''));
            if (! $ts || $ts > time() + 300 || $ts < time() - 14 * 86400) continue;
            $kind = in_array($p['kind'] ?? '', self::KINDS, true) ? $p['kind'] : 'post';
            $row = [
                'user_id' => $watch['user_id'], 'tweet_id' => $id, 'handle' => $watch['handle'], 'kind' => $kind,
                'posted_at' => gmdate('Y-m-d H:i:s', $ts),
                'text' => mb_substr((string) ($p['text'] ?? ''), 0, 300),
                'reply_to' => self::cleanHandle((string) ($p['reply_to'] ?? '')),
            ];
            $t = self::db()->table('x_posts');
            $old = $t->where(['user_id' => $watch['user_id'], 'tweet_id' => $id])->get()->getRowArray();
            if ($old) {
                // a later sighting may know more (reply target, text) than the first one
                $upd = [];
                if ($old['kind'] === 'post' && $kind !== 'post') $upd['kind'] = $kind;
                if ($old['reply_to'] === '' && $row['reply_to'] !== '') $upd['reply_to'] = $row['reply_to'];
                if ($old['text'] === '' && $row['text'] !== '') $upd['text'] = $row['text'];
                if ($upd) self::db()->table('x_posts')->where('id', $old['id'])->update($upd);
                continue;
            }
            $row['captured_at'] = $now;
            self::db()->table('x_posts')->insert($row);
            $new++;
        }
        return $new;
    }

    public static function deletePost(int $userId, string $tweetId): void
    {
        self::db()->table('x_posts')->where(['user_id' => $userId, 'tweet_id' => $tweetId])->delete();
    }

    /**
     * Posts of the last $hours with the gap to the previous counted post, plus cadence warnings.
     * Posts and quotes count toward the limits; replies only when count_replies is on; reposts never.
     */
    public static function timeline(array $watch, int $hours = 48): array
    {
        $uid   = (int) $watch['user_id'];
        $since = gmdate('Y-m-d H:i:s', time() - $hours * 3600);
        $rows  = self::db()->table('x_posts')->where('user_id', $uid)->where('posted_at >=', $since)
            ->orderBy('posted_at', 'ASC')->get()->getResultArray();
        $kinds  = self::countedKinds($watch);
        $before = self::db()->table('x_posts')->where('user_id', $uid)->where('posted_at <', $since)
            ->whereIn('kind', $kinds)->orderBy('posted_at', 'DESC')->limit(1)->get()->getRowArray();

        // second gap that ignores quote posts, shown next to the counted gap
        $plain  = array_values(array_diff($kinds, ['quote']));
        $beforePlain = $plain ? self::db()->table('x_posts')->where('user_id', $uid)->where('posted_at <', $since)
            ->whereIn('kind', $plain)->orderBy('posted_at', 'DESC')->limit(1)->get()->getRowArray() : null;
        $prevPlain = $beforePlain ? strtotime($beforePlain['posted_at'] . ' UTC') : null;

        $gapMin = max(0, (int) $watch['gap_min']);
        $prev   = $before ? strtotime($before['posted_at'] . ' UTC') : null;
        $times  = [];
        $posts  = [];
        $replyTimes = [];
        foreach ($rows as $r) {
            $ts = strtotime($r['posted_at'] . ' UTC');
            $counted = in_array($r['kind'], $kinds, true);
            if ($r['kind'] === 'reply') $replyTimes[] = $ts;
            $gap = ($counted && $prev) ? intdiv($ts - $prev, 60) : null;
            $isPlain  = in_array($r['kind'], $plain, true);
            $gapPlain = ($isPlain && $prevPlain) ? intdiv($ts - $prevPlain, 60) : null;
            $posts[] = ['id' => $r['tweet_id'], 'kind' => $r['kind'], 'ts' => $ts, 'text' => $r['text'],
                        'reply_to' => $r['reply_to'], 'gap' => $gap, 'gap_noquote' => $gapPlain,
                        'short' => $gap !== null && $gap < $gapMin, 'counted' => $counted];
            if ($counted) { $prev = $ts; $times[] = $ts; }
            if ($isPlain) $prevPlain = $ts;
        }

        $now   = time();
        $hour  = count(array_filter($times, static fn ($t) => $t > $now - 3600));
        $day   = count(array_filter($times, static fn ($t) => $t > $now - 86400));
        $last  = $times ? end($times) : $prev;

        // earliest moment a new post breaks none of the three limits
        $next = $now;
        if ($last) $next = max($next, $last + $gapMin * 60);
        $hourMax = max(1, (int) $watch['hour_max']);
        $dayMax  = max(1, (int) $watch['day_max']);
        if ($hour >= $hourMax) {
            $recent = array_values(array_filter($times, static fn ($t) => $t > $now - 3600));
            $next = max($next, $recent[count($recent) - $hourMax] + 3600);
        }
        if ($day >= $dayMax) {
            $recent = array_values(array_filter($times, static fn ($t) => $t > $now - 86400));
            $next = max($next, $recent[count($recent) - $dayMax] + 86400);
        }

        $warn = [];
        if ($last && $now - $last < $gapMin * 60) $warn[] = sprintf('직전 게시 후 %d분 - %d분 간격 권장', intdiv($now - $last, 60), $gapMin);
        if ($hour >= $hourMax) $warn[] = sprintf('최근 1시간 %d개 (기준 %d개 미만)', $hour, $hourMax);
        if ($day >= $dayMax) $warn[] = sprintf('최근 24시간 %d개 (기준 %d개 미만)', $day, $dayMax);
        $short = count(array_filter($posts, static fn ($p) => $p['short']));
        if ($short) $warn[] = sprintf('최근 %d시간 동안 %d분 미만 간격 %d회', $hours, $gapMin, $short);

        $replyHour = count(array_filter($replyTimes, static fn ($t) => $t > $now - 3600));
        return ['posts' => array_reverse($posts), 'hour' => $hour, 'day' => $day, 'last' => $last, 'reply_hour' => $replyHour,
                'next_ok' => $next, 'can_post' => $next <= $now, 'warnings' => $warn];
    }

    /** @return string[] kinds that count toward the cadence limits */
    public static function countedKinds(array $watch): array
    {
        return ! empty($watch['count_replies']) ? ['post', 'quote', 'reply'] : ['post', 'quote'];
    }

    public static function latestCheck(int $userId): ?array
    {
        $r = self::db()->table('x_checks')->where('user_id', $userId)->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();
        return $r ? self::decodeCheck($r) : null;
    }

    public static function history(int $userId, int $n = 48): array
    {
        $rows = self::db()->table('x_checks')->where('user_id', $userId)->orderBy('id', 'DESC')->limit($n)->get()->getResultArray();
        return array_map([self::class, 'decodeCheck'], $rows);
    }

    public static function decodeCheck(array $r): array
    {
        $r['result'] = json_decode((string) $r['result'], true) ?: [];
        $r['ts'] = strtotime($r['checked_at'] . ' UTC');
        return $r;
    }

    public static function probeReady(): bool
    {
        $p = Cookies::path(self::PROBE);
        return $p && is_readable($p) && filesize($p) > 0;
    }

    /** Next :00 or :30 after now (unix time). */
    public static function nextSlot(): int
    {
        return intdiv(time(), self::INTERVAL) * self::INTERVAL + self::INTERVAL;
    }

    /**
     * Checker for the slot that contains $t, rotating every 10 minutes:
     * :00/:30 probe account, :10/:40 shadowban.yuzurisa.com, :20/:50 banchecker.org.
     */
    public static function sourceAt(int $t): string
    {
        $src = ['probe', 'yuzurisa', 'banchecker'][intdiv($t, self::INTERVAL) % 3];
        return $src === 'probe' && ! (self::probeReady() && is_file(ROOTPATH . 'bin/xcheck.py')) ? 'yuzurisa' : $src;
    }

    /** Runs every enabled watch whose check is due. Called from the worker loop. */
    public static function runDue(callable $log): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $due = self::db()->table('x_watch')->where('enabled', 1)->where('handle !=', '')
            ->groupStart()->where('next_check_at', null)->orWhere('next_check_at <=', $now)->groupEnd()
            ->get()->getResultArray();
        foreach ($due as $w) {
            // push the next run first so a crash inside the check cannot make it loop
            self::update((int) $w['user_id'], ['next_check_at' => gmdate('Y-m-d H:i:s', self::nextSlot())]);
            $c = self::check($w);
            $log("xwatch @{$w['handle']}: {$c['status']} {$c['summary']}");
        }
    }

    /** Checks one watch now (with the slot's checker unless $source is given) and records the result. */
    public static function check(array $watch, ?string $source = null): array
    {
        $source ??= self::sourceAt(time());
        if ($source === 'yuzurisa') {
            return self::record($watch, self::yuzurisa((string) $watch['handle']));
        }
        if ($source === 'banchecker') {
            return self::record($watch, self::banchecker((string) $watch['handle']));
        }
        $env = [
            'PLAYWRIGHT_BROWSERS_PATH' => rtrim(ROOTPATH, '/') . '/browsers',
            'HOME'                     => rtrim(WRITEPATH, '/'),
            'LANG'                     => 'C.UTF-8',
        ];
        $args = ['python3', ROOTPATH . 'bin/xcheck.py', $watch['handle'], '--cookies', (string) Cookies::path(self::PROBE), '--timeout', '40'];
        $r = Ffmpeg::run($args, 240, $env);
        $j = json_decode(trim($r['stdout']), true);
        if (! is_array($j)) {
            $j = ['ok' => false, 'error' => 'checker failed: ' . mb_substr(trim($r['stderr']), -300)];
        }
        $j['source'] = 'probe';
        if (empty($j['ok']) && str_contains((string) ($j['error'] ?? ''), 'not logged in')) {
            Cookies::markFailure(self::PROBE, '검사용 부계정 로그인이 풀렸습니다. 쿠키를 다시 등록해 주세요.');
        } elseif (! empty($j['ok'])) {
            Cookies::clearFailure(self::PROBE);
        }
        return self::record($watch, $j);
    }

    /**
     * shadowban.yuzurisa.com (shadowban.eu rebuild) JSON API, mapped to the probe's result shape.
     * tests.search is a found tweet id or false; typeahead true/false; ghost / more_replies carry ban.
     */
    public static function yuzurisa(string $handle): array
    {
        $res = ['ok' => false, 'source' => 'yuzurisa', 'handle' => $handle];
        [$code, $j, $err] = self::getJson(self::YUZURISA . rawurlencode($handle),
                                          ['Origin: https://shadowban.yuzurisa.com', 'Referer: https://shadowban.yuzurisa.com/']);
        if ($code !== 200 || ! is_array($j) || ! isset($j['profile'])) {
            $res['error'] = 'yuzurisa ' . ($err !== '' ? $err : 'HTTP ' . $code);
            return $res;
        }
        $p = $j['profile'];
        if (! empty($p['error']) && empty($p['exists'])) {
            $res['error'] = 'yuzurisa: ' . $p['error'];
            return $res;
        }
        $t    = is_array($j['tests'] ?? null) ? $j['tests'] : [];
        $bool = static fn ($v) => is_bool($v) ? $v : null;
        $reply = static fn ($x) => is_array($x)
            ? array_filter(['ban' => $bool($x['ban'] ?? null), 'reply' => $x['tweet'] ?? null, 'parent' => $x['in_reply_to'] ?? null], static fn ($v) => $v !== null) + ['ban' => null]
            : ['ban' => null];
        $res['ok']      = true;
        $res['profile'] = ['exists' => (bool) ($p['exists'] ?? false), 'suspended' => ! empty($p['suspended']),
                           'protected' => ! empty($p['protected']), 'has_tweets' => (bool) ($p['has_tweets'] ?? false)];
        $search = $t['search'] ?? null;
        $res['search']    = $search === false ? ['ban' => true] : (is_string($search) && $search !== '' ? ['ban' => false, 'tweet' => $search] : ['ban' => null]);
        $res['typeahead'] = ['ban' => is_bool($t['typeahead'] ?? null) ? ! $t['typeahead'] : null];
        $res['ghost']     = $reply($t['ghost'] ?? null);
        $res['deboost']   = $reply($t['more_replies'] ?? null);
        return $res;
    }

    /** GET returning [http code, decoded JSON or null, curl error]. */
    private static function getJson(string $url, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers), CURLOPT_USERAGENT => self::UA,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        return [$code, is_string($body) ? json_decode($body, true) : null, $err];
    }

    /**
     * banchecker.org API. Its tasks carry its own verdict (ok | limited | unknown) per test; that is
     * what the site shows, so it is used as is. The raw upstream result is kept alongside.
     */
    public static function banchecker(string $handle): array
    {
        $res = ['ok' => false, 'source' => 'banchecker', 'handle' => $handle];
        [$code, $j, $err] = self::getJson(self::BANCHECKER . rawurlencode($handle), ['Referer: https://banchecker.org/twitter-ban-checker']);
        if ($code !== 200 || ! is_array($j) || empty($j['ok']) || ! is_array($j['tasks'] ?? null)) {
            $res['error'] = 'banchecker ' . ($err !== '' ? $err : 'HTTP ' . $code . (is_array($j) && ! empty($j['error']) ? ' ' . $j['error'] : ''));
            return $res;
        }
        $p = is_array($j['profile'] ?? null) ? $j['profile'] : [];
        $res['ok']      = true;
        $res['profile'] = ['exists' => (bool) ($p['exists'] ?? false), 'suspended' => ! empty($p['suspended']),
                           'protected' => ! empty($p['protected']), 'has_tweets' => (bool) ($p['hasTweets'] ?? false)];
        $map = ['search' => 'search', 'typeahead' => 'typeahead', 'ghost' => 'ghost', 'moreReplies' => 'deboost'];
        foreach ($map as $k) $res[$k] = ['ban' => null];
        foreach ($j['tasks'] as $t) {
            $k = $map[$t['id'] ?? ''] ?? null;
            if (! $k) continue;
            $st = (string) ($t['status'] ?? '');
            $res[$k] = ['ban' => $st === 'ok' ? false : ($st === 'limited' || $st === 'banned' || $st === 'restricted' ? true : null),
                        'status' => $st, 'note' => (string) ($t['summary'] ?? '')];
        }
        if (isset($j['raw'])) $res['raw'] = $j['raw'];
        return $res;
    }

    /** Stores one check result (either checker) and trims rows older than 30 days. */
    private static function record(array $watch, array $j): array
    {
        [$status, $summary] = self::judge($j);
        $row = ['user_id' => $watch['user_id'], 'handle' => $watch['handle'], 'status' => $status,
                'summary' => mb_substr($summary, 0, 200), 'result' => json_encode($j, JSON_UNESCAPED_UNICODE),
                'checked_at' => gmdate('Y-m-d H:i:s')];
        self::db()->table('x_checks')->insert($row);
        $id = (int) self::db()->insertID();
        self::update((int) $watch['user_id'], ['last_check_id' => $id]);
        self::db()->table('x_checks')->where('user_id', $watch['user_id'])
            ->where('checked_at <', gmdate('Y-m-d H:i:s', time() - 30 * 86400))->delete();
        return $row + ['id' => $id];
    }

    public const TESTS = [
        'search'    => '검색 차단 (Search Ban)',
        'typeahead' => '검색 제안 차단 (Suggestion Ban)',
        'ghost'     => '고스트 밴 (Ghost Ban)',
        'deboost'   => '답글 디부스트 (Reply Deboosting)',
    ];

    /** @return array{0:string,1:string} status and one-line Korean summary */
    public static function judge(array $j): array
    {
        if (empty($j['ok'])) return ['error', '검사 실패: ' . ($j['error'] ?? '알 수 없음')];
        $p = $j['profile'] ?? [];
        if (isset($p['exists']) && ! $p['exists']) return ['banned', '계정이 존재하지 않음'];
        if (! empty($p['suspended'])) return ['banned', '계정 정지됨'];
        if (! empty($p['protected'])) return ['error', '비공개 계정이라 검사 불가'];
        $bad = [];
        foreach (self::TESTS as $k => $label) {
            if (($j[$k]['ban'] ?? null) === true) $bad[] = $label;
        }
        if ($bad) return ['banned', implode(', ', $bad)];
        $unknown = array_keys(array_filter(self::TESTS, static fn ($l, $k) => ($j[$k]['ban'] ?? null) === null, ARRAY_FILTER_USE_BOTH));
        return ['ok', $unknown ? '제한 없음 (일부 검사 불가: ' . implode(', ', $unknown) . ')' : '제한 없음'];
    }

    /** Payload for the extension popup / badge. */
    public static function status(array $watch): array
    {
        $tl = self::timeline($watch);
        $c  = self::latestCheck((int) $watch['user_id']);
        return [
            'handle'   => $watch['handle'],
            'enabled'  => (bool) $watch['enabled'],
            'limits'   => ['gap_min' => (int) $watch['gap_min'], 'hour_max' => (int) $watch['hour_max'], 'day_max' => (int) $watch['day_max'],
                           'count_replies' => ! empty($watch['count_replies'])],
            'probe'    => self::probeReady(),
            'check'    => $c ? ['id' => (int) $c['id'], 'status' => $c['status'], 'summary' => $c['summary'], 'at' => $c['ts'],
                               'tests' => array_map(static fn ($k) => $c['result'][$k]['ban'] ?? null, array_combine(array_keys(self::TESTS), array_keys(self::TESTS)))] : null,
            'next_check' => $watch['next_check_at'] ? strtotime($watch['next_check_at'] . ' UTC') : null,
            'timeline' => $tl,
            'now'      => time(),
        ];
    }

    public static function kst(int $ts, string $fmt = 'm-d H:i'): string
    {
        return (new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone('Asia/Seoul'))->format($fmt);
    }
}
