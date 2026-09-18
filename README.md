# MV Cut

SNS 게시용 영상을 모으고, 자르고, 변환하는 웹 서비스.

여러 플랫폼(Instagram, Facebook, X, Threads)에 흩어진 영상과 이미지를 라이브러리에 모으고,
브라우저에서 타임라인과 화면 영역을 잘라내고, 원하는 포맷으로 변환해 내려받는다.
무거운 처리는 전부 서버의 FFmpeg(NVENC)가 담당하고, 브라우저는 미리보기와 편집 파라미터 지정만 한다.

## 기능

- 계정: 회원가입, 로그인, 회원정보 수정
- 라이브러리: 원본 업로드 또는 SNS 링크 가져오기로 등록. 편집 결과도 라이브러리에 생성
- SNS 링크 가져오기: 링크에 포함된 이미지/영상 목록을 보여주고 선택한 것만 서버로 내려받아 자동 등록
- 편집: Timeline Crop / Cut, Screen Crop / Cut, 슬로우 모션
- 다운로드: 원본 포맷을 보여주고 원하는 포맷(mp4 / webm / gif)으로 변환해서 다운로드

## 스택

| 구성 | 버전 |
|---|---|
| Ubuntu | 26.04 LTS |
| nginx | 1.28 |
| PHP | 8.5 (FPM) |
| MySQL | 9.7 LTS |
| CodeIgniter | 4.7.x |
| FFmpeg | NVENC 지원 빌드 |

프론트엔드는 HTML5 + CSS3 + Vanilla JS, Apple Human Interface Guidelines 기준.

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

`.env`에 DB 접속 정보와 `app.baseURL`을 설정한다.

```
CI_ENVIRONMENT = development
app.baseURL = 'http://localhost/'
database.default.hostname = 127.0.0.1
database.default.database = mvcut
database.default.username = mvcut
database.default.password = ...
database.default.DBDriver = MySQLi
```

MySQL:

```sql
CREATE DATABASE mvcut CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE USER 'mvcut'@'localhost' IDENTIFIED BY '...';
GRANT ALL PRIVILEGES ON mvcut.* TO 'mvcut'@'localhost';
```

권한:

```bash
sudo chown -R $USER:www-data . && sudo chmod -R 755 . && sudo chmod -R 775 writable
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
    location ~ /\.(?!well-known) { deny all; }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/mvcut /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

`/etc/php/8.5/fpm/php.ini`에서 `upload_max_filesize`, `post_max_size`를 2G, `max_execution_time`을 300으로 올린다.

## 사용

1. 회원가입 후 로그인
2. 라이브러리에서 업로드하거나 SNS 링크를 붙여넣어 원본 등록
3. 항목을 선택해 편집기에서 구간/영역 지정 후 저장. 결과가 라이브러리에 생성됨
4. 다운로드에서 포맷을 골라 내려받기

DB 마이그레이션과 워커:

```bash
php spark migrate
php spark worker:run   # FFmpeg 잡 워커 (구현 예정)
```

## 개발

- 소스는 이 저장소. 배포 시 `.env`, `writable/`, `vendor/`는 서버 것을 유지
- 커밋 전 `.env`, 키 파일, 자격증명 문자열이 포함되지 않았는지 확인 (배포 스크립트가 자동 검사)
- 미디어 파일은 `writable/media/` 아래(웹 루트 밖)에 저장하고 컨트롤러가 인증 후 스트리밍

## 라이선스

MIT
