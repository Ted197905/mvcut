<?php $toast = session('toast') ?? session()->getFlashdata('flash'); if ($toast): session()->remove('toast'); ?>
  <div class="toast" role="status" aria-live="polite" onanimationend="if (event.animationName === 'toast-out') this.remove()"><?= esc($toast) ?></div>
<?php endif ?>
<?php $errors = session()->getFlashdata('errors'); if (! empty($errors)): ?>
  <div class="alert error"><?php foreach ($errors as $e): ?><div><?= esc($e) ?></div><?php endforeach ?></div>
<?php endif ?>
