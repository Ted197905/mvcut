<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>
<main class="wide settings">
  <h1>관리자</h1>
  <?= view('partials/alerts') ?>

  <section class="set-card">
    <h2>계정 <span class="muted small">(<?= count($users) ?>개<?= $pending ? ' · 승인 대기 ' . $pending . '개' : '' ?>)</span></h2>
    <p class="muted small" style="margin-bottom:18px">신규 가입은 승인 후 로그인할 수 있고, AI 기능(프레임 생성, 선명하게, AI 지우기, 대상 추적, 배경 채우기)은 켜 준 계정만 쓸 수 있습니다. 서버 GPU를 쓰는 기능이라 기본은 꺼짐입니다.</p>

    <div class="adm-list">
      <?php foreach ($users as $u): ?>
        <?php
          $st = match ($u['status']) {
              'active'  => ['ok', '사용 중'],
              'blocked' => ['bad', '중지'],
              default   => ['warn', '승인 대기'],
          };
        ?>
        <div class="adm-row">
          <div class="adm-who">
            <b><?= esc($u['display_name']) ?></b>
            <span class="muted small"><?= esc($u['email']) ?></span>
            <span class="muted small">가입 <?= esc(substr((string) $u['created_at'], 0, 10)) ?> · 미디어 <?= (int) ($media[$u['id']] ?? 0) ?>개 · 작업 <?= (int) ($jobs[$u['id']] ?? 0) ?>개</span>
          </div>
          <div class="adm-badges">
            <span class="ck-badge <?= $st[0] ?>"><?= $st[1] ?></span>
            <span class="ck-badge <?= $u['ai_enabled'] ? 'ok' : 'none' ?>">AI <?= $u['ai_enabled'] ? '허용' : '차단' ?></span>
            <?php if ($u['role'] === 'admin'): ?><span class="ck-badge warn">관리자</span><?php endif ?>
          </div>
          <div class="adm-acts">
            <?php if ($u['status'] === 'pending'): ?>
              <form method="post" action="<?= site_url('admin/users/' . $u['id']) ?>"><?= csrf_field() ?>
                <input type="hidden" name="action" value="approve"><button class="btn sm" type="submit">승인</button></form>
            <?php elseif ($u['status'] === 'blocked'): ?>
              <form method="post" action="<?= site_url('admin/users/' . $u['id']) ?>"><?= csrf_field() ?>
                <input type="hidden" name="action" value="activate"><button class="btn sm" type="submit">사용 재개</button></form>
            <?php else: ?>
              <form method="post" action="<?= site_url('admin/users/' . $u['id']) ?>"><?= csrf_field() ?>
                <input type="hidden" name="action" value="block"><button class="btn sm ghost" type="submit">사용 중지</button></form>
            <?php endif ?>

            <form method="post" action="<?= site_url('admin/users/' . $u['id']) ?>"><?= csrf_field() ?>
              <input type="hidden" name="action" value="<?= $u['ai_enabled'] ? 'ai_off' : 'ai_on' ?>">
              <button class="btn sm <?= $u['ai_enabled'] ? 'ghost' : '' ?>" type="submit">AI <?= $u['ai_enabled'] ? '차단' : '허용' ?></button></form>

            <form method="post" action="<?= site_url('admin/users/' . $u['id']) ?>"><?= csrf_field() ?>
              <input type="hidden" name="action" value="<?= $u['role'] === 'admin' ? 'drop_admin' : 'make_admin' ?>">
              <button class="btn sm ghost" type="submit"><?= $u['role'] === 'admin' ? '관리자 해제' : '관리자 지정' ?></button></form>
          </div>
        </div>
      <?php endforeach ?>
    </div>
  </section>
</main>
<?= $this->endSection() ?>
