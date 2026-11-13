// Tells the page the extension is here, and forwards its "send to X" requests.
// Injected by the manifest at document_start and again by bg.js when a MV Cut tab finishes loading
// (a belt-and-braces for tabs where the manifest injection did not happen); the second run stops here.
if (window.__mvcutLive) throw new Error('content-mvcut.js already running');
window.__mvcutLive = true;
document.documentElement.dataset.mvcutX = '1';
document.documentElement.dataset.mvcutXMulti = '1';   // send-to-x takes files[] (up to 4 photos)
document.documentElement.dataset.mvcutXVersion = chrome.runtime.getManifest().version;

// After an extension reload this copy of the script is orphaned (no chrome.runtime.id, chrome.* throws
// "Extension context invalidated"): it stays quiet, the live copy bg.js re-injects answers instead, and a page
// that gets no ack at all tells the user to reload it.
const orphan = () => !(chrome.runtime && chrome.runtime.id);
const ack = (d, ok, error) => window.postMessage({ source: 'mvcut-ext', type: 'send-to-x-ack', ok, error: error || '', req: d.req || '' }, location.origin);

// 게시물 정리: the page asks (start / stop), progress comes back from the X tab through the service worker
chrome.runtime.onMessage.addListener((m) => {
  if (m && m.type === 'x-purge-progress') window.postMessage({ source: 'mvcut-ext', ...m }, location.origin);
});

window.addEventListener('message', (e) => {
  if (e.source !== window || e.origin !== location.origin || orphan()) return;
  const d = e.data;
  if (d && d.source === 'mvcut' && d.type === 'x-purge') {
    try {
      chrome.runtime.sendMessage({ type: 'x-purge', action: d.action === 'stop' ? 'stop' : 'start', job: String(d.job || ''), mode: d.mode,
                                   handle: String(d.handle || ''), kinds: Array.isArray(d.kinds) ? d.kinds.map(String) : ['post', 'reply'],
                                   from: Number(d.from) || 0, to: Number(d.to) || 0, query: String(d.query || '').slice(0, 500) })
        .then((r) => { if (r && !r.ok) window.postMessage({ source: 'mvcut-ext', type: 'x-purge-progress', job: String(d.job || ''), done: true, status: 'error', error: r.error }, location.origin); })
        .catch(() => {});
    } catch (err) {
      window.postMessage({ source: 'mvcut-ext', type: 'x-purge-progress', job: String(d.job || ''), done: true, status: 'error',
                           error: '확장이 새로고침되어 이 페이지와 연결이 끊겼습니다. 페이지를 새로고침하세요.' }, location.origin);
    }
    return;
  }
  if (!d || d.source !== 'mvcut' || d.type !== 'send-to-x' || (typeof d.url !== 'string' && !Array.isArray(d.files))) return;
  try {
    chrome.runtime.sendMessage({
      type: 'send-to-x',
      url: String(d.url || ''),
      note: String(d.note || '').slice(0, 300),
      at: Number(d.at) || 0,   // schedule time (unix): content-x.js sets it in the composer's schedule screen
      filename: String(d.filename || 'video.mp4'),
      mime: String(d.mime || 'application/octet-stream'),
      text: String(d.text || ''),
      files: Array.isArray(d.files) ? d.files.slice(0, 20).filter((f) => f && typeof f.url === 'string')
        .map((f) => ({ url: f.url, filename: String(f.filename || 'file'), mime: String(f.mime || 'application/octet-stream') })) : null,
    }).catch(() => {});
    ack(d, true);
  } catch (err) {
    ack(d, false, '확장이 새로고침되어 이 페이지와 연결이 끊겼습니다. 페이지를 새로고침한 뒤 다시 누르세요.');
  }
});
