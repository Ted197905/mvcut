// Tells the page the extension is here, and forwards its "send to X" requests.
document.documentElement.dataset.mvcutX = '1';

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
  });
});
