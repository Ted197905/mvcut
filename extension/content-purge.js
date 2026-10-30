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
  chrome.runtime.onMessage.addListener((m) => {
    if (m && m.type === 'x-purge-stop' && (!m.job || String(m.job) === String(job.id))) { stopped = true; log('stop received'); }
  });

  // every step is logged (console + the server through background.js), so a run that misbehaves can be read back
  let logBuf = [];
  const log = (m) => {
    const line = new Date().toTimeString().slice(0, 8) + ' ' + m;
    console.log('[MV Cut purge]', line);
    logBuf.push(line);
  };
  const report = (data) => {
    const lines = logBuf; logBuf = [];
    return chrome.runtime.sendMessage({ type: 'x-purge-progress', job: job.id, log: lines, ...data }).catch(() => {});
  };

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

  // closes an open "..." menu (X puts a transparent mask behind it; Escape is the fallback)
  const closeMenu = async () => {
    for (let i = 0; i < 3 && document.querySelector('div[role="menu"]'); i++) {
      const mask = document.querySelector('[data-testid="mask"]');
      if (mask) mask.click();
      else for (const t of [document.activeElement, document.body, document]) t && t.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', code: 'Escape', keyCode: 27, bubbles: true }));
      await sleep(400);
    }
  };

  // deletes the article's post through its menu; true when the confirm sheet was clicked
  const remove = async (r) => {
    await closeMenu();   // a menu left open by the previous try would stack under this one
    r.el.scrollIntoView({ block: 'center', behavior: 'smooth' });
    await sleep(jitter(700, 1400));   // a glance at the post before the menu
    const caret = r.el.querySelector('button[data-testid="caret"]');
    if (!caret) throw new Error('메뉴 버튼 없음');
    caret.click();
    await waitFor('div[role="menu"] [role="menuitem"]', 8000);
    await sleep(jitter(400, 900));
    const menu = Array.from(document.querySelectorAll('div[role="menu"] [role="menuitem"]')).map((x) => (x.innerText || '').trim().split('\n')[0]);
    log('menu ' + r.id + ': ' + menu.slice(0, 6).join(' / '));
    // "삭제하기" / "삭제" / "Delete": \b does not work after Hangul, so match the start of the text only
    const item = Array.from(document.querySelectorAll('div[role="menu"] [role="menuitem"]')).find((x) => /^\s*(삭제|Delete)/.test(x.innerText || ''));
    if (!item) {
      await closeMenu();
      throw new Error('삭제 메뉴 없음 (메뉴: ' + menu.slice(0, 4).join(' / ') + ')');
    }
    item.click();
    const ok = await waitFor('[data-testid="confirmationSheetConfirm"]', 8000);
    await sleep(jitter(500, 1100));
    log('confirm ' + r.id + ': "' + (ok.innerText || '').trim().slice(0, 20) + '"');
    ok.click();
    // the article leaves the timeline; wait for it (or give it 4 s)
    for (let i = 0; i < 20 && document.contains(r.el); i++) await sleep(200);
    const gone = !document.contains(r.el);
    const toast = (document.querySelector('[data-testid="toast"]') || {}).innerText || '';
    log('result ' + r.id + ': ' + (gone ? 'gone' : 'still on screen') + (toast ? ' toast="' + toast.slice(0, 60) + '"' : ''));
    return gone;
  };

  // X's search shows "결과 없음" when the first page of results is made only of posts that are gone (the ones just
  // deleted): the same until: then looks empty although older posts exist (verified 2026-10-31: until:2026-03-01
  // empty, until:2026-03-01_00:00:00_KST 21 results). The walk therefore pages by until: itself: when the results
  // run out (or come back empty) it reloads the search with until: = the oldest time seen, in X's full time format.
  const fmtUntil = (ts) => {
    const d = new Date(ts * 1000), p = (n) => String(n).padStart(2, '0');
    return d.getUTCFullYear() + '-' + p(d.getUTCMonth() + 1) + '-' + p(d.getUTCDate()) + '_' + p(d.getUTCHours()) + ':' + p(d.getUTCMinutes()) + ':' + p(d.getUTCSeconds()) + '_UTC';
  };
  const hop = async (ts, why) => {
    const q = (job.query || '').replace(/\s*until:\S+/g, '') + ' until:' + fmtUntil(ts);
    const r = await chrome.runtime.sendMessage({ type: 'x-purge-hop', query: q, until: ts, why }).catch(() => null);
    if (!r || !r.ok) { log('hop refused: ' + ((r && r.error) || 'no answer')); return false; }
    log('hop -> ' + q + ' (' + why + ')');
    say('더 오래된 글 찾는 중 (' + fmtUntil(ts).replace('_UTC', ' UTC') + ' 이전)...');
    await sleep(jitter(1500, 3000));
    location.href = 'https://x.com/search?q=' + encodeURIComponent(q) + '&src=typed_query&f=live';
    await sleep(60000);   // the page is leaving; nothing below should run
    return true;
  };

  // X throttles a run of deletions (replies above all): RATE_N deletions, then a full RATE_REST break (user, 2026-10-30)
  const RATE_N = 50, RATE_REST = 15 * 60 * 1000;
  let batch = 0;   // deletions since the last break
  const rest = async (why) => {
    log('rest 15 min: ' + why);
    const end = Date.now() + RATE_REST;
    while (!stopped && Date.now() < end) {
      const wait = end - Date.now();
      const until = new Date(end);
      say(why + ': ' + Math.ceil(wait / 60000) + '분 쉬는 중 (' + until.getHours() + ':' + String(until.getMinutes()).padStart(2, '0') + '에 계속)');
      report({ found, deleted, failed, done: false, waiting: Math.ceil(wait / 1000) });
      await sleep(Math.min(wait, 30000));
    }
    batch = 0;
  };
  const throttle = async () => { if (batch >= RATE_N) await rest(RATE_N + '건 지웠으니 15분 휴식'); };
  // a post that was "deleted" but is on screen again, or one that does not leave the screen: X is refusing deletions
  // (the limit) -> 15 minutes off at once, then the post is tried again (twice at most) (user, 2026-10-31)
  const retried = new Map();
  const limited = async (r, it, why) => {
    log('limit? ' + r.id + ': ' + why);
    const n = (retried.get(r.id) || 0) + 1;
    retried.set(r.id, n);
    if (it.ok) deleted--;
    done.delete(r.id);
    const i = items.indexOf(it); if (i >= 0) items.splice(i, 1);
    found--;
    report({ found, deleted, failed, done: false, item: { id: r.id, time: r.time, kind: r.kind, text: r.text, ok: null, error: why + ' (재시도 ' + n + ')' } });
    if (n > 2) {
      const fail = { id: r.id, time: r.time, kind: r.kind, text: r.text, ok: false, error: why + ' - 3번 실패' };
      done.set(r.id, fail); items.push(fail); found++; failed++;
      log('give up ' + r.id);
      return;
    }
    await rest('삭제가 안 먹힘(제한 추정), 15분 휴식');
  };

  const kinds = new Set(job.kinds || ['post', 'reply']);
  const seen = new Set();    // every post id that has been on screen
  const done = new Map();    // id -> item reported
  const items = [];
  let found = 0, deleted = 0, failed = 0, oldest = Infinity, idle = 0, mine = 0;
  if (job.resume) {   // the tab was reloaded in the middle (or a hop): carry on, skipping what was handled already
    found = job.resume.found || 0; deleted = job.resume.deleted || 0; failed = job.resume.failed || 0;
    for (const it of job.resume.items || []) { done.set(it.id, it); items.push(it); }
    if (job.resume.oldest) oldest = job.resume.oldest;
  }
  const me = (job.handle || '').toLowerCase();
  log('start job ' + job.id + ' mode=' + job.mode + ' kinds=' + (job.kinds || []).join(',') + ' to=' + job.to + ' from=' + job.from
      + ' hop=' + (job.hop || 0) + (job.resume ? ' resume found=' + found + ' deleted=' + deleted + ' failed=' + failed + ' oldest=' + (isFinite(oldest) ? fmtUntil(oldest) : '-') : '')
      + ' url=' + location.pathname + location.search.slice(0, 160));

  try {
    // a search with nothing in it shows no article at all
    const first = await waitFor('article[data-testid="tweet"]', 30000).then(() => true, () => false);   // a timeout is "empty", not an error
    await sleep(1000);
    const v = viewer();
    if (!v) throw new Error('X에 로그인되어 있지 않습니다');
    if (v.toLowerCase() !== me) throw new Error('로그인 계정(@' + v + ')이 감시 계정(@' + job.handle + ')과 다릅니다');
    say((job.resume ? '이어서 ' : '') + (job.mode === 'scan' ? '대상 세는 중' : '삭제 중') + ' ...');
    const column = (document.querySelector('[data-testid="primaryColumn"]') || {}).innerText || '';
    const notice = (column.match(/결과가? 없[^\n]*|No results[^\n]*|다시 시도[^\n]*|Something went wrong[^\n]*|문제가 발생[^\n]*/) || [''])[0];
    log('page ready: articles=' + document.querySelectorAll('article[data-testid="tweet"]').length + ' viewer=@' + v + (notice ? ' notice="' + notice.slice(0, 40) + '"' : ''));
    if (!first) {
      // empty: when posts were handled already the page is the "deleted first page" case -> page by until:; a fresh run
      // steps the until: back a day up to 3 times before taking the empty result at its word
      const base = isFinite(oldest) ? oldest : (job.to || Math.floor(Date.now() / 1000));
      const step = isFinite(oldest) ? 1 : 86400;
      if ((job.hop || 0) < 40 && (isFinite(oldest) || (job.hop || 0) < 3) && (!job.from || base - step > job.from)) {
        if (await hop(base - step, 'empty result')) return;
      }
      idle = 99;   // nothing there: the loop below ends at once
      log('empty result accepted (hop=' + (job.hop || 0) + ')');
    }

    while (!stopped && idle < 10) {
      const arts = Array.from(document.querySelectorAll('article[data-testid="tweet"]'));
      let acted = false, fresh = 0, prev = null;
      for (const a of arts) {
        const r = read(a);
        const before = prev;
        prev = r;
        if (r && !seen.has(r.id)) { seen.add(r.id); fresh++; }   // anything new on screen, mine or not: the page is still loading more
        if (!r || r.handle.toLowerCase() !== me || r.repost) continue;
        if (!done.has(r.id) && !seen.has(r.id + ':mine')) { seen.add(r.id + ':mine'); mine++; }
        // in a conversation the post right above (someone else's) is the parent: this one is a reply
        if (r.kind === 'post' && before && before.handle.toLowerCase() !== me) {
          const cell = a.closest('[data-testid="cellInnerDiv"]'), pcell = before.el.closest('[data-testid="cellInnerDiv"]');
          if (cell && pcell && cell.previousElementSibling === pcell) r.kind = 'reply';
        }
        if (!r.pinned && r.time < oldest) oldest = r.time;
        const earlier = done.get(r.id);
        if (earlier && earlier.ok === true && job.mode !== 'scan') {   // deleted a moment ago, back on screen: the deletion did not take
          await limited(r, earlier, '삭제한 글이 다시 보임');
          acted = true;
          break;
        }
        if (done.has(r.id) || r.pinned || (job.from && r.time < job.from) || r.time > job.to || !kinds.has(r.kind)) continue;
        const it = { id: r.id, time: r.time, kind: r.kind, text: r.text, ok: null };
        done.set(r.id, it);
        items.push(it);
        found++;
        log('target #' + found + ' ' + r.id + ' ' + fmtUntil(r.time) + ' ' + r.kind + ' "' + (r.text || '').slice(0, 40).replace(/\s+/g, ' ') + '"');
        if (job.mode === 'scan') {
          report({ found, deleted, failed, last: r.text, done: false, oldest: isFinite(oldest) ? oldest : 0 });
          continue;
        }
        await throttle();
        if (stopped) { done.delete(r.id); items.pop(); found--; break; }
        batch++;
        try {
          it.ok = await remove(r);
          if (it.ok) deleted++;
          else { await limited(r, it, '확인을 눌렀는데 화면에서 사라지지 않음'); acted = true; break; }
        } catch (e) {
          it.ok = false; it.error = e.message; failed++;
          log('error ' + r.id + ': ' + e.message);
        }
        say('삭제 ' + deleted + '건' + (failed ? ', 실패 ' + failed : '') + ' ...');
        report({ found, deleted, failed, last: r.text, done: false, item: it, oldest: isFinite(oldest) ? oldest : 0 });
        acted = true;
        await pause(1800, 4500);
        break;   // the timeline shifted: read it again
      }
      if (acted) continue;
      // nothing left on screen: older posts are further down, unless the range is already behind us
      if (job.from && oldest < job.from - 86400 * 3) { log('past the older bound: oldest=' + fmtUntil(oldest)); break; }
      idle = fresh ? 0 : idle + 1;
      if (idle % 3 === 0 || fresh) log('scroll: articles=' + arts.length + ' fresh=' + fresh + ' idle=' + idle + ' mine=' + mine + ' oldest=' + (isFinite(oldest) ? fmtUntil(oldest) : '-') + ' y=' + Math.round(window.scrollY));
      if (idle >= 10) {
        // the results ran out: X search pages only so far; anything older comes with a new search until: the oldest seen
        if (isFinite(oldest) && mine > 0 && (job.hop || 0) < 40 && (!job.from || oldest - 1 > job.from)) {
          report({ found, deleted, failed, done: false, oldest });
          if (await hop(oldest - 1, 'results ran out after ' + mine + ' of mine')) return;
        }
        log('end of results (hop=' + (job.hop || 0) + ', mine=' + mine + ')');
        break;
      }
      await scrollDown();
      await pause(900, 2400);
    }
    const status = stopped ? 'stopped' : 'done';
    log('finish ' + status + ' found=' + found + ' deleted=' + deleted + ' failed=' + failed);
    say(status === 'stopped' ? '중지됨' : (job.mode === 'scan' ? '대상 ' + found + '건' : '끝: 삭제 ' + deleted + '건, 실패 ' + failed + '건'));
    report({ found, deleted, failed, done: true, status, items });
  } catch (e) {
    log('fatal: ' + e.message);
    say('오류: ' + e.message);
    report({ found, deleted, failed, done: true, status: 'error', error: e.message, items });
  }
  setTimeout(() => banner.remove(), 15000);
})();
