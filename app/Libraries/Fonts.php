<?php

namespace App\Libraries;

/**
 * Fonts for watermarks and subtitles.
 *
 * The Google-hosted entries are SIL Open Font License 1.1. The noonnu-hosted Korean
 * fonts are free for commercial use and embedding but forbid modifying or
 * redistributing the file, so they are used as published (woff/woff2, which both
 * FreeType and the browser read) and never converted. 'terms' records each one.
 */
class Fonts
{
    public const LIST = [
        'pretendard' => [
            'label'   => 'Pretendard',
            'note'    => '현대적 산세리프 · 한글/영문',
            'file'    => 'Pretendard-Bold.otf',
            'url'     => 'https://github.com/orioncactus/pretendard/releases/download/v1.3.9/Pretendard-1.3.9.zip',
            'zipPath' => 'public/static/Pretendard-Bold.otf',
            'license' => 'SIL OFL 1.1',
            'by'      => 'Kil Hyung-jin',
        ],
        'nanumgothic' => [
            'label'   => '나눔고딕',
            'note'    => '가독성 좋은 기본 고딕',
            'file'    => 'NanumGothic-Bold.ttf',
            'url'     => 'https://github.com/google/fonts/raw/main/ofl/nanumgothic/NanumGothic-Bold.ttf',
            'licUrl'  => 'https://github.com/google/fonts/raw/main/ofl/nanumgothic/OFL.txt',
            'license' => 'SIL OFL 1.1',
            'by'      => 'NAVER',
        ],
        'blackhansans' => [
            'label'   => 'Black Han Sans',
            'note'    => '굵은 제목용',
            'file'    => 'BlackHanSans-Regular.ttf',
            'url'     => 'https://github.com/google/fonts/raw/main/ofl/blackhansans/BlackHanSans-Regular.ttf',
            'licUrl'  => 'https://github.com/google/fonts/raw/main/ofl/blackhansans/OFL.txt',
            'license' => 'SIL OFL 1.1',
            'by'      => 'Zess Type',
        ],
        'gothica1' => [
            'label'   => 'Gothic A1',
            'note'    => '단정한 고딕',
            'file'    => 'GothicA1-Bold.ttf',
            'url'     => 'https://github.com/google/fonts/raw/main/ofl/gothica1/GothicA1-Bold.ttf',
            'licUrl'  => 'https://github.com/google/fonts/raw/main/ofl/gothica1/OFL.txt',
            'license' => 'SIL OFL 1.1',
            'by'      => 'Hanyang Systems',
        ],
        'nanumpen' => [
            'label'   => '나눔손글씨 펜',
            'note'    => '손글씨',
            'file'    => 'NanumPenScript-Regular.ttf',
            'url'     => 'https://github.com/google/fonts/raw/main/ofl/nanumpenscript/NanumPenScript-Regular.ttf',
            'licUrl'  => 'https://github.com/google/fonts/raw/main/ofl/nanumpenscript/OFL.txt',
            'license' => 'SIL OFL 1.1',
            'by'      => 'NAVER',
        ],
        'notosanskr' => [
            'label'   => 'Noto Sans KR',
            'note'    => '얇은 두께 · 넓은 문자 지원',
            'file'    => 'NotoSansKR.ttf',
            'url'     => 'https://github.com/google/fonts/raw/main/ofl/notosanskr/NotoSansKR%5Bwght%5D.ttf',
            'licUrl'  => 'https://github.com/google/fonts/raw/main/ofl/notosanskr/OFL.txt',
            'license' => 'SIL OFL 1.1',
            'by'      => 'Google',
        ],
        'inter' => [
            'label'   => 'Inter',
            'note'    => '영문 전용',
            'file'    => 'Inter.ttf',
            'url'     => 'https://github.com/google/fonts/raw/main/ofl/inter/Inter%5Bopsz%2Cwght%5D.ttf',
            'licUrl'  => 'https://github.com/google/fonts/raw/main/ofl/inter/OFL.txt',
            'license' => 'SIL OFL 1.1',
            'by'      => 'Rasmus Andersson',
        ],
        'mulmaru' => [
            'label'   => '물마루',
            'note'    => '부드러운 손글씨풍',
            'file'    => 'Mulmaru.woff2',
            'url'     => 'https://cdn.jsdelivr.net/gh/projectnoonnu/2601-4@1.1/Mulmaru.woff2',
            'license' => 'SIL OFL 1.1',
            'by'      => 'Mushsooni',
        ],
        'omyupretty' => [
            'label'   => '오뮤 다예쁨체',
            'note'    => '동글동글한 자막용',
            'file'    => 'omyu_pretty.woff2',
            'url'     => 'https://cdn.jsdelivr.net/gh/projectnoonnu/noonfonts_2304-01@1.0/omyu_pretty.woff2',
            'license' => '무료 (상업적 사용/임베딩 허용, 파일 수정·재배포 금지)',
            'terms'   => 'https://noonnu.cc/font_page/1136',
            'by'      => '오뮤(OMYU)',
        ],
        'wiseelist' => [
            'label'   => '온글잎 위씨리스트',
            'note'    => '또박또박한 손글씨',
            'file'    => 'Ownglyph_wiseelist-Rg.woff2',
            'url'     => 'https://cdn.jsdelivr.net/gh/projectnoonnu/2501-1@1.1/Ownglyph_wiseelist-Rg.woff2',
            'license' => '무료 (상업적 사용/임베딩 허용, 파일 수정·재배포 금지)',
            'terms'   => 'https://noonnu.cc/font_page/1547',
            'by'      => '온글잎',
        ],
        'chosungu' => [
            'label'   => '조선굴림체',
            'note'    => '둥근 고딕 · 자막 기본',
            'file'    => 'ChosunGu.woff',
            'url'     => 'https://cdn.jsdelivr.net/gh/projectnoonnu/noonfonts_20-04@1.0/ChosunGu.woff',
            'license' => '무료 (상업적 사용/임베딩 허용, 파일 수정·유료 배포 금지)',
            'terms'   => 'https://noonnu.cc/font_page/415',
            'by'      => '조선일보',
        ],
        'chosun100' => [
            'label'   => '조선100년체',
            'note'    => '또렷한 제목용',
            'file'    => 'ChosunCentennial.woff2',
            'url'     => 'https://cdn.jsdelivr.net/gh/projectnoonnu/noonfonts_2206-02@1.0/ChosunCentennial.woff2',
            'license' => '무료 (상업적 사용/임베딩 허용, 파일 수정·유료 배포 금지, BI/CI 사용 불가)',
            'terms'   => 'https://noonnu.cc/font_page/937',
            'by'      => '조선일보',
        ],
        'gangwonedu' => [
            'label'   => '강원교육튼튼체',
            'note'    => '굵고 튼튼한 자막용',
            'file'    => 'GangwonEduPowerExtraBoldA.woff',
            'url'     => 'https://cdn.jsdelivr.net/gh/projectnoonnu/noonfonts_2201-2@1.0/GangwonEduPowerExtraBoldA.woff',
            'license' => '무료 (상업적 사용/임베딩 허용, 파일 수정·유료 배포 금지)',
            'terms'   => 'https://noonnu.cc/font_page/805',
            'by'      => '강원특별자치도교육청',
        ],
        'mplusrounded' => [
            'label'   => 'M PLUS Rounded 1c',
            'note'    => '둥근 고딕 · 일문/영문',
            'file'    => 'MPLUSRounded1c-Bold.ttf',
            'url'     => 'https://github.com/google/fonts/raw/main/ofl/mplusrounded1c/MPLUSRounded1c-Bold.ttf',
            'licUrl'  => 'https://github.com/google/fonts/raw/main/ofl/mplusrounded1c/OFL.txt',
            'license' => 'SIL OFL 1.1',
            'by'      => 'Coji Morishita',
        ],
    ];

    public static function dir(): string
    {
        return rtrim(ROOTPATH, '/') . '/fonts';
    }

    public static function path(string $key): ?string
    {
        $f = self::LIST[$key]['file'] ?? null;
        if (! $f) return null;
        $p = self::dir() . '/' . $f;
        return is_file($p) ? $p : null;
    }

    /** Human-readable font name for the key, or the key itself when unknown. */
    public static function label(string $key): string
    {
        return self::LIST[$key]['label'] ?? $key;
    }

    public static function has(string $key): bool
    {
        return self::path($key) !== null;
    }

    public static function default(): string
    {
        foreach (array_keys(self::LIST) as $k) {
            if (self::has($k)) return $k;
        }
        return 'pretendard';
    }

    /** Keys that are actually installed, with their labels (for the editor UI). */
    public static function available(): array
    {
        $out = [];
        foreach (self::LIST as $k => $f) {
            if (self::has($k)) $out[$k] = ['label' => $f['label'], 'note' => $f['note'], 'license' => $f['license'], 'by' => $f['by']];
        }
        return $out;
    }
}
