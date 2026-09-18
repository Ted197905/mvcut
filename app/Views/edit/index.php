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
    <a class="ed-back" href="<?= site_url('library/' . $item['id']) ?>" title="라이브러리로">&#x2039;</a>
    <div class="ed-title"><span class="name"><?= esc($item['title']) ?></span>
      <span class="meta"><?= esc($item['width'] . 'x' . $item['height']) ?> · <?= esc(rtrim(rtrim((string) $item['fps'], '0'), '.')) ?> fps · <?= esc($item['vcodec']) ?></span></div>
    <div class="ed-top-actions">
      <button class="tb" id="btnUndo" title="실행 취소 (Ctrl+Z)" disabled>&#x21A9;</button>
      <button class="tb" id="btnRedo" title="다시 실행 (Ctrl+Shift+Z)" disabled>&#x21AA;</button>
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
      <button class="tb" id="btnStart" title="처음으로 (Home)">&#x23EE;</button>
      <button class="tb" id="btnPrevFrame" title="이전 프레임 (←)">&#x25C0;</button>
      <button class="tb play" id="btnPlay" title="재생/일시정지 (Space)">&#x25B6;</button>
      <button class="tb" id="btnNextFrame" title="다음 프레임 (→)">&#x25B6;</button>
      <button class="tb" id="btnEnd" title="끝으로 (End)">&#x23ED;</button>
      <span class="sep"></span>
      <input class="tc big" id="tcCurrent" title="타임코드 입력 후 Enter">
      <span class="tc-total" id="tcTotal"></span>
      <span class="spacer"></span>
      <button class="tb" id="btnMarkIn" title="마크 In (I)">I</button>
      <button class="tb" id="btnMarkOut" title="마크 Out (O)">O</button>
      <button class="tb" id="btnSplit2" title="분할 (S)">&#x2702;</button>
      <button class="tb" id="btnDelete" title="세그먼트 삭제/복원 (Del)">&#x232B;</button>
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
