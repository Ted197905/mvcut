// Tells the page the extension is here, and forwards its "send to X" requests.
document.documentElement.dataset.mvcutX = '1';
document.documentElement.dataset.mvcutXMulti = '1';   // send-to-x takes files[] (up to 4 photos)

window.addEventListener('message', (e) => {
  if (e.source !== window || e.origin !== location.origin) return;
  const d = e.data;
  if (!d || d.source !== 'mvcut' || d.type !== 'send-to-x' || typeof d.url !== 'string') return;
  chrome.runtime.sendMessage({
    type: 'send-to-x',
    url: d.url,
    filename: String(d.filename || 'video.mp4'),
    mime: String(d.mime || 'application/octet-stream'),
    text: String(d.text || ''),
    files: Array.isArray(d.files) ? d.files.slice(0, 4).filter((f) => f && typeof f.url === 'string')
      .map((f) => ({ url: f.url, filename: String(f.filename || 'file'), mime: String(f.mime || 'application/octet-stream') })) : null,
  });
});
