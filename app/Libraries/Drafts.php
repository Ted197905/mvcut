<?php

namespace App\Libraries;

/**
 * X post drafts the collector writes twice a day (hallyuwire drafts.py, tables hallyuwire.drafts /
 * draft_batches). Here they get approved (-> a posting slot), the extension schedules them on X.
 *
 * Status: gen/regen (being written) -> new -> approved (slot_at set) -> sending (handed to the
 * extension) -> scheduled (on X, x_id) -> posted; or rejected / skipped (model) / failed / error.
 */
class Drafts
{
    public const STYLES = ['info' => '음슴체 정보', 'story' => '썰 헤드라인', 'quote' => '요약 + 한 줄 평', 'insight' => '장문 인사이트'];
    public const SLOT_FROM = '09:05';
    public const SLOT_TO   = '22:00';
    public const SLOT_STEP = 15;      // minutes
    public const LEAD_MIN  = 12;      // a slot must be at least this far ahead (the extension polls every 2 minutes)
    private const SENDING_MAX = 600;  // seconds before a draft the extension did not report on goes back to approved

    public static function t(string $table): string
    {
        return env('hallyuwire.db', 'hallyuwire') . '.' . $table;
    }

    private static function db(): \CodeIgniter\Database\BaseConnection
    {
        return \Config\Database::connect();
    }

    public static function find(int $id): ?array
    {
        return self::db()->table(self::t('drafts'))->where('id', $id)->get()->getRowArray() ?: null;
    }

    public static function update(int $id, array $data): void
    {
        $data['updated_at'] = gmdate('Y-m-d H:i:s');
        self::db()->table(self::t('drafts'))->where('id', $id)->update($data);
    }

    /** "20261028h06" -> "10/28 06시" (older "20261027pm" -> "10/27 저녁") */
    public static function batchLabel(string $b): string
    {
        if (! preg_match('/^(\d{4})(\d{2})(\d{2})(am|pm|h\d{2})$/', $b, $m)) return $b;
        $when = match ($m[4]) { 'am' => ' 오전', 'pm' => ' 저녁', default => ' ' . substr($m[4], 1) . '시' };
        return (int) $m[2] . '/' . (int) $m[3] . $when;
    }

    /** Unix time the batch's approved drafts may be posted from (the collector sets it: next day 09:05 for an evening batch). */
    public static function publishFrom(string $batch): ?int
    {
        $b = self::db()->table(self::t('draft_batches'))->select('publish_from')->where('batch', $batch)->get()->getRowArray();
        return ! empty($b['publish_from']) ? strtotime($b['publish_from'] . ' UTC') : null;
    }

    /** [label, css class] of a draft's state, for its card. */
    public static function badge(array $d): array
    {
        $at = static fn () => $d['slot_at'] ? XWatch::kst(strtotime($d['slot_at'] . ' UTC'), 'n/j H:i') : '';
        return match ($d['status']) {
            'gen', 'regen' => ['쓰는 중', 'warn'],
            'new'       => ['검토 대기', ''],
            'approved'  => [$at() . ' 예약 대기', 'warn'],
            'sending'   => [$at() . ' 등록 중', 'ok'],
            'scheduled' => [$at() . ' X 예약됨', 'ok'],
            'posted'    => [$at() . ' 게시됨', 'ok'],
            'rejected'  => ['제외', 'bad'],
            'skipped'   => ['모델이 제외', 'bad'],
            'error'     => ['등록 실패', 'bad'],
            'failed'    => ['작성 실패', 'bad'],
            default     => [$d['status'], ''],
        };
    }

    /** The slot line over the list: "10/28 게시: X 예약 5 · 승인 3 · 빈 슬롯 40/52 · 다음 10:35". */
    public static function usageText(int $userId, ?string $batch): string
    {
        $from = $batch ? self::publishFrom($batch) : null;
        $next = self::nextSlot($userId, $from);
        if (! $next) return '빈 슬롯 없음';
        $u = self::dayUsage($userId, $next, $from);
        $writing = (int) self::db()->table(self::t('drafts'))->whereIn('status', ['gen', 'regen'])->countAllResults();
        return $u['label'] . ' 게시: X 예약 ' . $u['x'] . ' · 승인 ' . $u['drafts'] . ' · 빈 슬롯 ' . $u['free'] . '/' . $u['total']
             . ' · 다음 ' . XWatch::kst($next, 'H:i') . ($writing ? ' · ' . $writing . '건 쓰는 중' : '');
    }

