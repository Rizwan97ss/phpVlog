<?php

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Rss\Vlog\Adapters\Laravel\VlogServiceProvider;

/**
 * Add application routes that return an HTML page, JSON and a download.
 */
function vlogDefineAppRoutes(): void
{
    Route::middleware('web')->get('/page', function () {
        return '<html><head><title>App</title></head><body>Page</body></html>';
    });
    Route::middleware('web')->get('/data', function () {
        return response()->json(['html' => '<head></head>']);
    });
    Route::middleware('web')->get('/export', function () {
        return response('<html><head></head></html>', 200, ['Content-Disposition' => 'attachment; filename="page.html"']);
    });
}

/**
 * Send a report the way the browser script does: a plain-text JSON body
 * without cookies.
 *
 * @param  array<string, string>  $server
 */
function vlogSendReport($test, string $body, array $server = []): TestResponse
{
    return $test->call('POST', '/vlog/browser-errors', [], [], [], array_merge([
        'CONTENT_TYPE' => 'text/plain',
        'HTTP_ORIGIN' => 'http://localhost',
    ], $server), $body);
}

describe('when enabled', function () {
    beforeEach(function () {
        config([
            'vlog.browser_errors.enabled' => true,
            'vlog.browser_errors.path' => $this->logDirectory.DIRECTORY_SEPARATOR.'browser-errors.log',
        ]);
        (new VlogServiceProvider($this->app))->boot();
        vlogDefineAppRoutes();
    });

    it('adds the error script to the application\'s HTML pages', function () {
        $this->get('/page')
            ->assertOk()
            ->assertSee('<script data-vlog-browser>', false)
            ->assertSee('"/vlog/browser-errors"', false);
    });

    it('leaves other responses unchanged', function (string $uri, array $headers) {
        Gate::define('viewVlog', function ($user = null): bool {
            return true;
        });

        $this->get($uri, $headers)->assertDontSee('data-vlog-browser', false);
    })->with([
        'JSON' => ['/data', []],
        'download' => ['/export', []],
        'Inertia visit' => ['/page', ['X-Inertia' => 'true']],
        'Vlog itself' => ['/vlog', []],
    ]);

    it('writes reports to the browser log without needing a session or CSRF token', function () {
        vlogSendReport($this, (string) json_encode(['errors' => [['type' => 'error', 'message' => 'Uncaught Error: Boom', 'url' => 'http://localhost/page']]]))
            ->assertNoContent();

        expect(file_get_contents($this->logDirectory.DIRECTORY_SEPARATOR.'browser-errors.log'))
            ->toContain('browser.ERROR: Uncaught Error: Boom');
    });

    it('shows the browser log in Vlog', function () {
        Gate::define('viewVlog', function ($user = null): bool {
            return true;
        });

        vlogSendReport($this, (string) json_encode(['errors' => [['message' => 'Uncaught Error: Boom']]]));

        $this->getJson('/vlog/api/files')->assertJsonPath('data.0.name', 'browser-errors.log');
    });

    it('refuses reports from other sites', function (array $server) {
        vlogSendReport($this, (string) json_encode(['errors' => [['message' => 'Forged']]]), $server)->assertForbidden();

        expect(is_file($this->logDirectory.DIRECTORY_SEPARATOR.'browser-errors.log'))->toBeFalse();
    })->with([
        'other origin' => [['HTTP_ORIGIN' => 'https://attacker.test']],
        'cross-site fetch' => [['HTTP_SEC_FETCH_SITE' => 'cross-site']],
    ]);

    it('limits how many reports one visitor can send', function () {
        $body = (string) json_encode(['errors' => [['message' => 'Boom']]]);

        for ($report = 1; $report <= 30; $report++) {
            vlogSendReport($this, $body)->assertNoContent();
        }

        vlogSendReport($this, $body)->assertStatus(429);
    });
});

describe('by default', function () {
    it('captures nothing outside the local environment', function () {
        vlogDefineAppRoutes();

        $this->get('/page')->assertDontSee('data-vlog-browser', false);
        vlogSendReport($this, (string) json_encode(['errors' => [['message' => 'Boom']]]))->assertNotFound();
    });
});
