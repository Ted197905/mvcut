<a class="card" href="<?= site_url('library/' . $it['id']) ?>">
  <div class="thumb">
    <?php if ($it['has_thumb']): ?>
      <img src="<?= site_url('media/' . $it['id'] . '/thumb') ?>" alt="" loading="lazy">
    <?php else: ?>
      <span><?= esc($it['media_type']) ?></span>
    <?php endif ?>
    <?php if ($it['duration']): ?><span class="dur"><?= gmdate($it['duration'] >= 3600 ? 'G:i:s' : 'i:s', (int) $it['duration']) ?></span><?php endif ?>
    <span class="badge"><?= esc($it['source']) ?></span>
  </div>
  <div class="body">
    <div class="title"><?= esc($it['title']) ?></div>
    <div class="meta"><?= $it['width'] ? esc($it['width'] . 'x' . $it['height']) . ' / ' : '' ?><?= number_format($it['size'] / 1048576, 1) ?> MB</div>
  </div>
</a>
