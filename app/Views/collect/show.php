<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>
<?php
use App\Controllers\Collect;

$num = static fn ($v) => number_format((int) $v);
$sendable = static fn (array $m) => ($m['status'] === 'done' && in_array($m['kind'], ['image', 'video'], true))
    || ($m['status'] === 'embed' && $m['kind'] === 'youtube');
$ytId = static fn (string $u) => preg_match('/[?&]v=([\w-]{11})/', $u, $mm) ? $mm[1] : null;
$stateLabel = ['pending' => '받는 중', 'failed' => '실패', 'robots' => 'robots 불허', 'too_big' => '용량 초과'];
$picks = array_filter($media, $sendable);
$body = trim((string) ($item['body'] ?: $item['excerpt']));
?>
<main class="yt-page cl-page">
  <?= view('partials/alerts') ?>
  <p class="meta-line" style="margin-bottom:14px"><a href="<?= site_url('collect') ?>">&larr; 목록</a></p>
  <div class="cl-detail">
    <div class="cl-main">
      <h1 class="cl-title"><?= esc($item['title']) ?></h1>
      <p class="meta-line">
        <span><?= esc($src['name']) ?></span><span class="dot">·</span>
        <span><?= esc(Collect::COUNTRIES[$item['country']] ?? $item['country']) ?></span><span class="dot">·</span>
        <span><?= esc(Collect::when($item['posted_at'] ?: $item['first_seen'])) ?></span>
        <?php if ($item['author']): ?><span class="dot">·</span><span><?= esc($item['author']) ?></span><?php endif ?>
        <span class="dot">·</span><a href="<?= esc($item['url'], 'attr') ?>" target="_blank" rel="noopener noreferrer">원문 보기</a>
      </p>

      <?php if ($body !== ''): ?>
        <div class="desc-body cl-body" id="descBody"><?= nl2br(esc(mb_substr($body, 0, 20000))) ?></div>
        <button class="btn ghost sm" type="button" id="descMore" hidden>더보기</button>
      <?php elseif ($item['detail_status'] === 'pending'): ?>
        <p class="muted">본문과 미디어를 받는 중입니다.</p>
      <?php elseif ($item['detail_status'] === 'failed'): ?>
        <div class="alert error">본문을 받지 못했습니다<?= $item['detail_error'] ? ': ' . esc($item['detail_error']) : '' ?></div>
      <?php endif ?>

      <?php if ($media !== []): ?>
        <form method="post" action="<?= site_url('collect/' . $item['id'] . '/library') ?>" id="libForm">
          <?= csrf_field() ?>
          <div class="cl-media">
            <?php foreach ($media as $m): $ok = $sendable($m); ?>
              <div class="post-item">
                <?php if ($m['status'] === 'done' && $m['kind'] === 'image'): ?>
                  <div class="viewer"><img src="<?= site_url('collect/media/' . $m['id']) ?>" alt="" loading="lazy"></div>
                <?php elseif ($m['status'] === 'done' && $m['kind'] === 'video'): ?>
                  <div class="viewer"><video controls playsinline preload="metadata" src="<?= site_url('collect/media/' . $m['id']) ?>"></video></div>
                <?php elseif ($m['kind'] === 'youtube' && ($vid = $ytId($m['src_url']))): ?>
                  <div class="viewer"><iframe src="https://www.youtube-nocookie.com/embed/<?= esc($vid, 'attr') ?>" allowfullscreen loading="lazy" title="YouTube"></iframe></div>
                <?php else: ?>
                  <div class="viewer cl-missing"><span><?= esc($stateLabel[$m['status']] ?? $m['status']) ?></span></div>
                <?php endif ?>
                <div class="post-item-bar">
                  <?php if ($ok): ?><label class="cl-check"><input type="checkbox" name="media[]" value="<?= (int) $m['id'] ?>" checked> 선택</label><?php endif ?>
                  <span class="muted small"><?= $m['kind'] === 'youtube' ? 'YouTube' : ($m['kind'] === 'video' ? '영상' : '사진') ?><?= $m['bytes'] ? ' · ' . esc(\App\Libraries\MediaSupport::size((int) $m['bytes'])) : '' ?><?= $m['width'] ? ' · ' . (int) $m['width'] . '×' . (int) $m['height'] : '' ?></span>
                  <span class="spacer"></span>
                  <a class="small" href="<?= esc($m['src_url'], 'attr') ?>" target="_blank" rel="noopener noreferrer">원본</a>
                </div>
              </div>
            <?php endforeach ?>
          </div>
        </form>
      <?php endif ?>
    </div>

    <aside class="cl-side">
      <div class="stats">
        <?php foreach (['views' => '조회', 'comments' => '댓글', 'likes' => '추천', 'rank_pos' => '순위', 'heat' => '열기'] as $k => $v): if ($item[$k] === null) continue; ?>
          <div class="stat"><b><?= $num($item[$k]) ?></b><span><?= $v ?></span></div>
        <?php endforeach ?>
      </div>
      <?php if ($picks !== []): ?>
        <button class="btn block" type="submit" form="libForm">선택 항목 라이브러리로 보내기</button>
        <p class="muted small" style="margin:8px 0 0">사진·영상은 바로 복사하고, YouTube는 가져오기 작업으로 넣습니다.<?= $inLib ? ' 이미 라이브러리에 이 글의 파일이 ' . $inLib . '개 있습니다.' : '' ?></p>
      <?php endif ?>
      <dl class="list cl-facts">
        <div class="row"><dt>분야</dt><dd><?= esc($item['domain'] ?? '-') ?></dd></div>
        <div class="row"><dt>이벤트</dt><dd><?= esc($item['event'] ?? '-') ?><?= (int) $item['breaking'] ? ' (속보 후보)' : '' ?></dd></div>
        <?php if ($item['entities']): ?><div class="row"><dt>그룹/인물</dt><dd><?= esc($item['entities']) ?></dd></div><?php endif ?>
        <?php if ($item['category'] || $item['board']): ?><div class="row"><dt>게시판</dt><dd><?= esc(trim($item['board'] . ' ' . $item['category'])) ?></dd></div><?php endif ?>
        <?php if ($item['tags']): ?><div class="row"><dt>태그</dt><dd><?= esc($item['tags']) ?></dd></div><?php endif ?>
        <div class="row"><dt>처음 수집</dt><dd><?= esc(Collect::when($item['first_seen'])) ?></dd></div>
        <div class="row"><dt>마지막 확인</dt><dd><?= esc(Collect::when($item['last_seen'])) ?></dd></div>
      </dl>
    </aside>
  </div>
</main>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?>
<script>
/* long bodies (forum threads) are clamped behind 더보기 so the media stays in view */
(function () {
  const body = document.getElementById('descBody'), more = document.getElementById('descMore');
  if (!body || !more || body.scrollHeight <= 168 + 24) return;
  body.classList.add('clamped'); more.hidden = false;
  more.addEventListener('click', () => { more.textContent = body.classList.toggle('clamped') ? '더보기' : '접기'; });
})();
</script>
<?= $this->endSection() ?>
