// Runs in the page (MAIN world) before X's own code, so it sees the responses X already
// fetches while you browse. It never makes requests of its own.
(() => {
  if (window.__mvxHook) return;
  window.__mvxHook = true;
  const API = /\/i\/api\/|api\.x\.com|api\.twitter\.com/;

  // Finds tweet objects anywhere in a JSON response (GraphQL or REST shapes).
  function walk(node, out, depth) {
    if (!node || typeof node !== 'object' || depth > 40) return;
    if (Array.isArray(node)) { for (const v of node) walk(v, out, depth + 1); return; }
    const leg = node.legacy;
    if (leg && typeof leg === 'object' && leg.created_at && (leg.full_text !== undefined || leg.text !== undefined)) {
      const u = node.core && node.core.user_results && node.core.user_results.result;
      const handle = u && ((u.core && u.core.screen_name) || (u.legacy && u.legacy.screen_name));
      const id = node.rest_id || leg.id_str;
      if (handle && id) {
        out.push({
          id: String(id), handle,
          time: new Date(leg.created_at).toISOString(),
          kind: leg.retweeted_status_result ? 'repost' : leg.in_reply_to_status_id_str ? 'reply' : leg.is_quote_status ? 'quote' : 'post',
          text: String(leg.full_text || leg.text || '').slice(0, 300),
          reply_to: leg.in_reply_to_screen_name || ''
        });
      }
    } else if (node.created_at && node.id_str && node.user && node.user.screen_name && (node.full_text !== undefined || node.text !== undefined)) {
      out.push({
        id: node.id_str, handle: node.user.screen_name, time: new Date(node.created_at).toISOString(),
        kind: node.retweeted_status ? 'repost' : node.in_reply_to_status_id_str ? 'reply' : node.is_quote_status ? 'quote' : 'post',
        text: String(node.full_text || node.text || '').slice(0, 300), reply_to: node.in_reply_to_screen_name || ''
      });
    }
    for (const k in node) {
      const v = node[k];
      if (v && typeof v === 'object') walk(v, out, depth + 1);
    }
  }

  function inspect(text) {
    if (!text || text.length < 20 || (text[0] !== '{' && text[0] !== '[')) return;
    let j;
    try { j = JSON.parse(text); } catch (e) { return; }
    const out = [];
    walk(j, out, 0);
    if (out.length) window.postMessage({ __mvx: 'posts', posts: out, src: 'api' }, location.origin);
  }

  const origFetch = window.fetch;
  window.fetch = async function (input, init) {
    const res = await origFetch.apply(this, arguments);
    try {
      const url = typeof input === 'string' ? input : (input && input.url) || '';
      if (API.test(url)) res.clone().text().then(inspect, () => {});
    } catch (e) { /* never break the page */ }
    return res;
  };

  const open = XMLHttpRequest.prototype.open;
  const send = XMLHttpRequest.prototype.send;
  XMLHttpRequest.prototype.open = function (method, url) {
    this.__mvxUrl = String(url || '');
    return open.apply(this, arguments);
  };
  XMLHttpRequest.prototype.send = function () {
    if (API.test(this.__mvxUrl || '')) {
      this.addEventListener('load', () => {
        try {
          if (this.responseType === '' || this.responseType === 'text') inspect(this.responseText);
          else if (this.responseType === 'json' && this.response) inspect(JSON.stringify(this.response));
        } catch (e) { /* ignore */ }
      });
    }
    return send.apply(this, arguments);
  };
})();
