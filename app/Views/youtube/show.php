<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>
<?php
include __DIR__ . '/_status.php';
[$label, $cls] = $ytStatus($item['status']);
$busy = in_array($item['status'], ['queued', 'downloading', 'analyzing'], true);
$base = site_url('youtube/' . $item['id']);
$dur  = $item['duration'] ? gmdate($item['duration'] >= 3600 ? 'G:i:s' : 'i:s', (int) $item['duration']) : '';
?>
<main class="yt-page">
  <?= view('partials/alerts') ?>
  <p class="meta-line" style="margin-bottom:14px"><a href="<?= site_url('youtube') ?>">&larr; 목록</a></p>
  <div class="detail yt-detail" data-busy="<?= $busy ? '1' : '0' ?>" data-status="<?= $base ?>/status">
    <div class="detail-main" style="--arw:<?= (int) ($item['width'] ?: 16) ?>;--arh:<?= (int) ($item['height'] ?: 9) ?>">
      <div class="detail-head" style="align-self:stretch"><h1><?= esc($item['title'] ?: $item['video_id']) ?></h1></div>
      <p class="meta-line" style="align-self:stretch">
        <?php if ($item['channel'] !== ''): ?><span><?= esc($item['channel']) ?></span><span class="dot">·</span><?php endif ?>
        <?php if ($item['uploaded_at']): ?><span><?= esc($item['uploaded_at']) ?></span><span class="dot">·</span><?php endif ?>
        <?php if ($dur): ?><span><?= $dur ?></span><span class="dot">·</span><?php endif ?>
        <?php if ($item['width']): ?><span><?= (int) $item['width'] ?>×<?= (int) $item['height'] ?></span><span class="dot">·</span><?php endif ?>
        <?php if ($item['size']): ?><span><?= esc(\App\Libraries\MediaSupport::size((int) $item['size'])) ?></span><span class="dot">·</span><?php endif ?>
        <a href="<?= esc($item['url'], 'attr') ?>" target="_blank" rel="noopener">YouTube에서 보기</a>
        <span class="dot">·</span><span class="yt-status yt-<?= $cls ?>" id="ytStatus"><?= esc($label) ?></span>
      </p>
      <?php if ($item['error']): ?><div class="alert error" style="align-self:stretch"><?= esc($item['error']) ?></div><?php endif ?>

      <?php if ($item['filename']): ?>
        <figure class="viewer">
          <video controls playsinline preload="metadata" src="<?= $base ?>/file"<?= $item['has_thumb'] ? ' poster="' . $base . '/thumb"' : '' ?>>
            <?php foreach ($subs as $s): ?>
              <track kind="subtitles" src="<?= $base ?>/sub/<?= esc($s['lang'], 'url') ?>.vtt" srclang="<?= esc(substr($s['lang'], 0, 2), 'attr') ?>" label="<?= esc($s['name'], 'attr') ?>">
            <?php endforeach ?>
          </video>
        </figure>
      <?php elseif ($busy): ?>
        <figure class="viewer empty" id="busyBox">
          <div><span class="spinner"></span> <b id="busyText"><?= esc($label) ?></b><br><span class="muted small" id="busyNote">영상 길이에 따라 몇 분 걸릴 수 있습니다. 이 페이지는 자동으로 갱신됩니다.</span></div>
        </figure>
      <?php else: ?>
        <figure class="viewer empty">영상 파일이 없습니다.</figure>
      <?php endif ?>

      <?php if ($item['status'] === 'analyzing' && $item['filename']): ?>
        <div class="alert" style="align-self:stretch;margin-top:18px"><span class="spinner"></span> 자막을 읽고 출연진과 내용을 정리하는 중입니다. 끝나면 자동으로 갱신됩니다.</div>
      <?php endif ?>

      <?php if ($cast || $item['summary']): ?>
        <section class="desc yt-analysis">
          <?php if ($cast): ?>
            <p class="eyebrow">출연진</p>
            <ul class="yt-cast">
              <?php foreach ($cast as $c): ?>
                <li><b><?= esc($c['name']) ?></b><?= $c['role'] !== '' ? ' <span class="muted">' . esc($c['role']) . '</span>' : '' ?></li>
              <?php endforeach ?>
            </ul>
          <?php endif ?>
          <?php if ($item['summary']): ?>
            <p class="eyebrow">내용 요약</p>
            <div class="desc-body yt-summary"><?= nl2br(esc($item['summary'])) ?></div>
          <?php endif ?>
        </section>
      <?php endif ?>

      <?php if (trim((string) $item['description']) !== ''): ?>
        <section class="desc">
          <p class="eyebrow">설명</p>
          <div class="desc-body" id="descBody"><?= \App\Libraries\MediaSupport::richText((string) $item['description'], 'youtube') ?></div>
          <button class="btn ghost sm" type="button" id="descMore" hidden>더보기</button>
        </section>
      <?php endif ?>

      <?php if ($subs): ?>
        <section class="desc yt-subview" id="subView" hidden>
          <p class="eyebrow" style="display:flex;gap:10px;align-items:center"><span id="subViewName">자막</span><span class="spacer"></span><button class="linkbtn small" type="button" id="subViewClose">닫기</button></p>
          <div class="desc-body yt-cues" id="subViewBody"></div>
        </section>
      <?php endif ?>
    </div>

    <aside class="detail-side">
      <?php if ($item['filename']): ?>
        <div class="panel-block" style="margin-top:0">
          <p class="eyebrow">영상</p>
          <a class="btn block" href="<?= $base ?>/file?dl=1">영상 다운로드<?= $item['size'] ? ' (' . esc(\App\Libraries\MediaSupport::size((int) $item['size'])) . ')' : '' ?></a>
        </div>
      <?php endif ?>
      <div class="panel-block"<?= $item['filename'] ? '' : ' style="margin-top:0"' ?>>
        <p class="eyebrow">자막 <?= count($subs) ?>개<?= $subs && $subs[0]['auto'] ? ' · 자동 생성' : '' ?></p>
        <?php if ($subs === []): ?>
          <p class="muted small"><?= $busy ? '가져오는 중입니다.' : '이 영상에는 자막이 없습니다.' ?></p>
        <?php else: ?>
          <div class="yt-subs">
            <?php foreach ($subs as $s): ?>
              <div class="yt-sub">
                <button class="lang sub-view" type="button" data-lang="<?= esc($s['lang'], 'attr') ?>" data-name="<?= esc($s['name'], 'attr') ?>" title="자막을 아래에 펼쳐 봅니다"><?= esc($s['name']) ?> <span class="muted small"><?= esc($s['lang']) ?><?= $s['auto'] ? ' · 자동' : '' ?></span></button>
                <span class="fmts">
                  <a class="btn tinted sm" href="<?= $base ?>/sub/<?= esc($s['lang'], 'url') ?>.srt">SRT</a>
                  <a class="btn tinted sm" href="<?= $base ?>/sub/<?= esc($s['lang'], 'url') ?>.vtt">VTT</a>
                  <a class="btn tinted sm" href="<?= $base ?>/sub/<?= esc($s['lang'], 'url') ?>.txt">TXT</a>
                </span>
              </div>
            <?php endforeach ?>
          </div>
        <?php endif ?>
      </div>
      <div class="panel-block">
        <p class="eyebrow">관리</p>
        <div class="aside-actions" style="margin-bottom:0">
          <?php if (! $busy && $item['filename']): ?>
            <form method="post" action="<?= $base ?>/analyze"><?= csrf_field() ?><button class="btn secondary sm" type="submit" title="자막·설명을 다시 읽어 출연진과 요약을 새로 만듭니다"><?= $item['summary'] ? '다시 분석' : '분석' ?></button></form>
          <?php endif ?>
          <?php if (! $busy): ?>
            <form method="post" action="<?= $base ?>/retry"><?= csrf_field() ?><button class="btn secondary sm" type="submit" title="영상과 자막을 다시 내려받습니다">다시 가져오기</button></form>
          <?php endif ?>
          <form method="post" action="<?= $base ?>/delete" onsubmit="return confirm('이 영상과 자막을 삭제할까요?')"><?= csrf_field() ?><button class="btn danger sm" type="submit">삭제</button></form>
        </div>
      </div>
    </aside>
  </div>
