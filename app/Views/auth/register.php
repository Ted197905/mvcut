<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>
<main class="narrow">
  <div class="form-card">
    <h1>회원가입</h1>
    <?= view('partials/alerts') ?>
    <form method="post" action="<?= site_url('register') ?>" novalidate>
      <?= csrf_field() ?>
      <div class="field"><label for="display_name">이름</label>
        <input class="input" type="text" id="display_name" name="display_name" value="<?= esc(old('display_name')) ?>" autocomplete="nickname" maxlength="60" autofocus required></div>
      <div class="field"><label for="email">이메일</label>
        <input class="input" type="email" id="email" name="email" value="<?= esc(old('email')) ?>" autocomplete="email" required></div>
      <div class="field"><label for="password">비밀번호 (8자 이상)</label>
        <input class="input" type="password" id="password" name="password" autocomplete="new-password" minlength="8" required></div>
      <div class="field"><label for="password_confirm">비밀번호 확인</label>
        <input class="input" type="password" id="password_confirm" name="password_confirm" autocomplete="new-password" required></div>
      <button class="btn block" type="submit">가입하기</button>
    </form>
    <div class="form-foot">이미 계정이 있으신가요? <a href="<?= site_url('login') ?>">로그인</a></div>
  </div>
</main>
<?= $this->endSection() ?>
