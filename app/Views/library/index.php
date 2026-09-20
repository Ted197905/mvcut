<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>
<main>
  <?= view('partials/alerts') ?>
  <?php if (! $ffmpeg): ?>
    <div class="alert error">서버에 ffmpeg가 없어 메타데이터와 썸네일을 만들 수 없습니다. <code>sudo apt install ffmpeg</code></div>
  <?php endif ?>

  <div class="dropzone" id="dropzone">
    <strong>파일을 여기에 끌어다 놓으세요</strong><br>
    <span class="small">또는</span> <button class="btn sm" type="button" id="pickBtn">파일 선택</button>
    <input type="file" id="fileInput" multiple accept="video/*,image/*,audio/*,.mkv,.mov,.ts" hidden>
    <div class="small muted" style="margin-top:12px">mp4 · mov · mkv · webm · gif · jpg · png · 최대 4GB</div>
  </div>
  <div class="progress-list" id="progressList"></div>

  <section class="import-panel" id="importPanel">
    <div class="import-head"><b>SNS 링크로 가져오기</b> <span class="muted small">YouTube · X · Facebook · Threads · Instagram</span></div>
    <div class="import-row">
      <input class="input" type="url" id="importUrl" placeholder="게시물 링크를 붙여 넣으세요" autocomplete="off">
      <button class="btn secondary" type="button" id="btnPaste" title="클립보드에서 붙여넣기">Paste</button>
      <button class="btn ghost" type="button" id="btnClearUrl" title="링크 지우기">Del</button>
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
  </section>

  <form class="lib-toolbar" method="get" action="<?= site_url('library') ?>" id="filterForm">
    <input class="input search" type="search" name="q" id="q" value="<?= esc($q) ?>" placeholder="제목으로 검색" autocomplete="off">
    <select class="input" name="kind" onchange="filterForm.submit()">
      <option value="all"<?= $kind === 'all' ? ' selected' : '' ?>>전체</option>
      <option value="original"<?= $kind === 'original' ? ' selected' : '' ?>>원본만</option>
      <option value="result"<?= $kind === 'result' ? ' selected' : '' ?>>편집 결과만</option>
      <option value="video"<?= $kind === 'video' ? ' selected' : '' ?>>영상만</option>
      <option value="image"<?= $kind === 'image' ? ' selected' : '' ?>>이미지만</option>
    </select>
    <select class="input" name="sort" onchange="filterForm.submit()">
      <option value="newest"<?= $sort === 'newest' ? ' selected' : '' ?>>최신순</option>
      <option value="oldest"<?= $sort === 'oldest' ? ' selected' : '' ?>>오래된순</option>
      <option value="title"<?= $sort === 'title' ? ' selected' : '' ?>>제목순</option>
      <option value="largest"<?= $sort === 'largest' ? ' selected' : '' ?>>용량순</option>
      <option value="longest"<?= $sort === 'longest' ? ' selected' : '' ?>>길이순</option>
    </select>
    <?php if ($q !== '' || $kind !== 'all' || $sort !== 'newest'): ?>
      <a class="btn sm ghost" href="<?= site_url('library') ?>">초기화</a>
    <?php endif ?>
    <span class="spacer"></span>
    <span class="muted small" id="libCount"><?= $matched ?>개<?= $matched !== $total ? ' / 전체 ' . $total . '개' : '' ?><?= $pages > 1 ? ' · ' . $page . '/' . $pages . ' 쪽' : '' ?></span>
    <button class="btn sm secondary" type="button" id="btnSelectMode">선택</button>
  </form>

  <form method="post" action="<?= site_url('library/delete') ?>" id="bulkForm">
    <?= csrf_field() ?>
    <div class="bulkbar" id="bulkBar" hidden>
      <label class="switch"><input type="checkbox" id="checkAll"> <span>전체 선택</span></label>
      <span class="muted small" id="bulkCount">0개 선택</span>
      <span class="spacer"></span>
      <button class="btn sm secondary" type="button" id="btnCancelSelect">취소</button>
      <button class="btn sm danger" type="button" id="btnBulkDelete" disabled>선택 삭제</button>
    </div>

    <div class="grid" id="grid">
      <?php foreach ($items as $it): ?>
        <?= view('library/_card', ['it' => $it]) ?>
      <?php endforeach ?>
    </div>
  </form>

  <?php if ($pages > 1): ?>
    <?php $link = static function (int $n) use ($q, $kind, $sort) {
        $qs = array_filter(['q' => $q, 'kind' => $kind === 'all' ? '' : $kind,
                            'sort' => $sort === 'newest' ? '' : $sort, 'page' => $n > 1 ? $n : '']);
        return site_url('library') . ($qs ? '?' . http_build_query($qs) : '');
    }; ?>
    <nav class="pager">
      <a class="pg<?= $page <= 1 ? ' off' : '' ?>" href="<?= $link(max(1, $page - 1)) ?>">이전</a>
      <?php
        $from = max(1, min($page - 2, $pages - 4));
        $to   = min($pages, max($page + 2, 5));
      ?>
      <?php if ($from > 1): ?><a class="pg" href="<?= $link(1) ?>">1</a><?php if ($from > 2): ?><span class="gap">…</span><?php endif ?><?php endif ?>
      <?php for ($n = $from; $n <= $to; $n++): ?>
        <a class="pg<?= $n === $page ? ' on' : '' ?>" href="<?= $link($n) ?>"><?= $n ?></a>
      <?php endfor ?>
      <?php if ($to < $pages): ?><?php if ($to < $pages - 1): ?><span class="gap">…</span><?php endif ?><a class="pg" href="<?= $link($pages) ?>"><?= $pages ?></a><?php endif ?>
      <a class="pg<?= $page >= $pages ? ' off' : '' ?>" href="<?= $link(min($pages, $page + 1)) ?>">다음</a>
    </nav>
  <?php endif ?>
  <?php if (empty($items)): ?>
    <div class="empty" id="empty"><?= $total > 0 ? '조건에 맞는 항목이 없습니다.' : '아직 라이브러리가 비어 있습니다.' ?></div>
  <?php endif ?>
