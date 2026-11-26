// Fills the compose modal with the post text and attaches the files (up to 4 with a video, up to 20 photos); a thread's other
// posts go into post boxes added under the first (job.parts, 1.12.0). The schedule time is set; "예약하기" stays for the user
// unless the job says submit.

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
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
  const fillText = async (text, n = 0) => {
    if (!text) return;
    const box = await waitFor('div[role="dialog"] div[data-testid="tweetTextarea_' + n + '"][contenteditable="true"]');
    box.focus();
    const dt = new DataTransfer();
    dt.setData('text/plain', text);
    box.dispatchEvent(new ClipboardEvent('paste', { bubbles: true, cancelable: true, clipboardData: dt }));
    await new Promise((r) => setTimeout(r, 400));
    if (!box.innerText.trim()) document.execCommand('insertText', false, text);
    if (!box.innerText.trim()) throw new Error((n + 1) + '번째 글을 넣지 못했습니다');
  };

  // "게시물 추가" needs the whole pointer sequence: a bare click() empties the composer instead of adding a post box (seen 2026-11-26)
  const press = (el) => {
    const r = el.getBoundingClientRect();
    const o = { bubbles: true, cancelable: true, composed: true, view: window, clientX: r.x + r.width / 2, clientY: r.y + r.height / 2,
                button: 0, buttons: 1, pointerId: 1, pointerType: 'mouse', isPrimary: true };
    el.dispatchEvent(new PointerEvent('pointerdown', o));
    el.dispatchEvent(new MouseEvent('mousedown', o));
    el.focus();
    el.dispatchEvent(new PointerEvent('pointerup', { ...o, buttons: 0 }));
    el.dispatchEvent(new MouseEvent('mouseup', { ...o, buttons: 0 }));
    el.dispatchEvent(new MouseEvent('click', { ...o, buttons: 0 }));
  };
  // waits while X uploads the files (the button stays disabled meanwhile)
  const enabled = async (sel, ms) => {
    const until = Date.now() + ms;
    for (;;) {
      const el = document.querySelector(sel);
      if (el && el.getAttribute('aria-disabled') !== 'true' && !el.disabled) return el;
      if (Date.now() > until) throw new Error('버튼이 눌리지 않습니다 (파일 올리는 중일 수 있음)');
      await sleep(400);
    }
  };
  const addPost = async (n, text) => {
    press(await enabled('div[role="dialog"] [data-testid="addButton"]', 180000));
    await waitFor('div[role="dialog"] div[data-testid="tweetTextarea_' + n + '"][contenteditable="true"]', 8000);
    await sleep(350);
    await fillText(text, n);
    await sleep(350);
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
    const parts = Array.isArray(job.parts) ? job.parts : [];
    for (let i = 0; i < parts.length; i++) {
      if (i === 0) await sleep(700);
      await addPost(i + 1, parts[i]);
    }
    let scheduled = false;
    if (job.at) {
      // X's own schedule screen: the date/time selects, then "확인"; the post itself stays for the user ("예약하기")
      await new Promise((r) => setTimeout(r, 1500));
      try {
        await setSchedule(job.at);
        scheduled = true;
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
      // gone by itself once the composer closes (posted, scheduled or dismissed), and after 5 minutes at the latest
      const until = Date.now() + 5 * 60 * 1000;
      const watch = setInterval(() => {
        if (!el.isConnected) return clearInterval(watch);
        if (Date.now() > until || !document.querySelector('div[role="dialog"] [data-testid="tweetTextarea_0"]')) { el.remove(); clearInterval(watch); }
      }, 1000);
      document.body.appendChild(el);
    }
    const what = '본문' + (parts.length ? ', 답글 ' + parts.length + '개' : '') + (files.length ? ', 파일 ' + files.length + '개' : '');
    if (job.submit && scheduled) {
      // the last step too: only with the schedule time set (the button then reads "예약하기", never "게시하기")
      const sel = 'div[role="dialog"] [data-testid="tweetButton"]';
      const btn = await enabled(sel, 300000);
      if (!/예약|Schedule/i.test(btn.innerText)) throw new Error('예약 버튼이 아닙니다: 누르지 않았습니다');
      await sleep(900 + Math.random() * 900);
      btn.click();
      const until = Date.now() + 30000;
      while (document.querySelector('div[role="dialog"] [data-testid="tweetTextarea_0"]')) {
        if (Date.now() > until) throw new Error('"예약하기"를 눌렀지만 작성창이 닫히지 않았습니다. 화면을 확인하세요.');
        await sleep(400);
      }
      toast(what + '를 예약했습니다. 이 탭은 곧 닫힙니다.');
      chrome.runtime.sendMessage({ type: 'x-done' }).catch(() => {});
      return;
    }
    toast(what + '를 올렸습니다. ' + (job.note ? '예약 시각을 확인하고 예약하세요.' : '확인 후 직접 게시하세요.'));
  } catch (e) {
    toast('MV Cut: ' + e.message, true);
  }
})();
