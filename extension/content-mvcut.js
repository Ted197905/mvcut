// Tells the page the extension is here, and forwards its "send to X" requests.
document.documentElement.dataset.mvcutX = '1';
document.documentElement.dataset.mvcutXMulti = '1';   // send-to-x takes files[] (up to 4 photos)
document.documentElement.dataset.mvcutXVersion = chrome.runtime.getManifest().version;

// 게시물 정리: the page asks (start / stop), progress comes back from the X tab through the service worker
chrome.runtime.onMessage.addListener((m) => {
  if (m && m.type === 'x-purge-progress') window.postMessage({ source: 'mvcut-ext', ...m }, location.origin);
});

window.addEventListener('message', (e) => {
  if (e.source !== window || e.origin !== location.origin) return;
  const d = e.data;
  if (d && d.source === 'mvcut' && d.type === 'x-purge') {
    chrome.runtime.sendMessage({ type: 'x-purge', action: d.action === 'stop' ? 'stop' : 'start', job: String(d.job || ''), mode: d.mode,
                                 handle: String(d.handle || ''), kinds: Array.isArray(d.kinds) ? d.kinds.map(String) : ['post', 'reply'],
                                 from: Number(d.from) || 0, to: Number(d.to) || 0, query: String(d.query || '').slice(0, 500) })
      .then((r) => { if (r && !r.ok) window.postMessage({ source: 'mvcut-ext', type: 'x-purge-progress', job: String(d.job || ''), done: true, status: 'error', error: r.error }, location.origin); })
      .catch(() => {});
    return;
  }
  if (!d || d.source !== 'mvcut' || d.type !== 'send-to-x' || (typeof d.url !== 'string' && !Array.isArray(d.files))) return;
  chrome.runtime.sendMessage({
    type: 'send-to-x',
    url: String(d.url || ''),
    note: String(d.note || '').slice(0, 300),
    at: Number(d.at) || 0,   // schedule time (unix): content-x.js sets it in the composer's schedule screen
    filename: String(d.filename || 'video.mp4'),
    mime: String(d.mime || 'application/octet-stream'),
    text: String(d.text || ''),
    files: Array.isArray(d.files) ? d.files.slice(0, 4).filter((f) => f && typeof f.url === 'string')
      .map((f) => ({ url: f.url, filename: String(f.filename || 'file'), mime: String(f.mime || 'application/octet-stream') })) : null,
  });
});
