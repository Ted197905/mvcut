<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>
<?php include __DIR__ . '/_status.php'; ?>
<main class="yt-page">
  <?= view('partials/alerts') ?>
  <h1>YouTube 자막 다운로드</h1>
  <p class="muted" style="margin:0 0 22px">영상 링크를 넣으면 영상과 제목·설명, 영상이 제공하는 모든 자막(없으면 자동 생성 자막)을 내려받고, 출연진과 전체 내용을 정리합니다.</p>
  <?php if (! $ytdlp): ?><div class="alert error">서버에 yt-dlp가 없어 가져올 수 없습니다.</div><?php endif ?>
  <?php if (! $ollama): ?><div class="alert">Ollama 서버가 꺼져 있어 출연진·요약 분석은 건너뜁니다. 영상과 자막은 받습니다.</div><?php endif ?>

  <form class="import-panel yt-form" method="post" action="<?= site_url('youtube') ?>">
    <?= csrf_field() ?>
    <div class="import-row">
      <div class="url-field">
        <input class="input" type="url" name="url" id="ytUrl" placeholder="https://www.youtube.com/watch?v=... 또는 youtu.be/..." autocomplete="off" required autofocus>
      </div>
      <button class="btn secondary" type="button" id="btnPaste" title="클립보드에서 붙여넣기">붙여넣기</button>
      <button class="btn" type="submit">가져오기</button>
    </div>
  </form>

  <form class="lib-toolbar" method="get" action="<?= site_url('youtube') ?>">
    <input class="input search" type="search" name="q" value="<?= esc($q) ?>" placeholder="제목 · 채널 · 출연진 · 요약 검색" autocomplete="off">
    <button class="btn secondary sm" type="submit">검색</button>
    <?php if ($q !== ''): ?><a class="btn ghost sm" href="<?= site_url('youtube') ?>">전체 보기</a><?php endif ?>
    <span class="spacer"></span>
    <span class="muted small"><?= number_format($matched) ?>개</span>
  </form>

  <?php if ($items === []): ?>
    <div class="empty"><?= $q !== '' ? '검색 결과가 없습니다.' : '아직 가져온 영상이 없습니다.' ?></div>
  <?php else: ?>
    <div class="grid yt-grid">
      <?php foreach ($items as $it): [$label, $cls] = $ytStatus($it['status']); $subs = \App\Models\YtVideoModel::subs($it); ?>
        <a class="card" href="<?= site_url('youtube/' . $it['id']) ?>">
          <div class="thumb">
            <?php if ($it['has_thumb']): ?>
              <img src="<?= site_url('youtube/' . $it['id'] . '/thumb') ?>" alt="" loading="lazy">
            <?php else: ?>
              <span class="muted small"><?= esc($label) ?></span>
            <?php endif ?>
            <?php if ($it['duration']): ?><span class="dur"><?= gmdate($it['duration'] >= 3600 ? 'G:i:s' : 'i:s', (int) $it['duration']) ?></span><?php endif ?>
            <?php if ($it['status'] !== 'ready'): ?><span class="badge yt-<?= $cls ?>"><?= esc($label) ?></span><?php endif ?>
          </div>
          <div class="body">
            <div class="title"><?= esc($it['title'] ?: $it['video_id']) ?></div>
            <div class="meta"><?= esc($it['channel']) ?><?= $it['channel'] !== '' ? ' · ' : '' ?>자막 <?= count($subs) ?>개<?= $subs && $subs[0]['auto'] ? ' (자동)' : '' ?><?= $it['summary'] ? ' · 요약' : '' ?></div>
          </div>
        </a>
      <?php endforeach ?>
    </div>
    <?php if ($pages > 1): ?>
      <nav class="pager">
        <?php for ($n = 1; $n <= $pages; $n++): ?>
          <a class="pg<?= $n === $page ? ' on' : '' ?>" href="<?= site_url('youtube?' . http_build_query(array_filter(['q' => $q, 'page' => $n]))) ?>"><?= $n ?></a>
        <?php endfor ?>
      </nav>
    <?php endif ?>
  <?php endif ?>
</main>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?>
<script>
document.getElementById('btnPaste').addEventListener('click', async () => {
  try { document.getElementById('ytUrl').value = (await navigator.clipboard.readText()).trim(); } catch (e) {}
});
</script>
<?= $this->endSection() ?>
