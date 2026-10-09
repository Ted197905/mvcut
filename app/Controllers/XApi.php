<?php

namespace App\Controllers;

use App\Libraries\XWatch;

/**
 * Endpoints for the MV Cut X Chrome extension. Authenticated by the per-user token
 * (Authorization: Bearer mvx_...), not by the session, and exempt from CSRF.
 */
class XApi extends BaseController
{
    private function watch(): ?array
    {
        $h = (string) $this->request->getHeaderLine('Authorization');
        $bearer = preg_match('/^Bearer\s+(\S+)$/i', $h, $m) ? $m[1] : '';
        return XWatch::byToken($bearer);
    }

    private function deny()
    {
        return $this->response->setStatusCode(401)->setJSON(['error' => 'invalid token']);
    }

    /** GET /xapi/status */
    public function status()
    {
        $w = $this->watch();
        if (! $w) return $this->deny();
        return $this->response->setJSON(XWatch::status($w));
    }

    /** POST /xapi/posts  {"posts":[{id,handle,time,kind,text,reply_to}]} */
    public function posts()
    {
        $w = $this->watch();
        if (! $w) return $this->deny();
        $body = json_decode((string) $this->request->getBody(), true);
        $posts = is_array($body['posts'] ?? null) ? $body['posts'] : [];
        $new = XWatch::savePosts($w, $posts);
        return $this->response->setJSON(['saved' => $new] + XWatch::status($w));
    }
}
