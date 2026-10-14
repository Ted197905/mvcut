<?php

namespace App\Libraries;

/**
 * Telegram bot alerts, one bot per user. The token is a secret, so it lives in
 * secrets/telegram_{user}.json (outside git and the DB) together with the chat id.
 */
class Telegram
{
    private static function path(int $userId): string
    {
        return ROOTPATH . 'secrets/telegram_' . $userId . '.json';
    }

    /** ['token' => ..., 'bot' => username, 'chat_id' => ..., 'chat' => name] or [] */
    public static function config(int $userId): array
    {
        $p = self::path($userId);
        $j = is_file($p) ? json_decode((string) file_get_contents($p), true) : null;
        return is_array($j) ? $j : [];
    }

    public static function ready(int $userId): bool
    {
        $c = self::config($userId);
        return ! empty($c['token']) && ! empty($c['chat_id']);
    }

    private static function save(int $userId, array $c): ?string
    {
        $dir = ROOTPATH . 'secrets';
        if (! is_dir($dir) && ! @mkdir($dir, 0770, true)) return '서버에 secrets 디렉터리를 만들 수 없습니다.';
        if (@file_put_contents(self::path($userId), json_encode($c, JSON_UNESCAPED_UNICODE)) === false) return '설정을 저장할 수 없습니다.';
        @chmod(self::path($userId), 0660);
        return null;
    }

    public static function forget(int $userId): void
    {
        @unlink(self::path($userId));
    }

    /** Bot API call; returns the decoded 'result' or throws with Telegram's description. */
    private static function api(string $token, string $method, array $params = [])
    {
        $ch = curl_init('https://api.telegram.org/bot' . $token . '/' . $method);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 10,
                                CURLOPT_POST => true, CURLOPT_POSTFIELDS => $params]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);
        $j = is_string($body) ? json_decode($body, true) : null;
        if (! is_array($j)) throw new \RuntimeException('텔레그램 서버에 연결하지 못했습니다' . ($err !== '' ? ' (' . $err . ')' : ''));
        if (empty($j['ok'])) throw new \RuntimeException('텔레그램 오류: ' . ($j['description'] ?? '알 수 없음'));
        return $j['result'];
    }

    /** Step 1: checks the token with getMe and stores it (the chat is linked in step 2). */
    public static function setToken(int $userId, string $token): ?string
    {
        $token = trim($token);
        if (! preg_match('/^\d{5,}:[A-Za-z0-9_-]{30,}$/', $token)) return '봇 토큰 형식이 아닙니다. BotFather가 준 "숫자:문자열" 전체를 붙여 넣으세요.';
        try {
            $me = self::api($token, 'getMe');
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }
        return self::save($userId, ['token' => $token, 'bot' => (string) ($me['username'] ?? '')]);
    }

    /** Step 2: finds the private chat that sent the bot a message (e.g. /start) and sends a test. */
    public static function linkChat(int $userId): ?string
    {
        $c = self::config($userId);
        if (empty($c['token'])) return '먼저 봇 토큰을 저장하세요.';
        try {
            $updates = self::api($c['token'], 'getUpdates', ['limit' => 100, 'timeout' => 0]);
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }
        $chat = null;
        foreach (array_reverse((array) $updates) as $u) {
            $m = $u['message'] ?? $u['edited_message'] ?? null;
            if (is_array($m) && ($m['chat']['type'] ?? '') === 'private') { $chat = $m['chat']; break; }
        }
        if (! $chat) return '봇에게 온 메시지가 없습니다. 텔레그램에서 @' . ($c['bot'] ?? '봇') . ' 을 열고 "시작"(/start)을 누른 뒤 다시 시도하세요.';
        $c['chat_id'] = (string) $chat['id'];
        $c['chat']    = trim(($chat['first_name'] ?? '') . ' ' . ($chat['last_name'] ?? '')) ?: ($chat['username'] ?? '');
        if ($e = self::save($userId, $c)) return $e;
        return self::send($userId, 'MV Cut 알림이 연결되었습니다. X 제한이 감지되거나 풀리면 여기로 알려드립니다.');
    }

    /** Sends a plain-text message to the linked chat; returns an error message or null. */
    public static function send(int $userId, string $text): ?string
    {
        $c = self::config($userId);
        if (empty($c['token']) || empty($c['chat_id'])) return '텔레그램이 연결되지 않았습니다.';
        try {
            self::api($c['token'], 'sendMessage', ['chat_id' => $c['chat_id'], 'text' => $text, 'disable_web_page_preview' => 'true']);
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }
        return null;
    }
}
