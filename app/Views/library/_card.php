<div class="card-wrap">
  <label class="pick"><input type="checkbox" name="ids[]" value="<?= (int) $it['id'] ?>"></label>
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
      <div class="title" title="<?= esc($it['title'], 'attr') ?>"><?= esc($it['title']) ?></div>
      <div class="meta"><?= $it['width'] ? esc($it['width'] . '×' . $it['height']) . ' · ' : '' ?><?= esc(\App\Libraries\MediaSupport::size((int) $it['size'])) ?></div>
    </div>
  </a>
</div>
