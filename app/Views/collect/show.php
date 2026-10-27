<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>
<?php
use App\Controllers\Collect;

$num = static fn ($v) => number_format((int) $v);
$sendable = static fn (array $m) => ($m['status'] === 'done' && in_array($m['kind'], ['image', 'video'], true))
    || ($m['status'] === 'embed' && $m['kind'] === 'youtube');
$ytId = static fn (string $u) => preg_match('/[?&]v=([\w-]{11})/', $u, $mm) ? $mm[1] : null;
$stateLabel = ['pending' => '받는 중', 'failed' => '실패', 'robots' => 'robots 불허', 'too_big' => '용량 초과'];
$picks = array_filter($media, $sendable);
$xable = static fn (array $m) => $m['status'] === 'done' && in_array($m['kind'], ['image', 'video'], true);
$xPicks = array_filter($media, $xable);
$orig = trim((string) ($item['body'] ?: $item['excerpt']));
$tr   = (string) $item['title_ko'] !== '';   // translated by the collector (foreign-language post)
$body = $tr ? trim((string) $item['body_ko']) : $orig;
?>
<main class="yt-page cl-page">
  <?= view('partials/alerts') ?>
  <p class="meta-line" style="margin-bottom:14px"><a href="<?= site_url('collect') ?>">&larr; 목록</a></p>
  <div class="cl-detail">
    <div class="cl-main">
      <h1 class="cl-title"><?= esc($tr ? $item['title_ko'] : $item['title']) ?></h1>
      <p class="meta-line">
        <span><?= esc($src['name']) ?></span><span class="dot">·</span>
        <span><?= esc(Collect::COUNTRIES[$item['country']] ?? $item['country']) ?></span><span class="dot">·</span>
        <span><?= esc(Collect::when($item['posted_at'] ?: $item['first_seen'])) ?></span>
        <?php if ($item['author']): ?><span class="dot">·</span><span><?= esc($item['author']) ?></span><?php endif ?>
        <span class="dot">·</span><a href="<?= esc($item['url'], 'attr') ?>" target="_blank" rel="noopener noreferrer">원문 보기</a>
      </p>

      <?php if ($body !== '' || $tr): ?>
        <div class="desc-body cl-body" id="descBody"><?= nl2br(esc(mb_substr($body, 0, 20000))) ?><?php if ($tr): ?><div class="cl-orig"><span class="muted small">원문<?= $item['tr_at'] ? ' · ' . esc(\App\Controllers\Collect::when($item['tr_at'])) . ' 번역' : '' ?></span><b><?= esc($item['title']) ?></b><?= $orig !== '' ? '<br>' . nl2br(esc(mb_substr($orig, 0, 20000))) : '' ?></div><?php endif ?></div>
        <button class="btn ghost sm" type="button" id="descMore" hidden>더보기</button>
      <?php elseif ($item['detail_status'] === 'pending'): ?>
        <p class="muted">본문과 미디어를 받는 중입니다.</p>
      <?php elseif ($item['detail_status'] === 'failed'): ?>
        <div class="alert error">본문을 받지 못했습니다<?= $item['detail_error'] ? ': ' . esc($item['detail_error']) : '' ?></div>
      <?php endif ?>

      <?php if ($media !== []): ?>
        <form method="post" action="<?= site_url('collect/' . $item['id'] . '/library') ?>" id="libForm">
          <?= csrf_field() ?>
          <div class="cl-media">
            <?php foreach ($media as $m): $ok = $sendable($m); $embed = $m['kind'] === 'youtube' && $ytId($m['src_url']); ?>
              <div class="post-item<?= $embed ? ' embed' : '' ?>">
                <?php if ($m['status'] === 'done' && $m['kind'] === 'image'): ?>
                  <div class="viewer"><img src="<?= site_url('collect/media/' . $m['id']) ?>" alt="" loading="lazy"></div>
                <?php elseif ($m['status'] === 'done' && $m['kind'] === 'video'): ?>
                  <div class="viewer"><video controls playsinline preload="metadata" src="<?= site_url('collect/media/' . $m['id']) ?>"></video></div>
                <?php elseif ($m['kind'] === 'youtube' && ($vid = $ytId($m['src_url']))): ?>
                  <div class="viewer"><iframe src="https://www.youtube-nocookie.com/embed/<?= esc($vid, 'attr') ?>" allowfullscreen loading="lazy" title="YouTube"></iframe></div>
                <?php else: ?>
                  <div class="viewer cl-missing"><span><?= esc($stateLabel[$m['status']] ?? $m['status']) ?></span></div>
                <?php endif ?>
                <div class="post-item-bar">
                  <?php if ($ok): ?><label class="cl-check"><input type="checkbox" name="media[]" value="<?= (int) $m['id'] ?>" checked> 선택</label><?php endif ?>
                  <?php if ($xable($m)): ?><label class="cl-check cl-x"><input type="checkbox" class="xpick" value="<?= (int) $m['id'] ?>" data-kind="<?= esc($m['kind'], 'attr') ?>" data-mime="<?= esc((string) $m['mime'], 'attr') ?>"> X</label><?php endif ?>
                  <span class="muted small"><?= $m['kind'] === 'youtube' ? 'YouTube' : ($m['kind'] === 'video' ? '영상' : '사진') ?><?= $m['bytes'] ? ' · ' . esc(\App\Libraries\MediaSupport::size((int) $m['bytes'])) : '' ?><?= $m['width'] ? ' · ' . (int) $m['width'] . '×' . (int) $m['height'] : '' ?></span>
                  <span class="spacer"></span>
                  <a class="small" href="<?= esc($m['src_url'], 'attr') ?>" target="_blank" rel="noopener noreferrer">원본</a>
                </div>
              </div>
            <?php endforeach ?>
          </div>
        </form>
      <?php endif ?>
    </div>

    <aside class="cl-side">
      <div class="stats">
        <?php foreach (['views' => '조회', 'comments' => '댓글', 'likes' => '추천', 'rank_pos' => '순위', 'heat' => '열기'] as $k => $v): if ($item[$k] === null) continue; ?>
          <div class="stat"><b><?= $num($item[$k]) ?></b><span><?= $v ?></span></div>
        <?php endforeach ?>
      </div>
      <?php if ($picks !== []): ?>
        <button class="btn block" type="submit" form="libForm">선택 항목 라이브러리로 보내기</button>
        <p class="muted small" style="margin:8px 0 0">사진·영상은 바로 복사하고, YouTube는 가져오기 작업으로 넣습니다.<?= $inLib ? ' 이미 라이브러리에 이 글의 파일이 ' . $inLib . '개 있습니다.' : '' ?></p>
      <?php endif ?>
      <div class="cl-side-acts">
        <form method="post" action="<?= site_url('collect/' . $item['id'] . '/used') ?>"><?= csrf_field() ?>
          <input type="hidden" name="on" value="<?= $item['used_at'] ? '0' : '1' ?>">
          <button class="btn sm<?= $item['used_at'] ? '' : ' secondary' ?>" type="submit"><?= $item['used_at'] ? '&#10003; 사용함 (' . esc(Collect::when($item['used_at'])) . ')' : '사용한 글로 표시' ?></button>
        </form>
        <form method="post" action="<?= site_url('collect/' . $item['id'] . '/delete') ?>" onsubmit="return confirm('이 글을 수집 목록에서 지웁니다. 계속할까요?')"><?= csrf_field() ?>
          <button class="btn sm ghost" type="submit">지우기</button>
        </form>
      </div>
      <?php if ($xPicks !== []): ?>
        <button class="btn block secondary" type="button" id="btnSendX" style="margin-top:14px" disabled>Send to X <span id="xCount">(0/4)</span></button>
        <p class="muted small" style="margin:8px 0 0">사진 아래 "X"로 고르세요. 사진은 4장까지, 영상은 1개만(사진과 함께 불가).</p>
      <?php endif ?>
      <dl class="list cl-facts">
        <div class="row"><dt>분야</dt><dd><?= esc($item['domain'] ?? '-') ?></dd></div>
        <div class="row"><dt>이벤트</dt><dd><?= esc($item['event'] ?? '-') ?><?= (int) $item['breaking'] ? ' (속보 후보)' : '' ?></dd></div>
        <?php if ($item['entities']): ?><div class="row"><dt>그룹/인물</dt><dd><?= esc($item['entities']) ?></dd></div><?php endif ?>
        <?php if ($item['category'] || $item['board']): ?><div class="row"><dt>게시판</dt><dd><?= esc(trim($item['board'] . ' ' . $item['category'])) ?></dd></div><?php endif ?>
        <?php if ($item['tags']): ?><div class="row"><dt>태그</dt><dd><?= esc($item['tags']) ?></dd></div><?php endif ?>
        <?php if ((int) $item['relevant'] && $item['news'] !== null && ! (int) $item['news']): ?><div class="row"><dt>숨김</dt><dd><?= esc(['no media in a share board' => '공유 글인데 미디어 없음', 'chat prefix' => '잡담 글', 'content share without media' => '영상/사진 공유인데 미디어 없음', 'question' => '질문 글'][$item['news_why']] ?? (string) $item['news_why']) ?></dd></div><?php endif ?>
        <?php if ($item['lang'] !== 'ko' && ! $tr): ?><div class="row"><dt>번역</dt><dd><?= $item['tr_error'] ? '실패: ' . esc(mb_substr($item['tr_error'], 0, 40)) : ((int) $item['relevant'] && ($item['news'] === null || (int) $item['news']) ? '대기 중' : '숨긴 글은 번역 안 함') ?></dd></div><?php endif ?>
        <div class="row"><dt>처음 수집</dt><dd><?= esc(Collect::when($item['first_seen'])) ?></dd></div>
        <div class="row"><dt>마지막 확인</dt><dd><?= esc(Collect::when($item['last_seen'])) ?></dd></div>
      </dl>
    </aside>
  </div>