</main>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function () {
  const $ = (id) => document.getElementById(id);
  const csrf = MV.csrf();
  const base = <?= json_encode(site_url('/')) ?>;
  const hdr = { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf ? csrf.hash : '' };
  const grid = $('grid'), list = $('progressList');
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  /* ---------------- chunked upload ---------------- */
  const dz = $('dropzone'), input = $('fileInput');
  $('pickBtn').addEventListener('click', () => input.click());
  input.addEventListener('change', () => { queue([...input.files]); input.value = ''; });
  ['dragenter', 'dragover'].forEach(ev => dz.addEventListener(ev, e => { e.preventDefault(); dz.classList.add('over'); }));
  ['dragleave', 'drop'].forEach(ev => dz.addEventListener(ev, e => { e.preventDefault(); dz.classList.remove('over'); }));
  dz.addEventListener('drop', e => queue([...e.dataTransfer.files]));

  const q = []; let busy = false;
  function queue(files) { files.forEach(f => q.push(f)); next(); }
  function next() { if (busy || !q.length) return; busy = true; upload(q.shift()).finally(() => { busy = false; next(); }); }

  function progressRow(label) {
    const el = document.createElement('div');
    el.className = 'progress-item';
    el.innerHTML = '<div><span class="name"></span> <span class="muted small pct"></span></div><div class="bar"><i></i></div>';
    el.querySelector('.name').textContent = label;
    list.prepend(el);
    return {
      el,
      set: (p, text) => { el.querySelector('.bar i').style.width = p + '%'; el.querySelector('.pct').textContent = text; },
      done: (text) => { el.classList.add('done'); el.querySelector('.bar i').style.width = '100%'; el.querySelector('.pct').textContent = text; setTimeout(() => el.remove(), 2500); },
      fail: (text) => { el.classList.add('fail'); el.querySelector('.pct').textContent = text; },
    };
  }

  async function post(url, body, isJson = true) {
    const res = await fetch(url, isJson
      ? { method: 'POST', headers: hdr, body: JSON.stringify(body) }
      : { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body });
    let j = {}; try { j = await res.json(); } catch (e) {}
    if (!res.ok || j.error) throw new Error(j.error || ('HTTP ' + res.status));
    return j;
  }

  function putChunk(fd, onProgress) {
    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest();
      xhr.open('POST', base + 'api/upload/chunk');
      xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
      xhr.upload.onprogress = e => { if (e.lengthComputable) onProgress(e.loaded); };
      xhr.onload = () => {
        let j = {}; try { j = JSON.parse(xhr.responseText); } catch (e) {}
        (xhr.status === 200 && j.ok) ? resolve(j) : reject(new Error(j.error || ('HTTP ' + xhr.status)));
      };
      xhr.onerror = () => reject(new Error('네트워크 오류'));
      xhr.send(fd);
    });
  }

  async function upload(file) {
    const row = progressRow(file.name + ' (' + MV.fmtSize(file.size) + ')');
    let uploadId = null;
    try {
      const init = await post(base + 'api/upload/init', { name: file.name, size: file.size });
      uploadId = init.uploadId;
      const size = init.chunkSize || 8 * 1024 * 1024;
      const total = Math.max(1, Math.ceil(file.size / size));
      let sent = 0;
      for (let i = 0; i < total; i++) {
        const blob = file.slice(i * size, Math.min(file.size, (i + 1) * size));
        const fd = new FormData();
        fd.append('uploadId', uploadId); fd.append('index', i); fd.append('chunk', blob);
        if (csrf) fd.append(csrf.name, csrf.hash);
        let tries = 0;
        for (;;) {
          try {
            await putChunk(fd, (loaded) => row.set(Math.round((sent + loaded) / file.size * 100), Math.round((sent + loaded) / file.size * 100) + '%'));
            break;
          } catch (e) {
            if (++tries >= 3) throw e;
            row.set(Math.round(sent / file.size * 100), '재시도 ' + tries + '...');
            await new Promise(r => setTimeout(r, 1000 * tries));
          }
        }
        sent += blob.size;
        row.set(Math.round(sent / file.size * 100), sent >= file.size ? '처리 중...' : Math.round(sent / file.size * 100) + '%');
      }
      const fin = await post(base + 'api/upload/finish', { uploadId, total });
      row.done('완료');
      addCard(fin.item);
    } catch (e) {
      row.fail(e.message);
      if (uploadId) post(base + 'api/upload/abort', { uploadId }).catch(() => {});
    }
  }

  function addCard(item) {
    grid.insertAdjacentHTML('afterbegin', cardHtml(item));
    const empty = $('empty'); if (empty) empty.remove();
    if (selectMode) grid.firstElementChild.classList.add('selectable');
  }
  function cardHtml(it) {
    const thumb = it.has_thumb == 1 ? '<img src="' + base + 'media/' + it.id + '/thumb" alt="">' : '<span>' + esc(it.media_type) + '</span>';
    const dur = it.duration ? '<span class="dur">' + MV.fmtDur(it.duration) + '</span>' : '';
    const meta = [it.width && it.height ? it.width + 'x' + it.height : null, MV.fmtSize(it.size)].filter(Boolean).join(' / ');
    return '<div class="card-wrap"><label class="pick"><input type="checkbox" name="ids[]" value="' + it.id + '"></label>' +
      '<a class="card" href="' + base + 'library/' + it.id + '"><div class="thumb">' + thumb + dur +
      '<span class="badge">' + esc(it.source) + '</span></div><div class="body"><div class="title">' + esc(it.title) +
      '</div><div class="meta">' + esc(meta) + '</div></div></a></div>';
  }

  /* ---------------- select mode ---------------- */
  let selectMode = false;
  const bulkBar = $('bulkBar');
  function boxes() { return [...grid.querySelectorAll('input[name="ids[]"]')]; }
  function setSelectMode(on) {
    selectMode = on;
    bulkBar.hidden = !on;
    grid.classList.toggle('picking', on);
    $('btnSelectMode').textContent = on ? '선택 종료' : '선택';
    if (!on) { boxes().forEach(b => b.checked = false); $('checkAll').checked = false; updateBulk(); }
  }
  function updateBulk() {
    const n = boxes().filter(b => b.checked).length;
    $('bulkCount').textContent = n + '개 선택';
    $('btnBulkDelete').disabled = !n;
    $('btnBulkDelete').textContent = n ? '선택 ' + n + '개 삭제' : '선택 삭제';
  }
  $('btnSelectMode').addEventListener('click', () => setSelectMode(!selectMode));
  $('btnCancelSelect').addEventListener('click', () => setSelectMode(false));
  $('checkAll').addEventListener('change', e => { boxes().forEach(b => b.checked = e.target.checked); updateBulk(); });
  grid.addEventListener('change', e => { if (e.target.name === 'ids[]') updateBulk(); });
  grid.addEventListener('click', e => {
    if (!selectMode) return;
    const card = e.target.closest('.card'); if (!card) return;
    e.preventDefault();
    const box = card.parentElement.querySelector('input[name="ids[]"]');
    box.checked = !box.checked; updateBulk();
  });
  let armed = false;
  $('btnBulkDelete').addEventListener('click', () => {
    if (armed) { $('bulkForm').submit(); return; }
    armed = true;
    const b = $('btnBulkDelete'); const t = b.textContent;
    b.textContent = '정말 삭제 (다시 클릭)';
    setTimeout(() => { armed = false; b.textContent = t; }, 4000);
  });

  /* ---------------- SNS import ---------------- */
  const st = $('importStatus'), il = $('importList'), ia = $('importActions');
  let inspected = null;
  function istatus(msg, cls) { st.hidden = !msg; st.className = 'import-status ' + (cls || ''); st.textContent = msg || ''; }
  $('btnInspect').addEventListener('click', inspect);
  $('btnPaste').addEventListener('click', async () => {
    const el = $('importUrl');
    try {
      const t = (await navigator.clipboard.readText()).trim();
      if (t) { el.value = t; istatus(''); el.focus(); return; }
      istatus('클립보드가 비어 있습니다.', 'error');
    } catch (e) {
      el.focus();
      istatus('브라우저가 붙여넣기를 막았습니다. 입력창에서 Ctrl+V를 눌러 주세요.', 'error');
    }
  });
  $('btnClearUrl').addEventListener('click', () => {
    $('importUrl').value = ''; $('importUrl').focus();
    il.hidden = ia.hidden = true; il.innerHTML = ''; inspected = null; istatus('');
  });
  $('importUrl').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); inspect(); } });
  async function inspect() {
    const url = $('importUrl').value.trim(); if (!url) return;
    istatus('리소스를 확인하는 중... (몇 초 걸립니다)'); il.hidden = ia.hidden = true; il.innerHTML = '';
    $('btnInspect').disabled = true;
    try {
      const j = await post(base + 'api/import/inspect', { url });
      inspected = { url, entries: j.entries, desc: j.desc || '', post: j.post || null };
      if (!j.entries.length) throw new Error('가져올 수 있는 영상/이미지가 없습니다.');
      j.entries.forEach(e => {
        const el = document.createElement('div');
        el.className = 'import-item on'; el.dataset.index = e.index;
        el.innerHTML = (e.thumbnail ? '<img src="' + esc(e.thumbnail) + '" alt="" referrerpolicy="no-referrer">' : '<img alt="">') +
          '<div style="min-width:0"><div class="t">' + esc(e.title) + '</div><div class="m">' +
          esc(e.kind === 'image' ? '이미지' : '영상') + (e.duration ? ' · ' + MV.fmtDur(e.duration) : '') +
          (e.width ? ' · ' + e.width + 'x' + e.height : '') + '</div></div>';
        el.addEventListener('click', () => { el.classList.toggle('on'); count(); });
        il.appendChild(el);
      });
      il.hidden = ia.hidden = false;
      istatus(j.notice ? j.notice : ((j.platform || '') + ' · ' + j.entries.length + '개 항목. 가져올 항목을 선택하세요.'), j.notice ? 'warn' : '');
      count();
    } catch (e) { istatus(e.message, 'error'); }
    $('btnInspect').disabled = false;
  }
  function count() {
    const n = il.querySelectorAll('.import-item.on').length;
    $('importCount').textContent = n + '개 선택';
    $('btnImport').disabled = !n;
  }
  $('btnSelectAll').addEventListener('click', () => {
    const all = [...il.querySelectorAll('.import-item')];
    const on = all.every(x => x.classList.contains('on'));
    all.forEach(x => x.classList.toggle('on', !on)); count();
  });
  $('btnImport').addEventListener('click', async () => {
    const items = [...il.querySelectorAll('.import-item.on')].map(x => +x.dataset.index);
    if (!items.length || !inspected) return;
    const titles = {}, images = {}, media = {};
    inspected.entries.forEach(e => {
      titles[e.index] = e.title;
      if (e.image_url) images[e.index] = e.image_url;
      else if (e.media_url) media[e.index] = e.media_url;
    });
    istatus('가져오기 요청 중...'); $('btnImport').disabled = true;
    try {
      const j = await post(base + 'api/import', { url: inspected.url, items, titles, images, media, desc: inspected.desc, post: inspected.post });
      il.hidden = ia.hidden = true;
      istatus(j.job_ids.length + '개 항목을 서버에서 내려받는 중입니다.');
      j.job_ids.forEach(id => pollImport(id));
    } catch (e) { istatus(e.message, 'error'); $('btnImport').disabled = false; }
  });
  let pending = 0;
  function pollImport(id) {
    pending++;
    const row = progressRow('가져오기 #' + id);
    row.set(5, '다운로드 중...');
    const tick = async () => {
      try {
        const res = await fetch(base + 'api/jobs/' + id, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const job = (await res.json()).job;
        if (job.status === 'done') {
          const m = await (await fetch(base + 'api/media/' + job.result_media_id, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })).json();
          row.el.querySelector('.name').textContent = m.media.title;
          row.done('완료');
          addCard(m.media);
          if (--pending === 0) istatus('가져오기 완료.');
        } else if (job.status === 'failed') { row.fail(job.error || '실패'); pending--; }
        else { row.set(Math.max(5, job.progress), job.status === 'queued' ? '대기 중' : '다운로드 중...'); setTimeout(tick, 2000); }
      } catch (e) { row.fail(e.message); pending--; }
    };
    setTimeout(tick, 1500);
  }
})();
</script>
<?= $this->endSection() ?>
