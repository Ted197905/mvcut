<?php if (session()->getFlashdata('flash')): ?>
  <div class="toast" role="status" aria-live="polite" onanimationend="if (event.animationName === 'toast-out') this.remove()"><?= esc(session()->getFlashdata('flash')) ?></div>
<?php endif ?>
<?php $errors = session()->getFlashdata('errors'); if (! empty($errors)): ?>
  <div class="alert error"><?php foreach ($errors as $e): ?><div><?= esc($e) ?></div><?php endforeach ?></div>
<?php endif ?>
