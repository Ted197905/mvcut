<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Moves a redirect's ->with('flash'|'error', ...) message into a plain session key that lives until
 * it is shown. Flashdata expires after one request, and thumbnails or API polls still in
 * flight from the previous page used to eat it before the next page rendered.
 */
class ToastFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        if (! $response instanceof RedirectResponse) return;
        $session = session();
        foreach (['flash' => 'toast', 'error' => 'toast_error'] as $flash => $keep) {
            $msg = $session->getFlashdata($flash);
            if ($msg === null || $msg === '') continue;
            $session->set($keep, $msg);
            $session->remove($flash);
        }
    }
}
