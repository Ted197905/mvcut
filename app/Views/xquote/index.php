<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>
<?php
$num = static fn ($n) => $n === null ? '-' : ($n >= 10000 ? number_format($n / 10000, 1) . '만' : number_format((int) $n));
$ago = static fn (int $m) => $m < 60 ? $m . '분' : intdiv($m, 60) . '시간 ' . ($m % 60) . '분';
$kst = static fn (?string $utc) => $utc ? \App\Libraries\XWatch::kst(strtotime($utc . ' UTC'), 'H:i') : '-';
?>
<main class="wide settings xq">
  <h1>X 인용 대상</h1>
  <p class="muted small" style="margin-top:-6px">게시 후 2시간이 안 됐는데 조회수 10만을 넘은 게시물입니다. 두 경로로 모읍니다: 수집기가 30분마다 부계정으로 X를 검색(한국어, 좋아요 500 이상, 08시~24시)하고, 본계정으로 X를 보는 동안 화면에 로드된 글을 확장이 보냅니다. 아이돌/기획사 등 공식 계정(금색 인증, 소속 배지, 아이돌 계정 목록)은 제외합니다. 조회수는 마지막으로 확인한 시점의 값입니다.</p>

  <section class="set-card">
    <div class="cl-log-bar">
      <form method="post" action="<?= site_url('xquote/refresh') ?>" style="display:inline"><?= csrf_field() ?><button class="btn sm" type="submit">지금 조회</button></form>
      <span class="muted small">
        마지막 검색 <?= esc($kst($src['last_run_at'] ?? null)) ?><?= $src && $src['last_status'] === 'error' ? ' (실패: ' . esc(mb_substr((string) $src['last_error'], 0, 80)) . ')' : ($src ? ' · ' . (int) $src['last_items'] . '건' : '') ?>
        · 다음 <?= esc($kst($src['next_run_at'] ?? null)) ?>
        <?= $on ? '' : ' · <b>꺼짐</b> (설정 > 자동 기능 > X 인용 대상)' ?>
      </span>
      <span class="spacer"></span>
      <span class="seg">
        <?php foreach ([2 => '2시간', 3 => '3시간', 6 => '6시간', 24 => '24시간'] as $h => $label): ?>
          <a class="seg-item<?= $hours === $h ? ' on' : '' ?>" href="<?= site_url('xquote') . '?h=' . $h ?>" title="게시된 지 <?= $label ?> 안 된 글까지 표시">~<?= $label ?></a>
        <?php endforeach ?>
      </span>
    </div>

    <?php if (! $rows): ?>
      <p class="muted" style="margin:18px 0 6px">지금은 조건에 맞는 게시물이 없습니다.</p>
    <?php endif ?>
    <?php foreach ($rows as $r): ?>
      <div class="xq-row<?= $r['used_at'] ? ' used' : '' ?>" data-key="<?= esc($r['key'], 'attr') ?>">
        <?php if ($r['thumb_url']): ?><img class="xq-thumb" src="<?= esc($r['thumb_url'], 'attr') ?>" alt="" loading="lazy" referrerpolicy="no-referrer"><?php else: ?><span class="xq-thumb"></span><?php endif ?>
        <div class="xq-main">
          <div class="xq-meta">
            <b><?= esc($num((int) $r['views'])) ?> 조회</b>
            <span class="ck-badge <?= $r['age_min'] <= 120 ? 'ok' : '' ?>">게시 <?= esc($ago($r['age_min'])) ?> 전</span>
            <span class="muted small">시간당 <?= esc($num($r['per_hour'])) ?> · 좋아요 <?= esc($num($r['likes'])) ?> · 답글 <?= esc($num($r['comments'])) ?> · <?= esc((string) $r['tags']) ?> · <?= (int) $r['seen_min'] ?>분 전 확인 · <?= esc($r['from']) ?></span>
          </div>
          <div class="xq-text"><span class="muted"><?= esc((string) $r['author']) ?></span> <?= esc(mb_substr((string) $r['text'], 0, 220)) ?></div>
        </div>
        <div class="xq-acts">
          <a class="btn sm" href="https://x.com/intent/post?url=<?= rawurlencode((string) $r['url']) ?>" target="_blank" rel="noopener" data-quote title="X 작성창을 이 글을 인용한 상태로 엽니다">인용하기</a>
          <a class="btn sm secondary" href="<?= esc($r['url'], 'attr') ?>" target="_blank" rel="noopener">원문</a>
          <label class="small muted"><input type="checkbox" data-used<?= $r['used_at'] ? ' checked' : '' ?>> 인용함</label>
        </div>
      </div>
    <?php endforeach ?>
  </section>
</main>
<script>
(() => {
  const mark = async (row, on) => {
    const c = MV.csrf(), b = new FormData();
    b.append('on', on ? '1' : '0'); b.append('key', row.dataset.key); if (c) b.append(c.name, c.hash);
    row.classList.toggle('used', on);
    row.querySelector('[data-used]').checked = on;
    try { await fetch('<?= site_url('xquote/used') ?>', { method: 'POST', body: b, headers: { 'X-Requested-With': 'XMLHttpRequest' } }); } catch (e) { /* shown again on reload */ }
  };
  document.addEventListener('change', (e) => { if (e.target.matches('[data-used]')) mark(e.target.closest('.xq-row'), e.target.checked); });
  document.addEventListener('click', (e) => { const a = e.target.closest('a[data-quote]'); if (a) mark(a.closest('.xq-row'), true); });
})();
</script>
<?= $this->endSection() ?>
