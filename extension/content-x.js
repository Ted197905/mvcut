// Fills the compose modal with the post text and attaches the files (one video or up to 4 photos). Posting stays manual.

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

  const load = async (f) => {
    try {
      const r = await fetch(f.url);
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return await r.blob();
    } catch (e) {
      const r = await chrome.runtime.sendMessage({ type: 'fetch-file', url: f.url });
      if (!r || !r.ok) throw new Error('파일을 불러오지 못했습니다: ' + ((r && r.error) || e.message));
      const bin = atob(r.b64);
      const buf = new Uint8Array(bin.length);
      for (let i = 0; i < bin.length; i++) buf[i] = bin.charCodeAt(i);
      return new Blob([buf], { type: f.mime });
    }
  };

  // The composer is a rich editor that only takes text through events it handles itself;
  // a paste event is one of them (and, unlike the /intent URL, the text then survives the
  // caption / content-warning sub-screens).
  const fillText = async (text) => {
    if (!text) return;
    const box = await waitFor('div[role="dialog"] div[data-testid="tweetTextarea_0"][contenteditable="true"]');
    box.focus();
    const dt = new DataTransfer();
    dt.setData('text/plain', text);
    box.dispatchEvent(new ClipboardEvent('paste', { bubbles: true, cancelable: true, clipboardData: dt }));
    await new Promise((r) => setTimeout(r, 400));
    if (!box.innerText.trim()) document.execCommand('insertText', false, text);
  };

  // the composer's schedule screen: native selects (SELECTOR_1..6 = month, day, year, hour, minute, am/pm) driven by React
  const setSchedule = async (at) => {
    const d = new Date(at * 1000);   // shown in this browser's time zone, as X's own screen does
    (await waitFor('div[role="dialog"] [data-testid="scheduleOption"]')).click();
    await waitFor('[data-testid="scheduledConfirmationPrimaryAction"]');
    const setter = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value').set;
    const pick = async (labelText, v) => {
      const sel = Array.from(document.querySelectorAll('select')).find((s) => {
        const l = s.getAttribute('aria-labelledby');
        return l && (document.getElementById(l) || {}).textContent === labelText;
      });
      if (!sel) throw new Error(labelText + ' 선택 칸이 없습니다');
      setter.call(sel, String(v));
      sel.dispatchEvent(new Event('change', { bubbles: true }));
      await new Promise((r) => setTimeout(r, 150));
      if (sel.value !== String(v)) throw new Error(labelText + ' 값을 넣지 못했습니다');
    };
    const h = d.getHours();
    await pick('년', d.getFullYear());
    await pick('월', d.getMonth() + 1);
    await pick('일', d.getDate());
    await pick('시', h % 12 || 12);
    await pick('분', d.getMinutes());
    await pick('AM/PM', h < 12 ? 'am' : 'pm');
    document.querySelector('[data-testid="scheduledConfirmationPrimaryAction"]').click();
    await new Promise((r) => setTimeout(r, 800));
    const btn = document.querySelector('div[role="dialog"] [data-testid="tweetButton"]');
    if (!btn || !/예약|Schedule/i.test(btn.innerText)) throw new Error('예약 화면을 닫지 못했습니다');
  };

  try {
    await fillText(job.text);
    const files = job.files || [{ url: job.url, filename: job.filename, mime: job.mime }];
    if (files.length) {
      // the compose modal, not the inline composer behind it
      const input = await waitFor('div[role="dialog"] input[data-testid="fileInput"]');
      const blobs = await Promise.all(files.map(load));
      const dt = new DataTransfer();
      files.forEach((f, i) => dt.items.add(new File([blobs[i]], f.filename, { type: f.mime })));
      input.files = dt.files;
      input.dispatchEvent(new Event('change', { bubbles: true }));
    }
    if (job.at) {
      // X's own schedule screen: the date/time selects, then "확인"; the post itself stays for the user ("예약하기")
      await new Promise((r) => setTimeout(r, 1500));
      try {
        await setSchedule(job.at);
        job.note = '예약 시각을 ' + job.note.replace(/^예약 시각 /, '').replace(/로 지정하고 예약하세요.*$/, '') + '로 맞춰 두었습니다. 확인 후 "예약하기"를 누르세요.';
      } catch (e) {
        toast('예약 시각을 자동으로 넣지 못했습니다: ' + e.message, true);
      }
    }
    if (job.note) {
      const el = document.createElement('div');   // stays until closed: the scheduled time to set
      el.textContent = job.note + '  (클릭하면 닫힘)';
      el.style.cssText = 'position:fixed;left:50%;top:16px;transform:translateX(-50%);z-index:2147483647;padding:10px 16px;border-radius:10px;'
        + 'font:600 15px system-ui;color:#fff;background:#c77700;box-shadow:0 6px 20px rgba(0,0,0,.3);cursor:pointer';
      el.onclick = () => el.remove();
      document.body.appendChild(el);
    }
    toast('본문' + (files.length ? '과 파일 ' + files.length + '개를' : '을') + ' 올렸습니다. ' + (job.note ? '예약 시각을 지정해 예약하세요.' : '확인 후 직접 게시하세요.'));
  } catch (e) {
    toast('MV Cut: ' + e.message, true);
  }
})();
