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
              <form method="post" action="<?= site_url('admin/users/' . $u['id']) ?>" onsubmit="return confirm('가입을 거절하고 계정을 삭제합니다. 계속할까요?')"><?= csrf_field() ?>
                <input type="hidden" name="action" value="delete"><button class="btn sm ghost" type="submit">거절 (삭제)</button></form>
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

  <section class="set-card">
    <h2>카테고리 <span class="muted small">(<?= count($categories) ?>개)</span></h2>
    <p class="muted small" style="margin-bottom:18px">라이브러리 미디어를 나누는 카테고리입니다. 이름을 바꾸면 지정된 미디어도 함께 바뀌고, 삭제하면 해당 미디어는 미지정이 됩니다.</p>
    <div class="adm-list">
      <?php foreach ($categories as $i => $c): ?>
        <div class="adm-row cat-row">
          <form class="cat-name" method="post" action="<?= site_url('admin/categories/' . $c['id']) ?>"><?= csrf_field() ?>
            <input type="hidden" name="action" value="rename">
            <input class="input" name="name" maxlength="32" value="<?= esc($c['name'], 'attr') ?>" required>
            <button class="btn sm secondary" type="submit">이름 저장</button>
          </form>
          <div class="adm-badges"><span class="ck-badge none">미디어 <?= (int) ($catUse[$c['name']] ?? 0) ?>개</span></div>
          <div class="adm-acts">
            <form method="post" action="<?= site_url('admin/categories/' . $c['id']) ?>"><?= csrf_field() ?>
              <input type="hidden" name="action" value="up"><button class="btn sm ghost" type="submit"<?= $i === 0 ? ' disabled' : '' ?>>위로</button></form>
            <form method="post" action="<?= site_url('admin/categories/' . $c['id']) ?>"><?= csrf_field() ?>
              <input type="hidden" name="action" value="down"><button class="btn sm ghost" type="submit"<?= $i === count($categories) - 1 ? ' disabled' : '' ?>>아래로</button></form>
            <form method="post" action="<?= site_url('admin/categories/' . $c['id']) ?>" onsubmit="return confirm('카테고리 &quot;<?= esc($c['name'], 'attr') ?>&quot;을(를) 삭제합니다. 지정된 미디어는 미지정이 됩니다. 계속할까요?')"><?= csrf_field() ?>
              <input type="hidden" name="action" value="delete"><button class="btn sm ghost" type="submit">삭제</button></form>
          </div>
        </div>
      <?php endforeach ?>
      <form class="adm-row cat-row cat-add" method="post" action="<?= site_url('admin/categories') ?>"><?= csrf_field() ?>
        <div class="cat-name">
          <input class="input" name="name" maxlength="32" placeholder="새 카테고리 이름" required>
          <button class="btn sm" type="submit">추가</button>
        </div>
      </form>
    </div>
  </section>
</main>
<?= $this->endSection() ?>
