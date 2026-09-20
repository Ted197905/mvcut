// Holds one pending payload per tab: the X tab asks for it once it has loaded.
const pending = new Map();

chrome.runtime.onMessage.addListener((msg, sender, reply) => {
  if (msg && msg.type === 'send-to-x') {
    chrome.tabs.create({ url: 'https://x.com/compose/post' }, (tab) => {
      pending.set(tab.id, { url: msg.url, filename: msg.filename, mime: msg.mime, text: msg.text });
      reply({ ok: true });
    });
    return true;   // the reply is async
  }
  if (msg && msg.type === 'x-ready') {
    const id = sender.tab && sender.tab.id;
    const job = id != null ? pending.get(id) : null;
    if (job) pending.delete(id);
    reply(job || null);
    return true;
  }
});

chrome.tabs.onRemoved.addListener((id) => pending.delete(id));
