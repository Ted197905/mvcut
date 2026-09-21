// Attaches the file to the compose window X opened with our text. Posting stays manual.
(async () => {
  const job = await chrome.runtime.sendMessage({ type: 'x-ready' });
  if (!job) return;

  const waitFor = (sel, ms = 25000) => new Promise((resolve, reject) => {
    const hit = document.querySelector(sel);
    if (hit) return resolve(hit);
    const t = setTimeout(() => { ob.disconnect(); reject(new Error('작성 창을 찾지 못했습니다')); }, ms);
    const ob = new MutationObserver(() => {
      const el = document.querySelector(sel);
      if (el) { clearTimeout(t); ob.disconnect(); resolve(el); }
    });
    ob.observe(document.documentElement, { childList: true, subtree: true });
  });

  const toast = (text, bad) => {
    const el = document.createElement('div');
    el.textContent = text;
    el.style.cssText = 'position:fixed;left:50%;bottom:28px;transform:translateX(-50%);z-index:2147483647;'
      + 'padding:10px 16px;border-radius:10px;font:600 14px system-ui;color:#fff;'
      + 'background:' + (bad ? '#d93025' : '#1d9bf0') + ';box-shadow:0 6px 20px rgba(0,0,0,.3)';
    document.body.appendChild(el);
    setTimeout(() => el.remove(), bad ? 8000 : 3500);
  };

  const load = async () => {
    try {
      const r = await fetch(job.url);
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return await r.blob();
    } catch (e) {
      const r = await chrome.runtime.sendMessage({ type: 'fetch-file', url: job.url });
      if (!r || !r.ok) throw new Error('파일을 불러오지 못했습니다: ' + ((r && r.error) || e.message));
      const bin = atob(r.b64);
      const buf = new Uint8Array(bin.length);
      for (let i = 0; i < bin.length; i++) buf[i] = bin.charCodeAt(i);
      return new Blob([buf], { type: job.mime });
    }
  };

  try {
    // the compose modal, not the inline composer behind it
    const input = await waitFor('div[role="dialog"] input[data-testid="fileInput"]');
    const file = new File([await load()], job.filename, { type: job.mime });
    const dt = new DataTransfer();
    dt.items.add(file);
    input.files = dt.files;
    input.dispatchEvent(new Event('change', { bubbles: true }));
    toast('본문과 파일을 올렸습니다. 확인 후 직접 게시하세요.');
  } catch (e) {
    toast('MV Cut: ' + e.message, true);
  }
})();
