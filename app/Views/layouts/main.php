<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf" data-name="<?= csrf_token() ?>" content="<?= csrf_hash() ?>">
<title><?= isset($title) && $title !== 'MV Cut' ? esc($title) . ' - MV Cut' : 'MV Cut' ?></title>
<link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>">
<?= $this->renderSection('head') ?>
</head>
<body>
<nav class="nav"><div class="in">
  <a class="brand" href="<?= site_url('/') ?>">MV Cut</a>
  <?php if (session()->get('user_id')): ?>
    <a href="<?= site_url('library') ?>" class="<?= uri_string() === 'library' ? 'active' : '' ?>">라이브러리</a>
    <span class="spacer"></span>
    <a href="<?= site_url('settings') ?>" class="<?= str_starts_with(uri_string(), 'settings') || uri_string() === 'account' ? 'active' : '' ?>">설정</a>
    <span class="nav-user"><?= esc(session()->get('display_name')) ?></span>
    <form method="post" action="<?= site_url('logout') ?>"><?= csrf_field() ?><button class="linkbtn" type="submit">로그아웃</button></form>
  <?php else: ?>
    <span class="spacer"></span>
    <a href="<?= site_url('login') ?>">로그인</a>
    <a href="<?= site_url('register') ?>">회원가입</a>
  <?php endif ?>
</div></nav>
<?= $this->renderSection('content') ?>
<script src="<?= base_url('assets/js/app.js') ?>"></script>
<?= $this->renderSection('scripts') ?>
</body>
</html>
