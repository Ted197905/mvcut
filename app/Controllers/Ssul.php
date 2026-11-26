<?php

namespace App\Controllers;

use App\Libraries\Ops;
use CodeIgniter\Exceptions\PageNotFoundException;

/**
 * /ssul - admin only. AI 썰 for X (hallyuwire ssul.py / trends.py): the trend keywords collected every 10 minutes,
 * jobs made from a trend or a keyword of one's own (a 썰, or a researched trend article), their results to edit,
 * copy or send to the drafts. A job is a row in hallyuwire.ssul_jobs (status queued); the model worker writes it.
 */
class Ssul extends BaseController
{
    public const ACCOUNTS = ['blackout' => '@blackout_kr', 'maeum' => '마음자로', 'hue' => 'HUE 한류분석'];
    public const KINDS    = ['trend' => '트렌드 썰', 'keyword' => '키워드 썰', 'article' => '트렌드 기사'];
    public const MODES    = ['remix' => '실제 썰 재구성', 'create' => '창작'];
    public const TYPES    = ['auto' => '자동', 'twist' => '반전 썰', 'dialog' => '대화체', 'relate' => '공감 일상', 'gap' => '기대 vs 현실', 'mistake' => '실수/교훈',
                             'arc' => '변화 스토리', 'contra' => '통념 반박', 'list' => '리스트', 'analysis' => '분석 분해'];
    public const ORIGINS  = ['community' => '커뮤니티', 'google' => '구글', 'nate' => '네이트', 'signal' => '시그널', 'x' => 'X'];
    private const STATUS  = ['queued' => ['대기', 'warn'], 'running' => ['쓰는 중', 'warn'], 'done' => ['완료', 'ok'], 'failed' => ['실패', 'bad']];

    private function t(string $table): string
    {
        return env('hallyuwire.db', 'hallyuwire') . '.' . $table;
    }

    private function job(int $id): array
    {
        $j = db_connect()->table($this->t('ssul_jobs'))->where('id', $id)->get()->getRowArray();
        if (! $j) throw PageNotFoundException::forPageNotFound();
        return $j;
    }

    private function setting(string $name, string $default = ''): string
    {
        $r = db_connect()->table($this->t('settings'))->where('name', $name)->get()->getRowArray();
        return $r ? (string) $r['value'] : $default;
    }

    /** A job as the page shows it (also the status poll's answer). */
    private function present(array $j): array
    {
        $meta = json_decode((string) $j['meta'], true) ?: [];
        [$label, $cls] = self::STATUS[$j['status']] ?? [$j['status'], ''];
        $notes = array_filter([$meta['note'] ?? '', $meta['warn'] ?? '']);
        if (! empty($meta['material'])) $notes[] = '소재: ' . $meta['material']['title'];
        if (! empty($meta['research'])) $notes[] = '자료: 뉴스 ' . (int) $meta['research']['news'] . '건 (전문 ' . (int) $meta['research']['read'] . '건), 커뮤니티 제목 ' . (int) $meta['research']['titles'] . '건';
        $made = ($meta['mode'] ?? $j['mode']);
        return [
            'id' => (int) $j['id'], 'status' => $j['status'], 'label' => $label, 'cls' => $cls,
            'kind' => self::KINDS[$j['kind']] ?? $j['kind'], 'isArticle' => $j['kind'] === 'article',
            'mode' => $j['kind'] === 'article' ? '자료 기반' : ($made === 'facts' ? '자료 기반' : (self::MODES[$made] ?? $made)),
            'made' => $made, 'account' => self::ACCOUNTS[$j['account']] ?? $j['account'],
            'ptype' => self::TYPES[$meta['ptype'] ?? $j['ptype']] ?? '', 'fmt' => $meta['fmt'] ?? '',
            'keyword' => $j['keyword'], 'note' => (string) $j['note'], 'auto' => (bool) $j['auto'],
            'title' => (string) $j['title'], 'body' => (string) $j['body'], 'reply' => (string) $j['reply'],
            'sources' => json_decode((string) $j['sources'], true) ?: [], 'material' => $meta['material'] ?? null,
            'notes' => array_values($notes), 'error' => (string) $j['error'],
            'draft' => $j['draft_id'] ? (int) $j['draft_id'] : ((int) $j['to_draft'] ? -1 : 0),
            'when' => Collect::when($j['done_at'] ?: $j['created_at']),
        ];
    }

