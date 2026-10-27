// Service worker: keeps your recent posts, syncs them to MV Cut, polls the restriction check,
// and raises desktop notifications / the toolbar badge.
const DEFAULT_SERVER = 'https://mvcut.blackout.kr';
const DEFAULT_LIMITS = { gap_min: 15, hour_max: 4, day_max: 30, count_replies: false };
const counted = (kind, limits) => kind === 'post' || kind === 'quote' || (kind === 'reply' && limits.count_replies);
const KEEP_DAYS = 7;

const get = (keys) => new Promise((r) => chrome.storage.local.get(keys, r));
const set = (obj) => new Promise((r) => chrome.storage.local.set(obj, r));

async function settings() {
  const s = await get(['server', 'token']);
  return { server: (s.server || DEFAULT_SERVER).replace(/\/+$/, ''), token: (s.token || '') };
}

async function api(path, opts = {}) {
  const { server, token } = await settings();
  if (!token) throw new Error('no token');
  const res = await fetch(server + path, Object.assign({}, opts, {
    headers: Object.assign({ Authorization: 'Bearer ' + token, 'Content-Type': 'application/json' }, opts.headers || {})
  }));
  if (res.status === 401) throw new Error('토큰이 올바르지 않습니다');
  if (!res.ok) throw new Error('HTTP ' + res.status);
  return res.json();
}

/** Same rules as the server (XWatch::timeline), on the posts this browser has seen. */
function computeLocal(posts, limits) {
  const now = Date.now() / 1000;
  const times = Object.values(posts).filter((p) => counted(p.kind, limits))
    .map((p) => Date.parse(p.time) / 1000).filter((t) => t > now - 86400 * 2).sort((a, b) => a - b);
  const last = times.length ? times[times.length - 1] : 0;
  const hourList = times.filter((t) => t > now - 3600);
  const dayStart = Math.floor(now / 86400) * 86400; // daily limit resets at 00:00 UTC (09:00 KST)
  const dayList = times.filter((t) => t >= dayStart);
  let next = now;
  if (last) next = Math.max(next, last + limits.gap_min * 60);
  if (hourList.length >= limits.hour_max) next = Math.max(next, hourList[hourList.length - limits.hour_max] + 3600);
  if (dayList.length >= limits.day_max) next = Math.max(next, dayStart + 86400);
  return { last, hour: hourList.length, day: dayList.length, next_ok: next, at: now };
}

async function refreshBadge() {
  const { status, local } = await get(['status', 'local']);
  const now = Date.now() / 1000;
  if (status && status.check && status.check.status === 'banned') {
    chrome.action.setBadgeText({ text: 'BAN' });
    chrome.action.setBadgeBackgroundColor({ color: '#d70015' });
    return;
  }
  const nextOk = Math.max((local && local.next_ok) || 0, (status && status.timeline && status.timeline.next_ok) || 0);
  if (nextOk > now + 30) {
    chrome.action.setBadgeText({ text: Math.ceil((nextOk - now) / 60) + 'm' });
    chrome.action.setBadgeBackgroundColor({ color: '#c77700' });
  } else {
    chrome.action.setBadgeText({ text: '' });
  }
}

function notify(id, title, message) {
  chrome.notifications.create(id + ':' + Date.now(), {
    type: 'basic', iconUrl: 'icon128.png', title, message, priority: 2
  });
}

async function handleStatus(st) {
  const { notified = {}, posts = {} } = await get(['notified', 'posts']);
  // posts the server does not have (e.g. sent before its handle was set) go up again
  const have = new Set(((st.timeline && st.timeline.posts) || []).map((p) => p.id));
  const since = Date.now() - 47 * 3600000;
  let resend = false;
  for (const p of Object.values(posts)) {
    if (p.synced && !have.has(p.id) && Date.parse(p.time) > since) { p.synced = false; resend = true; }
  }
  if (resend) await set({ posts });
  const c = st.check;
  if (c && c.id !== notified.checkId) {
    if (c.status === 'banned') {
      notify('ban', 'X 제한 감지 (@' + st.handle + ')', c.summary);
    } else if (c.status === 'ok' && notified.status === 'banned') {
      notify('ok', 'X 제한 해제 (@' + st.handle + ')', c.summary);
    } else if (c.status === 'error' && notified.status !== 'error') {
      notify('err', 'X 검사 실패', c.summary);
    }
    notified.checkId = c.id;
    notified.status = c.status;
  }
  await set({ status: st, notified, lastSync: Date.now(), lastError: '' });
  if (resend && st.handle && !uploading) upload();
}

