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
