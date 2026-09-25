<?php
$ext    = strtoupper(pathinfo($item['filename'], PATHINFO_EXTENSION));
$isVid  = $item['media_type'] === 'video';
$isImg  = $item['media_type'] === 'image';
$noX    = $isVid && $item['vcodec'] === 'av1';   // X rejects AV1 uploads
$fmts   = $isImg ? ['jpg' => 'JPG', 'png' => 'PNG', 'webp' => 'WebP'] : ['mp4' => 'MP4', 'webm' => 'WebM', 'gif' => 'GIF'];
$srcSum = $isImg
    ? array_filter([$ext, $item['width'] ? $item['width'] . '×' . $item['height'] : null])
    : array_filter([$ext, $item['vcodec'], $item['acodec'], $item['width'] ? $item['width'] . '×' . $item['height'] : null]);
?>
<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>
<main>
  <a class="back" href="<?= site_url('library') ?>">&lsaquo; 라이브러리</a>
  <?= view('partials/alerts') ?>
  <?php if ($pending): ?>
    <div class="alert" id="pendingBox" data-job="<?= (int) $pending['id'] ?>">
      <?= $pending['type'] === 'proxy' ? '편집용 미리보기를 만드는 중입니다' : '처리 중입니다' ?>
      <span id="pendingPct"><?= (int) $pending['progress'] ?>%</span> · 완료되면 자동으로 새로고침됩니다.
    </div>
  <?php endif ?>

  <div class="detail-head">
    <h1 id="mediaTitle" title="<?= esc($item['title'], 'attr') ?>"><?= esc($item['title']) ?></h1>
    <button class="iconbtn" type="button" id="btnRename" title="이름 변경" aria-label="이름 변경">&#9998;</button>
  </div>
  <div class="title-edit" id="titleEdit" hidden>
    <input class="input" id="titleInput" maxlength="255" value="<?= esc($item['title'], 'attr') ?>">
    <button class="btn sm" type="button" id="btnRenameSave">저장</button>
    <button class="btn sm secondary" type="button" id="btnRenameCancel">취소</button>
  </div>
  <p class="meta-line">
    <span><?= $isVid ? '영상' : ($isImg ? '이미지' : ucfirst($item['media_type'])) ?></span>
    <span class="dot">·</span><span><?= esc($item['source']) ?></span>
    <span class="dot">·</span><span><?= esc(date('Y년 n월 j일 H:i', strtotime($item['created_at']))) ?></span>
    <?php if (! empty($item['uploader'])): ?><span class="dot">·</span><span><?= esc($item['uploader']) ?></span><?php endif ?>
    <?php if ($item['source_url']): ?><span class="dot">·</span><a href="<?= esc($item['source_url'], 'attr') ?>" target="_blank" rel="noopener noreferrer">원본 링크</a><?php endif ?>
    <?php if ($item['parent_id']): ?><span class="dot">·</span><a href="<?= site_url('library/' . $item['parent_id']) ?>">원본 미디어</a><?php endif ?>
  </p>

  <div class="detail">
<?php
    $vw = (int) ($item['width'] ?: 16);
    $vh = (int) ($item['height'] ?: 9);
    $cls = $item['media_type'] === 'audio' ? ' audio' : ((! $isVid && ! $isImg && ! $item['has_thumb']) ? ' empty' : '');