async function poll() {
  try {
    const st = await api('/xapi/status');
    await handleStatus(st);
  } catch (e) {
    await set({ lastError: String(e.message || e) });
  }
  await refreshBadge();
}

let uploading = false;
async function upload() {
  const { posts = {}, viewer = '' } = await get(['posts', 'viewer']);
  const unsent = Object.values(posts).filter((p) => !p.synced);
  if (!unsent.length || uploading) return;
  uploading = true;
  try {
    const st = await api('/xapi/posts', { method: 'POST', body: JSON.stringify({ posts: unsent, viewer }) });
    const cur = (await get(['posts'])).posts || {};
    for (const p of unsent) if (cur[p.id]) cur[p.id].synced = true;
    await set({ posts: cur });
    uploading = false;
    await handleStatus(st);
  } catch (e) {
    await set({ lastError: String(e.message || e) });
  } finally {
    uploading = false;
  }
}

// ---- scheduled posts, read from X every 10 minutes even with no x.com tab open ----
// (the same request X's "예약됨" screen makes; hook.js does it too while an x.com tab is visible)
const SCHED_QID = 'H2elmT2R9DLhWoo0DZFNkA';
const X_WEB_AUTH = 'Bearer AAAAAAAAAAAAAAAAAAAAANRILgAAAAAAnNwIzUejRCOuH5E6I8xnZz4puTs%3D1Zv7ttfk8LF81IUq16cHjhLTvJu4FA33AGWWjCpTnA';

function schedItems(j) {
  const list = j && j.data && j.data.viewer && j.data.viewer.scheduled_tweet_list;
  if (!Array.isArray(list)) return null;
  return list.map((x) => {
    const req = x.tweet_create_request || {};
    const info = x.scheduling_info || {};
    let at = Number(info.execute_at) || 0;
    if (at > 1e12) at = Math.round(at / 1000);
    const mi = ((x.media_entities || [])[0] || {}).media_info;
    return {
      id: String(x.rest_id || ''), at, state: info.state || '',
      kind: req.in_reply_to_status_id ? 'reply' : req.attachment_url ? 'quote' : 'post',
      text: String(req.status || '').slice(0, 300),
      media: (req.media_ids || []).length,
      thumb: (mi && ((mi.preview_image && mi.preview_image.original_img_url) || mi.original_img_url)) || ''
    };
  }).filter((x) => x.id && x.at).slice(0, 100);
}

async function pollScheduled() {
  const s = await get(['status', 'viewer', 'schedQid']);
  const handle = (s.status && s.status.handle) || '';
  // the list belongs to whoever is signed in to x.com in this browser
  if (!handle || !s.viewer || s.viewer.toLowerCase() !== handle.toLowerCase()) return;
  const ct0 = await new Promise((r) => chrome.cookies.get({ url: 'https://x.com', name: 'ct0' }, (c) => r(c && c.value)));
  if (!ct0) return; // signed out
  try {
    const res = await fetch('https://x.com/i/api/graphql/' + (s.schedQid || SCHED_QID) + '/FetchScheduledTweets?variables=' + encodeURIComponent('{"ascending":true}'), {
      credentials: 'include',
      headers: { authorization: X_WEB_AUTH, 'x-csrf-token': ct0, 'x-twitter-active-user': 'yes', 'x-twitter-auth-type': 'OAuth2Session' }
    });
    if (!res.ok) throw new Error('X 예약 목록 HTTP ' + res.status);
    const items = schedItems(await res.json());
    if (!items) throw new Error('X 예약 목록 응답 형식이 바뀌었습니다');
    await api('/xapi/scheduled', { method: 'POST', body: JSON.stringify({ items, viewer: s.viewer }) });
    await set({ schedAt: Date.now() });
  } catch (e) {
    await set({ lastError: String(e.message || e) });
  }
}

// ---- approved drafts (MV Cut /drafts) -> X scheduled posts, every 2 minutes ----
// The same requests X's composer makes: media to upload.x.com (INIT/APPEND/FINALIZE), then the
// CreateScheduledTweet GraphQL mutation. They have to come from an x.com page: X answers 403 to the
// same POSTs from this worker (chrome-extension:// origin), so they run inside an x.com tab (one is
// opened in the background and closed again when none is open). Only for the watched account.
const CREATE_SCHED_QID = 'LCVzRQGxOaGnOnYH01NQXg';
const ct0Cookie = () => new Promise((r) => chrome.cookies.get({ url: 'https://x.com', name: 'ct0' }, (c) => r(c && c.value)));