    /** The post text as it goes to X: a story's headline, blank line, body. */
    public static function fullText(array $d): string
    {
        $text = trim((string) $d['text']);
        $thread = json_decode((string) ($d['thread'] ?? ''), true);
        if ($d['style'] === 'story' && is_array($thread) && trim((string) ($thread[0] ?? '')) !== '') {
            $text .= "\n\n" . trim((string) $thread[0]);
        }
        return $text;
    }

    /** Unix timestamps of the posting grid (KST 09:05 .. 22:00 every 15 min) for `days` days from today. */
    public static function grid(int $days = 3): array
    {
        $tz  = new \DateTimeZone('Asia/Seoul');
        $out = [];
        $day = new \DateTimeImmutable('today', $tz);
        for ($i = 0; $i < $days; $i++) {
            $t    = $day->modify(self::SLOT_FROM);
            $last = $day->modify(self::SLOT_TO);
            while ($t <= $last) {
                $out[] = $t->getTimestamp();
                $t = $t->modify('+' . self::SLOT_STEP . ' minutes');
            }
            $day = $day->modify('+1 day');
        }
        return $out;
    }

    /** Times (unix) already spoken for: approved/sending/scheduled drafts, X's own scheduled list, the last real post. */
    public static function taken(int $userId): array
    {
        $out = [];
        foreach (self::db()->table(self::t('drafts'))->select('slot_at')->whereIn('status', ['approved', 'sending', 'scheduled'])
                     ->where('slot_at IS NOT NULL', null, false)->get()->getResultArray() as $r) {
            $out[] = strtotime($r['slot_at'] . ' UTC');
        }
        $watch = XWatch::forUser($userId);
        foreach (json_decode((string) ($watch['scheduled'] ?? ''), true) ?: [] as $i) {
            if (! empty($i['at'])) $out[] = (int) $i['at'];
        }
        $tl = XWatch::timeline($watch, 6);
        if (! empty($tl['last'])) $out[] = (int) $tl['last'];
        sort($out);
        return $out;
    }

    /**
     * The posting day of `$t` (KST) in plain numbers: posts X already has scheduled that day (made by hand),
     * drafts approved for it, and how many of the 52 grid slots (09:05..21:50 every 15 min) are still free:
     * ahead of `$from`, and 15+ min (gap_min) away from anything taken. One hand-made post at 10:00 blocks
     * the 09:50 and 10:05 slots, so free + taken posts need not add up to 52.
     */
    public static function dayUsage(int $userId, int $t, ?int $from = null): array
    {
        $tz    = new \DateTimeZone('Asia/Seoul');
        $start = (new \DateTimeImmutable('@' . $t))->setTimezone($tz)->setTime(0, 0)->getTimestamp();
        $in    = static fn (int $v) => $v >= $start && $v < $start + 86400;
        $grid  = array_values(array_filter(self::grid(5), $in));
        $drafts = array_map(static fn ($r) => strtotime($r['slot_at'] . ' UTC'), self::db()->table(self::t('drafts'))->select('slot_at')
            ->whereIn('status', ['approved', 'sending', 'scheduled', 'posted'])->where('slot_at IS NOT NULL', null, false)->get()->getResultArray());
        $xs = [];
        foreach (json_decode((string) (XWatch::forUser($userId)['scheduled'] ?? ''), true) ?: [] as $i) {
            if (! empty($i['at']) && ! in_array((int) $i['at'], $drafts, true)) $xs[] = (int) $i['at'];   // a scheduled draft shows up in both lists
        }
        $gap   = max(self::SLOT_STEP, (int) XWatch::forUser($userId)['gap_min']) * 60;
        $taken = self::taken($userId);
        $from  = max(time() + self::LEAD_MIN * 60, $from ?? 0);
        $free  = count(array_filter($grid, static function ($g) use ($taken, $gap, $from) {
            if ($g < $from) return false;
            foreach ($taken as $x) if (abs($g - $x) < $gap) return false;
            return true;
        }));
        return ['label' => (new \DateTimeImmutable('@' . $start))->setTimezone($tz)->format('n/j'), 'total' => count($grid),
                'x' => count(array_filter($xs, $in)), 'drafts' => count(array_filter($drafts, $in)), 'free' => $free];
    }

