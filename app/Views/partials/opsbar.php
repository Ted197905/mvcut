<?php /* Admin status bar: what runs automatically right now, with stop (X) and on/off per part. Data: GET ops/status (3 s). */ ?>
<div class="ops-bar" id="opsBar">
  <div class="ops-line">
    <span class="ops-dot" title="실행 중인 작업이 있으면 초록"></span>
    <div class="ops-chips" id="opsChips"><span class="muted">불러오는 중...</span></div>
    <button class="ops-more" type="button" id="opsMore" aria-expanded="false">자동 작업 &#9662;</button>
  </div>
  <div class="ops-panel" id="opsPanel" hidden></div>
</div>
<script>
(() => {
  const bar = document.getElementById('opsBar'), chips = document.getElementById('opsChips'), panel = document.getElementById('opsPanel'),
        more = document.getElementById('opsMore'), dot = bar.querySelector('.ops-dot');
  const base = <?= json_encode(rtrim(site_url(), '/') . '/') ?>;
  const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const dur = (s) => s == null ? '' : s < 60 ? s + '초' : s < 3600 ? Math.floor(s / 60) + '분 ' + (s % 60) + '초' : Math.floor(s / 3600) + '시간 ' + Math.floor(s % 3600 / 60) + '분';
  const post = async (url, data) => {
    const c = MV.csrf(), fd = new FormData();
    Object.entries(data).forEach(([k, v]) => fd.append(k, v));
    if (c) fd.append(c.name, c.hash);
    const r = await fetch(base + url, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': c ? c.hash : '' } });
    if (!r.ok) throw new Error(MV.httpError(r.status));
  };
  let busy = false;
  const load = async () => {
    if (busy || document.hidden) return;
    busy = true;
    try {
      const j = await (await fetch(base + 'ops/status', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })).json();
      const run = j.parts.filter((p) => p.on && p.busy);
      // the three collection parts take turns every few seconds: one chip for them
      const coll = run.filter((p) => p.group === '수집').sort((a, b) => a.last - b.last)[0];
      const html = [];
      if (coll) html.push('<span class="ops-chip"><b>수집</b> ' + esc(coll.label) + ': ' + esc(coll.detail) + ' <button data-off="collect,detail,media" title="수집 전체 끄기 (아래 자동 작업에서 다시 켬)">&times;</button></span>');
      run.filter((p) => p.group !== '수집').forEach((p) => html.push('<span class="ops-chip hot"><b>' + esc(p.label) + '</b> ' + esc(p.detail) + ' · ' + dur(p.since)
        + ' <button data-off="' + p.name + '" title="' + esc(p.label) + ' 끄기: 지금 작업을 멈추고 다시 켤 때까지 쉼">&times;</button></span>'));
      j.models.forEach((m) => html.push('<span class="ops-chip gpu"><b>Ollama</b> ' + esc(m.name.replace(/^huihui_ai\//, '')) + ' GPU ' + m.vram + 'GB'
        + ' <button data-unload="' + esc(m.name) + '" title="메모리에서 내리기 (쓰는 작업이 켜져 있으면 다시 올라옴)">&times;</button></span>'));
      j.jobs.forEach((x) => html.push('<span class="ops-chip"><b>작업</b> #' + x.id + ' ' + esc(x.type) + ' ' + x.progress + '%'
        + (x.mine ? ' <button data-job="' + x.id + '" title="작업 취소">&times;</button>' : '') + '</span>'));
      const off = j.parts.filter((p) => !p.on).map((p) => p.label);
      if (off.length) html.push('<span class="ops-chip off">꺼짐: ' + esc(off.join(', ')) + '</span>');
      chips.innerHTML = html.join('') || '<span class="muted">실행 중인 자동 작업 없음</span>';
      dot.classList.toggle('on', run.length > 0 || j.jobs.length > 0);
      panel.innerHTML = '<div class="ops-grid">' + j.parts.map((p) => '<label class="ops-row' + (p.on ? '' : ' is-off') + '"><input type="checkbox" data-sw="' + p.name + '"' + (p.on ? ' checked' : '') + '>'
        + '<span class="ops-g">' + esc(p.group) + '</span><b>' + esc(p.label) + '</b><span class="muted">' + esc(p.about) + '</span>'
        + '<span class="ops-st">' + (!p.on ? '꺼짐' : p.busy ? '실행 중 ' + dur(p.since) + ': ' + esc(p.detail) : (p.last != null ? '대기 · 마지막 ' + dur(p.last) + ' 전' : '대기')) + '</span></label>').join('') + '</div>';
    } catch (e) { /* next tick */ }
    busy = false;
  };
  bar.addEventListener('click', async (e) => {
    const b = e.target.closest('button');
    if (!b) return;
    try {
      if (b.dataset.off) { for (const n of b.dataset.off.split(',')) await post('ops/switch', { name: n, on: '0' }); }
      else if (b.dataset.unload) await post('ops/unload', { model: b.dataset.unload });
      else if (b.dataset.job) await post('api/jobs/' + b.dataset.job + '/cancel', {});
      else return;
      load();
    } catch (err) { alert(err.message); }
  });
  panel.addEventListener('change', async (e) => {
    const c = e.target.closest('input[data-sw]');
    if (!c) return;
    try { await post('ops/switch', { name: c.dataset.sw, on: c.checked ? '1' : '0' }); } catch (err) { alert(err.message); }
    load();
  });
  more.addEventListener('click', () => { panel.hidden = !panel.hidden; more.setAttribute('aria-expanded', String(!panel.hidden)); });
  document.body.classList.add('has-ops');
  load();
  setInterval(load, 3000);
})();
</script>