// runs in the x.com page (MAIN world); everything it needs comes in as arguments
async function scheduleInPage(item, qid, auth) {
  const f = window.__mvxOrigFetch || window.fetch;
  const ct0 = (document.cookie.match(/(?:^|; )ct0=([^;]+)/) || [])[1];
  if (!ct0) return { error: 'x.com 로그아웃 상태' };
  const h = { authorization: auth, 'x-csrf-token': ct0, 'x-twitter-active-user': 'yes', 'x-twitter-auth-type': 'OAuth2Session' };
  const base = 'https://upload.x.com/i/media/upload.json';
  try {
    const media_ids = [];
    for (const file of item.files || []) {
      // bytes come from the worker: x.com's CSP does not let the page fetch from MV Cut
      const bin = atob(file.b64);
      const buf = new Uint8Array(bin.length);
      for (let i = 0; i < bin.length; i++) buf[i] = bin.charCodeAt(i);
      const type = file.mime || 'application/octet-stream';
      const blob = new Blob([buf], { type });
      const video = file.kind === 'video' || /^video\//.test(type);
      let r = await f(base + '?command=INIT&total_bytes=' + blob.size + '&media_type=' + encodeURIComponent(type)
        + '&media_category=' + (video ? 'tweet_video' : 'tweet_image'), { method: 'POST', credentials: 'include', headers: h });
      if (!r.ok) throw new Error('미디어 INIT HTTP ' + r.status);
      const id = String((await r.json()).media_id_string || '');
      const CHUNK = 4 * 1024 * 1024;
      for (let i = 0, seg = 0; i < blob.size; i += CHUNK, seg++) {
        const fd = new FormData();
        fd.append('media', blob.slice(i, i + CHUNK, type), file.filename || 'file');
        r = await f(base + '?command=APPEND&media_id=' + id + '&segment_index=' + seg, { method: 'POST', credentials: 'include', headers: h, body: fd });
        if (!r.ok) throw new Error('미디어 APPEND HTTP ' + r.status);
      }
      r = await f(base + '?command=FINALIZE&media_id=' + id, { method: 'POST', credentials: 'include', headers: h });
      if (!r.ok) throw new Error('미디어 FINALIZE HTTP ' + r.status);
      let j = await r.json();
      for (let n = 0; j.processing_info && j.processing_info.state !== 'succeeded'; n++) {
        if (j.processing_info.state === 'failed' || n > 60) throw new Error('미디어 처리 실패');
        await new Promise((res) => setTimeout(res, 1000 * Math.min(10, j.processing_info.check_after_secs || 2)));
        j = await (await f(base + '?command=STATUS&media_id=' + id, { credentials: 'include', headers: h })).json();
      }
      media_ids.push(id);
    }
    const variables = { post_tweet_request: { auto_populate_reply_metadata: false, status: item.text, exclude_reply_user_ids: [], media_ids }, execute_at: item.at };
    const r = await f('https://x.com/i/api/graphql/' + qid + '/CreateScheduledTweet', {
      method: 'POST', credentials: 'include', headers: Object.assign({ 'content-type': 'application/json' }, h),
      body: JSON.stringify({ variables, queryId: qid })
    });
    const j = await r.json().catch(() => ({}));
    const xid = j && j.data && j.data.tweet && j.data.tweet.rest_id;
    if (!r.ok || !xid) return { error: '예약 등록 실패: ' + ((j.errors && j.errors[0] && j.errors[0].message) || (r.ok ? JSON.stringify(j).slice(0, 160) : 'HTTP ' + r.status)) };
    return { x_id: String(xid) };
  } catch (e) {
    return { error: String(e.message || e).slice(0, 240) };
  }
}

// an x.com tab to run in: an open one, or a background one this call opens (closed by the caller)
async function xTab() {
  const tabs = await chrome.tabs.query({ url: ['https://x.com/*'], status: 'complete' });
  if (tabs.length) return { id: tabs[0].id, opened: false };
  const t = await chrome.tabs.create({ url: 'https://x.com/home', active: false });
  await new Promise((res) => {
    const done = (id, info) => { if (id === t.id && info.status === 'complete') { chrome.tabs.onUpdated.removeListener(done); res(); } };
    chrome.tabs.onUpdated.addListener(done);
    setTimeout(() => { chrome.tabs.onUpdated.removeListener(done); res(); }, 30000);
  });
  await new Promise((res) => setTimeout(res, 2000));
  return { id: t.id, opened: true };
}