    public function index()
    {
        $db = db_connect();
        $last = $db->table($this->t('trend_keywords'))->selectMax('updated_at', 't')->get()->getRowArray()['t'] ?? null;
        $trends = $last ? $db->table($this->t('trend_keywords'))->where('updated_at', $last)->orderBy('score', 'DESC')->get(150)->getResultArray() : [];
        foreach ($trends as &$k) {
            $k['samples'] = json_decode((string) $k['samples'], true) ?: [];
            $k['new'] = strtotime($k['first_seen'] . ' UTC') > strtotime($last . ' UTC') - 3600;
        }
        unset($k);
        $jobs = array_map(fn ($j) => $this->present($j), $db->table($this->t('ssul_jobs'))->orderBy('id', 'DESC')->get(40)->getResultArray());
        $stats = $db->table($this->t('ssul_stats'))->where('name', 'patterns')->get()->getRowArray();
        $lists = $db->table($this->t('trend_fetch'))->select('COUNT(*) AS n, SUM(status = "ok") AS ok, MAX(fetched_at) AS at')->get()->getRowArray();
        return view('ssul/index', [
            'title' => 'AI 썰', 'trends' => $trends, 'trendAt' => $last, 'jobs' => $jobs,
            'stats' => $stats ? (json_decode((string) $stats['value'], true) ?: null) : null,
            'corpus' => $db->table($this->t('ssul_corpus'))->select('origin, COUNT(*) AS n')->groupBy('origin')->get()->getResultArray(),
            'lists' => $lists, 'collectOn' => Ops::on('collect'),
            'on' => ['trends' => Ops::on('trends'), 'ssul' => Ops::on('ssul')],
            'auto' => ['n' => (int) $this->setting('ssul_auto_n', '0'), 'account' => $this->setting('ssul_auto_account', 'blackout'), 'mode' => $this->setting('ssul_auto_mode', 'remix')],
            'names' => array_column($db->table($this->t('sources'))->select('id, name')->get()->getResultArray(), 'name', 'id'),
        ]);
    }

