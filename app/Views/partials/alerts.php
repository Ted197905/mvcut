<?php $toast = session('toast') ?? session()->getFlashdata('flash'); if ($toast): session()->remove('toast'); ?>
  <div class="toast" role="status" aria-live="polite" onanimationend="if (event.animationName === 'toast-out') this.remove()"><?= esc($toast) ?></div>
<?php endif ?>
<?php $errors = session()->getFlashdata('errors'); if (! empty($errors)): ?>
  <div class="alert error"><?php foreach ($errors as $e): ?><div><?= esc($e) ?></div><?php endforeach ?></div>
<?php endif ?>
<?php $err = session('toast_error') ?? session()->getFlashdata('error'); if ($err): session()->remove('toast_error'); ?>
  <div class="alert error"><?= str_contains((string) $err, 'not allowed') ? '보안 확인이 만료되어 요청을 처리하지 못했습니다. 페이지를 새로 불러온 뒤 다시 시도해 주세요.' : esc($err) ?></div>
<?php endif ?>