</main>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?>
<script>
/* Send to X: up to 4 photos or one video into X's compose window (extension), or the phone share sheet */
(function () {
  const btn = document.getElementById('btnSendX'); if (!btn) return;
  const picks = [...document.querySelectorAll('.xpick')];
  const count = document.getElementById('xCount');
  const chosen = () => picks.filter(p => p.checked);
  const paint = () => {
    const c = chosen(), vid = c.some(p => p.dataset.kind === 'video');
    count.textContent = vid ? '(영상 1)' : '(' + c.length + '/4)';
    btn.disabled = !c.length;
    // a full set (4 photos or 1 video) locks the rest
    picks.forEach(p => { p.disabled = !p.checked && (vid || c.length >= 4); p.closest('label').classList.toggle('off', p.disabled); });
  };
  picks.forEach(p => p.addEventListener('change', () => {
    if (p.checked && p.dataset.kind === 'video') picks.forEach(o => { if (o !== p) o.checked = false; });
    paint();
  }));
  paint();

  const base = <?= json_encode(rtrim(site_url(), '/') . '/') ?>, itemId = <?= (int) $item['id'] ?>;
  const csrf = MV.csrf();
  const probe = () => { try { return new File([''], 'a.jpg', { type: 'image/jpeg' }); } catch (e) { return null; } };
  const canShareFiles = !!(navigator.share && navigator.canShare && probe() && navigator.canShare({ files: [probe()] }));
  let shareFiles = null, shareText = '';
  const label = () => { btn.firstChild.textContent = 'Send to X '; };

  btn.addEventListener('click', async () => {
    const ids = chosen().map(p => +p.value); if (!ids.length) return;
    const ext = document.documentElement.dataset.mvcutX === '1';
    btn.disabled = true;
    try {
      if (!ext && canShareFiles) {
        if (!shareFiles) {
          btn.firstChild.textContent = '파일 준비 중... ';
          const res = await fetch(base + 'collect/' + itemId + '/xlinks', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf ? csrf.hash : '' }, body: JSON.stringify({ media: ids }) });
          const j = await res.json().catch(() => ({}));
          if (!res.ok || !j.ok) throw new Error(j.error || MV.httpError(res.status));
          shareText = j.text;
          if (navigator.clipboard) navigator.clipboard.writeText(shareText).catch(() => {});
          shareFiles = await Promise.all(ids.map(async (mid, i) => {
            const r = await fetch(base + 'collect/media/' + mid, { credentials: 'same-origin' });
            if (!r.ok) throw new Error(MV.httpError(r.status));
            const b = await r.blob();
            return new File([b], j.files[i].filename, { type: b.type || j.files[i].mime });
          }));
        }
        await navigator.share({ files: shareFiles, text: shareText });
        shareFiles = null; label();
        return;
      }
      if (!ext) { alert('MV Cut 확장이 있는 PC 크롬이나 휴대폰에서 보낼 수 있습니다.'); return; }
      if (ids.length > 1 && document.documentElement.dataset.mvcutXMulti !== '1') {
        alert('여러 장 보내기는 확장 1.7.7 이상이 필요합니다. chrome://extensions에서 MV Cut 확장을 새로고침하세요.'); return;
      }
      const res = await fetch(base + 'collect/' + itemId + '/xlinks', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf ? csrf.hash : '' }, body: JSON.stringify({ media: ids }) });
      const j = await res.json().catch(() => ({}));
      if (MV.expired(res.status, j)) return;
      if (!res.ok || !j.ok) throw new Error(j.error || MV.httpError(res.status));
      window.postMessage({ source: 'mvcut', type: 'send-to-x', url: j.files[0].url, filename: j.files[0].filename, mime: j.files[0].mime,
                           files: j.files, text: j.text }, location.origin);
    } catch (e) {
      if (e && e.name === 'AbortError') { shareFiles = null; label(); return; }
      if (shareFiles && e && e.name === 'NotAllowedError') { btn.firstChild.textContent = 'X로 공유 (한 번 더 누르세요) '; return; }
      shareFiles = null; label();
      alert(e.message || e);
    } finally {
      setTimeout(paint, 1500);
    }
  });
})();

/* long bodies (forum threads) are clamped behind 더보기 so the media stays in view */
(function () {
  const body = document.getElementById('descBody'), more = document.getElementById('descMore');
  if (!body || !more || body.scrollHeight <= 168 + 24) return;
  body.classList.add('clamped'); more.hidden = false;
  more.addEventListener('click', () => { more.textContent = body.classList.toggle('clamped') ? '더보기' : '접기'; });
})();
</script>
<?= $this->endSection() ?>
