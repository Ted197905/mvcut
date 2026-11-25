// Runs in the page (MAIN world) before X's own code, so it sees the responses X already
// fetches while you browse. The one request of its own is your scheduled-post list
// (FetchScheduledTweets, the same call X's "예약됨" screen makes), at most every 5 minutes.
(() => {
  const API = /\/i\/api\/|api\.x\.com|api\.twitter\.com/;
  const SCHED = /\/graphql\/([A-Za-z0-9_-]+)\/FetchScheduledTweets/;
  const SCHED_CHANGE = /\/graphql\/[A-Za-z0-9_-]+\/(Create|Delete|Edit)ScheduledTweet/;
  // X web app's public bearer; replaced by the one X itself sends once seen
  let auth = 'Bearer AAAAAAAAAAAAAAAAAAAAANRILgAAAAAAnNwIzUejRCOuH5E6I8xnZz4puTs%3D1Zv7ttfk8LF81IUq16cHjhLTvJu4FA33AGWWjCpTnA';
  const ls = (k, v) => { try { if (v === undefined) return localStorage.getItem(k); localStorage.setItem(k, v); } catch (e) { return null; } };

  function schedItems(j) {
    const list = j && j.data && j.data.viewer && j.data.viewer.scheduled_tweet_list;
    if (!Array.isArray(list)) return null;
    return list.map((x) => {
      const req = x.tweet_create_request || {};
      const info = x.scheduling_info || {};
      let at = Number(info.execute_at) || 0;
      if (at > 1e12) at = Math.round(at / 1000);
      const m = (x.media_entities || [])[0];
      const mi = m && m.media_info;
      return {
        id: String(x.rest_id || ''), at, state: info.state || '',
        kind: req.in_reply_to_status_id ? 'reply' : req.attachment_url ? 'quote' : 'post',
        text: String(req.status || '').slice(0, 300),
        media: (req.media_ids || []).length,
        thumb: (mi && ((mi.preview_image && mi.preview_image.original_img_url) || mi.original_img_url)) || ''
      };
    }).filter((x) => x.id && x.at);
  }

  function onSched(text) {
    let j;
    try { j = JSON.parse(text); } catch (e) { return; }
    const items = schedItems(j);
    if (items) window.postMessage({ __mvx: 'scheduled', items }, location.origin);
  }

  async function fetchSched(force) {
    const last = Number(ls('mvx_sched_at')) || 0;
    if (!force && Date.now() - last < 5 * 60000) return;
    if (document.hidden && !force) return;
    const ct0 = (document.cookie.match(/(?:^|; )ct0=([^;]+)/) || [])[1];
    if (!ct0) return; // signed out
    ls('mvx_sched_at', String(Date.now()));
    const qid = ls('mvx_sched_qid') || 'H2elmT2R9DLhWoo0DZFNkA';
    try {
      const res = await origFetch.call(window, '/i/api/graphql/' + qid + '/FetchScheduledTweets?variables=' + encodeURIComponent('{"ascending":true}'), {
        credentials: 'include',
        headers: { authorization: auth, 'x-csrf-token': ct0, 'x-twitter-active-user': 'yes', 'x-twitter-auth-type': 'OAuth2Session' }
      });
      if (res.ok) onSched(await res.text());
    } catch (e) { /* offline */ }
  }

  function watchUrl(url) {
    const m = SCHED.exec(url);
    if (m) ls('mvx_sched_qid', m[1]);
    if (SCHED_CHANGE.test(url)) setTimeout(() => fetchSched(true), 1500);
    return !!m;
  }

  const origFetch = window.__mvxOrigFetch || window.fetch;
  if (!window.__mvxSched) {
    window.__mvxSched = true;
    window.__mvxOrigFetch = origFetch;
    setTimeout(() => fetchSched(false), 4000);
    setInterval(() => fetchSched(false), 60000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) fetchSched(false); });
    // content.js saw a schedule being made, edited or deleted (X's own requests are not visible here)
    window.addEventListener('message', (e) => {
      if (e.source === window && e.data && e.data.__mvx === 'sched-refresh') fetchSched(true);
    });
  }

  if (window.__mvxHook) return;
  window.__mvxHook = true;

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

  window.fetch = async function (input, init) {
    const res = await origFetch.apply(this, arguments);
    try {
      const url = typeof input === 'string' ? input : (input && input.url) || '';
      const h = init && init.headers;
      const a = h && (typeof h.get === 'function' ? h.get('authorization') : h.authorization || h.Authorization);
      if (a && /^Bearer /.test(a)) auth = a;
      if (watchUrl(url)) res.clone().text().then(onSched, () => {});
      else if (API.test(url)) res.clone().text().then(inspect, () => {});
    } catch (e) { /* never break the page */ }
    return res;
  };

  const open = XMLHttpRequest.prototype.open;
  const send = XMLHttpRequest.prototype.send;
  const setHeader = XMLHttpRequest.prototype.setRequestHeader;
  XMLHttpRequest.prototype.open = function (method, url) {
    this.__mvxUrl = String(url || '');
    return open.apply(this, arguments);
  };
  XMLHttpRequest.prototype.setRequestHeader = function (k, v) {
    if (/^authorization$/i.test(k) && /^Bearer /.test(v)) auth = v;
    return setHeader.apply(this, arguments);
  };
  XMLHttpRequest.prototype.send = function () {
    const sched = watchUrl(this.__mvxUrl || '');
    if (sched || API.test(this.__mvxUrl || '')) {
      const fn = sched ? onSched : inspect;
      this.addEventListener('load', () => {
        try {
          if (this.responseType === '' || this.responseType === 'text') fn(this.responseText);
          else if (this.responseType === 'json' && this.response) fn(JSON.stringify(this.response));
        } catch (e) { /* ignore */ }
      });
    }
    return send.apply(this, arguments);
  };
})();
