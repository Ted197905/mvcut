// SAVE button on every X post that carries media (timeline, profile, thread). It sends the
// post link, and the image URLs read off the page, to MV Cut, which downloads them into the
// library. Quoted posts inside a post are left alone.
(() => {
  if (window.top !== window) return;

  const STATUS = /^\/([A-Za-z0-9_]{1,15})\/status\/(\d+)/;
  const STALE = 'MV Cut 확장이 업데이트되었습니다. 이 탭을 새로고침하세요.';

  const css = document.createElement('style');
  css.id = 'mvcutXSaveCss';
  css.textContent = `
    .mvcut-xsave { display: inline-flex; align-items: center; height: 26px; margin-left: 8px; padding: 0 11px; border: 0; border-radius: 13px;
      background: #0a84ff; color: #fff; font: 700 12px/26px system-ui, sans-serif; letter-spacing: .04em; cursor: pointer; flex: none; align-self: center; }
    .mvcut-xsave:hover { filter: brightness(1.1); }
    .mvcut-xsave:disabled { opacity: .7; cursor: default; }
    .mvcut-xsave.ok { background: #30a14e; }
    .mvcut-xsave.bad { background: #d93025; }
    .mvcut-xsave.dup { background: #8e8e93; }
    #mvcutToast { position: fixed; right: 18px; bottom: 60px; z-index: 2147483646; max-width: 320px; padding: 10px 14px; border-radius: 12px;
      background: rgba(28,28,30,.96); color: #fff; font: 500 13px/1.45 system-ui, sans-serif; box-shadow: 0 6px 20px rgba(0,0,0,.35); white-space: pre-line; }`;
  document.getElementById('mvcutXSaveCss')?.remove();
  document.documentElement.appendChild(css);
  document.querySelectorAll('.mvcut-xsave').forEach((b) => b.remove());

  let toastTimer = 0;
  const toast = (text, ms = 4000) => {
    let el = document.getElementById('mvcutToast');
    if (!el) { el = document.createElement('div'); el.id = 'mvcutToast'; document.documentElement.appendChild(el); }
    el.textContent = text;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => el.remove(), ms);
  };

  const send = (msg) => new Promise((resolve) => {
    const t = setTimeout(() => resolve({ ok: false, error: '확장 응답이 없습니다. 이 탭을 새로고침하세요.' }), 30000);
    try {
      if (!chrome.runtime || !chrome.runtime.id) throw new Error(STALE);
      chrome.runtime.sendMessage(msg, (r) => {
        clearTimeout(t);
        resolve(r || { ok: false, error: chrome.runtime.lastError ? chrome.runtime.lastError.message : 'no reply' });
      });
    } catch (e) {
      clearTimeout(t);
      resolve({ ok: false, error: /context invalidated/i.test(e.message) ? STALE : e.message });
    }
  });

  /** Media of the post itself: a quoted post sits inside a div[role=link], so that is skipped. */
  const inQuote = (el, art) => { const q = el.closest('div[role="link"]'); return q && art.contains(q); };
  const readPost = (art) => {
    const t = art.querySelector('time');
    const link = t && t.closest('a');
    const m = link && STATUS.exec(link.getAttribute('href') || '');
    if (!m) return null;
    const images = [];
    for (const img of art.querySelectorAll('[data-testid="tweetPhoto"] img')) {
      if (inQuote(img, art)) continue;
      try {
        const u = new URL(img.src);
        if (!/(^|\.)twimg\.com$/.test(u.hostname) || !u.pathname.startsWith('/media/')) continue;
        u.searchParams.set('name', 'orig');            // full size instead of the timeline's "small"
        if (!images.includes(u.href)) images.push(u.href);
      } catch (e) { /* ignore */ }
    }
    const video = [...art.querySelectorAll('[data-testid="videoPlayer"], video')].some((v) => !inQuote(v, art));
    if (!images.length && !video) return null;
    const textEl = art.querySelector('[data-testid="tweetText"]');
    return { url: 'https://x.com/' + m[1] + '/status/' + m[2], handle: m[1], images, video,
             text: textEl && !inQuote(textEl, art) ? textEl.innerText.trim().slice(0, 2000) : '' };
  };

  const state = (btn, cls, label, ms = 2500) => {
    btn.className = 'mvcut-xsave ' + cls; btn.textContent = label;
    setTimeout(() => { btn.className = 'mvcut-xsave'; btn.textContent = 'SAVE'; btn.disabled = false; }, ms);
  };

  const onClick = async (btn, art) => {
    const post = readPost(art);
    if (!post) { toast('이 게시물에서 저장할 미디어를 찾지 못했습니다.'); return; }
    btn.disabled = true; btn.textContent = '...';
    const r = await send({ type: 'save-post', url: post.url, title: '', images: post.images, video: post.video, text: post.text });
    if (!r.ok) { state(btn, 'bad', '실패', 4000); toast('MV Cut: ' + (r.error || '저장하지 못했습니다') + (/토큰|token/i.test(r.error || '') ? '\n확장 옵션에서 서버 주소와 토큰을 확인하세요.' : '')); return; }
    if (r.duplicate) { state(btn, 'dup', '있음'); toast('이미 라이브러리에 있습니다: ' + (r.title || '')); return; }
    state(btn, 'ok', '저장됨');
    const n = (r.jobs || [r.job]).length;
    toast('MV Cut에 저장을 요청했습니다 (' + n + '개). 내려받는 중...');
    const started = Date.now();
    const tick = async () => {
      if (Date.now() - started > 180000) { toast('시간이 오래 걸립니다. 라이브러리에서 확인하세요.'); return; }
      const j = await send({ type: 'save-status', job: r.job });
      if (!j.ok) { toast('MV Cut: 상태를 확인하지 못했습니다 (' + (j.error || '') + ')'); return; }
      if (j.status === 'done') { toast('라이브러리에 저장됨: ' + (j.title || '') + (n > 1 ? ' 외 ' + (n - 1) + '개' : ''), 5000); return; }
      if (j.status === 'failed') { toast('가져오기 실패: ' + (j.error || ''), 8000); return; }
      setTimeout(tick, 2000);
    };
    setTimeout(tick, 2500);
  };

  /** Puts a SAVE button at the end of the action bar of every media post that lacks one. */
  const decorate = () => {
    for (const art of document.querySelectorAll('article[data-testid="tweet"]')) {
      if (art.querySelector('.mvcut-xsave')) continue;
      const bar = [...art.querySelectorAll('[role="group"]')].find((g) => !inQuote(g, art) && g.querySelector('[data-testid="reply"], [data-testid="like"], [data-testid="unlike"]'));
      if (!bar || !readPost(art)) continue;
      const btn = document.createElement('button');
      btn.type = 'button'; btn.className = 'mvcut-xsave'; btn.textContent = 'SAVE'; btn.title = 'MV Cut 라이브러리에 저장';
      btn.addEventListener('click', (e) => { e.preventDefault(); e.stopPropagation(); onClick(btn, art); });
      bar.appendChild(btn);
    }
  };

  let timer = null;
  const obs = new MutationObserver(() => {
    if (timer) return;
    timer = setTimeout(() => { timer = null; try { decorate(); } catch (e) { /* ignore */ } }, 500);
  });
  const start = () => { obs.observe(document.body, { childList: true, subtree: true }); decorate(); };
  if (document.body) start(); else document.addEventListener('DOMContentLoaded', start);
})();
