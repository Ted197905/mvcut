// Opens X's compose modal (not /intent/post: that page rebuilds the composer from its URL
// whenever a sub-screen such as the content warning closes, dropping the edited text and the
// attachment), then hands the text and the file over to the content script.
const pending = new Map();

chrome.runtime.onMessage.addListener((msg, sender, reply) => {
  if (msg && msg.type === 'send-to-x') {
    chrome.tabs.create({ url: 'https://x.com/compose/post' }, (tab) => {
      pending.set(tab.id, { url: msg.url, filename: msg.filename, mime: msg.mime, text: String(msg.text || '') });
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

chrome.tabs.onRemoved.addListener((id) => pending.delete(id));
