<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>
<main class="narrow">
  <div class="form-card">
    <h1>로그인</h1>
    <?= view('partials/alerts') ?>
    <form method="post" action="<?= site_url('login') ?>" novalidate>
      <?= csrf_field() ?>
      <div class="field"><label for="email">이메일</label>
        <input class="input" type="email" id="email" name="email" value="<?= esc(old('email')) ?>" autocomplete="email" autofocus required></div>
      <div class="field"><label for="password">비밀번호</label>
        <input class="input" type="password" id="password" name="password" autocomplete="current-password" required></div>
      <button class="btn block" type="submit">로그인</button>
    </form>
    <div class="form-foot">계정이 없으신가요? <a href="<?= site_url('register') ?>">회원가입</a></div>
  </div>
</main>
<?= $this->endSection() ?>
