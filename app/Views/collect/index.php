<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>
<?php
use App\Controllers\Collect;

$qs = static fn (array $over) => site_url('collect?' . http_build_query(array_filter(array_merge(
    ['src' => $f['src'], 'cc' => $f['cc'], 'q' => $f['q'], 'sort' => $f['sort'] === 'new' ? '' : $f['sort'],
     'all' => $f['all'] ? '1' : '', 'media' => $f['media'] ? '1' : ''], $over), static fn ($v) => $v !== '' && $v !== null)));
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
  <p class="muted" style="margin:0 0 22px">hallyuwire가 국내외 커뮤니티에서 모은 글입니다. 기본은 K-연예 관련 글 중 미디어가 있거나 소식을 전하는 글만 보여 줍니다.</p>

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

  <form class="lib-toolbar" method="get" action="<?= site_url('collect') ?>">
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
    <label class="cl-check"><input type="checkbox" name="all" value="1"<?= $f['all'] ? ' checked' : '' ?> onchange="this.form.submit()"> 숨긴 글 포함</label>
    <button class="btn secondary sm" type="submit">검색</button>
    <?php if ($f['q'] !== '' || $f['src'] !== '' || $f['cc'] !== '' || $f['all'] || $f['media'] || $f['sort'] !== 'new'): ?><a class="btn ghost sm" href="<?= site_url('collect') ?>">초기화</a><?php endif ?>
    <span class="spacer"></span>
    <span class="muted small"><?= number_format($matched) ?>개</span>
  </form>

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
        <a class="card" href="<?= site_url('collect/' . $it['id']) ?>">
          <div class="thumb">
            <?php if ($m && $m['first_img']): ?>
              <img src="<?= site_url('collect/media/' . $m['first_img']) ?>" alt="" loading="lazy">
            <?php elseif ($it['thumb_url']): ?>
              <img src="<?= esc($it['thumb_url'], 'attr') ?>" alt="" loading="lazy" referrerpolicy="no-referrer">
            <?php else: ?>
              <span class="ph"><?= esc(mb_substr($it['title_ko'] ?: $it['title'], 0, 60)) ?></span>
            <?php endif ?>
            <span class="badge"><?= esc(Collect::COUNTRIES[$it['country']] ?? $it['country']) ?></span>
            <?php if ((int) $it['breaking']): ?><span class="badge hot">속보 후보</span><?php endif ?>
            <?php if (! (int) $it['relevant']): ?><span class="badge off">관련 없음</span><?php elseif ($it['news'] !== null && ! (int) $it['news']): ?><span class="badge off" title="<?= esc((string) $it['news_why'], 'attr') ?>">뉴스 아님</span><?php endif ?>
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
