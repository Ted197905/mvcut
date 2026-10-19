// SAVE button on TikTok and YouTube: sends the post you are looking at to MV Cut, which downloads
// it into the library (same as "영상 바로 다운"). TikTok: video page and For You feed.
// YouTube: watch page and Shorts.
(() => {
  if (window.top !== window) return;

  const VIDEO_RE = /^https?:\/\/(?:www\.|m\.)?tiktok\.com\/@[^/]+\/(?:video|photo)\/\d+/;

  const YT = /(^|\.)youtube\.com$/.test(location.hostname);
  /** YouTube: the video of a watch page or the Short on screen (the URL follows the Short). */
  const youtubePost = () => {
    const u = new URL(location.href);
    if (u.pathname === '/watch' && u.searchParams.get('v')) return { url: 'https://www.youtube.com/watch?v=' + u.searchParams.get('v'), title: '' };
    const s = u.pathname.match(/^\/shorts\/([\w-]{6,})/);
    if (s) return { url: 'https://www.youtube.com/shorts/' + s[1], title: '' };
    return null;
  };

  /** The post in view: the page URL on a video page, else the most visible video's own link. */
  const currentPost = () => {
    if (YT) return youtubePost();
    const m = location.href.match(VIDEO_RE);
    if (m) return { url: m[0], title: document.title.replace(/\s*\|\s*TikTok\s*$/, '').trim() };
    let best = null, bestArea = 0;
    for (const v of document.querySelectorAll('video')) {
      const r = v.getBoundingClientRect();
      const w = Math.max(0, Math.min(r.right, innerWidth) - Math.max(r.left, 0));
      const h = Math.max(0, Math.min(r.bottom, innerHeight) - Math.max(r.top, 0));
      if (w * h > bestArea) { bestArea = w * h; best = v; }
    }
    if (!best || bestArea < 40000) return null;
    // For You feed: no /video/ links; the player wrapper id carries the video id (xgwrapper-N-<id>)
    // and the item's profile link gives the author
    const wrap = best.closest('[id^="xgwrapper-"]');
    const vid = wrap && (wrap.id.match(/-(\d{15,25})$/) || [])[1];
    const item = best.closest('article, [data-e2e="recommend-list-item-container"], section');
    const user = item && item.querySelector('a[href^="/@"]');
    if (vid && user) {
      const handle = user.getAttribute('href').split(/[/?]/)[1];
      const desc = item.querySelector('[data-e2e="video-desc"], [data-e2e="browse-video-desc"]');
      return { url: 'https://www.tiktok.com/' + handle + '/video/' + vid, title: desc ? desc.innerText.trim().slice(0, 200) : '' };
    }
    let el = best;
    for (let i = 0; i < 12 && el; i++, el = el.parentElement) {
      const a = [...el.querySelectorAll('a[href]')].find((x) => VIDEO_RE.test(x.href));
      if (a) {
        const desc = el.querySelector('[data-e2e="video-desc"], [data-e2e="browse-video-desc"]');
        return { url: a.href.match(VIDEO_RE)[0], title: desc ? desc.innerText.trim().slice(0, 200) : '' };
      }
    }
    return null;
  };

  document.getElementById('mvcutSave')?.remove();
  document.getElementById('mvcutSaveCss')?.remove();
  const css = document.createElement('style');
  css.id = 'mvcutSaveCss';
  css.textContent = `
    #mvcutSave { position: fixed; right: 18px; bottom: 90px; z-index: 2147483646; min-width: 76px; height: 40px; padding: 0 16px;
      border: 0; border-radius: 20px; background: #0a84ff; color: #fff; font: 700 14px/40px system-ui, sans-serif; letter-spacing: .04em;
      cursor: pointer; box-shadow: 0 6px 20px rgba(0,0,0,.35); transition: transform .15s, background .2s; }
    #mvcutSave:hover { transform: translateY(-1px); }
    #mvcutSave:active { transform: scale(.96); }
    #mvcutSave:disabled { opacity: .7; cursor: default; }
    #mvcutSave.ok { background: #30a14e; }
    #mvcutSave.bad { background: #d93025; }
    #mvcutSave.dup { background: #8e8e93; }
    #mvcutToast { position: fixed; right: 18px; bottom: 140px; z-index: 2147483646; max-width: 320px; padding: 10px 14px; border-radius: 12px;
      background: rgba(28,28,30,.96); color: #fff; font: 500 13px/1.45 system-ui, sans-serif; box-shadow: 0 6px 20px rgba(0,0,0,.35);
      white-space: pre-line; }`;
  document.documentElement.appendChild(css);

  const btn = document.createElement('button');
  btn.id = 'mvcutSave'; btn.type = 'button'; btn.textContent = 'SAVE'; btn.title = 'MV Cut 라이브러리에 저장';
  document.documentElement.appendChild(btn);
  // YouTube is a single-page app: show the button only on a watch page or a Short
  if (YT) {
    const sync = () => { btn.style.display = youtubePost() ? '' : 'none'; };
    sync();
    setInterval(sync, 800);
  }

  let toastTimer = 0;
  const toast = (text, ms = 4000) => {
    let el = document.getElementById('mvcutToast');
    if (!el) { el = document.createElement('div'); el.id = 'mvcutToast'; document.documentElement.appendChild(el); }
    el.textContent = text;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => el.remove(), ms);
  };
  const state = (cls, label, ms = 2500) => {
    btn.className = cls; btn.textContent = label;
    setTimeout(() => { btn.className = ''; btn.textContent = 'SAVE'; btn.disabled = false; }, ms);
  };
  // after the extension is reloaded this copy is cut off: sendMessage throws instead of answering
  const STALE = 'MV Cut 확장이 업데이트되었습니다. 이 탭을 새로고침하세요.';
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

  btn.addEventListener('click', async () => {
    const post = currentPost();
    if (!post) { toast('저장할 영상을 찾지 못했습니다. 영상을 화면 가운데 두거나 영상 페이지를 열어 주세요.'); return; }
    btn.disabled = true; btn.textContent = '...';
    const r = await send({ type: 'save-post', url: post.url, title: post.title });
    if (!r.ok) { state('bad', '실패', 4000); toast('MV Cut: ' + (r.error || '저장하지 못했습니다') + (/토큰|token/i.test(r.error || '') ? '\n확장 옵션에서 서버 주소와 토큰을 확인하세요.' : '')); return; }
    if (r.duplicate) { state('dup', '있음'); toast('이미 라이브러리에 있습니다: ' + (r.title || '')); return; }
    state('ok', '저장됨');
    toast('MV Cut에 저장을 요청했습니다. 내려받는 중...');
    // follow the import so the result shows up here
    const started = Date.now();
    const tick = async () => {
      if (Date.now() - started > 180000) { toast('시간이 오래 걸립니다. 라이브러리에서 확인하세요.'); return; }
      const j = await send({ type: 'save-status', job: r.job });
      if (!j.ok) { toast('MV Cut: 상태를 확인하지 못했습니다 (' + (j.error || '') + ')'); return; }
      if (j.status === 'done') { toast('라이브러리에 저장됨: ' + (j.title || ''), 5000); return; }
      if (j.status === 'failed') { toast('가져오기 실패: ' + (j.error || ''), 8000); return; }
      setTimeout(tick, 2000);
    };
    setTimeout(tick, 2500);
  });
})();
