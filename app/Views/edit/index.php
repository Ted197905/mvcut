<?php
// inline SVG icon set (SF Symbols-like): stroke icons use currentColor
$svg = static function (string $body, string $extra = '') {
    return '<svg viewBox="0 0 16 16" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" ' . $extra . '>' . $body . '</svg>';
};
$icons = [
    'start'  => '<svg viewBox="0 0 16 16" width="15" height="15" fill="currentColor"><rect x="2.4" y="3" width="1.7" height="10" rx=".85"/><path d="M13.3 3.9v8.2a.7.7 0 0 1-1.08.59l-6.2-4.1a.7.7 0 0 1 0-1.17l6.2-4.1a.7.7 0 0 1 1.08.58z"/></svg>',
    'end'    => '<svg viewBox="0 0 16 16" width="15" height="15" fill="currentColor"><rect x="11.9" y="3" width="1.7" height="10" rx=".85"/><path d="M2.7 3.9v8.2a.7.7 0 0 0 1.08.59l6.2-4.1a.7.7 0 0 0 0-1.17l-6.2-4.1A.7.7 0 0 0 2.7 3.9z"/></svg>',
    'prev'   => '<svg viewBox="0 0 16 16" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M10 3.5 5.5 8l4.5 4.5"/></svg>',
    'next'   => '<svg viewBox="0 0 16 16" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3.5 10.5 8 6 12.5"/></svg>',
    'play'   => '<svg viewBox="0 0 16 16" width="16" height="16" fill="currentColor"><path d="M4.2 2.9v10.2a.7.7 0 0 0 1.07.6l8-5.1a.7.7 0 0 0 0-1.2l-8-5.1a.7.7 0 0 0-1.07.6z"/></svg>',
    'pause'  => '<svg viewBox="0 0 16 16" width="16" height="16" fill="currentColor"><rect x="3.6" y="2.9" width="3.1" height="10.2" rx="1.1"/><rect x="9.3" y="2.9" width="3.1" height="10.2" rx="1.1"/></svg>',
    'split'  => '<svg viewBox="0 0 16 16" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="3.6" cy="12.2" r="2.1"/><circle cx="12.4" cy="12.2" r="2.1"/><path d="M5.2 10.6 12 1.8M10.8 10.6 4 1.8"/></svg>',
    'trash'  => '<svg viewBox="0 0 16 16" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M2.6 4.3h10.8M6.2 4.3V2.9h3.6v1.4M3.9 4.3l.6 8.4a1 1 0 0 0 1 .9h5a1 1 0 0 0 1-.9l.6-8.4"/></svg>',
    'undo'   => '<svg viewBox="0 0 16 16" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M2.8 5.2h7.4a3.5 3.5 0 0 1 0 7H6M2.8 5.2 5.6 2.6M2.8 5.2l2.8 2.6"/></svg>',
    'redo'   => '<svg viewBox="0 0 16 16" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M13.2 5.2H5.8a3.5 3.5 0 0 0 0 7H10M13.2 5.2 10.4 2.6M13.2 5.2l-2.8 2.6"/></svg>',
    'back'   => '<svg viewBox="0 0 16 16" width="19" height="19" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10 2.6 4.6 8 10 13.4"/></svg>',
];
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf" data-name="<?= csrf_token() ?>" content="<?= csrf_hash() ?>">
<title><?= esc($title) ?> - MV Cut</title>
<link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>">
<link rel="stylesheet" href="<?= base_url('assets/css/editor.css') ?>">
</head>
<body class="editor-body">
<div class="ed" id="editor">
  <header class="ed-top">
    <a class="ed-back" href="<?= site_url('library/' . $item['id']) ?>" title="미디어로 돌아가기" aria-label="뒤로"><?= $icons['back'] ?></a>
    <div class="ed-title"><span class="name"><?= esc($item['title']) ?></span>
      <span class="meta"><?= esc($item['width'] . 'x' . $item['height']) ?> · <?= esc(rtrim(rtrim((string) $item['fps'], '0'), '.')) ?> fps · <?= esc($item['vcodec']) ?></span></div>
    <div class="ed-top-actions">
      <button class="tb" id="btnUndo" title="실행 취소 (Ctrl+Z)" aria-label="실행 취소" disabled><?= $icons['undo'] ?></button>
      <button class="tb" id="btnRedo" title="다시 실행 (Ctrl+Shift+Z)" aria-label="다시 실행" disabled><?= $icons['redo'] ?></button>
      <button class="btn sm" id="btnSave">저장</button>
    </div>
  </header>

  <section class="ed-main">
    <div class="ed-preview" id="previewWrap">
      <div class="ed-stage" id="stage">
        <video id="video" src="<?= site_url('media/' . $item['id'] . '/proxy') ?>" preload="auto" playsinline <?= $item['has_thumb'] ? 'poster="' . site_url('media/' . $item['id'] . '/thumb') . '"' : '' ?>></video>
        <div class="ed-overlay" id="overlay">
          <div class="rect crop" id="cropRect" hidden>
            <div class="rect-label">CROP</div>
            <i data-h="nw"></i><i data-h="n"></i><i data-h="ne"></i><i data-h="e"></i><i data-h="se"></i><i data-h="s"></i><i data-h="sw"></i><i data-h="w"></i>
          </div>
          <div id="maskLayer"></div>
        </div>
      </div>
    </div>

    <aside class="ed-inspector">
      <div class="tabs" id="tabs">
        <button class="tab active" data-tab="segments">구간</button>
        <button class="tab" data-tab="screen">화면</button>
        <button class="tab" data-tab="output">출력</button>
      </div>

      <div class="panel active" data-panel="segments">
        <div class="sec">
          <div class="sec-title">마크 In / Out <span class="hint">I / O 키</span></div>
          <div class="row2">
            <label>In<input class="tc" id="markIn" placeholder="00:00:00:00"></label>
            <label>Out<input class="tc" id="markOut" placeholder="--:--:--:--"></label>
          </div>
          <div class="row2">
            <button class="btn sm secondary" id="btnKeepMark" title="마크 구간만 남기기">구간만 남기기</button>
            <button class="btn sm secondary" id="btnCutMark" title="마크 구간 잘라내기">구간 잘라내기</button>
          </div>
          <div class="row2">
            <button class="btn sm ghost" id="btnClearMark">마크 지우기</button>
          </div>
        </div>
        <div class="sec">
          <div class="sec-title">선택한 세그먼트 <span class="hint">S 분할 · Del 삭제</span></div>
          <div class="row2">
            <label>시작<input class="tc" id="segStart"></label>
            <label>끝<input class="tc" id="segEnd"></label>
          </div>
          <div class="row2">
            <button class="btn sm secondary" id="btnSplit">플레이헤드에서 분할</button>
            <button class="btn sm secondary" id="btnToggleSeg">삭제 / 복원</button>
          </div>
        </div>
        <div class="sec">
          <div class="sec-title">세그먼트 목록</div>
          <div class="seglist" id="segList"></div>
          <div class="hint" id="keepSummary"></div>
        </div>
      </div>

      <div class="panel" data-panel="screen">
        <div class="sec">
          <div class="sec-title">화면 크롭 (Screen Crop)</div>
          <label class="switch"><input type="checkbox" id="cropOn"> <span>크롭 사용</span></label>
          <div class="presets" id="cropPresets">
            <button data-ar="free" class="active">자유</button>
            <button data-ar="1:1">1:1</button>
            <button data-ar="4:5">4:5</button>
            <button data-ar="9:16">9:16</button>
            <button data-ar="16:9">16:9</button>
            <button data-ar="src">원본</button>
          </div>
          <div class="row4">
            <label>X<input type="number" id="cropX" min="0" step="2"></label>
            <label>Y<input type="number" id="cropY" min="0" step="2"></label>
            <label>W<input type="number" id="cropW" min="2" step="2"></label>
            <label>H<input type="number" id="cropH" min="2" step="2"></label>
          </div>
          <div class="row2">
            <button class="btn sm ghost" id="btnCropCenter">가운데 정렬</button>
            <button class="btn sm ghost" id="btnCropReset">초기화</button>
          </div>
        </div>
        <div class="sec">
          <div class="sec-title">화면 가리기 (Screen Cut)</div>
          <div class="row2">
            <button class="btn sm secondary" id="btnMaskBlack">검정 영역 추가</button>
            <button class="btn sm secondary" id="btnMaskBlur">블러 영역 추가</button>
          </div>
          <div class="masklist" id="maskList"></div>
          <div class="row4" id="maskFields" hidden>
            <label>X<input type="number" id="maskX" min="0" step="2"></label>
            <label>Y<input type="number" id="maskY" min="0" step="2"></label>
            <label>W<input type="number" id="maskW" min="2" step="2"></label>
            <label>H<input type="number" id="maskH" min="2" step="2"></label>
          </div>
        </div>
      </div>

      <div class="panel" data-panel="output">
        <div class="sec">
          <div class="sec-title">속도</div>
          <div class="presets" id="speedPresets">
            <button data-speed="0.25">0.25x</button><button data-speed="0.5">0.5x</button><button data-speed="0.75">0.75x</button>
            <button data-speed="1" class="active">1x</button><button data-speed="1.5">1.5x</button><button data-speed="2">2x</button><button data-speed="4">4x</button>
          </div>
          <label class="switch"><input type="checkbox" id="keepAudio" checked> <span>오디오 유지 (속도에 맞춰 피치 보정)</span></label>
        </div>
        <div class="sec">
          <div class="sec-title">출력</div>
          <div class="row2">
            <label>포맷<select id="outFormat"><option value="mp4">MP4 (H.264 + AAC)</option><option value="webm">WebM (VP9 + Opus)</option><option value="gif">GIF (15fps, 무음)</option></select></label>
            <label>해상도<select id="outHeight"><option value="0">원본</option><option value="1080">1080p</option><option value="720">720p</option><option value="480">480p</option><option value="360">360p</option></select></label>
          </div>
          <div class="row2">
            <label>품질<select id="outQuality"><option value="high">높음</option><option value="medium">보통 (작은 용량)</option></select></label>
            <label>예상 길이<input id="outDuration" readonly></label>
          </div>
        </div>
        <div class="sec">
          <button class="btn block" id="btnSave2">저장 (라이브러리에 결과 생성)</button>
          <div class="jobbox" id="jobBox" hidden>
            <div class="jobline"><span id="jobStatus">대기 중</span><span id="jobPct">0%</span></div>
            <div class="bar"><i id="jobBar"></i></div>
            <div class="joblinks" id="jobLinks" hidden>
              <a class="btn sm" id="jobResultLink" href="#">결과 보기</a>
              <a class="btn sm secondary" href="<?= site_url('library') ?>">라이브러리</a>
            </div>
            <div class="err" id="jobError" hidden></div>
          </div>
        </div>
      </div>
    </aside>
  </section>

  <section class="ed-timeline">
    <div class="transport">
      <button class="tb" id="btnStart" title="처음으로 (Home)" aria-label="처음으로"><?= $icons['start'] ?></button>
      <button class="tb" id="btnPrevFrame" title="이전 프레임 (←)" aria-label="이전 프레임"><?= $icons['prev'] ?></button>
      <button class="tb play" id="btnPlay" title="재생 / 일시정지 (Space)" aria-label="재생"><?= $icons['play'] ?></button>
      <button class="tb" id="btnNextFrame" title="다음 프레임 (→)" aria-label="다음 프레임"><?= $icons['next'] ?></button>
      <button class="tb" id="btnEnd" title="끝으로 (End)" aria-label="끝으로"><?= $icons['end'] ?></button>
      <span class="sep"></span>
      <input class="tc big" id="tcCurrent" title="타임코드 입력 후 Enter">
      <span class="tc-total" id="tcTotal"></span>
      <span class="spacer"></span>
      <button class="tb" id="btnMarkIn" title="마크 In (I)">I</button>
      <button class="tb" id="btnMarkOut" title="마크 Out (O)">O</button>
      <button class="tb" id="btnSplit2" title="플레이헤드에서 분할 (S)" aria-label="분할"><?= $icons['split'] ?></button>
      <button class="tb" id="btnDelete" title="세그먼트 삭제 / 복원 (Del)" aria-label="세그먼트 삭제"><?= $icons['trash'] ?></button>
      <span class="sep"></span>
      <label class="zoom" title="줌 (Ctrl+휠, +/-)">&#x1F50D;<input type="range" id="zoom" min="1" max="60" step="0.5" value="1"></label>
      <button class="tb" id="btnFit" title="전체 보기">Fit</button>
    </div>
    <div class="tl-scroll" id="tlScroll">
      <div class="tl-inner" id="tlInner">
        <canvas class="ruler" id="ruler"></canvas>
        <div class="track" id="track">
          <div class="strip" id="strip"></div>
          <div class="segments" id="segments"></div>
          <div class="marks" id="marks"></div>
        </div>
        <div class="playhead" id="playhead"><div class="head"></div></div>
      </div>
    </div>
  </section>
</div>

<script>
window.ICONS = { play: <?= json_encode($icons['play']) ?>, pause: <?= json_encode($icons['pause']) ?> };
window.EDITOR_DATA = <?= json_encode([
    'id'       => (int) $item['id'],
    'duration' => (float) $item['duration'],
    'fps'      => (float) ($item['fps'] ?: 30),
    'width'    => (int) $item['width'],
    'height'   => (int) $item['height'],
    'hasAudio' => ! empty($item['acodec']),
    'stripUrl' => site_url('media/' . $item['id'] . '/strip') . '?v=2',
    'submitUrl'=> site_url('api/edit/' . $item['id']),
    'jobsUrl'  => site_url('api/jobs'),
    'libraryUrl' => site_url('library'),
    'params'   => $params,
], JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="<?= base_url('assets/js/app.js') ?>"></script>
<script src="<?= base_url('assets/js/editor.js') ?>"></script>
</body>
</html>
