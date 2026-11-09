<?php

use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    Gate::define('viewVlog', function ($user = null): bool {
        return true;
    });
});

/**
 * Get the API URL of the only log file.
 */
function vlogOnlyFileUrl($test): string
{
    return '/vlog/api/files/'.$test->getJson('/vlog/api/files')->json('data.0.id');
}

it('hides secrets in the message, the exception and its stack trace', function () {
    $this->writeLog('laravel.log', implode("\n", [
        '[2026-11-09 10:00:00] local.ERROR: Login failed for password=hunter2 {"exception":"[object] (RuntimeException(code: 0): Bad token=xyz789 at /app/Auth.php:5)',
        '[stacktrace]',
        "#0 /app/Auth.php(12): login('amy', 'secret=s3cr3t')",
        '#1 {main}',
        '"}',
    ])."\n");

    $response = $this->getJson(vlogOnlyFileUrl($this).'/entries');

    $response->assertOk()
        ->assertJsonPath('data.0.message', 'Login failed for password=[redacted]')
        ->assertJsonPath('data.0.stack.0.call', "login('amy', 'secret=[redacted]')");
    expect($response->json('data.0.context.exception'))->toContain('Bad token=[redacted] at');
    expect($response->getContent())->not->toContain('hunter2')->not->toContain('xyz789')->not->toContain('s3cr3t');
});

it('hides secrets in decoded context', function () {
    $this->writeLog('laravel.log', '[2026-11-09 10:00:00] local.ERROR: Login failed {"user":"amy","password":"hunter2","headers":{"Authorization":"Bearer abc.def.ghi"},"note":"retry with token=xyz789"}'."\n");

    $response = $this->getJson(vlogOnlyFileUrl($this).'/entries');

    $response->assertOk()
        ->assertJsonPath('data.0.context.user', 'amy')
        ->assertJsonPath('data.0.context.password', '[redacted]')
        ->assertJsonPath('data.0.context.headers.Authorization', '[redacted]')
        ->assertJsonPath('data.0.context.note', 'retry with token=[redacted]');
    expect($response->getContent())->not->toContain('hunter2')->not->toContain('xyz789');
});

it('shows secrets when hiding is turned off', function () {
    config(['vlog.redact.enabled' => false]);
    $this->writeLog('laravel.log', '[2026-11-09 10:00:00] local.ERROR: Login failed {"password":"hunter2"}'."\n");

    $this->getJson(vlogOnlyFileUrl($this).'/entries')
        ->assertJsonPath('data.0.context.password', 'hunter2');
});

it('hides extra keys and patterns from the config', function () {
    config(['vlog.redact.keys' => ['iban'], 'vlog.redact.patterns' => ['/ACME-\d{4}/']]);
    $this->writeLog('laravel.log', '[2026-11-09 10:00:00] local.INFO: Licence ACME-1234 used {"iban":"GB33BUKB20201555555555"}'."\n");

    $this->getJson(vlogOnlyFileUrl($this).'/entries')
        ->assertJsonPath('data.0.message', 'Licence [redacted] used')
        ->assertJsonPath('data.0.context.iban', '[redacted]');
});

it('returns log content that looks like HTML as plain JSON text', function () {
    $this->writeLog('laravel.log', '[2026-11-09 10:00:00] local.ERROR: <script>alert("x")</script> {"html":"<img src=x onerror=alert(1)>"}'."\n");

    $response = $this->getJson(vlogOnlyFileUrl($this).'/entries');

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/json; charset=utf-8')
        ->assertJsonPath('data.0.message', '<script>alert("x")</script>');
});

it('returns an empty page instead of failing for very large page numbers', function () {
    $this->writeLog('laravel.log', '[2026-11-09 10:00:00] local.INFO: Only entry'."\n");

    $this->getJson(vlogOnlyFileUrl($this).'/entries?page='.PHP_INT_MAX)
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('rejects requests that change something without the Vlog request header', function () {
    $path = $this->writeLog('laravel.log', '[2026-11-09 10:00:00] local.INFO: Only entry'."\n");

    $this->deleteJson(vlogOnlyFileUrl($this))->assertForbidden();
    $this->postJson('/vlog/api/entries', ['message' => 'Hello'])->assertForbidden();

    expect(is_file($path))->toBeTrue();
});

it('rejects methods the routes do not accept', function () {
    $this->writeLog('laravel.log', '[2026-11-09 10:00:00] local.INFO: Only entry'."\n");

    $this->withHeaders(['X-Vlog-Request' => '1'])->postJson('/vlog/api/files')->assertStatus(405);
    $this->withHeaders(['X-Vlog-Request' => '1'])->putJson(vlogOnlyFileUrl($this))->assertStatus(405);
});

it('does not reveal the index folder when the index cannot be written', function () {
    $this->writeLog('laravel.log', '[2026-11-09 10:00:00] local.INFO: Only entry'."\n");
    $blockingFile = vlogTemporaryLog('');
    config(['vlog.index_path' => $blockingFile.DIRECTORY_SEPARATOR.'index']);

    $response = $this->getJson(vlogOnlyFileUrl($this));

    $response->assertStatus(500)
        ->assertJsonPath('message', 'Vlog could not write its index. Check that the "index_path" folder in the Vlog config is writable.');
    expect($response->getContent())->not->toContain('index"');
});

it('ignores log files reached through symbolic links that leave the log folder', function () {
    $outside = vlogTemporaryLog('[2026-11-09 10:00:00] local.INFO: Outside'."\n");

    if (! @symlink($outside, $this->logDirectory.DIRECTORY_SEPARATOR.'linked.log')) {
        $this->markTestSkipped('This system does not allow creating symbolic links.');
    }

    $this->getJson('/vlog/api/files')->assertOk()->assertJsonCount(0, 'data');
});
