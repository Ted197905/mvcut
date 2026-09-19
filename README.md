# MV Cut

SNS 게시용 영상을 모으고, 자르고, 변환하는 웹 서비스.

여러 플랫폼(YouTube, X, Facebook, Instagram, Threads)에 흩어진 영상과 이미지를 라이브러리에 모으고,
브라우저에서 타임라인과 화면 영역을 잘라내고, 워터마크를 올리고, 원하는 포맷으로 변환해 내려받는다.
무거운 처리는 전부 서버의 FFmpeg(NVENC)가 담당하고, 브라우저는 미리보기와 편집 파라미터 지정만 한다.

문서: [제작 의도](https://github.com/Ted197905/mvcut) 및 기능 설명, 설치 매뉴얼, 사용설명서는 프로젝트 문서 폴더(`docs/`)의 HTML로 관리한다.

## 기능

### 계정
- 회원가입, 로그인
- 설정 화면에서 이름, 이메일, 비밀번호 수정 (이메일/비밀번호 변경 시 현재 비밀번호 확인)

### 라이브러리
- 드래그 앤 드롭 업로드. 큰 파일은 청크 업로드로 나눠 전송 (최대 4GB)
- 등록 시 ffprobe로 메타데이터를 읽고 썸네일과 타임라인 필름스트립 생성
- 브라우저가 재생하지 못하는 코덱(HEVC, AV1)은 720p H.264 프록시를 따로 생성
- 검색, 종류 필터, 정렬, 다중 선택 삭제
- 정사각형 썸네일에 전체 화면을 담아 표시 (세로 영상도 잘리지 않음)

### SNS 수집
- 링크 입력창은 라이브러리 상단에 상시 노출. Paste / Del 버튼 제공
- YouTube, X, Facebook: yt-dlp. 실패 시 헤드리스 렌더러로 재시도
- Instagram, Threads: 페이지가 JavaScript로만 그려지므로 처음부터 헤드리스 Chromium(Playwright)으로 렌더링
- 게시물 주소면 해당 게시물 영역만 수집 (옆 게시물, 추천 그리드 제외)
- 캐러셀은 다음 버튼을 눌러가며 각 장 수집, CDN 크롭 변형은 하나로 합침
- Instagram 영상은 분할 스트리밍이라 다운로드는 쿠키를 쓴 yt-dlp가 담당
- 게시물 구조 파싱: 작성자, 본문, 좋아요/댓글/리포스트/공유 수를 분리해 저장
- 설정 화면에서 Instagram / Threads 로그인 쿠키 업로드. 형식과 도메인, sessionid를 검증하고 만료일과 실패 사유를 표시

### 편집
- Timeline Cut / Crop: 여러 구간, 프레임 스냅, 자석 스냅, 되돌리기
- 마우스, 키보드(Space, J/K/L, 화살표, I/O, S, Del, Ctrl+Z/Y/A/S), 타임코드 직접 입력
- Screen Crop (9:16, 1:1, 4:5 프리셋), 마스크(검정 / 블러 / 배경 채우기 / AI 지우기 / 대상 추적 지우기)
- 프레임 확장: 화면 바깥을 생성해 화각을 넓힘 (ProPainter outpainting)
- 속도 0.25x ~ 4x (오디오 동반, 소스 fps 유지)
- 워터마크: 9분할 위치 프리셋과 드래그, 크기/색/불투명도/스타일 4종, SIL OFL 폰트 7종
- 화질: 압축 노이즈 정리 + 대비 기반 선명화 3단계 (필터, 추가 설치 없음)
- AI 복원: Real-ESRGAN 압축 모델로 디테일 재생성, 원본 크기 또는 2배 확대 (GPU)
- 프레임 생성: RIFE 4.25로 2배/4배 부드럽게, 슬로우 모션의 끊김 제거 (GPU)

### 변환과 다운로드
- 원본 포맷을 표시하고 MP4 / WebM / GIF, 해상도, 품질 선택
- 이미지는 JPG / PNG / WebP
- 결과는 라이브러리에 결과물로 등록

### 서버 처리
- 편집, 변환, 프록시, 가져오기는 잡 큐(`jobs`)에 들어가고 systemd 워커가 처리
- FFmpeg `filter_complex` 한 번으로 구간, 크롭, 마스크, 워터마크, 속도, 스케일 처리
- NVENC 사용 가능하면 h264_nvenc, 없으면 libx264로 자동 전환
- 미디어는 웹 루트 밖에 저장하고 인증 후 nginx `X-Accel-Redirect`로 전송

## 스택

| 구성 | 버전 |
|---|---|
| Ubuntu | 26.04 LTS |
| nginx | 1.28 |
| PHP | 8.5 (FPM) |
| MySQL | 8.4 LTS |
| CodeIgniter | 4.7.x |
| FFmpeg | NVENC 지원 빌드 |
| yt-dlp | 최신 릴리스 |
| Playwright | Chromium (Instagram / Threads 수집) |
| PyTorch | 2.14 + CUDA 12.6 (AI 보정, 선택) |
| RIFE | 4.25 (MIT) |
| Real-ESRGAN | realesr-general-x4v3 / animevideov3 (BSD-3-Clause) |
| ProPainter | 영역 인페인팅 / 프레임 확장 (NTU S-Lab License 1.0, 비상업적 사용만) |
| SAM 2 | 클릭 대상 추적 마스크 (Apache-2.0) |

프론트엔드는 HTML5 + CSS3 + Vanilla JS, Apple Human Interface Guidelines 기준. 빌드 도구 없음.

## 설치

Ubuntu 26.04 기준. WSL2, 네이티브 모두 동일.

```bash
sudo apt update && sudo apt install -y nginx git unzip curl ffmpeg \
  php8.5-fpm php8.5-cli php8.5-mysql php8.5-intl php8.5-mbstring \
  php8.5-xml php8.5-curl php8.5-zip php8.5-gd php8.5-bcmath mysql-server

curl -sS https://getcomposer.org/installer | php && sudo mv composer.phar /usr/local/bin/composer

sudo chown $USER:www-data /var/www && chmod 775 /var/www
cd /var/www && git clone git@github.com:Ted197905/mvcut.git && cd mvcut
composer install --no-dev
cp env .env
```

`.env` 설정:

```
CI_ENVIRONMENT = production
app.baseURL = 'https://example.com/'
database.default.hostname = localhost
database.default.database = app
database.default.username = app
database.default.password = ...
database.default.DBDriver = MySQLi
```

DB:

```sql
CREATE DATABASE app CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE USER 'app'@'localhost' IDENTIFIED BY '...';
GRANT ALL PRIVILEGES ON app.* TO 'app'@'localhost';
```

```bash
php spark migrate
```

권한:

```bash
sudo chown -R $USER:www-data . && sudo chmod -R 755 . && sudo chmod -R 775 writable
sudo mkdir -p secrets && sudo chgrp www-data secrets && sudo chmod 770 secrets
```

nginx 서버 블록 (`/etc/nginx/sites-available/mvcut`):

```nginx
server {
    listen 80;
    server_name _;
    root /var/www/mvcut/public;
    index index.php;
    client_max_body_size 2G;

    location / { try_files $uri $uri/ /index.php$is_args$args; }
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.5-fpm.sock;
        fastcgi_read_timeout 300;
    }
    location /protected/ { internal; alias /var/www/mvcut/writable/media/; }
    location ~ /\.(?!well-known) { deny all; }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/mvcut /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

`/etc/php/8.5/fpm/php.ini`에서 `upload_max_filesize`, `post_max_size`를 2G, `max_execution_time`을 300으로 올린다.

### 수집 도구

```bash
# yt-dlp (저장소 밖, 앱 디렉터리 안)
cd bin && curl -sL https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp -o yt-dlp && chmod 755 yt-dlp

# Instagram / Threads용 헤드리스 Chromium
sudo apt install -y python3-pip libnss3 libnspr4 libasound2t64
sudo pip install --break-system-packages playwright
sudo PLAYWRIGHT_BROWSERS_PATH=/var/www/mvcut/browsers playwright install chromium
```

### AI 보정 (선택, GPU 필요)

없으면 해당 옵션만 건너뛰고 나머지는 그대로 동작한다.

```bash
pip install --target ./pylibs --index-url https://download.pytorch.org/whl/cu126 torch torchvision
pip install --target ./pylibs gdown

cd vendor_ml
git clone --depth 1 https://github.com/hzwer/Practical-RIFE.git
PYTHONPATH=../pylibs python3 -m gdown -O rife425.zip 1ZKjcbmt1hypiFprJPIKW0Tt0lr_2i7bg
unzip -q rife425.zip && rm -rf __MACOSX rife425.zip && mv train_log Practical-RIFE/
curl -sLO https://github.com/xinntao/Real-ESRGAN/releases/download/v0.2.5.0/realesr-general-x4v3.pth
curl -sLO https://github.com/xinntao/Real-ESRGAN/releases/download/v0.2.5.0/realesr-animevideov3.pth
```

AI 지우기(인페인팅)를 쓰려면 ProPainter도 설치한다. 라이선스가 비상업적 사용만 허용하므로
상업적으로 쓸 계획이라면 설치하지 않는다. 설치하지 않으면 해당 옵션만 동작하지 않는다.

```bash
pip install --target ./pylibs av addict einops scipy opencv-python-headless \
  scikit-image imageio imageio-ffmpeg pyyaml timm matplotlib
cd vendor_ml && git clone --depth 1 https://github.com/sczhou/ProPainter.git
cd ProPainter/weights
for f in ProPainter.pth recurrent_flow_completion.pth raft-things.pth i3d_rgb_imagenet.pt; do
  curl -sLO https://github.com/sczhou/ProPainter/releases/download/v0.1.0/$f
done

# 대상 추적 지우기용 SAM 2 (Apache-2.0)
pip install --target ../../pylibs "sam2==1.1.0" hydra-core iopath
mkdir -p ../sam2 && cd ../sam2
curl -sLO https://dl.fbaipublicfiles.com/segment_anything_2/092824/sam2.1_hiera_small.pt
```

### 워터마크 폰트

```bash
php spark fonts:install    # SIL OFL 폰트 7종을 fonts/ 에 설치
php spark watermark:test   # 폰트별 렌더링 확인
```

### 워커

```bash
sudo systemctl enable --now mvcut-worker
journalctl -u mvcut-worker -f
```

## 사용

1. 회원가입 후 로그인
2. 라이브러리에서 파일을 올리거나 SNS 링크를 붙여넣고 "리소스 확인" → 가져올 항목 선택
3. 항목을 열고 편집기에서 구간과 영역, 워터마크를 지정한 뒤 저장. 결과가 라이브러리에 생성됨
4. 상세 화면에서 포맷을 골라 변환 후 다운로드
5. 로그인이 필요한 게시물은 설정 화면에서 해당 플랫폼 쿠키를 등록

## 저장소에 없는 것

서버에만 존재하며 git에서 제외한다.

| 경로 | 내용 |
|---|---|
| `.env` | DB 접속 정보 등 환경 설정 |
| `writable/` | 업로드된 미디어, 캐시, 로그 |
| `vendor/` | Composer 의존성 |
| `bin/yt-dlp` | 실행 바이너리 |
| `browsers/` | Playwright Chromium |
| `fonts/` | 워터마크 폰트 |
| `pylibs/` | PyTorch 등 AI 보정 라이브러리 |
| `vendor_ml/` | RIFE / Real-ESRGAN / ProPainter / SAM 2 모델 |
| `secrets/` | 플랫폼 로그인 쿠키 |

## 개발

- 배포 스크립트가 커밋 대상에 `.env`, 키 파일, 쿠키, 자격증명 문자열이 섞였는지 매번 검사한다
- 미디어 파일은 `writable/media/` 아래(웹 루트 밖)에 저장하고 컨트롤러가 인증 후 스트리밍한다
- 프론트엔드는 빌드 단계가 없다. `public/assets/` 아래 CSS와 JS를 직접 수정한다

## 라이선스

MIT
