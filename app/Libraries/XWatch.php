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
    public const SOURCES  = ['probe' => '부계정', 'yuzurisa' => 'yuzurisa', 'banchecker' => 'banchecker', 'xautodm' => 'xautodm', 'taishin' => 'taishin'];
    /** Checkers on trial: their "restricted" verdicts are stored in x_check_diffs, not acted on. */
    public const TRIAL    = ['xautodm', 'taishin'];
    private const ROTATION = ['probe', 'yuzurisa', 'banchecker', 'xautodm', 'taishin'];
    private const YUZURISA   = 'https://shadowban-api.yuzurisa.com:444/';
    private const BANCHECKER = 'https://banchecker.org/api/check-twitter?username=';
    private const XAUTODM    = 'https://xautodm.com/api/shadowban-check';
    private const TAISHIN    = 'https://taishin-miyamoto.com/ShadowBan/api/?screen_name=';
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

        $gapMin  = max(0, (int) $watch['gap_min']);
        $gapWarn = min($gapMin, max(0, (int) ($watch['gap_warn'] ?? 10)));
        $prev   = $before ? strtotime($before['posted_at'] . ' UTC') : null;
        $times  = [];
        $posts  = [];
        $replyTimes = [];
        foreach ($rows as $r) {
            $ts = strtotime($r['posted_at'] . ' UTC');
            $counted = in_array($r['kind'], $kinds, true);
            if ($r['kind'] === 'reply') $replyTimes[] = $ts;
            // nearest minute: X sends a scheduled post a second or two late, 14:58 is a 15-minute gap
            $gap = ($counted && $prev) ? (int) round(($ts - $prev) / 60) : null;
            $isPlain  = in_array($r['kind'], $plain, true);
            $gapPlain = ($isPlain && $prevPlain) ? (int) round(($ts - $prevPlain) / 60) : null;
            $posts[] = ['id' => $r['tweet_id'], 'kind' => $r['kind'], 'ts' => $ts, 'text' => $r['text'],
                        'reply_to' => $r['reply_to'], 'gap' => $gap, 'gap_noquote' => $gapPlain,
                        'short' => $gap !== null && $gap < $gapMin, 'danger' => $gap !== null && $gap < $gapWarn, 'counted' => $counted];
            if ($counted) { $prev = $ts; $times[] = $ts; }
            if ($isPlain) $prevPlain = $ts;
        }

        $now   = time();
        $hour  = count(array_filter($times, static fn ($t) => $t > $now - 3600));
        // the daily limit resets at 00:00 UTC (09:00 KST), the hourly one is a rolling hour
        $dayStart = intdiv($now, 86400) * 86400;
        $day   = count(array_filter($times, static fn ($t) => $t >= $dayStart));
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
        if ($day >= $dayMax) $next = max($next, $dayStart + 86400);

        $warn = [];
        if ($last && $now - $last < $gapMin * 60) $warn[] = sprintf('직전 게시 후 %d분 - %d분 간격 권장', intdiv($now - $last, 60), $gapMin);
        if ($hour >= $hourMax) $warn[] = sprintf('최근 1시간 %d개 (기준 %d개 미만)', $hour, $hourMax);
        if ($day >= $dayMax) $warn[] = sprintf('오늘(09시 이후) %d개 (기준 %d개 미만, 09시 리셋)', $day, $dayMax);
        $danger = count(array_filter($posts, static fn ($p) => $p['danger']));
        $short  = count(array_filter($posts, static fn ($p) => $p['short'])) - $danger;
        if ($danger) $warn[] = sprintf('최근 %d시간 동안 %d분 미만 간격 %d회 (경고)', $hours, $gapWarn, $danger);
        if ($short) $warn[] = sprintf('최근 %d시간 동안 %d~%d분 간격 %d회', $hours, $gapWarn, $gapMin, $short);

        $replyHour = count(array_filter($replyTimes, static fn ($t) => $t > $now - 3600));
        return ['posts' => array_reverse($posts), 'hour' => $hour, 'day' => $day, 'last' => $last, 'reply_hour' => $replyHour,
                'next_ok' => $next, 'day_reset' => $dayStart + 86400, 'can_post' => $next <= $now, 'warnings' => $warn];
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
     * Checker for the slot that contains $t, rotating every 10 minutes through ROTATION
     * (probe account, shadowban.yuzurisa.com, banchecker.org, xautodm.com, taishin-miyamoto.com).
     */
    public static function sourceAt(int $t): string
    {
        $src = self::ROTATION[intdiv($t, self::INTERVAL) % count(self::ROTATION)];
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
            $uid  = (int) $w['user_id'];
            // restricted mode lasts from the confirmed alert until a check says clear (stored, so it survives
            // worker restarts and episodes longer than the look-back)
            $was  = ! empty($w['restricted_at']);
            $hold = $was || self::restricted($uid);
            // push the next run first so a crash inside the check cannot make it loop
            self::update($uid, ['next_check_at' => gmdate('Y-m-d H:i:s', $hold ? self::nextHour() : self::nextSlot())]);
            // a fresh restriction verdict: the checkers that have not looked yet go first, 10 minutes apart
            $v   = $hold ? null : self::verification($uid);
            $src = $hold ? 'probe' : ($v && $v['pending'] ? $v['pending'][0] : null);
            $c = self::check($w, $src);
            $log("xwatch @{$w['handle']}: {$c['status']} {$c['summary']}" . ($hold ? ' (restricted mode)' : ($src ? ' (cross-check)' : '')));
            // the first restricted verdict of an episode is reported at once, the cross-check follows
            $now = self::restricted($uid) || ($was && $c['status'] !== 'ok');
            if ($c['status'] === 'banned' && ! $hold && ! $now && Telegram::ready($uid)) {
                $v = self::verification($uid);
                $alerted = $w['alerted_at'] ? strtotime($w['alerted_at'] . ' UTC') : 0;
                if ($v && $alerted < $v['start']) {
                    self::update($uid, ['alerted_at' => gmdate('Y-m-d H:i:s')]);
                    $err = Telegram::send($uid, self::suspectText($w, $c, $v));
                    $log('xwatch telegram (suspected): ' . ($err ?? 'sent'));
                }
            }
            // entering or leaving restricted mode, against the stored state (x_watch.restricted_at)
            if ($now !== $was || $now !== $hold) {
                self::update($uid, ['next_check_at' => gmdate('Y-m-d H:i:s', $now ? self::nextHour() : self::nextSlot()),
                                    'restricted_at' => $now ? ($w['restricted_at'] ?: gmdate('Y-m-d H:i:s')) : null]);
            }
            if ($now !== $was) {
                $log("xwatch @{$w['handle']}: " . ($now ? 'restriction confirmed by every checker, hourly probe checks until it clears' : 'restriction cleared, back to the 10-minute rotation'));
                if (Telegram::ready($uid)) {
                    $err = Telegram::send($uid, self::alertText($w, $c, $now, $was ? strtotime($w['restricted_at'] . ' UTC') : null));
                    $log('xwatch telegram: ' . ($err ?? 'sent'));
                }
            }
        }
    }

    /**
     * Restricted mode (hourly probe-only checks). A restriction found by one checker is first
     * cross-checked: the 10-minute rotation keeps going until every established checker (probe,
     * yuzurisa, banchecker; trial checkers only collect data) has been tried since the first
     * restricted verdict. Only if none of them answered "clear" and at least two said
     * "restricted" does the hourly probe-only mode start. Any "clear" from an established
     * checker ends the episode. Without a usable probe account the rotation keeps running.
     */
    public static function restricted(int $userId): bool
    {
        if (! (self::probeReady() && is_file(ROOTPATH . 'bin/xcheck.py'))) return false;
        $p = self::verification($userId);
        return $p !== null && $p['confirmed'];
    }

    /** Established (non-trial) checkers that can run right now. */
    public static function trustedSources(): array
    {
        $out = [];
        foreach (self::ROTATION as $s) {
            if (in_array($s, self::TRIAL, true)) continue;
            if ($s === 'probe' && ! (self::probeReady() && is_file(ROOTPATH . 'bin/xcheck.py'))) continue;
            $out[] = $s;
        }
        return $out;
    }

    /**
     * State of the current restriction episode from the established checkers' log, or null when
     * the latest conclusive established verdict is "clear":
     * ['start' => unix, 'banned' => [source...], 'tried' => [source...], 'pending' => [source...], 'confirmed' => bool]
     */
    public static function verification(int $userId): ?array
    {
        $rows = self::db()->table('x_checks')->select('status, result, checked_at')->where('user_id', $userId)
            ->where('checked_at >=', gmdate('Y-m-d H:i:s', time() - 30 * 86400))   // the whole kept log: with 3 days a long episode
            ->orderBy('checked_at', 'DESC')->orderBy('id', 'DESC')->limit(3000)->get()->getResultArray();   // lost its first verdicts and ended unnoticed
        $episode = [];
        foreach ($rows as $r) {
            $src = json_decode((string) $r['result'], true)['source'] ?? 'probe';
            if (in_array($src, self::TRIAL, true)) continue;
            if ($r['status'] === 'ok') break;                     // the last "clear" ends the look-back
            $episode[] = ['src' => $src, 'status' => $r['status'], 'ts' => strtotime($r['checked_at'] . ' UTC')];
        }
        $banned = array_filter($episode, static fn ($e) => $e['status'] === 'banned');
        if ($banned === []) return null;
        $start  = min(array_column($banned, 'ts'));
        $since  = array_filter($episode, static fn ($e) => $e['ts'] >= $start);
        $tried  = array_values(array_unique(array_column($since, 'src')));
        $bsrc   = array_values(array_unique(array_column(array_filter($since, static fn ($e) => $e['status'] === 'banned'), 'src')));
        $pending = array_values(array_diff(self::trustedSources(), $tried));
        return ['start' => $start, 'banned' => $bsrc, 'tried' => $tried, 'pending' => $pending,
                'confirmed' => $pending === [] && count($bsrc) >= 2];
    }

    /** Telegram text for the first restricted verdict of an episode, while the cross-check is still running. */
    public static function suspectText(array $w, array $c, array $v): string
    {
        helper('url');
        $name = static fn ($k) => self::SOURCES[$k] ?? $k;
        $src  = $name(json_decode((string) $c['result'], true)['source'] ?? 'probe');
        $text = "[제한 의심] @{$w['handle']}\n{$c['summary']} ({$src})\n"
              . '발견 일시: ' . self::kst($v['start'], 'Y-m-d H:i') . "\n";
        // trial checkers that flagged the account around the same time, for context only
        $trial = self::db()->table('x_checks')->select('result')->where('user_id', $w['user_id'])
            ->where('checked_at >=', gmdate('Y-m-d H:i:s', $v['start'] - 3600))->where('status', 'error')
            ->like('summary', '미확정', 'after')->get()->getResultArray();
        $names = array_values(array_unique(array_filter(array_map(
            static fn ($r) => json_decode((string) $r['result'], true)['source'] ?? null, $trial))));
        if ($names) $text .= '시험 중 검사기도 제한 판정: ' . implode(', ', array_map($name, $names)) . "\n";
        $text .= $v['pending']
            ? '교차 검사 진행 중 (10분 간격): ' . implode(', ', array_map($name, $v['pending'])) . "\n"
              . "모두 제한이면 1시간 간격 부계정 검사로 전환하고 다시 알립니다.\n"
            : "교차 검사 진행 중입니다.\n";
        return $text . site_url('xwatch/checks');
    }

    /** Telegram text for entering ($restricted) or leaving restricted mode. */
    public static function alertText(array $w, array $c, bool $restricted, ?int $since = null): string
    {
        helper('url');   // the worker runs from the CLI
        $src = self::SOURCES[json_decode((string) $c['result'], true)['source'] ?? 'probe'] ?? '';
        $now = strtotime($c['checked_at'] . ' UTC');
        $fmt = 'Y-m-d H:i';
        if ($restricted) {
            $v = self::verification((int) $w['user_id']);
            $start = $v['start'] ?? self::restrictionStart((int) $w['user_id'], $now) ?? $now;
            $who = $v ? implode(', ', array_map(static fn ($k) => self::SOURCES[$k] ?? $k, $v['banned'])) : $src;
            return "[제한 감지] @{$w['handle']}\n{$c['summary']}\n교차 검사에서 모두 제한: {$who}\n"
                 . '제한 발견 일시: ' . self::kst($start, $fmt) . "\n"
                 . "풀릴 때까지 1시간마다 부계정으로 검사합니다.\n" . site_url('xwatch/checks');
        }
        // the episode's first verdict: before the alert of restricted mode ($since) when that is earlier
        $first = $since ? self::restrictionStart((int) $w['user_id'], $since) : null;
        $start = $first ?? $since ?? self::restrictionStart((int) $w['user_id'], $now);
        $lines = "[제한 풀림] @{$w['handle']}\n{$c['summary']} ({$src})\n";
        if ($start) {
            $lines .= '시작 일시: ' . self::kst($start, $fmt) . "\n"
                    . '종료 일시: ' . self::kst($now, $fmt) . "\n"
                    . '제한 기간: ' . self::span($now - $start) . "\n";
        } else {
            $lines .= '종료 일시: ' . self::kst($now, $fmt) . "\n";
        }
        return $lines . "10분 주기 검사로 돌아갑니다.\n" . site_url('xwatch/checks');
    }

    /**
     * When the restriction that is current at $before began, from the check log: the first
     * "banned" check after the last "ok" check earlier than $before (failed checks ignored).
     */
    public static function restrictionStart(int $userId, int $before): ?int
    {
        $db  = self::db();
        $cut = gmdate('Y-m-d H:i:s', $before);
        $ok  = $db->table('x_checks')->select('checked_at')->where('user_id', $userId)->where('status', 'ok')
                  ->where('checked_at <', $cut)->orderBy('checked_at', 'DESC')->limit(1)->get()->getRowArray();
        $q = $db->table('x_checks')->select('checked_at')->where('user_id', $userId)->where('status', 'banned')
                ->where('checked_at <=', $cut);
        if ($ok) $q->where('checked_at >', $ok['checked_at']);
        $first = $q->orderBy('checked_at', 'ASC')->limit(1)->get()->getRowArray();
        return $first ? strtotime($first['checked_at'] . ' UTC') : null;
    }

    /** "2일 3시간 20분" */
    public static function span(int $sec): string
    {
        $sec = max(0, $sec);
        $d = intdiv($sec, 86400); $h = intdiv($sec % 86400, 3600); $m = intdiv($sec % 3600, 60);
        return trim(($d ? $d . '일 ' : '') . ($d || $h ? $h . '시간 ' : '') . $m . '분');
    }

    /** Next full hour (unix time). */
    public static function nextHour(): int
    {
        return intdiv(time(), 3600) * 3600 + 3600;
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
        if ($source === 'xautodm') {
            return self::record($watch, self::xautodm((string) $watch['handle']));
        }
        if ($source === 'taishin') {
            return self::record($watch, self::taishin((string) $watch['handle']));
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
        // the watched account's posts the probe saw: posting history for posts made without the extension
        if (is_array($j['posts'] ?? null)) {
            $j['posts_seen'] = count($j['posts']);
            $j['posts_new']  = self::savePosts($watch, $j['posts']);
            unset($j['posts']);
        }
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

    /**
     * xautodm.com: POST {handle} -> checks[] with status ok | banned | not_tested (exists,
     * search_suggestion, search_ban, reply_deboost). Ghost ban is not tested. Results may be cached.
     */
    public static function xautodm(string $handle): array
    {
        $res = ['ok' => false, 'source' => 'xautodm', 'handle' => $handle];
        [$code, $j, $err] = self::getJson(self::XAUTODM, ['Content-Type: application/json', 'Origin: https://xautodm.com',
            'Referer: https://xautodm.com/tools/twitter-shadowban-checker'], json_encode(['handle' => $handle]));
        if ($code !== 200 || ! is_array($j) || ! is_array($j['checks'] ?? null)) {
            $res['error'] = 'xautodm ' . ($err !== '' ? $err : 'HTTP ' . $code . (is_array($j) && ! empty($j['error']) ? ' ' . $j['error'] : ''));
            return $res;
        }
        $by = [];
        foreach ($j['checks'] as $c) $by[(string) ($c['key'] ?? '')] = $c;
        $ban = static function (?array $c): ?bool {
            $s = (string) ($c['status'] ?? '');
            return $s === 'ok' ? false : (in_array($s, ['banned', 'fail', 'failed', 'limited', 'warn', 'warning'], true) ? true : null);
        };
        $exists = $ban($by['exists'] ?? null);
        if ($exists === null) {
            $res['error'] = 'xautodm: 계정 확인 결과 없음 (' . ($j['verdict'] ?? '') . ')';
            return $res;
        }
        $p = is_array($j['profile'] ?? null) ? $j['profile'] : [];
        $res['ok']        = true;
        $res['cached']    = ! empty($j['cached']);
        $res['profile']   = ['exists' => ! $exists, 'suspended' => false,
                             'protected' => ! empty($p['protected']), 'has_tweets' => true];
        $res['search']    = ['ban' => $ban($by['search_ban'] ?? null), 'note' => (string) ($by['search_ban']['detail'] ?? '')];
        $res['typeahead'] = ['ban' => $ban($by['search_suggestion'] ?? null), 'note' => (string) ($by['search_suggestion']['detail'] ?? '')];
        $res['ghost']     = ['ban' => null];
        $res['deboost']   = ['ban' => $ban($by['reply_deboost'] ?? null)];
        $res['raw']       = $j;
        return $res;
    }

    /**
     * taishin-miyamoto.com/ShadowBan: booleans search_ban, search_suggestion_ban, ghost_ban,
     * reply_deboosting plus not_found / suspend / protect / no_tweet / error. On not_found the
     * ban flags are all false, so the flags only count for an account that was found.
     */
    public static function taishin(string $handle): array
    {
        $res = ['ok' => false, 'source' => 'taishin', 'handle' => $handle];
        [$code, $j, $err] = self::getJson(self::TAISHIN . rawurlencode($handle), ['Referer: https://taishin-miyamoto.com/ShadowBan/']);
        if ($code !== 200 || ! is_array($j) || ! array_key_exists('search_ban', $j)) {
            $res['error'] = 'taishin ' . ($err !== '' ? $err : 'HTTP ' . $code);
            return $res;
        }
        if (! empty($j['error']) || empty($j['user']['id']) && empty($j['not_found'])) {
            $res['error'] = 'taishin: 검사 오류';
            return $res;
        }
        $res['ok']      = true;
        $res['profile'] = ['exists' => empty($j['not_found']), 'suspended' => ! empty($j['suspend']),
                           'protected' => ! empty($j['protect']), 'has_tweets' => empty($j['no_tweet'])];
        if (! empty($j['not_found']) || ! empty($j['suspend']) || ! empty($j['protect']) || ! empty($j['no_tweet'])) {
            foreach (['search', 'typeahead', 'ghost', 'deboost'] as $k) $res[$k] = ['ban' => null];
        } else {
            $res['search']    = ['ban' => (bool) $j['search_ban']];
            $res['typeahead'] = ['ban' => (bool) ($j['search_suggestion_ban'] ?? false)];
            $res['ghost']     = ['ban' => (bool) ($j['ghost_ban'] ?? false)];
            $res['deboost']   = ['ban' => (bool) ($j['reply_deboosting'] ?? false)];
        }
        $res['raw'] = $j;
        return $res;
    }

    /** GET (or POST with $post) returning [http code, decoded JSON or null, curl error]. */
    private static function getJson(string $url, array $headers, ?string $post = null): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers), CURLOPT_USERAGENT => self::UA,
        ]);
        if ($post !== null) curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post]);
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
        // When the site's own X account is locked or rate-limited it answers "exists: false" with an
        // error (the page then shows "The string did not match the expected pattern." for a while).
        // That says nothing about the watched account, so it is a failed check, not a missing account.
        $upErr = (string) ($j['raw']['profile']['error'] ?? $p['error'] ?? '');
        if ($upErr !== '' || ($j['raw']['tests'] ?? 'x') === null && empty($p['exists'])) {
            $res['error'] = 'banchecker 장애: ' . ($upErr !== '' ? mb_substr($upErr, 0, 120) : '검사 결과 없음');
            return $res;
        }
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
        // a checker on trial does not get to declare a restriction: keep its verdict for analysis
        if ($status === 'banned' && in_array($j['source'] ?? '', self::TRIAL, true)) {
            $ref = self::db()->table('x_checks')->where('user_id', $watch['user_id'])->where('status !=', 'error')
                ->orderBy('checked_at', 'DESC')->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();
            self::db()->table('x_check_diffs')->insert([
                'user_id' => $watch['user_id'], 'handle' => $watch['handle'], 'source' => $j['source'],
                'summary' => mb_substr($summary, 0, 200), 'result' => json_encode($j, JSON_UNESCAPED_UNICODE),
                'ref_check_id' => $ref['id'] ?? null, 'ref_source' => $ref ? (json_decode((string) $ref['result'], true)['source'] ?? 'probe') : '',
                'ref_status' => $ref['status'] ?? '', 'ref_summary' => mb_substr((string) ($ref['summary'] ?? ''), 0, 200),
                'checked_at' => gmdate('Y-m-d H:i:s'),
            ]);
            $status  = 'error';
            $summary = '미확정: ' . $summary . ' (' . $j['source'] . ' 검증 중, 판정에 반영 안 함)';
        }
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
        // "account missing" with every test inconclusive is the checker failing, not a verdict
        $allUnknown = ! array_filter(array_keys(self::TESTS), static fn ($k) => ($j[$k]['ban'] ?? null) !== null);
        if (isset($p['exists']) && ! $p['exists'] && $allUnknown && ($j['source'] ?? '') !== 'probe') {
            return ['error', '검사 실패: ' . ($j['source'] ?? '') . ' 결과 불확실 (계정 없음 + 모든 항목 판정 불가)'];
        }
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

    /** Replaces the stored scheduled-post list with what the extension read from X. */
    public static function saveScheduled(array $watch, array $items): int
    {
        $out = [];
        foreach (array_slice($items, 0, 100) as $i) {
            if (! is_array($i)) continue;
            $id = (string) ($i['id'] ?? '');
            $at = (int) ($i['at'] ?? 0);
            if (! preg_match('/^\d{1,25}$/', $id) || $at <= 0) continue;
            $thumb = (string) ($i['thumb'] ?? '');
            $out[] = ['id' => $id, 'at' => $at,
                      'kind' => in_array($i['kind'] ?? '', self::KINDS, true) ? $i['kind'] : 'post',
                      'text' => mb_substr((string) ($i['text'] ?? ''), 0, 300),
                      'media' => max(0, (int) ($i['media'] ?? 0)),
                      'thumb' => preg_match('#^https://pbs\.twimg\.com/#', $thumb) ? $thumb : ''];
        }
        usort($out, static fn ($a, $b) => $a['at'] <=> $b['at']);
        self::update((int) $watch['user_id'], ['scheduled' => json_encode($out, JSON_UNESCAPED_UNICODE),
                                               'scheduled_at' => gmdate('Y-m-d H:i:s')]);
        return count($out);
    }

    /**
     * Upcoming scheduled posts with the gap to the post before each (the last real post for the
     * first one), colored by the same thresholds as the timeline.
     */
    public static function scheduled(array $watch, ?int $last): array
    {
        $synced = $watch['scheduled_at'] ?? null;
        $list   = json_decode((string) ($watch['scheduled'] ?? ''), true) ?: [];
        $kinds  = self::countedKinds($watch);
        $gapMin = max(0, (int) $watch['gap_min']);
        $gapWarn = min($gapMin, max(0, (int) ($watch['gap_warn'] ?? 10)));
        $now  = time();
        $prev = $last;
        $out  = [];
        foreach ($list as $i) {
            if ($i['at'] <= $now) continue; // sent already: it shows up among the posts
            $counted = in_array($i['kind'], $kinds, true);
            $gap = ($counted && $prev) ? (int) round(($i['at'] - $prev) / 60) : null;
            $out[] = $i + ['gap' => $gap, 'counted' => $counted,
                           'short' => $gap !== null && $gap < $gapMin, 'danger' => $gap !== null && $gap < $gapWarn];
            if ($counted) $prev = $i['at'];
        }
        $reset = intdiv($now, 86400) * 86400 + 86400;   // same day boundary as timeline(): 00:00 UTC
        $today = count(array_filter($out, static fn ($i) => $i['counted'] && $i['at'] < $reset));
        return ['items' => $out, 'synced' => $synced ? strtotime($synced . ' UTC') : null, 'today' => $today];
    }

    /** Payload for the extension popup / badge. */
    public static function status(array $watch): array
    {
        $tl = self::timeline($watch);
        $c  = self::latestCheck((int) $watch['user_id']);
        return [
            'handle'   => $watch['handle'],
            'enabled'  => (bool) $watch['enabled'],
            'limits'   => ['gap_min' => (int) $watch['gap_min'], 'gap_warn' => (int) ($watch['gap_warn'] ?? 10), 'hour_max' => (int) $watch['hour_max'], 'day_max' => (int) $watch['day_max'],
                           'count_replies' => ! empty($watch['count_replies'])],
            'probe'    => self::probeReady(),
            'check'    => $c ? ['id' => (int) $c['id'], 'status' => $c['status'], 'summary' => $c['summary'], 'at' => $c['ts'],
                               'tests' => array_map(static fn ($k) => $c['result'][$k]['ban'] ?? null, array_combine(array_keys(self::TESTS), array_keys(self::TESTS)))] : null,
            'next_check' => $watch['next_check_at'] ? strtotime($watch['next_check_at'] . ' UTC') : null,
            'restricted' => ! empty($watch['restricted_at']) || self::restricted((int) $watch['user_id']),
            'timeline' => $tl,
            'scheduled' => self::scheduled($watch, $tl['last'] ?: null),
            'now'      => time(),
        ];
    }

    public static function kst(int $ts, string $fmt = 'm-d H:i'): string
    {
        return (new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone('Asia/Seoul'))->format($fmt);
    }
}
