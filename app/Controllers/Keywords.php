<?php

namespace App\Controllers;

use App\Models\KeywordModel;
use CodeIgniter\Exceptions\PageNotFoundException;

/** /keywords - keyword tree (group -> members) to paste into X posts. Tree state lives in the DB. */
class Keywords extends BaseController
{
    /** Starter set: group line followed by member lines; a single line is a solo keyword. */
    private const DEFAULTS = [
        ['치이카와 ちいかわ chiikawa', '우사기 usagi', '먼작귀 먼가작고귀여운녀석들', '치이카와극장판 인어섬의비밀', '映画ちいかわ 人魚の島のひみつ', 'Chiikawa the Movie: The Secret of the Mermaid Island'],
        ['블랙핑크 BLACKPINK ブラックピンク', '지수 JISOO', '리사 라리사 LISA LALISA', '제니 JENNIE 김제니 ジェニー'],
        ['IVE 아이브', 'Jang Won-young 장원영 チャン・ウォニョン', 'LEESEO 이서 イソ'],
        ['LESSERAFIM 르세라핌', '카즈하 KAZUHA 나카무라 카즈하 中村一葉 Nakamura Kazuha', '사쿠라 MIYAWAKI SAKURA', '은채 EUNCHAE'],
        ['AESPA 에스파', 'KARINA 카리나', 'WINTER 윈터'],
        ['키스오브라이프 KISSOFLIFE', '나띠 NATTY'],
        ['오마이걸 OH MY GIRL オーマイガール', '아린 ARIN アリン 최예원 崔乂園 Choi Yeawon', '효정 Hyojung', '유아 YooA'],
        ['APINK 에이핑크', 'Oh Hayoung 오하영'],
        ['tripleS 트리플에스', 'Xinyu 신위', 'Nien 니엔', '카에데 Kaede', 'S14 박소현 朴昭玹 Park SoHyun'],
        ['NMIXX 엔믹스 エンミックス', 'SULLYOON 설윤 ソリュン', 'Haewon 혜원 Oh Haewon 오혜원'],
        ['(G)I-DLE I-DLE 아이들', '미연 Miyeon Cho Miyeon'],
        ['미카미 유아 三上悠亜 Yua Mikami'],
        ['코요이 코난 小宵こなん Konan Koyoi'],
        ['FIFTYFIFTY 피프티피프티', 'ATHENA 아테나'],
        ['Mooove!', '히메노 히나노 姫野ひなの Hinano Himeno'],
        ['權恩妃 권은비 EUNBI'],
        ['지지원 지원 ZZONE 김지원 Jiwon'],
        ['이주은 leejueun 李珠珢 チアリーダー เชียร์ลีดเดอร์ cheerleader 치어리더'],
        ['ILLIT 아일릿 アイリット', 'MINJU 민주', 'Wonhee 원희'],
        ['BABYMONSTER 베이비몬스터', 'Ahyeon 아현'],
        ['ITZY 잇지', 'YUNA 유나'],
        ['케플러 Kep1er ke1per', '김채현 CHAEHYUN'],
        ['박보영 朴寶英 ParkBoyoung'],
        ['TWICE 트와이스 トゥワイス', 'Jeongyeon 정연', 'Jihyo 지효', 'MINA 미나 ミナ 名井南'],
        ['하츠투하츠 Hearts2Hearts', '이안 IAN', '지우 JIWOO'],
        ['앳하트 AtHeart', '나현 NAHYUN'],
        ['캣츠아이 KATSEYE キャッツアイ'],
        ['하이키 H1-KEY', '옐 YEL'],
        ['미야오 MEOVV ミヤオ', '안나 ANNA アンナ 田中杏奈 타나카 안나'],
        ['최예나 예나 YENA 캐치캐치'],
        ['비비 BIBI ビビ 김형서 金亨瑞 Kim Hyeong-seo'],
    ];

    private function uid(): int
    {
        return (int) session()->get('user_id');
    }

    private function json(array $body, int $status = 200)
    {
        return $this->response->setStatusCode($status)->setJSON($body);
    }

    public function index()
    {
        $m = new KeywordModel();
        return view('keywords/index', ['title' => '키워드', 'tree' => $m->tree($this->uid())]);
    }

    /** POST /api/keywords  {parent_id: int|null, name} -> {ok, node} */
    public function create()
    {
        $in   = $this->request->getJSON(true) ?? [];
        $name = KeywordModel::clean((string) ($in['name'] ?? ''), $err);
        if ($err) return $this->json(['error' => $err], 422);
        $m   = new KeywordModel();
        $pid = isset($in['parent_id']) && $in['parent_id'] !== null ? (int) $in['parent_id'] : null;
        if ($pid !== null) {
            $parent = $m->findOwned($pid, $this->uid());
            if (! $parent) return $this->json(['error' => '상위 키워드가 없습니다.'], 404);
            if ($parent['parent_id'] !== null) return $this->json(['error' => '하위 키워드 아래에는 추가할 수 없습니다.'], 422);
        }
        $id = $m->insert(['user_id' => $this->uid(), 'parent_id' => $pid, 'name' => $name, 'sort' => $m->nextSort($this->uid(), $pid)]);
        return $this->json(['ok' => true, 'node' => ['id' => (int) $id, 'name' => $name, 'collapsed' => false, 'children' => []]]);
    }

