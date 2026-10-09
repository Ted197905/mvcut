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
  return { server: (s.server || DEFAULT_SERVER).replace(/\/+$/, ''), token: s.token || '' };
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
  const dayList = times.filter((t) => t > now - 86400);
  let next = now;
  if (last) next = Math.max(next, last + limits.gap_min * 60);
  if (hourList.length >= limits.hour_max) next = Math.max(next, hourList[hourList.length - limits.hour_max] + 3600);
  if (dayList.length >= limits.day_max) next = Math.max(next, dayList[dayList.length - limits.day_max] + 86400);
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
  const { notified = {} } = await get(['notified']);
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

async function upload() {
  const { posts = {} } = await get(['posts']);
  const unsent = Object.values(posts).filter((p) => !p.synced);
  if (!unsent.length) return;
  try {
    const st = await api('/xapi/posts', { method: 'POST', body: JSON.stringify({ posts: unsent }) });
    const cur = (await get(['posts'])).posts || {};
    for (const p of unsent) if (cur[p.id]) cur[p.id].synced = true;
    await set({ posts: cur });
    await handleStatus(st);
  } catch (e) {
    await set({ lastError: String(e.message || e) });
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
    if (!p.id || !p.time || (handle && String(p.handle).toLowerCase() !== handle)) continue;
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
    if (before && t - before < limits.gap_min * 60000) {
      notify('gap', '게시 간격이 짧습니다', '직전 게시와 ' + Math.round((t - before) / 60000) + '분 간격입니다 (권장 ' + limits.gap_min + '분 이상).');
    }
    if (local.hour > limits.hour_max) notify('hour', '1시간 게시 수 초과', '최근 1시간 ' + local.hour + '개 (권장 ' + limits.hour_max + '개 이하).');
  }
  await refreshBadge();
  upload();
}

chrome.runtime.onMessage.addListener((msg, _sender, reply) => {
  if (msg && msg.type === 'posts') { addPosts(msg.posts || []); return false; }
  if (msg && msg.type === 'poll') { upload().then(poll).then(() => reply({ ok: true })); return true; }
  return false;
});

chrome.runtime.onInstalled.addListener(() => {
  chrome.alarms.create('poll', { periodInMinutes: 5 });
  chrome.alarms.create('badge', { periodInMinutes: 1 });
  poll();
});
chrome.runtime.onStartup.addListener(() => { poll(); });
chrome.alarms.onAlarm.addListener((a) => {
  if (a.name === 'poll') upload().then(poll);
  if (a.name === 'badge') refreshBadge();
});
chrome.notifications.onClicked.addListener(async () => {
  const { server } = await settings();
  chrome.tabs.create({ url: server + '/xwatch' });
});
