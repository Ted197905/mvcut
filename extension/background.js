// Opens X's compose modal (not /intent/post: that page rebuilds the composer from its URL
// whenever a sub-screen such as the content warning closes, dropping the edited text and the
// attachment), then hands the text and the file over to the content script.
const pending = new Map();
// 게시물 정리 (content-purge.js): x tab id -> job; the MV Cut tab that asked gets the progress
const purges = new Map();

chrome.runtime.onMessage.addListener((msg, sender, reply) => {
  if (msg && msg.type === 'send-to-x') {
    chrome.tabs.create({ url: 'https://x.com/compose/post' }, (tab) => {
      const files = Array.isArray(msg.files) ? msg.files : (msg.url ? [{ url: msg.url, filename: msg.filename, mime: msg.mime }] : []);
      pending.set(tab.id, { files, text: String(msg.text || ''), note: String(msg.note || ''), at: Number(msg.at) || 0 });
      reply({ ok: true });
    });
    return true;
  }
  if (msg && msg.type === 'x-ready') {
    const id = sender.tab && sender.tab.id;
    const job = id != null ? pending.get(id) : null;
    if (job) pending.delete(id);
    reply(job || null);
    return true;
  }
  // 게시물 정리: MV Cut's X 관리 page asks; the profile timeline opens in a new tab and content-purge.js works there
  if (msg && msg.type === 'x-purge') {
    const from = sender.tab && sender.tab.id;
    if (msg.action === 'stop') {
      for (const [tabId, j] of purges) if (j.id === msg.job) chrome.tabs.sendMessage(tabId, { type: 'x-purge-stop' }).catch(() => {});
      reply({ ok: true });
      return true;
    }
    const handle = String(msg.handle || '').replace(/^@/, '');
    if (!/^[A-Za-z0-9_]{1,15}$/.test(handle)) { reply({ ok: false, error: '계정 아이디가 없습니다' }); return true; }
    const kinds = Array.isArray(msg.kinds) ? msg.kinds : ['post', 'reply'];
    const url = 'https://x.com/' + handle + (kinds.includes('reply') ? '/with_replies' : '');
    chrome.tabs.create({ url, active: true }, (tab) => {
      purges.set(tab.id, { id: String(msg.job), mode: msg.mode === 'scan' ? 'scan' : 'delete', handle, kinds,
                           from: Number(msg.from) || 0, to: Number(msg.to) || 0, mvcutTab: from, started: false });
      reply({ ok: true });
    });
    return true;
  }
  if (msg && msg.type === 'x-purge-ready') {
    const id = sender.tab && sender.tab.id;
    const j = id != null ? purges.get(id) : null;
    if (!j || j.started) { reply(null); return true; }   // a reload of that tab does not start it over
    j.started = true;
    reply({ id: j.id, mode: j.mode, handle: j.handle, kinds: j.kinds, from: j.from, to: j.to });
    return true;
  }
  if (msg && msg.type === 'x-purge-progress') {
    const id = sender.tab && sender.tab.id;
    const j = id != null ? purges.get(id) : null;
    if (j) {
      chrome.tabs.sendMessage(j.mvcutTab, { type: 'x-purge-progress', ...msg }).catch(() => {});
      if (msg.done) purges.delete(id);
    }
    reply({ ok: true });
    return true;
  }
  // fallback path: some pages block the content script's own fetch
  if (msg && msg.type === 'fetch-file') {
    fetch(msg.url)
      .then((r) => (r.ok ? r.arrayBuffer() : Promise.reject(new Error('HTTP ' + r.status))))
      .then((buf) => {
        let s = '';
        const b = new Uint8Array(buf);
        for (let i = 0; i < b.length; i += 0x8000) s += String.fromCharCode.apply(null, b.subarray(i, i + 0x8000));
        reply({ ok: true, b64: btoa(s) });
      })
      .catch((e) => reply({ ok: false, error: e.message }));
    return true;
  }
});

chrome.tabs.onRemoved.addListener((id) => {
  pending.delete(id);
  const j = purges.get(id);
  if (j) {   // the X tab was closed in the middle: the page learns it stopped
    chrome.tabs.sendMessage(j.mvcutTab, { type: 'x-purge-progress', job: j.id, done: true, status: 'stopped', error: 'X 탭이 닫혔습니다' }).catch(() => {});
    purges.delete(id);
  }
});