async function fileB64(url) {
  const r = await fetch(url);
  if (!r.ok) throw new Error('파일 HTTP ' + r.status);
  const bytes = new Uint8Array(await r.arrayBuffer());
  let s = '';
  for (let i = 0; i < bytes.length; i += 0x8000) s += String.fromCharCode.apply(null, bytes.subarray(i, i + 0x8000));
  return btoa(s);
}

async function scheduleOne(item, tabId) {
  const files = [];
  for (const f of item.files || []) files.push({ b64: await fileB64(f.url), mime: f.mime, kind: f.kind, filename: f.filename });
  const [res] = await chrome.scripting.executeScript({ target: { tabId }, world: 'MAIN', func: scheduleInPage,
    args: [Object.assign({}, item, { files }), CREATE_SCHED_QID, X_WEB_AUTH] });
  const out = res && res.result;
  if (!out) throw new Error('x.com 탭에서 실행하지 못했습니다');
  if (out.error) throw new Error(out.error);
  return out.x_id;
}

let queueBusy = false;
async function processQueue() {
  if (queueBusy) return;
  queueBusy = true;
  try {
    const s = await get(['status', 'viewer', 'queued']);
    const handle = (s.status && s.status.handle) || '';
    if (!handle || !s.viewer || s.viewer.toLowerCase() !== handle.toLowerCase()) return;
    const ct0 = await ct0Cookie();
    if (!ct0) return;
    const q = await api('/xapi/queue');
    const items = q.items || [];
    if (!items.length) return;
    const done = s.queued || {};   // draft id -> X id, so a report that failed to reach MV Cut is retried, never the scheduling
    let tab = null;
    for (const item of items) {
      const body = {};
      if (done[item.id]) body.x_id = done[item.id];
      else {
        try {
          if (!tab) tab = await xTab();
          body.x_id = await scheduleOne(item, tab.id);
          done[item.id] = body.x_id;
          await set({ queued: done });
        } catch (e) { body.error = String(e.message || e).slice(0, 250); }
      }
      await api('/xapi/queue/' + item.id, { method: 'POST', body: JSON.stringify(body) });
      if (body.x_id) { delete done[item.id]; await set({ queued: done }); }
    }
    if (tab && tab.opened) chrome.tabs.remove(tab.id).catch(() => {});
    await set({ lastError: '' });
    pollScheduled();
  } catch (e) {
    await set({ lastError: String(e.message || e) });
  } finally {
    queueBusy = false;
  }
}

async function addPosts(list) {
  const s = await get(['posts', 'status', 'viewer']);
  const posts = s.posts || {};
  const handle = ((s.status && s.status.handle) || s.viewer || '').toLowerCase();
  const limits = (s.status && s.status.limits) || DEFAULT_LIMITS;
  const cutoff = Date.now() - KEEP_DAYS * 86400000;
  const fresh = [];
  for (const p of list) {
    if (!handle || !p.id || !p.time || String(p.handle).toLowerCase() !== handle) continue;
    const old = posts[p.id];
    if (!old) {
      posts[p.id] = { id: p.id, handle: p.handle, time: p.time, kind: p.kind || 'post', text: p.text || '', reply_to: p.reply_to || '', synced: false };
      fresh.push(posts[p.id]);
    } else {
      // later sightings can add the kind / reply target / text the first one lacked
      let changed = false;
      if (old.kind === 'post' && p.kind && p.kind !== 'post') { old.kind = p.kind; changed = true; }
      if (!old.reply_to && p.reply_to) { old.reply_to = p.reply_to; changed = true; }
      if (!old.text && p.text) { old.text = p.text; changed = true; }
      if (changed) old.synced = false;
    }
  }
  for (const id of Object.keys(posts)) if (Date.parse(posts[id].time) < cutoff) delete posts[id];
  const local = computeLocal(posts, limits);
  await set({ posts, local });

  // a post made just now that came too soon after the previous one
  const now = Date.now();
  for (const p of fresh) {
    const t = Date.parse(p.time);
    if (now - t > 10 * 60000 || !counted(p.kind, limits)) continue;
    const before = Object.values(posts).filter((q) => q.id !== p.id && counted(q.kind, limits) && Date.parse(q.time) < t)
      .map((q) => Date.parse(q.time)).sort((a, b) => b - a)[0];
    // nearest minute, as on the server: a scheduled post lands a second or two late
    if (before && Math.round((t - before) / 60000) < limits.gap_min) {
      notify('gap', '게시 간격이 짧습니다', '직전 게시와 ' + Math.round((t - before) / 60000) + '분 간격입니다 (권장 ' + limits.gap_min + '분 이상).');
    }
    if (local.hour > limits.hour_max) notify('hour', '1시간 게시 수 초과', '최근 1시간 ' + local.hour + '개 (권장 ' + limits.hour_max + '개 이하).');
  }
  await refreshBadge();
  upload();
}

