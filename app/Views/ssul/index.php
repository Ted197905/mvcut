<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>
<?php
use App\Controllers\Ssul;
$kst = static fn (?string $utc, string $f = 'n/j H:i') => $utc ? \App\Libraries\XWatch::kst(strtotime($utc . ' UTC'), $f) : '-';
$pct = static fn ($v) => round($v * 100) . '%';
$nCorpus = array_column($corpus, 'n', 'origin');
?>
<style>
main.wide.settings.ssul { max-width: 980px; }
.ss-form { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px 12px; align-items: end; }
.ss-form .wide { grid-column: 1 / -1; }
.ss-form .two { grid-column: span 2; }
.ss-form select.input, .ss-form input.input { width: 100%; }
.ss-bar { display: flex; flex-wrap: wrap; gap: 8px 12px; align-items: center; margin-bottom: 12px; }
.ss-bar .spacer { flex: 1; }
.ss-tr { display: grid; grid-template-columns: 34px minmax(0, 1fr) auto; gap: 4px 10px; align-items: center; padding: 9px 4px; }
.ss-tr + .ss-tr { box-shadow: inset 0 0.5px 0 var(--separator); }
.ss-tr.flag { opacity: .55; }
.ss-rank { text-align: center; color: var(--label2); font-variant-numeric: tabular-nums; font-size: 13px; }
.ss-kw { font-weight: 600; font-size: 15px; overflow-wrap: anywhere; }
.ss-kw .ck-badge { margin-left: 4px; font-weight: 500; }
.ss-sm { grid-column: 2 / -1; font-size: 13px; color: var(--label2); }
.ss-sm summary { cursor: pointer; list-style: none; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ss-sm summary::-webkit-details-marker { display: none; }
.ss-sm ul { margin: 6px 0 2px; padding-left: 18px; }
.ss-sm li { margin: 2px 0; }
.ss-acts { display: flex; gap: 6px; }
.ss-list { max-height: 560px; overflow: auto; background: var(--bg-elev); border-radius: var(--r-group); padding: 4px 12px; box-shadow: var(--shadow-sm); }
.ss-card { background: var(--bg-elev); border-radius: 16px; padding: 16px 18px; box-shadow: var(--shadow-sm); margin-bottom: 14px; }
.ss-card.out { opacity: .5; }
.ss-head { display: flex; flex-wrap: wrap; gap: 6px 8px; align-items: center; margin-bottom: 10px; font-size: 13px; color: var(--label2); }
.ss-head b { color: var(--label); font-size: 15px; }
.ss-card textarea { width: 100%; font: inherit; font-size: 15px; line-height: 1.55; border: 1px solid var(--separator); border-radius: 10px; padding: 10px 12px; background: var(--bg); color: var(--label); resize: vertical; margin-bottom: 8px; }
.ss-card label.lb { display: block; font-size: 12px; color: var(--label2); margin: 2px 0 4px; }
.ss-note { font-size: 13px; color: #b26a00; margin: 0 0 8px; }
.ss-err { color: var(--red); font-size: 14px; margin: 0 0 8px; }
.ss-src { font-size: 13px; color: var(--label2); margin: 0 0 10px; }
.ss-src summary { cursor: pointer; color: var(--blue); }
.ss-src ol { margin: 6px 0 0; padding-left: 20px; }
.ss-src li.unused { opacity: .6; }
.ss-btns { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.ss-btns .spacer { flex: 1; }
.ss-count { font-size: 12px; color: var(--label2); font-variant-numeric: tabular-nums; }
.ss-wait { color: var(--label2); font-size: 14px; margin: 0 0 8px; }
.ss-stat { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 14px; font-size: 14px; line-height: 1.6; }
.ss-stat h3 { font-size: 15px; margin: 0 0 6px; }
.ss-stat ul { margin: 4px 0; padding-left: 18px; }
.ss-off { background: var(--red-tint); color: var(--red); border-radius: 12px; padding: 10px 14px; font-size: 14px; margin-bottom: 14px; }
@media (max-width: 700px) { .ss-form { grid-template-columns: repeat(2, minmax(0, 1fr)); } .ss-tr { grid-template-columns: 26px minmax(0, 1fr); } .ss-acts { grid-column: 2; } }
</style>
<main class="wide settings ssul">
  <h1>AI 썰</h1>
  <p class="muted small" style="margin-top:-6px">트렌드 키워드나 직접 넣은 키워드로 X 게시용 썰과 트렌드 기사를 로컬 모델(Qwen3.6)이 씁니다. 재구성 = 수집한 실제 썰을 잘 먹히는 형태로 다시 씀, 창작 = 지어낸 이야기(실존 인물/그룹/상호 없이), 기사 = 뉴스 검색과 수집 글로 조사한 내용만으로 작성. 결과는 고쳐서 복사하거나 초안으로 보냅니다.</p>
  <?php if (! $on['ssul']): ?><div class="ss-off">"AI 썰 작성" 스위치가 꺼져 있어 요청이 대기만 합니다. 설정 &gt; 자동 기능에서 켜 주세요.</div><?php endif ?>
  <?php if (! $on['trends']): ?><div class="ss-off">"트렌드 키워드" 스위치가 꺼져 있어 아래 목록이 갱신되지 않습니다. 설정 &gt; 자동 기능에서 켜 주세요.</div><?php endif ?>

  <section class="set-card">
    <h2>새로 쓰기</h2>
    <form class="ss-form" id="ssForm" method="post" action="<?= site_url('ssul/jobs') ?>">
      <?= csrf_field() ?>
      <div class="two"><label for="ssKw">키워드</label><input class="input" id="ssKw" name="keyword" maxlength="200" required placeholder="예: 축의금, 40대 수면, 청룡영화제"></div>
      <div><label for="ssKind">종류</label><select class="input" id="ssKind" name="kind">
        <option value="keyword">키워드 썰</option><option value="article">트렌드 기사 (자료 조사)</option></select></div>
      <div><label for="ssAcc">계정</label><select class="input" id="ssAcc" name="account">
        <?php foreach (Ssul::ACCOUNTS as $k => $v): ?><option value="<?= $k ?>"><?= esc($v) ?></option><?php endforeach ?></select></div>
      <div><label for="ssMode">방식</label><select class="input" id="ssMode" name="mode">
        <?php foreach (Ssul::MODES as $k => $v): ?><option value="<?= $k ?>"><?= esc($v) ?></option><?php endforeach ?></select></div>
      <div><label for="ssType">유형</label><select class="input" id="ssType" name="ptype">
        <?php foreach (Ssul::TYPES as $k => $v): ?><option value="<?= $k ?>"><?= esc($v) ?></option><?php endforeach ?></select></div>
      <div class="two"><label for="ssNote">추가 요청 (선택)</label><input class="input" id="ssNote" name="note" maxlength="500" placeholder="예: 주인공은 30대 직장인, 배경은 명절"></div>
      <div class="wide ss-btns"><button class="btn" type="submit">쓰기</button>
        <span class="muted small">HUE 계정과 실존 인물/그룹 키워드는 방식과 상관없이 수집 자료로만 씁니다. 기사는 방식/유형을 쓰지 않습니다.</span></div>
    </form>
  </section>

  <section class="set-card">
    <h2>트렌드 키워드</h2>
    <div class="ss-bar">
      <span class="muted small">갱신 <?= esc($kst($trendAt, 'H:i')) ?> · 10분마다 · <?= count($trends) ?>개 ·
        커뮤니티 제목은 <?= $collectOn ? '수집기가 읽은 목록에서' : '목록 ' . (int) ($lists['ok'] ?? 0) . '/' . (int) ($lists['n'] ?? 0) . '곳을 제목만 읽어서 (수집 스위치가 꺼져 있음)' ?></span>
      <span class="spacer"></span>
      <span class="seg" id="ssOrigin">
        <a class="seg-item on" href="#" data-o="">전체</a>
        <?php foreach (Ssul::ORIGINS as $k => $v): ?><a class="seg-item" href="#" data-o="<?= $k ?>"><?= esc($v) ?></a><?php endforeach ?>
      </span>
      <label class="small muted"><input type="checkbox" id="ssPol"> 정치 포함</label>
    </div>
    <?php if (! $trends): ?><p class="muted">아직 수집된 키워드가 없습니다.</p><?php else: ?>
    <div class="ss-list">
      <?php foreach ($trends as $i => $k): ?>
        <div class="ss-tr<?= $k['flag'] ? ' flag' : '' ?>" data-o="<?= esc($k['origin'], 'attr') ?>" data-flag="<?= esc((string) $k['flag'], 'attr') ?>"<?= $k['flag'] ? ' hidden' : '' ?>>
          <span class="ss-rank"><?= $i + 1 ?></span>
          <span class="ss-kw"><?= esc($k['kw']) ?>
            <?php foreach (explode(',', $k['origin']) as $o): ?><span class="ck-badge"><?= esc(Ssul::ORIGINS[$o] ?? $o) ?></span><?php endforeach ?>
            <?php if ($k['new']): ?><span class="ck-badge ok">새로 뜸</span><?php endif ?>
            <?php if ($k['flag']): ?><span class="ck-badge bad">정치</span><?php endif ?>
            <?php if ((int) $k['df']): ?><span class="muted small" style="font-weight:400">글 <?= (int) $k['df'] ?> · <?= (int) $k['n_src'] ?>곳</span><?php endif ?>
          </span>
          <span class="ss-acts">
            <button class="btn sm tinted" type="button" data-make="trend" data-kw="<?= esc($k['kw'], 'attr') ?>" title="위 폼의 계정/방식/유형으로 이 트렌드의 썰을 씁니다">썰</button>
            <button class="btn sm secondary" type="button" data-make="article" data-kw="<?= esc($k['kw'], 'attr') ?>" title="자료를 조사해 트렌드 기사를 씁니다">기사</button>
          </span>
          <?php if ($k['samples']): ?>
            <details class="ss-sm"><summary><?= esc($k['samples'][0]['t']) ?></summary><ul>
              <?php foreach ($k['samples'] as $s): ?>
                <li><?php if (! empty($s['u'])): ?><a href="<?= esc($s['u'], 'attr') ?>" target="_blank" rel="noopener noreferrer"><?= esc($s['t']) ?></a><?php else: ?><?= esc($s['t']) ?><?php endif ?>
                  <span class="muted"> · <?= esc($names[$s['s'] ?? ''] ?? ($s['s'] ?? '')) ?></span></li>
              <?php endforeach ?>
            </ul></details>
          <?php endif ?>
        </div>
      <?php endforeach ?>
    </div>
    <?php endif ?>
  </section>

  <section class="set-card">
    <h2>결과</h2>
    <div id="ssJobs"></div>
    <p class="muted" id="ssEmpty" hidden>아직 쓴 글이 없습니다.</p>
  </section>

  <section class="set-card">
    <details>
      <summary><b>패턴 분석</b> <span class="muted small">잘 나온 썰 모음: X 100만+ 텍스트 썰 <?= (int) ($nCorpus['x'] ?? 0) ?>건, 커뮤니티 썰 <?= (int) ($nCorpus['community'] ?? 0) ?>건 (수집 글이 들어올 때마다 추가)</span></summary>
      <?php if ($stats): ?>
      <div class="ss-stat" style="margin-top:14px">
        <?php foreach (['x' => 'X에서 조회 100만을 넘긴 텍스트 썰', 'community' => '커뮤니티에서 게시판 중앙값보다 조회가 높은 썰'] as $o => $label): $s = $stats['origins'][$o] ?? null; if (! $s) continue; ?>
          <div>
            <h3><?= esc($label) ?> (<?= (int) $s['n'] ?>건)</h3>
            <ul>
              <li>길이 중앙 <?= (int) $s['chars'] ?>자, <?= (int) $s['lines'] ?>줄, 한 줄 <?= (int) $s['cpl'] ?>자, 첫 줄 <?= (int) $s['first'] ?>자</li>
              <li>음슴체로 끝나는 줄 <?= $pct($s['eum']) ?>, 숫자 <?= (int) $s['nums'] ?>개, 대사 <?= (int) $s['quotes'] ?>개</li>
              <li>마지막 줄을 대사/물음으로 끊은 글 <?= $pct($s['open']) ?> · 조회 중앙 <?= number_format((int) $s['views']) ?></li>
              <li>소재: <?= esc(implode(', ', array_map(static fn ($t) => $t[0] . ' ' . $t[1], array_slice($s['topics'], 0, 6)))) ?></li>
              <?php if (! empty($s['top_vs_rest'])): ?>
                <li>조회 상위 25% vs 나머지의 소재 비중: <?= esc(implode(', ', array_map(static fn ($t, $v) => $t . ' ' . $pct($v[0]) . ' / ' . $pct($v[1]), array_keys($s['top_vs_rest']), $s['top_vs_rest']))) ?></li>
                <li>제목이 물음표로 끝나는 글: 상위 <?= $pct($s['top_q']) ?> / 나머지 <?= $pct($s['rest_q']) ?></li>
              <?php endif ?>
            </ul>
            <details><summary class="muted small">조회 상위 글의 첫 줄</summary><ul><?php foreach ($s['hooks'] ?? [] as $h): ?><li><?= esc($h) ?></li><?php endforeach ?></ul></details>
          </div>
        <?php endforeach ?>
      </div>
      <?php else: ?><p class="muted">아직 분석할 글이 없습니다.</p><?php endif ?>
    </details>
  </section>

  <section class="set-card">
    <details<?= $auto['n'] ? ' open' : '' ?>>
      <summary><b>자동 생성</b> <span class="muted small"><?= $auto['n'] ? '6시간마다 상위 트렌드 ' . (int) $auto['n'] . '건' : '꺼짐' ?></span></summary>
      <form class="ss-form" method="post" action="<?= site_url('ssul/auto') ?>" style="margin-top:14px">
        <?= csrf_field() ?>
        <div><label>6시간마다 건수 (0 = 끔)</label><input class="input" type="number" name="n" min="0" max="20" value="<?= (int) $auto['n'] ?>"></div>
        <div><label>계정</label><select class="input" name="account"><?php foreach (Ssul::ACCOUNTS as $k => $v): ?><option value="<?= $k ?>"<?= $auto['account'] === $k ? ' selected' : '' ?>><?= esc($v) ?></option><?php endforeach ?></select></div>
        <div><label>방식</label><select class="input" name="mode"><?php foreach (Ssul::MODES as $k => $v): ?><option value="<?= $k ?>"<?= $auto['mode'] === $k ? ' selected' : '' ?>><?= esc($v) ?></option><?php endforeach ?></select></div>
        <div><button class="btn sm" type="submit">저장</button></div>
        <p class="wide muted small" style="margin:0">정치로 표시된 키워드와 48시간 안에 이미 쓴 키워드는 건너뜁니다. 쓴 글은 위 결과에 "자동"으로 쌓이고, 초안으로 보내는 것은 직접 합니다.</p>
      </form>
    </details>
  </section>
</main>
<script>
(() => {
  const U = <?= json_encode(rtrim(site_url('ssul'), '/')) ?>;
  const box = document.getElementById('ssJobs'), empty = document.getElementById('ssEmpty'), form = document.getElementById('ssForm');
  const jobs = new Map(<?= json_encode(array_map(static fn ($j) => [$j['id'], $j], $jobs), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>);
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const toast = (msg, bad) => {
    const d = document.createElement('div');
    d.className = 'toast' + (bad ? ' error' : ''); d.setAttribute('role', 'status'); d.textContent = msg;
    d.addEventListener('animationend', (e) => { if (e.animationName === 'toast-out') d.remove(); });
    document.body.appendChild(d); setTimeout(() => d.remove(), 6000);
  };
  const post = async (path, data) => {
    const c = MV.csrf(), b = new FormData();
    for (const [k, v] of Object.entries(data || {})) b.append(k, v);
    if (c) b.append(c.name, c.hash);
    const r = await fetch(U + path, { method: 'POST', body: b, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    if (!r.ok) throw new Error('HTTP ' + r.status);
    return r.json();
  };
  const rows = (s, min) => Math.max(min, Math.min(28, String(s || '').split('\n').length + Math.floor(String(s || '').length / 60)));
  const card = (j) => {
    const done = j.status === 'done', sent = j.draft !== 0;
    const head = `<div class="ss-head"><span class="ck-badge ${j.cls}">${esc(j.label)}</span><b>${esc(j.keyword)}</b>
      <span>${esc(j.kind)} · ${esc(j.mode)} · ${esc(j.account)}${j.ptype && !j.isArticle ? ' · ' + (j.ptype === '자동' ? '유형 자동' : esc(j.ptype)) : ''}${j.auto ? ' · 자동 생성' : ''} · ${esc(j.when)}</span>
      ${j.made === 'create' ? '<span class="ck-badge warn" title="지어낸 이야기입니다">창작</span>' : ''}${sent ? `<span class="ck-badge ok">${j.draft > 0 ? '초안 #' + j.draft : '초안으로 보내는 중'}</span>` : ''}</div>`;
    let body = '';
    if (j.status === 'queued' || j.status === 'running') body = `<p class="ss-wait">${j.status === 'running' ? '쓰는 중입니다...' : '차례를 기다립니다...'}${j.note ? ' (요청: ' + esc(j.note) + ')' : ''}</p>`;
    if (j.status === 'failed') body = `<p class="ss-err">${esc(j.error || '실패')}</p>`;
    if (done) {
      const thread = j.fmt === 'thread';
      body = j.notes.map((n) => `<p class="ss-note">${esc(n)}</p>`).join('')
        + `<label class="lb">${j.isArticle ? '제목' : thread ? '타래 1 (훅)' : '첫 줄 (훅)'}</label><textarea data-f="title" rows="${rows(j.title, 2)}">${esc(j.title)}</textarea>`
        + `<label class="lb">${j.isArticle ? '기사' : thread ? '타래 2~ ("---" 줄로 나눔)' : '본문'}</label><textarea data-f="body" rows="${rows(j.body, 6)}">${esc(j.body)}</textarea>`
        + (thread ? '' : `<label class="lb">${j.isArticle ? 'X 요약 글' : '답글 (타래로 보내면 본문 아래 답글로 이어질 나머지)'}</label><textarea data-f="reply" rows="${rows(j.reply, 2)}">${esc(j.reply)}</textarea>`)
        + (j.material && j.material.url ? `<p class="ss-src">소재 원문: <a href="${esc(j.material.url)}" target="_blank" rel="noopener noreferrer">${esc(j.material.title)}</a></p>` : '')
        + (j.sources.length ? `<details class="ss-src"><summary>출처 ${j.sources.length}건</summary><ol>` + j.sources.map((s) =>
            `<li class="${s.used === false ? 'unused' : ''}"><a href="${esc(s.url)}" target="_blank" rel="noopener noreferrer">${esc(s.title)}</a> · ${esc(s.site)}${s.at ? ' · ' + esc(s.at) : ''}${s.used ? ' · 근거로 씀' : ''}</li>`).join('') + '</ol></details>' : '');
    }
    const btns = `<div class="ss-btns">`
      + (done ? `<button class="btn sm" type="button" data-a="copy">복사</button><button class="btn sm secondary" type="button" data-a="save">저장</button>`
              + (sent ? '' : `<button class="btn sm tinted" type="button" data-a="draft" data-with="0" title="전체를 한 게시물로">초안으로${!j.isArticle ? ' (한 글)' : ''}</button>`
                           + (!j.isArticle ? `<button class="btn sm tinted" type="button" data-a="draft" data-with="1" title="본문은 게시물로, 나머지는 그 아래 답글로 함께 예약">초안으로 (타래)</button>` : '')) : '')
      + (done ? `<span class="ss-count" data-count></span>` : '') + `<span class="spacer"></span>`
      + ((done || j.status === 'failed') && !sent ? `<button class="btn sm ghost" type="button" data-a="redo">다시 쓰기</button>` : '')
      + (j.status !== 'running' ? `<button class="btn sm ghost" type="button" data-a="delete" style="color:var(--red)">삭제</button>` : '') + `</div>`;
    return `<div class="ss-card" id="j${j.id}" data-id="${j.id}">${head}${body}${btns}</div>`;
  };
  const count = (el) => {
    const c = el.querySelector('[data-count]'); if (!c) return;
    const n = [...el.querySelectorAll('textarea[data-f="title"], textarea[data-f="body"]')].reduce((a, t) => a + t.value.length, 0);
    c.textContent = n.toLocaleString() + '자';
  };
  const put = (j, top) => {
    jobs.set(j.id, j);
    const old = document.getElementById('j' + j.id), t = document.createElement('template');
    t.innerHTML = card(j);
    const el = t.content.firstElementChild;
    if (old) old.replaceWith(el); else if (top) box.prepend(el); else box.append(el);
    count(el); empty.hidden = true;
  };
  [...jobs.values()].sort((a, b) => b.id - a.id).forEach((j) => put(j, false));
  empty.hidden = jobs.size > 0;

  const text = (el, j) => {
    const v = (f) => (el.querySelector(`textarea[data-f="${f}"]`) || { value: '' }).value.trim();
    return { title: v('title'), body: v('body'), reply: v('reply') };
  };
  box.addEventListener('input', (e) => { const el = e.target.closest('.ss-card'); if (el) count(el); });
  box.addEventListener('click', async (e) => {
    const b = e.target.closest('button[data-a]'); if (!b) return;
    const el = b.closest('.ss-card'), id = +el.dataset.id, j = jobs.get(id), a = b.dataset.a;
    try {
      if (a === 'copy') {
        const t = text(el, j);
        await navigator.clipboard.writeText(t.title + '\n\n' + t.body.replace(/\n\n---\n\n/g, '\n\n'));
        return toast('첫 줄과 본문을 복사했습니다.' + (t.reply ? ' 결말/요약은 따로 복사해 주세요.' : ''));
      }
      if (a === 'delete' && !confirm('이 글을 삭제할까요?')) return;
      b.disabled = true;
      const data = a === 'save' ? text(el, j) : a === 'draft' ? { ...text(el, j), with: b.dataset.with } : {};
      const r = await post('/' + id + '/' + a, data);
      if (!r.ok) { b.disabled = false; return toast(r.error || '실패했습니다.', true); }
      toast(r.msg || '완료');
      if (a === 'delete') { el.remove(); jobs.delete(id); empty.hidden = jobs.size > 0; }
      else if (r.job) put(r.job); else b.disabled = false;
    } catch (err) { b.disabled = false; toast('요청에 실패했습니다: ' + err.message, true); }
  });

  const make = async (data) => {
    try {
      const r = await post('/jobs', data);
      if (!r.ok) return toast(r.error || '실패했습니다.', true);
      toast(r.msg); put(r.job, true);
      document.getElementById('j' + r.job.id).scrollIntoView({ behavior: 'smooth', block: 'center' });
    } catch (err) { toast('요청에 실패했습니다: ' + err.message, true); }
  };
  const val = (id) => document.getElementById(id).value;
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    if (!val('ssKw').trim()) return;
    make({ keyword: val('ssKw'), kind: val('ssKind'), mode: val('ssMode'), account: val('ssAcc'), ptype: val('ssType'), note: val('ssNote') });
  });
  document.addEventListener('click', (e) => {
    const b = e.target.closest('button[data-make]'); if (!b) return;
    make({ keyword: b.dataset.kw, kind: b.dataset.make, mode: val('ssMode'), account: val('ssAcc'), ptype: val('ssType'), note: '' });
  });

  // trend list filters
  let origin = '';
  const filter = () => {
    const pol = document.getElementById('ssPol').checked;
    document.querySelectorAll('.ss-tr').forEach((r) => { r.hidden = (origin && !r.dataset.o.split(',').includes(origin)) || (r.dataset.flag && !pol); });
  };
  document.getElementById('ssOrigin').addEventListener('click', (e) => {
    const a = e.target.closest('a[data-o]'); if (!a) return;
    e.preventDefault(); origin = a.dataset.o;
    document.querySelectorAll('#ssOrigin .seg-item').forEach((x) => x.classList.toggle('on', x === a));
    filter();
  });
  document.getElementById('ssPol').addEventListener('change', filter);

  // jobs being written, and ones on their way to the drafts
  setInterval(async () => {
    const ids = [...jobs.values()].filter((j) => j.status === 'queued' || j.status === 'running' || j.draft === -1).map((j) => j.id);
    if (!ids.length || document.hidden) return;
    try {
      const r = await (await fetch(U + '/status?ids=' + ids.join(','), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })).json();
      for (const j of r.jobs) { const o = jobs.get(j.id); if (o && (o.status !== j.status || o.draft !== j.draft)) put(j); }
    } catch (err) { /* next round */ }
  }, 4000);
})();
</script>
<?= $this->endSection() ?>
