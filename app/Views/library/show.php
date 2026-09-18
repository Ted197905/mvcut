<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>
<main>
  <p class="small"><a href="<?= site_url('library') ?>">&larr; 라이브러리</a><?php if ($item['parent_id']): ?> · <a href="<?= site_url('library/' . $item['parent_id']) ?>">원본 보기</a><?php endif ?></p>
  <h1><?= esc($item['title']) ?></h1>
  <p class="sub"><?= esc(ucfirst($item['media_type'])) ?> · <?= esc($item['source']) ?> · <?= esc($item['created_at']) ?><?php if ($item['source_url']): ?> · <a href="<?= esc($item['source_url'], 'attr') ?>" target="_blank" rel="noopener">원본 링크</a><?php endif ?></p>
  <?= view('partials/alerts') ?>
  <?php if ($pending): ?>
    <div class="alert" id="pendingBox" data-job="<?= (int) $pending['id'] ?>">
      <?= $pending['type'] === 'proxy' ? '편집용 미리보기(프록시)를 만드는 중입니다' : '처리 중입니다' ?> <span id="pendingPct"><?= (int) $pending['progress'] ?>%</span>. 완료되면 자동으로 새로고침됩니다.
    </div>
  <?php endif ?>
  <div class="detail">
    <div class="player">
      <?php if ($item['media_type'] === 'video' && $playable): ?>
        <video controls playsinline preload="metadata" src="<?= site_url('media/' . $item['id'] . '/proxy') ?>" <?= $item['has_thumb'] ? 'poster="' . site_url('media/' . $item['id'] . '/thumb') . '"' : '' ?>></video>
      <?php elseif ($item['media_type'] === 'image'): ?>
        <img src="<?= site_url('media/' . $item['id'] . '/file') ?>" alt="">
      <?php elseif ($item['media_type'] === 'audio'): ?>
        <audio controls src="<?= site_url('media/' . $item['id'] . '/file') ?>"></audio>
      <?php elseif ($item['has_thumb']): ?>
        <img src="<?= site_url('media/' . $item['id'] . '/thumb') ?>" alt="">
      <?php else: ?>
        <span class="muted">브라우저에서 바로 재생할 수 없는 포맷입니다. 프록시 생성 후 미리보기가 가능합니다.</span>
      <?php endif ?>
    </div>
    <div>
      <h3 style="margin-top:0">정보</h3>
      <dl class="kv">
        <dt>파일</dt><dd><?= esc($item['filename']) ?></dd>
        <dt>형식</dt><dd><?= esc($item['container'] ?? $item['mime']) ?><?= $item['has_proxy'] ? ' <span class="muted small">(프록시 있음)</span>' : '' ?></dd>
        <?php if ($item['vcodec']): ?><dt>비디오</dt><dd><?= esc($item['vcodec']) ?><?= $item['fps'] ? ' / ' . esc(rtrim(rtrim($item['fps'], '0'), '.')) . ' fps' : '' ?></dd><?php endif ?>
        <?php if ($item['acodec']): ?><dt>오디오</dt><dd><?= esc($item['acodec']) ?></dd><?php endif ?>
        <?php if ($item['width']): ?><dt>해상도</dt><dd><?= esc($item['width'] . ' x ' . $item['height']) ?></dd><?php endif ?>
        <?php if ($item['duration']): ?><dt>길이</dt><dd><?= gmdate($item['duration'] >= 3600 ? 'G:i:s' : 'i:s', (int) $item['duration']) ?></dd><?php endif ?>
        <dt>크기</dt><dd><?= number_format($item['size'] / 1048576, 2) ?> MB</dd>
      </dl>
      <div class="actions">
        <?php if ($item['media_type'] === 'video'): ?>
          <a class="btn <?= $playable ? '' : 'disabled' ?>" href="<?= site_url('edit/' . $item['id']) ?>" <?= $playable ? '' : 'aria-disabled="true" onclick="return false"' ?>>편집</a>
        <?php endif ?>
        <button class="btn secondary" type="button" id="btnDownload">다운로드</button>
        <form method="post" action="<?= site_url('library/' . $item['id'] . '/delete') ?>" id="deleteForm">
          <?= csrf_field() ?>
          <button class="btn secondary" type="button" id="btnDelete">삭제</button>
        </form>
      </div>

      <div class="dl-panel" id="dlPanel" hidden>
        <h3>다운로드</h3>
        <p class="small muted">원본: <?= esc(strtoupper($item['container'] ?? pathinfo($item['filename'], PATHINFO_EXTENSION))) ?><?= $item['vcodec'] ? ' / ' . esc($item['vcodec']) : '' ?><?= $item['acodec'] ? ' + ' . esc($item['acodec']) : '' ?><?= $item['width'] ? ' / ' . esc($item['width'] . 'x' . $item['height']) : '' ?></p>
        <div class="dl-row">
          <a class="btn sm" href="<?= site_url('media/' . $item['id'] . '/file?dl=1') ?>">원본 그대로 받기</a>
        </div>
        <div class="dl-row">
          <select id="dlFormat" class="input">
            <?php if ($item['media_type'] === 'image'): ?>
              <option value="jpg">JPG</option><option value="png">PNG</option><option value="webp">WebP</option>
            <?php else: ?>
              <option value="mp4">MP4 (H.264 + AAC)</option><option value="webm">WebM (VP9 + Opus)</option><option value="gif">GIF (15fps, 무음)</option>
            <?php endif ?>
          </select>
          <select id="dlHeight" class="input">
            <option value="0">원본 해상도</option><option value="1080">1080p</option><option value="720">720p</option><option value="480">480p</option><option value="360">360p</option>
          </select>
          <select id="dlQuality" class="input"><option value="high">높은 품질</option><option value="medium">작은 용량</option></select>
          <button class="btn sm" type="button" id="btnConvert">변환해서 받기</button>
        </div>
        <div class="dl-status" id="dlStatus" hidden></div>
      </div>
    </div>
  </div>
