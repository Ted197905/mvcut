/* MV Cut editor: timeline (split/trim/delete), screen crop & masks, output & job submission. */
(function () {
  'use strict';
  const D = window.EDITOR_DATA;
  const $ = (id) => document.getElementById(id);
  const clamp = (v, a, b) => Math.max(a, Math.min(b, v));
  const escHtml = (v) => String(v).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const FPS = D.fps > 0 ? D.fps : 30;
  const FRAME = 1 / FPS;
  const DUR = D.duration;
  const MIN_SEG = Math.max(FRAME, 0.04);
  const snapFrame = (t) => clamp(Math.round(t * FPS) / FPS, 0, DUR);

  /* ---------- timecode ---------- */
  function tc(t, withFrames = true) {
    t = Math.max(0, t || 0);
    const totalFrames = Math.round(t * FPS);
    const f = totalFrames % Math.round(FPS);
    const s = Math.floor(totalFrames / FPS);
    const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60;
    const p = (n, w = 2) => String(n).padStart(w, '0');
    return `${p(h)}:${p(m)}:${p(sec)}` + (withFrames ? `:${p(f)}` : '');
  }
  function parseTc(str) {
    str = String(str || '').trim();
    if (!str) return null;
    if (/^\d+(\.\d+)?$/.test(str)) return parseFloat(str);
    const m = str.match(/^(?:(\d+):)?(?:(\d+):)?(\d+)(?:[:.](\d+))?$/);
    if (!m) return null;
    const parts = [m[1], m[2], m[3]].filter(v => v !== undefined).map(Number);
    let sec = 0;
    if (parts.length === 3) sec = parts[0] * 3600 + parts[1] * 60 + parts[2];
    else if (parts.length === 2) sec = parts[0] * 60 + parts[1];
    else sec = parts[0];
    if (m[4] !== undefined) {
      const sepIsDot = str.lastIndexOf('.') > str.lastIndexOf(':');
      sec += sepIsDot ? parseFloat('0.' + m[4]) : Number(m[4]) / FPS;
    }
    return sec;
  }

  /* ---------- state ---------- */
  const state = {
    segments: [{ start: 0, end: DUR, removed: false }],
    selected: 0,
    markIn: null, markOut: null,
    crop: null,               // {x,y,w,h} in source px, null = off
    cropAR: 'free',
    masks: [],                // {x,y,w,h,style}
    selectedMask: -1,
    watermark: null,          // {text,font,size,color,opacity,x,y,anchor,style}
    speed: 1, keepAudio: true,
    enhance: { sharpen: 'off', denoise: true },
    smooth: 'off',
    restore: { mode: 'off', model: 'general' },
    expand: { w: 1, h: 1 },
    erase: { quality: 'normal' },
    subtitles: [null, null],   // up to two layers: {template,font,size,color,anchor,x,y,cues:[]}
    subLayer: 0,
    subCue: -1,
    output: { format: 'mp4', height: 0, quality: 'high' },
  };
  const undoStack = [], redoStack = [];
  function snapshot() { return JSON.stringify({ segments: state.segments, crop: state.crop, masks: state.masks, speed: state.speed, watermark: state.watermark, subtitles: state.subtitles }); }
  function commit() { undoStack.push(snapshot()); if (undoStack.length > 100) undoStack.shift(); redoStack.length = 0; updateUndoButtons(); }
  function restore(json) { const s = JSON.parse(json); state.segments = s.segments; state.crop = s.crop; state.masks = s.masks; state.speed = s.speed; state.watermark = s.watermark; if (s.subtitles) state.subtitles = s.subtitles; state.selected = clamp(state.selected, 0, state.segments.length - 1); state.selectedMask = -1; renderAll(); }
  function undo() { if (!undoStack.length) return; redoStack.push(snapshot()); restore(undoStack.pop()); updateUndoButtons(); }
  function redo() { if (!redoStack.length) return; undoStack.push(snapshot()); restore(redoStack.pop()); updateUndoButtons(); }
  function updateUndoButtons() { $('btnUndo').disabled = !undoStack.length; $('btnRedo').disabled = !redoStack.length; }

  // restore previous params (re-edit)
  if (D.params && D.params.keep) {
    const keep = D.params.keep;
    const segs = []; let cur = 0;
    keep.forEach(([s, e]) => { if (s > cur + 0.001) segs.push({ start: cur, end: s, removed: true }); segs.push({ start: s, end: e, removed: false }); cur = e; });
    if (cur < DUR - 0.001) segs.push({ start: cur, end: DUR, removed: true });
    state.segments = segs;
    state.crop = D.params.crop || null;
    state.masks = D.params.masks || [];
    state.speed = D.params.speed || 1;
    state.watermark = D.params.watermark || null;
    state.keepAudio = D.params.keepAudio !== false;
    if (D.params.output) state.output = D.params.output;
  }

  /* ---------- segment ops ---------- */
  function segAt(t) { for (let i = 0; i < state.segments.length; i++) { const s = state.segments[i]; if (t >= s.start && t < s.end) return i; } return state.segments.length - 1; }
  function split(t) {
    t = snapFrame(t);
    const i = segAt(t); const s = state.segments[i];
    if (t - s.start < MIN_SEG || s.end - t < MIN_SEG) return false;
    commit();
    state.segments.splice(i, 1, { start: s.start, end: t, removed: s.removed }, { start: t, end: s.end, removed: s.removed });
    state.selected = i + 1;
    renderAll(); return true;
  }
  function toggleSeg(i) {
    if (i < 0 || i >= state.segments.length) return;
    if (!state.segments[i].removed && state.segments.filter(s => !s.removed).length === 1) { flash('마지막 남은 구간은 삭제할 수 없습니다.'); return; }
    commit(); state.segments[i].removed = !state.segments[i].removed; renderAll();
  }
  function mergeBoundary(i) { // merge segments i and i+1
    if (i < 0 || i + 1 >= state.segments.length) return;
    commit();
    const a = state.segments[i], b = state.segments[i + 1];
    state.segments.splice(i, 2, { start: a.start, end: b.end, removed: a.removed && b.removed });
    state.selected = i; renderAll();
  }
  function moveBoundary(i, t, live) { // boundary between i and i+1
    const a = state.segments[i], b = state.segments[i + 1];
    t = clamp(snapFrame(t), a.start + MIN_SEG, b.end - MIN_SEG);
    a.end = t; b.start = t;
    if (live) renderSegments(); else renderAll();
  }
  function applyMarks(mode) { // 'keep' or 'cut'
    if (state.markIn === null || state.markOut === null || state.markOut - state.markIn < MIN_SEG) { flash('In / Out 마크를 먼저 지정하세요.'); return; }
    commit();
    const a = snapFrame(state.markIn), b = snapFrame(state.markOut);
    // split at a and b without pushing extra undo entries
    const doSplit = (t) => { const i = segAt(t); const s = state.segments[i]; if (t - s.start >= MIN_SEG && s.end - t >= MIN_SEG) state.segments.splice(i, 1, { start: s.start, end: t, removed: s.removed }, { start: t, end: s.end, removed: s.removed }); };
    doSplit(a); doSplit(b);
    state.segments.forEach(s => {
      const inside = s.start >= a - 0.0005 && s.end <= b + 0.0005;
      if (mode === 'keep') s.removed = !inside; else if (inside) s.removed = true;
    });
    if (!state.segments.some(s => !s.removed)) state.segments.forEach(s => s.removed = false);
    state.selected = segAt(a);
    state.markIn = state.markOut = null;
    renderAll();
  }
  function keepList() { return state.segments.filter(s => !s.removed).map(s => [round3(s.start), round3(s.end)]); }
  const round3 = (v) => Math.round(v * 1000) / 1000;
  function outputDuration() { return keepList().reduce((t, [s, e]) => t + (e - s), 0) / state.speed; }

  /* ---------- video / playhead ---------- */
  const video = $('video');
  let playhead = 0, playing = false, rafId = 0;
  function seek(t, fromVideo) {
    playhead = clamp(t, 0, DUR);
    if (!fromVideo && Math.abs(video.currentTime - playhead) > 0.001) video.currentTime = playhead;
    // the subtitle preview follows the playhead, not the video's seeked event, which
    // does not fire while the media is still loading
    renderPlayhead(); renderSubs();
  }
  function play() { if (playing) return; const i = segAt(playhead); if (state.segments[i].removed) jumpToNextKept(); video.play().catch(() => {}); }
  function pause() { video.pause(); }
  function jumpToNextKept() {
    const i = segAt(playhead);
    for (let k = i; k < state.segments.length; k++) if (!state.segments[k].removed) { seek(state.segments[k].start); return true; }
    pause(); seek(state.segments[i].end); return false;
  }
  video.addEventListener('play', () => { playing = true; $('btnPlay').innerHTML = window.ICONS.pause; loop(); });
  video.addEventListener('pause', () => { playing = false; $('btnPlay').innerHTML = window.ICONS.play; cancelAnimationFrame(rafId); seek(video.currentTime, true); });
  video.addEventListener('ended', () => { playing = false; $('btnPlay').innerHTML = window.ICONS.play; });
  video.addEventListener('loadedmetadata', () => layoutStage());
  video.addEventListener('seeked', () => renderSubs());
  function loop() {
    if (!playing) return;
    playhead = video.currentTime;
    const i = segAt(playhead);
    if (state.segments[i].removed) { if (!jumpToNextKept()) return; }
    renderPlayhead(); keepPlayheadVisible(); renderSubs();
    rafId = requestAnimationFrame(loop);
  }
  function stepFrames(n) { pause(); seek(snapFrame(playhead + n * FRAME)); }
  let jkl = 0;
  function shuttle(dir) { // J/K/L
    if (dir === 0) { pause(); video.playbackRate = 1; jkl = 0; return; }
    if (dir > 0) { jkl = jkl > 0 ? Math.min(jkl * 2, 8) : 1; video.playbackRate = jkl; play(); }
    else { pause(); stepFrames(-Math.round(FPS / 4)); } // no native reverse playback: step back 1/4 s
  }

  /* ---------- timeline geometry ---------- */
  const tlScroll = $('tlScroll'), tlInner = $('tlInner'), ruler = $('ruler'), track = $('track'), stripEl = $('strip'), segLayer = $('segments'), markLayer = $('marks'), playheadEl = $('playhead');
  let zoom = 1;
  const trackWidth = () => Math.max(10, tlScroll.clientWidth * zoom);
  const pps = () => trackWidth() / DUR; // px per second
  const xOf = (t) => t * pps();
  const tOf = (x) => x / pps();
  function setZoom(z, anchorT) {
    const viewX = anchorT !== undefined ? xOf(anchorT) - tlScroll.scrollLeft : null;
    zoom = clamp(z, 1, 60); $('zoom').value = zoom;
    layoutTimeline();
    if (viewX !== null) tlScroll.scrollLeft = xOf(anchorT) - viewX;
  }
  function layoutTimeline() {
    const w = trackWidth();
    tlInner.style.width = w + 'px';
    ruler.width = Math.floor(w * devicePixelRatio); ruler.height = Math.floor(28 * devicePixelRatio);
    ruler.style.width = w + 'px';
    drawRuler(); renderSegments(); renderMarks(); renderCueTrack(true); renderPlayhead();
  }
  function drawRuler() {
    const ctx = ruler.getContext('2d'); const dpr = devicePixelRatio; const w = ruler.width / dpr, h = 28;
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.clearRect(0, 0, w, h); ctx.fillStyle = '#1a1a1d'; ctx.fillRect(0, 0, w, h);
    const p = pps();
    const steps = [FRAME, FRAME * 5, 0.5, 1, 2, 5, 10, 15, 30, 60, 120, 300, 600];
    let major = steps.find(s => s * p >= 90) || 600;
    const minor = major / (major === FRAME ? 1 : (major <= FRAME * 5 ? 5 : (major === 0.5 ? 5 : (major === 1 ? 4 : (major === 2 ? 4 : (major === 5 ? 5 : (major === 10 ? 5 : (major === 15 ? 3 : (major === 30 ? 6 : 6)))))))));
    ctx.strokeStyle = '#4a4a50'; ctx.fillStyle = '#9a9aa2'; ctx.font = '10px -apple-system, "SF Mono", Menlo, monospace'; ctx.textBaseline = 'top';
    const start = Math.floor(tOf(tlScroll.scrollLeft) / minor) * minor, end = Math.min(DUR, tOf(tlScroll.scrollLeft + tlScroll.clientWidth) + minor);
    ctx.beginPath();
    for (let t = start; t <= end + 1e-6; t += minor) {
      const x = Math.round(xOf(t)) + 0.5; const isMajor = Math.abs(t / major - Math.round(t / major)) < 1e-6;
      ctx.moveTo(x, isMajor ? 14 : 21); ctx.lineTo(x, 28);
      if (isMajor) ctx.fillText(tc(t, major < 1), x + 3, 3);
    }
    ctx.stroke();
    ctx.strokeStyle = '#333338'; ctx.beginPath(); ctx.moveTo(0, 27.5); ctx.lineTo(w, 27.5); ctx.stroke();
  }
  function renderPlayhead() {
    playheadEl.style.left = xOf(playhead) + 'px';
    $('tcCurrent').value = tc(playhead);
    if (!$('segStart').matches(':focus')) syncSegFields();
  }
  function keepPlayheadVisible() { const x = xOf(playhead); const l = tlScroll.scrollLeft, r = l + tlScroll.clientWidth; if (x < l || x > r - 20) tlScroll.scrollLeft = x - tlScroll.clientWidth * 0.2; }
  function renderSegments() {
    segLayer.innerHTML = '';
    state.segments.forEach((s, i) => {
      const el = document.createElement('div');
      el.className = 'seg' + (s.removed ? ' removed' : '') + (i === state.selected ? ' selected' : '');
      el.style.left = xOf(s.start) + 'px'; el.style.width = Math.max(1, xOf(s.end) - xOf(s.start)) + 'px';
      const len = document.createElement('span'); len.className = 'len'; len.textContent = tc(s.end - s.start, false) + ((s.end - s.start) < 60 ? '.' + String(Math.round(((s.end - s.start) % 1) * 100)).padStart(2, '0') : '');
      el.appendChild(len);
      el.dataset.i = i;
      segLayer.appendChild(el);
      if (i < state.segments.length - 1) {
        const b = document.createElement('div'); b.className = 'boundary'; b.style.left = xOf(s.end) + 'px'; b.dataset.i = i; segLayer.appendChild(b);
      }
    });
    renderSegList();
  }
  function renderMarks() {
    markLayer.innerHTML = '';
    if (state.markIn !== null && state.markOut !== null) { const r = document.createElement('div'); r.className = 'markrange'; r.style.left = xOf(state.markIn) + 'px'; r.style.width = (xOf(state.markOut) - xOf(state.markIn)) + 'px'; markLayer.appendChild(r); }
    if (state.markIn !== null) { const m = document.createElement('div'); m.className = 'mark in'; m.style.left = xOf(state.markIn) + 'px'; markLayer.appendChild(m); }
    if (state.markOut !== null) { const m = document.createElement('div'); m.className = 'mark out'; m.style.left = (xOf(state.markOut) - 2) + 'px'; markLayer.appendChild(m); }
    $('markIn').value = state.markIn === null ? '' : tc(state.markIn);
    $('markOut').value = state.markOut === null ? '' : tc(state.markOut);
  }
  function renderSegList() {
    const list = $('segList'); list.innerHTML = '';
    state.segments.forEach((s, i) => {
      const el = document.createElement('div'); el.className = 'segitem' + (s.removed ? ' removed' : '') + (i === state.selected ? ' selected' : '');
      el.innerHTML = `<span class="dot"></span><span>${tc(s.start)} - ${tc(s.end)}</span><span class="len">${(s.end - s.start).toFixed(2)}s</span>`;
      el.addEventListener('click', () => { state.selected = i; pause(); seek(s.start); renderSegments(); });
      list.appendChild(el);
    });
    const kept = keepList();
    $('keepSummary').textContent = `남는 구간 ${kept.length}개, 총 ${outputDuration().toFixed(2)}s (원본 ${DUR.toFixed(2)}s)`;
    $('outDuration').value = tc(outputDuration());
    syncSegFields();
  }
  function syncSegFields() { const s = state.segments[state.selected]; if (!s) return; $('segStart').value = tc(s.start); $('segEnd').value = tc(s.end); }

  /* ---------- timeline interactions ---------- */
  function snapTime(t, exclude) { // snap to boundaries / playhead / marks within 8px
    const tol = 8 / pps(); let best = t, bd = tol;
    const cands = [0, DUR, playhead];
    state.segments.forEach((s, i) => { if (i !== exclude) cands.push(s.end); if (i - 1 !== exclude) cands.push(s.start); });
    if (state.markIn !== null) cands.push(state.markIn); if (state.markOut !== null) cands.push(state.markOut);
    cands.forEach(c => { const d = Math.abs(c - t); if (d < bd) { bd = d; best = c; } });
    return best;
  }
  const localX = (e) => e.clientX - tlInner.getBoundingClientRect().left;
  // ruler / track scrub
  function startScrub(e) {
    if (e.button !== 0) return;
    pause();
    const move = (ev) => { const t = snapFrame(tOf(clamp(localX(ev), 0, trackWidth()))); seek(ev.altKey ? t : snapTime(t, -1)); };
    move(e);
    const up = () => { window.removeEventListener('pointermove', move); window.removeEventListener('pointerup', up); };
    window.addEventListener('pointermove', move); window.addEventListener('pointerup', up);
  }
  ruler.addEventListener('pointerdown', startScrub);
  segLayer.addEventListener('pointerdown', (e) => {
    const b = e.target.closest('.boundary');
    if (b) { startBoundaryDrag(+b.dataset.i, e, b); return; }
    const seg = e.target.closest('.seg');
    if (seg) { state.selected = +seg.dataset.i; renderSegments(); }
    startScrub(e);
  });
  segLayer.addEventListener('dblclick', (e) => {
    const b = e.target.closest('.boundary'); if (b) { mergeBoundary(+b.dataset.i); return; }
    const seg = e.target.closest('.seg'); if (seg) toggleSeg(+seg.dataset.i);
  });
  function startBoundaryDrag(i, e, el) {
    e.stopPropagation(); e.preventDefault(); pause();
    el.classList.add('dragging'); commit();
    const move = (ev) => { let t = tOf(clamp(localX(ev), 0, trackWidth())); if (!ev.altKey) t = snapTime(t, i); moveBoundary(i, t, true); seek(state.segments[i].end); };
    const up = () => { el.classList.remove('dragging'); window.removeEventListener('pointermove', move); window.removeEventListener('pointerup', up); renderAll(); };
    window.addEventListener('pointermove', move); window.addEventListener('pointerup', up);
  }
  tlScroll.addEventListener('wheel', (e) => {
    if (e.ctrlKey || e.metaKey) { e.preventDefault(); const t = tOf(localX(e)); setZoom(zoom * (e.deltaY < 0 ? 1.15 : 1 / 1.15), t); }
    else if (Math.abs(e.deltaY) > Math.abs(e.deltaX)) { tlScroll.scrollLeft += e.deltaY; }
  }, { passive: false });
  tlScroll.addEventListener('scroll', drawRuler);
  // pinch to zoom (touch)
  let pinch = null;
  tlScroll.addEventListener('touchstart', (e) => {
    if (e.touches.length !== 2) return;
    const [a, b] = e.touches;
    const cx = (a.clientX + b.clientX) / 2 - tlInner.getBoundingClientRect().left;
    pinch = { d: Math.hypot(a.clientX - b.clientX, a.clientY - b.clientY), z: zoom, t: tOf(cx) };
  }, { passive: true });
  tlScroll.addEventListener('touchmove', (e) => {
    if (!pinch || e.touches.length !== 2) return;
    e.preventDefault();
    const [a, b] = e.touches;
    const d = Math.hypot(a.clientX - b.clientX, a.clientY - b.clientY);
    setZoom(pinch.z * (d / pinch.d), pinch.t);
  }, { passive: false });
  tlScroll.addEventListener('touchend', () => { pinch = null; });
  $('zoom').addEventListener('input', (e) => setZoom(+e.target.value, tOf(tlScroll.scrollLeft + tlScroll.clientWidth / 2)));
  $('btnFit').addEventListener('click', () => setZoom(1));
  window.addEventListener('resize', () => { layoutTimeline(); layoutStage(); });
  window.addEventListener('orientationchange', () => setTimeout(() => { layoutTimeline(); layoutStage(); }, 250));

  /* ---------- transport / keyboard ---------- */
  $('btnPlay').addEventListener('click', () => playing ? pause() : play());
  $('btnStart').addEventListener('click', () => { pause(); seek(0); });
  $('btnEnd').addEventListener('click', () => { pause(); seek(DUR); });
  $('btnPrevFrame').addEventListener('click', () => stepFrames(-1));
  $('btnNextFrame').addEventListener('click', () => stepFrames(1));
  $('btnSplit').addEventListener('click', () => split(playhead));
  $('btnSplit2').addEventListener('click', () => split(playhead));
  $('btnToggleSeg').addEventListener('click', () => toggleSeg(state.selected));
  $('btnDelete').addEventListener('click', () => toggleSeg(state.selected));
  $('btnMarkIn').addEventListener('click', markIn); $('btnMarkOut').addEventListener('click', markOut);
  $('btnKeepMark').addEventListener('click', () => applyMarks('keep'));
  $('btnCutMark').addEventListener('click', () => applyMarks('cut'));
  $('btnMarkAll').addEventListener('click', markAll);
  $('btnClearMark').addEventListener('click', () => { state.markIn = state.markOut = null; renderMarks(); });
  $('btnUndo').addEventListener('click', undo); $('btnRedo').addEventListener('click', redo);
  /** In at the very start, Out at the very end. */
  function markAll() { state.markIn = 0; state.markOut = DUR; renderMarks(); flash('전체 구간을 선택했습니다.'); }
  function markIn() { state.markIn = snapFrame(playhead); if (state.markOut !== null && state.markOut <= state.markIn) state.markOut = null; renderMarks(); }
  function markOut() { state.markOut = snapFrame(playhead); if (state.markIn !== null && state.markIn >= state.markOut) state.markIn = null; renderMarks(); }
  $('tcCurrent').addEventListener('keydown', (e) => { if (e.key === 'Enter') { const t = parseTc(e.target.value); if (t !== null) { pause(); seek(snapFrame(t)); } e.target.blur(); } if (e.key === 'Escape') e.target.blur(); });
  $('tcCurrent').addEventListener('blur', () => renderPlayhead());
  $('markIn').addEventListener('change', (e) => { const t = parseTc(e.target.value); if (t !== null) { state.markIn = snapFrame(t); } renderMarks(); });
  $('markOut').addEventListener('change', (e) => { const t = parseTc(e.target.value); if (t !== null) { state.markOut = snapFrame(t); } renderMarks(); });
  $('segStart').addEventListener('change', (e) => { const t = parseTc(e.target.value); const i = state.selected; if (t !== null && i > 0) { commit(); moveBoundary(i - 1, t); } else syncSegFields(); });
  $('segEnd').addEventListener('change', (e) => { const t = parseTc(e.target.value); const i = state.selected; if (t !== null && i < state.segments.length - 1) { commit(); moveBoundary(i, t); } else syncSegFields(); });

  document.addEventListener('keydown', (e) => {
    const tag = (e.target.tagName || '').toLowerCase();
    if (tag === 'input' || tag === 'select' || tag === 'textarea') return;
    const k = e.key;
    if ((e.ctrlKey || e.metaKey) && k.toLowerCase() === 'z') { e.preventDefault(); e.shiftKey ? redo() : undo(); return; }
    if ((e.ctrlKey || e.metaKey) && k.toLowerCase() === 'y') { e.preventDefault(); redo(); return; }
    if ((e.ctrlKey || e.metaKey) && k.toLowerCase() === 's') { e.preventDefault(); submit(); return; }
    if ((e.ctrlKey || e.metaKey) && k.toLowerCase() === 'a') { e.preventDefault(); markAll(); return; }
    switch (k) {
      case ' ': e.preventDefault(); playing ? pause() : play(); break;
      case 'ArrowLeft': e.preventDefault(); e.shiftKey ? (pause(), seek(snapFrame(playhead - 1))) : stepFrames(-1); break;
      case 'ArrowRight': e.preventDefault(); e.shiftKey ? (pause(), seek(snapFrame(playhead + 1))) : stepFrames(1); break;
      case 'Home': e.preventDefault(); pause(); seek(0); break;
      case 'End': e.preventDefault(); pause(); seek(DUR); break;
      case 'i': case 'I': markIn(); break;
      case 'o': case 'O': markOut(); break;
      case 's': case 'S': split(playhead); break;
      case 'Delete': case 'Backspace': e.preventDefault();
        if (state.selectedMask >= 0 && activeTab() === 'screen') removeMask(state.selectedMask);
        else if (activeTab() === 'subtitle' && curSub() && curSub().cues[state.subCue]) removeCue(state.subLayer, state.subCue);
        else toggleSeg(state.selected); break;
      case 'j': case 'J': shuttle(-1); break;
      case 'k': case 'K': shuttle(0); break;
      case 'l': case 'L': shuttle(1); break;
      case '[': { pause(); const prev = state.segments.map(s => s.start).filter(t => t < playhead - 0.001).pop(); seek(prev ?? 0); break; }
      case ']': { pause(); const next = state.segments.map(s => s.end).find(t => t > playhead + 0.001); seek(next ?? DUR); break; }
      case '=': case '+': setZoom(zoom * 1.25, playhead); break;
      case '-': setZoom(zoom / 1.25, playhead); break;
      case 'Escape': state.markIn = state.markOut = null; renderMarks(); break;
      default: return;
    }
  });

  /* ---------- tabs ---------- */
  const activeTab = () => document.querySelector('.tab.active').dataset.tab;
  const inspector = document.querySelector('.ed-inspector');
  const isSheet = () => matchMedia('(max-width: 900px)').matches;
  $('tabs').addEventListener('click', (e) => {
    const b = e.target.closest('.tab'); if (!b) return;
    const wasActive = b.classList.contains('active');
    document.querySelectorAll('.tab').forEach(t => t.classList.toggle('active', t === b));
    document.querySelectorAll('.panel').forEach(p => p.classList.toggle('active', p.dataset.panel === b.dataset.tab));
    if (isSheet()) inspector.classList.toggle('open', !(wasActive && inspector.classList.contains('open')));
    renderOverlay();
  });
  function openSheet() { if (isSheet()) inspector.classList.add('open'); }

  /* ---------- stage / crop / masks ---------- */
  const stage = $('stage'), overlay = $('overlay'), cropRect = $('cropRect'), maskLayer = $('maskLayer');
  let scale = 1; // stage px per source px
  function layoutStage() {
    const wrap = $('previewWrap');
    const cs = getComputedStyle(wrap);
    const aw = wrap.clientWidth - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight);
    const ah = wrap.clientHeight - parseFloat(cs.paddingTop) - parseFloat(cs.paddingBottom);
    if (aw <= 0 || ah <= 0) return;
    const ar = D.width / D.height; let w = aw, h = w / ar; if (h > ah) { h = ah; w = h * ar; }
    stage.style.width = w + 'px'; stage.style.height = h + 'px'; video.style.width = w + 'px'; video.style.height = h + 'px';
    scale = w / D.width; renderOverlay();
  }
  function placeRect(el, r) { el.style.left = r.x * scale + 'px'; el.style.top = r.y * scale + 'px'; el.style.width = r.w * scale + 'px'; el.style.height = r.h * scale + 'px'; }
  function renderOverlay() {
    const screenTab = activeTab() === 'screen';
    overlay.classList.toggle('active', screenTab || activeTab() === 'watermark');
    cropRect.hidden = !state.crop; if (state.crop) placeRect(cropRect, state.crop);
    cropRect.style.pointerEvents = screenTab ? 'auto' : 'none';
    maskLayer.innerHTML = '';
    state.masks.forEach((m, i) => {
      if (m.style === 'track') {
        const dot = document.createElement('div');
        dot.className = 'trackdot';
        dot.style.left = m.x * scale + 'px'; dot.style.top = m.y * scale + 'px';
        dot.title = '대상 추적 지우기';
        maskLayer.appendChild(dot);
        return;
      }
      const el = document.createElement('div'); el.className = 'rect mask ' + m.style + (i === state.selectedMask ? ' selected' : '');
      el.innerHTML = '<div class="rect-label">' + ({ blur: 'BLUR', fill: 'FILL', ai: 'AI' }[m.style] || 'BLACK') + '</div>' + (i === state.selectedMask ? '<i data-h="nw"></i><i data-h="n"></i><i data-h="ne"></i><i data-h="e"></i><i data-h="se"></i><i data-h="s"></i><i data-h="sw"></i><i data-h="w"></i>' : '');
      el.dataset.i = i; placeRect(el, m); el.style.pointerEvents = screenTab ? 'auto' : 'none'; maskLayer.appendChild(el);
    });
    renderWatermark(); renderSubs();
    syncCropFields(); renderMaskList();
  }

  /* ---------- subtitles ---------- */
  const subEls = [$('subPreview0'), $('subPreview1')];
  // preview of the server side designs; keys match EditParams::TEMPLATES
  const TPL = {
    outline:   { stroke: 0.06 },
    heavy:     { stroke: 0.13 },
    plain:     { shadow: 0.05 },
    box:       { box: 'rgba(0,0,0,.55)' },
    blackbox:  { box: '#000000' },
    whitebox:  { color: '#111111', box: '#ffffff' },
    grayline:  { stroke: 0.05, strokeColor: '#8a8a8a' },
    highlight: { color: '#ffd60a', stroke: 0.06 },
    glow:      { stroke: 0.13, glow: 0.20 },
    softglow:  { glow: 0.28 },
  };
  function defaultSub() {
    const b = wmBox();
    return { template: 'outline', font: Object.keys(window.FONTS)[0] || 'pretendard',
      size: Math.max(16, Math.round(b.h * 0.045)), color: '#ffffff',
      anchor: 's', x: Math.round(b.w / 2), y: Math.round(b.h * 0.88), cues: [] };
  }
  const curSub = () => state.subtitles[state.subLayer];
  function cueAt(sub, t) {
    if (!sub) return null;
    return sub.cues.find(c => t >= c.start && t <= c.end) || null;
  }
  function renderSubs() {
    const t = playhead;
    state.subtitles.forEach((sub, i) => {
      const el = subEls[i], span = el.firstElementChild;
      const editing = activeTab() === 'subtitle' && i === state.subLayer;
      // the preview follows the playhead, so what is on screen is what the cue block says
      const cue = sub ? cueAt(sub, t) : null;
      el.hidden = !sub || !cue;
      if (!sub || !cue) return;
      ensureFont(sub.font);
      const b = wmBox(), tpl = TPL[sub.template] || TPL.outline;
      span.textContent = cue.text;
      el.style.left = (b.x + sub.x) * scale + 'px';
      el.style.top = (b.y + sub.y) * scale + 'px';
      const tx = sub.anchor.includes('e') ? '-100%' : (['n', 's', 'c'].includes(sub.anchor) ? '-50%' : '0');
      const ty = sub.anchor.startsWith('s') ? '-100%' : (['w', 'e', 'c'].includes(sub.anchor) ? '-50%' : '0');
      el.style.transform = 'translate(' + tx + ',' + ty + ')';
      el.style.fontFamily = '"wm-' + sub.font + '", sans-serif';
      el.style.fontSize = (sub.size * scale) + 'px';
      el.style.color = tpl.color || sub.color;
      el.style.pointerEvents = editing ? 'auto' : 'none';
      el.classList.toggle('sel', editing);
      span.style.cssText = '';
      if (tpl.stroke) { span.style.webkitTextStroke = Math.max(1, sub.size * scale * tpl.stroke) + 'px ' + (tpl.strokeColor || '#000'); span.style.paintOrder = 'stroke fill'; }
      if (tpl.shadow) { const sw = Math.max(1, sub.size * scale * tpl.shadow); span.style.textShadow = sw + 'px ' + sw + 'px 0 rgba(0,0,0,.7)'; }
      if (tpl.glow) { const g = Math.max(2, sub.size * scale * tpl.glow), c = tpl.color || sub.color;
        span.style.textShadow = '0 0 ' + g + 'px ' + c + ', 0 0 ' + (g / 2) + 'px ' + c; }
      if (tpl.box) { span.style.background = tpl.box; span.style.padding = Math.max(2, sub.size * scale * 0.18) + 'px ' + Math.max(3, sub.size * scale * 0.3) + 'px'; }
    });
    syncSubFields(); renderCueTrack();
  }
  function syncSubFields() {
    const sub = curSub();
    document.querySelectorAll('#subLayerTabs button').forEach(b => b.classList.toggle('active', +b.dataset.layer === state.subLayer));
    $('subOn').checked = !!sub;
    ['subTemplate', 'subFont', 'subSize', 'subColor', 'subPos', 'subX', 'subY'].forEach(id => {
      const el = $(id); if (el) el.classList.toggle('off', !sub);
    });
    ['subFont', 'subSize', 'subColor', 'subX', 'subY', 'btnCueAdd', 'btnCueClear'].forEach(id => $(id).disabled = !sub);
    if (!sub) { $('cueList').innerHTML = ''; $('cueFields').hidden = true; $('cueTextField').hidden = true; return; }
    document.querySelectorAll('#subTemplate button').forEach(b => b.classList.toggle('active', b.dataset.t === sub.template));
    document.querySelectorAll('#subPos button').forEach(b => b.classList.toggle('active', b.dataset.a === sub.anchor));
    $('subFont').value = sub.font; $('subSize').value = sub.size; $('subColor').value = sub.color;
    $('subX').value = Math.round(sub.x); $('subY').value = Math.round(sub.y);
    renderCueList();
  }
  function renderCueList() {
    const sub = curSub(), list = $('cueList'); list.innerHTML = '';
    if (!sub) return;
    sub.cues.forEach((c, i) => {
      const el = document.createElement('div');
      el.className = 'cueitem' + (i === state.subCue ? ' selected' : '');
      el.innerHTML = `<span class="t">${tc(c.start, false)}</span><span class="x2">${escHtml(c.text)}</span><span class="x" title="삭제">&#x2715;</span>`;
      el.addEventListener('click', (e) => {
        if (e.target.classList.contains('x')) { commit(); sub.cues.splice(i, 1); state.subCue = -1; renderSubs(); return; }
        state.subCue = i; video.currentTime = c.start; renderSubs();
      });
      list.appendChild(el);
    });
    const c = sub.cues[state.subCue];
    $('cueFields').hidden = !c; $('cueTextField').hidden = !c;
    if (c) {
      if (document.activeElement !== $('cueStart')) $('cueStart').value = tc(c.start, false);
      if (document.activeElement !== $('cueEnd')) $('cueEnd').value = tc(c.end, false);
      if (document.activeElement !== $('cueText')) $('cueText').value = c.text;
    }
  }
  /* ---------- subtitle tracks on the timeline ---------- */
  const subTracks = [$('subTrack0'), $('subTrack1')], cueLayers = [$('cues0'), $('cues1')];
  const MIN_CUE = 0.1;
  let cueSig = '';
  // rebuilding runs on every frame through renderSubs(), so skip it when nothing moved
  function renderCueTrack(force) {
    const sig = JSON.stringify([state.subtitles.map(s => s && s.cues.map(c => [c.start, c.end, c.text])),
                                state.subLayer, state.subCue, Math.round(trackWidth())]);
    if (!force && sig === cueSig) return;
    cueSig = sig;
    state.subtitles.forEach((sub, li) => {
      subTracks[li].classList.toggle('nolayer', !sub);
      const layer = cueLayers[li]; layer.innerHTML = '';
      if (!sub) return;
      sub.cues.forEach((c, ci) => {
        const el = document.createElement('div');
        el.className = 'cue' + (li === state.subLayer && ci === state.subCue ? ' selected' : '');
        el.style.left = xOf(c.start) + 'px';
        el.style.width = Math.max(4, xOf(c.end) - xOf(c.start)) + 'px';
        el.dataset.i = ci;
        el.innerHTML = '<span></span><i data-h="s"></i><i data-h="e"></i>';
        el.firstChild.textContent = c.text;
        layer.appendChild(el);
      });
    });
  }
  function removeCue(li, ci) {
    const sub = state.subtitles[li]; if (!sub || !sub.cues[ci]) return;
    commit(); sub.cues.splice(ci, 1); state.subCue = -1; renderSubs(); renderCueTrack(true);
  }
  function selectCue(li, ci) {
    state.subLayer = li; state.subCue = ci;
    const tab = document.querySelector('.tab[data-tab=subtitle]');
    if (tab && activeTab() !== 'subtitle') tab.click();
    // move into the cue, otherwise its styling would not be on screen to look at
    const c = state.subtitles[li] && state.subtitles[li].cues[ci];
    if (c && (playhead < c.start || playhead > c.end)) { pause(); seek(c.start); }
    renderSubs(); renderCueTrack(true);
  }
  function addCueAt(li, t) {
    const at = clamp(t, 0, DUR - MIN_CUE);
    const cur = state.subtitles[li];
    const hit = cur ? cur.cues.findIndex(c => at >= c.start && at <= c.end) : -1;
    if (hit >= 0) { selectCue(li, hit); return; }   // the spot is taken: just select what is there
    commit();
    if (!state.subtitles[li]) state.subtitles[li] = defaultSub();
    const sub = state.subtitles[li];
    let end = Math.min(DUR, at + 2);
    sub.cues.forEach(c => { if (c.start > at && c.start < end) end = c.start; });
    if (end - at < MIN_CUE) { flash('자막을 넣을 자리가 좁습니다.'); return; }
    sub.cues.push({ start: round3(at), end: round3(end), text: '자막' });
    sub.cues.sort((a, b) => a.start - b.start);
    selectCue(li, sub.cues.findIndex(c => Math.abs(c.start - at) < 1e-6));
    $('cueText').focus(); $('cueText').select();
  }
  function startCueDrag(li, ci, mode, e) {
    e.preventDefault(); e.stopPropagation(); pause(); commit();
    const sub = state.subtitles[li], c = sub.cues[ci];
    const t0 = tOf(localX(e)), s0 = c.start, e0 = c.end;
    // a cue may not run into its neighbours on the same layer
    const lo = ci > 0 ? sub.cues[ci - 1].end : 0;
    const hi = ci + 1 < sub.cues.length ? sub.cues[ci + 1].start : DUR;
    const move = (ev) => {
      const d = tOf(clamp(localX(ev), 0, trackWidth())) - t0;
      if (mode === 'move') {
        let st = s0 + d;
        if (!ev.altKey) st = snapTime(st, -1);
        st = clamp(st, lo, hi - (e0 - s0));
        c.start = round3(st); c.end = round3(st + (e0 - s0));
      } else if (mode === 's') {
        let t = ev.altKey ? s0 + d : snapTime(s0 + d, -1);
        c.start = round3(clamp(t, lo, e0 - MIN_CUE));
      } else {
        let t = ev.altKey ? e0 + d : snapTime(e0 + d, -1);
        c.end = round3(clamp(t, s0 + MIN_CUE, hi));
      }
      renderCueTrack(true); renderSubs();
    };
    const up = () => {
      window.removeEventListener('pointermove', move); window.removeEventListener('pointerup', up);
      renderCueTrack(true); renderSubs();
    };
    window.addEventListener('pointermove', move); window.addEventListener('pointerup', up);
  }
  subTracks.forEach((tr, li) => {
    tr.addEventListener('pointerdown', (e) => {
      if (e.button !== 0) return;
      const cue = e.target.closest('.cue');
      if (!cue) { startScrub(e); return; }
      const ci = +cue.dataset.i;
      selectCue(li, ci);
      startCueDrag(li, ci, e.target.dataset.h || 'move', e);
    });
    tr.addEventListener('dblclick', (e) => {
      if (e.target.closest('.cue')) return;
      addCueAt(li, tOf(clamp(localX(e), 0, trackWidth())));
    });
  });

  $('subLayerTabs').addEventListener('click', (e) => {
    const b = e.target.closest('button'); if (!b) return;
    state.subLayer = +b.dataset.layer; state.subCue = -1; renderSubs();
  });
  $('subOn').addEventListener('change', (e) => {
    commit();
    state.subtitles[state.subLayer] = e.target.checked ? (curSub() || defaultSub()) : null;
    state.subCue = -1; renderSubs();
  });
  $('subTemplate').addEventListener('click', (e) => {
    const b = e.target.closest('button'); if (!b || !curSub()) return;
    commit(); curSub().template = b.dataset.t; renderSubs();
  });
  $('subPos').addEventListener('click', (e) => {
    const b = e.target.closest('button'); if (!b || !curSub()) return;
    commit();
    const sub = curSub(), box = wmBox(), pad = Math.round(Math.min(box.w, box.h) * 0.06);
    sub.anchor = b.dataset.a;
    sub.x = sub.anchor.includes('w') ? pad : (sub.anchor.includes('e') ? box.w - pad : Math.round(box.w / 2));
    sub.y = sub.anchor.startsWith('n') ? pad : (sub.anchor.startsWith('s') ? box.h - pad : Math.round(box.h / 2));
    renderSubs();
  });
  $('subFont').addEventListener('change', (e) => { if (!curSub()) return; commit(); curSub().font = e.target.value; renderSubs(); });
  $('subSize').addEventListener('input', (e) => { if (!curSub()) return; curSub().size = clamp(+e.target.value || 20, 8, 400); renderSubs(); });
  $('subColor').addEventListener('input', (e) => { if (!curSub()) return; curSub().color = e.target.value; renderSubs(); });
  ['subX', 'subY'].forEach(id => $(id).addEventListener('change', () => {
    const sub = curSub(); if (!sub) return; commit();
    sub.x = Math.round(+$('subX').value) || 0; sub.y = Math.round(+$('subY').value) || 0; renderSubs();
  }));
  $('btnCueAdd').addEventListener('click', () => {
    const sub = curSub(); if (!sub) return; commit();
    const t = video.currentTime || 0;
    sub.cues.push({ start: +t.toFixed(3), end: +Math.min(D.duration, t + 2).toFixed(3), text: '자막' });
    sub.cues.sort((a, b) => a.start - b.start);
    state.subCue = sub.cues.findIndex(c => Math.abs(c.start - t) < 0.001);
    renderSubs(); $('cueText').focus(); $('cueText').select();
  });
  $('btnCueClear').addEventListener('click', () => {
    const sub = curSub(); if (!sub || !sub.cues.length) return;
    commit(); sub.cues = []; state.subCue = -1; renderSubs();
  });
  $('cueText').addEventListener('input', (e) => {
    const sub = curSub(), c = sub && sub.cues[state.subCue]; if (!c) return;
    c.text = e.target.value; renderSubs();
  });
  ['cueStart', 'cueEnd'].forEach(id => $(id).addEventListener('change', () => {
    const sub = curSub(), c = sub && sub.cues[state.subCue]; if (!c) return; commit();
    const s = parseTc($('cueStart').value), e2 = parseTc($('cueEnd').value);
    if (s !== null) c.start = clamp(s, 0, D.duration);
    if (e2 !== null) c.end = clamp(e2, c.start + 0.1, D.duration);
    sub.cues.sort((a, b) => a.start - b.start);
    state.subCue = sub.cues.indexOf(c);
    renderSubs();
  }));

  /* ---------- watermark ---------- */
  const wmEl = $('wmPreview'), wmText = $('wmPreviewText');
  const wmBox = () => state.crop || { x: 0, y: 0, w: D.width, h: D.height };
  function defaultWatermark() {
    const b = wmBox(), pad = Math.round(Math.min(b.w, b.h) * 0.04);
    return { text: '', font: Object.keys(window.FONTS)[0] || 'pretendard', size: Math.max(12, Math.round(b.h * 0.05)),
      color: '#ffffff', opacity: 0.85, x: b.w - pad, y: b.h - pad, anchor: 'se', style: 'shadow' };
  }
  const loadedFonts = new Set();
  function ensureFont(key) {
    if (loadedFonts.has(key)) return;
    loadedFonts.add(key);
    const st = document.createElement('style');
    st.textContent = '@font-face{font-family:"wm-' + key + '";src:url("' + window.FONT_URL + key + '");font-display:swap}';
    document.head.appendChild(st);
  }
  function renderWatermark() {
    const w = state.watermark;
    wmEl.hidden = !w || !w.text;
    if (!w) return;
    ensureFont(w.font);
    const b = wmBox();
    wmText.textContent = w.text;
    wmEl.style.left = (b.x + w.x) * scale + 'px';
    wmEl.style.top = (b.y + w.y) * scale + 'px';
    const tx = w.anchor.includes('e') || ['n', 's', 'c'].includes(w.anchor) ? (w.anchor.includes('e') ? '-100%' : '-50%') : '0';
    const ty = w.anchor.startsWith('s') ? '-100%' : (['w', 'e', 'c'].includes(w.anchor) ? '-50%' : '0');
    wmEl.style.transform = 'translate(' + tx + ',' + ty + ')';
    wmEl.style.fontFamily = '"wm-' + w.font + '", sans-serif';
    wmEl.style.fontSize = (w.size * scale) + 'px';
    wmEl.style.color = w.color;
    wmEl.style.opacity = w.opacity;
    wmEl.style.pointerEvents = activeTab() === 'watermark' ? 'auto' : 'none';
    wmEl.classList.toggle('sel', activeTab() === 'watermark');
    const sw = Math.max(1, w.size * scale * 0.05);
    wmText.style.cssText = '';
    if (w.style === 'shadow') wmText.style.textShadow = sw + 'px ' + sw + 'px 0 rgba(0,0,0,.7)';
    else if (w.style === 'outline') { wmText.style.webkitTextStroke = Math.max(1, w.size * scale * 0.06) + 'px #000'; wmText.style.paintOrder = 'stroke fill'; }
    else if (w.style === 'box') { wmText.style.background = 'rgba(0,0,0,.55)'; wmText.style.padding = Math.max(2, w.size * scale * 0.2) + 'px ' + Math.max(3, w.size * scale * 0.28) + 'px'; }
    syncWmFields();
  }
  function syncWmFields() {
    const w = state.watermark;
    $('wmOn').checked = !!w;
    ['wmText', 'wmFont', 'wmX', 'wmY', 'wmSize', 'wmColor', 'wmOpacity'].forEach(id => $(id).disabled = !w);
    if (!w) return;
    if (document.activeElement !== $('wmText')) $('wmText').value = w.text;
    $('wmFont').value = w.font;
    $('wmX').value = Math.round(w.x); $('wmY').value = Math.round(w.y);
    $('wmSize').value = w.size; $('wmColor').value = w.color;
    $('wmOpacity').value = Math.round(w.opacity * 100);
    $('wmOpacityVal').textContent = Math.round(w.opacity * 100) + '%';
    document.querySelectorAll('#wmPos button').forEach(b => b.classList.toggle('active', b.dataset.a === w.anchor));
    document.querySelectorAll('#wmStyle button').forEach(b => b.classList.toggle('active', b.dataset.s === w.style));
  }
  function anchorPoint(a) {
    const b = wmBox(), pad = Math.round(Math.min(b.w, b.h) * 0.04);
    const x = a.includes('w') ? pad : (a.includes('e') ? b.w - pad : Math.round(b.w / 2));
    const y = a.startsWith('n') ? pad : (a.startsWith('s') ? b.h - pad : Math.round(b.h / 2));
    return { x, y };
  }
  $('wmOn').addEventListener('change', (e) => {
    commit();
    state.watermark = e.target.checked ? defaultWatermark() : null;
    renderOverlay();
    if (e.target.checked) $('wmText').focus();
  });
  $('wmText').addEventListener('input', (e) => { if (!state.watermark) return; state.watermark.text = e.target.value; renderWatermark(); });
  $('wmText').addEventListener('change', () => commit());
  $('wmFont').addEventListener('change', (e) => { if (!state.watermark) return; commit(); state.watermark.font = e.target.value; renderWatermark(); });
  ['wmX', 'wmY', 'wmSize'].forEach(id => $(id).addEventListener('change', () => {
    const w = state.watermark; if (!w) return; commit();
    const b = wmBox();
    w.x = clamp(+$('wmX').value || 0, 0, b.w); w.y = clamp(+$('wmY').value || 0, 0, b.h);
    w.size = clamp(+$('wmSize').value || w.size, 8, 400);
    renderWatermark();
  }));
  $('wmColor').addEventListener('input', (e) => { if (!state.watermark) return; state.watermark.color = e.target.value; renderWatermark(); });
  $('wmColor').addEventListener('change', () => commit());
  $('wmOpacity').addEventListener('input', (e) => { if (!state.watermark) return; state.watermark.opacity = (+e.target.value) / 100; renderWatermark(); });
  $('wmOpacity').addEventListener('change', () => commit());
  $('wmPos').addEventListener('click', (e) => {
    const b = e.target.closest('button'); if (!b || !state.watermark) return;
    commit();
    state.watermark.anchor = b.dataset.a;
    Object.assign(state.watermark, anchorPoint(b.dataset.a));
    renderOverlay();
  });
  $('wmStyle').addEventListener('click', (e) => {
    const b = e.target.closest('button'); if (!b || !state.watermark) return;
    commit(); state.watermark.style = b.dataset.s; renderOverlay();
  });
  wmEl.addEventListener('pointerdown', (e) => {
    const w = state.watermark; if (!w || e.button !== 0) return;
    e.preventDefault(); e.stopPropagation();
    commit();
    const sx = e.clientX, sy = e.clientY, ox = w.x, oy = w.y, b = wmBox();
    const move = (ev) => {
      w.x = clamp(Math.round(ox + (ev.clientX - sx) / scale), 0, b.w);
      w.y = clamp(Math.round(oy + (ev.clientY - sy) / scale), 0, b.h);
      renderWatermark();
    };
    const up = () => { window.removeEventListener('pointermove', move); window.removeEventListener('pointerup', up); };
    window.addEventListener('pointermove', move); window.addEventListener('pointerup', up);
  });
  subEls.forEach((el, i) => el.addEventListener('pointerdown', (e) => {
    const sub = state.subtitles[i];
    if (!sub || e.button !== 0 || activeTab() !== 'subtitle' || i !== state.subLayer) return;
    e.preventDefault(); e.stopPropagation();
    commit();
    const sx = e.clientX, sy = e.clientY, ox = sub.x, oy = sub.y, b = wmBox();
    const move = (ev) => {
      sub.x = clamp(Math.round(ox + (ev.clientX - sx) / scale), 0, b.w);
      sub.y = clamp(Math.round(oy + (ev.clientY - sy) / scale), 0, b.h);
      renderSubs();
    };
    const up = () => { window.removeEventListener('pointermove', move); window.removeEventListener('pointerup', up); };
    window.addEventListener('pointermove', move); window.addEventListener('pointerup', up);
  }));

  function syncCropFields() {
    $('cropOn').checked = !!state.crop;
    const c = state.crop || { x: 0, y: 0, w: D.width, h: D.height };
    $('cropX').value = c.x; $('cropY').value = c.y; $('cropW').value = c.w; $('cropH').value = c.h;
    [ 'cropX', 'cropY', 'cropW', 'cropH' ].forEach(id => $(id).disabled = !state.crop);
    document.querySelectorAll('#cropPresets button').forEach(b => b.classList.toggle('active', b.dataset.ar === state.cropAR));
  }
  const even = (v) => Math.round(v / 2) * 2;
  function normRect(r) {
    r.w = clamp(even(r.w), 2, D.width); r.h = clamp(even(r.h), 2, D.height);
    r.x = clamp(even(r.x), 0, D.width - r.w); r.y = clamp(even(r.y), 0, D.height - r.h);
    return r;
  }
  function arValue(ar) { if (ar === 'src') return D.width / D.height; if (ar === 'free') return null; const [a, b] = ar.split(':').map(Number); return a / b; }
  function applyAR(r, ar, anchor) {
    const v = arValue(ar); if (!v) return normRect(r);
    // keep width, adjust height (or vice versa when it does not fit)
    let w = r.w, h = w / v;
    if (h > D.height) { h = D.height; w = h * v; }
    if (w > D.width) { w = D.width; h = w / v; }
    const cx = anchor ? anchor.x : r.x + r.w / 2, cy = anchor ? anchor.y : r.y + r.h / 2;
    return normRect({ x: cx - w / 2, y: cy - h / 2, w, h });
  }
  function setCropOn(on) {
    commit();
    if (on) { const r = { x: 0, y: 0, w: D.width, h: D.height }; state.crop = applyAR(r, state.cropAR); }
    else state.crop = null;
    renderOverlay();
  }
  $('cropOn').addEventListener('change', (e) => setCropOn(e.target.checked));
  $('cropPresets').addEventListener('click', (e) => {
    const b = e.target.closest('button'); if (!b) return;
    state.cropAR = b.dataset.ar;
    if (!state.crop) { setCropOn(true); $('cropOn').checked = true; }
    else { commit(); state.crop = applyAR(state.crop, state.cropAR); }
    if (state.cropAR !== 'free' && state.crop) { // maximize within frame while keeping ratio
      const v = arValue(state.cropAR); let w = D.width, h = w / v; if (h > D.height) { h = D.height; w = h * v; }
      state.crop = normRect({ x: (D.width - w) / 2, y: (D.height - h) / 2, w, h });
    }
    renderOverlay();
  });
  ['cropX', 'cropY', 'cropW', 'cropH'].forEach(id => $(id).addEventListener('change', () => {
    if (!state.crop) return; commit();
    let r = { x: +$('cropX').value, y: +$('cropY').value, w: +$('cropW').value, h: +$('cropH').value };
    if (state.cropAR !== 'free') { const v = arValue(state.cropAR); if (id === 'cropH') r.w = r.h * v; else r.h = r.w / v; }
    state.crop = normRect(r); renderOverlay();
  }));
  $('btnCropCenter').addEventListener('click', () => { if (!state.crop) return; commit(); state.crop.x = even((D.width - state.crop.w) / 2); state.crop.y = even((D.height - state.crop.h) / 2); renderOverlay(); });
  $('btnCropReset').addEventListener('click', () => { commit(); state.crop = null; state.cropAR = 'free'; renderOverlay(); });

  function addMask(style) {
    commit();
    const w = even(D.width / 4), h = even(D.height / 4);
    state.masks.push(normRect({ x: (D.width - w) / 2, y: (D.height - h) / 2, w, h, style }));
    state.selectedMask = state.masks.length - 1; renderOverlay();
  }
  function removeMask(i) { commit(); state.masks.splice(i, 1); state.selectedMask = -1; renderOverlay(); }
  $('btnMaskBlack').addEventListener('click', () => addMask('black'));
  $('btnMaskBlur').addEventListener('click', () => addMask('blur'));
  $('eraseQuality').addEventListener('change', (e) => state.erase.quality = e.target.value);
  $('btnMaskFill').addEventListener('click', () => addMask('fill'));
  $('btnMaskAi').addEventListener('click', () => addMask('ai'));
  let pickTrack = false;
  const trackHint = (msg) => { const el = $('trackHint'); el.textContent = msg; el.hidden = !msg; };
  $('btnMaskTrack').addEventListener('click', () => {
    pickTrack = !pickTrack;
    $('btnMaskTrack').classList.toggle('active', pickTrack);
    overlay.classList.toggle('picking', pickTrack);
    trackHint(pickTrack ? '지울 대상을 미리보기에서 클릭하세요. 지금 보이는 프레임이 기준입니다.' : '');
  });
  overlay.addEventListener('click', (e) => {
    if (!pickTrack) return;
    e.preventDefault(); e.stopPropagation();
    const r = overlay.getBoundingClientRect();
    commit();
    state.masks.push({
      style: 'track',
      x: Math.round((e.clientX - r.left) / scale),
      y: Math.round((e.clientY - r.top) / scale),
      at: +(video.currentTime || 0).toFixed(3),
    });
    pickTrack = false;
    $('btnMaskTrack').classList.remove('active');
    overlay.classList.remove('picking');
    trackHint('');
    state.selectedMask = -1; renderOverlay();
  }, true);
  const MASK_LABEL = { black: '검정', blur: '블러', fill: '배경 채우기', ai: 'AI 지우기', track: '대상 추적' };
  function renderMaskList() {
    const list = $('maskList'); list.innerHTML = '';
    state.masks.forEach((m, i) => {
      const el = document.createElement('div'); el.className = 'maskitem' + (i === state.selectedMask ? ' selected' : '');
      const desc = m.style === 'track'
        ? `${m.x},${m.y} · ${MV.fmtDur(m.at)} 지점`
        : `${m.w}x${m.h} @ ${m.x},${m.y}`;
      el.innerHTML = `<span>${MASK_LABEL[m.style] || m.style} ${desc}</span><span class="x" title="삭제">&#x2715;</span>`;
      el.addEventListener('click', (e) => { if (e.target.classList.contains('x')) removeMask(i); else { state.selectedMask = i; renderOverlay(); } });
      list.appendChild(el);
    });
    const f = $('maskFields');
    f.hidden = state.selectedMask < 0 || state.masks[state.selectedMask]?.style === 'track';
    if (state.selectedMask >= 0) { const m = state.masks[state.selectedMask]; $('maskX').value = m.x; $('maskY').value = m.y; $('maskW').value = m.w; $('maskH').value = m.h; }
  }
  ['maskX', 'maskY', 'maskW', 'maskH'].forEach(id => $(id).addEventListener('change', () => {
    const i = state.selectedMask; if (i < 0 || state.masks[i].style === 'track') return; commit();
    const m = state.masks[i]; state.masks[i] = normRect({ x: +$('maskX').value, y: +$('maskY').value, w: +$('maskW').value, h: +$('maskH').value, style: m.style }); renderOverlay();
  }));

  // rect drag / resize (crop and masks)
  overlay.addEventListener('pointerdown', (e) => {
    const rectEl = e.target.closest('.rect'); if (!rectEl || e.button !== 0) return;
    e.preventDefault(); e.stopPropagation();
    const isCrop = rectEl.classList.contains('crop');
    const idx = isCrop ? -1 : +rectEl.dataset.i;
    if (!isCrop && state.selectedMask !== idx) { state.selectedMask = idx; renderOverlay(); }
    const handle = e.target.dataset.h || null;
    const get = () => isCrop ? state.crop : state.masks[idx];
    const start = { ...get() }; const sx = e.clientX, sy = e.clientY;
    const ar = isCrop ? arValue(state.cropAR) : null;
    commit();
    const move = (ev) => {
      const dx = (ev.clientX - sx) / scale, dy = (ev.clientY - sy) / scale;
      let r = { ...start };
      if (!handle) { r.x = start.x + dx; r.y = start.y + dy; r.w = start.w; r.h = start.h; r.x = clamp(r.x, 0, D.width - r.w); r.y = clamp(r.y, 0, D.height - r.h); }
      else {
        if (handle.includes('e')) r.w = start.w + dx;
        if (handle.includes('s')) r.h = start.h + dy;
        if (handle.includes('w')) { r.x = start.x + dx; r.w = start.w - dx; }
        if (handle.includes('n')) { r.y = start.y + dy; r.h = start.h - dy; }
        r.w = Math.max(16, r.w); r.h = Math.max(16, r.h);
        if (ar) {
          if (handle === 'n' || handle === 's') r.w = r.h * ar; else r.h = r.w / ar;
          if (handle.includes('w')) r.x = start.x + start.w - r.w;
          if (handle.includes('n')) r.y = start.y + start.h - r.h;
        }
        if (r.x < 0) { if (ar) { r.w += r.x; r.h = r.w / ar; } else r.w += r.x; r.x = 0; }
        if (r.y < 0) { if (ar) { r.h += r.y; r.w = r.h * ar; } else r.h += r.y; r.y = 0; }
        if (r.x + r.w > D.width) { r.w = D.width - r.x; if (ar) r.h = r.w / ar; }
        if (r.y + r.h > D.height) { r.h = D.height - r.y; if (ar) r.w = r.h * ar; }
      }
      r = normRect(r); if (!isCrop) r.style = start.style;
      if (isCrop) state.crop = r; else state.masks[idx] = r;
      if (isCrop) placeRect(cropRect, r); else placeRect(rectEl, r);
      syncCropFields(); if (!isCrop) renderMaskList();
    };
    const up = () => { window.removeEventListener('pointermove', move); window.removeEventListener('pointerup', up); renderOverlay(); };
    window.addEventListener('pointermove', move); window.addEventListener('pointerup', up);
  });

  /* ---------- output / speed ---------- */
  $('speedPresets').addEventListener('click', (e) => { const b = e.target.closest('button'); if (!b) return; state.speed = +b.dataset.speed; document.querySelectorAll('#speedPresets button').forEach(x => x.classList.toggle('active', x === b)); renderSegList(); });
  $('keepAudio').addEventListener('change', (e) => state.keepAudio = e.target.checked);
  $('sharpenPresets').addEventListener('click', (e) => {
    const b = e.target.closest('button'); if (!b) return;
    state.enhance.sharpen = b.dataset.sharpen;
    document.querySelectorAll('#sharpenPresets button').forEach(x => x.classList.toggle('active', x === b));
    $('denoise').disabled = state.enhance.sharpen === 'off';
    document.querySelectorAll('#smoothPresets button').forEach(x => x.classList.toggle('active', x.dataset.smooth === state.smooth));
    document.querySelectorAll('#restorePresets button').forEach(x => x.classList.toggle('active', x.dataset.restore === state.restore.mode));
    document.querySelectorAll('#expandPresets button').forEach(x => x.classList.toggle('active', +x.dataset.w === state.expand.w && +x.dataset.h === state.expand.h));
    $('eraseQuality').value = state.erase.quality;
    $('restoreModel').value = state.restore.model;
    $('restoreModelRow').hidden = state.restore.mode === 'off';
  });
  $('denoise').addEventListener('change', (e) => state.enhance.denoise = e.target.checked);
  $('restorePresets').addEventListener('click', (e) => {
    const b = e.target.closest('button'); if (!b) return;
    state.restore.mode = b.dataset.restore;
    document.querySelectorAll('#restorePresets button').forEach(x => x.classList.toggle('active', x === b));
    $('restoreModelRow').hidden = state.restore.mode === 'off';
  });
  $('restoreModel').addEventListener('change', (e) => state.restore.model = e.target.value);
  $('expandPresets').addEventListener('click', (e) => {
    const b = e.target.closest('button'); if (!b) return;
    state.expand = { w: +b.dataset.w, h: +b.dataset.h };
    document.querySelectorAll('#expandPresets button').forEach(x => x.classList.toggle('active', x === b));
  });
  $('smoothPresets').addEventListener('click', (e) => {
    const b = e.target.closest('button'); if (!b) return;
    state.smooth = b.dataset.smooth;
    document.querySelectorAll('#smoothPresets button').forEach(x => x.classList.toggle('active', x === b));
  });
  $('outFormat').addEventListener('change', (e) => { state.output.format = e.target.value; $('keepAudio').disabled = e.target.value === 'gif'; });
  $('outHeight').addEventListener('change', (e) => state.output.height = +e.target.value);
  $('outQuality').addEventListener('change', (e) => state.output.quality = e.target.value);
  function syncOutputFields() {
    document.querySelectorAll('#speedPresets button').forEach(x => x.classList.toggle('active', +x.dataset.speed === state.speed));
    $('keepAudio').checked = state.keepAudio; $('outFormat').value = state.output.format; $('outHeight').value = String(state.output.height); $('outQuality').value = state.output.quality;
    $('keepAudio').disabled = !D.hasAudio || state.output.format === 'gif';
    document.querySelectorAll('#sharpenPresets button').forEach(x => x.classList.toggle('active', x.dataset.sharpen === state.enhance.sharpen));
    $('denoise').checked = state.enhance.denoise;
    $('denoise').disabled = state.enhance.sharpen === 'off';
  }

  /* ---------- submit / job polling ---------- */
  let pollTimer = 0;
  function params() { return { keep: keepList(), crop: state.crop, masks: state.masks, watermark: state.watermark, speed: state.speed, enhance: state.enhance, subtitles: state.subtitles.filter(Boolean), smooth: state.smooth, restore: state.restore, expand: state.expand, erase: state.erase, keepAudio: state.keepAudio, output: state.output }; }
  async function submit() {
    const csrf = MV.csrf();
    const box = $('jobBox'); box.hidden = false; box.classList.remove('done'); $('jobLinks').hidden = true; $('jobError').hidden = true;
    $('jobStatus').textContent = '요청 중...'; $('jobPct').textContent = ''; $('jobBar').style.width = '0%';
    $('btnSave').disabled = $('btnSave2').disabled = true;
    const outTab = document.querySelector('.tab[data-tab=output]');
    document.querySelectorAll('.tab').forEach(t => t.classList.toggle('active', t === outTab));
    document.querySelectorAll('.panel').forEach(p => p.classList.toggle('active', p.dataset.panel === 'output'));
    openSheet(); renderOverlay();
    try {
      const res = await fetch(D.submitUrl, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf ? csrf.hash : '' }, body: JSON.stringify(params()) });
      const j = await res.json();
      if (!res.ok || !j.ok) throw new Error(j.error || ('HTTP ' + res.status));
      poll(j.job.id);
    } catch (err) { showJobError(err.message); }
  }
  function poll(id) {
    clearTimeout(pollTimer);
    pollTimer = setTimeout(async () => {
      try {
        const res = await fetch(D.jobsUrl + '/' + id, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const j = (await res.json()).job;
        const label = { queued: '대기 중 (워커 대기)', running: '처리 중', done: '완료', failed: '실패' }[j.status] || j.status;
        $('jobStatus').textContent = label; $('jobPct').textContent = j.progress + '%'; $('jobBar').style.width = j.progress + '%';
        if (j.status === 'done') { $('jobBox').classList.add('done'); $('jobLinks').hidden = false; $('jobResultLink').href = D.libraryUrl + '/' + j.result_media_id; $('btnSave').disabled = $('btnSave2').disabled = false; return; }
        if (j.status === 'failed') { showJobError(j.error || '알 수 없는 오류'); return; }
        poll(id);
      } catch (e) { showJobError(e.message); }
    }, 1000);
  }
  function showJobError(msg) { $('jobStatus').textContent = '실패'; const e = $('jobError'); e.hidden = false; e.textContent = msg; $('btnSave').disabled = $('btnSave2').disabled = false; }
  $('btnSave').addEventListener('click', submit); $('btnSave2').addEventListener('click', submit);

  /* ---------- misc ---------- */
  let flashTimer = 0;
  function flash(msg) { let el = document.getElementById('flash'); if (!el) { el = document.createElement('div'); el.id = 'flash'; el.style.cssText = 'position:fixed;left:50%;bottom:260px;transform:translateX(-50%);background:rgba(0,0,0,.8);color:#fff;padding:8px 14px;border-radius:8px;font-size:13px;z-index:99;pointer-events:none;transition:opacity .3s'; document.body.appendChild(el); } el.textContent = msg; el.style.opacity = 1; clearTimeout(flashTimer); flashTimer = setTimeout(() => el.style.opacity = 0, 1800); }
  function renderAll() { renderSegments(); renderMarks(); renderPlayhead(); renderOverlay(); }

  /* ---------- init ---------- */
  stripEl.style.backgroundImage = `url("${D.stripUrl}")`;
  $('tcTotal').textContent = '/ ' + tc(DUR);
  syncOutputFields();
  layoutStage(); layoutTimeline(); renderAll();
})();
