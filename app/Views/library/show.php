<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>
<main>
  <p class="small"><a href="<?= site_url('library') ?>">&larr; 라이브러리</a></p>
  <h1><?= esc($item['title']) ?></h1>
  <p class="sub"><?= esc(ucfirst($item['media_type'])) ?> · <?= esc($item['source']) ?> · <?= esc($item['created_at']) ?></p>
  <?= view('partials/alerts') ?>
  <div class="detail">
    <div class="player">
      <?php if ($item['media_type'] === 'video' && $playable): ?>
        <video controls playsinline preload="metadata" src="<?= site_url('media/' . $item['id'] . '/proxy') ?>" <?= $item['has_thumb'] ? 'poster="' . site_url('media/' . $item['id'] . '/thumb') . '"' : '' ?>></video>
      <?php elseif ($item['media_type'] === 'image'): ?>
        <img src="<?= site_url('media/' . $item['id'] . '/file') ?>" alt="">
      <?php elseif ($item['media_type'] === 'audio'): ?>
        <audio controls src="<?= site_url('media/' . $item['id'] . '/file') ?>"></audio>
      <?php elseif ($item['has_thumb']): ?>
        <img src="<?= site_url('media/' . $item['id'] . '/thumb') ?>" alt="">
      <?php else: ?>
        <span class="muted">브라우저에서 바로 재생할 수 없는 포맷입니다. 프록시 생성 후 미리보기가 가능합니다.</span>
      <?php endif ?>
    </div>
    <div>
      <h3 style="margin-top:0">정보</h3>
      <dl class="kv">
        <dt>파일</dt><dd><?= esc($item['filename']) ?></dd>
        <dt>형식</dt><dd><?= esc($item['container'] ?? $item['mime']) ?></dd>
        <?php if ($item['vcodec']): ?><dt>비디오</dt><dd><?= esc($item['vcodec']) ?><?= $item['fps'] ? ' / ' . esc(rtrim(rtrim($item['fps'], '0'), '.')) . ' fps' : '' ?></dd><?php endif ?>
        <?php if ($item['acodec']): ?><dt>오디오</dt><dd><?= esc($item['acodec']) ?></dd><?php endif ?>
        <?php if ($item['width']): ?><dt>해상도</dt><dd><?= esc($item['width'] . ' x ' . $item['height']) ?></dd><?php endif ?>
        <?php if ($item['duration']): ?><dt>길이</dt><dd><?= gmdate($item['duration'] >= 3600 ? 'G:i:s' : 'i:s', (int) $item['duration']) ?></dd><?php endif ?>
        <dt>크기</dt><dd><?= number_format($item['size'] / 1048576, 2) ?> MB</dd>
      </dl>
      <div class="actions">
        <?php if ($item['media_type'] === 'video'): ?><a class="btn" href="<?= site_url('edit/' . $item['id']) ?>">편집</a><?php endif ?>
        <a class="btn secondary" href="<?= site_url('media/' . $item['id'] . '/file?dl=1') ?>">다운로드</a>
        <form method="post" action="<?= site_url('library/' . $item['id'] . '/delete') ?>" onsubmit="return confirm('삭제하시겠습니까?')">
          <?= csrf_field() ?>
          <button class="btn secondary" type="submit">삭제</button>
        </form>
      </div>
    </div>
  </div>
</main>
<?= $this->endSection() ?>
