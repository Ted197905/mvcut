// MV Cut - shared client helpers
window.MV = (function () {
  const csrf = () => {
    const m = document.querySelector('meta[name="csrf"]');
    return m ? { name: m.dataset.name, hash: m.content } : null;
  };
  const fmtDur = (s) => {
    s = Math.round(+s || 0);
    const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60;
    return (h ? h + ':' : '') + String(m).padStart(h ? 2 : 1, '0') + ':' + String(sec).padStart(2, '0');
  };
  const fmtSize = (b) => {
    b = +b || 0;
    if (b < 1024) return b + ' B';
    if (b < 1048576) return (b / 1024).toFixed(0) + ' KB';
    if (b < 1073741824) return (b / 1048576).toFixed(1) + ' MB';
    return (b / 1073741824).toFixed(2) + ' GB';
  };
  /**
   * 401, or a 403 the server did not explain, means the session or its CSRF token is
   * gone: end the session and go to the login page. A 403 that carries an error message
   * is a real refusal (no permission), so it is left to the caller to show.
   */
  let leaving = false;
  const expired = (status, body) => {
    if (status !== 401 && status !== 403) return false;
    if (status === 403 && body && body.error) return false;
    if (leaving) return true;
    leaving = true;
    const m = document.querySelector('meta[name="expired-url"]');
    location.href = m ? m.content : '/session/expired';
    return true;
  };
  const httpError = (status, fallback) => status === 403 || status === 401
    ? '로그인 세션이 만료되었습니다. 다시 로그인해 주세요.'
    : (fallback || ('HTTP ' + status));

  return { csrf, fmtDur, fmtSize, httpError, expired };
})();

/**
 * Hands a "send to X" request to the MV Cut extension (content-mvcut.js) and waits for its ack.
 * No ack within 2.5 s means the extension is not there or its copy on this page died after an
 * extension reload: the user is told to reload the page instead of nothing happening.
 */
window.mvSendToX = (data) => new Promise((resolve, reject) => {
  if (document.documentElement.dataset.mvcutX !== '1') { reject(new Error('MV Cut 확장이 필요합니다.')); return; }
  const req = String(Date.now()) + Math.random().toString(36).slice(2, 8);
  const v = (document.documentElement.dataset.mvcutXVersion || '0').split('.').map(Number);
  if ((v[0] || 0) * 10000 + (v[1] || 0) * 100 + (v[2] || 0) < 11000) {   // before 1.10.0 the extension sent no ack
    window.postMessage({ source: 'mvcut', type: 'send-to-x', req, ...data }, location.origin);
    resolve(true);
    return;
  }
  const timer = setTimeout(() => { window.removeEventListener('message', onAck); reject(new Error('확장이 응답하지 않습니다. 확장을 새로고침했다면 이 페이지를 새로고침한 뒤 다시 누르세요.')); }, 2500);
  const onAck = (e) => {
    const d = e.data;
    if (e.source !== window || !d || d.source !== 'mvcut-ext' || d.type !== 'send-to-x-ack' || d.req !== req) return;
    clearTimeout(timer); window.removeEventListener('message', onAck);
    if (d.ok) resolve(true); else reject(new Error(d.error || '확장 오류'));
  };
  window.addEventListener('message', onAck);
  window.postMessage({ source: 'mvcut', type: 'send-to-x', req, ...data }, location.origin);
});

// top nav: hamburger on narrow screens
(function () {
  const btn = document.getElementById('navToggle'), links = document.getElementById('navLinks');
  if (!btn || !links) return;
  const set = (open) => { links.classList.toggle('open', open); btn.setAttribute('aria-expanded', open ? 'true' : 'false'); };
  btn.addEventListener('click', () => set(!links.classList.contains('open')));
  document.addEventListener('click', (e) => { if (!e.target.closest('.nav')) set(false); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') set(false); });
})();
