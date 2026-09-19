<?php

namespace App\Controllers;

use App\Libraries\EditParams;
use App\Models\UserModel;

/** Editor settings kept per user, so they do not have to be set up again for every video. */
class Prefs extends BaseController
{
    /** POST /api/prefs/watermark  body: the watermark preset, or null to forget it. */
    public function watermark()
    {
        $userId = (int) session()->get('user_id');
        if ($userId <= 0) return $this->response->setStatusCode(401)->setJSON(['error' => 'unauthorized']);

        $raw = $this->request->getJSON(true);
        $wm  = is_array($raw) ? EditParams::watermarkPreset($raw) : null;
        (new UserModel())->savePref($userId, 'watermark', $wm);
        return $this->response->setJSON(['ok' => true, 'watermark' => $wm]);
    }
}
