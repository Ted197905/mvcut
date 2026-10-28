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
$badge = [D::class, 'badge'];
$pending = (int) ($counts['new'] ?? 0) + (int) ($counts['error'] ?? 0) + (int) ($counts['failed'] ?? 0);
$writing = (int) ($counts['gen'] ?? 0) + (int) ($counts['regen'] ?? 0);
?>
<main class="lib-layout dr-page">
<div class="lib-col">
  <?= view('partials/alerts') ?>
  <h1>초안</h1>
  <p class="muted" style="margin:0 0 18px">매일 06시, 12시, 18시에 수집 글 100건씩으로 쓴 X 게시글 초안입니다. 본문을 고쳐 "저장"하거나 바로 "승인"하면 다음 날부터 아래 슬롯 방식대로 승인한 순서에 따라 슬롯에 들어가고, 확장이 X 예약 게시로 등록합니다. 승인하지 않은 글은 올라가지 않습니다.</p>

  <div class="alert error dr-ext" hidden></div>
  <form class="dr-mode" method="post" action="<?= site_url('drafts/mode') ?>"><?= csrf_field() ?>
    <input type="hidden" name="back" value="<?= esc($back, 'attr') ?>">
    <span class="small">등록 방식</span>
    <div class="seg">
      <button class="seg-item<?= $mode === 'semi' ? ' on' : '' ?>" name="mode" value="semi" type="submit">반자동</button>
      <button class="seg-item<?= $mode === 'auto' ? ' on' : '' ?>" name="mode" value="auto" type="submit">자동</button>
    </div>
    <span class="muted small"><?= $mode === 'auto'
        ? '승인한 초안을 확장이 승인 순서대로 10분에 1건씩 X 예약에 등록합니다. 실패하면 다시 줄을 서고 ' . D::MAX_TRIES . '번까지 재시도합니다 (크롬이 켜져 있어야 함).'
        : '승인하면 X 작성창에 본문, 사진, 예약 시각까지 넣어 엽니다. "예약하기"만 직접 누르면 카드가 "X 예약됨"으로 바뀝니다.' ?></span>
  </form>
  <form class="dr-mode" method="post" action="<?= site_url('drafts/slots') ?>"><?= csrf_field() ?>
    <input type="hidden" name="back" value="<?= esc($back, 'attr') ?>">
    <span class="small">슬롯</span>
    <div class="seg">
      <?php foreach (D::SLOT_TYPES as $k => $g): ?>
        <button class="seg-item<?= $slot === $k ? ' on' : '' ?>" name="type" value="<?= $k ?>" type="submit"><?= esc($g['label']) ?></button>
      <?php endforeach ?>
    </div>
    <span class="muted small">새로 승인하는 초안부터 적용됩니다.</span>
  </form>
  <form class="lib-toolbar" method="get" action="<?= site_url('drafts') ?>">
    <select class="input" name="batch" onchange="this.form.submit()">
      <?php foreach ($batches as $b): ?>
        <option value="<?= esc($b['batch'], 'attr') ?>"<?= $b['batch'] === $batch ? ' selected' : '' ?>><?= esc(D::batchLabel($b['batch'])) ?><?= ! empty($b['publish_from']) ? ' -> ' . XWatch::kst(strtotime($b['publish_from'] . ' UTC'), 'n/j') . ' 게시' : '' ?> (<?= (int) $b['made'] ?: '쓰는 중' ?>)</option>
      <?php endforeach ?>
      <?php if ($batches === []): ?><option value="">배치 없음</option><?php endif ?>
    </select>
    <div class="seg" role="tablist">
      <?php foreach ($filters as $k => $v): ?>
        <a class="seg-item<?= $filter === $k ? ' on' : '' ?>" href="<?= $qs(['f' => $k === 'new' ? '' : $k]) ?>"><?= $v ?><?php if ($k === 'new' && $pending): ?> <b><?= $pending ?></b><?php elseif ($k === 'done' && ($n = (int) ($counts['approved'] ?? 0) + (int) ($counts['sending'] ?? 0) + (int) ($counts['scheduled'] ?? 0) + (int) ($counts['posted'] ?? 0))): ?> <b><?= $n ?></b><?php endif ?></a>
      <?php endforeach ?>
    </div>
    <span class="spacer"></span>
    <span class="muted small dr-usage" title="슬롯: <?= esc(D::SLOT_TYPES[$slot]['label'], 'attr') ?>. 이미 예약된 글 앞뒤 15분 안의 슬롯은 쓰지 않습니다.">
      <?= esc($usage) ?>
    </span>
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
        <?php $rs = json_decode((string) ($d['research'] ?? ''), true) ?: []; if (array_filter($rs)): ?>
          <details class="dr-rs"><summary>조사 메모</summary>
            <?php if (! empty($rs['meaning'])): ?><p><b>글의 뜻</b> <?= esc($rs['meaning']) ?></p><?php endif ?>
            <?php if (! empty($rs['image_text'])): ?><p><b>사진 속 글자</b><br><?= nl2br(esc($rs['image_text'])) ?></p><?php endif ?>
            <?php if (! empty($rs['image_desc'])): ?><p><b>사진 내용</b> <?= esc($rs['image_desc']) ?></p><?php endif ?>
            <?php foreach ($rs['background'] ?? [] as $b): ?><p><b>위키백과 <?= esc($b['title']) ?></b> <?= esc($b['text']) ?></p><?php endforeach ?>
          </details>
        <?php endif ?>
        <?php if ($d['files'] || $editable): ?>
          <div class="dr-media" data-id="<?= (int) $d['id'] ?>">
            <?php foreach ($d['files'] as $m): ?>
              <div class="dr-m<?= $m['chosen'] ? ' on' : '' ?>" title="<?= esc(($m['kind'] === 'video' ? '영상' : '사진') . ' ' . ($m['width'] ? $m['width'] . 'x' . $m['height'] : '') . ' - 누르면 크게 보기', 'attr') ?>">
                <input type="checkbox" name="media[]" value="<?= (int) $m['id'] ?>" form="f<?= (int) $d['id'] ?>" title="게시물에 넣기"<?= $m['chosen'] ? ' checked' : '' ?><?= $editable ? '' : ' disabled' ?>>
                <a class="dr-view" href="<?= site_url('collect/media/' . (int) $m['id']) ?>" data-kind="<?= esc($m['kind'], 'attr') ?>">
                  <?php if ($m['kind'] === 'image'): ?><img src="<?= site_url('collect/media/' . (int) $m['id']) ?>" alt="" loading="lazy">
                  <?php else: ?><video src="<?= site_url('collect/media/' . (int) $m['id']) ?>#t=0.5" preload="metadata" muted playsinline></video><span class="dr-play">&#9654;</span><?php endif ?>
                </a>
              </div>
            <?php endforeach ?>
            <?php if ($editable): ?>
              <label class="dr-add" title="사진 추가 (여기로 끌어 놓아도 됩니다, 5MB까지)">
                <input type="file" accept="image/jpeg,image/png,image/webp,image/gif" multiple hidden>
                <span>+ 사진</span>
              </label>
            <?php endif ?>
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
          <div class="dr-count muted small"><span class="n"></span> / 280 <span class="fold"></span></div>
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
            <?php if ($mode === 'semi'): ?><button class="btn sm dr-fill" type="button" data-id="<?= (int) $d['id'] ?>">X 작성창 채우기</button><?php endif ?>
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
</div>
<?= view('xwatch/side', ['xw' => $xwatch]) ?>
</main>
<div class="dr-modal" id="drModal" hidden>
  <button class="dr-modal-x" type="button" aria-label="닫기">&times;</button>
  <div class="dr-modal-body"></div>
