<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>
<?php
use App\Controllers\Collect;

$defSort = $f['scope'] === 'x' ? 'views' : 'new';
$qs = static fn (array $over) => site_url('collect?' . http_build_query(array_filter(array_merge(
    ['src' => $f['src'], 'cc' => $f['cc'], 'q' => $f['q'], 'sort' => $f['sort'] === $defSort ? '' : $f['sort'], 'day' => $f['scope'] === 'x' ? $f['day'] : '',
     'all' => $f['all'] ? '1' : '', 'media' => $f['media'] ? '1' : '', 'unused' => $f['unused'] ? '1' : '',
     'scope' => $f['scope'] === 'k' ? '' : $f['scope']], $over), static fn ($v) => $v !== '' && $v !== null)));
$num = static fn ($v) => $v === null ? '-' : number_format((int) $v);
$state = static function (array $s): array {
    if (! (int) $s['enabled']) return ['꺼짐', 'bad'];
    return match ($s['last_status']) {
        'ok', '304' => ['정상', 'ok'],
        'blocked' => ['차단 대기', 'warn'],
        'error' => ['오류', 'bad'],
        null, '' => ['대기', ''],
        default => [$s['last_status'], 'warn'],
    };
};
$bad = count(array_filter($sources, static fn ($s) => $state($s)[1] !== 'ok'));
?>
<main class="yt-page cl-page">
  <?= view('partials/alerts') ?>
  <h1>수집</h1>
  <p class="muted" style="margin:0 0 22px">hallyuwire가 국내외 커뮤니티에서 모은 글입니다. "한류"는 K-연예 관련 글 중 미디어가 있거나 소식을 전하는 글, "최신 이슈"는 인기/이슈 게시판의 글 전부(장르 무관), "X 100만+"는 매일 아침 6시 40분부터 X에서 찾은 최근 24시간 조회수 100만 이상 글(한국어 + 해외 K-연예)입니다.</p>

  <details class="import-panel cl-sources">
    <summary>소스 상태 <span class="muted"><?= count($sources) ?>개<?= $bad ? ' · 확인 필요 ' . $bad . '개' : ' · 모두 정상' ?></span></summary>
    <div class="xw-table-wrap" style="margin-top:14px">
      <table class="xw-table">
        <thead><tr><th>소스</th><th>국가</th><th>상태</th><th>마지막 수집</th><th>다음</th><th class="c">전체</th><th class="c">관련</th><th class="c" title="관련 글 중 보여 줄 미디어도 소식도 없어 숨긴 글">숨김</th><th class="c">24시간</th><th class="c">미디어 대기</th><th class="c">저장</th><th>쿠키</th><th>오류</th></tr></thead>
        <tbody>
        <?php foreach ($sources as $s): [$label, $cls] = $state($s); $c = $counts[$s['id']] ?? []; ?>
          <tr>
            <td><a href="<?= $qs(['src' => $s['id'], 'page' => null]) ?>"><?= esc($s['name']) ?></a></td>
            <td><?= esc(Collect::COUNTRIES[$s['country']] ?? $s['country']) ?></td>
            <td><span class="ck-badge <?= $cls ?>"><?= esc($label) ?></span></td>
            <td><?= esc(Collect::when($s['last_run_at'])) ?><?= $s['last_new'] !== null ? ' <span class="muted">+' . (int) $s['last_new'] . '</span>' : '' ?></td>
            <td class="muted"><?= $s['next_run_at'] && strtotime($s['next_run_at'] . ' UTC') > time() ? round((strtotime($s['next_run_at'] . ' UTC') - time()) / 60) . '분 뒤' : '-' ?></td>
            <td class="c"><?= $num($c['n'] ?? 0) ?></td>
            <td class="c"><?= $num($c['rel'] ?? 0) ?></td>
            <td class="c"><?= $num($c['noise'] ?? 0) ?></td>
            <td class="c"><?= $num($c['d1'] ?? 0) ?></td>
            <td class="c"><?= $num($c['pending'] ?? 0) ?></td>
            <td class="c"><?= esc(\App\Libraries\MediaSupport::size((int) ($c['bytes'] ?? 0))) ?></td>
            <td class="cl-cookie"><?php if (isset($cookies[$s['id']])): ?><form method="post" action="<?= site_url('collect/cookies') ?>" onsubmit="return confirm('<?= esc($s['name'], 'attr') ?> 쿠키를 지울까요?')"><?= csrf_field() ?><input type="hidden" name="source" value="<?= esc($s['id'], 'attr') ?>"><input type="hidden" name="action" value="delete"><span class="ck-badge ok" title="<?= esc(Collect::when(gmdate('Y-m-d H:i:s', $cookies[$s['id']])), 'attr') ?> 등록">등록됨</span> <button class="btn sm ghost" type="submit">지움</button></form><?php else: ?><span class="muted">-</span><?php endif ?></td>
            <td class="cl-err" title="<?= esc((string) $s['last_error'], 'attr') ?>"><?= esc(mb_substr((string) $s['last_error'], 0, 60)) ?></td>
          </tr>
        <?php endforeach ?>
        </tbody>
      </table>
    </div>
    <form class="cl-cookie-add" method="post" action="<?= site_url('collect/cookies') ?>" enctype="multipart/form-data"><?= csrf_field() ?>
      <span class="small muted">로그인이 필요한 게시판은 그 사이트에 로그인한 브라우저에서 내보낸 cookies.txt(Netscape 형식)를 소스에 등록하면 수집기가 그 쿠키로 요청합니다.</span>
      <select class="input" name="source" required>
        <option value="">소스 선택</option>
        <?php foreach ($sources as $s): ?><option value="<?= esc($s['id'], 'attr') ?>"><?= esc($s['name']) ?><?= isset($cookies[$s['id']]) ? ' (등록됨)' : '' ?></option><?php endforeach ?>
      </select>
      <input class="input" type="file" name="file" accept=".txt,text/plain" required>
      <button class="btn sm" type="submit">쿠키 등록</button>
    </form>
  </details>

  <details class="import-panel cl-log" id="clLog">
    <summary><span class="cl-log-dot" title="최근 2분 안에 기록이 있으면 초록"></span>진행 사항 <span class="muted cl-log-last">불러오는 중...</span></summary>
    <div class="cl-log-bar">
      <select class="input sm" id="clLogSrc"><option value="">모든 소스</option><?php foreach ($sources as $s): ?><option value="<?= esc($s['id'], 'attr') ?>"><?= esc($s['name']) ?></option><?php endforeach ?></select>
      <label class="cl-check"><input type="checkbox" id="clLogErr"> 경고/오류만</label>
      <span class="muted small">최근 200줄, 5초마다 갱신. 오류 줄을 누르면 상세. HTTP 요청별 기록은 서버 fetch_log 테이블.</span>
    </div>
    <div class="cl-log-box" id="clLogBox"></div>
  </details>
