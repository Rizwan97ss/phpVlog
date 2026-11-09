<?php

namespace Rss\Vlog\Adapters\Laravel\Http;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Rss\Vlog\Adapters\Laravel\VlogServiceProvider;
use Rss\Vlog\Core\Http\UiPage;

/**
 * Serves the viewer's page.
 */
final class UiController
{
    private UiPage $page;

    private UrlGenerator $url;

    private Container $container;

    public function __construct(UiPage $page, UrlGenerator $url, Container $container)
    {
        $this->page = $page;
        $this->url = $url;
        $this->container = $container;
    }

    public function show(Request $request): Response
    {
        // The API's files route, without its last segment, is where every API route starts.
        $apiUrl = substr($this->url->route('vlog.api.files', [], false), 0, -strlen('/files'));

        /** @var Repository $config */
        $config = $this->container->make('config');
        $canManage = Gate::check('manageVlog');
        $editor = $config->get('vlog.editor') ?: $config->get('app.editor');

        $html = $this->page->render($apiUrl, 'Vlog', $this->cspNonce(), [
            'canManage' => $canManage,
            'canPreviewSource' => $canManage && VlogServiceProvider::sourcePreviewEnabled($config),
            'editor' => is_string($editor) && $editor !== '' ? $editor : null,
            // The CSRF token needs a session, which the "web" middleware starts.
            'csrfToken' => $request->hasSession() ? $request->session()->token() : null,
        ]);

        return new Response($html, 200, $this->page->headers());
    }

    /**
     * Get the Content Security Policy nonce the application gave Laravel's
     * Vite integration, so the page works under a strict policy.
     */
    private function cspNonce(): ?string
    {
        $nonce = $this->container->make(Vite::class)->cspNonce();

        return is_string($nonce) && $nonce !== '' ? $nonce : null;
    }
}
