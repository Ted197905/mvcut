<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>
<main>
  <h1>라이브러리</h1>
  <p class="sub">원본을 업로드하거나 SNS 링크로 가져와서 편집을 시작하세요.</p>
  <?= view('partials/alerts') ?>
  <?php if (! $ffmpeg): ?>
    <div class="alert error">서버에 ffmpeg가 없어 메타데이터와 썸네일을 만들 수 없습니다. <code>sudo apt install ffmpeg</code></div>
  <?php endif ?>

  <div class="dropzone" id="dropzone">
    <strong>파일을 여기에 끌어다 놓으세요</strong><br>
    <span class="small">또는</span> <button class="btn sm" type="button" id="pickBtn">파일 선택</button>
    <input type="file" id="fileInput" multiple accept="video/*,image/*,audio/*,.mkv,.mov,.ts" hidden>
    <div class="small" style="margin-top:8px">mp4, mov, mkv, webm, gif, jpg, png 등. 파일당 최대 2GB</div>
  </div>
  <div class="progress-list" id="progressList"></div>

  <div class="import-panel">
    <h3>SNS 링크로 가져오기</h3>
    <div class="import-row">
      <input class="input" type="url" id="importUrl" placeholder="Instagram, Facebook, X, Threads 게시물 링크 붙여넣기" autocomplete="off">
      <button class="btn" type="button" id="btnInspect">리소스 확인</button>
    </div>
    <div class="import-status" id="importStatus" hidden></div>
    <div class="import-list" id="importList" hidden></div>
    <div class="toolbar" id="importActions" hidden style="margin-top:12px">
      <span class="muted small" id="importCount"></span>
      <span class="spacer"></span>
      <button class="btn secondary sm" type="button" id="btnSelectAll">전체 선택</button>
      <button class="btn sm" type="button" id="btnImport">선택한 리소스 가져오기</button>
    </div>
  </div>

  <div class="grid" id="grid">
    <?php foreach ($items as $it): ?>
      <?= view('library/_card', ['it' => $it]) ?>
    <?php endforeach ?>
  </div>
  <?php if (empty($items)): ?>
    <div class="empty" id="empty">아직 라이브러리가 비어 있습니다.</div>
  <?php endif ?>