</div>
<script>
// phones and tablets have no extension: review and approve here, X scheduling happens from the PC's Chrome
const drMobile = /Mobi|Android|iPhone|iPad/i.test(navigator.userAgent) || (navigator.maxTouchPoints > 1 && /Mac/.test(navigator.platform));
(() => {
  // semi-automatic scheduling: X's composer filled with the draft (Send to X); the time is set by hand
  const fill = async (id) => {
    if (document.documentElement.dataset.mvcutX !== '1') { alert('MV Cut 확장이 필요합니다.'); return; }
    const c = MV.csrf();
    try {
      const r = await fetch('<?= site_url('drafts') ?>/' + id + '/xfill', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': c ? c.hash : '' } });
      const j = await r.json();
      if (!j.ok) throw new Error(j.error || MV.httpError(r.status));
      window.postMessage({ source: 'mvcut', type: 'send-to-x', url: '', text: j.text, files: j.files, note: j.note, at: j.ts }, location.origin);
    } catch (e) { alert('X 작성창 채우기 실패: ' + e.message); }
  };
  if (drMobile && document.documentElement.dataset.mvcutX !== '1') {
    document.querySelectorAll('.dr-fill').forEach((b) => { b.hidden = true; });
    return;
  }
  window.drFill = fill;
  document.addEventListener('click', (e) => { const b = e.target.closest('.dr-fill'); if (b) fill(b.dataset.id); });
  const auto = <?= json_encode((int) (session()->getFlashdata('fill') ?? 0)) ?>;
  if (auto) setTimeout(() => fill(auto), 600);
})();
(() => {
  // approved drafts reach X only through the extension (1.8.0+): say so when this browser does not have it
  setTimeout(() => {
    const v = document.documentElement.dataset.mvcutXVersion || '', box = document.querySelector('.dr-ext');
    const old = !v || v.split('.').map(Number).reduce((a, n, i) => a + n * [10000, 100, 1][i], 0) < 10805;
    if (!old) return;
    if (drMobile && !v) {
      box.className = 'muted small dr-ext dr-ext-mobile';
      box.textContent = <?= json_encode($mode === 'auto'
          ? '모바일: 검토, 수정, 승인은 여기서 하면 됩니다. X 예약 등록은 MV Cut 확장이 설치된 PC 크롬이 켜져 있을 때 확장이 10분에 1건씩 합니다.'
          : '모바일: 검토, 수정, 승인은 여기서 하면 됩니다. X 예약 등록은 PC 크롬에서 승인한 카드의 "X 작성창 채우기"를 누르고 "예약하기"만 누르면 됩니다.', JSON_UNESCAPED_UNICODE) ?>;
      box.hidden = false;
      return;
    }
    box.textContent = (v ? 'MV Cut 확장 ' + v + '는' : '이 브라우저에 MV Cut 확장이 없거나 오래된 버전이라') + ' 승인한 초안을 X 예약으로 등록하지 못합니다. '
      + 'chrome://extensions 에서 MV Cut 확장을 새로고침(1.8.5 이상)하세요. 확장은 x.com에 감시 계정으로 로그인된 크롬에서 2분마다 등록합니다.';
    box.hidden = false;
  }, 800);
})();
(() => {
  // approve / reject in place: the card turns blue (approved) or grey (excluded); the list is sorted again on reload
  document.addEventListener('submit', async (e) => {
    const f = e.target.closest('form.dr-edit'), btn = e.submitter;
    if (!f || !btn) return;
    const fa = btn.getAttribute('formaction') || '';
    const kind = !fa ? 'approve' : (fa.endsWith('/reject') ? 'reject' : '');
    if (!kind) return;   // save, rewrite, unapprove: as before (page reload)
    e.preventDefault();
    const card = f.closest('.dr-card'), acts = f.querySelector('.dr-acts');
    acts.querySelectorAll('button, select').forEach((b) => { b.disabled = true; });
    try {
      const r = await fetch(fa || f.action, { method: 'POST', body: new FormData(f), headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      const j = await r.json();
      if (!j.ok) throw new Error(j.error || MV.httpError(r.status));
      card.dataset.status = kind === 'approve' ? 'approved' : 'rejected';
      card.classList.add(kind === 'approve' ? 'dr-ok' : 'dr-out');
      const b = card.querySelector('.dr-src-head .ck-badge');
      b.textContent = j.label; b.className = 'ck-badge ' + (kind === 'approve' ? 'warn' : 'bad');
      f.querySelectorAll('textarea').forEach((t) => { t.readOnly = true; });
      card.querySelectorAll('.dr-m input').forEach((i) => { i.disabled = true; });
      card.querySelectorAll('.dr-add').forEach((x) => x.remove());
      acts.innerHTML = '<span class="muted small"></span>';
      acts.firstChild.textContent = kind === 'approve' ? j.msg : '제외했습니다. 새로고침하면 목록에서 빠집니다.';
      if (j.fill && window.drFill && !drMobile) window.drFill(j.fill);
    } catch (err) {
      acts.querySelectorAll('button, select').forEach((b) => { b.disabled = false; });
      alert((kind === 'approve' ? '승인' : '제외') + ' 실패: ' + err.message);
    }
  });
})();
(() => {
  // photos added by hand: picked with "+ 사진" or dropped on the photo row; added ones are chosen while there is room
  const upload = async (box, files) => {
    const imgs = Array.from(files).filter((f) => f.type.startsWith('image/'));
    if (!imgs.length) return;
    const id = box.dataset.id, add = box.querySelector('.dr-add'), c = MV.csrf(), fd = new FormData();
    imgs.forEach((f) => fd.append('photo[]', f));
    if (c) fd.append(c.name, c.hash);
    add.classList.add('busy');
    try {
      const r = await fetch('<?= site_url('drafts') ?>/' + id + '/photos', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      const j = await r.json().catch(() => ({ ok: false, error: MV.httpError(r.status) }));
      if (!j.ok) throw new Error(j.error);
      for (const it of j.items) {
        if (box.querySelector('input[value="' + it.id + '"]')) continue;
        const el = document.createElement('div');
        el.className = 'dr-m' + (it.chosen ? ' on' : '');
        el.innerHTML = '<input type="checkbox" name="media[]" title="게시물에 넣기"><a class="dr-view" data-kind="image"><img alt=""></a>';
        const cb = el.querySelector('input');
        cb.value = it.id; cb.setAttribute('form', 'f' + id); cb.checked = it.chosen;
        el.querySelector('a').href = it.url; el.querySelector('img').src = it.url;
        box.insertBefore(el, add);
      }
    } catch (e) { alert('사진 추가 실패: ' + e.message); }
    add.classList.remove('busy');
  };
  document.addEventListener('change', (e) => {
    const inp = e.target.closest('.dr-add input[type=file]');
    if (inp) { upload(inp.closest('.dr-media'), inp.files); inp.value = ''; }
  });
  document.addEventListener('dragover', (e) => { const box = e.target.closest('.dr-media'); if (box && box.querySelector('.dr-add')) { e.preventDefault(); box.classList.add('drop'); } });
  document.addEventListener('dragleave', (e) => { const box = e.target.closest('.dr-media'); if (box) box.classList.remove('drop'); });
  document.addEventListener('drop', (e) => {
    const box = e.target.closest('.dr-media');
    if (!box || !box.querySelector('.dr-add')) return;
    e.preventDefault(); box.classList.remove('drop'); upload(box, e.dataTransfer.files);
  });
})();
(() => {
  // photo / video viewer: a layer over the page, closed by the backdrop, the X or Esc
  const modal = document.getElementById('drModal'), body = modal.querySelector('.dr-modal-body');
  const close = () => { modal.hidden = true; body.innerHTML = ''; document.body.style.overflow = ''; };
  document.addEventListener('click', (e) => {
    const a = e.target.closest('a.dr-view');
    if (!a) return;
    e.preventDefault();
    body.innerHTML = a.dataset.kind === 'video'
      ? '<video src="' + a.href + '" controls autoplay playsinline></video>'
      : '<img src="' + a.href + '" alt="">';
    modal.hidden = false;
    document.body.style.overflow = 'hidden';
    const v = body.querySelector('video');
    if (v) v.play().catch(() => { v.muted = true; v.play().catch(() => {}); });   // browsers may refuse sound without a gesture
  });
  modal.addEventListener('click', (e) => { if (e.target === modal || e.target.closest('.dr-modal-x')) close(); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !modal.hidden) close(); });
})();
(() => {
  // X's weighted length (twitter-text v3): Latin/digits/punctuation 1, Hangul/CJK/emoji 2, a URL 23.
  // Over 280 the timeline folds the post behind "더 보기"; a Premium account can post up to 25,000.
  const xWeight = (s) => {
    let n = 0;
    for (const ch of s.replace(/https?:\/\/\S+/g, 'x'.repeat(23))) {
      const c = ch.codePointAt(0);
      n += (c <= 0x10FF || (c >= 0x2000 && c <= 0x200D) || (c >= 0x2010 && c <= 0x201F) || (c >= 0x2032 && c <= 0x2037)) ? 1 : 2;
    }
    return n;
  };
  document.querySelectorAll('.dr-card').forEach((card) => {
    const tas = card.querySelectorAll('textarea'), out = card.querySelector('.dr-count .n'), fold = card.querySelector('.dr-count .fold');
    if (!tas.length || !out) return;
    const upd = () => {
      const w = xWeight(Array.from(tas).map((t) => t.value.trim()).filter(Boolean).join('\n\n'));
      out.textContent = w;
      fold.textContent = w > 25000 ? '(25,000 초과: 게시 불가)' : w > 280 ? '(280 넘는 부분은 "더 보기"로 접힘)' : '';
      card.querySelector('.dr-count').classList.toggle('over', w > 280);
    };
    tas.forEach((t) => t.addEventListener('input', upd)); upd();
  });
  // every 5 s: the cards' states (approved -> X 예약됨 -> 게시됨) and the slot line, updated in place.
  // A draft that finished being written reloads the page, unless a text box was edited and not saved.
  const cards = Array.from(document.querySelectorAll('.dr-card'));
  const usage = document.querySelector('.dr-usage');
  const dirty = () => Array.from(document.querySelectorAll('.dr-card textarea')).some((t) => t.value !== t.defaultValue);
  const tick = async () => {
    if (document.hidden) return;
    try {
      const r = await fetch('<?= site_url('drafts/status') ?>?batch=<?= urlencode($batch) ?>&ids=' + cards.map((c) => c.dataset.id).join(','), { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      const j = await r.json();
      if (usage && j.usage) usage.textContent = j.usage;
      let reload = false;
      for (const c of cards) {
        const it = j.items[c.dataset.id];
        if (!it || it.status === c.dataset.status) continue;
        if (c.dataset.status === 'gen' || c.dataset.status === 'regen') reload = true;
        c.dataset.status = it.status;
        const b = c.querySelector('.dr-src-head .ck-badge');
        b.textContent = it.label; b.className = 'ck-badge ' + it.cls;
        const err = c.querySelector('.dr-err');
        if (err) err.textContent = it.error;
      }
      if (reload && !dirty()) location.reload();
    } catch (e) { /* next tick */ }
  };
  setInterval(tick, 5000);
})();
// X watch sidebar, as on the library page: swapped only when its content changed
(function () {
  let busy = false, last = '';
  setInterval(() => {
    const side = document.getElementById('xwSide');
    if (!side || document.hidden || busy) return;
    busy = true;
    fetch('<?= site_url('xwatch/panel') ?>', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then((r) => r.ok ? r.text() : null)
      .then((h) => {
        if (!h || !h.trim() || h === last) return;
        last = h;
        const cur = document.getElementById('xwSide');
        const top = cur.querySelector('.xw-box.xw-list')?.scrollTop || 0;
        cur.outerHTML = h;
        const list = document.querySelector('#xwSide .xw-box.xw-list');
        if (list) list.scrollTop = top;
      })
      .catch(() => {})
      .finally(() => { busy = false; });
  }, 3000);
})();
</script>
<?= $this->endSection() ?>
