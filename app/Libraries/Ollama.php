<?php

namespace App\Libraries;

/** Minimal client for the Ollama server on this machine (localhost:11434). */
class Ollama
{
    public const MODEL = 'huihui_ai/qwen3.5-abliterated:9b';

    public static function url(): string
    {
        return rtrim((string) (env('ollama.url') ?: 'http://127.0.0.1:11434'), '/');
    }

    public static function model(): string
    {
        return (string) (env('ollama.model') ?: self::MODEL);
    }

    public static function available(): bool
    {
        $ch = curl_init(self::url() . '/api/tags');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3]);
        $ok = curl_exec($ch) !== false && curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 200;
        curl_close($ch);
        return $ok;
    }

    /**
     * One completion. $json = true asks the model for a JSON object and decodes it.
     * @return string|array|null null when the server fails or the JSON cannot be parsed
     */
    public static function generate(string $system, string $prompt, bool $json = false, int $numCtx = 16384, int $timeout = 600)
    {
        $body = [
            'model'   => self::model(),
            'system'  => $system,
            'prompt'  => $prompt,
            'stream'  => false,
            'think'   => false,
            'options' => ['num_ctx' => $numCtx, 'temperature' => 0.2, 'num_predict' => 2048],
        ];
        if ($json) $body['format'] = 'json';
        $ch = curl_init(self::url() . '/api/generate');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) throw new \RuntimeException('Ollama: ' . $err);
        $res = json_decode($raw, true);
        if (! is_array($res) || ! isset($res['response'])) {
            throw new \RuntimeException('Ollama: ' . mb_substr((string) ($res['error'] ?? $raw), 0, 300));
        }
        $text = trim((string) $res['response']);
        if (! $json) return $text;
        $obj = json_decode($text, true);
        if (! is_array($obj) && preg_match('/\{.*\}/su', $text, $m)) $obj = json_decode($m[0], true);
        return is_array($obj) ? $obj : null;
    }
}
