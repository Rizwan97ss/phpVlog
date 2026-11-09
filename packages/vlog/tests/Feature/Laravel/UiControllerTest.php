<?php

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;

it('serves the viewer page with the API URL', function () {
    Gate::define('viewVlog', function ($user = null): bool {
        return true;
    });

    $response = $this->get('/vlog');

    $response->assertOk()
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertSee('<div id="vlog"></div>', false)
        ->assertSee('"apiUrl":"/vlog/api","title":"Vlog"', false);
    expect($response->headers->get('Content-Type'))->toBe('text/html; charset=utf-8');
});

it('tells the interface whether the user can manage logs', function (bool $canManage) {
    Gate::define('viewVlog', function ($user = null): bool {
        return true;
    });
    Gate::define('manageVlog', function ($user = null) use ($canManage): bool {
        return $canManage;
    });

    $this->get('/vlog')->assertSee('"canManage":'.($canManage ? 'true' : 'false'), false);
})->with([
    'allowed' => [true],
    'not allowed' => [false],
]);

it('forbids the viewer page when the gate denies access', function () {
    $this->get('/vlog')->assertForbidden();
});

it('uses the Content Security Policy nonce the application gave Vite', function () {
    Gate::define('viewVlog', function ($user = null): bool {
        return true;
    });
    Vite::useCspNonce('test-nonce');

    $this->get('/vlog')
        ->assertOk()
        ->assertSee('<script type="module" nonce="test-nonce">', false)
        ->assertSee('<style nonce="test-nonce">', false);
});

it('tells the interface whether code can be previewed and which editor to use', function (bool $canManage, bool $enabled, string $expected) {
    Gate::define('viewVlog', function ($user = null): bool {
        return true;
    });
    Gate::define('manageVlog', function ($user = null) use ($canManage): bool {
        return $canManage;
    });
    config(['vlog.source.enabled' => $enabled, 'vlog.editor' => 'phpstorm']);

    $this->get('/vlog')
        ->assertSee('"canPreviewSource":'.$expected, false)
        ->assertSee('"editor":"phpstorm"', false);
})->with([
    'manager' => [true, true, 'true'],
    'manager with previews turned off' => [true, false, 'false'],
    'viewer' => [false, true, 'false'],
]);
