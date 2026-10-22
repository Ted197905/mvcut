<?= $this->extend('layouts/main') ?>
<?= $this->section('head') ?>
<style>
main.wide.settings.keywords { max-width: 760px; }
.kw-top { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin: 0 0 18px; }
.kw-top .spacer { flex: 1; }
.kw-search { position: relative; margin: 0 0 14px; }
.kw-search .input { padding-left: 38px; padding-right: 38px; }
.kw-search .ico { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); color: var(--label3); pointer-events: none; display: flex; }
.kw-search .clear { position: absolute; right: 8px; top: 50%; transform: translateY(-50%); }
.kw-nomatch { text-align: center; padding: 28px 10px; color: var(--label2); }
.kw-name mark { background: color-mix(in srgb, #ffd60a 55%, transparent); color: inherit; border-radius: 3px; padding: 0 1px; }
.kw-acts .iconbtn.sort { font-size: 11px; font-weight: 700; letter-spacing: -0.02em; }
.kw-tree { list-style: none; margin: 0; padding: 0; }
.kw-tree ul { list-style: none; margin: 0 0 0 18px; padding: 0 0 0 14px; border-left: 1px solid var(--separator); }
.kw-row { display: flex; align-items: center; gap: 4px; min-height: 40px; padding: 2px 0; }
.kw-row.group { min-height: 44px; }
.kw-tog { flex: none; width: 28px; height: 28px; border: 0; border-radius: 50%; background: transparent; color: var(--label2); cursor: pointer; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; transition: transform .18s, background .15s; }
.kw-tog:hover { background: var(--fill3); }
.kw-tog.open { transform: rotate(90deg); }
.kw-tog.none { visibility: hidden; }
.kw-name { flex: 1; min-width: 0; text-align: left; border: 0; background: transparent; color: var(--label); font: inherit; font-size: 15px; padding: 7px 10px; border-radius: var(--r-control); cursor: pointer; overflow-wrap: anywhere; white-space: pre-wrap; line-height: 1.35; transition: background .15s; }
.kw-row.group .kw-name { font-weight: 600; font-size: 16px; }
.kw-name:hover { background: var(--blue-tint); color: var(--blue); }
.kw-name:active { transform: scale(0.99); }
.kw-count { font-weight: 400; font-size: 12px; color: var(--label3); margin-left: 6px; }
.kw-acts { display: flex; gap: 2px; flex: none; }
.kw-acts .iconbtn { width: 30px; height: 30px; background: transparent; }
.kw-acts .iconbtn:hover { background: var(--fill2); }
.kw-acts .iconbtn.del:hover { background: var(--red-tint); color: var(--red); }
.kw-copied { flex: none; font-size: 12px; font-weight: 600; color: var(--green); padding: 0 6px; animation: kw-fade 1.4s ease forwards; }
@keyframes kw-fade { 0%, 70% { opacity: 1; } 100% { opacity: 0; } }
.kw-edit { display: flex; align-items: flex-start; gap: 6px; flex: 1; min-width: 0; padding: 2px 0; }
.kw-edit .input { flex: 1; min-width: 0; min-height: 36px; padding: 6px 10px; font-size: 15px; resize: none; line-height: 1.35; font-family: inherit; overflow: hidden; }
.kw-edit .btns { display: flex; gap: 6px; flex: none; }
.kw-empty { text-align: center; padding: 36px 10px; color: var(--label2); }
.kw-empty p { margin: 0 0 16px; }
.kw-hint { font-size: 13px; color: var(--label3); margin: 14px 0 0; }
@media (max-width: 480px) {
  .kw-tree ul { margin-left: 8px; padding-left: 8px; }
  .kw-name { padding: 7px 6px; }
}
</style>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<main class="wide settings keywords">
  <h1>키워드</h1>
  <p class="muted" style="margin:0 0 22px">키워드를 누르면 클립보드에 복사됩니다. X 게시물에 붙여 넣으세요.</p>
  <?= view('partials/alerts') ?>

  <div class="kw-search">
    <span class="ico"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg></span>
    <input class="input" type="search" id="kwSearch" placeholder="키워드 검색" autocomplete="off">
    <button class="iconbtn clear" type="button" id="kwSearchClear" title="지우기" hidden>&times;</button>
  </div>
  <div class="kw-top">
    <button class="btn sm" type="button" id="kwAddTop">+ 상위 키워드</button>
    <button class="btn sm secondary" type="button" id="kwSortAsc" title="상위 키워드 오름차순 (하위는 각 그룹 안에서)">정렬 A→Z</button>
    <button class="btn sm secondary" type="button" id="kwSortDesc" title="상위 키워드 내림차순 (하위는 각 그룹 안에서)">정렬 Z→A</button>
    <span class="spacer"></span>
    <button class="btn sm secondary" type="button" id="kwExpand">전체 펼치기</button>
    <button class="btn sm secondary" type="button" id="kwCollapse">전체 접기</button>
  </div>

  <section class="set-card">
    <ul class="kw-tree" id="kwTree"></ul>
    <div class="kw-nomatch" id="kwNoMatch" hidden>검색 결과가 없습니다.</div>
    <div class="kw-empty" id="kwEmpty" hidden>
      <p>아직 키워드가 없습니다.</p>
      <button class="btn sm" type="button" id="kwSeed">기본 키워드 불러오기</button>
    </div>
  </section>
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
    const b = document.createElement('button'); b.type = 'button'; b.className = 'iconbtn ' + cls; b.title = title; b.setAttribute('aria-label', title); b.innerHTML = svg; return b;
  };
  const SVG = {
    pen: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>',
    plus: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>',
    trash: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/></svg>',
    sort: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h16M4 12h10M4 18h5"/><path d="M19 12v8M16 17l3 3 3-3"/></svg>',
    chev: '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5l7 7-7 7"/></svg>',
  };

  function editor(value, onSave, onCancel) {
    const wrap = document.createElement('div'); wrap.className = 'kw-edit';
    const inp = document.createElement('textarea'); inp.className = 'input'; inp.value = value; inp.maxLength = <?= \App\Models\KeywordModel::MAX ?>; inp.rows = 1; inp.placeholder = '키워드 (여러 줄 가능, Ctrl+Enter 저장)';
    const grow = () => { inp.style.height = 'auto'; inp.style.height = inp.scrollHeight + 'px'; };
    inp.addEventListener('input', grow);
    const ok = document.createElement('button'); ok.type = 'button'; ok.className = 'btn sm'; ok.textContent = '저장';
    const no = document.createElement('button'); no.type = 'button'; no.className = 'btn sm ghost'; no.textContent = '취소';
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
    const li = document.createElement('li');
    const r = document.createElement('div'); r.className = 'kw-row' + (parent ? '' : ' group'); li.appendChild(r);
    const isGroup = !parent;
    const hasKids = isGroup && node.children.length > 0;

    if (editing && editing.kind === 'edit' && editing.id === node.id) {
      const tog = document.createElement('span'); tog.className = 'kw-tog none'; r.appendChild(tog);
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
    r.appendChild(tog);

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

    kids(li, node, isGroup, hasKids);
    return li;
  }

  function kids(li, node, isGroup, hasKids) {
    const adding = editing && editing.kind === 'add' && editing.parentId === node.id;
    if (!isGroup || (node.collapsed && query === '') || !(hasKids || adding)) return;
    const ul = document.createElement('ul');
    const shown = query === '' || hit(node) ? node.children : node.children.filter(hit);
    shown.forEach((c) => ul.appendChild(row(c, node)));
    if (adding) ul.appendChild(addRow(node));
    li.appendChild(ul);
  }

  function addRow(parent) {
    const li = document.createElement('li');
    const r = document.createElement('div'); r.className = 'kw-row' + (parent ? '' : ' group'); li.appendChild(r);
    const tog = document.createElement('span'); tog.className = 'kw-tog none'; r.appendChild(tog);
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