chrome.runtime.onMessage.addListener((msg, _sender, reply) => {
  if (msg && msg.type === 'posts') { addPosts(msg.posts || []); return false; }
  if (msg && msg.type === 'scheduled') {
    if (msg.qid && /^[A-Za-z0-9_-]{10,40}$/.test(msg.qid)) set({ schedQid: msg.qid });
    api('/xapi/scheduled', { method: 'POST', body: JSON.stringify({ items: msg.items || [], viewer: msg.viewer || '' }) })
      .catch((e) => set({ lastError: String(e.message || e) }));
    return false;
  }
  // SAVE button on a post page: MV Cut downloads the link into the library
  if (msg && msg.type === 'save-post') {
    api('/xapi/import', { method: 'POST', body: JSON.stringify({ url: msg.url, title: msg.title || '', images: msg.images || [], video: !!msg.video, text: msg.text || '' }) })
      .then((j) => reply(j)).catch((e) => reply({ ok: false, error: e.message }));
    return true;
  }
  if (msg && msg.type === 'save-status') {
    api('/xapi/jobs/' + encodeURIComponent(msg.job)).then((j) => reply(Object.assign({ ok: true }, j)))
      .catch((e) => reply({ ok: false, error: e.message }));
    return true;
  }
  if (msg && msg.type === 'poll') { upload().then(poll).then(processQueue).then(() => reply({ ok: true })); return true; }
  return false;
});

// After an install, update or reload, x.com tabs that were already open keep a dead copy of
// the content script that can no longer reach this worker; give them a live one.
async function injectOpenTabs() {
  for (const t of await chrome.tabs.query({ url: ['https://www.tiktok.com/*', 'https://www.youtube.com/*', 'https://www.instagram.com/*'] })) {
    try { await chrome.scripting.executeScript({ target: { tabId: t.id }, files: ['content-save.js'] }); } catch (e) { /* ignore */ }
  }
  for (const t of await chrome.tabs.query({ url: ['https://x.com/*', 'https://twitter.com/*'] })) {
    try { await chrome.scripting.executeScript({ target: { tabId: t.id }, files: ['content-save-x.js'] }); } catch (e) { /* ignore */ }
  }
  for (const t of await chrome.tabs.query({ url: ['https://www.facebook.com/*'] })) {
    try { await chrome.scripting.executeScript({ target: { tabId: t.id }, files: ['content-save-fb.js'] }); } catch (e) { /* ignore */ }
  }
  const tabs = await chrome.tabs.query({ url: ['https://x.com/*', 'https://twitter.com/*'] });
  for (const t of tabs) {
    try {
      await chrome.scripting.executeScript({ target: { tabId: t.id }, files: ['hook.js'], world: 'MAIN' });
      await chrome.scripting.executeScript({ target: { tabId: t.id }, files: ['content.js'] });
    } catch (e) { /* discarded or restricted tab */ }
  }
}

chrome.runtime.onInstalled.addListener(() => {
  injectOpenTabs();
  chrome.alarms.create('poll', { periodInMinutes: 5 });
  chrome.alarms.create('badge', { periodInMinutes: 1 });
  chrome.alarms.create('sched', { periodInMinutes: 10 });
  chrome.alarms.create('queue', { periodInMinutes: 2 });
  poll();
  pollScheduled();
  processQueue();
});
chrome.runtime.onStartup.addListener(() => { poll(); });
// alarms are made on install/update; make sure the draft queue one exists whenever the worker starts
chrome.alarms.get('queue', (a) => { if (!a) chrome.alarms.create('queue', { periodInMinutes: 2 }); });
chrome.alarms.onAlarm.addListener((a) => {
  if (a.name === 'poll') upload().then(poll);
  if (a.name === 'badge') refreshBadge();
  if (a.name === 'sched') pollScheduled();
  if (a.name === 'queue') processQueue();
});
chrome.notifications.onClicked.addListener(async () => {
  const { server } = await settings();
  chrome.tabs.create({ url: server + '/xwatch' });
});
