<?php

namespace Rss\Vlog\Adapters\Laravel;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Log\LogManager;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Rss\Vlog\Adapters\Laravel\Http\ApiController;
use Rss\Vlog\Adapters\Laravel\Http\Authorize;
use Rss\Vlog\Adapters\Laravel\Http\BrowserErrorController;
use Rss\Vlog\Adapters\Laravel\Http\InjectBrowserErrors;
use Rss\Vlog\Adapters\Laravel\Http\ManageController;
use Rss\Vlog\Adapters\Laravel\Http\UiController;
use Rss\Vlog\Core\Browser\BrowserErrorLog;
use Rss\Vlog\Core\Files\LogFileFinder;
use Rss\Vlog\Core\Http\LogViewerApi;
use Rss\Vlog\Core\Http\ManageApi;
use Rss\Vlog\Core\Security\Redactor;
use Rss\Vlog\Core\Source\SourcePreview;

/**
 * Registers Vlog's configuration, access gate and routes in Laravel.
 */
final class VlogServiceProvider extends ServiceProvider
{
    private const CONFIG_PATH = __DIR__.'/../../../config/vlog.php';

    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG_PATH, 'vlog');

        $this->app->bind(LogViewerApi::class, function (Application $app): LogViewerApi {
            $config = $this->config($app);

            return new LogViewerApi(
                $this->finder($config),
                $this->indexPath($app, $config),
                (float) $this->number($config->get('vlog.time_limit'), 2.0),
                (int) $this->number($config->get('vlog.max_entry_bytes'), 1048576),
                $this->redactor($config)
            );
        });

        $this->app->bind(ManageApi::class, function (Application $app): ManageApi {
            $config = $this->config($app);

            return new ManageApi(
                $this->finder($config),
                $this->indexPath($app, $config),
                function (string $level, string $message, array $context) use ($app): void {
                    /** @var LogManager $logger */
                    $logger = $app->make('log');
                    $logger->log($level, $message, array_merge($context, ['written_with' => 'vlog']));
                },
                self::sourcePreviewEnabled($config)
                    ? new SourcePreview($this->stringList($config->get('vlog.source.paths', [$app->basePath()])))
                    : null,
                $this->redactor($config)
            );
        });

        $this->app->bind(BrowserErrorLog::class, function (Application $app): BrowserErrorLog {
            return new BrowserErrorLog(
                $this->string($this->config($app)->get('vlog.browser_errors.path'), $app->storagePath().'/logs/browser-errors.log')
            );
        });
    }

    private function redactor(Repository $config): Redactor
    {
        return new Redactor(
            $config->get('vlog.redact.enabled', true) !== false,
            $this->stringList($config->get('vlog.redact.keys', [])),
            $this->stringList($config->get('vlog.redact.patterns', []))
        );
    }

    /**
     * Determine whether stack frames can be previewed.
     */
    public static function sourcePreviewEnabled(Repository $config): bool
    {
        return self::boolean($config->get('vlog.source.enabled', true), true);
    }

    /**
     * Determine whether browser errors are captured. By default, they're only
     * captured in the local environment.
     */
    private function browserErrorsEnabled(Repository $config): bool
    {
        return self::boolean($config->get('vlog.browser_errors.enabled'), $this->app->environment('local') === true);
    }

    /**
     * Get a config value as a boolean, or the default when it's empty.
     * Values from environment variables can arrive as strings such as "false".
     *
     * @param  mixed  $value
     */
    private static function boolean($value, bool $default): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    private function config(Application $app): Repository
    {
        /** @var Repository $config */
        $config = $app->make('config');

        return $config;
    }

    private function finder(Repository $config): LogFileFinder
    {
        return new LogFileFinder(
            $this->stringList($config->get('vlog.paths')),
            $this->stringList($config->get('vlog.patterns', ['*.log'])),
            (int) $this->number($config->get('vlog.max_depth'), 3)
        );
    }

    private function indexPath(Application $app, Repository $config): string
    {
        return $this->string($config->get('vlog.index_path'), $app->storagePath().'/framework/vlog');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([self::CONFIG_PATH => $this->app->configPath('vlog.php')], 'vlog-config');
        }

        // Applications define their own gate in a service provider, which
        // boots after this one and replaces this default.
        foreach (['viewVlog', 'manageVlog'] as $ability) {
            if (! Gate::has($ability)) {
                Gate::define($ability, function ($user = null): bool {
                    return $this->app->environment('local') === true;
                });
            }
        }

        $this->registerRoutes();
    }

    private function registerRoutes(): void
    {
        /** @var Repository $config */
        $config = $this->app->make('config');

        if (! self::boolean($config->get('vlog.enabled', true), true)) {
            return;
        }

        /** @var Router $router */
        $router = $this->app->make('router');
        $browserErrors = $this->browserErrorsEnabled($config);

        if ($browserErrors) {
            $this->addToWebMiddleware($router);
        }

        if ($this->app instanceof CachesRoutes && $this->app->routesAreCached()) {
            return;
        }

        $prefix = trim($this->string($config->get('vlog.path'), 'vlog'), '/');

        // Reports come from any page of the application and are sent without
        // cookies, so this route has no session, CSRF or access checks.
        if ($browserErrors) {
            $route = $router->post($prefix.'/browser-errors', [BrowserErrorController::class, 'store'])->name('vlog.browser');
            $domain = $config->get('vlog.domain');

            if (is_string($domain) && $domain !== '') {
                $route->domain($domain);
            }
        }

        $router->group([
            'prefix' => $prefix,
            'domain' => $config->get('vlog.domain'),
            'middleware' => array_merge($this->stringList($config->get('vlog.middleware', ['web'])), [Authorize::class]),
            'as' => 'vlog.',
        ], function (Router $router): void {
            $router->get('/', [UiController::class, 'show'])->name('index');
            $router->get('api/files', [ApiController::class, 'files'])->name('api.files');
            $router->get('api/files/{file}', [ApiController::class, 'file'])->name('api.file');
            $router->get('api/files/{file}/entries', [ApiController::class, 'entries'])->name('api.entries');
            $router->get('api/files/{file}/search', [ApiController::class, 'search'])->name('api.search');
            $router->get('api/files/{file}/export', [ApiController::class, 'export'])->name('api.export');
            $router->get('api/files/{file}/groups', [ApiController::class, 'groups'])->name('api.groups');
            $router->get('api/search', [ApiController::class, 'searchAll'])->name('api.search_all');

            $router->get('api/bookmarks', [ApiController::class, 'bookmarks'])->name('api.bookmarks');
            $router->post('api/bookmarks', [ApiController::class, 'addBookmark'])->name('api.bookmarks.store');
            $router->patch('api/bookmarks/{bookmark}', [ApiController::class, 'updateBookmark'])->name('api.bookmarks.update');
            $router->delete('api/bookmarks/{bookmark}', [ApiController::class, 'deleteBookmark'])->name('api.bookmarks.destroy');

            $router->get('api/files/{file}/download', [ManageController::class, 'download'])->name('api.download');
            $router->post('api/files/{file}/clear', [ManageController::class, 'clear'])->name('api.clear');
            $router->delete('api/files/{file}', [ManageController::class, 'delete'])->name('api.delete');
            $router->post('api/entries', [ManageController::class, 'write'])->name('api.write');
            $router->get('api/source', [ManageController::class, 'source'])->name('api.source');
        });
    }

    /**
     * Add the browser error script's middleware to the "web" group.
     *
     * The HTTP kernel copies its own middleware groups to the router, which
     * would drop middleware added only to the router, so it's added through
     * the kernel when the kernel allows it.
     */
    private function addToWebMiddleware(Router $router): void
    {
        $kernel = $this->app->make(HttpKernel::class);

        if ($kernel instanceof Kernel) {
            $kernel->appendMiddlewareToGroup('web', InjectBrowserErrors::class);

            return;
        }

        $router->pushMiddlewareToGroup('web', InjectBrowserErrors::class);
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    private function stringList($value): array
    {
        return array_values(array_filter((array) $value, 'is_string'));
    }

    /**
     * Get a config value as a string, or the default when it isn't one.
     *
     * @param  mixed  $value
     */
    private function string($value, string $default): string
    {
        return is_string($value) && $value !== '' ? $value : $default;
    }

    /**
     * Get a config value as a number, or the default when it isn't one.
     * Values from environment variables arrive as numeric strings.
     *
     * @param  mixed  $value
     * @param  int|float  $default
     * @return int|float
     */
    private function number($value, $default)
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        return is_string($value) && is_numeric($value) ? $value + 0 : $default;
    }
}
