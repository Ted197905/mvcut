// Deletes the signed-in account's own posts / replies / quotes of a date range from an X search
// (latest first: from:handle until: since: filter:..., composed on MV Cut's page; the profile timeline
// before 1.8.8), through X's own UI (the "..." menu > 삭제 > confirm), the way a person would: scrolling down
// a screen at a time with uneven pauses, a longer breather now and then, deleting one post at a time.
// Nothing goes through X's API directly: direct GraphQL calls got the account flagged as automated (2026-10-27).
// job.to = the newer bound (the walk starts there), job.from = the older bound (0: down to the first post).
// Started from MV Cut's X 관리 page (background.js hands the job over on 'x-ready'); progress goes back
// to that page. Mode 'scan' only counts and lists what would be deleted.

(async () => {
  const job = await chrome.runtime.sendMessage({ type: 'x-purge-ready' });
  if (!job) return;

  const STATUS = /^\/([A-Za-z0-9_]{1,15})\/status\/(\d+)/;
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
  const jitter = (a, b) => a + Math.random() * (b - a);
  // a person's pace: most pauses short, some long; about one in ten actions gets a breather
  const pause = async (a, b) => { await sleep(jitter(a, b)); if (Math.random() < 0.1) await sleep(jitter(3000, 7000)); };
  // scroll like a wheel / trackpad: a screen or so, in a few steps, sometimes a little back up first
  const scrollDown = async () => {
    if (Math.random() < 0.15) { window.scrollBy({ top: -jitter(80, 240), behavior: 'smooth' }); await sleep(jitter(400, 900)); }
    const total = window.innerHeight * jitter(0.6, 1.3);
    const steps = 3 + Math.floor(Math.random() * 3);
    for (let i = 0; i < steps; i++) { window.scrollBy({ top: total / steps, behavior: 'smooth' }); await sleep(jitter(90, 220)); }
  };
  let stopped = false;
  chrome.runtime.onMessage.addListener((m) => { if (m && m.type === 'x-purge-stop') stopped = true; });

  const report = (data) => chrome.runtime.sendMessage({ type: 'x-purge-progress', job: job.id, ...data }).catch(() => {});

  const banner = document.createElement('div');
  banner.style.cssText = 'position:fixed;left:50%;top:16px;transform:translateX(-50%);z-index:2147483647;padding:10px 16px;border-radius:10px;'
    + 'font:600 15px system-ui;color:#fff;background:#c77700;box-shadow:0 6px 20px rgba(0,0,0,.3);max-width:80vw';
  document.body.appendChild(banner);
  const say = (t) => { banner.textContent = 'MV Cut 게시물 정리: ' + t; };

  const waitFor = (sel, ms = 20000) => new Promise((resolve, reject) => {
    const hit = document.querySelector(sel);
    if (hit) return resolve(hit);
    const t = setTimeout(() => { ob.disconnect(); reject(new Error(sel + ' 없음')); }, ms);
    const ob = new MutationObserver(() => {
      const el = document.querySelector(sel);
      if (el) { clearTimeout(t); ob.disconnect(); resolve(el); }
    });
    ob.observe(document.documentElement, { childList: true, subtree: true });
  });

  const viewer = () => {
    const a = document.querySelector('[data-testid="AppTabBar_Profile_Link"]');
    const h = a && (a.getAttribute('href') || '').replace(/^\//, '');
    return /^[A-Za-z0-9_]{1,15}$/.test(h || '') ? h : '';
  };

  // one timeline article -> {el, id, handle, time, kind, pinned, text}; null for anything that is not a post
  const read = (a) => {
    const t = a.querySelector('time');
    const link = t && t.closest('a');
    const m = link && STATUS.exec(link.getAttribute('href') || '');
    if (!m) return null;
    const social = ((a.querySelector('[data-testid="socialContext"]') || {}).innerText || '');
    const head = (a.innerText || '').slice(0, 400);
    const textEl = a.querySelector('[data-testid="tweetText"]');
    const reply = /(?:Replying to|님에게 보내는 답글)/.test(head);
    const quote = a.querySelectorAll('[data-testid="User-Name"]').length > 1;
    return {
      el: a, id: m[2], handle: m[1], time: Date.parse(t.getAttribute('datetime')) / 1000,
      repost: /repost|재게시/i.test(social), pinned: /pinned|고정/i.test(social),
      kind: reply ? 'reply' : (quote ? 'quote' : 'post'),
      text: textEl ? textEl.innerText.slice(0, 200) : '',
    };
  };

  // deletes the article's post through its menu; true when the confirm sheet was clicked
  const remove = async (r) => {
    r.el.scrollIntoView({ block: 'center', behavior: 'smooth' });
    await sleep(jitter(700, 1400));   // a glance at the post before the menu
    const caret = r.el.querySelector('button[data-testid="caret"]');
    if (!caret) throw new Error('메뉴 버튼 없음');
    caret.click();
    await waitFor('div[role="menu"] [role="menuitem"]', 8000);
    await sleep(jitter(400, 900));
    const item = Array.from(document.querySelectorAll('div[role="menu"] [role="menuitem"]')).find((x) => /^(삭제|Delete)(\b|$)/.test((x.innerText || '').trim()));
    if (!item) {
      document.body.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
      await sleep(300);
      throw new Error('삭제 메뉴 없음 (내 글이 아니거나 메뉴가 다름)');
    }
    item.click();
    const ok = await waitFor('[data-testid="confirmationSheetConfirm"]', 8000);
    await sleep(jitter(500, 1100));
    ok.click();
    // the article leaves the timeline; wait for it (or give it 4 s)
    for (let i = 0; i < 20 && document.contains(r.el); i++) await sleep(200);
    return !document.contains(r.el);
  };

  // X throttles a run of deletions (replies above all): at most RATE_N in any RATE_WINDOW, then a wait (user, 2026-10-30)
  const RATE_N = 50, RATE_WINDOW = 15 * 60 * 1000;
  const attempts = [];   // times of the last deletion attempts
  const throttle = async () => {
    while (!stopped) {
      const now = Date.now();
      while (attempts.length && now - attempts[0] > RATE_WINDOW) attempts.shift();
      if (attempts.length < RATE_N) return;
      const wait = attempts[0] + RATE_WINDOW - now + 2000;
      const until = new Date(now + wait);
      say('15분에 ' + RATE_N + '건까지만: ' + Math.ceil(wait / 60000) + '분 쉬는 중 (' + until.getHours() + ':' + String(until.getMinutes()).padStart(2, '0') + '에 계속)');
      report({ found, deleted, failed, done: false, waiting: Math.ceil(wait / 1000) });
      await sleep(Math.min(wait, 30000));
    }
  };

  const kinds = new Set(job.kinds || ['post', 'reply']);
  const seen = new Set();    // every post id that has been on screen
  const done = new Map();    // id -> item reported
  const items = [];
  let found = 0, deleted = 0, failed = 0, oldest = Infinity, idle = 0;
  const me = (job.handle || '').toLowerCase();

  try {
    // a search with nothing in it shows no article at all: that is a finished run, not a failure
    const first = await Promise.race([waitFor('article[data-testid="tweet"]', 30000).then(() => true), sleep(30000).then(() => false)]);
    await sleep(1000);
    const v = viewer();
    if (!v) throw new Error('X에 로그인되어 있지 않습니다');
    if (v.toLowerCase() !== me) throw new Error('로그인 계정(@' + v + ')이 감시 계정(@' + job.handle + ')과 다릅니다');
    say((job.mode === 'scan' ? '대상 세는 중' : '삭제 중') + ' ...');
    if (!first) idle = 99;   // no result: the loop below ends at once

    while (!stopped && idle < 10) {
      const arts = Array.from(document.querySelectorAll('article[data-testid="tweet"]'));
      let acted = false, fresh = 0, prev = null;
      for (const a of arts) {
        const r = read(a);
        const before = prev;
        prev = r;
        if (r && !seen.has(r.id)) { seen.add(r.id); fresh++; }   // anything new on screen, mine or not: the page is still loading more
        if (!r || r.handle.toLowerCase() !== me || r.repost) continue;
        // in a conversation the post right above (someone else's) is the parent: this one is a reply
        if (r.kind === 'post' && before && before.handle.toLowerCase() !== me) {
          const cell = a.closest('[data-testid="cellInnerDiv"]'), pcell = before.el.closest('[data-testid="cellInnerDiv"]');
          if (cell && pcell && cell.previousElementSibling === pcell) r.kind = 'reply';
        }
        if (!r.pinned && r.time < oldest) oldest = r.time;
        if (done.has(r.id) || r.pinned || (job.from && r.time < job.from) || r.time > job.to || !kinds.has(r.kind)) continue;
        const it = { id: r.id, time: r.time, kind: r.kind, text: r.text, ok: null };
        done.set(r.id, it);
        items.push(it);
        found++;
        if (job.mode === 'scan') {
          report({ found, deleted, failed, last: r.text, done: false });
          continue;
        }
        await throttle();
        if (stopped) { done.delete(r.id); items.pop(); found--; break; }
        attempts.push(Date.now());
        try {
          it.ok = await remove(r);
          if (it.ok) deleted++; else { failed++; it.error = '화면에서 사라지지 않음'; }
        } catch (e) {
          it.ok = false; it.error = e.message; failed++;
        }
        say('삭제 ' + deleted + '건' + (failed ? ', 실패 ' + failed : '') + ' ...');
        report({ found, deleted, failed, last: r.text, done: false, item: it });
        acted = true;
        await pause(1800, 4500);
        break;   // the timeline shifted: read it again
      }
      if (acted) continue;
      // nothing left on screen: older posts are further down, unless the range is already behind us
      if (job.from && oldest < job.from - 86400 * 3) break;   // 3 days past the end of the range (threads can be out of order)
      idle = fresh ? 0 : idle + 1;
      if (idle >= 10) break;                                  // the end of the timeline (10 screens without anything new)
      await scrollDown();
      await pause(900, 2400);
    }
    const status = stopped ? 'stopped' : 'done';
    say(status === 'stopped' ? '중지됨' : (job.mode === 'scan' ? '대상 ' + found + '건' : '끝: 삭제 ' + deleted + '건, 실패 ' + failed + '건'));
    report({ found, deleted, failed, done: true, status, items });
  } catch (e) {
    say('오류: ' + e.message);
    report({ found, deleted, failed, done: true, status: 'error', error: e.message, items });
  }
  setTimeout(() => banner.remove(), 15000);
})();
