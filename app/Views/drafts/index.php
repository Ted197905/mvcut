<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>
<?php
use App\Controllers\Collect;
use App\Libraries\Drafts as D;
use App\Libraries\XWatch;

$back = http_build_query(array_filter(['batch' => $batch, 'f' => $filter === 'new' ? '' : $filter]));
$qs = static fn (array $over) => site_url('drafts?' . http_build_query(array_filter(array_merge(['batch' => $batch, 'f' => $filter === 'new' ? '' : $filter], $over))));
$num = static fn ($v) => $v === null ? null : number_format((int) $v);
$kst = static fn (?string $utc, string $fmt = 'n/j H:i') => $utc ? XWatch::kst(strtotime($utc . ' UTC'), $fmt) : '';
$badge = static function (array $d) use ($kst): array {
    return match ($d['status']) {
        'gen', 'regen' => ['쓰는 중', 'warn'],
        'new'       => ['검토 대기', ''],
        'approved'  => [$kst($d['slot_at']) . ' 예정', 'ok'],
        'sending'   => [$kst($d['slot_at']) . ' 등록 중', 'ok'],
        'scheduled' => [$kst($d['slot_at']) . ' X 예약됨', 'ok'],
        'posted'    => [$kst($d['slot_at']) . ' 게시됨', 'ok'],
        'rejected'  => ['제외', 'bad'],
        'skipped'   => ['모델이 제외', 'bad'],
        'error'     => ['등록 실패', 'bad'],
        'failed'    => ['작성 실패', 'bad'],
        default     => [$d['status'], ''],
    };
};
$pending = (int) ($counts['new'] ?? 0) + (int) ($counts['error'] ?? 0) + (int) ($counts['failed'] ?? 0);
$writing = (int) ($counts['gen'] ?? 0) + (int) ($counts['regen'] ?? 0);
?>
<main class="yt-page dr-page">
  <?= view('partials/alerts') ?>
  <h1>초안</h1>
  <p class="muted" style="margin:0 0 18px">수집한 글로 매일 10시, 18시에 쓴 X 게시글 초안입니다. 고쳐서 승인하면 09:05~22:00 사이 15분 간격의 빈 슬롯에 들어가고, 확장이 X 예약 게시로 등록합니다. 승인하지 않은 글은 올라가지 않습니다.</p>

  <form class="lib-toolbar" method="get" action="<?= site_url('drafts') ?>">
    <select class="input" name="batch" onchange="this.form.submit()">
      <?php foreach ($batches as $b): ?>
        <option value="<?= esc($b['batch'], 'attr') ?>"<?= $b['batch'] === $batch ? ' selected' : '' ?>><?= esc(D::batchLabel($b['batch'])) ?> (<?= (int) $b['made'] ?: '쓰는 중' ?>)</option>
      <?php endforeach ?>
      <?php if ($batches === []): ?><option value="">배치 없음</option><?php endif ?>
    </select>
    <div class="seg" role="tablist">
      <?php foreach ($filters as $k => $v): ?>
        <a class="seg-item<?= $filter === $k ? ' on' : '' ?>" href="<?= $qs(['f' => $k === 'new' ? '' : $k]) ?>"><?= $v ?><?php if ($k === 'new' && $pending): ?> <b><?= $pending ?></b><?php elseif ($k === 'done' && ($n = (int) ($counts['approved'] ?? 0) + (int) ($counts['sending'] ?? 0) + (int) ($counts['scheduled'] ?? 0) + (int) ($counts['posted'] ?? 0))): ?> <b><?= $n ?></b><?php endif ?></a>
      <?php endforeach ?>
    </div>
    <span class="spacer"></span>
    <span class="muted small"><?= $writing ? $writing . '건 쓰는 중 · ' : '' ?>다음 빈 슬롯 <?= $next ? XWatch::kst($next, 'n/j H:i') : '없음' ?></span>
  </form>

  <?php if ($rows === []): ?>
    <div class="empty"><?= $batches === [] ? '아직 만들어진 배치가 없습니다. 수집기가 10시와 18시에 씁니다.' : '이 조건에 맞는 초안이 없습니다.' ?></div>
  <?php else: ?>
  <div class="dr-list">
    <?php foreach ($rows as $d): [$label, $cls] = $badge($d);
      $editable = in_array($d['status'], ['new', 'error', 'failed', 'rejected', 'skipped'], true);
      $thread = json_decode((string) $d['thread'], true) ?: [];
      $stat = array_filter(['조회 ' . $num($d['views']) => $d['views'] !== null, '댓글 ' . $num($d['comments']) => $d['comments'] !== null, '추천 ' . $num($d['likes']) => $d['likes'] !== null]);
      $firstImg = null;
      foreach ($d['files'] as $m) { if ($m['kind'] === 'image') { $firstImg = $m; break; } }
    ?>
    <article class="dr-card st-<?= esc($d['status'], 'attr') ?>" id="d<?= (int) $d['id'] ?>" data-id="<?= (int) $d['id'] ?>" data-status="<?= esc($d['status'], 'attr') ?>">
      <div class="dr-src">
        <div class="dr-src-head">
          <span class="ck-badge <?= $cls ?>"><?= esc($label) ?></span>
          <span class="ck-badge"><?= esc(D::STYLES[$d['style']] ?? $d['style']) ?></span>
          <span class="muted small">점수 <?= number_format((float) $d['score'], 1) ?></span>
        </div>
        <a class="dr-title" href="<?= site_url('collect/' . (int) $d['item_id']) ?>" target="_blank" rel="noopener"><?= esc($d['title_ko'] ?: $d['title']) ?></a>
        <div class="meta"><?= esc($names[$d['source_id']] ?? $d['source_id']) ?> · <?= esc(Collect::when($d['posted_at'] ?: $d['first_seen'])) ?><?= $stat ? ' · ' . esc(implode(' · ', array_keys($stat))) : '' ?> · <a href="<?= esc($d['url'], 'attr') ?>" target="_blank" rel="noopener noreferrer">원문</a></div>
        <?php if ($d['error']): ?><div class="dr-err"><?= esc($d['error']) ?></div><?php endif ?>
        <?php if ($d['files']): ?>
          <div class="dr-media">
            <?php foreach ($d['files'] as $m): ?>
              <label class="dr-m<?= $m['chosen'] ? ' on' : '' ?>" title="<?= esc($m['kind'] . ' ' . ($m['width'] ? $m['width'] . 'x' . $m['height'] : ''), 'attr') ?>">
                <input type="checkbox" name="media[]" value="<?= (int) $m['id'] ?>" form="f<?= (int) $d['id'] ?>"<?= $m['chosen'] ? ' checked' : '' ?><?= $editable ? '' : ' disabled' ?>>
                <?php if ($m['kind'] === 'image'): ?><img src="<?= site_url('collect/media/' . (int) $m['id']) ?>" alt="" loading="lazy"><?php else: ?><span class="dr-vid">영상</span><?php endif ?>
              </label>
            <?php endforeach ?>
          </div>
        <?php endif ?>
      </div>
      <form class="dr-edit" id="f<?= (int) $d['id'] ?>" method="post" action="<?= site_url('drafts/' . (int) $d['id'] . '/approve') ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="back" value="<?= esc($back, 'attr') ?>">
        <?php if (in_array($d['status'], ['gen', 'regen'], true)): ?>
          <div class="dr-wait">모델이 쓰는 중입니다...</div>
        <?php else: ?>
          <textarea class="input dr-text" name="text" rows="<?= $d['style'] === 'story' ? 2 : 7 ?>" placeholder="<?= $d['style'] === 'story' ? '헤드라인' : '본문' ?>"<?= $editable ? '' : ' readonly' ?>><?= esc((string) $d['text']) ?></textarea>
          <?php if ($d['style'] === 'story'): ?>
            <textarea class="input dr-text" name="body" rows="6" placeholder="본문 (헤드라인 아래 빈 줄 뒤에 붙습니다)"<?= $editable ? '' : ' readonly' ?>><?= esc((string) ($thread[0] ?? '')) ?></textarea>
          <?php endif ?>
          <div class="dr-count muted small"><span class="n"></span>자</div>
        <?php endif ?>
        <div class="dr-acts">
          <?php if ($editable): ?>
            <button class="btn sm" type="submit">승인</button>
            <button class="btn sm secondary" type="submit" formaction="<?= site_url('drafts/' . (int) $d['id'] . '/save') ?>">저장</button>
            <button class="btn sm ghost" type="submit" formaction="<?= site_url('drafts/' . (int) $d['id'] . '/regen') ?>" formnovalidate>다시 쓰기</button>
            <select class="input sm" name="style" title="다시 쓸 스타일">
              <?php foreach (D::STYLES as $k => $v): ?><option value="<?= $k ?>"<?= $k === $d['style'] ? ' selected' : '' ?>><?= $v ?></option><?php endforeach ?>
            </select>
            <?php if ($d['status'] !== 'rejected'): ?><button class="btn sm ghost danger-text" type="submit" formaction="<?= site_url('drafts/' . (int) $d['id'] . '/reject') ?>" formnovalidate>제외</button><?php endif ?>
          <?php elseif (in_array($d['status'], ['approved', 'error'], true)): ?>
            <button class="btn sm secondary" type="submit" formaction="<?= site_url('drafts/' . (int) $d['id'] . '/unapprove') ?>" formnovalidate>승인 취소</button>
          <?php elseif ($d['status'] === 'scheduled'): ?>
            <span class="muted small">X 예약 목록에서 고치거나 지울 수 있습니다.</span>
          <?php elseif ($d['status'] === 'posted' && $d['x_id']): ?>
            <a class="btn sm ghost" href="https://x.com/i/status/<?= esc($d['x_id'], 'attr') ?>" target="_blank" rel="noopener">게시물 보기</a>
          <?php endif ?>
        </div>
      </form>
    </article>
    <?php endforeach ?>
  </div>
  <?php endif ?>
</main>
<script>
(() => {
  // character count (X counts a CJK character as 2 of the 280 limit; a Premium account can go long)
  document.querySelectorAll('.dr-card').forEach((card) => {
    const tas = card.querySelectorAll('textarea'), out = card.querySelector('.dr-count .n');
    if (!tas.length || !out) return;
    const upd = () => { out.textContent = Array.from(tas).map((t) => t.value.trim()).filter(Boolean).join('\n\n').length; };
    tas.forEach((t) => t.addEventListener('input', upd)); upd();
  });
  // drafts still being written: poll until they change, then reload
  const waiting = Array.from(document.querySelectorAll('.dr-card[data-status="gen"], .dr-card[data-status="regen"]')).map((c) => c.dataset.id);
  if (!waiting.length) return;
  const tick = async () => {
    try {
      const r = await fetch('<?= site_url('drafts/status') ?>?ids=' + waiting.join(','), { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      const j = await r.json();
      if (waiting.some((id) => j[id] && j[id] !== 'gen' && j[id] !== 'regen')) { location.reload(); return; }
    } catch (e) { /* retry */ }
    setTimeout(tick, 8000);
  };
  setTimeout(tick, 8000);
})();
</script>
<?= $this->endSection() ?>
