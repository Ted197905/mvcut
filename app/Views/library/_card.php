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
      <?php if (($postSize ?? 1) > 1): ?><span class="stack"><?= (int) $postSize ?>개</span><?php endif ?>
    </div>
    <div class="body">
      <?php
        $label = ($it['category'] !== '' ? $it['category'] . ' | ' : '') . $it['title'];
        $fmt   = \App\Libraries\MediaSupport::formatLabel($it);
        $noX   = \App\Libraries\MediaSupport::xUnsupported($it);
      ?>
      <div class="title<?= $noX ? ' no-x' : '' ?>" title="<?= esc($label . ($noX ? ' (X 업로드 불가: ' . $noX . ')' : ''), 'attr') ?>"><?= esc($label) ?></div>
      <div class="meta"><?= $fmt !== '' ? '<span' . ($noX ? ' class="no-x"' : '') . '>' . esc($fmt) . '</span> · ' : '' ?><?= $it['width'] ? esc($it['width'] . '×' . $it['height']) . ' · ' : '' ?><?= esc(\App\Libraries\MediaSupport::size((int) $it['size'])) ?></div>
    </div>
  </a>
</div>
