<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf" data-name="<?= csrf_token() ?>" content="<?= csrf_hash() ?>">
<meta name="expired-url" content="<?= site_url('session/expired') ?>">
<title>MV Cut<?= ! empty($title) && $title !== 'MV Cut' ? ' - ' . esc($title) : '' ?></title>
<?= view('partials/favicon') ?>
<link rel="stylesheet" href="<?= asset_url('assets/css/app.css') ?>">
<?= $this->renderSection('head') ?>
</head>
<body>
<nav class="nav"><div class="in">
  <a class="brand" href="<?= site_url(session()->get('user_id') ? 'library' : '/') ?>"><img src="<?= base_url('favicon/android-chrome-192x192.png') ?>" alt="" width="26" height="26"><span>MV Cut</span></a>
  <span class="spacer"></span>
  <button class="nav-toggle" type="button" id="navToggle" aria-controls="navLinks" aria-expanded="false" aria-label="메뉴"><span></span><span></span><span></span></button>
  <div class="nav-links" id="navLinks">
  <a href="<?= site_url('youtube') ?>" class="<?= str_starts_with(uri_string(), 'youtube') ? 'active' : '' ?>">YouTube</a>
  <?php if (session()->get('user_id')): ?>
    <?php if (session()->get('role') === 'admin'): ?>
      <a href="<?= site_url('admin') ?>" class="<?= str_starts_with(uri_string(), 'admin') ? 'active' : '' ?>">관리자</a>
      <a href="<?= site_url('collect') ?>" class="<?= str_starts_with(uri_string(), 'collect') ? 'active' : '' ?>">수집</a>
      <a href="<?= site_url('drafts') ?>" class="<?= str_starts_with(uri_string(), 'drafts') ? 'active' : '' ?>">초안</a>
      <?php $errs = \App\Libraries\AppLog::recentErrors() ?>
      <a href="<?= site_url('admin/logs') ?>" class="<?= str_starts_with(uri_string(), 'admin/logs') ? 'active' : '' ?>">로그<?= $errs ? ' <span class="nav-badge" title="24시간 안의 오류">' . $errs . '</span>' : '' ?></a>
    <?php endif ?>
    <a href="<?= site_url('keywords') ?>" class="<?= str_starts_with(uri_string(), 'keywords') ? 'active' : '' ?>">키워드</a>
    <a href="<?= site_url('xwatch') ?>" class="<?= str_starts_with(uri_string(), 'xwatch') ? 'active' : '' ?>">X 관리</a>
    <a href="<?= site_url('settings') ?>" class="<?= str_starts_with(uri_string(), 'settings') || uri_string() === 'account' ? 'active' : '' ?>">설정</a>
    <span class="nav-user"><?= esc(session()->get('display_name')) ?></span>
    <form method="post" action="<?= site_url('logout') ?>"><?= csrf_field() ?><button class="linkbtn" type="submit">로그아웃</button></form>
  <?php else: ?>
    <a href="<?= site_url('login') ?>">로그인</a>
    <a href="<?= site_url('register') ?>">회원가입</a>
  <?php endif ?>
  </div>
</div></nav>
<?= $this->renderSection('content') ?>
<?php if (session()->get('user_id')): ?>
<script>
(function () {
  var n = 0, u = <?= json_encode(site_url('log/js')) ?>;
  function send(msg, detail) {
    if (n++ > 4) return;
    var c = document.querySelector('meta[name="csrf"]'), b = new FormData();
    b.append('msg', msg); b.append('detail', detail || ''); b.append('url', location.href);
    if (c) b.append(c.dataset.name, c.content);
    try { fetch(u, { method: 'POST', body: b, keepalive: true, credentials: 'same-origin' }).catch(function () {}); } catch (e) {}
  }
  window.addEventListener('error', function (e) {
    if (!e.message) return;   // resource load failures carry no message
    send(e.message, (e.filename || '') + ':' + (e.lineno || 0) + ':' + (e.colno || 0) + '\n' + (e.error && e.error.stack || ''));
  });
  window.addEventListener('unhandledrejection', function (e) {
    var r = e.reason;
    send('unhandledrejection: ' + (r && r.message || String(r)), (r && r.stack) || '');
  });
})();
</script>
<?php endif ?>
<script src="<?= asset_url('assets/js/app.js') ?>"></script>
<?php if (session()->get('role') === 'admin'): ?><?= view('partials/opsbar') ?><?php endif ?>
<?= $this->renderSection('scripts') ?>
</body>
</html>