<script>
(() => {
  const url = <?= json_encode(site_url('collect/log')) ?>, itemUrl = <?= json_encode(rtrim(site_url('collect'), '/') . '/') ?>;
  const panel = document.getElementById('clLog'), box = document.getElementById('clLogBox'), last = panel.querySelector('.cl-log-last'),
        dot = panel.querySelector('.cl-log-dot'), srcSel = document.getElementById('clLogSrc'), errBox = document.getElementById('clLogErr');
  let top = 0;   // newest id shown
  const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const line = (r) => '<div class="cl-log-row lv-' + esc(r.lv.toLowerCase()) + '"' + (r.detail ? ' data-detail="' + esc(r.detail) + '"' : '') + '>'
    + '<span class="t">' + esc(r.t) + '</span><span class="lv">' + esc(r.lv[0]) + '</span><span class="s">' + esc(r.src) + '</span>'
    + '<span class="m">' + esc(r.msg) + (r.item ? ' <a href="' + itemUrl + r.item + '" target="_blank" rel="noopener">#' + r.item + '</a>' : '') + '</span></div>';
  const load = async (reset) => {
    if (reset) { top = 0; box.innerHTML = ''; }
    const q = new URLSearchParams({ after: top, src: srcSel.value, err: errBox.checked ? '1' : '' });
    try {
      const j = await (await fetch(url + '?' + q, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })).json();
      dot.classList.toggle('on', j.idle !== null && j.idle < 120);
      if (j.items.length) {
        if (top === 0 || !srcSel.value && !errBox.checked) last.textContent = j.items[0].t + '  ' + (j.items[0].src ? j.items[0].src + ': ' : '') + j.items[0].msg;
        top = j.items[0].id;
        box.insertAdjacentHTML('afterbegin', j.items.map(line).join(''));
        while (box.children.length > 500) box.lastElementChild.remove();
      } else if (!box.children.length) { last.textContent = '기록 없음'; box.innerHTML = '<div class="muted small">기록이 없습니다.</div>'; }
    } catch (e) { /* next tick */ }
  };
  box.addEventListener('click', (e) => {
    const row = e.target.closest('.cl-log-row[data-detail]');
    if (!row || e.target.tagName === 'A') return;
    const pre = row.nextElementSibling;
    if (pre && pre.tagName === 'PRE') { pre.remove(); return; }
    row.insertAdjacentHTML('afterend', '<pre class="cl-log-detail">' + esc(row.dataset.detail) + '</pre>');
  });
  srcSel.addEventListener('change', () => load(true));
  errBox.addEventListener('change', () => load(true));
  load(true);
  setInterval(() => { if (!document.hidden) load(false); }, 5000);
})();
</script>

  <form class="lib-toolbar" method="get" action="<?= site_url('collect') ?>">
    <div class="seg" role="tablist">
      <a class="seg-item<?= $f['scope'] === 'k' ? ' on' : '' ?>" href="<?= $qs(['scope' => '', 'page' => null]) ?>">한류</a>
      <a class="seg-item<?= $f['scope'] === 'issue' ? ' on' : '' ?>" href="<?= $qs(['scope' => 'issue', 'page' => null]) ?>">최신 이슈</a>
      <a class="seg-item<?= $f['scope'] === 'x' ? ' on' : '' ?>" href="<?= $qs(['scope' => 'x', 'page' => null, 'src' => '', 'day' => '', 'sort' => '']) ?>">X 100만+</a>
      <a class="seg-item<?= $f['scope'] === 'all' ? ' on' : '' ?>" href="<?= $qs(['scope' => 'all', 'page' => null]) ?>">전체</a>
    </div>
    <?php if ($f['scope'] !== 'k'): ?><input type="hidden" name="scope" value="<?= esc($f['scope'], 'attr') ?>"><?php endif ?>
    <?php if ($f['scope'] === 'x' && $f['day'] !== ''): ?><input type="hidden" name="day" value="<?= esc($f['day'], 'attr') ?>"><?php endif ?>
    <input class="input search" type="search" name="q" value="<?= esc($f['q']) ?>" placeholder="제목 · 그룹/인물 검색" autocomplete="off">
    <select class="input" name="src" onchange="this.form.submit()">
      <option value="">모든 소스</option>
      <?php foreach ($sources as $s): ?>
        <option value="<?= esc($s['id'], 'attr') ?>"<?= $f['src'] === $s['id'] ? ' selected' : '' ?>><?= esc($s['name']) ?></option>
      <?php endforeach ?>
    </select>
    <select class="input" name="cc" onchange="this.form.submit()">
      <option value="">모든 국가</option>
      <?php foreach (Collect::COUNTRIES as $k => $v): ?>
        <option value="<?= $k ?>"<?= $f['cc'] === $k ? ' selected' : '' ?>><?= $v ?></option>
      <?php endforeach ?>
    </select>
    <select class="input" name="sort" onchange="this.form.submit()">
      <?php foreach (['new' => '최신순', 'views' => '조회순', 'comments' => '댓글순', 'likes' => '추천순'] as $k => $v): ?>
        <option value="<?= $k ?>"<?= $f['sort'] === $k ? ' selected' : '' ?>><?= $v ?></option>
      <?php endforeach ?>
    </select>
    <label class="cl-check"><input type="checkbox" name="media" value="1"<?= $f['media'] ? ' checked' : '' ?> onchange="this.form.submit()"> 미디어 있는 글만</label>
    <?php if ($f['scope'] === 'k'): ?><label class="cl-check"><input type="checkbox" name="all" value="1"<?= $f['all'] ? ' checked' : '' ?> onchange="this.form.submit()"> 숨긴 글 포함</label><?php endif ?>
    <label class="cl-check"><input type="checkbox" name="unused" value="1"<?= $f['unused'] ? ' checked' : '' ?> onchange="this.form.submit()"> 사용한 글 제외</label>
    <button class="btn secondary sm" type="submit">검색</button>
    <?php if ($f['q'] !== '' || $f['src'] !== '' || $f['cc'] !== '' || $f['all'] || $f['media'] || $f['unused'] || $f['scope'] !== 'k' || $f['sort'] !== $defSort): ?><a class="btn ghost sm" href="<?= site_url('collect') ?>">초기화</a><?php endif ?>
    <span class="spacer"></span>
    <span class="muted small"><?= number_format($matched) ?>개</span>
  </form>

  <?php if ($f['scope'] === 'x'): ?>
    <nav class="pager cl-days" aria-label="날짜">
      <?php foreach ($days as $d => $n): $t = strtotime($d); ?>
        <a class="pg<?= $d === $f['day'] ? ' on' : '' ?>" href="<?= $qs(['day' => $d, 'page' => null]) ?>"><?= date('n/j', $t) ?> <?= ['일', '월', '화', '수', '목', '금', '토'][(int) date('w', $t)] ?> <span class="muted"><?= $n ?></span></a>
      <?php endforeach ?>
      <?php if ($days === []): ?><span class="muted small">아직 수집한 글이 없습니다. 매일 06:40에 시작해 7시 40분 전후에 끝납니다.</span><?php endif ?>
    </nav>
  <?php endif ?>

  <?php if ($items === []): ?>
    <div class="empty">조건에 맞는 글이 없습니다.</div>
  <?php else: ?>
    <div class="grid cl-grid">
      <?php foreach ($items as $it):
        $m = $media[$it['id']] ?? null;
        $files = $m ? (int) $m['imgs'] + (int) $m['vids'] : 0;
        $stat = array_filter(['조회 ' . $num($it['views']) => $it['views'] !== null, '댓글 ' . $num($it['comments']) => $it['comments'] !== null,
                              '추천 ' . $num($it['likes']) => $it['likes'] !== null, $num($it['rank_pos']) . '위' => $it['rank_pos'] !== null]);
      ?>
        <a class="card<?= $it['used_at'] ? ' used' : '' ?>" href="<?= site_url('collect/' . $it['id']) ?>" data-id="<?= (int) $it['id'] ?>">
          <div class="thumb">
            <span class="cl-acts">
              <button class="cl-act use" type="button" title="사용한 글 표시/해제" aria-label="사용한 글 표시/해제">&#10003;</button>
              <button class="cl-act del" type="button" title="이 글 지우기" aria-label="이 글 지우기">&#10005;</button>
            </span>
            <?php if ($m && $m['first_img']): ?>
              <img src="<?= site_url('collect/media/' . $m['first_img']) ?>" alt="" loading="lazy">
            <?php elseif ($it['thumb_url']): ?>
              <img src="<?= esc($it['thumb_url'], 'attr') ?>" alt="" loading="lazy" referrerpolicy="no-referrer">
            <?php else: ?>
              <span class="ph"><?= esc(mb_substr($it['title_ko'] ?: $it['title'], 0, 60)) ?></span>
            <?php endif ?>
            <span class="badge"><?= esc(Collect::COUNTRIES[$it['country']] ?? $it['country']) ?></span>
            <?php if ((int) $it['breaking']): ?><span class="badge hot">속보 후보</span><?php endif ?>
            <?php if ($it['used_at']): ?><span class="badge used" title="<?= esc(Collect::when($it['used_at']), 'attr') ?>">사용함</span><?php endif ?>
            <?php if (! in_array($f['scope'], ['issue', 'x'], true)): ?><?php if (! (int) $it['relevant']): ?><span class="badge off">관련 없음</span><?php elseif ($it['news'] !== null && ! (int) $it['news']): ?><span class="badge off" title="<?= esc((string) $it['news_why'], 'attr') ?>">뉴스 아님</span><?php endif ?><?php elseif ((int) $it['relevant']): ?><span class="badge off">한류</span><?php endif ?>
            <?php if ($files || ($m['embeds'] ?? 0)): ?>
              <span class="dur"><?= $m['vids'] ? '영상 ' . (int) $m['vids'] . ' ' : '' ?><?= $m['imgs'] ? '사진 ' . (int) $m['imgs'] . ' ' : '' ?><?= $m['embeds'] ? 'YT ' . (int) $m['embeds'] : '' ?></span>
            <?php endif ?>
          </div>
          <div class="body">
            <div class="title"><?= esc($it['title_ko'] ?: $it['title']) ?></div>
            <div class="meta"><?= esc($names[$it['source_id']] ?? $it['source_id']) ?> · <?= esc(Collect::when($it['posted_at'] ?: $it['first_seen'])) ?><?= $stat ? ' · ' . esc(implode(' · ', array_keys($stat))) : '' ?></div>
            <?php if ($it['entities']): ?><div class="meta cl-ents"><?= esc($it['entities']) ?></div><?php endif ?>
          </div>
        </a>
      <?php endforeach ?>
    </div>
    <?php if ($pages > 1): ?>
      <nav class="pager">
        <a class="pg<?= $page <= 1 ? ' off' : '' ?>" href="<?= $qs(['page' => $page - 1]) ?>">이전</a>
        <?php for ($n = max(1, $page - 4); $n <= min($pages, $page + 4); $n++): ?>
          <a class="pg<?= $n === $page ? ' on' : '' ?>" href="<?= $qs(['page' => $n]) ?>"><?= $n ?></a>
        <?php endfor ?>
        <a class="pg<?= $page >= $pages ? ' off' : '' ?>" href="<?= $qs(['page' => $page + 1]) ?>">다음</a>
      </nav>
    <?php endif ?>
  <?php endif ?>