    /** The first free grid slot (unix) at least LEAD_MIN ahead, keeping the account's minimum gap to everything taken. */
    public static function nextSlot(int $userId, ?int $notBefore = null): ?int
    {
        $watch = XWatch::forUser($userId);
        $gap   = max(self::SLOT_STEP, (int) $watch['gap_min']) * 60;
        $dayMax = max(1, (int) $watch['day_max']);
        $taken = self::taken($userId);
        $from  = max(time() + self::LEAD_MIN * 60, $notBefore ?? 0);
        foreach (self::grid(5) as $t) {
            if ($t < $from) continue;
            $ok = true;
            foreach ($taken as $x) {
                if (abs($t - $x) < $gap) { $ok = false; break; }
            }
            if (! $ok) continue;
            $dayStart = intdiv($t, 86400) * 86400;   // X's daily limit resets at 00:00 UTC (09:00 KST)
            $inDay = count(array_filter($taken, static fn ($x) => $x >= $dayStart && $x < $dayStart + 86400));
            if ($inDay >= $dayMax) continue;
            return $t;
        }
        return null;
    }

    /** Approves a draft: the slot, the item marked used. Returns the slot or an error string. */
    public static function approve(int $userId, array $d): int|string
    {
        if (! in_array($d['status'], ['new', 'error', 'rejected'], true)) return '지금 상태에서는 승인할 수 없습니다.';
        if (trim((string) $d['text']) === '') return '본문이 비어 있습니다.';
        $slot = self::nextSlot($userId, self::publishFrom((string) $d['batch']));
        if ($slot === null) return '빈 슬롯이 없습니다 (4일 치가 찼습니다).';
        self::update((int) $d['id'], ['status' => 'approved', 'slot_at' => gmdate('Y-m-d H:i:s', $slot), 'error' => null, 'tries' => 0]);
        self::db()->table(self::t('items'))->where('id', (int) $d['item_id'])->update(['used_at' => gmdate('Y-m-d H:i:s')]);
        return $slot;
    }

    /** Media rows (done image/video) of a draft's post, with the chosen ones flagged. */
    public static function media(array $d): array
    {
        $chosen = array_map('intval', json_decode((string) ($d['media'] ?? '[]'), true) ?: []);
        $rows = self::db()->table(self::t('media'))->where('item_id', (int) $d['item_id'])->where('status', 'done')
            ->whereIn('kind', ['image', 'video'])->orderBy('id')->get()->getResultArray();
        foreach ($rows as &$m) $m['chosen'] = in_array((int) $m['id'], $chosen, true);
        return $rows;
    }

    /** Validates a media choice for X: up to 4 images or one video. Returns an error or null. */
    public static function checkMedia(array $rows, array $pick): ?string
    {
        $kinds = [];
        foreach ($rows as $m) if (in_array((int) $m['id'], $pick, true)) $kinds[] = $m['kind'];
        if (count($kinds) !== count($pick)) return '없는 파일이 골라졌습니다.';
        if (in_array('video', $kinds, true) && count($kinds) > 1) return 'X는 영상과 사진을 함께 올릴 수 없습니다 (영상은 1개).';
        if (count($kinds) > 4) return 'X 사진은 4장까지입니다.';
        return null;
    }

    public const AUTO_GAP  = 600;   // auto mode: seconds between two drafts handed to the extension
    public const MAX_TRIES = 3;     // auto mode: tries in all (the first + 2 retries); then the draft stops and waits for the user

