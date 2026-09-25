<?php

namespace App\Libraries;

/**
 * Login cookies the operator uploads so the importer can read posts that need a session.
 * Files live in secrets/ (never in git) and are shared by the whole server.
 */
class Cookies
{
    public const PLATFORMS = [
        'instagram' => ['label' => 'Instagram', 'domains' => ['instagram.com'], 'site' => 'https://www.instagram.com/'],
        'threads'   => ['label' => 'Threads',   'domains' => ['threads.com', 'threads.net'], 'site' => 'https://www.threads.com/'],
        'facebook'  => ['label' => 'Facebook',  'domains' => ['facebook.com'], 'site' => 'https://www.facebook.com/', 'session' => ['c_user', 'xs']],
        'x'         => ['label' => 'X',         'domains' => ['x.com', 'twitter.com'], 'site' => 'https://x.com/', 'session' => ['auth_token', 'ct0']],
    ];

    /** Cookies that carry the session; used for the expiry readout. */
    private const SESSION_COOKIES = ['sessionid', 'ds_user_id', 'csrftoken', 'c_user', 'xs', 'auth_token'];

    public static function known(string $platform): bool
    {
        return isset(self::PLATFORMS[$platform]);
    }

    public static function path(string $platform): ?string
    {
        return self::known($platform) ? ROOTPATH . 'secrets/cookies_' . $platform . '.txt' : null;
    }

    /** Parsed view of one platform's cookie file. */
    public static function status(string $platform): array
    {
        $out = ['platform' => $platform, 'label' => self::PLATFORMS[$platform]['label'] ?? $platform,
                'site' => self::PLATFORMS[$platform]['site'] ?? '', 'exists' => false, 'names' => [],
                'expires' => null, 'expired' => false, 'soon' => false, 'uploaded' => null, 'failure' => null];
        $p = self::path($platform);
        if (! $p || ! is_readable($p)) {
            $out['failure'] = self::failure($platform);
            return $out;
        }
        $rows = self::parse((string) file_get_contents($p));
        $out['exists']   = $rows !== [];
        $out['uploaded'] = filemtime($p) ?: null;
        $out['names']    = array_values(array_unique(array_column($rows, 'name')));
        $exp = [];
        foreach ($rows as $r) {
            if (in_array($r['name'], self::SESSION_COOKIES, true) && $r['expires'] > 0) $exp[] = $r['expires'];
        }
        if ($exp !== []) {
            $out['expires'] = min($exp);
            $out['expired'] = $out['expires'] <= time();
            $out['soon']    = ! $out['expired'] && $out['expires'] - time() < 14 * 86400;
        }
        $out['failure'] = self::failure($platform);
        return $out;
    }

    public static function all(): array
    {
        $out = [];
        foreach (array_keys(self::PLATFORMS) as $k) $out[$k] = self::status($k);
        return $out;
    }

    /**
     * Validates and stores an uploaded Netscape cookies.txt.
     * Returns null on success, or a user-facing reason.
     */
    public static function save(string $platform, string $raw): ?string
    {
        if (! self::known($platform)) return '알 수 없는 플랫폼입니다.';
        $rows = self::parse($raw);
        if ($rows === []) return 'Netscape 형식 cookies.txt가 아닙니다. 확장에서 "Export" 한 파일을 그대로 올려 주세요.';
        $domains = self::PLATFORMS[$platform]['domains'];
        $match = false;
        foreach ($rows as $r) {
            $d = ltrim($r['domain'], '.');
            foreach ($domains as $suffix) {
                if ($d === $suffix || str_ends_with($d, '.' . $suffix)) { $match = true; break 2; }
            }
        }
        if (! $match) return self::PLATFORMS[$platform]['label'] . ' 쿠키가 아닙니다. 해당 사이트에 로그인한 상태에서 다시 내보내 주세요.';
        $need = self::PLATFORMS[$platform]['session'] ?? ['sessionid'];
        $missing = array_diff($need, array_column($rows, 'name'));
        if ($missing !== []) {
            return '로그인 세션 쿠키(' . implode(', ', $missing) . ')가 없습니다. 로그인한 상태에서 내보냈는지 확인해 주세요.';
        }
        $dir = ROOTPATH . 'secrets';
        if (! is_dir($dir) && ! @mkdir($dir, 0770, true)) return '서버에 secrets 디렉터리를 만들 수 없습니다.';
        $p = self::path($platform);
        // the previous file may belong to the deploy user, so replace it rather than write into it
        if (is_file($p)) @unlink($p);
        if (@file_put_contents($p, self::render($rows)) === false) return '파일을 저장할 수 없습니다. secrets 디렉터리 권한을 확인해 주세요.';
        @chmod($p, 0660);
        self::clearFailure($platform);
        return null;
    }

    public static function delete(string $platform): void
    {
        $p = self::path($platform);
        if ($p && is_file($p)) @unlink($p);
        self::clearFailure($platform);
    }

    /** Records why the last import with these cookies failed, so the settings page can say so. */
    public static function markFailure(string $platform, string $message): void
    {
        if (! self::known($platform) || ! self::path($platform) || ! is_file(self::path($platform))) return;
        $s = self::state();
        $s[$platform] = ['at' => time(), 'message' => mb_substr($message, 0, 300)];
        self::writeState($s);
    }

    public static function clearFailure(string $platform): void
    {
        $s = self::state();
        if (isset($s[$platform])) { unset($s[$platform]); self::writeState($s); }
    }

    public static function failure(string $platform): ?array
    {
        return self::state()[$platform] ?? null;
    }

    /** True when a cookie file is present but expired or failing: the UI asks for a fresh export. */
    public static function needsRenewal(string $platform): bool
    {
        $s = self::status($platform);
        return $s['exists'] && ($s['expired'] || $s['failure'] !== null);
    }

    /** @return array<int,array{domain:string,flag:string,path:string,secure:string,expires:int,name:string,value:string}> */
    private static function parse(string $raw): array
    {
        $rows = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim($line, "\r\n");
            if ($line === '' || str_starts_with(ltrim($line), '#')) continue;
            $f = explode("\t", $line);
            if (count($f) < 7) continue;
            [$domain, $flag, $path, $secure, $expires, $name, $value] = array_slice($f, 0, 7);
            if ($domain === '' || $name === '') continue;
            $rows[] = ['domain' => $domain, 'flag' => strtoupper($flag) === 'TRUE' ? 'TRUE' : 'FALSE',
                       'path' => $path !== '' ? $path : '/', 'secure' => strtoupper($secure) === 'TRUE' ? 'TRUE' : 'FALSE',
                       'expires' => (int) $expires, 'name' => $name, 'value' => $value];
        }
        return $rows;
    }

    /** @param array<int,array<string,mixed>> $rows */
    private static function render(array $rows): string
    {
        $out = "# Netscape HTTP Cookie File\n# uploaded via MV Cut settings\n\n";
        foreach ($rows as $r) {
            $out .= implode("\t", [$r['domain'], $r['flag'], $r['path'], $r['secure'], (string) $r['expires'], $r['name'], $r['value']]) . "\n";
        }
        return $out;
    }

    private static function stateFile(): string
    {
        return WRITEPATH . 'cookie_state.json';
    }

    private static function state(): array
    {
        $f = self::stateFile();
        if (! is_readable($f)) return [];
        $j = json_decode((string) file_get_contents($f), true);
        return is_array($j) ? $j : [];
    }

    private static function writeState(array $s): void
    {
        @file_put_contents(self::stateFile(), json_encode($s, JSON_UNESCAPED_UNICODE));
        @chmod(self::stateFile(), 0664);
    }
}
