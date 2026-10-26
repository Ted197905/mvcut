<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>
<main class="lib-layout">
<div class="lib-col">
  <?= view('partials/alerts') ?>
  <?php if (! $ffmpeg): ?>
    <div class="alert error">서버에 ffmpeg가 없어 메타데이터와 썸네일을 만들 수 없습니다. <code>sudo apt install ffmpeg</code></div>
  <?php endif ?>

  <section class="import-panel" id="importPanel">
    <div class="drop-hint" aria-hidden="true">여기에 놓으면 업로드합니다 <span class="small">mp4 · mov · mkv · webm · gif · jpg · png · 최대 4GB</span></div>
    <div class="import-head"><b>SNS 링크로 가져오기</b> <span class="muted small">YouTube · X · Facebook · Threads · Instagram · TikTok · 파일은 이 영역에 끌어다 놓기</span>
      <span class="spacer"></span>
      <button class="btn sm ghost" type="button" id="pickBtn">파일 선택</button>
      <input type="file" id="fileInput" multiple accept="video/*,image/*,audio/*,.mkv,.mov,.ts" hidden>
    </div>
    <div class="import-row">
      <div class="url-field">
        <input class="input" type="url" id="importUrl" placeholder="게시물 링크를 붙여 넣으세요" autocomplete="off">
        <button class="url-clear" type="button" id="btnClearUrl" title="링크 지우기" aria-label="링크 지우기" hidden>&times;</button>
      </div>
      <button class="btn secondary" type="button" id="btnPaste" title="클립보드에서 붙여넣기">붙여넣기</button>
      <button class="btn secondary" type="button" id="btnQuick" title="리소스 확인 없이 링크의 영상만 모두 가져옵니다 (이미지는 제외). X에 올릴 수 없는 형식이면 MP4로 바꾸고 원본은 지웁니다">영상 바로 다운</button>
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
  <div class="progress-list" id="progressList"></div>

  <div class="segmented cat-filter" role="radiogroup" aria-label="카테고리">
    <?php
      $shown  = array_values(array_filter($cats, static fn ($c) => $c !== \App\Models\CategoryModel::HIDDEN));
      $hidden = in_array(\App\Models\CategoryModel::HIDDEN, $cats, true) ? [\App\Models\CategoryModel::HIDDEN => \App\Models\CategoryModel::HIDDEN] : [];
    ?>
    <?php foreach (array_merge(['ALL' => 'ALL'], array_combine($shown, $shown) ?: [], ['_none' => '미지정'], $hidden) as $v => $labelText): ?>
      <label><input type="radio" name="cat" form="filterForm" value="<?= esc($v, 'attr') ?>"<?= $cat === (string) $v ? ' checked' : '' ?> onchange="filterForm.submit()"><span><?= esc($labelText) ?></span></label>
    <?php endforeach ?>
  </div>

  <form class="lib-toolbar" method="get" action="<?= site_url('library') ?>" id="filterForm">
    <input class="input search" type="search" name="q" id="q" value="<?= esc($q) ?>" placeholder="제목으로 검색" autocomplete="off">
    <select class="input" name="kind" onchange="filterForm.submit()">
      <option value="all"<?= $kind === 'all' ? ' selected' : '' ?>>전체</option>
      <option value="original"<?= $kind === 'original' ? ' selected' : '' ?>>원본만</option>
      <option value="result"<?= $kind === 'result' ? ' selected' : '' ?>>편집 결과만</option>
      <option value="video"<?= $kind === 'video' ? ' selected' : '' ?>>영상만</option>
      <option value="image"<?= $kind === 'image' ? ' selected' : '' ?>>이미지만</option>
      <option value="xbad"<?= $kind === 'xbad' ? ' selected' : '' ?>>X 미지원만</option>
    </select>
    <select class="input" name="sort" onchange="filterForm.submit()">
      <option value="newest"<?= $sort === 'newest' ? ' selected' : '' ?>>최신순</option>
      <option value="oldest"<?= $sort === 'oldest' ? ' selected' : '' ?>>오래된순</option>
      <option value="title"<?= $sort === 'title' ? ' selected' : '' ?>>제목순</option>
      <option value="largest"<?= $sort === 'largest' ? ' selected' : '' ?>>용량순</option>
      <option value="longest"<?= $sort === 'longest' ? ' selected' : '' ?>>길이순</option>
    </select>
    <?php if (empty($mobile)): ?>
    <label class="per-page small muted">표시 <input class="input" type="number" name="per" min="1" max="500" step="1" value="<?= (int) $perPage ?>" onchange="filterForm.submit()" aria-label="페이지당 표시 개수">개</label>
    <?php endif ?>
    <?php if ($q !== '' || $kind !== 'all' || $sort !== 'newest'): ?>
      <a class="btn sm ghost" href="<?= site_url('library') ?>">초기화</a>
    <?php endif ?>
    <span class="spacer"></span>
    <span class="muted small" id="libCount"><?= $matched ?>개<?= $matched !== $total ? ' / 전체 ' . $total . '개' : '' ?><?= $pages > 1 ? ' · ' . $page . '/' . $pages . ' 쪽' : '' ?></span>
    <button class="btn sm secondary" type="button" id="btnSelectMode">선택</button>
  </form>

  <section class="job-queue" id="jobQueue" hidden>
    <div class="jq-head"><b>작업 큐</b> <span class="muted small" id="jqCount"></span><span class="spacer"></span>
      <button class="btn sm secondary" type="button" id="jqReload" hidden>목록 새로고침</button></div>
    <div class="jq-list" id="jqList"></div>
  </section>

  <form method="post" action="<?= site_url('library/delete') ?>" id="bulkForm">
    <?= csrf_field() ?>
    <div class="bulkbar" id="bulkBar" hidden>
      <label class="switch"><input type="checkbox" id="checkAll"> <span>전체 선택</span></label>
      <span class="muted small" id="bulkCount">0개 선택</span>
      <span class="spacer"></span>
      <span class="cat-pick">
        <select class="input sm" name="category" aria-label="카테고리 지정">
          <option value="">미지정</option>
          <?php foreach ($cats as $c): ?><option value="<?= esc($c, 'attr') ?>"<?= $cat === $c ? ' selected' : '' ?>><?= esc($c) ?></option><?php endforeach ?>
        </select>
        <button class="btn sm secondary" type="submit" id="btnBulkCat" formaction="<?= site_url('library/category') ?>" disabled>카테고리 지정</button>
      </span>
      <span class="cat-pick">
        <select class="input sm" name="format" aria-label="변환 포맷">
          <option value="mp4">MP4 (H.264)</option>
          <option value="webm">WebM</option>
          <option value="gif">GIF</option>
        </select>
        <label class="switch small"><input type="checkbox" name="delete_source" value="1"> <span>변환 후 원본 삭제</span></label>
        <button class="btn sm secondary" type="submit" id="btnBulkConvert" formaction="<?= site_url('library/convert') ?>" disabled>일괄 변환</button>
      </span>
      <button class="btn sm secondary" type="button" id="btnCancelSelect">취소</button>
      <button class="btn sm danger" type="button" id="btnBulkDelete" disabled>선택 삭제</button>
    </div>

    <div class="grid" id="grid">
      <?php foreach ($items as $it): ?>
        <?= view('library/_card', ['it' => $it, 'postSize' => $sizes[$it['post_key']] ?? 1]) ?>
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
</div>
<?= view('xwatch/side', ['xw' => $xwatch]) ?>
</main>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
// X watch sidebar: refreshed every second while the tab is visible (one request at a time,
// and the DOM is swapped only when the content changed, keeping the post list's scroll)
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
  }, 1000);
})();

