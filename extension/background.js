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
    // X search, latest first (from:handle until: since: filter:... as composed on the page); the profile timeline before 1.8.8
    const query = String(msg.query || '').trim() || ('from:' + handle);
    const url = 'https://x.com/search?q=' + encodeURIComponent(query) + '&src=typed_query&f=live';
    chrome.tabs.create({ url, active: true }, (tab) => {
      purges.set(tab.id, { id: String(msg.job), mode: msg.mode === 'scan' ? 'scan' : 'delete', handle, kinds, query,
                           from: Number(msg.from) || 0, to: Number(msg.to) || 0, mvcutTab: from, started: false,
                           found: 0, deleted: 0, failed: 0, items: [], posted: 0, postedAt: 0, hop: 0, oldest: 0, until: 0, log: [], logSent: 0 });
      reply({ ok: true });
    });
    return true;
  }
  if (msg && msg.type === 'x-purge-ready') {
    const id = sender.tab && sender.tab.id;
    const j = id != null ? purges.get(id) : null;
    if (!j) { reply(null); return true; }
    // the tab was reloaded in the middle (X does that on its own now and then): the new script carries on with the counts so far
    const resume = j.started ? { found: j.found, deleted: j.deleted, failed: j.failed, items: j.items, oldest: j.oldest } : null;
    j.started = true;
    reply({ id: j.id, mode: j.mode, handle: j.handle, kinds: j.kinds, from: j.from, to: j.to, query: j.query, hop: j.hop, resume });
    return true;
  }
  // the walk pages by until: (X search shows nothing when the first page is all deleted posts): the script asks before
  // reloading the tab with a new query; each hop must go further back, 40 hops at most
  if (msg && msg.type === 'x-purge-hop') {
    const id = sender.tab && sender.tab.id;
    const j = id != null ? purges.get(id) : null;
    if (!j) { reply({ ok: false, error: 'no job' }); return true; }
    const ts = Number(msg.until) || 0;
    if (j.hop >= 40) { reply({ ok: false, error: '40 hops' }); return true; }
    if (j.until && ts >= j.until) { reply({ ok: false, error: 'until did not move back (' + ts + ' >= ' + j.until + ')' }); return true; }
    j.hop++; j.until = ts; j.oldest = ts; j.query = String(msg.query || j.query);
    j.log.push(new Date().toTimeString().slice(0, 8) + ' [bg] hop ' + j.hop + ': ' + j.query + ' (' + (msg.why || '') + ')');
    purgeReport(j, { force: true });
    reply({ ok: true, hop: j.hop });
    return true;
  }
  if (msg && msg.type === 'x-purge-progress') {
    const id = sender.tab && sender.tab.id;
    const j = id != null ? purges.get(id) : null;
    if (j) {
      j.found = msg.found || 0; j.deleted = msg.deleted || 0; j.failed = msg.failed || 0;
      if (msg.oldest && (!j.oldest || msg.oldest < j.oldest)) j.oldest = msg.oldest;
      if (Array.isArray(msg.log) && msg.log.length) { j.log.push(...msg.log); if (j.log.length > 2000) { const cut = j.log.length - 2000; j.log.splice(0, cut); j.logSent = Math.max(0, j.logSent - cut); } }
      if (msg.item) j.items.push(msg.item);
      if (msg.done && Array.isArray(msg.items)) j.items = msg.items;
      chrome.tabs.sendMessage(j.mvcutTab, { type: 'x-purge-progress', ...msg }).catch(() => {});
      purgeReport(j, msg);
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

// the run's state goes to the server from here (POST /xapi/purge/{id}, the extension's token), so the page may be
// closed or reloaded: at the end, while waiting, and every 5 posts handled
function purgeReport(j, msg) {
  const n = (j.deleted || 0) + (j.failed || 0);
  const due = msg.done || msg.force || (msg.waiting && Date.now() - j.postedAt > 60000) || (n > 0 && n % 5 === 0 && n !== j.posted)
    || (j.log.length - j.logSent >= 25 && Date.now() - j.postedAt > 20000);
  if (!due) return;
  j.posted = n; j.postedAt = Date.now();
  const log = j.log.slice(j.logSent); j.logSent = j.log.length;
  api('/xapi/purge/' + encodeURIComponent(j.id), { method: 'POST', body: JSON.stringify({
    status: msg.done ? (msg.status || 'done') : 'running', found: j.found, deleted: j.deleted, failed: j.failed, error: msg.error || null, items: j.items, log,
  }) }).catch((e) => { j.logSent -= log.length; console.warn('purge report failed', e); });
}

chrome.tabs.onRemoved.addListener((id) => {
  pending.delete(id);
  const j = purges.get(id);
  if (j) {   // the X tab was closed in the middle: the page and the server learn it stopped
    const msg = { job: j.id, done: true, status: 'stopped', error: 'X 탭이 닫혔습니다' };
    chrome.tabs.sendMessage(j.mvcutTab, { type: 'x-purge-progress', ...msg }).catch(() => {});
    purgeReport(j, msg);
    purges.delete(id);
  }
});