</main>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?>
<script>
/* card buttons: "used" check and delete, without leaving the list */
(function () {
  const base = <?= json_encode(rtrim(site_url(), '/') . '/') ?>;
  const post = (url, body) => { const c = MV.csrf(); return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': c ? c.hash : '' }, body: JSON.stringify(body || {}) }).then(async r => { const j = await r.json().catch(() => ({})); if (!r.ok || !j.ok) throw new Error(j.error || MV.httpError(r.status)); return j; }); };
  document.querySelectorAll('.cl-grid .card').forEach(card => {
    const id = card.dataset.id;
    card.querySelector('.cl-act.use')?.addEventListener('click', async (e) => {
      e.preventDefault(); e.stopPropagation();
      try {
        const j = await post(base + 'collect/' + id + '/used', { on: card.classList.contains('used') ? '0' : '1' });
        card.classList.toggle('used', j.used);
        let b = card.querySelector('.badge.used');
        if (j.used && !b) { b = document.createElement('span'); b.className = 'badge used'; b.textContent = '사용함'; card.querySelector('.thumb').appendChild(b); }
        if (!j.used && b) b.remove();
      } catch (err) { alert(err.message); }
    });
    card.querySelector('.cl-act.del')?.addEventListener('click', async (e) => {
      e.preventDefault(); e.stopPropagation();
      try { await post(base + 'collect/' + id + '/delete'); card.remove(); } catch (err) { alert(err.message); }
    });
  });
})();
</script>
<?= $this->endSection() ?>
