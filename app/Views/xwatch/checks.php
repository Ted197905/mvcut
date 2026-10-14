<?php
use App\Libraries\XWatch;

$badge = static fn ($ban) => $ban === true ? ['bad', '제한'] : ($ban === false ? ['ok', '정상'] : ['none', '-']);
$stCls = ['ok' => 'ok', 'banned' => 'bad', 'error' => 'warn'];
$stTxt = ['ok' => '정상', 'banned' => '제한 감지', 'error' => '검사 실패'];
$short = ['search' => '검색 차단', 'typeahead' => '검색 제안', 'ghost' => '고스트 밴', 'deboost' => '답글 디부스트'];
$link  = static function (array $set) use ($status, $page) {
    $qs = array_filter(array_merge(['status' => $status, 'page' => $page > 1 ? $page : ''], $set));
    return site_url('xwatch/checks') . ($qs ? '?' . http_build_query($qs) : '');
};
// details worth showing for one test: which reply/parent it used, what it skipped, why it gave up
$detail = static function (array $t): string {
    $out = [];
    if (isset($t['count'])) $out[] = '검색 결과 ' . (int) $t['count'] . '개';
    if (isset($t['results'])) $out[] = '자동완성 ' . (int) $t['results'] . '개';
    if (! empty($t['parent_handle'])) $out[] = '@' . $t['parent_handle'] . ' 스레드';
    if (! empty($t['stage'])) $out[] = $t['stage'];
    if (! empty($t['skipped'])) $out[] = '건너뛴 답글 ' . count($t['skipped']) . '개';
    if (! empty($t['uncertain'])) $out[] = '긴 스레드라 불확실';
    if (! empty($t['error'])) $out[] = $t['error'];
    return implode(' · ', $out);
};
?>
<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>
<main class="wide settings xwatch">
  <a class="back" href="<?= site_url('xwatch') ?>">&lsaquo; X 계정 관리</a>
  <h1>제한 검사 기록</h1>
  <p class="muted small">@<?= esc($handle ?: '-') ?> · 최근 30일 보관 · 전체 <?= (int) $total ?>회
    (정상 <?= (int) ($counts['ok'] ?? 0) ?> · 제한 감지 <?= (int) ($counts['banned'] ?? 0) ?> · 검사 실패 <?= (int) ($counts['error'] ?? 0) ?>)</p>

  <div class="segmented xw-filter">
    <?php foreach (['' => '전체', 'banned' => '제한 감지', 'error' => '검사 실패', 'ok' => '정상'] as $v => $t): ?>
      <a class="<?= $status === $v ? 'on' : '' ?>" href="<?= esc($link(['status' => $v, 'page' => '']), 'attr') ?>"><?= $t ?></a>
    <?php endforeach ?>
  </div>

  <?php if (! $rows): ?>
    <p class="muted" style="margin-top:24px">기록이 없습니다.</p>
  <?php else: ?>
    <div class="xw-log">
      <?php foreach ($rows as $h): $r = $h['result']; ?>
        <details class="xw-log-row">
          <summary>
            <span class="xw-log-time"><?= esc(XWatch::kst($h['ts'], 'm/d H:i')) ?></span>
            <span class="ck-badge <?= $stCls[$h['status']] ?? 'none' ?>"><?= $stTxt[$h['status']] ?? esc($h['status']) ?></span>
            <span class="xw-kind"><?= esc(XWatch::SOURCES[$r['source'] ?? 'probe'] ?? ($r['source'] ?? '')) ?></span>
            <span class="xw-log-tests">
              <?php foreach ($short as $k => $label): [$c, $t] = $badge($r[$k]['ban'] ?? null); ?>
                <span class="ck-badge <?= $c ?>" title="<?= esc($label, 'attr') ?>"><?= $label ?> <?= $t ?></span>
              <?php endforeach ?>
            </span>
            <span class="xw-log-sum muted small"><?= esc($h['summary']) ?></span>
          </summary>
          <dl class="list">
            <div class="row"><dt>검사 방법</dt><dd><?= ($r['source'] ?? 'probe') === 'yuzurisa' ? 'shadowban.yuzurisa.com' : '부계정' . (! empty($r['probe']) ? ' @' . esc($r['probe']) : '') ?></dd></div>
            <?php foreach (XWatch::TESTS as $k => $label): $t = is_array($r[$k] ?? null) ? $r[$k] : []; [$c, $tx] = $badge($t['ban'] ?? null); ?>
              <div class="row"><dt><?= esc($label) ?></dt><dd><span class="ck-badge <?= $c ?>"><?= $tx === '-' ? '확인 불가' : $tx ?></span>
                <?php if ($d = $detail($t)): ?> <span class="muted small"><?= esc($d) ?></span><?php endif ?>
                <?php if (! empty($t['reply'])): ?> <a class="small" href="https://x.com/i/status/<?= esc($t['reply'], 'attr') ?>" target="_blank" rel="noopener">답글</a><?php endif ?>
                <?php if (! empty($t['parent'])): ?> <a class="small" href="https://x.com/i/status/<?= esc($t['parent'], 'attr') ?>" target="_blank" rel="noopener">원글</a><?php endif ?>
              </dd></div>
            <?php endforeach ?>
            <?php if (! empty($r['error'])): ?><div class="row"><dt>오류</dt><dd><?= esc($r['error']) ?></dd></div><?php endif ?>
          </dl>
        </details>
      <?php endforeach ?>
    </div>

    <?php if ($pages > 1): ?>
      <nav class="pager">
        <a class="pg<?= $page <= 1 ? ' off' : '' ?>" href="<?= esc($link(['page' => max(1, $page - 1)]), 'attr') ?>">이전</a>
        <span class="muted small"><?= $page ?> / <?= $pages ?></span>
        <a class="pg<?= $page >= $pages ? ' off' : '' ?>" href="<?= esc($link(['page' => min($pages, $page + 1)]), 'attr') ?>">다음</a>
      </nav>
    <?php endif ?>
  <?php endif ?>
</main>
<?= $this->endSection() ?>