    /** POST /ssul/jobs  keyword, kind, mode, account, ptype, note */
    public function create()
    {
        $kw = trim(preg_replace('/\s+/u', ' ', (string) $this->request->getPost('keyword')));
        $kind = (string) $this->request->getPost('kind');
        $mode = (string) $this->request->getPost('mode');
        $acc  = (string) $this->request->getPost('account');
        $type = (string) $this->request->getPost('ptype');
        if ($kw === '' || mb_strlen($kw) > 200) return $this->fail('키워드를 넣어 주세요 (200자 이내).');
        if (! isset(self::KINDS[$kind])) $kind = 'keyword';
        if (! isset(self::MODES[$mode])) $mode = 'remix';
        if (! isset(self::ACCOUNTS[$acc])) $acc = 'blackout';
        if (! isset(self::TYPES[$type])) $type = 'auto';
        $db = db_connect();
        $db->table($this->t('ssul_jobs'))->insert([
            'kind' => $kind, 'mode' => $mode, 'account' => $acc, 'ptype' => $type, 'keyword' => $kw,
            'note' => mb_substr(trim((string) $this->request->getPost('note')), 0, 500) ?: null,
            'status' => 'queued', 'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $msg = Ops::on('ssul') ? '요청했습니다. 모델 워커가 차례대로 씁니다 (모델을 올리는 데 30초쯤, 한 건에 1~2분).' : '요청은 넣었지만 "AI 썰 작성" 스위치가 꺼져 있습니다 (설정 > 자동 기능).';
        if ($this->request->isAJAX()) return $this->response->setJSON(['ok' => true, 'msg' => $msg, 'job' => $this->present($this->job($id))]);
        return redirect()->to(site_url('ssul') . '#j' . $id)->with('flash', $msg);
    }

    /** GET /ssul/status?ids=1,2 - the jobs still being written, for the page's poll */
    public function status()
    {
        $ids = array_values(array_filter(array_map('intval', explode(',', (string) $this->request->getGet('ids')))));
        $rows = $ids ? db_connect()->table($this->t('ssul_jobs'))->whereIn('id', array_slice($ids, 0, 40))->get()->getResultArray() : [];
        return $this->response->setJSON(['jobs' => array_map(fn ($j) => $this->present($j), $rows)]);
    }

    /** The card's text stored. Returns an error or null. */
    private function store(int $id): ?string
    {
        $clean = fn (string $k) => str_replace("\r", '', trim((string) $this->request->getPost($k)));
        $title = $clean('title');
        $body  = $clean('body');
        if ($title === '' || $body === '') return '첫 줄과 본문은 비울 수 없습니다.';
        if (mb_strlen($title) > 500 || mb_strlen($body) + mb_strlen($clean('reply')) > 25000) return '너무 깁니다 (X 긴 글 한도 25,000자).';
        db_connect()->table($this->t('ssul_jobs'))->where('id', $id)->update(['title' => $title, 'body' => $body, 'reply' => $clean('reply')]);
        return null;
    }

    /** POST /ssul/{id}/save  title, body, reply */
    public function save(int $id)
    {
        $j = $this->job($id);
        if ($j['status'] !== 'done') return $this->fail('아직 완성되지 않은 글입니다.');
        if ($e = $this->store($id)) return $this->fail($e);
        if ($this->request->isAJAX()) return $this->response->setJSON(['ok' => true, 'msg' => '저장했습니다.']);
        return redirect()->to(site_url('ssul') . '#j' . $id)->with('flash', '저장했습니다.');
    }

    /** POST /ssul/{id}/draft  with=0|1 - saved first, then handed to the drafts (the model worker makes the draft within ~15 s) */
    public function draft(int $id)
    {
        $j = $this->job($id);
        if ($j['status'] !== 'done') return $this->fail('아직 완성되지 않은 글입니다.');
        if ($j['draft_id'] || (int) $j['to_draft']) return $this->fail('이미 초안으로 보냈습니다.');
        if ($this->request->getPost('title') !== null && ($e = $this->store($id))) return $this->fail($e);
        db_connect()->table($this->t('ssul_jobs'))->where('id', $id)->update(['to_draft' => $this->request->getPost('with') === '1' ? 2 : 1]);
        $msg = '초안으로 보냈습니다. 15초쯤 뒤 초안 메뉴의 오늘 배치에 "검토 대기"로 나타납니다.';
        if ($this->request->isAJAX()) return $this->response->setJSON(['ok' => true, 'msg' => $msg, 'job' => $this->present($this->job($id))]);
        return redirect()->to(site_url('ssul') . '#j' . $id)->with('flash', $msg);
    }

    /** POST /ssul/{id}/redo - written again with the same request */
    public function redo(int $id)
    {
        $j = $this->job($id);
        if (! in_array($j['status'], ['done', 'failed'], true)) return $this->fail('지금 쓰는 중입니다.');
        if ($j['draft_id'] || (int) $j['to_draft']) return $this->fail('초안으로 보낸 글은 다시 쓰지 않습니다. 같은 키워드로 새로 요청해 주세요.');
        db_connect()->table($this->t('ssul_jobs'))->where('id', $id)->update(['status' => 'queued', 'error' => null, 'auto' => 0]);
        if ($this->request->isAJAX()) return $this->response->setJSON(['ok' => true, 'msg' => '다시 씁니다.', 'job' => $this->present($this->job($id))]);
        return redirect()->to(site_url('ssul') . '#j' . $id)->with('flash', '다시 씁니다.');
    }

    /** POST /ssul/{id}/delete */
    public function delete(int $id)
    {
        $j = $this->job($id);
        if ($j['status'] === 'running') return $this->fail('지금 쓰는 중입니다.');
        db_connect()->table($this->t('ssul_jobs'))->where('id', $id)->delete();
        if ($this->request->isAJAX()) return $this->response->setJSON(['ok' => true, 'msg' => '삭제했습니다.']);
        return redirect()->to('/ssul')->with('flash', '삭제했습니다.');
    }

    /** POST /ssul/auto  n, account, mode - the automatic trend 썰 (every 6 hours, the top keywords without a job) */
    public function auto()
    {
        $n    = max(0, min(20, (int) $this->request->getPost('n')));
        $acc  = (string) $this->request->getPost('account');
        $mode = (string) $this->request->getPost('mode');
        $vals = ['ssul_auto_n' => (string) $n, 'ssul_auto_account' => isset(self::ACCOUNTS[$acc]) ? $acc : 'blackout', 'ssul_auto_mode' => isset(self::MODES[$mode]) ? $mode : 'remix'];
        $db = db_connect();
        foreach ($vals as $name => $value) {
            $db->query('INSERT INTO ' . $this->t('settings') . ' (name, value, updated_at) VALUES (?, ?, UTC_TIMESTAMP()) AS n '
                       . 'ON DUPLICATE KEY UPDATE value = n.value, updated_at = n.updated_at', [$name, $value]);
        }
        return redirect()->to('/ssul')->with('flash', $n ? "자동 생성: 6시간마다 상위 트렌드 {$n}건을 씁니다." : '자동 생성을 껐습니다.');
    }

    private function fail(string $msg)
    {
        if ($this->request->isAJAX()) return $this->response->setJSON(['ok' => false, 'error' => $msg]);
        return redirect()->to('/ssul')->with('error', $msg);
    }
}
