<?php

namespace App\Controllers;

use App\Libraries\AppLog;
use App\Libraries\XWatch;

/** /admin/logs - the site-wide log (app_log); POST /log/js - browser errors reported by the layout script. */
class Logs extends BaseController
{
    public function index()
    {
        $db = db_connect();
        $since = gmdate('Y-m-d H:i:s', time() - 7 * 86400);
        $areas = array_column($db->table('app_log')->distinct()->select('area')->where('ts >', $since)->orderBy('area')->get()->getResultArray(), 'area');
        $users = $db->table('users')->select('id, display_name')->orderBy('id')->get()->getResultArray();
        return view('admin/logs', ['title' => '로그', 'areas' => $areas, 'users' => $users]);
    }

    /**
     * GET /admin/logs/data?after=ID&level=warn|error&area=&user=&q=&hours=24&req=
     * Newest first; with after=ID only rows newer than that id (polling).
     */
    public function data()
    {
        $g = $this->request->getGet();
        $b = db_connect()->table('app_log');
        $after = (int) ($g['after'] ?? 0);
        if ($after > 0) $b->where('id >', $after);
        $hours = (int) ($g['hours'] ?? 24);
        if ($hours > 0 && $after === 0) $b->where('ts >', gmdate('Y-m-d H:i:s', time() - $hours * 3600));
        if (($g['level'] ?? '') === 'warn') $b->whereIn('level', ['warn', 'error']);
        elseif (($g['level'] ?? '') === 'error') $b->where('level', 'error');
        if (! empty($g['area']) && preg_match('/^[\w-]{1,24}$/', $g['area'])) $b->where('area', $g['area']);
        if (! empty($g['user'])) $b->where('user_id', (int) $g['user']);
        if (! empty($g['req']) && preg_match('/^[0-9a-f]{6,12}$/', $g['req'])) $b->where('req', $g['req']);
        $q = trim((string) ($g['q'] ?? ''));
        if ($q !== '') $b->groupStart()->like('msg', $q)->orLike('action', $q)->orLike('detail', $q)->groupEnd();
        $rows = $b->orderBy('id', 'DESC')->get($after > 0 ? 500 : 300)->getResultArray();
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['id' => (int) $r['id'], 't' => XWatch::kst(strtotime($r['ts'] . ' UTC'), 'n/j H:i:s'), 'lv' => $r['level'], 'area' => $r['area'],
                      'action' => $r['action'], 'user' => $r['user_id'] ? (int) $r['user_id'] : null, 'req' => $r['req'], 'ip' => $r['ip'],
                      'msg' => $r['msg'], 'detail' => $r['detail']];
        }
        return $this->response->setJSON(['items' => $out]);
    }

    /** POST /log/js  msg, detail, url - an uncaught browser error (max a few per page load, see layouts/main.php). */
    public function js()
    {
        $msg = trim((string) $this->request->getPost('msg'));
        if ($msg === '') return $this->response->setStatusCode(204);
        $url = (string) $this->request->getPost('url');
        $detail = mb_substr((string) $this->request->getPost('detail'), 0, 4000) . "\n\nurl: " . mb_substr($url, 0, 500)
                . "\nua: " . mb_substr((string) $this->request->getUserAgent(), 0, 200);
        AppLog::error('browser', mb_substr(preg_replace('#^https?://[^/]+#', '', $url), 0, 120), $msg, $detail);
        return $this->response->setStatusCode(204);
    }
}
