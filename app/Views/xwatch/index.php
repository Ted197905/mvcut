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

  <div class="seg xw-tabs" role="tablist">
    <a class="seg-item" href="#gaps" data-tab="gaps">게시 간격</a>
    <a class="seg-item" href="#checks" data-tab="checks">검사 이력</a>
    <a class="seg-item" href="#settings" data-tab="settings">설정</a>
  </div>

  <div class="xw-tab" data-tab="gaps">
  <section class="set-card">
    <div class="xw-head">
      <h2>게시 간격 (최근 48시간)</h2>
      <span class="ck-badge <?= $tl['can_post'] ? 'ok' : 'warn' ?>"><?= $tl['can_post'] ? '지금 게시 가능' : esc(XWatch::kst($tl['next_ok'], 'h:i A')) . ' 이후 권장' ?></span>
    </div>
    <div class="stats">
      <div class="stat"><b><?= $tl['hour'] ?></b><span>최근 1시간 (기준 <?= (int) $w['hour_max'] ?> 미만)</span></div>
      <?php $schedToday = (int) ($st['scheduled']['today'] ?? 0); ?>
      <div class="stat"><b><?= $tl['day'] ?><?= $schedToday ? ' <i class="xw-sched-n">+ ' . $schedToday . '</i>' : '' ?></b><span>오늘 09시 이후<?= $schedToday ? ' + 예약' : '' ?> (기준 <?= (int) $w['day_max'] ?> 미만, 매일 09시 리셋)</span></div>
      <div class="stat"><b><?= $tl['last'] ? esc($ago($tl['last'])) : '-' ?></b><span>마지막 게시</span></div>
      <div class="stat"><b><?= (int) $tl['reply_hour'] ?></b><span>최근 1시간 답글</span></div>
    </div>
    <?php foreach ($tl['warnings'] as $m): ?><p class="ck-alert warn" style="margin:0 0 8px"><?= esc($m) ?></p><?php endforeach ?>

    <?php if (! $tl['posts']): ?>
      <p class="muted small">기록된 게시물이 없습니다. 크롬 확장을 켠 상태로 x.com에서 내 프로필(게시물/답글 탭)을 열면 기록됩니다.</p>
    <?php else: ?>
      <div class="xw-posts">
        <?php foreach ($tl['posts'] as $p): ?>
          <div class="xw-post<?= ! empty($p['danger']) ? ' danger' : ($p['short'] ? ' short' : ($p['gap'] !== null ? ' kept' : '')) ?><?= $p['counted'] ? '' : ' dim' ?>">
            <div class="xw-time"><b><?= esc(XWatch::kst($p['ts'], 'h:i A')) ?></b><span><?= esc(XWatch::kst($p['ts'], 'm-d')) ?></span></div>
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
  </div>

  <div class="xw-tab" data-tab="checks">
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
  </div>

  <div class="xw-tab" data-tab="settings">
  <section class="set-card">
    <h2>설정</h2>
    <form method="post" action="<?= site_url('xwatch/settings') ?>">
      <?= csrf_field() ?>
      <div class="field"><label for="handle">감시할 X 아이디</label>
        <input class="input" id="handle" name="handle" value="<?= esc($w['handle']) ?>" placeholder="blackout_kr" maxlength="16" required></div>
      <div class="xw-grid">
        <div class="field"><label for="gap_min">권장 간격 (분, 미만이면 주황)</label><input class="input" type="number" id="gap_min" name="gap_min" min="0" max="720" value="<?= (int) $w['gap_min'] ?>"></div>
        <div class="field"><label for="gap_warn">경고 간격 (분, 미만이면 빨강)</label><input class="input" type="number" id="gap_warn" name="gap_warn" min="0" max="720" value="<?= (int) ($w['gap_warn'] ?? 10) ?>"></div>
        <div class="field"><label for="hour_max">1시간 경고 개수</label><input class="input" type="number" id="hour_max" name="hour_max" min="1" max="100" value="<?= (int) $w['hour_max'] ?>"></div>
        <div class="field"><label for="day_max">하루 경고 개수 (09시 리셋)</label><input class="input" type="number" id="day_max" name="day_max" min="1" max="500" value="<?= (int) $w['day_max'] ?>"></div>
      </div>
      <label class="xw-check"><input type="checkbox" name="enabled" value="1" <?= $w['enabled'] ? 'checked' : '' ?>> 10분마다 자동 검사 (:00·:30 부계정, :10·:40 yuzurisa, :20·:50 banchecker)</label>
      <p class="muted small">제한이 감지되면 풀릴 때까지 매시 정각에 부계정으로만 검사하고, 부계정 검사에서 정상이 나오면 10분 주기로 돌아갑니다.</p>
      <label class="xw-check"><input type="checkbox" name="count_replies" value="1" <?= ! empty($w['count_replies']) ? 'checked' : '' ?>> 답글도 간격/개수 계산에 포함</label>
      <p class="muted small">기본은 게시물과 인용만 계산합니다. 재게시(리포스트)는 목록에만 표시합니다.</p>
      <button class="btn" type="submit">저장</button>
    </form>
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

  <section class="set-card" id="purge">
    <div class="xw-head"><h2>게시물 정리</h2><span class="ck-badge none xp-ext" hidden>확장 필요</span></div>
    <p class="muted small" style="margin:0 0 14px">아래 검색어로 X 검색(최신순)을 열고, 결과 중 "시작" 시각 이전 ~ "끝" 시각 사이의 내 글을 크롬 확장이 X 화면에서 하나씩 지웁니다(사람이 스크롤하며 메뉴 &gt; 삭제를 누르는 속도로, 50건 지우면 15분 쉬고, 지운 글이 다시 보이면 제한으로 보고 바로 15분 쉰 뒤 다시 시도합니다). 먼저 "대상 세기"로 몇 건인지 보고 지우세요. 고정 글과 재게시는 건드리지 않습니다. 지운 글은 되돌릴 수 없습니다.</p>
    <?php if ($w['handle'] === ''): ?>
      <p class="ck-alert">먼저 설정에서 X 아이디를 넣어야 합니다.</p>
    <?php else: ?>
    <div class="xp-row">
      <span class="small">시작 (이 날짜 이전 글부터 = until:)</span>
      <div class="seg xp-preset"><button class="seg-item" type="button" data-months="3">3개월 전</button><button class="seg-item" type="button" data-months="6">6개월 전</button><button class="seg-item" type="button" data-months="12">1년 전</button></div>
      <input class="input" type="date" id="xpFrom">
    </div>
    <div class="xp-row">
      <span class="small">끝 (이 날짜까지 거슬러 = since:)</span>
      <input class="input" type="date" id="xpTo">
      <label class="xw-check"><input type="checkbox" id="xpAll" checked> 끝까지 (계정의 첫 글까지)</label>
    </div>
    <div class="xp-row">
      <span class="small">키워드 (선택)</span>
      <input class="input" type="text" id="xpKw" placeholder="예: 이벤트, to:계정아이디, &quot;정확한 문구&quot;" style="min-width:280px">
    </div>
    <div class="xp-row">
      <span class="small">X 검색어</span>
      <input class="input xp-query" type="text" id="xpQuery" spellcheck="false">
      <button class="btn sm ghost" type="button" id="xpRebuild" hidden>다시 만들기</button>
    </div>
    <div class="xp-kinds">
      <label class="xw-check"><input type="radio" name="xpKind" value="post" checked> 게시물 (인용 포함)</label>
      <label class="xw-check"><input type="radio" name="xpKind" value="reply"> 댓글 (답글)</label>
    </div>
    <div class="xp-acts">
      <button class="btn sm secondary" type="button" id="xpScan">대상 세기</button>
      <button class="btn sm danger" type="button" id="xpDelete">삭제</button>
      <button class="btn sm ghost" type="button" id="xpStop" hidden>중지</button>
      <span class="small xp-status"></span>
      <a class="small xp-log" href="#" target="_blank" rel="noopener" hidden>로그</a>
    </div>
    <div class="xp-bar" hidden><i></i></div>
    <ul class="xp-list small muted" hidden></ul>
    <?php endif ?>
    <?php if ($purges): ?>
      <table class="xp-log small">
        <thead><tr><th>실행</th><th>기간 (시작 이전 ~ 끝)</th><th>검색어 / 종류</th><th>결과</th></tr></thead>
        <tbody>
        <?php $kn = ['post' => '게시물', 'reply' => '답글', 'quote' => '인용']; foreach ($purges as $p): ?>
          <tr>
            <td><?= esc(XWatch::kst(strtotime($p['created_at'] . ' UTC'), 'm-d H:i')) ?> <?= $p['mode'] === 'scan' ? '세기' : '삭제' ?></td>
            <td><?= esc(XWatch::kst(strtotime($p['to_at'] . ' UTC'), 'Y-m-d')) ?> 이전 ~ <?= strtotime($p['from_at'] . ' UTC') > 86400 ? esc(XWatch::kst(strtotime($p['from_at'] . ' UTC'), 'Y-m-d')) : '첫 글까지' ?></td>
            <td><?php if (! empty($p['query'])): ?><code><?= esc($p['query']) ?></code><br><?php endif ?><?= esc(implode(', ', array_map(static fn ($k) => $kn[$k] ?? $k, explode(',', $p['kinds'])))) ?></td>
            <td><?= ['running' => '진행 중', 'done' => '완료', 'stopped' => '중지', 'error' => '오류'][$p['status']] ?? esc($p['status']) ?> · 대상 <?= (int) $p['found'] ?><?= $p['mode'] === 'delete' ? ' · 삭제 ' . (int) $p['deleted'] . ($p['failed'] ? ' · 실패 ' . (int) $p['failed'] : '') : '' ?><?= $p['error'] ? ' · ' . esc($p['error']) : '' ?> · <a href="<?= site_url('xwatch/purge/' . (int) $p['id'] . '/log') ?>" target="_blank" rel="noopener">로그</a></td>
          </tr>
        <?php endforeach ?>
        </tbody>
      </table>
    <?php endif ?>
  </section>
  <script>
  (() => {
    const $ = (id) => document.getElementById(id);
    const from = $('xpFrom'), to = $('xpTo');
    if (!from) return;
    const status = document.querySelector('.xp-status'), bar = document.querySelector('.xp-bar'), list = document.querySelector('.xp-list');
    // 게시물 = post + quote, 댓글 = reply (the extension checks each post's kind; the query only adds filter:replies for 댓글)
    const kinds = () => (document.querySelector('.xp-kinds input:checked') || {}).value === 'reply' ? ['reply'] : ['post', 'quote'];
    const pad = (n) => String(n).padStart(2, '0');
    const local = (d) => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());   // date only (user, 2026-10-30)
    const all = $('xpAll'), kw = $('xpKw'), query = $('xpQuery'), rebuild = $('xpRebuild');
    const csrf = () => { const c = MV.csrf(); return c ? { 'X-CSRF-TOKEN': c.hash } : {}; };
    const handle = <?= json_encode($w['handle']) ?>;
    let edited = false;   // the user typed in the query box: inputs no longer overwrite it
    // the user's own search shapes (2026-10-30): 게시물 "from:h until:D", 댓글 "from:h until:D filter:replies";
    // the dates go into the query as typed: until: = the start date (posts before it), since: = the end date
    const build = () => {
      if (edited) return;
      const parts = [];
      if (kw.value.trim()) parts.push(kw.value.trim());
      parts.push('from:' + handle);
      if (from.value) parts.push('until:' + from.value);
      if (!all.checked && to.value) parts.push('since:' + to.value);
      if (kinds()[0] === 'reply') parts.push('filter:replies');
      query.value = parts.join(' ');
    };
    query.addEventListener('input', () => { edited = true; rebuild.hidden = false; });
    rebuild.onclick = () => { edited = false; rebuild.hidden = true; build(); };
    [kw, to].forEach((el) => el.addEventListener('input', build));
    document.querySelectorAll('.xp-kinds input').forEach((i) => i.addEventListener('change', build));
    const monthsAgo = (d, n) => { const x = new Date(d); x.setMonth(x.getMonth() - n); return x; };
    const setStart = (d) => { from.value = local(d); to.value = local(monthsAgo(d, 1)); build(); };   // the end: a month further back
    setStart(monthsAgo(new Date(), 3));
    document.querySelectorAll('.xp-preset button').forEach((b) => { b.onclick = () => {
      setStart(monthsAgo(new Date(), Number(b.dataset.months)));
      document.querySelectorAll('.xp-preset button').forEach((x) => x.classList.toggle('on', x === b));
    }; });
    document.querySelector('.xp-preset button').classList.add('on');
    from.addEventListener('change', () => { document.querySelectorAll('.xp-preset button').forEach((x) => x.classList.remove('on')); if (from.value) to.value = local(monthsAgo(new Date(from.value + 'T00:00'), 1)); build(); });
    all.onchange = () => { to.disabled = all.checked; build(); };
    to.disabled = all.checked;
    // the form's values are kept per account (x_watch.purge_prefs): restored here, saved a moment after any change
    const prefs = <?= json_encode($purgePrefs, JSON_UNESCAPED_UNICODE) ?>;
    if (prefs && prefs.from) {
      from.value = prefs.from; to.value = prefs.to || to.value; all.checked = !!prefs.all; to.disabled = all.checked; kw.value = prefs.kw || '';
      const kr = document.querySelector('.xp-kinds input[value="' + (prefs.kind === 'reply' ? 'reply' : 'post') + '"]'); if (kr) kr.checked = true;
      document.querySelectorAll('.xp-preset button').forEach((x) => x.classList.toggle('on', String(x.dataset.months) === String(prefs.preset || '')));
      build();
      if (prefs.query && prefs.query !== query.value) { query.value = prefs.query; edited = true; rebuild.hidden = false; }
    }
    let saveT = null;
    const savePrefs = () => {
      clearTimeout(saveT);
      saveT = setTimeout(() => {
        const on = document.querySelector('.xp-preset button.on');
        fetch('<?= site_url('xwatch/purge/prefs') ?>', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...csrf() },
          body: JSON.stringify({ preset: on ? Number(on.dataset.months) : 0, from: from.value, to: to.value, all: all.checked, kw: kw.value, kind: kinds()[0] === 'reply' ? 'reply' : 'post', query: edited ? query.value : '' }) }).catch(() => {});
      }, 800);
    };
    [from, to, all, kw, query].forEach((el) => { el.addEventListener('input', savePrefs); el.addEventListener('change', savePrefs); });
    document.querySelectorAll('.xp-kinds input, .xp-preset button, #xpRebuild').forEach((el) => el.addEventListener('click', savePrefs));
    setTimeout(() => { if (document.documentElement.dataset.mvcutX !== '1') { document.querySelector('.xp-ext').hidden = false; $('xpScan').disabled = $('xpDelete').disabled = true; } }, 800);
    let job = null, mode = '', items = [], lastSent = 0, pollT = null;
    const report = async (d) => {
      if (!job) return;
      await fetch('<?= site_url('xwatch/purge') ?>/' + job, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...csrf() },
        body: JSON.stringify({ status: d.status || 'running', found: d.found || 0, deleted: d.deleted || 0, failed: d.failed || 0, error: d.error || null, items: d.items || items }) }).catch(() => {});
    };
    const show = (d) => {
      const n = (d.found || 0);
      status.textContent = (mode === 'scan' ? '대상 ' + n + '건' : '대상 ' + n + '건 · 삭제 ' + (d.deleted || 0) + '건' + (d.failed ? ' · 실패 ' + d.failed + '건' : ''))
        + (d.done ? (d.status === 'stopped' ? ' · 중지됨' : d.status === 'error' ? ' · 오류: ' + (d.error || '') : ' · 완료')
                  : d.waiting ? ' · 50건 지우고 15분 휴식: ' + Math.ceil(d.waiting / 60) + '분 뒤 계속' : ' · 진행 중 (X 탭에서 작업 중)');
      if (mode === 'delete' && n) { bar.hidden = false; bar.firstElementChild.style.width = Math.round(((d.deleted || 0) + (d.failed || 0)) * 100 / n) + '%'; }
    };
    const finish = (d) => {
      $('xpScan').disabled = $('xpDelete').disabled = false; $('xpStop').hidden = true;
      clearTimeout(pollT); pollT = null;
      const reload = mode === 'delete' && d.deleted;
      job = null;
      if (reload) setTimeout(() => location.reload(), 1500);
    };
    // the run's state from the server (the extension reports there from the X tab, so a page reload or a closed
    // page loses nothing): every 3 s while a run is on
    const poll = async () => {
      if (!job) return;
      try {
        const r = await fetch('<?= site_url('xwatch/purge') ?>/' + job, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const d = await r.json();
        if (!d.ok || String(d.id) !== job) return;
        const done = d.status !== 'running';
        const stale = !done && Date.now() / 1000 - d.updated_at > 20 * 60;
        show({ found: d.found, deleted: d.deleted, failed: d.failed, done, status: d.status, error: d.error });
        if (stale) status.textContent += ' · 20분 넘게 보고 없음: X 탭을 확인하세요 (닫혔으면 다시 실행)';
        if (d.items && d.items.length && list.children.length < d.items.length) {
          list.innerHTML = '';
          d.items.forEach((it) => { const li = document.createElement('li'); li.textContent = (it.ok ? '삭제: ' : it.ok === null ? '재시도 예정 (' + (it.error || '') + '): ' : '실패 (' + (it.error || '') + '): ') + new Date(it.time * 1000).toLocaleString('ko-KR') + ' ' + (it.text || '(본문 없음)').slice(0, 80); list.appendChild(li); });
          list.hidden = false;
        }
        if (done) { finish(d); return; }
        if (stale) { $('xpScan').disabled = $('xpDelete').disabled = false; }
      } catch (e) { /* next tick */ }
      pollT = setTimeout(poll, 3000);
    };
    const start = async (m) => {
      // "시작" is the newer bound (where the walk down the timeline begins), "끝" the older one; 끝까지 = no older bound
      // the same dates as the query, at local midnight: posts before the start day, back to the end day
      const t = Math.floor(new Date(from.value + 'T00:00').getTime() / 1000), f = all.checked ? 0 : Math.floor(new Date(to.value + 'T00:00').getTime() / 1000), k = kinds();
      if (!t || (!all.checked && (!f || f >= t))) { alert('기간을 확인하세요. "끝"은 "시작"보다 이전 시각이어야 합니다.'); return; }
      if (!k.length) { alert('종류를 하나는 골라야 합니다.'); return; }
      const q = query.value.trim();
      if (!q) { alert('X 검색어가 비어 있습니다.'); return; }
      if (m === 'delete' && !confirm(from.value + ' 이전 ~ ' + (all.checked ? '첫 글까지' : to.value + ' 0시') + ' 사이의 ' + (k[0] === 'reply' ? '댓글(답글)' : '게시물(인용 포함)') + '을 X에서 지웁니다.\n검색어: ' + q + '\n되돌릴 수 없습니다. 계속할까요?')) return;
      const fd = new FormData(); fd.append('mode', m); fd.append('from', f); fd.append('to', t); fd.append('query', q); k.forEach((x) => fd.append('kinds[]', x));
      const c = MV.csrf(); if (c) fd.append(c.name, c.hash);
      const r = await fetch('<?= site_url('xwatch/purge') ?>', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      const j = await r.json().catch(() => ({}));
      if (!j.ok) { alert(j.error || MV.httpError(r.status)); return; }
      job = String(j.id); mode = m; items = []; lastSent = 0;
      const lg = document.querySelector('.xp-log'); lg.href = '<?= site_url('xwatch/purge') ?>/' + job + '/log'; lg.hidden = false;
      list.innerHTML = ''; list.hidden = true; bar.hidden = true;
      $('xpScan').disabled = $('xpDelete').disabled = true; $('xpStop').hidden = false;
      show({ found: 0 });
      window.postMessage({ source: 'mvcut', type: 'x-purge', action: 'start', job, mode: m, handle, kinds: k, from: f, to: t, query: q }, location.origin);
      clearTimeout(pollT); pollT = setTimeout(poll, 3000);
    };
    // a run that is still going (the page was reloaded or reopened): show it and offer 중지
    const running = <?= json_encode($purgeRun ? ['id' => (int) $purgeRun['id'], 'mode' => $purgeRun['mode']] : null) ?>;
    if (running) {
      job = String(running.id); mode = running.mode;
      const lg = document.querySelector('.xp-log'); lg.href = '<?= site_url('xwatch/purge') ?>/' + job + '/log'; lg.hidden = false;
      $('xpScan').disabled = $('xpDelete').disabled = true; $('xpStop').hidden = false;
      show({ found: 0 });
      poll();
    }
    $('xpScan').onclick = () => start('scan');
    $('xpDelete').onclick = () => start('delete');
    $('xpStop').onclick = () => window.postMessage({ source: 'mvcut', type: 'x-purge', action: 'stop', job }, location.origin);
    window.addEventListener('message', (e) => {
      const d = e.data;
      if (e.source !== window || !d || d.source !== 'mvcut-ext' || d.type !== 'x-purge-progress' || String(d.job) !== job) return;
      if (d.item) {
        items.push(d.item);
        const li = document.createElement('li');
        li.textContent = (d.item.ok ? '삭제: ' : d.item.ok === null ? '재시도 예정 (' + (d.item.error || '') + '): ' : '실패 (' + (d.item.error || '') + '): ') + new Date(d.item.time * 1000).toLocaleString('ko-KR') + ' ' + (d.item.text || '(본문 없음)').slice(0, 80);
        list.prepend(li); list.hidden = false;
      }
      if (d.done && Array.isArray(d.items)) items = d.items;
      show(d);
      if (d.done) {
        report(d).then(() => finish(d));   // the extension reports too; this covers an extension without a token
      } else if ((d.deleted || 0) + (d.failed || 0) >= lastSent + 10) {
        lastSent = (d.deleted || 0) + (d.failed || 0); report(d);
      }
    });
  })();
  </script>
  </div>
  <script>
  (() => {
    // three tabs, the hash picks one (#telegram and #purge belong to the settings tab)
    const own = { gaps: 'gaps', checks: 'checks', settings: 'settings', telegram: 'settings', purge: 'settings' };
    const show = (name) => {
      document.querySelectorAll('.xw-tab').forEach((t) => { t.hidden = t.dataset.tab !== name; });
      document.querySelectorAll('.xw-tabs .seg-item').forEach((a) => a.classList.toggle('on', a.dataset.tab === name));
    };
    const pick = () => {
      const h = location.hash.replace('#', '');
      show(own[h] || 'gaps');
      if (own[h] && h !== own[h]) { const el = document.getElementById(h); if (el) setTimeout(() => el.scrollIntoView(), 50); }
    };
    window.addEventListener('hashchange', pick);
    pick();
  })();
  </script>
</main>
<?= $this->endSection() ?>
