<?= $this->extend('layouts/main') ?>
<?= $this->section('head') ?>
<style>
body { background: var(--bg-grouped); }
main.wide.settings.keywords { max-width: 720px; padding-top: 84px; }
.kw-head { margin: 0 0 16px; }
.kw-head h1 { margin: 0; font-size: 34px; letter-spacing: -0.022em; }
.kw-head p { margin: 4px 0 0; color: var(--label2); font-size: 14px; }
.kw-search { position: relative; margin: 0 0 12px; }
.kw-search input { width: 100%; height: 38px; border: 0; border-radius: 12px; background: var(--fill1); padding: 0 38px 0 36px; font: inherit; font-size: 16px; color: var(--label); outline: none; -webkit-appearance: none; appearance: none; transition: background .15s, box-shadow .15s; }
.kw-search input::placeholder { color: var(--label3); }
.kw-search input::-webkit-search-cancel-button { display: none; }
.kw-search input:focus { background: var(--bg-elev); box-shadow: 0 0 0 3.5px color-mix(in srgb, var(--blue) 18%, transparent), var(--shadow-sm); }
.kw-search .ico { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--label3); pointer-events: none; display: flex; }
.kw-search .clear { position: absolute; right: 9px; top: 50%; transform: translateY(-50%); width: 20px; height: 20px; border: 0; border-radius: 50%; background: var(--label3); color: var(--bg-elev); font-size: 14px; line-height: 1; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; padding: 0; }
.kw-search .clear:hover { background: var(--label2); }
.kw-bar { display: flex; align-items: center; gap: 8px; margin: 0 0 18px; flex-wrap: wrap; }
.kw-bar .spacer { flex: 1; }
.kw-pill { height: 34px; padding: 0 14px 0 10px; border: 0; border-radius: 17px; background: var(--blue-tint); color: var(--blue); font: inherit; font-size: 14px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; transition: background .15s; }
.kw-pill:hover { background: color-mix(in srgb, var(--blue) 18%, transparent); }
.kw-pill:active { transform: scale(0.97); }
.kw-seg { display: inline-flex; background: var(--fill1); border-radius: 9px; padding: 2px; }
.kw-seg button { border: 0; background: transparent; height: 28px; padding: 0 12px; border-radius: 7px; font: inherit; font-size: 13px; font-weight: 500; color: var(--label); cursor: pointer; display: inline-flex; align-items: center; gap: 5px; transition: background .15s, box-shadow .15s; }
.kw-seg button:hover { background: var(--bg-elev); box-shadow: var(--shadow-sm); }
.kw-ib { width: 32px; height: 32px; border: 0; border-radius: 50%; background: var(--fill1); color: var(--label2); cursor: pointer; display: inline-flex; align-items: center; justify-content: center; transition: background .15s, color .15s; }
.kw-ib:hover { background: var(--fill2); color: var(--label); }
.kw-tree { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 12px; }
.kw-card { background: var(--bg-elev); border-radius: 16px; box-shadow: var(--shadow-sm); overflow: hidden; }
.kw-row { display: flex; align-items: center; gap: 4px; min-height: 46px; padding: 4px 8px 4px 0; }
.kw-card > .kw-row { min-height: 54px; padding-left: 18px; }
.kw-members { list-style: none; margin: 0 0 0 18px; padding: 0 0 4px; border-top: 1px solid var(--separator); }
.kw-members li + li { border-top: 1px solid var(--separator); }
.kw-members .kw-row { padding-left: 14px; }
.kw-name { flex: 1; min-width: 0; text-align: left; border: 0; background: transparent; color: var(--label); font: inherit; font-size: 15px; padding: 8px 10px 8px 0; cursor: pointer; white-space: pre-wrap; overflow-wrap: anywhere; line-height: 1.4; transition: color .12s, opacity .12s; -webkit-tap-highlight-color: transparent; }
.kw-card > .kw-row .kw-name { font-size: 17px; font-weight: 600; letter-spacing: -0.012em; }
.kw-name:hover { color: var(--blue); }
.kw-name:active { opacity: 0.45; }
.kw-name mark { background: color-mix(in srgb, #ffd60a 50%, transparent); color: inherit; border-radius: 3px; padding: 0 1px; }
.kw-count { display: inline-block; vertical-align: 2px; font-weight: 600; font-size: 12px; color: var(--label2); background: var(--fill1); border-radius: 10px; padding: 2px 8px; margin-left: 8px; letter-spacing: 0; }
.kw-acts { display: flex; flex: none; opacity: 0.5; transition: opacity .15s; }
.kw-row:hover .kw-acts, .kw-row:focus-within .kw-acts { opacity: 1; }
.kw-acts button { width: 32px; height: 32px; border: 0; border-radius: 50%; background: transparent; color: var(--label2); cursor: pointer; display: inline-flex; align-items: center; justify-content: center; transition: background .15s, color .15s; }
.kw-acts button:hover { background: var(--fill1); color: var(--label); }
.kw-acts button.del:hover { background: var(--red-tint); color: var(--red); }
.kw-tog { flex: none; width: 32px; height: 32px; border: 0; border-radius: 50%; background: transparent; color: var(--label3); cursor: pointer; display: inline-flex; align-items: center; justify-content: center; transition: transform .22s cubic-bezier(.2, .8, .2, 1), background .15s, color .15s; }
.kw-tog:hover { background: var(--fill1); color: var(--label); }
.kw-tog.open { transform: rotate(90deg); }
.kw-tog.none { visibility: hidden; }
.kw-copied { flex: none; font-size: 12px; font-weight: 600; color: var(--green); background: color-mix(in srgb, var(--green) 14%, transparent); border-radius: 10px; padding: 3px 9px; margin-right: 4px; animation: kw-fade 1.4s ease forwards; }
@keyframes kw-fade { 0% { opacity: 0; transform: translateX(4px); } 12%, 72% { opacity: 1; transform: none; } 100% { opacity: 0; } }
.kw-edit { display: flex; align-items: flex-start; gap: 8px; flex: 1; min-width: 0; padding: 8px 0; }
.kw-edit textarea { flex: 1; min-width: 0; min-height: 38px; border: 0; border-radius: 10px; background: var(--fill1); padding: 8px 12px; font: inherit; font-size: 15px; line-height: 1.4; color: var(--label); resize: none; overflow: hidden; outline: none; }
.kw-edit textarea:focus { box-shadow: 0 0 0 3.5px color-mix(in srgb, var(--blue) 18%, transparent); background: var(--bg-elev); }
.kw-edit .btns { display: flex; gap: 6px; flex: none; }
.kw-edit .btns button { height: 34px; padding: 0 14px; border: 0; border-radius: 17px; font: inherit; font-size: 14px; font-weight: 600; cursor: pointer; }
.kw-edit .btns .ok { background: var(--blue); color: #fff; }
.kw-edit .btns .ok:hover { background: var(--blue-hover); }
.kw-edit .btns .no { background: var(--fill1); color: var(--label); }
.kw-edit .btns .no:hover { background: var(--fill2); }
.kw-edit .btns button:disabled { opacity: 0.4; }
/* reference photos: 500x500 crops, shown only while the toggle is on */
.kw-row { flex-wrap: wrap; }
.kw-picwrap { flex: 1 0 100%; padding: 10px 0 4px; }
.kw-members .kw-picwrap { padding-left: 0; }
.kw-pic { display: block; width: 320px; max-width: 100%; aspect-ratio: 1 / 1; border: 0; padding: 0; border-radius: 14px; background: var(--fill1); cursor: zoom-in; overflow: hidden; }
.kw-pic img { width: 100%; height: 100%; object-fit: cover; display: block; transition: opacity .15s; }
.kw-pic:hover img { opacity: 0.9; }
.kw-acts button.cam.has { color: var(--blue); }
.kw-ib.on { background: var(--blue); color: #fff; }
.kw-modal { position: fixed; inset: 0; z-index: 1000; background: rgba(0,0,0,.72); display: flex; align-items: center; justify-content: center; padding: 16px; }
.kw-modal .box { background: var(--bg-elev); border-radius: 18px; box-shadow: var(--shadow-lg, 0 20px 60px rgba(0,0,0,.4)); max-width: min(92vw, 560px); max-height: 92vh; display: flex; flex-direction: column; overflow: hidden; }
.kw-modal .box.view { background: transparent; box-shadow: none; max-width: 96vw; max-height: 96vh; align-items: center; }
.kw-modal .view img { max-width: 96vw; max-height: calc(96vh - 60px); object-fit: contain; border-radius: 10px; background: #000; }
.kw-modal .bar { display: flex; gap: 8px; justify-content: center; align-items: center; padding: 12px 14px; flex-wrap: wrap; }
.kw-modal .bar button { height: 34px; padding: 0 14px; border: 0; border-radius: 17px; font: inherit; font-size: 14px; font-weight: 600; cursor: pointer; background: var(--fill1); color: var(--label); }
.kw-modal .view .bar button { background: rgba(255,255,255,.14); color: #fff; }
.kw-modal .bar button.ok { background: var(--blue); color: #fff; }
.kw-modal .bar button.del { color: var(--red); }
.kw-modal .bar button:disabled { opacity: .4; }
.kw-crop { position: relative; width: min(80vw, 360px); height: min(80vw, 360px); margin: 16px auto 0; overflow: hidden; background: #111; border-radius: 8px; touch-action: none; cursor: grab; user-select: none; }
.kw-crop img { position: absolute; left: 0; top: 0; transform-origin: 0 0; pointer-events: none; max-width: none; }
.kw-crop::after { content: ""; position: absolute; inset: 0; box-shadow: inset 0 0 0 1px rgba(255,255,255,.5); pointer-events: none; }
.kw-crop-zoom { display: flex; align-items: center; gap: 10px; padding: 12px 20px 0; font-size: 13px; color: var(--label2); }
.kw-crop-zoom input { flex: 1; accent-color: var(--blue); }
.kw-crop-hint { text-align: center; font-size: 12px; color: var(--label3); margin: 8px 0 0; }
.kw-empty, .kw-nomatch { background: var(--bg-elev); border-radius: 16px; box-shadow: var(--shadow-sm); text-align: center; padding: 40px 16px; color: var(--label2); font-size: 15px; }
.kw-empty p { margin: 0 0 16px; }
.kw-hint { font-size: 13px; color: var(--label3); margin: 16px 4px 0; line-height: 1.5; }
@media (max-width: 520px) {
  main.wide.settings.keywords { padding-left: 14px; padding-right: 14px; }
  .kw-head h1 { font-size: 30px; }
  .kw-card > .kw-row { padding-left: 14px; }
  .kw-members { margin-left: 14px; }
  .kw-members .kw-row { padding-left: 10px; }
  .kw-acts { opacity: 0.7; }
}
</style>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<main class="wide settings keywords">
  <div class="kw-head">
    <h1>키워드</h1>
    <p>키워드를 누르면 클립보드에 복사됩니다. X 게시물에 붙여 넣으세요.</p>
  </div>
  <?= view('partials/alerts') ?>

  <div class="kw-search">
    <span class="ico"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg></span>
    <input type="search" id="kwSearch" placeholder="검색" autocomplete="off" aria-label="키워드 검색">
    <button class="clear" type="button" id="kwSearchClear" title="지우기" aria-label="지우기" hidden>&times;</button>
  </div>
  <div class="kw-bar">
    <button class="kw-pill" type="button" id="kwAddTop"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>상위 키워드</button>
    <div class="kw-seg" role="group" aria-label="정렬">
      <button type="button" id="kwSortAsc" title="이름 오름차순 (상위, 그리고 각 그룹 안의 하위)">A→Z</button>
      <button type="button" id="kwSortDesc" title="이름 내림차순 (상위, 그리고 각 그룹 안의 하위)">Z→A</button>
    </div>
    <span class="spacer"></span>
    <button class="kw-ib" type="button" id="kwImgToggle" title="사진 표시 (얼굴 확인용)" aria-label="사진 표시"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="2"/><path d="M21 16l-5-5-8 8"/></svg></button>
    <button class="kw-ib" type="button" id="kwExpand" title="전체 펼치기" aria-label="전체 펼치기"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M7 9l5-5 5 5M7 15l5 5 5-5"/></svg></button>
    <button class="kw-ib" type="button" id="kwCollapse" title="전체 접기" aria-label="전체 접기"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M7 4l5 5 5-5M7 20l5-5 5 5"/></svg></button>
  </div>

  <ul class="kw-tree" id="kwTree"></ul>
  <div class="kw-nomatch" id="kwNoMatch" hidden>검색 결과가 없습니다.</div>
  <div class="kw-empty" id="kwEmpty" hidden>
    <p>아직 키워드가 없습니다.</p>
    <button class="kw-pill" type="button" id="kwSeed">기본 키워드 불러오기</button>
  </div>
  <input type="file" id="kwFile" accept="image/jpeg,image/png,image/gif,image/webp" hidden>
  <p class="kw-hint">상위 키워드의 + 는 그 아래에 멤버 키워드를 추가합니다. 연필은 수정, 휴지통은 삭제(상위를 지우면 하위도 함께 삭제). 키워드는 여러 줄로 쓸 수 있습니다(Enter 줄바꿈, Ctrl+Enter 저장).</p>
</main>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?>
<script>
(function () {
  const $ = (id) => document.getElementById(id);
  const base = <?= json_encode(site_url('api/keywords')) ?>;
  const csrf = MV.csrf();
  const hdr = { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf ? csrf.hash : '' };
  let tree = <?= json_encode($tree, JSON_UNESCAPED_UNICODE) ?>;
  let editing = null;   // { kind: 'edit'|'add', id, parentId }
  let query = '';       // search text, lower-cased
  const hit = (n) => query !== '' && n.name.toLowerCase().includes(query);
  // root collation, like Windows Explorer's name order: symbols, numbers (natural), A-Z, then Hangul, kana, CJK
  const coll = new Intl.Collator('en', { sensitivity: 'base', numeric: true });
  const sortList = (list, dir) => list.sort((a, b) => dir * coll.compare(a.name, b.name));
  // highlighted name: the query's matches wrapped in <mark>
  const nameHtml = (text) => {
    if (query === '') return document.createTextNode(text);
    const frag = document.createDocumentFragment(), low = text.toLowerCase(); let i = 0, j;
    while ((j = low.indexOf(query, i)) !== -1) {
      if (j > i) frag.appendChild(document.createTextNode(text.slice(i, j)));
      const m = document.createElement('mark'); m.textContent = text.slice(j, j + query.length); frag.appendChild(m); i = j + query.length;
    }
    if (i < text.length) frag.appendChild(document.createTextNode(text.slice(i)));
    return frag;
  };
  const treeEl = $('kwTree');
  const imgBase = <?= json_encode(site_url('keywords')) ?>;
  let showImg = false; try { showImg = localStorage.getItem('mvcut.kwimg') === '1'; } catch (e) {}
  const picUrl = (node, kind) => imgBase + '/' + node.id + '/image/' + kind + '?v=' + (node.imgv || 0);

  async function api(path, body) {
    const res = await fetch(base + path, { method: 'POST', headers: hdr, body: JSON.stringify(body || {}) });
    let j = {}; try { j = await res.json(); } catch (e) {}
    if (MV.expired(res.status, j)) throw new Error(MV.httpError(res.status));
    if (!res.ok || !j.ok) throw new Error(j.error || MV.httpError(res.status));
    return j;
  }

  async function copy(text, row) {
    try {
      if (navigator.clipboard && window.isSecureContext) await navigator.clipboard.writeText(text);
      else {
        const ta = document.createElement('textarea'); ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
        document.body.appendChild(ta); ta.focus(); ta.select(); document.execCommand('copy'); ta.remove();
      }
    } catch (e) { alert('복사하지 못했습니다.'); return; }
    row.querySelectorAll('.kw-copied').forEach((n) => n.remove());
    const tag = document.createElement('span'); tag.className = 'kw-copied'; tag.textContent = '복사됨';
    row.querySelector('.kw-name').after(tag);
    tag.addEventListener('animationend', () => tag.remove());
  }

  const icon = (cls, title, svg) => {
    const b = document.createElement('button'); b.type = 'button'; b.className = cls; b.title = title; b.setAttribute('aria-label', title); b.innerHTML = svg; return b;
  };
  const SVG = {
    pen: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>',
    plus: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>',
    trash: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/></svg>',
    sort: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h16M4 12h10M4 18h5"/><path d="M19 12v8M16 17l3 3 3-3"/></svg>',
    chev: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5l7 7-7 7"/></svg>',
    cam: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8h3l2-3h6l2 3h3v11H4z"/><circle cx="12" cy="13" r="3.5"/></svg>',
  };

  /* ---------- reference photos ---------- */
  function modal(cls) {
    const m = document.createElement('div'); m.className = 'kw-modal';
    const box = document.createElement('div'); box.className = 'box ' + cls; m.appendChild(box);
    const close = () => { m.remove(); document.removeEventListener('keydown', onKey); };
    const onKey = (e) => { if (e.key === 'Escape') close(); };
    m.addEventListener('click', (e) => { if (e.target === m) close(); });
    document.addEventListener('keydown', onKey);
    document.body.appendChild(m);
    return { m, box, close };
  }
  const btn = (label, cls, fn) => { const b = document.createElement('button'); b.type = 'button'; b.textContent = label; if (cls) b.className = cls; b.addEventListener('click', fn); return b; };

  // original on click, with re-crop / replace / delete
  function viewImage(node) {
    const { box, close } = modal('view');
    const im = document.createElement('img'); im.src = picUrl(node, 'orig'); im.alt = node.name;
    im.addEventListener('click', close);
    const bar = document.createElement('div'); bar.className = 'bar';
    bar.append(
      btn('다시 자르기', '', () => { close(); cropImage(node, picUrl(node, 'orig'), null, node.crop); }),
      btn('사진 바꾸기', '', () => { close(); pickImage(node); }),
      btn('삭제', 'del', async () => {
        if (!confirm('이 사진을 삭제할까요?')) return;
        try { await api('/' + node.id + '/image/delete'); node.image = false; node.crop = null; close(); render(); } catch (e) { alert(e.message); }
      }),
      btn('닫기', '', close));
    box.append(im, bar);
  }

  let pickFor = null;
  function pickImage(node) { pickFor = node; $('kwFile').value = ''; $('kwFile').click(); }
  $('kwFile').addEventListener('change', () => {
    const f = $('kwFile').files[0], node = pickFor; pickFor = null;
    if (!f || !node) return;
    if (f.size > 20 * 1024 * 1024) { alert('이미지는 20MB 이하만 올릴 수 있습니다.'); return; }
    cropImage(node, URL.createObjectURL(f), f, null);
  });

  // the photo moves under a fixed square (drag) and zooms (slider); the square is the 500x500 thumb
  function cropImage(node, src, file, initial) {
    const { box, close } = modal('');
    const vp = document.createElement('div'); vp.className = 'kw-crop';
    const im = document.createElement('img'); im.src = src; im.alt = ''; im.draggable = false; vp.appendChild(im);
    const zoomRow = document.createElement('div'); zoomRow.className = 'kw-crop-zoom';
    zoomRow.innerHTML = '<span>확대</span><input type="range" min="1" max="4" step="0.01" value="1">';
    const zoom = zoomRow.querySelector('input');
    const hint = document.createElement('p'); hint.className = 'kw-crop-hint'; hint.textContent = '사진을 끌어서 얼굴을 네모 안에 맞추세요. 네모 안이 1:1 실제 크기로 저장됩니다';
    const bar = document.createElement('div'); bar.className = 'bar';
    const ok = btn('저장', 'ok', () => save()); const no = btn('취소', '', close);
    bar.append(ok, no);
    box.append(vp, zoomRow, hint, bar);

    let nw = 0, nh = 0, s0 = 1, s = 1, ox = 0, oy = 0, V = 0;
    const clamp = () => { V = vp.clientWidth; ox = Math.min(0, Math.max(V - nw * s, ox)); oy = Math.min(0, Math.max(V - nh * s, oy)); };
    const draw = () => { clamp(); im.style.transform = 'translate(' + ox + 'px,' + oy + 'px) scale(' + s + ')'; };
    im.onload = () => {
      nw = im.naturalWidth; nh = im.naturalHeight; V = vp.clientWidth;
      s0 = Math.max(V / nw, V / nh);
      if (initial && initial.w) {      // reopen on the crop in use
        s = V / initial.w; zoom.value = Math.min(4, Math.max(1, s / s0)); s = s0 * zoom.value;
        ox = -initial.x * s; oy = -initial.y * s;
      } else { s = s0; ox = (V - nw * s) / 2; oy = (V - nh * s) / 2; }
      draw();
    };
    im.onerror = () => { alert('이미지를 열지 못했습니다.'); close(); };
    zoom.addEventListener('input', () => {
      const ns = s0 * parseFloat(zoom.value), c = V / 2;    // zoom around the centre of the square
      ox = c - (c - ox) * ns / s; oy = c - (c - oy) * ns / s; s = ns; draw();
    });
    let drag = null;
    vp.addEventListener('pointerdown', (e) => { drag = { x: e.clientX - ox, y: e.clientY - oy }; vp.setPointerCapture(e.pointerId); });
    vp.addEventListener('pointermove', (e) => { if (!drag) return; ox = e.clientX - drag.x; oy = e.clientY - drag.y; draw(); });
    vp.addEventListener('pointerup', () => { drag = null; }); vp.addEventListener('pointercancel', () => { drag = null; });
    vp.addEventListener('wheel', (e) => { e.preventDefault(); zoom.value = Math.min(4, Math.max(1, parseFloat(zoom.value) * (e.deltaY < 0 ? 1.08 : 0.93))); zoom.dispatchEvent(new Event('input')); }, { passive: false });

    async function save() {
      if (!nw) return;
      const crop = { x: Math.round(-ox / s), y: Math.round(-oy / s), w: Math.round(V / s), h: Math.round(V / s) };
      ok.disabled = no.disabled = true; ok.textContent = '저장 중...';
      try {
        let j;
        if (file) {
          const fd = new FormData(); fd.append('file', file); fd.append('crop', JSON.stringify(crop));
          const res = await fetch(base + '/' + node.id + '/image', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf ? csrf.hash : '' }, body: fd });
          try { j = await res.json(); } catch (e) { j = {}; }
          if (MV.expired(res.status, j)) return;
          if (!res.ok || !j.ok) throw new Error(j.error || MV.httpError(res.status));
        } else {
          j = await api('/' + node.id + '/image/crop', crop);
        }
        node.image = true; node.imgv = j.imgv; node.crop = j.crop;
        if (file) URL.revokeObjectURL(src);
        if (!showImg) { showImg = true; try { localStorage.setItem('mvcut.kwimg', '1'); } catch (e) {} paintToggle(); }
        close(); render();
      } catch (e) { alert(e.message); ok.disabled = no.disabled = false; ok.textContent = '저장'; }
    }
  }

  const imgToggle = $('kwImgToggle');
  function paintToggle() { imgToggle.classList.toggle('on', showImg); imgToggle.title = showImg ? '사진 숨기기' : '사진 표시 (얼굴 확인용)'; }
  imgToggle.addEventListener('click', () => { showImg = !showImg; try { localStorage.setItem('mvcut.kwimg', showImg ? '1' : '0'); } catch (e) {} paintToggle(); render(); });
  paintToggle();

  function editor(value, onSave, onCancel) {
    const wrap = document.createElement('div'); wrap.className = 'kw-edit';
    const inp = document.createElement('textarea'); inp.value = value; inp.maxLength = <?= \App\Models\KeywordModel::MAX ?>; inp.rows = 1; inp.placeholder = '키워드 (여러 줄 가능, Ctrl+Enter 저장)';
    const grow = () => { inp.style.height = 'auto'; inp.style.height = inp.scrollHeight + 'px'; };
    inp.addEventListener('input', grow);
    const ok = document.createElement('button'); ok.type = 'button'; ok.className = 'ok'; ok.textContent = '저장';
    const no = document.createElement('button'); no.type = 'button'; no.className = 'no'; no.textContent = '취소';
    const save = async () => {
      const v = inp.value.trim(); if (!v) { inp.focus(); return; }
      ok.disabled = no.disabled = true;
      try { await onSave(v); } catch (e) { alert(e.message); ok.disabled = no.disabled = false; inp.focus(); }
    };
    ok.addEventListener('click', save);
    no.addEventListener('click', onCancel);
    inp.addEventListener('keydown', (e) => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); save(); } else if (e.key === 'Escape') onCancel(); });
    const btns = document.createElement('div'); btns.className = 'btns'; btns.append(ok, no);
    wrap.append(inp, btns);
    setTimeout(() => { grow(); inp.focus(); inp.setSelectionRange(inp.value.length, inp.value.length); }, 0);
    return wrap;
  }

  function row(node, parent) {
    const li = document.createElement('li'); if (!parent) li.className = 'kw-card';
    const r = document.createElement('div'); r.className = 'kw-row'; li.appendChild(r);
    const isGroup = !parent;
    const hasKids = isGroup && node.children.length > 0;

    if (editing && editing.kind === 'edit' && editing.id === node.id) {
      r.appendChild(editor(node.name, async (v) => {
        const j = await api('/' + node.id, { name: v }); node.name = j.name; editing = null; render();
      }, () => { editing = null; render(); }));
      kids(li, node, isGroup, hasKids);
      return li;
    }

    const tog = document.createElement('button'); tog.type = 'button'; tog.className = 'kw-tog' + (hasKids ? (node.collapsed ? '' : ' open') : ' none'); tog.innerHTML = SVG.chev; tog.title = node.collapsed ? '펼치기' : '접기';
    tog.addEventListener('click', async () => {
      node.collapsed = !node.collapsed; render();
      try { await api('/' + node.id + '/collapse', { collapsed: node.collapsed }); } catch (e) {}
    });

    const name = document.createElement('button'); name.type = 'button'; name.className = 'kw-name'; name.title = '클립보드에 복사'; name.appendChild(nameHtml(node.name));
    if (hasKids && node.collapsed && query === '') { const c = document.createElement('span'); c.className = 'kw-count'; c.textContent = node.children.length; name.appendChild(c); }
    name.addEventListener('click', () => copy(node.name, r));
    r.appendChild(name);

    const acts = document.createElement('div'); acts.className = 'kw-acts';
    const pen = icon('', '수정', SVG.pen);
    pen.addEventListener('click', () => { editing = { kind: 'edit', id: node.id }; render(); });
    acts.appendChild(pen);
    if (isGroup) {
      const add = icon('', '하위 키워드 추가', SVG.plus);
      add.addEventListener('click', () => { node.collapsed = false; editing = { kind: 'add', parentId: node.id }; render(); });
      acts.appendChild(add);
      if (node.children.length > 1) {
        const srt = icon('sort', '하위 키워드 정렬 (다시 누르면 역순)', SVG.sort);
        srt.addEventListener('click', () => { node.sortDir = node.sortDir === 1 ? -1 : 1; sortList(node.children, node.sortDir); node.collapsed = false; render(); reorder(node.children); });
        acts.appendChild(srt);
      }
    }
    const cam = icon('cam' + (node.image ? ' has' : ''), node.image ? '사진 바꾸기' : '사진 올리기 (1:1 자르기)', SVG.cam);
    cam.addEventListener('click', () => pickImage(node));
    acts.appendChild(cam);
    const del = icon('del', '삭제', SVG.trash);
    del.addEventListener('click', async () => {
      const n = isGroup && node.children.length ? '"' + node.name + '"과(와) 하위 ' + node.children.length + '개를 삭제할까요?' : '"' + node.name + '"을(를) 삭제할까요?';
      if (!confirm(n)) return;
      try {
        await api('/' + node.id + '/delete');
        const list = parent ? parent.children : tree; list.splice(list.indexOf(node), 1); render();
      } catch (e) { alert(e.message); }
    });
    acts.appendChild(del);
    r.appendChild(acts);
    if (isGroup) r.appendChild(tog);
    if (showImg && node.image) {
      const wrap = document.createElement('div'); wrap.className = 'kw-picwrap';
      const pic = document.createElement('button'); pic.type = 'button'; pic.className = 'kw-pic'; pic.title = '원본 보기';
      const im = document.createElement('img'); im.src = picUrl(node, 'thumb'); im.alt = ''; im.loading = 'lazy'; pic.appendChild(im);
      pic.addEventListener('click', () => viewImage(node));
      wrap.appendChild(pic); r.appendChild(wrap);
    }

    kids(li, node, isGroup, hasKids);
    return li;
  }

  function kids(li, node, isGroup, hasKids) {
    const adding = editing && editing.kind === 'add' && editing.parentId === node.id;
    if (!isGroup || (node.collapsed && query === '') || !(hasKids || adding)) return;
    const ul = document.createElement('ul'); ul.className = 'kw-members';
    const shown = query === '' || hit(node) ? node.children : node.children.filter(hit);
    shown.forEach((c) => ul.appendChild(row(c, node)));
    if (adding) ul.appendChild(addRow(node));
    li.appendChild(ul);
  }

  function addRow(parent) {
    const li = document.createElement('li'); if (!parent) li.className = 'kw-card';
    const r = document.createElement('div'); r.className = 'kw-row'; li.appendChild(r);
    r.appendChild(editor('', async (v) => {
      const j = await api('', { parent_id: parent ? parent.id : null, name: v });
      (parent ? parent.children : tree).push(j.node); editing = null; render();
    }, () => { editing = null; render(); }));
    return li;
  }

  function render() {
    treeEl.innerHTML = '';
    const shown = query === '' ? tree : tree.filter((n) => hit(n) || n.children.some(hit));
    shown.forEach((n) => treeEl.appendChild(row(n, null)));
    if (editing && editing.kind === 'add' && editing.parentId === null) treeEl.appendChild(addRow(null));
    $('kwEmpty').hidden = tree.length > 0 || !!editing;
    $('kwNoMatch').hidden = !(query !== '' && shown.length === 0);
  }

  /** Persists the current order of a list (top level or one group's members). */
  async function reorder(list) {
    try { await api('/reorder', { ids: list.map((n) => n.id) }); } catch (e) { alert(e.message); }
  }
  async function sortAll(dir) {
    sortList(tree, dir);
    await reorder(tree);
    for (const g of tree) { if (g.children.length > 1) { g.sortDir = dir; sortList(g.children, dir); await reorder(g.children); } }
    render();
  }
  $('kwSortAsc').addEventListener('click', () => sortAll(1));
  $('kwSortDesc').addEventListener('click', () => sortAll(-1));

  const search = $('kwSearch');
  search.addEventListener('input', () => { query = search.value.trim().toLowerCase(); $('kwSearchClear').hidden = query === ''; render(); });
  search.addEventListener('keydown', (e) => { if (e.key === 'Escape') { search.value = ''; search.dispatchEvent(new Event('input')); } });
  $('kwSearchClear').addEventListener('click', () => { search.value = ''; search.dispatchEvent(new Event('input')); search.focus(); });

  $('kwAddTop').addEventListener('click', () => { editing = { kind: 'add', parentId: null }; render(); treeEl.lastElementChild.scrollIntoView({ block: 'nearest' }); });
  $('kwExpand').addEventListener('click', async () => { tree.forEach((n) => { n.collapsed = false; }); render(); try { await api('/collapse-all', { collapsed: false }); } catch (e) {} });
  $('kwCollapse').addEventListener('click', async () => { tree.forEach((n) => { n.collapsed = true; }); render(); try { await api('/collapse-all', { collapsed: true }); } catch (e) {} });
  $('kwSeed').addEventListener('click', async (e) => {
    e.target.disabled = true;
    try { const j = await api('/seed'); tree = j.tree; render(); } catch (err) { alert(err.message); e.target.disabled = false; }
  });
  render();
})();
</script>
<?= $this->endSection() ?>
