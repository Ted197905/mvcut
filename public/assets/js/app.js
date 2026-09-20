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
  // 403 here is almost always an expired CSRF token / session on a long-open page
  const httpError = (status, fallback) => status === 403
    ? '로그인 세션이 만료되었습니다. 새로고침(F5) 후 다시 시도하세요.'
    : (fallback || ('HTTP ' + status));

  return { csrf, fmtDur, fmtSize, httpError };
})();