    /** POST /api/keywords/{id}  {name} */
    public function update(int $id)
    {
        $m = new KeywordModel();
        if (! $m->findOwned($id, $this->uid())) return $this->json(['error' => '키워드가 없습니다.'], 404);
        $in   = $this->request->getJSON(true) ?? [];
        $name = KeywordModel::clean((string) ($in['name'] ?? ''), $err);
        if ($err) return $this->json(['error' => $err], 422);
        $m->update($id, ['name' => $name]);
        return $this->json(['ok' => true, 'name' => $name]);
    }

    /** POST /api/keywords/{id}/collapse  {collapsed: bool} - remembered per node */
    public function collapse(int $id)
    {
        $m = new KeywordModel();
        if (! $m->findOwned($id, $this->uid())) return $this->json(['error' => '키워드가 없습니다.'], 404);
        $in = $this->request->getJSON(true) ?? [];
        $m->update($id, ['collapsed' => ! empty($in['collapsed']) ? 1 : 0]);
        return $this->json(['ok' => true]);
    }

    /** POST /api/keywords/collapse-all  {collapsed: bool} */
    public function collapseAll()
    {
        $in = $this->request->getJSON(true) ?? [];
        (new KeywordModel())->builder()->where('user_id', $this->uid())->where('parent_id', null)->update(['collapsed' => ! empty($in['collapsed']) ? 1 : 0]);
        return $this->json(['ok' => true]);
    }

    /** POST /api/keywords/reorder  {ids: [..]} - sort = position, for ids the user owns */
    public function reorder()
    {
        $in  = $this->request->getJSON(true) ?? [];
        $ids = array_values(array_unique(array_map('intval', (array) ($in['ids'] ?? []))));
        if ($ids === []) return $this->json(['ok' => true]);
        $m = new KeywordModel();
        foreach ($ids as $i => $id) {
            $m->builder()->where('user_id', $this->uid())->where('id', $id)->update(['sort' => $i]);
        }
        return $this->json(['ok' => true]);
    }

    /** POST /api/keywords/{id}/delete - a group takes its members with it */
    public function delete(int $id)
    {
        $m = new KeywordModel();
        if (! $m->findOwned($id, $this->uid())) return $this->json(['error' => '키워드가 없습니다.'], 404);
        foreach ($m->where('user_id', $this->uid())->groupStart()->where('id', $id)->orWhere('parent_id', $id)->groupEnd()->findAll() as $r) {
            if ($r['image']) { foreach (glob(KeywordModel::imageDir($r) . '/*') ?: [] as $old) @unlink($old); @rmdir(KeywordModel::imageDir($r)); }
        }
        $m->where('user_id', $this->uid())->where('parent_id', $id)->delete();
        $m->delete($id);
        return $this->json(['ok' => true]);
    }

    /* ---------------- reference photo (500x500 crop of an uploaded image) ---------------- */

    private const CROP_MAX = 3000;   // a 1:1 crop larger than this is scaled down to it
    private const IMAGE_MAX = 20 * 1024 * 1024;

    /** GET /keywords/{id}/image/thumb|orig - streams the file (small, so no X-Accel needed). */
    public function image(int $id, string $kind = 'thumb')
    {
        $row = (new KeywordModel())->findOwned($id, $this->uid());
        if (! $row || ! $row['image']) throw PageNotFoundException::forPageNotFound();
        $dir  = KeywordModel::imageDir($row);
        $file = $kind === 'orig' ? $dir . '/' . $row['image'] : $dir . '/thumb.jpg';
        if (! is_file($file)) throw PageNotFoundException::forPageNotFound();
        $mime = $kind === 'orig' ? (mime_content_type($file) ?: 'application/octet-stream') : 'image/jpeg';
        return $this->response->setHeader('Content-Type', $mime)->setHeader('Cache-Control', 'private, max-age=86400')
            ->setHeader('Content-Length', (string) filesize($file))->setBody((string) file_get_contents($file));
    }

