<?php
use App\Libraries\XWatch;

$tl    = $st['timeline'];
$check = $history[0] ?? null; // decoded row (result, ts); $st['check'] is the compact API shape
$badge = static fn ($ban) => $ban === true ? ['bad', '제한'] : ($ban === false ? ['ok', '정상'] : ['none', '확인 불가']);
$kinds = ['post' => '게시', 'reply' => '답글', 'quote' => '인용', 'repost' => '재게시'];
$ago   = static function (int $ts): string {
    $s = time() - $ts;
    if ($s < 60) return '방금';
    if ($s < 3600) return intdiv($s, 60) . '분 전';
    if ($s < 86400) return intdiv($s, 3600) . '시간 ' . intdiv($s % 3600, 60) . '분 전';
    return intdiv($s, 86400) . '일 전';
};
$gapTxt = static fn (?int $m) => $m === null ? '-' : ($m >= 60 ? intdiv($m, 60) . '시간 ' . ($m % 60) . '분' : $m . '분');
?>
<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>
<main class="wide settings xwatch">
  <h1>X 계정 관리</h1>
  <p class="muted" style="margin:0 0 22px"><?= $w['handle'] !== '' ? '@' . esc($w['handle']) : '아이디 미설정' ?> · 시간은 한국 시간(KST)</p>
  <?= view('partials/alerts') ?>

  <section class="set-card">
    <div class="xw-head">
      <h2>제한 검사</h2>
      <?php if ($check): ?>
        <?php $cls = ['ok' => 'ok', 'banned' => 'bad', 'error' => 'warn'][$check['status']] ?? 'none'; ?>
        <span class="ck-badge <?= $cls ?>"><?= $check['status'] === 'ok' ? '정상' : ($check['status'] === 'banned' ? '제한 감지' : '검사 실패') ?></span>
      <?php endif ?>
    </div>
    <?php if (! $st['probe']): ?>
      <p class="ck-alert">검사용 부계정 쿠키가 없어 검사를 할 수 없습니다. 아래 "검사용 부계정"에 등록하세요.</p>
    <?php endif ?>
    <?php if ($check): ?>
      <p class="small" style="margin:0 0 14px"><?= esc($check['summary']) ?> <span class="muted">· <?= esc(XWatch::kst($check['ts'])) ?> (<?= $ago($check['ts']) ?>)<?= ! empty($check['result']['source']) ? ' · ' . esc(XWatch::SOURCES[$check['result']['source']] ?? $check['result']['source']) : '' ?></span></p>
      <div class="xw-tests">
        <?php foreach (XWatch::TESTS as $k => $label): $b = $badge($check['result'][$k]['ban'] ?? null); ?>
          <div class="xw-test"><span><?= esc($label) ?></span><span class="ck-badge <?= $b[0] ?>"><?= $b[1] ?></span></div>
        <?php endforeach ?>
      </div>
    <?php else: ?>
      <p class="muted small">아직 검사 기록이 없습니다.</p>
    <?php endif ?>
    <div class="ck-actions">
      <form method="post" action="<?= site_url('xwatch/check') ?>"><?= csrf_field() ?><button class="btn sm" type="submit">지금 검사</button></form>
      <a class="btn sm secondary" href="<?= site_url('xwatch/checks') ?>">검사 기록 보기</a>
      <span class="muted small">
        <?= ! $w['enabled'] ? '자동 검사 꺼짐' : ($st['restricted'] ? '제한 감지 모드: 풀릴 때까지 1시간마다 부계정으로만 검사' : '10분마다 자동 검사 (:00·:30 부계정, :10·:40 yuzurisa, :20·:50 banchecker)') ?><?php if ($st['next_check'] && $w['enabled']): ?> · 다음 <?= esc(XWatch::kst($st['next_check'], 'H:i')) ?><?php endif ?>
      </span>
    </div>
  </section>

  <section class="set-card" id="telegram">
    <h2>텔레그램 알림 <?php if (! empty($tg['chat_id'])): ?><span class="ck-badge ok">연결됨</span><?php elseif (! empty($tg['token'])): ?><span class="ck-badge warn">채팅 연결 필요</span><?php else: ?><span class="ck-badge none">미설정</span><?php endif ?></h2>
    <p class="muted small" style="margin-bottom:14px">제한이 감지되거나 풀리면 휴대폰 텔레그램으로 알려줍니다.</p>
    <?php if (empty($tg['token'])): ?>
      <ol class="small" style="margin:0 0 14px;padding-left:20px;line-height:1.7">
        <li>텔레그램에서 <b>@BotFather</b>를 열고 <code>/newbot</code> 을 보냅니다.</li>
        <li>봇 이름과 아이디(끝이 bot)를 정하면 <b>토큰</b>(숫자:문자열)을 줍니다.</li>
        <li>그 토큰을 아래에 붙여 넣고 저장합니다.</li>
      </ol>
      <form method="post" action="<?= site_url('xwatch/telegram') ?>" class="ck-actions"><?= csrf_field() ?>
        <input type="hidden" name="action" value="token">
        <input class="input" name="token" placeholder="123456789:AA..." autocomplete="off" required style="max-width:420px">
        <button class="btn sm" type="submit">토큰 저장</button>
      </form>
    <?php else: ?>
      <p class="small" style="margin:0 0 12px">봇: <b>@<?= esc($tg['bot'] ?? '') ?></b><?php if (! empty($tg['chat_id'])): ?> · 받는 사람: <?= esc($tg['chat'] ?? $tg['chat_id']) ?><?php endif ?></p>
      <?php if (empty($tg['chat_id'])): ?>
        <p class="small" style="margin:0 0 12px">텔레그램에서 <a href="https://t.me/<?= esc($tg['bot'] ?? '', 'attr') ?>" target="_blank" rel="noopener">@<?= esc($tg['bot'] ?? '') ?></a> 을 열고 <b>시작</b>을 누른 다음 "채팅 연결"을 누르세요.</p>
      <?php endif ?>
      <div class="ck-actions">
        <form method="post" action="<?= site_url('xwatch/telegram') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="link"><button class="btn sm" type="submit"><?= empty($tg['chat_id']) ? '채팅 연결' : '채팅 다시 연결' ?></button></form>
        <?php if (! empty($tg['chat_id'])): ?>
          <form method="post" action="<?= site_url('xwatch/telegram') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="test"><button class="btn sm secondary" type="submit">테스트 보내기</button></form>
        <?php endif ?>
        <form method="post" action="<?= site_url('xwatch/telegram') ?>" onsubmit="return confirm('텔레그램 알림 설정을 삭제할까요?')"><?= csrf_field() ?><input type="hidden" name="action" value="off"><button class="btn sm ghost" type="submit">해제</button></form>
      </div>
    <?php endif ?>
  </section>

  <section class="set-card">
    <div class="xw-head">
      <h2>게시 간격 (최근 48시간)</h2>
      <span class="ck-badge <?= $tl['can_post'] ? 'ok' : 'warn' ?>"><?= $tl['can_post'] ? '지금 게시 가능' : esc(XWatch::kst($tl['next_ok'], 'H:i')) . ' 이후 권장' ?></span>
    </div>
    <div class="stats">
      <div class="stat"><b><?= $tl['hour'] ?></b><span>최근 1시간 (기준 <?= (int) $w['hour_max'] ?> 미만)</span></div>
      <div class="stat"><b><?= $tl['day'] ?></b><span>최근 24시간 (기준 <?= (int) $w['day_max'] ?> 미만)</span></div>
      <div class="stat"><b><?= $tl['last'] ? esc($ago($tl['last'])) : '-' ?></b><span>마지막 게시</span></div>
      <div class="stat"><b><?= (int) $tl['reply_hour'] ?></b><span>최근 1시간 답글</span></div>
    </div>
    <?php foreach ($tl['warnings'] as $m): ?><p class="ck-alert warn" style="margin:0 0 8px"><?= esc($m) ?></p><?php endforeach ?>

    <?php if (! $tl['posts']): ?>
      <p class="muted small">기록된 게시물이 없습니다. 크롬 확장을 켠 상태로 x.com에서 내 프로필(게시물/답글 탭)을 열면 기록됩니다.</p>
    <?php else: ?>
      <div class="xw-posts">
        <?php foreach ($tl['posts'] as $p): ?>
          <div class="xw-post<?= $p['short'] ? ' short' : ($p['gap'] !== null ? ' kept' : '') ?><?= $p['counted'] ? '' : ' dim' ?>">
            <div class="xw-time"><b><?= esc(XWatch::kst($p['ts'], 'H:i')) ?></b><span><?= esc(XWatch::kst($p['ts'], 'm-d')) ?></span></div>
            <div class="xw-body">
              <div class="xw-meta">
                <span class="xw-kind <?= esc($p['kind']) ?>"><?= $kinds[$p['kind']] ?? esc($p['kind']) ?></span>
                <?php if ($p['reply_to']): ?><span class="muted small">@<?= esc($p['reply_to']) ?>에게</span><?php endif ?>
                <span class="xw-gap"><?= $p['counted'] ? '간격(인용 포함) ' . esc($gapTxt($p['gap'])) . ' · 인용 제외 ' . esc($gapTxt($p['gap_noquote'] ?? null)) : '간격 -' ?></span>
              </div>
              <a class="xw-text" href="https://x.com/<?= esc($w['handle']) ?>/status/<?= esc($p['id']) ?>" target="_blank" rel="noopener"><?= $p['text'] !== '' ? esc($p['text']) : '<span class="muted">(본문 없음 / 미디어)</span>' ?></a>
            </div>
            <form method="post" action="<?= site_url('xwatch/posts/' . $p['id'] . '/delete') ?>"><?= csrf_field() ?><button class="btn ghost sm" type="submit" title="기록에서 삭제">삭제</button></form>
          </div>
        <?php endforeach ?>
      </div>
    <?php endif ?>
  </section>

  <section class="set-card">
    <h2>검사 이력</h2>
    <p><a class="btn sm secondary" href="<?= site_url('xwatch/checks') ?>">전체 검사 기록 보기</a></p>
    <?php if (! $history): ?>
      <p class="muted small">없음</p>
    <?php else: ?>
      <dl class="list">
        <?php foreach ($history as $h): $cls = ['ok' => 'ok', 'banned' => 'bad', 'error' => 'warn'][$h['status']] ?? 'none'; ?>
          <div class="row"><dt><?= esc(XWatch::kst($h['ts'])) ?></dt><dd><span class="ck-badge <?= $cls ?>"><?= esc($h['summary']) ?></span></dd></div>
        <?php endforeach ?>
      </dl>
    <?php endif ?>
  </section>

  <section class="set-card">
    <h2>설정</h2>
    <form method="post" action="<?= site_url('xwatch/settings') ?>">
      <?= csrf_field() ?>
      <div class="field"><label for="handle">감시할 X 아이디</label>
        <input class="input" id="handle" name="handle" value="<?= esc($w['handle']) ?>" placeholder="blackout_kr" maxlength="16" required></div>
      <div class="xw-grid">
        <div class="field"><label for="gap_min">최소 간격 (분)</label><input class="input" type="number" id="gap_min" name="gap_min" min="0" max="720" value="<?= (int) $w['gap_min'] ?>"></div>
        <div class="field"><label for="hour_max">1시간 경고 개수</label><input class="input" type="number" id="hour_max" name="hour_max" min="1" max="100" value="<?= (int) $w['hour_max'] ?>"></div>
        <div class="field"><label for="day_max">24시간 경고 개수</label><input class="input" type="number" id="day_max" name="day_max" min="1" max="500" value="<?= (int) $w['day_max'] ?>"></div>
      </div>
      <label class="xw-check"><input type="checkbox" name="enabled" value="1" <?= $w['enabled'] ? 'checked' : '' ?>> 10분마다 자동 검사 (:00·:30 부계정, :10·:40 yuzurisa, :20·:50 banchecker)</label>
      <p class="muted small">제한이 감지되면 풀릴 때까지 매시 정각에 부계정으로만 검사하고, 부계정 검사에서 정상이 나오면 10분 주기로 돌아갑니다.</p>
      <label class="xw-check"><input type="checkbox" name="count_replies" value="1" <?= ! empty($w['count_replies']) ? 'checked' : '' ?>> 답글도 간격/개수 계산에 포함</label>
      <p class="muted small">기본은 게시물과 인용만 계산합니다. 재게시(리포스트)는 목록에만 표시합니다.</p>
      <button class="btn" type="submit">저장</button>
    </form>
  </section>

  <section class="set-card">
    <h2>크롬 확장 연결</h2>
    <?php if ($token): ?>
      <p class="small">아래 토큰을 확장 설정에 붙여 넣으세요. 이 화면을 벗어나면 다시 볼 수 없습니다.</p>
      <input class="input" readonly value="<?= esc($token) ?>" onclick="this.select()" style="font-family:ui-monospace,monospace;margin-bottom:12px">
    <?php else: ?>
      <p class="muted small"><?= $w['token_hash'] ? '토큰이 발급되어 있습니다. 잃어버렸으면 새로 발급하세요.' : '확장이 이 서버에 게시 기록을 보내고 상태를 받으려면 토큰이 필요합니다.' ?></p>
    <?php endif ?>
    <form method="post" action="<?= site_url('xwatch/token') ?>"><?= csrf_field() ?><button class="btn sm secondary" type="submit"><?= $w['token_hash'] ? '토큰 재발급' : '토큰 발급' ?></button></form>
    <p class="muted small" style="margin-top:14px">서버 주소: <code><?= esc(rtrim(base_url(), '/')) ?></code></p>
  </section>

  <section class="set-card">
    <h2>검사용 부계정</h2>
    <p class="muted small" style="margin-bottom:14px">검사는 이 부계정 세션으로만 합니다. 감시하는 본 계정의 쿠키는 절대 등록하지 마세요(같은 계정이면 검사가 거부됩니다). 부계정이 감시 계정을 차단/뮤트하지 않은 상태여야 합니다.</p>
    <?php $c = $probe; $state = ! $c['exists'] ? ['none', '미등록'] : ($c['expired'] ? ['bad', '만료됨'] : ($c['failure'] ? ['bad', '오류'] : ($c['soon'] ? ['warn', '곧 만료'] : ['ok', '정상']))); ?>
    <div class="ck">
      <div class="ck-head"><b>X 부계정 cookies.txt</b><span class="ck-badge <?= $state[0] ?>"><?= $state[1] ?></span></div>
      <?php if ($c['failure']): ?><p class="ck-alert"><?= esc($c['failure']['message']) ?></p><?php endif ?>
      <div class="ck-actions">
        <form method="post" action="<?= site_url('settings/cookies/' . XWatch::PROBE) ?>" enctype="multipart/form-data">
          <?= csrf_field() ?><input type="hidden" name="back" value="xwatch">
          <input class="input" type="file" name="cookies" accept=".txt,text/plain" required>
          <button class="btn sm" type="submit"><?= $c['exists'] ? '교체' : '등록' ?></button>
        </form>
      </div>
    </div>
  </section>
</main>
<?= $this->endSection() ?>
