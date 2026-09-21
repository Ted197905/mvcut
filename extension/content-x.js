// Fills the X composer with the text and attaches the file, then stops. Posting stays manual.
(async () => {
  const job = await chrome.runtime.sendMessage({ type: 'x-ready' });
  if (!job) return;

  const waitFor = (sel, ms = 20000) => new Promise((resolve, reject) => {
    const found = document.querySelector(sel);
    if (found) return resolve(found);
    const t = setTimeout(() => { ob.disconnect(); reject(new Error('시간이 초과되었습니다: ' + sel)); }, ms);
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

  try {
    // our own server allows this origin on the signed link, so the file can be read here
    const res = await fetch(job.url);
    if (!res.ok) throw new Error('파일을 불러오지 못했습니다 (HTTP ' + res.status + ')');
    const file = new File([await res.blob()], job.filename, { type: job.mime });

    const box = await waitFor('div[data-testid^="tweetTextarea_"]');
    box.focus();
    // A restored draft would otherwise stay and our text would land inside it.
    document.execCommand('selectAll');
    document.execCommand('delete');

    if (job.text) {
      // Paste, not insertText: the composer is a rich editor that only updates its own
      // state (and hides the placeholder) for events it knows, and paste is one of them.
      const dt = new DataTransfer();
      dt.setData('text/plain', job.text);
      box.dispatchEvent(new ClipboardEvent('paste', { bubbles: true, cancelable: true, clipboardData: dt }));
      await new Promise((r) => setTimeout(r, 250));
      if (!box.textContent.trim()) {
        box.focus();
        document.execCommand('insertText', false, job.text);
      }
    }

    const input = await waitFor('input[data-testid="fileInput"]');
    const dt = new DataTransfer();
    dt.items.add(file);
    input.files = dt.files;
    input.dispatchEvent(new Event('change', { bubbles: true }));

    toast('본문과 파일을 올렸습니다. 확인 후 직접 게시하세요.');
  } catch (e) {
    toast('MV Cut: ' + e.message, true);
  }
})();