?>
    <div class="detail-main" style="--arw:<?= $vw ?>;--arh:<?= $vh ?>">
    <figure class="viewer<?= $cls ?>">
      <?php if ($isVid && $playable): ?>
        <video controls playsinline preload="metadata" src="<?= site_url('media/' . $item['id'] . '/proxy') ?>" <?= $item['has_thumb'] ? 'poster="' . site_url('media/' . $item['id'] . '/thumb') . '"' : '' ?>></video>
      <?php elseif ($isImg): ?>
        <img src="<?= site_url('media/' . $item['id'] . '/file') ?>" alt="<?= esc($item['title'], 'attr') ?>">
      <?php elseif ($item['media_type'] === 'audio'): ?>
        <audio controls src="<?= site_url('media/' . $item['id'] . '/file') ?>"></audio>
      <?php elseif ($item['has_thumb']): ?>
        <img src="<?= site_url('media/' . $item['id'] . '/thumb') ?>" alt="">
      <?php else: ?>
        브라우저에서 바로 재생할 수 없는 형식입니다.<br>편집용 미리보기가 만들어지면 재생할 수 있습니다.
      <?php endif ?>
    </figure>

    <?php
    $stats = ! empty($item['stats']) ? (json_decode($item['stats'], true) ?: []) : [];
    $meta  = ! empty($item['meta']) ? (json_decode($item['meta'], true) ?: []) : [];
    $chan  = $meta['channel'] ?? $item['uploader'] ?? null;
    $dateKo = \App\Libraries\MediaSupport::uploadDateKo($stats['upload_date'] ?? null);
    $hasDesc = trim((string) $item['description']) !== '';
    ?>
    <?php if ($hasDesc || $stats || $chan): ?>
      <section class="desc">
        <?php if ($chan): ?>
          <div class="channel">
            <?php if (! empty($meta['avatar'])): ?>
              <img class="avatar" src="<?= esc($meta['avatar'], 'attr') ?>" alt="" referrerpolicy="no-referrer" loading="lazy">
            <?php else: ?>
              <span class="avatar mono"><?= esc(mb_substr($chan, 0, 1)) ?></span>
            <?php endif ?>
            <div class="who">
              <div class="name"><?= esc($chan) ?></div>
              <div class="sub">
                <?php if (! empty($meta['handle']) && str_starts_with((string) $meta['handle'], '@')): ?><?= esc($meta['handle']) ?><?php endif ?>
                <?php if (isset($meta['subscribers'])): ?>
                  <?= ! empty($meta['handle']) && str_starts_with((string) $meta['handle'], '@') ? ' · ' : '' ?>구독자 <?= esc(\App\Libraries\MediaSupport::countKo((int) $meta['subscribers'])) ?>명
                <?php endif ?>
              </div>
            </div>
            <?php if (! empty($meta['channel_url'])): ?>
              <a class="btn tinted sm" href="<?= esc($meta['channel_url'], 'attr') ?>" target="_blank" rel="noopener noreferrer">채널</a>
            <?php endif ?>
          </div>
        <?php endif ?>
        <?php if ($hasDesc || $stats): ?><p class="eyebrow">설명</p><?php endif ?>
        <?php if ($stats): ?>
          <div class="stats">
            <?php if (isset($stats['like_count'])): ?>
              <div class="stat"><b title="<?= number_format($stats['like_count']) ?>"><?= esc(\App\Libraries\MediaSupport::countKo((int) $stats['like_count'])) ?></b><span>좋아요</span></div>
            <?php endif ?>
            <?php if (isset($stats['view_count'])): ?>
              <div class="stat"><b title="<?= number_format($stats['view_count']) ?>"><?= esc(\App\Libraries\MediaSupport::countKo((int) $stats['view_count'])) ?></b><span>조회수</span></div>
            <?php endif ?>
            <?php if (isset($stats['comment_count'])): ?>
              <div class="stat"><b title="<?= number_format($stats['comment_count']) ?>"><?= esc(\App\Libraries\MediaSupport::countKo((int) $stats['comment_count'])) ?></b><span>댓글</span></div>
            <?php endif ?>
            <?php if (isset($stats['repost_count'])): ?>
              <div class="stat"><b title="<?= number_format($stats['repost_count']) ?>"><?= esc(\App\Libraries\MediaSupport::countKo((int) $stats['repost_count'])) ?></b><span>리포스트</span></div>
            <?php endif ?>
            <?php if (isset($stats['share_count'])): ?>
              <div class="stat"><b title="<?= number_format($stats['share_count']) ?>"><?= esc(\App\Libraries\MediaSupport::countKo((int) $stats['share_count'])) ?></b><span>공유</span></div>
            <?php endif ?>
            <?php if ($dateKo && preg_match('/^(\d+)년 (.+)$/u', $dateKo, $dm)): ?>
              <div class="stat"><b><?= esc($dm[2]) ?></b><span><?= esc($dm[1]) ?>년</span></div>
            <?php endif ?>
          </div>
        <?php endif ?>
        <?php if ($hasDesc): ?>
          <div class="desc-body" id="descBody"><?= \App\Libraries\MediaSupport::richText((string) $item['description'], (string) $item['source']) ?></div>
          <button class="btn ghost sm" type="button" id="descMore" hidden>더보기</button>
        <?php endif ?>
      </section>
    <?php endif ?>

    <?php $rest = array_values(array_filter($siblings ?? [], static fn ($s) => (int) $s['id'] !== (int) $item['id'])); ?>
    <?php if ($rest): ?>
      <section class="post-items">
        <p class="eyebrow">같은 게시물의 다른 리소스 <?= count($rest) ?>개</p>
        <?php foreach ($rest as $n => $s): ?>
          <?php $sVid = $s['media_type'] === 'video'; $sImg = $s['media_type'] === 'image'; ?>
          <article class="post-item">
            <figure class="viewer" style="--arw:<?= (int) ($s['width'] ?: 16) ?>;--arh:<?= (int) ($s['height'] ?: 9) ?>">
              <?php if ($sVid && ($s['has_proxy'] || \App\Libraries\MediaSupport::browserPlayable($s))): ?>
                <video controls playsinline preload="none" src="<?= site_url('media/' . $s['id'] . '/proxy') ?>" <?= $s['has_thumb'] ? 'poster="' . site_url('media/' . $s['id'] . '/thumb') . '"' : '' ?>></video>
              <?php elseif ($sImg): ?>
                <img src="<?= site_url('media/' . $s['id'] . '/file') ?>" alt="<?= esc($s['title'], 'attr') ?>" loading="lazy">
              <?php elseif ($s['has_thumb']): ?>
                <img src="<?= site_url('media/' . $s['id'] . '/thumb') ?>" alt="" loading="lazy">
              <?php endif ?>
            </figure>
            <div class="post-item-bar">
              <span class="muted small">
                <?= ($n + 2) ?>번째 · <?= $sVid ? '영상' : ($sImg ? '이미지' : esc($s['media_type'])) ?>
                <?= $s['width'] ? ' · ' . esc($s['width'] . '×' . $s['height']) : '' ?>
                · <?= esc(\App\Libraries\MediaSupport::size((int) $s['size'])) ?>
              </span>
              <span class="spacer"></span>
              <?php if ($sVid): ?><a class="btn sm secondary" href="<?= site_url('edit/' . $s['id']) ?>">편집</a><?php endif ?>
              <a class="btn sm ghost" href="<?= site_url('library/' . $s['id']) ?>">상세</a>
            </div>
          </article>
        <?php endforeach ?>
      </section>
    <?php endif ?>
    </div>

    <aside>
      <div class="aside-actions">
        <?php if ($isVid): ?>
          <a class="btn<?= $playable ? '' : ' disabled' ?>" href="<?= site_url('edit/' . $item['id']) ?>">편집</a>
        <?php endif ?>
        <a class="btn secondary" href="<?= site_url('media/' . $item['id'] . '/file?dl=1') ?>">원본 받기</a>
        <button class="btn secondary" type="button" id="btnSendX" title="X 새 글 작성 창에 본문과 파일을 올립니다">Send to X</button>
        <form method="post" action="<?= site_url('library/' . $item['id'] . '/delete') ?>" id="deleteForm">
          <?= csrf_field() ?>
          <button class="btn ghost" type="button" id="btnDelete">삭제</button>
        </form>
      </div>
      <?php if ($noX): ?>
        <p class="codec-warn">AV1 코덱 영상은 X(트위터)에 업로드할 수 없습니다. 아래 "변환해서 받기"에서 MP4(H.264)로 변환한 파일을 올려 주세요.</p>
      <?php endif ?>

      <div class="panel-block">
        <p class="eyebrow">정보</p>
        <dl class="list">
          <div class="row"><dt>파일</dt><dd><?= esc($item['filename']) ?></dd></div>
          <div class="row"><dt>형식</dt><dd><?= $isImg ? esc($ext) . ' <span class="muted">· ' . esc($item['mime']) . '</span>' : esc($item['container'] ?: $item['mime']) . ($item['has_proxy'] ? ' <span class="muted">· 프록시</span>' : '') ?></dd></div>
          <?php if (! $isImg && $item['vcodec']): ?><div class="row"><dt>비디오</dt><dd<?= $noX ? ' class="codec-bad"' : '' ?>><?= esc($item['vcodec']) ?><?= $item['fps'] ? ' · ' . esc(rtrim(rtrim($item['fps'], '0'), '.')) . ' fps' : '' ?></dd></div><?php endif ?>
          <?php if (! $isImg && $item['acodec']): ?><div class="row"><dt>오디오</dt><dd><?= esc($item['acodec']) ?></dd></div><?php endif ?>
          <?php if ($item['width']): ?><div class="row"><dt>해상도</dt><dd><?= esc($item['width'] . ' × ' . $item['height']) ?></dd></div><?php endif ?>
          <?php if ($item['duration']): ?><div class="row"><dt>길이</dt><dd><?= gmdate($item['duration'] >= 3600 ? 'G:i:s' : 'i:s', (int) $item['duration']) ?></dd></div><?php endif ?>
          <div class="row"><dt>크기</dt><dd><?= esc(\App\Libraries\MediaSupport::size((int) $item['size'])) ?></dd></div>
          <?php if (! empty($meta['source_res'])): ?><div class="row"><dt>원본 화질</dt><dd><?= esc(str_replace('x', ' × ', (string) $meta['source_res'])) ?><?= ! empty($meta['source_fps']) ? ' · ' . esc(rtrim(rtrim(number_format((float) $meta['source_fps'], 2), '0'), '.')) . ' fps' : '' ?><?= ! empty($meta['dynamic_range']) ? ' · ' . esc($meta['dynamic_range']) : '' ?></dd></div><?php endif ?>
          <?php if (! empty($meta['categories'])): ?><div class="row"><dt>카테고리</dt><dd><?= esc(implode(', ', $meta['categories'])) ?></dd></div><?php endif ?>
          <?php if (! empty($meta['language'])): ?><div class="row"><dt>언어</dt><dd><?= esc($meta['language']) ?></dd></div><?php endif ?>
          <?php if (! empty($meta['availability'])): ?><div class="row"><dt>공개 범위</dt><dd><?= esc($meta['availability']) ?><?= ! empty($meta['age_limit']) ? ' · ' . esc($meta['age_limit']) . '+' : '' ?></dd></div><?php endif ?>
          <?php if (! empty($meta['timestamp'])): ?><div class="row"><dt>게시</dt><dd><?= esc(date('Y-m-d H:i', (int) $meta['timestamp'])) ?></dd></div><?php endif ?>
        </dl>
        <?php if (! empty($meta['tags'])): ?>
          <div class="taglist">
            <?php foreach ($meta['tags'] as $t): ?><span class="chip"><?= esc($t) ?></span><?php endforeach ?>
          </div>
        <?php endif ?>
      </div>

      <div class="panel-block">
        <p class="eyebrow">변환해서 받기</p>
        <p class="small muted" style="margin-bottom:10px">원본 <?= esc(implode(' · ', $srcSum)) ?></p>
        <div class="dl-grid">
          <div class="segmented" id="dlFormat">
            <?php $first = true; foreach ($fmts as $v => $labelText): ?>
              <label><input type="radio" name="dlfmt" value="<?= $v ?>"<?= $first ? ' checked' : '' ?>><span><?= $labelText ?></span></label>
            <?php $first = false; endforeach ?>
          </div>
          <?php if ($isImg): ?>
            <div class="dl-field">
              <label for="dlLong">긴 방향 크기</label>
              <div class="dl-row">
                <input class="input" type="number" id="dlLong" min="16" max="20000" step="1"
                       placeholder="원본 <?= (int) max((int) $item['width'], (int) $item['height']) ?>px" inputmode="numeric">
                <span class="unit">px</span>
              </div>
              <p class="hint">비워 두면 원본 크기 그대로. 원본보다 크게는 늘리지 않습니다.</p>
            </div>
            <div class="dl-field" id="dlQualityBox">
              <label for="dlQuality">압축률 <b id="dlQualityVal">60%</b></label>
              <input type="range" id="dlQuality" min="10" max="100" step="5" value="60">
              <p class="hint">낮을수록 용량이 작고 화질이 떨어집니다. JPG에만 적용됩니다.</p>
            </div>
          <?php else: ?>
            <div class="dl-row">
              <select class="input" id="dlHeight">
                <option value="0">원본 해상도</option><option value="1080">1080p</option><option value="720">720p</option><option value="480">480p</option><option value="360">360p</option>
              </select>
              <select class="input" id="dlQuality"><option value="high">높은 품질</option><option value="medium">작은 용량</option></select>
            </div>
          <?php endif ?>
          <button class="btn block" type="button" id="btnConvert">변환 후 다운로드</button>
          <div class="dl-status" id="dlStatus" hidden></div>
        </div>
      </div>
    </aside>
  </div>
