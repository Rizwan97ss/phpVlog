<?php

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * Allow viewing, and allow or deny managing.
 */
function vlogAllowManaging(bool $canManage = true): void
{
    Gate::define('viewVlog', function ($user = null): bool {
        return true;
    });
    Gate::define('manageVlog', function ($user = null) use ($canManage): bool {
        return $canManage;
    });
}

/**
 * Get the API ID of the only log file.
 */
function vlogManagedFileId($test): string
{
    return $test->getJson('/vlog/api/files')->json('data.0.id');
}

describe('download', function () {
    it('downloads the file exactly as written, including secrets', function () {
        vlogAllowManaging();
        $contents = '[2026-11-09 10:00:00] local.INFO: Login {"password":"hunter2"}'."\n";
        $this->writeLog('laravel.log', $contents);

        $response = $this->get('/vlog/api/files/'.vlogManagedFileId($this).'/download');

        $response->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="laravel.log"');
        expect($response->streamedContent())->toBe($contents);
    });

    it('forbids downloads for users who cannot manage logs', function () {
        vlogAllowManaging(false);
        $this->writeLog('laravel.log', '[2026-11-09 10:00:00] local.INFO: Entry'."\n");

        $this->get('/vlog/api/files/'.vlogManagedFileId($this).'/download')->assertForbidden();
    });
});

describe('clear', function () {
    it('empties the file and keeps it', function () {
        vlogAllowManaging();
        $path = $this->writeLog('laravel.log', '[2026-11-09 10:00:00] local.INFO: Entry'."\n");
        $id = vlogManagedFileId($this);
        $this->getJson('/vlog/api/files/'.$id)->assertJsonPath('data.entries', 1);

        $this->withHeaders(['X-Vlog-Request' => '1'])
            ->postJson('/vlog/api/files/'.$id.'/clear')
            ->assertOk()
            ->assertJsonPath('data.cleared', true);

        clearstatcache();
        expect(filesize($path))->toBe(0);
        $this->getJson('/vlog/api/files/'.$id)->assertJsonPath('data.entries', 0);
    });

    it('forbids clearing for users who cannot manage logs', function () {
        vlogAllowManaging(false);
        $path = $this->writeLog('laravel.log', '[2026-11-09 10:00:00] local.INFO: Entry'."\n");

        $this->withHeaders(['X-Vlog-Request' => '1'])
            ->postJson('/vlog/api/files/'.vlogManagedFileId($this).'/clear')
            ->assertForbidden();

        clearstatcache();
        expect(filesize($path))->toBeGreaterThan(0);
    });
});

describe('delete', function () {
    it('deletes the file', function () {
        vlogAllowManaging();
        $path = $this->writeLog('laravel.log', '[2026-11-09 10:00:00] local.INFO: Entry'."\n");

        $this->withHeaders(['X-Vlog-Request' => '1'])
            ->deleteJson('/vlog/api/files/'.vlogManagedFileId($this))
            ->assertOk()
            ->assertJsonPath('data.deleted', true);

        expect(is_file($path))->toBeFalse();
        $this->getJson('/vlog/api/files')->assertJsonCount(0, 'data');
    });

    it('returns 404 for a file that is not in the log folders', function () {
        vlogAllowManaging();

        $this->withHeaders(['X-Vlog-Request' => '1'])
            ->deleteJson('/vlog/api/files/0123456789abcdef0123')
            ->assertNotFound();
    });

    it('forbids deleting for users who cannot manage logs', function () {
        vlogAllowManaging(false);
        $path = $this->writeLog('laravel.log', '[2026-11-09 10:00:00] local.INFO: Entry'."\n");

        $this->withHeaders(['X-Vlog-Request' => '1'])
            ->deleteJson('/vlog/api/files/'.vlogManagedFileId($this))
            ->assertForbidden();

        expect(is_file($path))->toBeTrue();
    });
});