</main>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function () {
  const dz = document.getElementById('dropzone');
  const input = document.getElementById('fileInput');
  const list = document.getElementById('progressList');
  const grid = document.getElementById('grid');
  const csrf = MV.csrf();
  const uploadUrl = <?= json_encode(site_url('library/upload')) ?>;
  const base = <?= json_encode(site_url('/')) ?>;
  const hdr = { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf ? csrf.hash : '' };

  document.getElementById('pickBtn').addEventListener('click', () => input.click());
  input.addEventListener('change', () => { queue([...input.files]); input.value = ''; });
  ['dragenter', 'dragover'].forEach(ev => dz.addEventListener(ev, e => { e.preventDefault(); dz.classList.add('over'); }));
  ['dragleave', 'drop'].forEach(ev => dz.addEventListener(ev, e => { e.preventDefault(); dz.classList.remove('over'); }));
  dz.addEventListener('drop', e => queue([...e.dataTransfer.files]));

  const q = []; let busy = false;
  function queue(files) { files.forEach(f => q.push(f)); next(); }
  function next() {
    if (busy || !q.length) return;
    busy = true;
    const f = q.shift();
    const el = document.createElement('div');
    el.className = 'progress-item';
    el.innerHTML = '<div><span class="name"></span> <span class="muted small pct"></span></div><div class="bar"><i></i></div>';
    el.querySelector('.name').textContent = f.name + ' (' + MV.fmtSize(f.size) + ')';
    list.prepend(el);
    const fd = new FormData();
    fd.append('file', f);
    if (csrf) fd.append(csrf.name, csrf.hash);
    const xhr = new XMLHttpRequest();
    xhr.open('POST', uploadUrl);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.upload.onprogress = e => { if (e.lengthComputable) {
      const p = Math.round(e.loaded / e.total * 100);
      el.querySelector('.bar i').style.width = p + '%';
      el.querySelector('.pct').textContent = p < 100 ? p + '%' : '처리 중...';
    }};
    xhr.onload = () => {
      let r = {}; try { r = JSON.parse(xhr.responseText); } catch (e) {}
      if (xhr.status === 200 && r.ok) {
        el.classList.add('done'); el.querySelector('.pct').textContent = '완료';
        grid.insertAdjacentHTML('afterbegin', cardHtml(r.item));
        const empty = document.getElementById('empty'); if (empty) empty.remove();
        setTimeout(() => el.remove(), 2500);
      } else {
        el.classList.add('fail'); el.querySelector('.pct').textContent = r.error || ('실패 (' + xhr.status + ')');
      }
      busy = false; next();
    };
    xhr.onerror = () => { el.classList.add('fail'); el.querySelector('.pct').textContent = '네트워크 오류'; busy = false; next(); };
    xhr.send(fd);
  }
  /* ---- SNS import ---- */
  const st = document.getElementById('importStatus'), il = document.getElementById('importList'), ia = document.getElementById('importActions');
  let inspected = null;
  function istatus(msg, cls) { st.hidden = !msg; st.className = 'import-status ' + (cls || ''); st.textContent = msg || ''; }
  document.getElementById('btnInspect').addEventListener('click', inspect);
  document.getElementById('importUrl').addEventListener('keydown', e => { if (e.key === 'Enter') inspect(); });
  async function inspect() {
    const url = document.getElementById('importUrl').value.trim(); if (!url) return;
    istatus('리소스를 확인하는 중... (몇 초 걸립니다)'); il.hidden = ia.hidden = true; il.innerHTML = '';
    document.getElementById('btnInspect').disabled = true;
    try {
      const res = await fetch(base + 'api/import/inspect', { method: 'POST', headers: hdr, body: JSON.stringify({ url }) });
      const j = await res.json();
      if (!res.ok || !j.ok) throw new Error(j.error || 'HTTP ' + res.status);
      inspected = { url, entries: j.entries };
      if (!j.entries.length) throw new Error('가져올 수 있는 영상/이미지가 없습니다.');
      j.entries.forEach(e => {
        const el = document.createElement('div'); el.className = 'import-item on'; el.dataset.index = e.index;
        el.innerHTML = (e.thumbnail ? '<img src="' + esc(e.thumbnail) + '" alt="" referrerpolicy="no-referrer">' : '<img alt="">') +
          '<div style="min-width:0"><div class="t">' + esc(e.title) + '</div><div class="m">' + esc(e.kind === 'image' ? '이미지' : '영상') + (e.duration ? ' · ' + MV.fmtDur(e.duration) : '') + (e.width ? ' · ' + e.width + 'x' + e.height : '') + '</div></div>';
        el.addEventListener('click', () => { el.classList.toggle('on'); count(); });
        il.appendChild(el);
      });
      il.hidden = ia.hidden = false; istatus((j.platform || '') + ' · ' + j.entries.length + '개 항목. 가져올 항목을 선택하세요.'); count();
    } catch (e) { istatus(e.message, 'error'); }
    document.getElementById('btnInspect').disabled = false;
  }
  function count() { const n = il.querySelectorAll('.import-item.on').length; document.getElementById('importCount').textContent = n + '개 선택'; document.getElementById('btnImport').disabled = !n; }
  document.getElementById('btnSelectAll').addEventListener('click', () => { const all = [...il.querySelectorAll('.import-item')]; const on = all.every(x => x.classList.contains('on')); all.forEach(x => x.classList.toggle('on', !on)); count(); });
  document.getElementById('btnImport').addEventListener('click', async () => {
    const items = [...il.querySelectorAll('.import-item.on')].map(x => +x.dataset.index); if (!items.length || !inspected) return;
    const titles = {}; inspected.entries.forEach(e => titles[e.index] = e.title);
    istatus('가져오기 요청 중...'); document.getElementById('btnImport').disabled = true;
    try {
      const res = await fetch(base + 'api/import', { method: 'POST', headers: hdr, body: JSON.stringify({ url: inspected.url, items, titles }) });
      const j = await res.json(); if (!res.ok || !j.ok) throw new Error(j.error || 'HTTP ' + res.status);
      il.hidden = ia.hidden = true;
      istatus(j.job_ids.length + '개 항목을 서버에서 내려받는 중입니다. 완료되면 라이브러리에 추가됩니다.');
      j.job_ids.forEach(id => pollJob(id));
    } catch (e) { istatus(e.message, 'error'); document.getElementById('btnImport').disabled = false; }
  });
  let pending = 0;
  function pollJob(id) {
    pending++;
    const tick = async () => {
      const res = await fetch(base + 'api/jobs/' + id, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      const job = (await res.json()).job;
      if (job.status === 'done') {
        const m = await (await fetch(base + 'api/media/' + job.result_media_id, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })).json();
        grid.insertAdjacentHTML('afterbegin', cardHtml(m.media)); const empty = document.getElementById('empty'); if (empty) empty.remove();
        if (--pending === 0) istatus('가져오기 완료.');
      } else if (job.status === 'failed') { istatus('실패: ' + (job.error || ''), 'error'); pending--; }
      else setTimeout(tick, 2000);
    };
    setTimeout(tick, 1500);
  }

  function esc(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
  function cardHtml(it) {
    const base = <?= json_encode(site_url('/')) ?>;
    const thumb = it.has_thumb == 1 ? '<img src="' + base + 'media/' + it.id + '/thumb" alt="">' : '<span>' + esc(it.media_type) + '</span>';
    const dur = it.duration ? '<span class="dur">' + MV.fmtDur(it.duration) + '</span>' : '';
    const meta = [it.width && it.height ? it.width + 'x' + it.height : null, MV.fmtSize(it.size)].filter(Boolean).join(' / ');
    return '<a class="card" href="' + base + 'library/' + it.id + '"><div class="thumb">' + thumb + dur +
      '<span class="badge">' + esc(it.source) + '</span></div><div class="body"><div class="title">' + esc(it.title) +
      '</div><div class="meta">' + esc(meta) + '</div></div></a>';
  }
})();
</script>
<?= $this->endSection() ?>
