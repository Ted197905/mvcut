const $ = (id) => document.getElementById(id);

chrome.storage.local.get(['server', 'token'], (s) => {
  $('server').value = s.server || 'https://mvcut.blackout.kr';
  $('token').value = s.token || '';
});

$('save').onclick = async () => {
  const server = $('server').value.trim().replace(/\/+$/, '') || 'https://mvcut.blackout.kr';
  const token = $('token').value.trim();
  if (!/^https?:\/\//.test(server)) { $('msg').textContent = '서버 주소는 http(s)://로 시작해야 합니다.'; return; }
  await chrome.storage.local.set({ server, token });
  $('msg').textContent = '확인 중...';
  try {
    const res = await fetch(server + '/xapi/status', { headers: { Authorization: 'Bearer ' + token } });
    if (res.status === 401) throw new Error('토큰이 올바르지 않습니다.');
    if (!res.ok) throw new Error('HTTP ' + res.status);
    const st = await res.json();
    await chrome.storage.local.set({ status: st });
    $('msg').textContent = '연결됨' + (st.handle ? ': @' + st.handle : ' (서버에서 X 아이디를 먼저 저장하세요)');
    chrome.runtime.sendMessage({ type: 'poll' });
  } catch (e) {
    $('msg').textContent = '실패: ' + e.message + (/^http:\/\/localhost/.test(server) || /mvcut\.blackout\.kr/.test(server) ? '' : ' (manifest의 host_permissions에 없는 주소입니다)');
  }
};

$('clear').onclick = async () => {
  await chrome.storage.local.remove(['posts', 'local']);
  $('msg').textContent = '이 브라우저의 기록을 지웠습니다 (서버 기록은 그대로).';
};
