<?php

namespace Rss\Vlog\Adapters\Laravel\Http;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Rss\Vlog\Core\Browser\BrowserScript;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Adds the browser error script to the application's HTML pages.
 *
 * Only full HTML pages are changed. JSON, Inertia and other XHR responses,
 * downloads, streamed responses and Vlog's own pages are left alone.
 */
final class InjectBrowserErrors
{
    private Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            if ($this->shouldInject($request, $response)) {
                $this->inject($response);
            }
        } catch (Throwable $exception) {
            // The application's page matters more than the error script.
        }

        return $response;
    }

    private function shouldInject(Request $request, Response $response): bool
    {
        if (! $request->isMethod('GET') || $request->ajax() || $request->headers->has('X-Inertia') || $request->routeIs('vlog.*')) {
            return false;
        }

        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse || $response->headers->has('Content-Disposition')) {
            return false;
        }

        $contentType = strtolower((string) $response->headers->get('Content-Type', 'text/html'));

        return strpos($contentType, 'text/html') !== false;
    }

    private function inject(Response $response): void
    {
        /** @var Router $router */
        $router = $this->container->make('router');
        $content = $response->getContent();

        if (! is_string($content) || $content === '' || ! $router->has('vlog.browser')) {
            return;
        }

        /** @var UrlGenerator $url */
        $url = $this->container->make('url');
        $nonce = $this->container->make(Vite::class)->cspNonce();
        $tag = BrowserScript::tag($url->route('vlog.browser', [], false), is_string($nonce) && $nonce !== '' ? $nonce : null);
        $injected = BrowserScript::inject($content, $tag);

        if ($injected !== $content) {
            $response->setContent($injected);
            $response->headers->remove('Content-Length');
        }
    }
}
