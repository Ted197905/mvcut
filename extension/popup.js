const $ = (id) => document.getElementById(id);
const TESTS = { search: '검색 차단', typeahead: '검색 제안', ghost: '고스트 밴', deboost: '답글 디부스트' };
const KINDS = { post: '게시', reply: '답글', quote: '인용', repost: '재게시' };

const hm = (ts) => { const d = new Date(ts * 1000); return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0'); };
const md = (ts) => { const d = new Date(ts * 1000); return (d.getMonth() + 1) + '/' + d.getDate(); };
const ago = (ts) => { const s = Date.now() / 1000 - ts; return s < 60 ? '방금' : s < 3600 ? Math.floor(s / 60) + '분' : s < 86400 ? Math.floor(s / 3600) + '시간' : Math.floor(s / 86400) + '일'; };
const gapTxt = (m) => m == null ? '-' : m >= 60 ? Math.floor(m / 60) + '시간 ' + (m % 60) + '분' : m + '분';

function badge(el, cls, text) { el.className = 'badge ' + cls; el.textContent = text; }

/** Server timeline when available, otherwise the posts this browser saw. */
function timeline(st, posts, limits) {
  const recent = Object.values(posts || {}).filter((p) => Date.parse(p.time) > Date.now() - 172800000).length;
  if (st && st.timeline && st.timeline.posts.length >= recent) return st.timeline;
  const list = Object.values(posts || {}).map((p) => ({ id: p.id, kind: p.kind, ts: Date.parse(p.time) / 1000, text: p.text, reply_to: p.reply_to }))
    .filter((p) => p.ts > Date.now() / 1000 - 172800).sort((a, b) => a.ts - b.ts);
  let prev = null;
  for (const p of list) {
    const counts = p.kind === 'post' || p.kind === 'quote' || (p.kind === 'reply' && limits.count_replies);
    if (!counts) { p.gap = null; p.dim = true; continue; }
    p.gap = prev ? Math.floor((p.ts - prev) / 60) : null;
    p.short = p.gap != null && p.gap < limits.gap_min;
    prev = p.ts;
  }
  return { posts: list.reverse(), warnings: [] };
}

async function render() {
  const s = await chrome.storage.local.get(['token', 'status', 'posts', 'local', 'lastSync', 'lastError', 'server']);
  const st = s.status;
  if (!s.token) { $('setup').hidden = false; $('main').hidden = true; return; }
  $('setup').hidden = true; $('main').hidden = false;
  $('handle').textContent = st && st.handle ? '@' + st.handle : '';
  const limits = (st && st.limits) || { gap_min: 15, hour_max: 4, day_max: 30, count_replies: false };

  const c = st && st.check;
  if (!c) { badge($('checkBadge'), 'none', '기록 없음'); $('checkSummary').textContent = st && !st.probe ? '서버에 검사용 부계정 쿠키가 없습니다.' : ''; }
  else {
    badge($('checkBadge'), c.status === 'ok' ? 'ok' : c.status === 'banned' ? 'bad' : 'warn', c.status === 'ok' ? '정상' : c.status === 'banned' ? '제한 감지' : '검사 실패');
    $('checkSummary').textContent = c.summary + ' · ' + hm(c.at) + ' (' + ago(c.at) + ' 전)';
    $('tests').innerHTML = '';
    for (const k of Object.keys(TESTS)) {
      const v = c.tests ? c.tests[k] : null;
      const d = document.createElement('div');
      d.innerHTML = '<span></span><span class="badge"></span>';
      d.firstChild.textContent = TESTS[k];
      badge(d.lastChild, v === true ? 'bad' : v === false ? 'ok' : 'none', v === true ? '제한' : v === false ? '정상' : '-');
      $('tests').appendChild(d);
    }
  }

  const now = Date.now() / 1000;
  const loc = s.local || {};
  const nextOk = Math.max(loc.next_ok || 0, (st && st.timeline && st.timeline.next_ok) || 0);
  if (nextOk > now + 30) badge($('postBadge'), 'warn', hm(nextOk) + ' 이후 (' + Math.ceil((nextOk - now) / 60) + '분)');
  else badge($('postBadge'), 'ok', '지금 가능');
  const tl = timeline(st, s.posts, limits);
  $('hour').textContent = Math.max(loc.hour || 0, (st && st.timeline && st.timeline.hour) || 0) + '/' + limits.hour_max;
  $('day').textContent = Math.max(loc.day || 0, (st && st.timeline && st.timeline.day) || 0) + '/' + limits.day_max;
  const last = Math.max(loc.last || 0, (st && st.timeline && st.timeline.last) || 0);
  $('last').textContent = last ? ago(last) : '-';
  $('warnings').innerHTML = '';
  for (const w of tl.warnings || []) { const p = document.createElement('p'); p.className = 'warn small'; p.textContent = w; $('warnings').appendChild(p); }

  const box = $('posts');
  box.innerHTML = '';
  if (!tl.posts.length) box.innerHTML = '<p class="muted small" style="padding:10px 12px;margin:0">최근 48시간 기록 없음. x.com에서 내 프로필의 게시물/답글 탭을 열면 기록됩니다.</p>';
  for (const p of tl.posts.slice(0, 60)) {
    const row = document.createElement('a');
    row.className = 'post' + (p.short ? ' short' : p.gap != null ? ' kept' : '') + (p.dim || p.counted === false ? ' dim' : '');
    row.href = 'https://x.com/' + (st && st.handle ? st.handle : 'i') + '/status/' + p.id;
    row.target = '_blank';
    row.innerHTML = '<span class="t"><b></b><i></i></span><span class="b"><span class="m"><em></em><small></small></span><span class="x"></span></span>';
    row.querySelector('b').textContent = hm(p.ts);
    row.querySelector('i').textContent = md(p.ts);
    row.querySelector('em').textContent = KINDS[p.kind] || p.kind;
    row.querySelector('em').className = p.kind;
    row.querySelector('small').textContent = '간격 ' + gapTxt(p.gap);
    row.querySelector('.x').textContent = (p.reply_to && !(p.text || '').startsWith('@' + p.reply_to) ? '@' + p.reply_to + ' · ' : '') + (p.text || '(미디어)');
    box.appendChild(row);
  }

  $('sync').textContent = s.lastError ? '오류: ' + s.lastError : s.lastSync ? '동기화 ' + ago(s.lastSync / 1000) + ' 전' : '';
}

$('openOptions').onclick = $('opts').onclick = (e) => { e.preventDefault(); chrome.runtime.openOptionsPage(); };
$('dash').onclick = async (e) => {
  e.preventDefault();
  const { server } = await chrome.storage.local.get(['server']);
  chrome.tabs.create({ url: (server || 'https://mvcut.blackout.kr').replace(/\/+$/, '') + '/xwatch' });
};
$('refresh').onclick = (e) => { e.preventDefault(); $('sync').textContent = '동기화 중...'; chrome.runtime.sendMessage({ type: 'poll' }, render); };
chrome.storage.onChanged.addListener(render);
render();
chrome.runtime.sendMessage({ type: 'poll' }, render);
