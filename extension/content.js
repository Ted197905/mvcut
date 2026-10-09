// Isolated-world script on x.com: collects your own posts from what is rendered (and from
// hook.js), passes them to the service worker, and shows a small cadence pill on the page.
(() => {
  const STATUS = /^\/([A-Za-z0-9_]{1,15})\/status\/(\d+)/;
  const sent = new Set();
  let handle = '';
  let pending = [];
  let flushTimer = null;

  chrome.storage.local.get(['status', 'viewer'], (s) => {
    handle = (s.status && s.status.handle) || s.viewer || '';
  });
  chrome.storage.onChanged.addListener((c) => {
    if (c.status && c.status.newValue) { handle = c.status.newValue.handle || handle; renderPill(c.status.newValue); }
    if (c.local && c.local.newValue) renderPill();
  });

  function queue(posts, src) {
    for (const p of posts) {
      if (!p || !p.id || !p.handle || !p.time) continue;
      if (handle && p.handle.toLowerCase() !== handle.toLowerCase()) continue;
      const key = p.id + ':' + p.kind + ':' + (p.reply_to || '');
      if (sent.has(key)) continue;
      sent.add(key);
      pending.push(Object.assign({ src }, p));
    }
    if (pending.length && !flushTimer) {
      flushTimer = setTimeout(() => {
        flushTimer = null;
        const batch = pending; pending = [];
        try { chrome.runtime.sendMessage({ type: 'posts', posts: batch }); } catch (e) { /* extension reloaded */ }
      }, 1200);
    }
  }

  // from hook.js (page world)
  window.addEventListener('message', (e) => {
    if (e.source !== window || !e.data || e.data.__mvx !== 'posts') return;
    queue(e.data.posts || [], 'api');
  });

  function viewerHandle() {
    const a = document.querySelector('[data-testid="AppTabBar_Profile_Link"]');
    const h = a && (a.getAttribute('href') || '').replace(/^\//, '');
    return /^[A-Za-z0-9_]{1,15}$/.test(h || '') ? h : '';
  }

  function readArticle(a) {
    const t = a.querySelector('time');
    const link = t && t.closest('a');
    const m = link && STATUS.exec(link.getAttribute('href') || '');
    if (!m) return null;
    const social = (a.querySelector('[data-testid="socialContext"]') || {}).innerText || '';
    const textEl = a.querySelector('[data-testid="tweetText"]');
    const head = (a.innerText || '').slice(0, 400);
    let replyTo = '';
    const rep = /(?:Replying to|님에게 보내는 답글)/.test(head);
    if (rep) {
      const r = /Replying to\s+@([A-Za-z0-9_]{1,15})/.exec(head) || /@([A-Za-z0-9_]{1,15})\s*님에게 보내는 답글/.exec(head);
      if (r) replyTo = r[1];
    }
    return {
      el: a, id: m[2], handle: m[1], time: t.getAttribute('datetime'),
      repostedBy: /repost|재게시/i.test(social),
      quote: a.querySelectorAll('[data-testid="User-Name"]').length > 1,
      reply: rep, reply_to: replyTo,
      text: textEl ? textEl.innerText.slice(0, 300) : ''
    };
  }

  function scan() {
    if (!handle) handle = viewerHandle();
    const v = viewerHandle();
    if (v) chrome.storage.local.set({ viewer: v });
    if (!handle) return;
    const me = handle.toLowerCase();
    const threadPage = /\/with_replies|\/status\//.test(location.pathname);
    const arts = [...document.querySelectorAll('article[data-testid="tweet"]')];
    const out = [];
    let prev = null;
    for (const a of arts) {
      const r = readArticle(a);
      if (!r) { prev = null; continue; }
      // a repost row shows the original author's post and time, not when you reposted
      if (r.handle.toLowerCase() === me && !r.repostedBy) {
        let kind = r.quote ? 'quote' : 'post';
        let replyTo = r.reply_to;
        if (r.reply) kind = 'reply';
        else if (threadPage && prev && prev.handle.toLowerCase() !== me) {
          // in a conversation the post right above is the parent
          const cell = a.closest('[data-testid="cellInnerDiv"]');
          const pcell = prev.el.closest('[data-testid="cellInnerDiv"]');
          if (cell && pcell && cell.previousElementSibling === pcell) { kind = 'reply'; replyTo = prev.handle; }
        }
        out.push({ id: r.id, handle: r.handle, time: r.time, kind, text: r.text, reply_to: replyTo });
      }
      prev = r;
    }
    if (out.length) queue(out, 'dom');

    // "Your post was sent  View" toast right after posting
    const toast = document.querySelector('[data-testid="toast"] a[href*="/status/"]');
    if (toast) {
      const m = STATUS.exec(new URL(toast.href).pathname);
      if (m && m[1].toLowerCase() === me) {
        queue([{ id: m[2], handle: m[1], time: new Date().toISOString(), kind: 'post', text: '' }], 'toast');
      }
    }
  }

  let scanTimer = null;
  const obs = new MutationObserver(() => {
    if (scanTimer) return;
    scanTimer = setTimeout(() => { scanTimer = null; scan(); }, 800);
  });

  // ---- on-page pill: can I post now? ----
  let pill = null;
  function fmtHM(ts) {
    const d = new Date(ts * 1000);
    return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
  }
  function renderPill(st) {
    chrome.storage.local.get(['status', 'local'], (s) => {
      st = st || s.status;
      const loc = s.local;
      if (!document.body) return;
      if (!pill) {
        pill = document.createElement('div');
        pill.id = 'mvx-pill';
        pill.style.cssText = 'position:fixed;left:16px;bottom:16px;z-index:2147483646;font:600 12px/1.3 -apple-system,BlinkMacSystemFont,"Apple SD Gothic Neo",sans-serif;' +
          'padding:8px 12px;border-radius:999px;box-shadow:0 4px 16px rgba(0,0,0,.18);cursor:pointer;color:#fff;max-width:320px';
        pill.title = 'MV Cut X 관리 - 클릭하면 숨김';
        pill.addEventListener('click', () => { pill.style.display = 'none'; });
        document.body.appendChild(pill);
      }
      const now = Date.now() / 1000;
      const banned = st && st.check && st.check.status === 'banned';
      const nextOk = Math.max((loc && loc.next_ok) || 0, (st && st.timeline && st.timeline.next_ok) || 0);
      let text, bg;
      if (banned) { text = '제한 감지: ' + st.check.summary; bg = '#d70015'; }
      else if (nextOk > now + 30) { text = fmtHM(nextOk) + ' 이후 게시 권장 (' + Math.ceil((nextOk - now) / 60) + '분 남음)'; bg = '#c77700'; }
      else { text = '지금 게시 가능'; bg = '#1d8a3a'; }
      pill.textContent = 'MV Cut · ' + text;
      pill.style.background = bg;
    });
  }

  function start() {
    obs.observe(document.body, { childList: true, subtree: true });
    scan();
    renderPill();
    setInterval(() => renderPill(), 30000);
  }
  if (document.body) start(); else document.addEventListener('DOMContentLoaded', start);
})();
