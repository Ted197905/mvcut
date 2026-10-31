<?php

namespace App\Libraries;

/**
 * Site-wide log in the app_log table, for finding out what happened when something went wrong:
 * every non-GET web request and every failed one (Filters\ActionLogFilter), the worker's steps
 * (Commands\WorkerRun), PHP warnings/exceptions (Log\DbHandler), Telegram sends, browser errors
 * (Controllers\Logs::js). Admin page /admin/logs. Writing never throws: a log failure must not
 * break the action being logged.
 */
class AppLog
{
    public const LEVELS = ['info', 'warn', 'error'];
    public const KEEP_DAYS = 14;

    private static ?string $req = null;
    private static bool $busy = false;

    /** Id of the current request (shown on error pages so the user can quote it). */
    public static function reqId(): string
    {
        return self::$req ??= substr(bin2hex(random_bytes(6)), 0, 12);
    }

    public static function info(string $area, string $action, string $msg, mixed $detail = null, ?int $userId = null): void
    {
        self::write('info', $area, $action, $msg, $detail, $userId);
    }

    public static function warn(string $area, string $action, string $msg, mixed $detail = null, ?int $userId = null): void
    {
        self::write('warn', $area, $action, $msg, $detail, $userId);
    }

    public static function error(string $area, string $action, string $msg, mixed $detail = null, ?int $userId = null): void
    {
        self::write('error', $area, $action, $msg, $detail, $userId);
    }

    public static function exception(string $area, string $action, \Throwable $e, mixed $ctx = null, ?int $userId = null): void
    {
        $detail = get_class($e) . ': ' . $e->getMessage() . "\nin " . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString();
        if ($ctx !== null) $detail .= "\n\ncontext: " . self::text($ctx);
        self::write('error', $area, $action, $e->getMessage(), $detail, $userId);
    }

    /** A worker log line: "FAILED"/"failed"/"실패"/"error" lines are errors, the rest info. */
    public static function worker(string $line): void
    {
        $level = preg_match('/FAILED|failed|실패|error|exception/i', $line) ? 'error' : 'info';
        $action = preg_match('/^(job \d+|xwatch|drafts|cleanup|worker|release|check)\b/i', $line, $m) ? strtolower($m[1]) : 'log';
        self::write($level, 'worker', $action, $line, null, null);
    }

    public static function write(string $level, string $area, string $action, string $msg, mixed $detail = null, ?int $userId = null): void
    {
        if (self::$busy) return;          // a DB error while logging must not log itself forever
        self::$busy = true;
        try {
            if (! in_array($level, self::LEVELS, true)) $level = 'info';
            $row = [
                'ts'      => gmdate('Y-m-d H:i:s'),
                'level'   => $level,
                'area'    => mb_substr($area, 0, 24),
                'action'  => mb_substr($action, 0, 120),
                'user_id' => $userId ?? self::userId(),
                'req'     => is_cli() ? null : self::reqId(),
                'ip'      => is_cli() ? null : self::ip(),
                'msg'     => mb_substr(trim(preg_replace('/\s+/', ' ', $msg)), 0, 600),
                'detail'  => $detail === null || $detail === '' ? null : mb_substr(self::text($detail), 0, 200000),
            ];
            db_connect()->table('app_log')->insert($row);
        } catch (\Throwable $e) {
            // last resort: the framework's file log
            try { log_message('error', 'AppLog write failed: ' . $e->getMessage() . ' | ' . $area . ' ' . $action . ' ' . $msg); } catch (\Throwable) {}
        } finally {
            self::$busy = false;
        }
    }

    /** Errors in the last 24 hours, for the nav badge. */
    public static function recentErrors(): int
    {
        try {
            return (int) db_connect()->table('app_log')->where('level', 'error')
                ->where('ts >', gmdate('Y-m-d H:i:s', time() - 86400))->countAllResults();
        } catch (\Throwable) {
            return 0;
        }
    }

    public static function cleanup(): int
    {
        $db = db_connect();
        $db->table('app_log')->where('ts <', gmdate('Y-m-d H:i:s', time() - self::KEEP_DAYS * 86400))->delete();
        return $db->affectedRows();
    }

    private static function text(mixed $v): string
    {
        if (is_string($v)) return $v;
        return (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    private static function userId(): ?int
    {
        if (is_cli()) return null;
        try {
            $id = session()->get('user_id');
            return $id ? (int) $id : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function ip(): ?string
    {
        try {
            return service('request')->getIPAddress() ?: null;
        } catch (\Throwable) {
            return null;
        }
    }
}
