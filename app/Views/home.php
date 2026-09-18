<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>
<section class="hero">
  <h1>MV Cut</h1>
  <p>여러 플랫폼의 영상과 이미지를 모아, 가볍고 빠르게 편집해서 SNS에 올리세요.</p>
  <div class="actions">
    <a class="btn" href="<?= site_url('register') ?>">시작하기</a>
    <a class="btn secondary" href="<?= site_url('login') ?>">로그인</a>
  </div>
</section>
<section class="features">
  <div class="feature"><h4>라이브러리</h4><p>업로드하거나 SNS 링크로 가져온 원본을 한 곳에 모읍니다.</p></div>
  <div class="feature"><h4>Timeline Crop / Cut</h4><p>필요한 구간만 남기거나, 중간을 잘라내고 이어붙입니다.</p></div>
  <div class="feature"><h4>Screen Crop / Cut</h4><p>화면 영역을 SNS 규격에 맞게 자르거나 가립니다.</p></div>
  <div class="feature"><h4>Format Convert</h4><p>원하는 포맷과 해상도로 변환해서 바로 내려받습니다.</p></div>
</section>
<?= $this->endSection() ?>
