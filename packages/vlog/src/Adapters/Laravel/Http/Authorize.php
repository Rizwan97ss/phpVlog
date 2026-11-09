<?php

namespace Rss\Vlog\Adapters\Laravel\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Allows access only to users the "viewVlog" gate allows.
 *
 * Requests that change something must also send the "X-Vlog-Request"
 * header. Other sites can't send custom headers without the browser asking
 * this application first, so this blocks cross-site request forgery even
 * when the routes don't use Laravel's CSRF middleware.
 */
final class Authorize
{
    public const HEADER = 'X-Vlog-Request';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! Gate::check('viewVlog')) {
            abort(403);
        }

        if (! $request->isMethodSafe() && $request->headers->get(self::HEADER) !== '1') {
            abort(403, 'Missing the '.self::HEADER.' header.');
        }

        return $next($request);
    }
}
