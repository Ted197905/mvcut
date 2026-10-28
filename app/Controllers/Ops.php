<?php

namespace App\Controllers;

use App\Libraries\Ops as O;

/** /ops - the admin status bar's data and actions (see app/Views/partials/opsbar.php). */
class Ops extends BaseController
{
    /** GET /ops/status */
    public function status()
    {
        return $this->response->setJSON(O::status((int) session()->get('user_id')));
    }

    /** POST /ops/switch  name, on=0|1 */
    public function switch()
    {
        $name = (string) $this->request->getPost('name');
        if (! isset(O::PARTS[$name])) return $this->response->setStatusCode(400)->setJSON(['ok' => false, 'error' => 'unknown part']);
        O::setSwitch($name, $this->request->getPost('on') === '1');
        return $this->response->setJSON(['ok' => true]);
    }

    /** POST /ops/unload  model */
    public function unload()
    {
        $m = (string) $this->request->getPost('model');
        if (! preg_match('#^[\w./:-]{1,120}$#', $m)) return $this->response->setStatusCode(400)->setJSON(['ok' => false]);
        O::unload($m);
        return $this->response->setJSON(['ok' => true]);
    }
}
