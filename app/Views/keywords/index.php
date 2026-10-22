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
    <button class="kw-ib" type="button" id="kwExpand" title="전체 펼치기" aria-label="전체 펼치기"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M7 9l5-5 5 5M7 15l5 5 5-5"/></svg></button>
    <button class="kw-ib" type="button" id="kwCollapse" title="전체 접기" aria-label="전체 접기"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M7 4l5 5 5-5M7 20l5-5 5 5"/></svg></button>
  </div>

  <ul class="kw-tree" id="kwTree"></ul>
  <div class="kw-nomatch" id="kwNoMatch" hidden>검색 결과가 없습니다.</div>
  <div class="kw-empty" id="kwEmpty" hidden>
    <p>아직 키워드가 없습니다.</p>
    <button class="kw-pill" type="button" id="kwSeed">기본 키워드 불러오기</button>
  </div>
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
  };

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