</main>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function () {
  const csrf = MV.csrf();
  const mediaId = <?= (int) $item['id'] ?>;
  const jobsUrl = <?= json_encode(site_url('api/jobs')) ?>, base = <?= json_encode(site_url('/')) ?>;
  const hdr = { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf ? csrf.hash : '' };

  document.getElementById('btnDownload').addEventListener('click', () => { const p = document.getElementById('dlPanel'); p.hidden = !p.hidden; });
  // two-step delete without a native confirm dialog
  const del = document.getElementById('btnDelete');
  del.addEventListener('click', () => { if (del.dataset.armed) document.getElementById('deleteForm').submit(); else { del.dataset.armed = '1'; del.textContent = '정말 삭제 (다시 클릭)'; del.classList.add('danger'); setTimeout(() => { delete del.dataset.armed; del.textContent = '삭제'; del.classList.remove('danger'); }, 4000); } });

  const st = document.getElementById('dlStatus');
  function status(html, cls) { st.hidden = false; st.className = 'dl-status ' + (cls || ''); st.innerHTML = html; }
  document.getElementById('btnConvert').addEventListener('click', async () => {
    status('요청 중...');
    try {
      const res = await fetch(base + 'api/convert/' + mediaId, { method: 'POST', headers: hdr, body: JSON.stringify({ format: dlFormat.value, height: +dlHeight.value, quality: dlQuality.value }) });
      const j = await res.json();
      if (!res.ok || !j.ok) throw new Error(j.error || 'HTTP ' + res.status);
      if (j.cached) return done(j.media.id);
      poll(j.job.id, (job) => done(job.result_media_id), (job) => status('변환 중 ' + job.progress + '%'));
    } catch (e) { status('실패: ' + e.message, 'error'); }
  });
  function done(id) { status('완료. <a class="btn sm" href="' + base + 'media/' + id + '/file?dl=1">다운로드</a> <a href="' + base + 'library/' + id + '">라이브러리에서 보기</a>', 'ok'); }
  function poll(id, onDone, onTick) {
    setTimeout(async () => {
      const res = await fetch(jobsUrl + '/' + id, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      const job = (await res.json()).job;
      if (job.status === 'done') return onDone(job);
      if (job.status === 'failed') return status('실패: ' + (job.error || ''), 'error');
      onTick(job); poll(id, onDone, onTick);
    }, 1000);
  }
  const pending = document.getElementById('pendingBox');
  if (pending) poll(+pending.dataset.job, () => location.reload(), (job) => { document.getElementById('pendingPct').textContent = job.progress + '%'; });
})();
</script>
<?= $this->endSection() ?>
