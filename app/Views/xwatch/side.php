<?php
/**
 * Compact X watch panel (library sidebar). $xw = XWatch::status() + 'row' (decoded latest check) or null.
 * $compact = true (media detail page): the two status boxes only, without the post list.
 */
use App\Libraries\XWatch;

if (! $xw) return;
$tl    = $xw['timeline'];
$c     = $xw['row'];
$kinds = ['post' => '게시', 'reply' => '답글', 'quote' => '인용', 'repost' => '재게시'];
$short = ['search' => '검색 차단', 'typeahead' => '검색 제안', 'ghost' => '고스트 밴', 'deboost' => '답글 디부스트'];
$ago   = static function (int $ts): string {
    $s = time() - $ts;
    return $s < 60 ? '방금' : ($s < 3600 ? intdiv($s, 60) . '분' : ($s < 86400 ? intdiv($s, 3600) . '시간' : intdiv($s, 86400) . '일'));
};
$gapTxt = static fn (?int $m) => $m === null ? '-' : ($m >= 60 ? intdiv($m, 60) . '시간 ' . ($m % 60) . '분' : $m . '분');
?>
<?php $compact = ! empty($compact); ?>
<aside class="xw-side<?= $compact ? ' compact' : '' ?>" id="xwSide" data-compact="<?= $compact ? '1' : '0' ?>">
  <div class="xw-side-head">
    <a href="<?= site_url('xwatch') ?>"><b>X 관리</b></a>
    <span class="muted small">@<?= esc($xw['handle']) ?></span>
  </div>

  <div class="xw-box">
    <div class="xw-row"><span>제한 검사</span>
      <?php if ($c): $cls = ['ok' => 'ok', 'banned' => 'bad', 'error' => 'warn'][$c['status']] ?? 'none'; ?>
        <span class="ck-badge <?= $cls ?>"><?= $c['status'] === 'ok' ? '정상' : ($c['status'] === 'banned' ? '제한 감지' : '검사 실패') ?></span>
      <?php else: ?><span class="ck-badge none">기록 없음</span><?php endif ?>
    </div>
    <?php if ($c): ?>
      <p class="xw-sum"><?= esc($c['summary']) ?> <span class="muted">· <?= esc(XWatch::kst($c['ts'], 'H:i')) ?> (<?= $ago($c['ts']) ?> 전)<?= ! empty($c['result']['source']) ? ' · ' . esc(XWatch::SOURCES[$c['result']['source']] ?? $c['result']['source']) : '' ?></span></p>
      <div class="xw-mini">
        <?php foreach ($short as $k => $label): $v = $c['result'][$k]['ban'] ?? null; ?>
          <div><span><?= $label ?></span><span class="ck-badge <?= $v === true ? 'bad' : ($v === false ? 'ok' : 'none') ?>"><?= $v === true ? '제한' : ($v === false ? '정상' : '-') ?></span></div>
        <?php endforeach ?>
      </div>
    <?php endif ?>
    <?php if (! empty($xw['restricted'])): ?><p class="xw-warn" style="margin:8px 0 0">제한 감지 모드 · 1시간마다 부계정 검사<?= $xw['next_check'] ? ' (다음 ' . esc(XWatch::kst($xw['next_check'], 'H:i')) . ')' : '' ?></p><?php endif ?>
    <a class="btn sm secondary xw-more" href="<?= site_url('xwatch/checks') ?>">검사 기록 보기</a>
  </div>

  <?php if (! $compact): $sc = $xw['scheduled'] ?? ['items' => [], 'synced' => null]; ?>
  <div class="xw-box xw-sched">
    <div class="xw-row"><a href="https://x.com/compose/post/unsent/scheduled" target="_blank" rel="noopener">예약 게시</a>
      <span class="ck-badge <?= $sc['items'] ? 'ok' : 'none' ?>"><?= count($sc['items']) ?>개</span>
    </div>
    <?php if ($sc['synced'] === null): ?>
      <p class="muted small" style="margin:0">x.com을 열면 확장이 예약 목록을 가져옵니다.</p>
    <?php elseif (! $sc['items']): ?>
      <p class="muted small" style="margin:0">예약된 게시물 없음 · <?= $ago($sc['synced']) ?> 전 확인</p>
    <?php else: ?>
      <div class="xw-list">
        <?php foreach ($sc['items'] as $p): ?>
          <div class="xw-item<?= $p['danger'] ? ' danger' : ($p['short'] ? ' short' : ($p['gap'] !== null ? ' kept' : '')) ?><?= $p['counted'] ? '' : ' dim' ?>">
            <span class="t"><b><?= esc(XWatch::kst($p['at'], 'h:i A')) ?></b><i><?= esc(XWatch::kst($p['at'], 'n/j')) ?></i></span>
            <span class="b">
              <span class="m"><em class="<?= esc($p['kind']) ?>"><?= $kinds[$p['kind']] ?? esc($p['kind']) ?></em><small title="직전 게시(예약 포함)와의 간격">간격 <?= esc($gapTxt($p['gap'])) ?></small></span>
              <span class="x"><?= $p['text'] !== '' ? esc($p['text']) : '(미디어)' ?><?= $p['media'] ? ' · 미디어 ' . (int) $p['media'] : '' ?></span>
            </span>
          </div>
        <?php endforeach ?>
      </div>
      <p class="muted small" style="margin:6px 0 0"><?= $ago($sc['synced']) ?> 전 확인</p>
    <?php endif ?>
  </div>
  <?php endif ?>

  <div class="xw-box">
    <div class="xw-row"><span>다음 게시</span>
      <span class="ck-badge <?= $tl['can_post'] ? 'ok' : 'warn' ?>"><?= $tl['can_post'] ? '지금 가능' : esc(XWatch::kst($tl['next_ok'], 'H:i')) . ' 이후 (' . (int) ceil(($tl['next_ok'] - time()) / 60) . '분)' ?></span>
    </div>
    <div class="xw-stats">
      <div><b><?= $tl['hour'] ?>/<?= (int) $xw['limits']['hour_max'] ?></b><span>1시간</span></div>
      <div><b><?= $tl['day'] ?>/<?= (int) $xw['limits']['day_max'] ?></b><span>24시간</span></div>
      <div><b><?= $tl['last'] ? $ago($tl['last']) : '-' ?></b><span>마지막</span></div>
    </div>
    <?php foreach ($tl['warnings'] as $m): ?><p class="xw-warn"><?= esc($m) ?></p><?php endforeach ?>
  </div>

  <?php if (! $compact): ?>
  <div class="xw-box xw-list">
    <?php if (! $tl['posts']): ?>
      <p class="muted small" style="margin:0;padding:10px 12px">최근 48시간 기록 없음</p>
    <?php endif ?>
    <?php foreach (array_slice($tl['posts'], 0, 40) as $p): ?>
      <a class="xw-item<?= ! empty($p['danger']) ? ' danger' : ($p['short'] ? ' short' : ($p['gap'] !== null ? ' kept' : '')) ?><?= $p['counted'] ? '' : ' dim' ?>" href="https://x.com/<?= esc($xw['handle']) ?>/status/<?= esc($p['id']) ?>" target="_blank" rel="noopener">
        <span class="t"><b><?= esc(XWatch::kst($p['ts'], 'H:i')) ?></b><i><?= esc(XWatch::kst($p['ts'], 'n/j')) ?></i></span>
        <span class="b">
          <span class="m"><em class="<?= esc($p['kind']) ?>"><?= $kinds[$p['kind']] ?? esc($p['kind']) ?></em><small title="인용 포함 간격 / 인용 제외 간격">간격 <?= esc($gapTxt($p['gap'])) ?><?= $p['counted'] ? ' · 인용제외 ' . esc($gapTxt($p['gap_noquote'] ?? null)) : '' ?></small></span>
          <span class="x"><?= $p['reply_to'] && ! str_starts_with($p['text'], '@' . $p['reply_to']) ? '@' . esc($p['reply_to']) . ' · ' : '' ?><?= $p['text'] !== '' ? esc($p['text']) : '(미디어)' ?></span>
        </span>
      </a>
    <?php endforeach ?>
  </div>
  <?php endif ?>
</aside>
