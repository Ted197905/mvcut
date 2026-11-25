<?php

namespace App\Controllers;

/**
 * /xquote - X 인용 대상: Korean X posts that passed 100,000 views within 2 hours of posting, found by the collector
 * every 30 minutes (hallyuwire source x_quote, xsearch.run_quote). Picked here to quote while they are still rising.
 */
class XQuote extends BaseController
{
    private const SOURCE = 'x_quote';

    private function t(string $table): string
    {
        return env('hallyuwire.db', 'hallyuwire') . '.' . $table;
    }

    public function index()
    {
        $db    = db_connect();
        $hours = max(1, min(24, (int) ($this->request->getGet('h') ?: 3)));   // posts younger than this are listed
        $rows  = $db->table($this->t('items'))
            ->select('id, url, title, excerpt, author, views, likes, comments, tags, posted_at, first_seen, last_seen, used_at, thumb_url')
            ->where('source_id', self::SOURCE)->where('deleted_at', null)
            ->where('posted_at >', gmdate('Y-m-d H:i:s', time() - $hours * 3600))
            ->orderBy('views', 'DESC')->get(200)->getResultArray();
        $now = time();
        foreach ($rows as &$r) {
            $posted = strtotime($r['posted_at'] . ' UTC');
            $seen   = strtotime($r['last_seen'] . ' UTC');
            $r['age_min']  = (int) round(($now - $posted) / 60);
            $r['seen_min'] = (int) round(($now - $seen) / 60);
            // views per hour at the time it was last read
            $r['per_hour'] = (int) round((int) $r['views'] / max(0.25, ($seen - $posted) / 3600));
        }
        unset($r);
        $src = $db->table($this->t('sources'))->select('last_run_at, last_status, last_error, last_items, next_run_at, enabled')
            ->where('id', self::SOURCE)->get()->getRowArray();
        return view('xquote/index', ['title' => 'X 인용 대상', 'rows' => $rows, 'hours' => $hours, 'src' => $src,
                                     'on' => \App\Libraries\Ops::on('xquote')]);
    }

    /** POST /xquote/refresh - the collector runs the search at its next turn */
    public function refresh()
    {
        db_connect()->table($this->t('sources'))->where('id', self::SOURCE)->update(['next_run_at' => gmdate('Y-m-d H:i:s')]);
        return redirect()->to('/xquote')->with('flash', '조회를 요청했습니다. 수집기가 다음 차례에 X를 검색합니다 (1~3분, 08시 이전에는 돌지 않습니다).');
    }

    /** POST /xquote/{id}/used  on=0|1 - marks a post as quoted (dimmed in the list) */
    public function used(int $id)
    {
        $on = $this->request->getPost('on') === '1';
        db_connect()->table($this->t('items'))->where('id', $id)->where('source_id', self::SOURCE)
            ->update(['used_at' => $on ? gmdate('Y-m-d H:i:s') : null]);
        return $this->response->setJSON(['ok' => true]);
    }
}
