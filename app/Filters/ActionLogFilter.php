<?php

namespace App\Filters;

use App\Libraries\AppLog;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Logs every request that changes something (non-GET), every failed request (status >= 400 except
 * 404) and every slow one. Frequent polling/streaming endpoints are logged only when they fail.
 * Runs after ToastFilter, so a redirect's error message is in the session and gets logged too.
 */
class ActionLogFilter implements FilterInterface
{
    private const NOISY = '#^(api/upload/chunk|xapi/posts|xapi/scheduled|xwatch/purge/\d+|ops/status|log/js|collect/log|api/jobs(/\d+)?)$#';
    private const SLOW_MS = 8000;
    private const SECRET = '/pass|token|cookie|secret|csrf|authorization/i';

    public function before(RequestInterface $request, $arguments = null)
    {
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        try {
            $method = strtoupper($request->getMethod());
            $uri    = trim($request->getUri()->getPath(), '/');
            $status = $response->getStatusCode();
            $ms     = (int) round((microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true))) * 1000);
            $read   = in_array($method, ['GET', 'HEAD', 'OPTIONS'], true) || preg_match(self::NOISY, $uri);
            $failed = $status >= 400 && $status !== 404;
            if ($read && ! $failed && $ms < self::SLOW_MS) return;

            $toast = null;
            if ($response instanceof RedirectResponse) {
                try { $toast = session()->get('toast_error'); } catch (\Throwable) {}
            }
            $level = $status >= 500 ? 'error' : ($failed || $toast ? 'warn' : 'info');
            $msg   = $status . ' ' . $ms . 'ms';
            if ($toast) $msg .= ' | ' . $toast;
            elseif ($ms >= self::SLOW_MS) $msg .= ' | slow';

            $detail = ['status' => $status, 'ms' => $ms];
            $post = $request->getPost();
            if (is_array($post) && $post !== []) $detail['post'] = $this->mask($post);
            if ($method !== 'GET' && ! $post) {
                $body = (string) $request->getBody();
                if ($body !== '') $detail['body'] = mb_substr($body, 0, 2000);
            }
            $files = [];
            foreach ($request->getFiles() as $k => $f) {
                foreach (is_array($f) ? $f : [$f] as $one) {
                    if (is_object($one) && method_exists($one, 'getName')) $files[] = $k . ': ' . $one->getName() . ' (' . $one->getSize() . ' B, error ' . $one->getError() . ')';
                }
            }
            if ($files) $detail['files'] = $files;
            $q = $request->getUri()->getQuery();
            if ($q !== '') $detail['query'] = mb_substr($q, 0, 500);
            if ($response instanceof RedirectResponse) $detail['redirect'] = $response->getHeaderLine('Location');
            if ($failed && str_contains($response->getHeaderLine('Content-Type'), 'json')) $detail['response'] = mb_substr((string) $response->getBody(), 0, 1000);
            $detail['ua'] = mb_substr((string) $request->getUserAgent(), 0, 160);
            AppLog::write($level, 'web', $method . ' /' . $uri, $msg, $detail);
        } catch (\Throwable) {
            // never let logging break a response
        }
    }

    private function mask(array $a, int $depth = 0): array
    {
        $out = [];
        foreach ($a as $k => $v) {
            if (preg_match(self::SECRET, (string) $k)) { $out[$k] = '***'; continue; }
            if (is_array($v)) { $out[$k] = $depth < 2 ? $this->mask($v, $depth + 1) : '[array]'; continue; }
            $v = (string) $v;
            $out[$k] = mb_strlen($v) > 300 ? mb_substr($v, 0, 300) . '... (' . mb_strlen($v) . ' chars)' : $v;
        }
        return $out;
    }
}