    /** 'auto' (the extension schedules approved drafts on X) or 'semi' (X's composer is filled, the user schedules). */
    public static function mode(int $userId): string
    {
        return (XWatch::forUser($userId)['draft_mode'] ?? 'semi') === 'auto' ? 'auto' : 'semi';
    }

    /**
     * Auto mode: the next approved draft for the extension to schedule on X, at most one every AUTO_GAP
     * seconds (sent or failed). A draft whose slot is too close by now gets the next free slot first.
     * One the extension never reported on within SENDING_MAX seconds counts as a failed try.
     */
    public static function queue(int $userId): array
    {
        $w = XWatch::forUser($userId);
        if (($w['draft_mode'] ?? 'semi') !== 'auto') return [];
        $db = self::db();
        foreach ($db->table(self::t('drafts'))->where('status', 'sending')->where('updated_at <', gmdate('Y-m-d H:i:s', time() - self::SENDING_MAX))
                     ->get()->getResultArray() as $d) {
            self::failed($d, '확장이 결과를 보내지 않음 (시간 초과)');
        }
        if (! empty($w['draft_queue_at']) && strtotime($w['draft_queue_at'] . ' UTC') > time() - self::AUTO_GAP) return [];
        $d = $db->table(self::t('drafts'))->where('status', 'approved')->where('tries <', self::MAX_TRIES)
            ->orderBy('slot_at')->orderBy('id')->get(1)->getRowArray();
        if (! $d) return [];
        if (strtotime($d['slot_at'] . ' UTC') < time() + 5 * 60) {   // X refuses a time in the past; keep a margin
            self::update((int) $d['id'], ['slot_at' => null]);
            $slot = self::nextSlot($userId, self::publishFrom((string) $d['batch']));
            if ($slot === null) return [];
            self::update((int) $d['id'], ['slot_at' => gmdate('Y-m-d H:i:s', $slot)]);
            $d = self::find((int) $d['id']);
        }
        self::update((int) $d['id'], ['status' => 'sending']);
        XWatch::update($userId, ['draft_queue_at' => gmdate('Y-m-d H:i:s')]);
        return [['id' => (int) $d['id'], 'at' => strtotime($d['slot_at'] . ' UTC'), 'text' => self::fullText($d), 'files' => self::files($d)]];
    }

    /** A failed registration: back in the queue (approved) until MAX_TRIES, then 'error' for the user to look at. */
    private static function failed(array $d, string $why): void
    {
        $tries = (int) ($d['tries'] ?? 0) + 1;
        $stop  = $tries >= self::MAX_TRIES;
        self::update((int) $d['id'], ['status' => $stop ? 'error' : 'approved', 'tries' => $tries,
            'error' => mb_substr(($stop ? '재시도 중단 (' : '재시도 대기 (') . $tries . '/' . self::MAX_TRIES . '): ' . $why, 0, 255)]);
    }

    /** Signed links (1 h) to the draft's chosen photos / video, readable from x.com. */
    public static function files(array $d): array
    {
        $files = [];
        $exp = time() + 3600;
        foreach (self::media($d) as $n => $m) {
            if (! $m['chosen']) continue;
            $ext = strtolower(pathinfo((string) $m['path'], PATHINFO_EXTENSION)) ?: ($m['kind'] === 'video' ? 'mp4' : 'jpg');
            $files[] = ['url' => site_url('share-hw/' . $m['id'] . '/' . $exp . '/' . \App\Controllers\Collect::shareSig((int) $m['id'], $exp)),
                        'filename' => ($m['kind'] === 'video' ? 'video' : 'photo' . ($n + 1)) . '.' . $ext,
                        'mime' => $m['mime'] ?: 'application/octet-stream', 'kind' => $m['kind']];
        }
        return $files;
    }

