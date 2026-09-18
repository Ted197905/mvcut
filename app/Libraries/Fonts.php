<?php

namespace App\Libraries;

/**
 * Watermark fonts. All entries are SIL Open Font License 1.1, which permits
 * commercial use; the licence text ships next to each file in fonts/.
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