</main>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?>
<script>
(() => {
  const $ = (id) => document.getElementById(id);
  const box = document.querySelector('.yt-detail');
  if (box.dataset.busy === '1') {
    const labels = { queued: '대기 중', downloading: '다운로드 중', analyzing: '분석 중' };
    const tick = async () => {
      try {
        const r = await fetch(box.dataset.status, { headers: { Accept: 'application/json' } });
        if (!r.ok) throw new Error(r.status);
        const j = await r.json();
        if (!j.busy) { location.reload(); return; }
        const t = labels[j.status] || j.status;
        if ($('ytStatus')) $('ytStatus').textContent = t;
        if ($('busyText')) $('busyText').textContent = t + (j.status === 'queued' && j.ahead > 0 ? ' (앞에 ' + j.ahead + '건)' : '');
        if (j.title && document.title !== j.title) { document.title = j.title; const h = document.querySelector('.detail-head h1'); if (h) h.textContent = j.title; }
      } catch (e) {}
      setTimeout(tick, 3000);
    };
    setTimeout(tick, 3000);
  }
  // subtitle viewer: the language name in the side panel opens the cues under the description
  const view = $('subView'), video = document.querySelector('.viewer video');
  const cache = {};
  let shown = null;
  const fmt = (t) => { t = Math.floor(t); const h = Math.floor(t / 3600), m = Math.floor(t % 3600 / 60), s = t % 60;
    return (h ? h + ':' + String(m).padStart(2, '0') : m) + ':' + String(s).padStart(2, '0'); };
  const parse = (vtt) => {
    const cues = []; let last = [];
    for (const block of vtt.replace(/\r/g, '').split(/\n{2,}/)) {
      const lines = block.split('\n'); const ti = lines.findIndex((l) => l.includes('-->'));
      if (ti < 0) continue;
      const m = lines[ti].match(/(?:(\d+):)?(\d+):(\d+)\.(\d+)/); if (!m) continue;
      const start = (+(m[1] || 0)) * 3600 + (+m[2]) * 60 + (+m[3]);
      // auto captions scroll: each cue repeats the previous cue's last line, so keep only new lines
      const clean = lines.slice(ti + 1).map((l) => l.replace(/<[^>]*>/g, '').replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/\s+/g, ' ').trim()).filter(Boolean);
      const fresh = clean.filter((l) => !last.includes(l));
      last = clean;
      if (!fresh.length) continue;
      cues.push([start, fresh.join(' ')]);
    }
    return cues;
  };
  const render = (cues) => {
    const body = $('subViewBody'); body.textContent = '';
    for (const [t, text] of cues) {
      const row = document.createElement('div'); row.className = 'cue';
      const b = document.createElement('button'); b.type = 'button'; b.className = 'at'; b.textContent = fmt(t);
      b.addEventListener('click', () => { if (video) { video.currentTime = t; video.play().catch(() => {}); } });
      const span = document.createElement('span'); span.textContent = text;
      row.append(b, span); body.appendChild(row);
    }
    if (!cues.length) body.textContent = '내용이 없는 자막입니다.';
  };
  document.querySelectorAll('.sub-view').forEach((btn) => btn.addEventListener('click', async () => {
    const lang = btn.dataset.lang;
    if (shown === lang) { view.hidden = true; shown = null; btn.classList.remove('on'); return; }
    document.querySelectorAll('.sub-view.on').forEach((b) => b.classList.remove('on'));
    btn.classList.add('on'); shown = lang;
    $('subViewName').textContent = '자막 · ' + btn.dataset.name;
    view.hidden = false; $('subViewBody').textContent = '불러오는 중...';
    try {
      cache[lang] ??= parse(await (await fetch(box.dataset.status.replace(/\/status$/, '') + '/sub/' + encodeURIComponent(lang) + '.vtt')).text());
      render(cache[lang]);
    } catch (e) { $('subViewBody').textContent = '자막을 불러오지 못했습니다.'; }
    view.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }));
  if ($('subViewClose')) $('subViewClose').addEventListener('click', () => { view.hidden = true; shown = null; document.querySelectorAll('.sub-view.on').forEach((b) => b.classList.remove('on')); });

  const body = $('descBody'), more = $('descMore');
  if (body && more && body.scrollHeight > 192) {
    body.classList.add('clamped'); more.hidden = false;
    more.addEventListener('click', () => { more.textContent = body.classList.toggle('clamped') ? '더보기' : '접기'; });
  }
})();
</script>
<?= $this->endSection() ?>
