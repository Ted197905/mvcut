<?php

namespace App\Controllers;

use App\Models\KeywordModel;

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
        $m->where('user_id', $this->uid())->where('parent_id', $id)->delete();
        $m->delete($id);
        return $this->json(['ok' => true]);
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
