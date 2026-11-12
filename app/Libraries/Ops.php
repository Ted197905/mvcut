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
        'drafts_06' => ['초안', '06시 배치', '아침 06시 초안 배치 (선정 + 조사 + 작성). 끄면 이 시간대 배치만 건너뜀'],
        'drafts_12' => ['초안', '12시 배치', '낮 12시 초안 배치. 끄면 이 시간대 배치만 건너뜀'],
        'drafts_18' => ['초안', '18시 배치', '저녁 18시 초안 배치. 끄면 이 시간대 배치만 건너뜀'],
        'xviral'    => ['X', 'X 100만+ 수집', '매일 06:40, 24시간 안에 조회 100만 넘은 X 게시물'],
        'cleanup'   => ['수집', '오래된 수집 글 정리', '1시간마다, 14일 지난 글과 파일 삭제 (사용한 글은 보존)'],
        'mvcut_cleanup' => ['MV Cut', '미디어 정리', '6시간마다, 지운 항목의 남은 파일, 업로드 조각, 오래된 작업 기록 정리'],
        'worker'    => ['MV Cut', '미디어 작업', '편집/변환/가져오기 작업 처리 (꺼지면 새 작업을 받지 않고 대기열에 둠)'],
    ];
    /** switches row that marks "everything paused" (updated_at = since when). */
    private const PAUSE = '_pause';

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
        // the model worker's phase (hallyuwire phases.py): collect | select | drafts, with what it is on
        $ph = $act['_phase'] ?? null;
        $phase = ['name' => 'collect', 'detail' => '', 'since' => null];
        if ($ph && $ph['updated_at'] && $now - strtotime($ph['updated_at'] . ' UTC') < 90 * 60) {
            [$pn, $pd] = array_pad(explode(' ', (string) $ph['detail'], 2), 2, '');
            $phase = ['name' => $pn ?: 'collect', 'detail' => $pd, 'since' => $ph['started_at'] ? strtotime($ph['started_at'] . ' UTC') : null];
        }
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
        return ['parts' => $parts, 'models' => $models, 'jobs' => $jobs, 'paused' => self::paused(), 'phase' => $phase];
    }

    /** ['since' => unix] while 일시 정지 is on, null otherwise. */
    public static function paused(): ?array
    {
        try {
            $row = db_connect()->table(self::t('switches'))->where('name', self::PAUSE)->get()->getRowArray();
        } catch (\Throwable $e) {
            return null;
        }
        return $row && (int) $row['enabled'] ? ['since' => strtotime($row['updated_at'] . ' UTC')] : null;
    }

    /**
     * 일시 정지: every part off (each switch remembers its value in `saved`), the media worker stops taking jobs,
     * every model is unloaded from Ollama. The loops stop at their next step (the draft writer within ~15 s) and
     * leave their state in the DB, so 재개 continues where they stopped.
     */
    public static function pause(): void
    {
        $db = db_connect();
        foreach (array_keys(self::PARTS) as $name) {
            $db->query('INSERT INTO ' . self::t('switches') . ' (name, enabled, saved, updated_at) VALUES (?, 0, 1, UTC_TIMESTAMP()) '
                       . 'ON DUPLICATE KEY UPDATE saved = IF(saved IS NULL, enabled, saved), enabled = 0, updated_at = UTC_TIMESTAMP()', [$name]);
        }
        $db->query('INSERT INTO ' . self::t('switches') . ' (name, enabled, updated_at) VALUES (?, 1, UTC_TIMESTAMP()) '
                   . 'ON DUPLICATE KEY UPDATE enabled = 1, updated_at = UTC_TIMESTAMP()', [self::PAUSE]);
        self::unloadAll();
        \App\Libraries\AppLog::info('ops', 'pause', 'all parts paused, models unloaded');
    }

    /** 재개: every switch back to what it was before the pause. */
    public static function resume(): void
    {
        $db = db_connect();
        $db->query('UPDATE ' . self::t('switches') . ' SET enabled = COALESCE(saved, 1), saved = NULL, updated_at = UTC_TIMESTAMP() '
                   . 'WHERE saved IS NOT NULL AND name IN (' . implode(',', array_fill(0, count(self::PARTS), '?')) . ')', array_keys(self::PARTS));
        $db->query('UPDATE ' . self::t('switches') . ' SET enabled = 0, updated_at = UTC_TIMESTAMP() WHERE name = ?', [self::PAUSE]);
        \App\Libraries\AppLog::info('ops', 'resume', 'switches restored');
    }

    /** Unloads every model Ollama has in memory. */
    public static function unloadAll(): void
    {
        $url = rtrim(getenv('OLLAMA_URL') ?: 'http://localhost:11434', '/');
        $ps  = json_decode((string) @file_get_contents($url . '/api/ps', false, stream_context_create(['http' => ['timeout' => 2]])), true);
        foreach ($ps['models'] ?? [] as $m) self::unload((string) $m['name']);
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