    /** POST /api/keywords/{id}/image  multipart: file + crop (JSON {x,y,w,h} in source pixels, after EXIF rotation); the 1:1 crop is kept at full size */
    public function imageUpload(int $id)
    {
        $m   = new KeywordModel();
        $row = $m->findOwned($id, $this->uid());
        if (! $row) return $this->json(['error' => '키워드가 없습니다.'], 404);
        $f = $this->request->getFile('file');
        if (! $f || ! $f->isValid()) return $this->json(['error' => '이미지 파일을 선택하세요.'], 422);
        if ($f->getSize() > self::IMAGE_MAX) return $this->json(['error' => '이미지는 20MB 이하만 올릴 수 있습니다.'], 422);
        $info = @getimagesize($f->getTempName());
        $ext  = match ($info[2] ?? 0) { IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp', default => null };
        if (! $ext) return $this->json(['error' => 'JPG, PNG, GIF, WEBP 이미지만 올릴 수 있습니다 (HEIC는 변환 후).'], 422);
        $dir = KeywordModel::imageDir($row);
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true)) return $this->json(['error' => '저장 폴더를 만들지 못했습니다.'], 500);
        foreach (glob($dir . '/*') ?: [] as $old) @unlink($old);
        $name = 'orig.' . $ext;
        $f->move($dir, $name, true);
        $crop = json_decode((string) $this->request->getPost('crop'), true);
        try {
            $crop = $this->makeThumb($dir . '/' . $name, $dir . '/thumb.jpg', is_array($crop) ? $crop : null);
        } catch (\Throwable $e) {
            @unlink($dir . '/' . $name);
            return $this->json(['error' => '이미지를 처리하지 못했습니다: ' . $e->getMessage()], 422);
        }
        $m->update($id, ['image' => $name, 'crop' => json_encode($crop), 'image_at' => date('Y-m-d H:i:s')]);
        return $this->json(['ok' => true, 'imgv' => time(), 'crop' => $crop]);
    }

    /** POST /api/keywords/{id}/image/crop  {x,y,w,h} - a new 500x500 from the stored original */
    public function imageCrop(int $id)
    {
        $m   = new KeywordModel();
        $row = $m->findOwned($id, $this->uid());
        if (! $row || ! $row['image']) return $this->json(['error' => '사진이 없습니다.'], 404);
        $dir = KeywordModel::imageDir($row);
        try {
            $crop = $this->makeThumb($dir . '/' . $row['image'], $dir . '/thumb.jpg', $this->request->getJSON(true) ?: null);
        } catch (\Throwable $e) {
            return $this->json(['error' => '이미지를 처리하지 못했습니다: ' . $e->getMessage()], 422);
        }
        $m->update($id, ['crop' => json_encode($crop), 'image_at' => date('Y-m-d H:i:s')]);
        return $this->json(['ok' => true, 'imgv' => time(), 'crop' => $crop]);
    }

    /** POST /api/keywords/{id}/image/delete */
    public function imageDelete(int $id)
    {
        $m   = new KeywordModel();
        $row = $m->findOwned($id, $this->uid());
        if (! $row) return $this->json(['error' => '키워드가 없습니다.'], 404);
        $dir = KeywordModel::imageDir($row);
        foreach (glob($dir . '/*') ?: [] as $old) @unlink($old);
        @rmdir($dir);
        $m->update($id, ['image' => null, 'crop' => null, 'image_at' => null]);
        return $this->json(['ok' => true]);
    }

    /**
     * Writes the 1:1 crop at its real size (the centred largest square when none is given;
     * clamped to the picture). Coordinates refer to the EXIF-upright picture, which is what the
     * browser showed the user. Returns the crop actually used.
     */
    private function makeThumb(string $src, string $dst, ?array $crop): array
    {
        $img = \Config\Services::image('gd')->withFile($src);
        try { $img->reorient(); } catch (\Throwable $e) { /* no EXIF */ }
        $W = $img->getWidth(); $H = $img->getHeight();
        $side = min($W, $H);
        $x = (int) round((float) ($crop['x'] ?? ($W - $side) / 2));
        $y = (int) round((float) ($crop['y'] ?? ($H - $side) / 2));
        $w = (int) round((float) ($crop['w'] ?? $side));
        $w = max(16, min($w, $side));
        $x = max(0, min($x, $W - $w));
        $y = max(0, min($y, $H - $w));
        $img->crop($w, $w, $x, $y);
        if ($w > self::CROP_MAX) $img->resize(self::CROP_MAX, self::CROP_MAX);
        $img->convert(IMAGETYPE_JPEG)->save($dst, 90);
        return ['x' => $x, 'y' => $y, 'w' => $w, 'h' => $w];
    }

    /** POST /api/keywords/seed - loads the starter set when the user has no keywords yet */
    public function seed()
    {
        $m = new KeywordModel();
        if ($m->where('user_id', $this->uid())->countAllResults() > 0) return $this->json(['error' => '이미 키워드가 있습니다.'], 422);
        $now = date('Y-m-d H:i:s');
        foreach (self::DEFAULTS as $gi => $lines) {
            $pid = $m->insert(['user_id' => $this->uid(), 'parent_id' => null, 'name' => $lines[0], 'sort' => $gi, 'collapsed' => 0, 'created_at' => $now, 'updated_at' => $now]);
            foreach (array_slice($lines, 1) as $ci => $name) {
                $m->insert(['user_id' => $this->uid(), 'parent_id' => $pid, 'name' => $name, 'sort' => $ci, 'collapsed' => 0, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
        return $this->json(['ok' => true, 'tree' => $m->tree($this->uid())]);
    }
}
