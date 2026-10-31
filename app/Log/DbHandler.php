<?php

namespace App\Log;

use App\Libraries\AppLog;
use CodeIgniter\Log\Handlers\BaseHandler;

/** Mirrors the framework log (warnings, uncaught exceptions) into app_log; deprecations stay in the file log only. */
class DbHandler extends BaseHandler
{
    public function handle($level, $message): bool
    {
        $message = (string) $message;
        if (str_contains($message, '[DEPRECATED]')) return true;
        $lv = in_array($level, ['warning', 'notice'], true) ? 'warn' : 'error';
        $first = strtok($message, "\n") ?: $message;
        AppLog::write($lv, 'php', (string) $level, $first, $message);
        return true;
    }
}
