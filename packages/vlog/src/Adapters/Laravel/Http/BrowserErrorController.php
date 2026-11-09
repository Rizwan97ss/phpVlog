<?php

namespace Rss\Vlog\Adapters\Laravel\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;
use Rss\Vlog\Core\Browser\BrowserErrorLog;
use Throwable;

/**
 * Receives error reports from the script Vlog adds to the application's
 * pages.
 *
 * Any visitor can send reports, so they're only accepted from pages on this
 * site, are rate limited per visitor, and never reveal anything in the
 * response.
 */
final class BrowserErrorController
{
    /**
     * The most reports one visitor can send each minute.
     */
    private const REPORTS_PER_MINUTE = 30;

    private BrowserErrorLog $log;

    public function __construct(BrowserErrorLog $log)
    {
        $this->log = $log;
    }

    public function store(Request $request): Response
    {
        if (! $this->isFromThisSite($request)) {
            return new Response('', 403);
        }

        if ((int) $request->headers->get('Content-Length', '0') > BrowserErrorLog::MAX_BODY_BYTES) {
            return new Response('', 413);
        }

        if (! $this->withinRateLimit($request)) {
            return new Response('', 429);
        }

        $this->log->record((string) $request->getContent(), $request->userAgent());

        return new Response('', 204);
    }

    /**
     * Browsers send the page's origin with every report, so a report from a
     * page on another site can be told apart and refused.
     */
    private function isFromThisSite(Request $request): bool
    {
        if ($request->headers->get('Sec-Fetch-Site') === 'cross-site') {
            return false;
        }

        $origin = $request->headers->get('Origin');

        if ($origin === null || $origin === '') {
            return true;
        }

        return parse_url($origin, PHP_URL_HOST) === $request->getHost();
    }

    private function withinRateLimit(Request $request): bool
    {
        $key = 'vlog-browser-errors:'.sha1((string) $request->ip());

        try {
            if (RateLimiter::tooManyAttempts($key, self::REPORTS_PER_MINUTE)) {
                return false;
            }

            RateLimiter::hit($key, 60);
        } catch (Throwable $exception) {
            // Without a working cache, reports are still accepted. The script
            // sends at most 20 errors from each page.
        }

        return true;
    }
}