</main>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function () {
  const $ = (id) => document.getElementById(id);
  const csrf = MV.csrf();
  const id = <?= (int) $item['id'] ?>;
  const base = <?= json_encode(site_url('/')) ?>;
  const hdr = { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf ? csrf.hash : '' };

  /* rename */
  const head = document.querySelector('.detail-head'), edit = $('titleEdit');
  $('btnRename').addEventListener('click', () => { head.hidden = true; edit.hidden = false; $('titleInput').focus(); $('titleInput').select(); });
  $('btnRenameCancel').addEventListener('click', () => { head.hidden = false; edit.hidden = true; $('titleInput').value = $('mediaTitle').textContent.trim(); });
  $('titleInput').addEventListener('keydown', e => { if (e.key === 'Enter') save(); if (e.key === 'Escape') $('btnRenameCancel').click(); });
  $('btnRenameSave').addEventListener('click', save);
  /* ---------- Send to X ---------- */
  const xText = <?= json_encode(\App\Libraries\MediaSupport::shareText($item), JSON_UNESCAPED_UNICODE) ?>;
  $('btnSendX').addEventListener('click', async () => {
    const b = $('btnSendX'); b.disabled = true;
    try {
      // the extension announces itself on the page; without it, fall back to text-only intent
      if (document.documentElement.dataset.mvcutX !== '1') {
        window.open('https://x.com/intent/post?text=' + encodeURIComponent(xText), '_blank', 'noopener');
        location.href = base + 'media/' + id + '/file?dl=1';
        alert('X 확장이 설치되어 있지 않아 본문만 채운 작성 창을 열고 파일을 내려받습니다.\n작성 창에 파일을 끌어다 놓으세요.');
        return;
      }
      const res = await fetch(base + 'api/media/' + id + '/sharelink', { method: 'POST', headers: hdr });
      let j = {}; try { j = await res.json(); } catch (e) {}
      if (MV.expired(res.status, j)) throw new Error(MV.httpError(res.status));
      if (!res.ok || !j.ok) throw new Error(j.error || MV.httpError(res.status));
      window.postMessage({ source: 'mvcut', type: 'send-to-x', url: j.url, filename: j.filename,
                           mime: j.mime, text: xText }, location.origin);
    } catch (e) { alert(e.message); }
    setTimeout(() => { b.disabled = false; }, 1500);
  });

  async function save() {
    const title = $('titleInput').value.trim(); if (!title) return;
    try {
      const res = await fetch(base + 'api/media/' + id + '/rename', { method: 'POST', headers: hdr, body: JSON.stringify({ title }) });
      let j = {}; try { j = await res.json(); } catch (e) {}
      if (MV.expired(res.status, j)) throw new Error(MV.httpError(res.status));
      if (!res.ok || !j.ok) throw new Error(j.error || MV.httpError(res.status));
      $('mediaTitle').textContent = j.title; $('mediaTitle').title = j.title;
    } catch (e) { alert(e.message); }
    head.hidden = false; edit.hidden = true;
  }

  /* delete: two-step, no modal */
  const del = $('btnDelete');
  del.addEventListener('click', () => {
    if (del.dataset.armed) return $('deleteForm').submit();
    del.dataset.armed = '1'; del.textContent = '한 번 더 누르면 삭제'; del.classList.add('danger'); del.classList.remove('ghost');
    setTimeout(() => { delete del.dataset.armed; del.textContent = '삭제'; del.classList.remove('danger'); del.classList.add('ghost'); }, 4000);
  });

  /* convert */
  const isImage = <?= $isImg ? 'true' : 'false' ?>;
  if (isImage) {
    const q = $('dlQuality'), qv = $('dlQualityVal'), qbox = $('dlQualityBox');
    q.addEventListener('input', () => { qv.textContent = q.value + '%'; });
    const syncQuality = () => {
      qbox.hidden = document.querySelector('input[name=dlfmt]:checked').value !== 'jpg';
    };
    document.getElementById('dlFormat').addEventListener('change', syncQuality);
    syncQuality();
  }
  const st = $('dlStatus');
  const status = (html, cls) => { st.hidden = false; st.className = 'dl-status ' + (cls || ''); st.innerHTML = html; };
  $('btnConvert').addEventListener('click', async () => {
    const fmt = document.querySelector('input[name=dlfmt]:checked').value;
    status('요청하는 중…'); $('btnConvert').disabled = true;
    try {
      const body = isImage
        ? { format: fmt, long: +($('dlLong').value || 0), quality: +$('dlQuality').value }
        : { format: fmt, height: +$('dlHeight').value, quality: $('dlQuality').value };
      const res = await fetch(base + 'api/convert/' + id, { method: 'POST', headers: hdr, body: JSON.stringify(body) });
      let j = {}; try { j = await res.json(); } catch (e) {}
      if (MV.expired(res.status, j)) throw new Error(MV.httpError(res.status));
      if (!res.ok || !j.ok) throw new Error(j.error || MV.httpError(res.status));
      if (j.cached) return done(j.media.id);
      poll(j.job.id, (job) => done(job.result_media_id), (job) => status('변환 중 ' + job.progress + '%'));
    } catch (e) { status('실패: ' + e.message, 'error'); $('btnConvert').disabled = false; }
  });
  function done(mid) {
    status('<a class="btn sm" href="' + base + 'media/' + mid + '/file?dl=1">다운로드</a><a href="' + base + 'library/' + mid + '">라이브러리에서 보기</a>', 'ok');
    $('btnConvert').disabled = false;
  }
  function poll(jid, onDone, onTick) {
    setTimeout(async () => {
      try {
        const job = (await (await fetch(base + 'api/jobs/' + jid, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })).json()).job;
        if (job.status === 'done') return onDone(job);
        if (job.status === 'failed') { status('실패: ' + (job.error || ''), 'error'); $('btnConvert').disabled = false; return; }
        onTick(job); poll(jid, onDone, onTick);
      } catch (e) { status('실패: ' + e.message, 'error'); $('btnConvert').disabled = false; }
    }, 1000);
  }
  /* description: clamp long text behind 더보기 */
  const body = $('descBody'), more = $('descMore');
  if (body && more) {
    const collapsedMax = 168;
    if (body.scrollHeight > collapsedMax + 24) {
      body.classList.add('clamped'); more.hidden = false;
      more.addEventListener('click', () => {
        const open = body.classList.toggle('clamped');
        more.textContent = open ? '더보기' : '접기';
      });
    }
  }

  const pending = $('pendingBox');
  if (pending) poll(+pending.dataset.job, () => location.reload(), (job) => { $('pendingPct').textContent = job.progress + '%'; });
})();
</script>
<?= $this->endSection() ?>
