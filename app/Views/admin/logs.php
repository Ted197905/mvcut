<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>
<main class="wide settings">
  <h1>로그</h1>
  <p class="muted small" style="margin-top:-6px">웹 요청(변경/실패/8초 이상), 워커(작업, X 검사, 초안 알림), PHP 경고/예외, 텔레그램, 브라우저 오류. <?= \App\Libraries\AppLog::KEEP_DAYS ?>일 보관. 수집기 로그는 <a href="<?= site_url('collect') ?>">수집</a> 페이지의 진행 사항.</p>

  <section class="set-card">
    <div class="cl-log-bar lg-bar">
      <div class="seg" id="lgLevel">
        <button class="seg-item on" type="button" data-v="">전체</button>
        <button class="seg-item" type="button" data-v="warn">경고 이상</button>
        <button class="seg-item" type="button" data-v="error">오류만</button>
      </div>
      <select class="input sm" id="lgArea"><option value="">모든 영역</option><?php foreach ($areas as $a): ?><option value="<?= esc($a, 'attr') ?>"><?= esc($a) ?></option><?php endforeach ?></select>
      <select class="input sm" id="lgUser"><option value="">모든 사용자</option><?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>"><?= esc($u['display_name']) ?> (#<?= (int) $u['id'] ?>)</option><?php endforeach ?></select>
      <select class="input sm" id="lgHours"><option value="1">1시간</option><option value="24" selected>24시간</option><option value="168">7일</option><option value="720">전체</option></select>
      <input class="input sm" type="search" id="lgQ" placeholder="검색 (내용, 동작, 상세, 오류 코드)" style="min-width:220px">
      <span class="muted small" id="lgCount"></span>
    </div>
    <div class="cl-log-box lg-box" id="lgBox"></div>
  </section>
</main>
<script>
(() => {
  const url = <?= json_encode(site_url('admin/logs/data')) ?>;
  const box = document.getElementById('lgBox'), lvSeg = document.getElementById('lgLevel'), area = document.getElementById('lgArea'),
        user = document.getElementById('lgUser'), hours = document.getElementById('lgHours'), q = document.getElementById('lgQ'), count = document.getElementById('lgCount');
  let top = 0, level = '', total = 0, timer = null;
  const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const line = (r) => '<div class="cl-log-row lg-row lv-' + esc(r.lv) + '"' + (r.detail ? ' data-detail="' + esc(r.detail) + '"' : '') + '>'
    + '<span class="t">' + esc(r.t) + '</span><span class="lg-lv ' + esc(r.lv) + '">' + esc(r.lv) + '</span>'
    + '<span class="lg-area">' + esc(r.area) + '</span><span class="lg-act" title="' + esc(r.action) + '">' + esc(r.action) + '</span>'
    + '<span class="lg-user">' + (r.user ? '#' + r.user : '') + '</span>'
    + '<span class="m">' + esc(r.msg) + (r.req ? ' <span class="lg-req" title="요청 코드">' + esc(r.req) + '</span>' : '') + '</span></div>';
  const params = () => {
    const p = new URLSearchParams({ after: top, level, area: area.value, user: user.value, hours: hours.value });
    const v = q.value.trim();
    if (/^[0-9a-f]{6,12}$/.test(v)) p.set('req', v); else if (v) p.set('q', v);
    return p;
  };
  const load = async (reset) => {
    if (reset) { top = 0; total = 0; box.innerHTML = '<div class="muted small" style="padding:8px 12px">불러오는 중...</div>'; }
    try {
      const j = await (await fetch(url + '?' + params(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })).json();
      if (reset) box.innerHTML = '';
      if (j.items.length) {
        top = j.items[0].id;
        total += j.items.length;
        box.insertAdjacentHTML('afterbegin', j.items.map(line).join(''));
        while (box.children.length > 1000) box.lastElementChild.remove();
      } else if (!box.children.length) box.innerHTML = '<div class="muted small" style="padding:8px 12px">기록이 없습니다.</div>';
      count.textContent = total ? total + '건' + (total >= 300 ? ' (최근 300건부터, 새 기록은 자동 추가)' : '') : '';
    } catch (e) { /* next tick */ }
  };
  box.addEventListener('click', (e) => {
    const row = e.target.closest('.cl-log-row[data-detail]');
    if (!row) return;
    const pre = row.nextElementSibling;
    if (pre && pre.tagName === 'PRE') { pre.remove(); return; }
    row.insertAdjacentHTML('afterend', '<pre class="cl-log-detail">' + esc(row.dataset.detail) + '</pre>');
  });
  lvSeg.addEventListener('click', (e) => {
    const b = e.target.closest('.seg-item'); if (!b) return;
    lvSeg.querySelectorAll('.seg-item').forEach((x) => x.classList.toggle('on', x === b));
    level = b.dataset.v; load(true);
  });
  [area, user, hours].forEach((el) => el.addEventListener('change', () => load(true)));
  q.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => load(true), 400); });
  load(true);
  setInterval(() => { if (!document.hidden) load(false); }, 5000);
})();
</script>
<?= $this->endSection() ?>
