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
    public const STYLES = ['info' => '음슴체 정보', 'story' => '썰 헤드라인', 'quote' => '요약 + 한 줄 평'];
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

    /** Slot use on the day of `$t` (KST): grid slots, the ones taken (drafts + X scheduled), how many of those X-only. */
    public static function dayUsage(int $userId, int $t): array
    {
        $tz  = new \DateTimeZone('Asia/Seoul');
        $day = (new \DateTimeImmutable('@' . $t))->setTimezone($tz)->setTime(0, 0);
        $grid = array_values(array_filter(self::grid(5), static fn ($g) => $g >= $day->getTimestamp() && $g < $day->getTimestamp() + 86400));
        $drafts = array_map(static fn ($r) => strtotime($r['slot_at'] . ' UTC'), self::db()->table(self::t('drafts'))->select('slot_at')
            ->whereIn('status', ['approved', 'sending', 'scheduled', 'posted'])->where('slot_at IS NOT NULL', null, false)->get()->getResultArray());
        $x = [];
        foreach (json_decode((string) (XWatch::forUser($userId)['scheduled'] ?? ''), true) ?: [] as $i) {
            if (! empty($i['at']) && ! in_array((int) $i['at'], $drafts, true)) $x[] = (int) $i['at'];
        }
        $used = 0; $xOnly = 0;
        foreach ($grid as $g) {
            $hitDraft = (bool) array_filter($drafts, static fn ($v) => abs($v - $g) < self::SLOT_STEP * 60);
            $hitX = (bool) array_filter($x, static fn ($v) => abs($v - $g) < self::SLOT_STEP * 60);
            if ($hitDraft || $hitX) $used++;
            if ($hitX && ! $hitDraft) $xOnly++;
        }
        return ['label' => $day->format('n/j'), 'total' => count($grid), 'used' => $used, 'x' => $xOnly];
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
        self::update((int) $d['id'], ['status' => 'approved', 'slot_at' => gmdate('Y-m-d H:i:s', $slot), 'error' => null]);
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

    /**
     * Drafts for the extension to schedule on X: approved, slot still ahead. They move to 'sending';
     * ones the extension never reported on go back to 'approved' after SENDING_MAX seconds.
     */
    public static function queue(): array
    {
        $db  = self::db();
        $now = gmdate('Y-m-d H:i:s');
        $db->table(self::t('drafts'))->where('status', 'sending')->where('updated_at <', gmdate('Y-m-d H:i:s', time() - self::SENDING_MAX))
            ->update(['status' => 'approved', 'updated_at' => $now]);
        $rows = $db->table(self::t('drafts'))->where('status', 'approved')->where('slot_at >', gmdate('Y-m-d H:i:s', time() + 120))
            ->orderBy('slot_at')->get(20)->getResultArray();
        $out = [];
        foreach ($rows as $d) {
            $files = [];
            $exp = time() + 3600;
            foreach (self::media($d) as $n => $m) {
                if (! $m['chosen']) continue;
                $ext = strtolower(pathinfo((string) $m['path'], PATHINFO_EXTENSION)) ?: ($m['kind'] === 'video' ? 'mp4' : 'jpg');
                $files[] = ['url' => site_url('share-hw/' . $m['id'] . '/' . $exp . '/' . \App\Controllers\Collect::shareSig((int) $m['id'], $exp)),
                            'filename' => ($m['kind'] === 'video' ? 'video' : 'photo' . ($n + 1)) . '.' . $ext,
                            'mime' => $m['mime'] ?: 'application/octet-stream', 'kind' => $m['kind']];
            }
            self::update((int) $d['id'], ['status' => 'sending']);
            $out[] = ['id' => (int) $d['id'], 'at' => strtotime($d['slot_at'] . ' UTC'), 'text' => self::fullText($d), 'files' => $files];
        }
        return $out;
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
        self::update($id, ['status' => 'error', 'error' => mb_substr((string) ($body['error'] ?? 'unknown'), 0, 255)]);
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