    /**
     * Approved drafts the user scheduled by hand in X's composer (semi-automatic mode): found in the
     * scheduled list the extension synced by the first 25 characters of the text. The draft takes the
     * time actually chosen there. Returns how many matched.
     */
    public static function matchScheduled(array $items): int
    {
        $wait = self::db()->table(self::t('drafts'))->whereIn('status', ['approved', 'error'])->get()->getResultArray();
        if ($wait === []) return 0;
        $head = static fn (string $s) => mb_substr(preg_replace('/\s+/u', ' ', trim($s)), 0, 25);
        $n = 0;
        foreach ($items as $i) {
            if (! is_array($i) || empty($i['id']) || empty($i['at']) || ($i['kind'] ?? 'post') !== 'post') continue;
            foreach ($wait as $k => $d) {
                if ($head((string) ($i['text'] ?? '')) !== $head(self::fullText($d))) continue;
                self::update((int) $d['id'], ['status' => 'scheduled', 'x_id' => (string) $i['id'], 'slot_at' => gmdate('Y-m-d H:i:s', (int) $i['at']), 'error' => null]);
                unset($wait[$k]);
                $n++;
                break;
            }
        }
        return $n;
    }

    /** The extension's report on one queued draft. */
    public static function report(int $id, array $body): ?string
    {
        $d = self::find($id);
        if (! $d) return 'not found';
        if ($d['status'] !== 'sending') return 'not sending';
        $xid = (string) ($body['x_id'] ?? '');
        if (preg_match('/^\d{1,25}$/', $xid)) {
            self::update($id, ['status' => 'scheduled', 'x_id' => $xid, 'error' => null]);
            return null;
        }
        self::failed($d, (string) ($body['error'] ?? 'unknown'));
        return null;
    }

    /** Scheduled drafts whose post the extension saw: status posted, x_id = the post. */
    public static function matchPosted(array $posts): int
    {
        $sched = self::db()->table(self::t('drafts'))->where('status', 'scheduled')->get()->getResultArray();
        if ($sched === []) return 0;
        $n = 0;
        $head = static fn (string $s) => mb_substr(preg_replace('/\s+/u', ' ', trim($s)), 0, 25);
        foreach ($posts as $p) {
            if (! is_array($p) || ($p['kind'] ?? 'post') !== 'post' || empty($p['text']) || empty($p['id'])) continue;
            $t = strtotime((string) ($p['time'] ?? ''));
            foreach ($sched as $k => $d) {
                $slot = strtotime($d['slot_at'] . ' UTC');
                if ($t && abs($t - $slot) > 900) continue;
                if ($head((string) $p['text']) !== $head(self::fullText($d))) continue;
                self::update((int) $d['id'], ['status' => 'posted', 'x_id' => (string) $p['id']]);
                unset($sched[$k]);
                $n++;
                break;
            }
        }
        return $n;
    }

    /** Telegram to every admin with a bot when a batch finished writing (worker loop, once a minute). */
    public static function notifyReady(callable $log): void
    {
        $db = self::db();
        $rows = $db->table(self::t('draft_batches'))->where('finished_at IS NOT NULL', null, false)->where('notified_at', null)->get()->getResultArray();
        foreach ($rows as $b) {
            $counts = $db->table(self::t('drafts'))->select('status, COUNT(*) AS n')->where('batch', $b['batch'])->groupBy('status')->get()->getResultArray();
            $c = array_column($counts, 'n', 'status');
            $msg = '[초안] ' . self::batchLabel($b['batch']) . ' 배치 준비: ' . (int) ($c['new'] ?? 0) . '건'
                 . (($c['skipped'] ?? 0) ? ' (제외 ' . (int) $c['skipped'] . ')' : '') . (($c['failed'] ?? 0) ? ' (실패 ' . (int) $c['failed'] . ')' : '')
                 . "\n" . site_url('drafts?batch=' . $b['batch']);
            foreach (model('UserModel')->where('role', 'admin')->findAll() as $u) {
                if (! Telegram::ready((int) $u['id'])) continue;
                $err = Telegram::send((int) $u['id'], $msg);
                $log('drafts ' . $b['batch'] . ': telegram ' . ($err ?: 'sent') . ' (user ' . $u['id'] . ')');
            }
            $db->table(self::t('draft_batches'))->where('batch', $b['batch'])->update(['notified_at' => gmdate('Y-m-d H:i:s')]);
        }
    }
}
