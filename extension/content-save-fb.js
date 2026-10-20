// SAVE button next to Facebook's "공유하기" on every video (watch page, reels, feed). It sends the
// video's permalink to MV Cut, which downloads it into the library.
(() => {
  if (window.top !== window) return;

  const PERMALINK = /\/(watch\/?\?v=(\d+)|reel\/(\d+)|[^/?#]+\/videos\/[^?#]+|share\/[rv]\/[^/?#]+)/;
  const STALE = 'MV Cut 확장이 업데이트되었습니다. 이 탭을 새로고침하세요.';

  const css = document.createElement('style');
  css.id = 'mvcutFbSaveCss';
  css.textContent = `
    .mvcut-fbsave { display: inline-flex; align-items: center; justify-content: center; height: 32px; margin: 0 4px; padding: 0 14px; border: 0; border-radius: 16px;
      background: #0a84ff; color: #fff; font: 700 13px/32px system-ui, sans-serif; letter-spacing: .04em; cursor: pointer; flex: none; align-self: center; }
    .mvcut-fbsave:hover { filter: brightness(1.1); }
    .mvcut-fbsave:disabled { opacity: .7; cursor: default; }
    .mvcut-fbsave.ok { background: #30a14e; }
    .mvcut-fbsave.bad { background: #d93025; }
    .mvcut-fbsave.dup { background: #8e8e93; }
    #mvcutToast { position: fixed; right: 18px; bottom: 60px; z-index: 2147483646; max-width: 320px; padding: 10px 14px; border-radius: 12px;
      background: rgba(28,28,30,.96); color: #fff; font: 500 13px/1.45 system-ui, sans-serif; box-shadow: 0 6px 20px rgba(0,0,0,.35); white-space: pre-line; }`;
  document.getElementById('mvcutFbSaveCss')?.remove();
  document.documentElement.appendChild(css);
  document.querySelectorAll('.mvcut-fbsave').forEach((b) => b.remove());

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

  const canonical = (href) => {
    const m = PERMALINK.exec(href || '');
    if (!m) return null;
    if (m[2]) return 'https://www.facebook.com/watch/?v=' + m[2];
    if (m[3]) return 'https://www.facebook.com/reel/' + m[3];
    return 'https://www.facebook.com' + m[1].replace(/^\/?/, '/').split('?')[0];
  };

  /** The video this share button belongs to: the nearest ancestor that holds a video permalink. */
  const postOf = (shareBtn) => {
    let el = shareBtn;
    for (let i = 0; i < 25 && el; i++, el = el.parentElement) {
      for (const a of el.querySelectorAll('a[href]')) {
        const u = canonical(a.getAttribute('href'));
        if (u) return { url: u, video: !!el.querySelector('video') };
      }
    }
    // a watch / reel page whose main video has no self link
    const u = canonical(location.pathname + location.search);
    return u ? { url: u, video: true } : null;
  };

  const isShare = (b) => {
    const t = (b.innerText || '').trim();
    const al = b.getAttribute('aria-label') || '';
    return t === '공유하기' || t === '공유' || t === 'Share' || /^(공유|Share)\b/.test(al);
  };

  const state = (btn, cls, label, ms = 2500) => {
    btn.className = 'mvcut-fbsave ' + cls; btn.textContent = label;
    setTimeout(() => { btn.className = 'mvcut-fbsave'; btn.textContent = 'SAVE'; btn.disabled = false; }, ms);
  };

  const onClick = async (btn, shareBtn) => {
    const post = postOf(shareBtn);
    if (!post) { toast('이 게시물의 영상 링크를 찾지 못했습니다.'); return; }
    btn.disabled = true; btn.textContent = '...';
    const r = await send({ type: 'save-post', url: post.url, title: '' });
    if (!r.ok) { state(btn, 'bad', '실패', 4000); toast('MV Cut: ' + (r.error || '저장하지 못했습니다') + (/토큰|token/i.test(r.error || '') ? '\n확장 옵션에서 서버 주소와 토큰을 확인하세요.' : '')); return; }
    if (r.duplicate) { state(btn, 'dup', '있음'); toast('이미 라이브러리에 있습니다: ' + (r.title || '')); return; }
    state(btn, 'ok', '저장됨');
    toast('MV Cut에 저장을 요청했습니다. 내려받는 중...');
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
  };

  /** Puts a SAVE button right after every "공유하기" of a video post that lacks one. */
  const decorate = () => {
    for (const share of document.querySelectorAll('[role="button"]')) {
      if (!isShare(share) || share.dataset.mvcutSave) continue;
      const post = postOf(share);
      if (!post) continue;
      share.dataset.mvcutSave = '1';
      const btn = document.createElement('button');
      btn.type = 'button'; btn.className = 'mvcut-fbsave'; btn.textContent = 'SAVE'; btn.title = 'MV Cut 라이브러리에 저장';
      btn.addEventListener('click', (e) => { e.preventDefault(); e.stopPropagation(); onClick(btn, share); });
      share.insertAdjacentElement('afterend', btn);
    }
  };

  let timer = null;
  const obs = new MutationObserver(() => {
    if (timer) return;
    timer = setTimeout(() => { timer = null; try { decorate(); } catch (e) { /* ignore */ } }, 600);
  });
  const start = () => { obs.observe(document.body, { childList: true, subtree: true }); decorate(); };
  if (document.body) start(); else document.addEventListener('DOMContentLoaded', start);
})();