(function () {
  const $ = (id) => document.getElementById(id);
  const csrf = MV.csrf();
  const base = <?= json_encode(site_url('/')) ?>;
  const hdr = { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf ? csrf.hash : '' };
  const grid = $('grid'), list = $('progressList');
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  /* ---------------- chunked upload ---------------- */
  // files are dropped on the link panel; it lights up only while files are dragged over it
  const dz = $('importPanel'), input = $('fileInput');
  $('pickBtn').addEventListener('click', () => input.click());
  input.addEventListener('change', () => { queue([...input.files]); input.value = ''; });
  const hasFiles = (e) => [...(e.dataTransfer?.types || [])].includes('Files');
  let depth = 0;
  dz.addEventListener('dragenter', e => { if (!hasFiles(e)) return; e.preventDefault(); depth++; dz.classList.add('over'); });
  dz.addEventListener('dragover', e => { if (hasFiles(e)) e.preventDefault(); });
  dz.addEventListener('dragleave', () => { if (--depth <= 0) { depth = 0; dz.classList.remove('over'); } });
  dz.addEventListener('drop', e => {
    if (!hasFiles(e)) return;
    e.preventDefault(); depth = 0; dz.classList.remove('over');
    queue([...e.dataTransfer.files]);
  });
  // a file dropped anywhere else must not make the browser open it
  ['dragover', 'drop'].forEach(ev => window.addEventListener(ev, e => { if (hasFiles(e) && !dz.contains(e.target)) e.preventDefault(); }));

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
    if (MV.expired(res.status, j)) throw new Error(MV.httpError(res.status));
    if (!res.ok || j.error) throw new Error(j.error || MV.httpError(res.status));
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
        MV.expired(xhr.status, j);
        (xhr.status === 200 && j.ok) ? resolve(j) : reject(new Error(j.error || MV.httpError(xhr.status)));
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

  /* ---------------- job queue (edit / convert) ---------------- */
  const jq = { rows: new Map(), timer: null };
  const ownImports = new Set();
  const jqParams = (j) => { try { return JSON.parse(j.params || '{}'); } catch (e) { return {}; } };
  const jqLabel = (j) => {
    const p = jqParams(j);
    if (j.type === 'import') return '가져오기' + (p.platform ? ' ' + String(p.platform).toUpperCase() : '');
    if (j.type === 'convert') return '변환 ' + String(p.format || '').toUpperCase() + (p.delete_source ? ' · 원본 삭제' : '');
    return '편집';
  };
  function jqRow(j) {
    let el = jq.rows.get(j.id);
    if (!el) {
      el = document.createElement('div'); el.className = 'progress-item';
      el.innerHTML = '<div><span class="name"></span> <span class="muted small kind"></span> <span class="muted small pct"></span> <span class="jq-act"></span></div><div class="bar"><i></i></div>';
      const p = jqParams(j);
      el.querySelector('.name').textContent = j.title || p.title || p.url || ('미디어 ' + j.media_id);
      el.querySelector('.kind').textContent = '· ' + jqLabel(j);
      $('jqList').append(el); jq.rows.set(j.id, el);
    }
    return el;
  }
  // queued / running row: 중단 (a cancelled row then comes back as failed, with 재시도 / 삭제)
  function jqCancelButton(el, id, status) {
    const act = el.querySelector('.jq-act');
    if (status === 'cancelled') { if (!el.dataset.cancelUi) { el.dataset.cancelUi = '1'; jqFailed(el, id, '', '중단 중...'); } return; }
    if (act.querySelector('.jq-cancel')) return;
    act.innerHTML = '<button type="button" class="linkbtn jq-cancel">중단</button>';
    act.querySelector('.jq-cancel').addEventListener('click', async (e) => {
      e.preventDefault();
      if (!confirm('이 작업을 중단할까요?')) return;
      e.target.disabled = true;
      try {
        const r = await fetch(base + 'api/jobs/' + id + '/cancel', { method: 'POST', headers: hdr });
        if (!r.ok) throw new Error('HTTP ' + r.status);
        const j = await r.json();
        if (j.removed) { el.remove(); jq.rows.delete(id); }
        else { el.querySelector('.pct').textContent = '중단 중...'; act.innerHTML = ''; }
        clearTimeout(jq.timer); jqPoll();
      } catch (err) { e.target.disabled = false; alert('중단하지 못했습니다: ' + err.message); }
    });
  }
  // failed row: message plus 재시도 / 삭제
  function jqFailed(el, id, error, label) {
    el.classList.add('fail');
    el.querySelector('.pct').textContent = (label || '실패') + (error ? ': ' + String(error).slice(0, 160) : '');
    const act = el.querySelector('.jq-act');
    act.innerHTML = '<button type="button" class="linkbtn jq-retry">재시도</button> <button type="button" class="linkbtn jq-dismiss">삭제</button>';
    const call = async (what) => {
      act.querySelectorAll('button').forEach(b => b.disabled = true);
      try {
        const r = await fetch(base + 'api/jobs/' + id + '/' + what, { method: 'POST', headers: hdr });
        if (!r.ok) throw new Error('HTTP ' + r.status);
        el.remove(); jq.rows.delete(id);
        if (what === 'retry') { clearTimeout(jq.timer); jqPoll(); }
      } catch (e) { act.querySelectorAll('button').forEach(b => b.disabled = false); alert('처리하지 못했습니다: ' + e.message); }
    };
    act.querySelector('.jq-retry').addEventListener('click', () => call('retry'));
    act.querySelector('.jq-dismiss').addEventListener('click', () => call('dismiss'));
  }
  async function jqPoll() {
    let active = [];
    try {
      const r = await fetch(base + 'api/jobs?active=1', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      if (r.ok) active = (await r.json()).jobs || [];
    } catch (e) {}
    active = active.filter(j => !ownImports.has(+j.id));
    for (const id of ownImports) { const el = jq.rows.get(id); if (el) { el.remove(); jq.rows.delete(id); } }
    const ids = new Set(active.map(j => +j.id));
    const live = active.filter(j => j.status !== 'failed');
    live.forEach((j, i) => {
      const el = jqRow({ ...j, id: +j.id });
      const running = j.status === 'running';
      el.querySelector('.pct').textContent = j.status === 'cancelled' ? '중단 중...' : running ? (+j.progress || 0) + '%' : '대기 ' + (i + 1) + '번째';
      el.querySelector('.bar i').style.width = (running ? +j.progress || 0 : 0) + '%';
      jqCancelButton(el, +j.id, j.status);
    });
    // failed jobs stay until retried or dismissed (also after a reload)
    active.filter(j => j.status === 'failed').forEach(j => {
      const el = jqRow({ ...j, id: +j.id });
      if (el.dataset.end) return;
      el.dataset.end = '1';
      jqFailed(el, +j.id, j.error);
    });
    // rows that left the active list have finished or failed
    for (const [id, el] of jq.rows) {
      if (ids.has(id) || el.dataset.end) continue;
      el.dataset.end = '1';
      el.querySelector('.jq-act').innerHTML = '';
      try {
        const j = (await (await fetch(base + 'api/jobs/' + id, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })).json()).job || {};
        if (j.status === 'done') {
          el.classList.add('done'); el.querySelector('.bar i').style.width = '100%';
          if (j.type === 'import' && j.result_media_id && !grid.querySelector('a.card[href$="/library/' + (+j.result_media_id) + '"]')) {
            try {
              const m = (await (await fetch(base + 'api/media/' + (+j.result_media_id), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })).json()).media;
              if (m) { addCard(m); el.querySelector('.name').textContent = m.title; }
            } catch (e) {}
          }
          el.querySelector('.pct').innerHTML = '완료' + (j.result_media_id ? ' · <a href="' + base + 'library/' + (+j.result_media_id) + '">결과 보기</a>' : '');
        } else {
          jqFailed(el, id, j.error);
        }
      } catch (e) {}
      $('jqReload').hidden = false;
    }
    $('jobQueue').hidden = jq.rows.size === 0;
    const run = live.filter(j => j.status === 'running').length;
    const failed = active.length - live.length;
    $('jqCount').textContent = (live.length ? '진행 ' + run + ' · 대기 ' + (live.length - run) : '모두 끝났습니다') + (failed ? ' · 실패 ' + failed : '');
    clearTimeout(jq.timer);
    jq.timer = setTimeout(jqPoll, live.length ? 2000 : (document.hidden ? 15000 : 4000));
  }
  $('jqReload').addEventListener('click', () => location.reload());
  jqPoll();

  /* ---------------- hover preview (YouTube style) ---------------- */
  // after a short hover the thumbnail turns into the muted video; leaving restores the image
  const canHover = window.matchMedia('(hover: hover)').matches;
  if (canHover) {
    let timer = null;
    grid.addEventListener('mouseover', (e) => {
      const th = e.target.closest('.thumb[data-preview]');
      if (!th || th.dataset.playing || grid.classList.contains('picking')) return;
      clearTimeout(timer);
      timer = setTimeout(() => {
        if (!th.matches(':hover')) return;
        const v = document.createElement('video');
        v.className = 'preview'; v.muted = true; v.loop = true; v.playsInline = true; v.preload = 'auto';
        v.src = th.dataset.preview;
        v.addEventListener('playing', () => th.classList.add('previewing'), { once: true });
        th.appendChild(v); th.dataset.playing = '1';
        v.play().catch(() => {});
      }, 350);
    });
    grid.addEventListener('mouseout', (e) => {
      const th = e.target.closest('.thumb[data-preview]');
      if (!th || th.contains(e.relatedTarget)) return;
      clearTimeout(timer);
      const v = th.querySelector('video.preview');
      if (v) { v.pause(); v.removeAttribute('src'); v.load(); v.remove(); }
      th.classList.remove('previewing'); delete th.dataset.playing;
    });
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
    $('btnBulkCat').disabled = !n;
    $('btnBulkConvert').disabled = !n;
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
      if (t) { el.value = t; syncClear(); istatus(''); el.focus(); return; }
      istatus('클립보드가 비어 있습니다.', 'error');
    } catch (e) {
      el.focus();
      istatus('브라우저가 붙여넣기를 막았습니다. 입력창에서 Ctrl+V를 눌러 주세요.', 'error');
    }
  });
  $('btnClearUrl').addEventListener('click', () => {
    $('importUrl').value = ''; syncClear(); $('importUrl').focus();
    il.hidden = ia.hidden = true; il.innerHTML = ''; inspected = null; istatus('');
  });
  const syncClear = () => { $('btnClearUrl').hidden = !$('importUrl').value; };
  $('importUrl').addEventListener('input', syncClear);
  syncClear();
  $('importUrl').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); inspect(); } });
  // Asks before importing a post that is already in the library; resolves true to go on.
  async function confirmDuplicate(url) {
    let items = [];
    try { items = (await post(base + 'api/import/duplicates', { url })).items || []; } catch (e) { return true; }
    if (!items.length) return true;
    return new Promise((resolve) => {
      st.hidden = false; st.className = 'import-status warn';
      st.innerHTML = '이미 라이브러리에 있는 링크입니다: ' +
        items.slice(0, 3).map(i => '<a href="' + base + 'library/' + i.id + '" target="_blank">' + esc(i.title) + '</a> <span class="muted">(' + esc(i.created_at) + ')</span>').join(', ') +
        (items.length > 3 ? ' 외 ' + (items.length - 3) + '개' : '') +
        '<div class="dup-actions"><button class="btn sm" type="button" data-go="1">그래도 가져오기</button> <button class="btn sm secondary" type="button" data-go="0">취소</button></div>';
      st.querySelectorAll('[data-go]').forEach(btn => btn.addEventListener('click', () => {
        const go = btn.dataset.go === '1';
        if (!go) istatus('가져오기를 취소했습니다.');
        resolve(go);
      }, { once: true }));
    });
  }
  async function inspect() {
    const url = $('importUrl').value.trim(); if (!url) return;
    $('btnInspect').disabled = true;
    const go = await confirmDuplicate(url);
    $('btnInspect').disabled = false;
    if (!go) return;
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
          (e.width ? ' · ' + e.width + ' × ' + e.height : '') + '</div></div>';
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
  async function submitImport(ins, items, xReady = false) {
    const titles = {}, images = {}, media = {}, audio = {};
    ins.entries.forEach(e => {
      titles[e.index] = e.title;
      if (e.image_url) images[e.index] = e.image_url;
      else if (e.media_url) { media[e.index] = e.media_url; if (e.audio_url) audio[e.index] = e.audio_url; }
    });
    istatus('가져오기 요청 중...');
    const j = await post(base + 'api/import', { url: ins.url, items, titles, images, media, audio, desc: ins.desc, post: ins.post, x_ready: xReady });
    il.hidden = ia.hidden = true;
    istatus(j.job_ids.length + '개 항목을 서버에서 내려받는 중입니다.');
    j.job_ids.forEach(id => pollImport(id));
  }
  $('btnImport').addEventListener('click', async () => {
    const items = [...il.querySelectorAll('.import-item.on')].map(x => +x.dataset.index);
    if (!items.length || !inspected) return;
    $('btnImport').disabled = true;
    try { await submitImport(inspected, items); } catch (e) { istatus(e.message, 'error'); $('btnImport').disabled = false; }
  });
  // one click: look the link up and import every video in it, skipping images
  $('btnQuick').addEventListener('click', async () => {
    const url = $('importUrl').value.trim(); if (!url) return;
    const b = $('btnQuick'); b.disabled = $('btnInspect').disabled = true;
    if (!(await confirmDuplicate(url))) { b.disabled = $('btnInspect').disabled = false; return; }
    il.hidden = ia.hidden = true; il.innerHTML = ''; inspected = null;
    istatus('영상을 찾는 중... (몇 초 걸립니다)');
    try {
      const j = await post(base + 'api/import/inspect', { url });
      const ins = { url, entries: j.entries || [], desc: j.desc || '', post: j.post || null };
      const items = ins.entries.filter(e => e.kind !== 'image').map(e => e.index);
      if (!items.length) throw new Error('이 링크에는 가져올 영상이 없습니다.' + (ins.entries.length ? ' (이미지 ' + ins.entries.length + '개는 제외)' : ''));
      await submitImport(ins, items, true);
    } catch (e) { istatus(e.message, 'error'); }
    b.disabled = $('btnInspect').disabled = false;
  });
  let pending = 0;
  function pollImport(id) {
    ownImports.add(+id);
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
        else { row.set(Math.max(5, job.progress), job.status === 'queued' ? '대기 중' : (job.progress >= 60 ? 'MP4로 변환 중...' : '다운로드 중...')); setTimeout(tick, 2000); }
      } catch (e) { row.fail(e.message); pending--; }
    };
    setTimeout(tick, 1500);
  }
})();
</script>
<?= $this->endSection() ?>
