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
<?= view('partials/favicon') ?>
<link rel="stylesheet" href="<?= asset_url('assets/css/app.css') ?>">
<link rel="stylesheet" href="<?= asset_url('assets/css/editor.css') ?>">
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
          <div class="wm" id="wmPreview" hidden><span id="wmPreviewText"></span></div>
          <div class="wm sub" id="subPreview0" hidden><span></span></div>
          <div class="wm sub" id="subPreview1" hidden><span></span></div>
        </div>
      </div>
    </div>

    <aside class="ed-inspector">
      <div class="tabs" id="tabs">
        <button class="tab active" data-tab="segments">구간</button>
        <button class="tab" data-tab="screen">화면</button>
        <button class="tab" data-tab="subtitle">자막</button>
        <button class="tab" data-tab="watermark">워터마크</button>
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
            <button class="btn sm ghost" id="btnMarkAll" title="시작을 In, 끝을 Out으로 (Ctrl+A)">전체 선택</button>
            <button class="btn sm ghost" id="btnClearMark" title="마크 해제 (Esc)">마크 지우기</button>
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
          <div class="row2">
            <button class="btn sm secondary" id="btnMaskFill">배경 채우기 추가</button>
            <button class="btn sm secondary" id="btnMaskAi" <?= $aiEnabled ? '' : 'hidden' ?>>AI 지우기 추가</button>
          </div>
          <?php if (! $aiEnabled): ?><p class="hint">AI 기능(GPU)은 관리자가 계정에 허용해야 쓸 수 있습니다. 서버 부하 때문에 신규 계정은 기본으로 꺼져 있습니다.</p><?php endif ?>
          <div <?= $aiEnabled ? '' : 'hidden' ?>>
          <div class="row2">
            <button class="btn sm secondary" id="btnMaskTrack">대상 추적 지우기</button>
          </div>
          <p class="hint" id="trackHint" hidden></p>
          <div class="row2">
            <label>지우기 정밀도<select id="eraseQuality">
              <option value="fast">빠르게 (384px)</option>
              <option value="normal" selected>보통 (512px)</option>
              <option value="fine">정밀 (896px, 매우 느림)</option>
            </select></label>
          </div>
          </div>
          <p class="hint">배경 채우기는 주변 픽셀로 메웁니다(빠름, 작은 로고에 적합). AI 지우기는 지정한 사각형을 앞뒤 프레임을 참조해 복원합니다. 대상 추적 지우기는 클릭한 대상을 프레임마다 따라가며 지웁니다(움직이는 사람이나 물체에 적합, 가장 느림). 본인 영상에만 사용하세요.</p>
          <div class="masklist" id="maskList"></div>
          <div class="row4" id="maskFields" hidden>
            <label>X<input type="number" id="maskX" min="0" step="2"></label>
            <label>Y<input type="number" id="maskY" min="0" step="2"></label>
            <label>W<input type="number" id="maskW" min="2" step="2"></label>
            <label>H<input type="number" id="maskH" min="2" step="2"></label>
          </div>
        </div>
      </div>


      <div class="panel" data-panel="subtitle">
        <div class="sec">
          <div class="presets" id="subLayerTabs">
            <button data-layer="0" class="active">자막 1</button>
            <button data-layer="1">자막 2</button>
          </div>
          <label class="switch" style="margin-top:10px"><input type="checkbox" id="subOn"> <span>이 레이어 사용</span></label>
        </div>

        <div class="sec" id="subBody">
          <div class="sec-title">디자인 <span class="scope" id="styleScope">레이어 전체</span></div>
          <div class="presets" id="subTemplate">
            <button data-t="outline" class="active">외곽선</button>
            <button data-t="heavy">굵은 외곽선</button>
            <button data-t="grayline">회색 외곽선</button>
            <button data-t="plain">그림자</button>
            <button data-t="box">반투명 박스</button>
            <button data-t="blackbox">검정 박스</button>
            <button data-t="whitebox">흰 박스</button>
            <button data-t="highlight">노란 강조</button>
            <button data-t="glow">번짐 + 외곽선</button>
            <button data-t="softglow">번짐</button>
            <button data-t="yellowline">노랑 굵은 외곽선</button>
            <button data-t="invert">검정 글자</button>
            <button data-t="softbox">흰 반투명 박스</button>
            <button data-t="drop">큰 그림자</button>
            <button data-t="neon">네온</button>
          </div>
          <div class="field-lite" style="margin-top:10px">
            <label for="subFont">폰트</label>
            <select id="subFont">
              <?php foreach ($fonts as $key => $f): ?>
                <option value="<?= esc($key, 'attr') ?>"><?= esc($f['label']) ?> · <?= esc($f['note']) ?></option>
              <?php endforeach ?>
            </select>
          </div>
          <div class="row2">
            <label>크기(px)<input type="number" id="subSize" min="8" max="400" step="1"></label>
            <label>색<input type="color" id="subColor" value="#ffffff"></label>
          </div>
          <div class="sec-title" style="margin-top:14px">위치 <span class="hint">미리보기에서 드래그</span></div>
          <div class="pos-grid" id="subPos">
            <button data-a="nw"></button><button data-a="n"></button><button data-a="ne"></button>
            <button data-a="w"></button><button data-a="c"></button><button data-a="e"></button>
            <button data-a="sw"></button><button data-a="s" class="active"></button><button data-a="se"></button>
          </div>
          <div class="row2">
            <label>X<input type="number" id="subX" step="1"></label>
            <label>Y<input type="number" id="subY" step="1"></label>
          </div>
        </div>

        <div class="sec" id="subCueSec">
          <div class="sec-title">문장</div>
          <div class="row2">
            <button class="btn sm secondary" id="btnCueAdd">현재 위치에 추가</button>
            <button class="btn sm ghost" id="btnCueClear">모두 지우기</button>
          </div>
          <div class="cuelist" id="cueList"></div>
          <div class="row4" id="cueFields" hidden>
            <label>시작<input class="tc-off" id="cueStart"></label>
            <label>끝<input class="tc-off" id="cueEnd"></label>
          </div>
          <div class="field-lite" id="cueTextField" hidden>
            <label for="cueText">내용</label>
            <input class="tc-off" id="cueText" maxlength="200" autocomplete="off">
          </div>
          <div class="row2"><button class="btn sm ghost" id="btnStyleAll">이 디자인을 모든 문장에</button></div>
          <p class="hint">문장마다 디자인, 폰트, 크기, 색, 위치가 따로 저장됩니다. 문장을 고르면 위 "디자인"이 그 문장을 편집하고, 아무 문장도 고르지 않았을 때는 새로 만들 문장의 기본값을 편집합니다. 전부 같게 맞추려면 "이 디자인을 모든 문장에"를 누르세요.</p>
          <p class="hint">타임라인 아래 "자막 1 / 자막 2" 줄에서 더블클릭하면 그 자리에 문장이 생기고, 블록을 끌면 위치가, 양 끝을 끌면 길이가 바뀝니다. Del 키로 선택한 문장을 지웁니다.</p>
          <p class="hint">시간은 원본 기준입니다. 구간을 잘라내면 남은 구간에 맞춰 자동으로 당겨집니다.</p>
        </div>
      </div>

      <div class="panel" data-panel="watermark">
        <div class="sec">
          <label class="switch"><input type="checkbox" id="wmOn"> <span>워터마크 사용</span></label>
          <div class="field-lite">
            <label for="wmText">텍스트</label>
            <input class="tc-off" id="wmText" maxlength="120" placeholder="예: @mychannel" autocomplete="off">
          </div>
          <div class="field-lite">
            <label for="wmFont">폰트</label>
            <select id="wmFont">
              <?php foreach ($fonts as $key => $f): ?>
                <option value="<?= esc($key, 'attr') ?>"><?= esc($f['label']) ?> · <?= esc($f['note']) ?></option>
              <?php endforeach ?>
            </select>
          </div>
          <div class="hint" style="margin-top:6px">모두 SIL Open Font License · 상업적 사용 가능</div>
        </div>

        <div class="sec">
          <div class="sec-title">위치 <span class="hint">미리보기에서 드래그</span></div>
          <div class="pos-grid" id="wmPos">
            <button data-a="nw" title="좌상"></button><button data-a="n" title="상단"></button><button data-a="ne" title="우상"></button>
            <button data-a="w" title="좌"></button><button data-a="c" title="가운데"></button><button data-a="e" title="우"></button>
            <button data-a="sw" title="좌하"></button><button data-a="s" title="하단"></button><button data-a="se" class="active" title="우하"></button>
          </div>
          <div class="row2">
            <label>X<input type="number" id="wmX" step="1"></label>
            <label>Y<input type="number" id="wmY" step="1"></label>
          </div>
        </div>

        <div class="sec">
          <div class="sec-title">모양</div>
          <div class="row2">
            <label>크기(px)<input type="number" id="wmSize" min="8" max="400" step="1"></label>
            <label>색<input type="color" id="wmColor" value="#ffffff"></label>
          </div>
          <label class="slider">불투명도 <span id="wmOpacityVal">85%</span>
            <input type="range" id="wmOpacity" min="5" max="100" step="5" value="85">
          </label>
          <div class="presets" id="wmStyle">
            <button data-s="none">없음</button>
            <button data-s="shadow" class="active">그림자</button>
            <button data-s="outline">외곽선</button>
            <button data-s="box">박스</button>
          </div>
          <p class="hint">워터마크 내용과 설정은 계정에 저장됩니다. 다음 영상에서는 "워터마크 사용"만 켜면 그대로 적용되고, 크기는 영상 높이에 맞춰 조정됩니다. 내용을 비우면 저장된 설정도 지워집니다.</p>
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
          <div <?= $aiEnabled ? '' : 'hidden' ?>>
          <div class="sec-title" style="margin-top:14px">프레임 생성 (GPU)</div>
          <div class="presets" id="smoothPresets">
            <button data-smooth="off" class="active">끄기</button>
            <button data-smooth="x2">2배 부드럽게</button>
            <button data-smooth="x4">4배 부드럽게</button>
            <button data-smooth="slow">슬로우 보정</button>
          </div>
          <p class="hint">중간 프레임을 AI로 만들어 채웁니다. 슬로우 보정은 느리게 만든 영상의 끊김을 없앱니다. 처리 시간이 크게 늘어납니다.</p>
          <div class="sec-title" style="margin-top:14px">프레임 확장 (GPU)</div>
          <div class="presets" id="expandPresets">
            <button data-w="1" data-h="1" class="active">끄기</button>
            <button data-w="1.2" data-h="1">좌우 1.2배</button>
            <button data-w="1.5" data-h="1">좌우 1.5배</button>
            <button data-w="1" data-h="1.2">위아래 1.2배</button>
            <button data-w="1.2" data-h="1.2">전체 1.2배</button>
          </div>
          <p class="hint">화면 바깥을 AI로 만들어 화각을 넓힙니다. 검은 띠 대신 배경을 채울 때 씁니다. 1.5배를 넘으면 생성 영역이 부자연스러워집니다.</p>
          </div>
          <?php if (! $aiEnabled): ?><p class="hint">AI 기능(GPU)은 관리자가 계정에 허용해야 쓸 수 있습니다. 서버 부하 때문에 신규 계정은 기본으로 꺼져 있습니다.</p><?php endif ?>
        </div>
        <div class="sec">
          <div class="sec-title">화질</div>
          <div class="presets" id="sharpenPresets">
            <button data-sharpen="off" class="active">원본</button>
            <button data-sharpen="low">약하게</button>
            <button data-sharpen="mid">보통</button>
            <button data-sharpen="high">강하게</button>
          </div>
          <label class="switch"><input type="checkbox" id="denoise" checked> <span>압축 노이즈 정리 후 선명화</span></label>
          <p class="hint">SNS에서 가져온 영상처럼 압축으로 뭉개진 화면에 효과가 큽니다. 강하게는 윤곽에 테두리가 생길 수 있습니다.</p>
          <div <?= $aiEnabled ? '' : 'hidden' ?>>
          <div class="sec-title" style="margin-top:14px">AI 복원 (GPU)</div>
          <div class="presets" id="restorePresets">
            <button data-restore="off" class="active">끄기</button>
            <button data-restore="ai">디테일 복원</button>
            <button data-restore="ai2x">2배 확대</button>
          </div>
          <div class="row2" id="restoreModelRow" hidden>
            <label>모델<select id="restoreModel"><option value="general">실사</option><option value="anime">애니메이션 · 그래픽</option></select></label>
          </div>
          <p class="hint">프레임마다 신경망을 돌려 디테일을 다시 만듭니다. 처리 시간이 길고(720p 기준 실시간의 5배 안팎), 얼굴이 작게 나오면 이목구비가 달라 보일 수 있습니다.</p>
          </div>
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
        <div class="subtrack" id="subTrack0" data-layer="0">
          <span class="tl-label">자막 1</span><div class="cues" id="cues0"></div>
        </div>
        <div class="subtrack" id="subTrack1" data-layer="1">
          <span class="tl-label">자막 2</span><div class="cues" id="cues1"></div>
        </div>
        <div class="playhead" id="playhead"><div class="head"></div></div>
      </div>
    </div>
  </section>
</div>

<script>
window.FONTS = <?= json_encode($fonts, JSON_UNESCAPED_UNICODE) ?>;
window.FONT_URL = <?= json_encode(site_url('fonts/')) ?>;
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
    'wmPreset' => $wmPreset,
    'wmPrefUrl'=> site_url('api/prefs/watermark'),
    'aiEnabled'=> (bool) $aiEnabled,
], JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="<?= asset_url('assets/js/app.js') ?>"></script>
<script src="<?= asset_url('assets/js/editor.js') ?>"></script>
</body>
</html>
