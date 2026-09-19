<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>
<main class="wide settings">
  <h1>설정</h1>
  <?= view('partials/alerts') ?>

  <section class="set-card">
    <h2>계정 정보</h2>
    <form method="post" action="<?= site_url('settings/account') ?>" novalidate>
      <?= csrf_field() ?>
      <div class="field"><label for="display_name">이름</label>
        <input class="input" type="text" id="display_name" name="display_name" value="<?= esc(old('display_name', $user['display_name'])) ?>" maxlength="60" required></div>
      <div class="field"><label for="email">이메일</label>
        <input class="input" type="email" id="email" name="email" value="<?= esc(old('email', $user['email'])) ?>" maxlength="190" required></div>
      <p class="muted small">이메일이나 비밀번호를 바꾸려면 현재 비밀번호가 필요합니다.</p>
      <div class="field"><label for="current_password">현재 비밀번호</label>
        <input class="input" type="password" id="current_password" name="current_password" autocomplete="current-password"></div>
      <div class="field"><label for="new_password">새 비밀번호 (8자 이상, 바꾸지 않으려면 비워 두세요)</label>
        <input class="input" type="password" id="new_password" name="new_password" autocomplete="new-password"></div>
      <div class="field"><label for="new_password_confirm">새 비밀번호 확인</label>
        <input class="input" type="password" id="new_password_confirm" name="new_password_confirm" autocomplete="new-password"></div>
      <button class="btn" type="submit">계정 정보 저장</button>
    </form>
  </section>

  <section class="set-card">
    <h2>가져오기 로그인 쿠키</h2>
    <p class="muted small" style="margin-bottom:18px">로그인해야 보이는 게시물을 가져오려면 브라우저에서 내보낸 쿠키가 필요합니다. 비밀번호는 저장하지 않습니다. 쿠키는 서버 전체가 함께 사용하며, 등록한 계정으로 접속합니다.</p>

    <?php foreach ($cookies as $key => $c): ?>
      <?php
        $state = ! $c['exists'] ? ['none', '미등록']
               : ($c['expired'] ? ['bad', '만료됨']
               : ($c['failure'] ? ['bad', '오류']
               : ($c['soon'] ? ['warn', '곧 만료'] : ['ok', '정상'])));
      ?>
      <div class="ck">
        <div class="ck-head">
          <b><?= esc($c['label']) ?></b>
          <span class="ck-badge <?= $state[0] ?>"><?= esc($state[1]) ?></span>
        </div>

        <?php if ($c['exists']): ?>
          <dl class="list">
            <div class="row"><dt>등록</dt><dd><?= esc(date('Y-m-d H:i', (int) $c['uploaded'])) ?></dd></div>
            <div class="row"><dt>만료</dt><dd><?= $c['expires'] ? esc(date('Y-m-d', (int) $c['expires'])) . ($c['expired'] ? '' : ' (' . (int) ceil(($c['expires'] - time()) / 86400) . '일 남음)') : '알 수 없음' ?></dd></div>
            <div class="row"><dt>쿠키</dt><dd><?= esc(implode(', ', array_slice($c['names'], 0, 8))) ?></dd></div>
          </dl>
          <?php if ($c['expired'] || $c['failure']): ?>
            <p class="ck-alert">쿠키를 다시 발급받아야 합니다.<?php if ($c['failure']): ?> 마지막 실패(<?= esc(date('m-d H:i', (int) $c['failure']['at'])) ?>): <?= esc($c['failure']['message']) ?><?php endif ?></p>
          <?php elseif ($c['soon']): ?>
            <p class="ck-alert warn">곧 만료됩니다. 미리 다시 내보내 두세요.</p>
          <?php endif ?>
        <?php else: ?>
          <p class="muted small">등록된 쿠키가 없습니다. 로그인이 필요한 게시물은 가져올 수 없습니다.</p>
        <?php endif ?>

        <div class="ck-actions">
          <form method="post" action="<?= site_url('settings/cookies/' . $key) ?>" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input class="input" type="file" name="cookies" accept=".txt,text/plain" required>
            <button class="btn sm" type="submit"><?= $c['exists'] ? '교체' : '등록' ?></button>
          </form>
          <?php if ($c['exists']): ?>
            <form method="post" action="<?= site_url('settings/cookies/' . $key . '/delete') ?>">
              <?= csrf_field() ?>
              <button class="btn sm ghost" type="submit">삭제</button>
            </form>
          <?php endif ?>
        </div>
      </div>
    <?php endforeach ?>

    <details class="ck-help">
      <summary>쿠키 내보내는 방법</summary>
      <ol>
        <li>Chrome에 쿠키 내보내기 확장을 설치합니다 (예: Get cookies.txt LOCALLY).</li>
        <li>해당 사이트에 로그인한 상태에서 확장 아이콘을 눌러 Netscape 형식으로 Export 합니다.</li>
        <li>내려받은 <code>cookies.txt</code>를 위에서 업로드합니다.</li>
      </ol>
      <p class="muted small">쿠키는 보통 수주에서 수개월 뒤 만료됩니다. 가져오기가 실패하면 이 화면에 다시 발급하라고 표시됩니다.</p>
    </details>
  </section>
</main>
<?= $this->endSection() ?>
