<?php

namespace App\Libraries;

/**
 * The automatic work behind MV Cut, for the admin status bar: the hallyuwire collector's parts
 * (on/off in hallyuwire.switches, what each does now in hallyuwire.activity, see ops.py there),
 * the models loaded in Ollama and the media jobs of the worker.
 */
class Ops
{
    /** name => [group, label, what it does] */
    public const PARTS = [
        'collect'   => ['수집', '목록 수집', '53개 커뮤니티 목록을 소스별 주기로 읽음'],
        'detail'    => ['수집', '본문 수집', '새 글의 본문, 댓글, 사진 주소'],
        'media'     => ['수집', '사진/영상 저장', '초안 글 먼저, 최신 순'],
        'judge'     => ['모델', '뉴스 판정', 'Nemotron 3.5 Bolt (GPU 3GB)'],
        'translate' => ['모델', '번역', 'TranslateGemma 4B (GPU 3GB)'],
        'drafts'    => ['모델', '초안 작성', 'Qwen3.6 35B (GPU 8GB + RAM 18GB), 06/12/18시 100건씩'],
        'xviral'    => ['X', 'X 100만+ 수집', '매일 06:40, 24시간 안에 조회 100만 넘은 X 게시물'],
        'cleanup'   => ['수집', '오래된 수집 글 정리', '1시간마다, 14일 지난 글과 파일 삭제 (사용한 글은 보존)'],
        'mvcut_cleanup' => ['MV Cut', '미디어 정리', '6시간마다, 지운 항목의 남은 파일, 업로드 조각, 오래된 작업 기록 정리'],
    ];

    /** A switch read by MV Cut itself (worker housekeeping); on unless switched off. */
    public static function on(string $name): bool
    {
        try {
            $row = db_connect()->table(self::t('switches'))->where('name', $name)->get()->getRowArray();
            return $row === null || (bool) $row['enabled'];
        } catch (\Throwable $e) {
            return true;
        }
    }
    private const BUSY_MAX = 900;   // a 'busy' row older than this (seconds) is a process that died mid-step

    private static function t(string $table): string
    {
        return env('hallyuwire.db', 'hallyuwire') . '.' . $table;
    }

    public static function status(int $userId): array
    {
        $db  = db_connect();
        $sw  = array_column($db->table(self::t('switches'))->get()->getResultArray(), 'enabled', 'name');
        $act = [];
        foreach ($db->table(self::t('activity'))->get()->getResultArray() as $a) $act[$a['name']] = $a;
        $now = time();
        $parts = [];
        foreach (self::PARTS as $name => [$group, $label, $about]) {
            $a  = $act[$name] ?? null;
            $up = $a ? strtotime($a['updated_at'] . ' UTC') : null;
            $busy = $a && $a['state'] === 'busy' && $now - $up < self::BUSY_MAX;
            $parts[] = ['name' => $name, 'group' => $group, 'label' => $label, 'about' => $about,
                        'on' => (bool) ($sw[$name] ?? 1), 'busy' => $busy, 'detail' => (string) ($a['detail'] ?? ''),
                        'item' => $a['item_id'] ?? null,
                        'since' => $a && $a['started_at'] ? $now - strtotime($a['started_at'] . ' UTC') : null,
                        'last' => $up ? $now - $up : null];
        }
        $models = [];
        $url = rtrim(getenv('OLLAMA_URL') ?: 'http://localhost:11434', '/');
        $ps  = json_decode((string) @file_get_contents($url . '/api/ps', false, stream_context_create(['http' => ['timeout' => 2]])), true);
        foreach ($ps['models'] ?? [] as $m) {
            $models[] = ['name' => $m['name'], 'vram' => round(($m['size_vram'] ?? 0) / 1e9, 1), 'size' => round(($m['size'] ?? 0) / 1e9, 1)];
        }
        $jobs = [];
        foreach ($db->table('jobs')->select('id, type, progress, user_id, started_at')->where('status', 'running')->get()->getResultArray() as $j) {
            $jobs[] = ['id' => (int) $j['id'], 'type' => $j['type'], 'progress' => (int) $j['progress'], 'mine' => (int) $j['user_id'] === $userId,
                       'since' => $j['started_at'] ? $now - strtotime($j['started_at']) : null];
        }
        return ['parts' => $parts, 'models' => $models, 'jobs' => $jobs];
    }

    public static function setSwitch(string $name, bool $on): void
    {
        if (! isset(self::PARTS[$name])) return;
        db_connect()->query('INSERT INTO ' . self::t('switches') . ' (name, enabled, updated_at) VALUES (?, ?, UTC_TIMESTAMP()) '
                            . 'ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), updated_at = VALUES(updated_at)', [$name, $on ? 1 : 0]);
    }

    /** Unloads one model from Ollama (frees GPU / RAM; the next call that needs it loads it again). */
    public static function unload(string $model): void
    {
        $url = rtrim(getenv('OLLAMA_URL') ?: 'http://localhost:11434', '/');
        @file_get_contents($url . '/api/generate', false, stream_context_create(['http' => [
            'method' => 'POST', 'header' => 'Content-Type: application/json', 'timeout' => 20,
            'content' => json_encode(['model' => $model, 'keep_alive' => 0])]]));
    }
}
