<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>
<main class="narrow">
  <div class="form-card">
    <h1>계정</h1>
    <?= view('partials/alerts') ?>
    <form method="post" action="<?= site_url('account') ?>" novalidate>
      <?= csrf_field() ?>
      <div class="field"><label>이메일</label>
        <input class="input" type="email" value="<?= esc($user['email']) ?>" disabled></div>
      <div class="field"><label for="display_name">이름</label>
        <input class="input" type="text" id="display_name" name="display_name" value="<?= esc(old('display_name', $user['display_name'])) ?>" maxlength="60" required></div>
      <h3>비밀번호 변경</h3>
      <p class="muted small">변경하지 않으려면 비워 두세요.</p>
      <div class="field"><label for="current_password">현재 비밀번호</label>
        <input class="input" type="password" id="current_password" name="current_password" autocomplete="current-password"></div>
      <div class="field"><label for="new_password">새 비밀번호 (8자 이상)</label>
        <input class="input" type="password" id="new_password" name="new_password" autocomplete="new-password"></div>
      <div class="field"><label for="new_password_confirm">새 비밀번호 확인</label>
        <input class="input" type="password" id="new_password_confirm" name="new_password_confirm" autocomplete="new-password"></div>
      <button class="btn block" type="submit">저장</button>
    </form>
  </div>
</main>
<?= $this->endSection() ?>