describe('write', function () {
    it('writes an entry to the application log', function () {
        vlogAllowManaging();
        Log::shouldReceive('log')
            ->once()
            ->with('warning', 'Deployed v2.3', ['release' => 'v2.3', 'written_with' => 'vlog']);

        $this->withHeaders(['X-Vlog-Request' => '1'])
            ->postJson('/vlog/api/entries', ['level' => 'warning', 'message' => 'Deployed v2.3', 'context' => ['release' => 'v2.3']])
            ->assertCreated()
            ->assertJsonPath('data.written', true);
    });

    it('rejects invalid entries', function (array $input, string $message) {
        vlogAllowManaging();

        $this->withHeaders(['X-Vlog-Request' => '1'])
            ->postJson('/vlog/api/entries', $input)
            ->assertStatus(422)
            ->assertJsonPath('message', $message);
    })->with([
        'missing message' => [['level' => 'info'], 'The message parameter is required.'],
        'unknown level' => [['level' => 'loud', 'message' => 'Hi'], 'The level parameter must be one of: debug, info, notice, warning, error, critical, alert, emergency.'],
        'message too long' => [['message' => str_repeat('a', 5001)], 'The message can be up to 5000 characters long.'],
        'context not an object' => [['message' => 'Hi', 'context' => 'text'], 'The context parameter must be an object.'],
    ]);

    it('forbids writing for users who cannot manage logs', function () {
        vlogAllowManaging(false);
        Log::shouldReceive('log')->never();

        $this->withHeaders(['X-Vlog-Request' => '1'])
            ->postJson('/vlog/api/entries', ['message' => 'Hi'])
            ->assertForbidden();
    });
});

describe('source', function () {
    /**
     * Create an application folder holding app/Login.php and .env.php, and
     * let Vlog preview files in it.
     */
    function vlogSourceApp(): string
    {
        $root = vlogTemporaryDirectory();
        mkdir($root.'/app');
        file_put_contents($root.'/app/Login.php', implode("\n", ['<?php', '', '// password=hunter2', 'throw new Exception();', ''])."\n");
        file_put_contents($root.'/.env.php', "<?php\n");
        config(['vlog.source.paths' => [$root]]);

        return $root;
    }

    it('shows the code around a line, with secrets hidden', function () {
        vlogAllowManaging();
        $root = vlogSourceApp();

        $this->getJson('/vlog/api/source?'.http_build_query(['path' => $root.'/app/Login.php', 'line' => 4]))
            ->assertOk()
            ->assertJsonPath('data.path', 'app/Login.php')
            ->assertJsonPath('data.line', 4)
            ->assertJsonPath('data.start', 1)
            ->assertJsonPath('data.lines.2', '// password=[redacted]')
            ->assertJsonPath('data.lines.3', 'throw new Exception();');
    });

    it('refuses files outside the source folders and hidden files', function (string $file) {
        vlogAllowManaging();
        $root = vlogSourceApp();
        $path = $file === '.env.php' ? $root.'/.env.php' : $this->writeLog('Outside.php', "<?php\n");

        $this->getJson('/vlog/api/source?'.http_build_query(['path' => $path, 'line' => 1]))
            ->assertNotFound()
            ->assertJsonPath('message', 'This file is not available for preview.');
    })->with(['.env.php', 'outside']);

    it('can be turned off', function () {
        vlogAllowManaging();
        $root = vlogSourceApp();
        config(['vlog.source.enabled' => false]);

        $this->getJson('/vlog/api/source?'.http_build_query(['path' => $root.'/app/Login.php', 'line' => 4]))
            ->assertStatus(501);
    });

    it('forbids previews for users who cannot manage logs', function () {
        vlogAllowManaging(false);
        $root = vlogSourceApp();

        $this->getJson('/vlog/api/source?'.http_build_query(['path' => $root.'/app/Login.php', 'line' => 4]))
            ->assertForbidden();
    });
});
