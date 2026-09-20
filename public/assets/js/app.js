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
