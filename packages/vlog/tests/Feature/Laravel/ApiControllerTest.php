<?php

use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    Gate::define('viewVlog', function ($user = null): bool {
        return true;
    });
});

/**
 * Get the API ID of a log file by its path inside the log folder.
 */
function vlogFileId($test, string $path): string
{
    foreach ($test->getJson('/vlog/api/files')->json('data') as $file) {
        if ($file['path'] === $path) {
            return $file['id'];
        }
    }

    throw new RuntimeException("The log file [{$path}] was not listed.");
}

/**
 * A Laravel log with entries at different levels.
 */
function vlogApiLog(): string
{
    return implode("\n", [
        '[2026-11-01 09:00:00] local.INFO: Order 1 placed {"order_id":1}',
        '[2026-11-02 09:00:00] local.ERROR: Order 2 failed',
        '[2026-11-03 09:00:00] local.WARNING: Order 3 slow',
        '[2026-11-04 09:00:00] local.ERROR: Order 4 failed',
    ])."\n";
}

describe('files', function () {
    it('lists log files in the log folders, most recently modified first', function () {
        touch($this->writeLog('old.log', ''), 1000);
        touch($this->writeLog('nested/new.log', ''), 2000);
        $this->writeLog('notes.txt', '');

        $this->getJson('/vlog/api/files')
            ->assertOk()
            ->assertJsonPath('data.0.path', 'nested/new.log')
            ->assertJsonPath('data.0.name', 'new.log')
            ->assertJsonPath('data.1.path', 'old.log')
            ->assertJsonCount(2, 'data');
    });

    it('does not reveal where log files are on the server', function () {
        $this->writeLog('laravel.log', '');

        $response = $this->getJson('/vlog/api/files');

        expect($response->getContent())->not->toContain(addslashes($this->logDirectory));
        expect($response->getContent())->not->toContain(str_replace('\\', '/', $this->logDirectory));
    });

    it('sends headers that stop responses being cached or sniffed', function () {
        $this->getJson('/vlog/api/files')
            ->assertHeader('Content-Type', 'application/json; charset=utf-8')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        expect($this->getJson('/vlog/api/files')->headers->get('Cache-Control'))->toContain('no-store');
    });
});

describe('file', function () {
    it('returns the format and level counts of a file', function () {
        $this->writeLog('laravel.log', vlogApiLog());

        $this->getJson('/vlog/api/files/'.vlogFileId($this, 'laravel.log'))
            ->assertOk()
            ->assertJsonPath('data.format', 'laravel')
            ->assertJsonPath('data.entries', 4)
            ->assertJsonPath('data.levels.error', 2)
            ->assertJsonPath('data.complete', true);
    });

    it('returns 404 for a file that is not in the log folders', function (string $id) {
        $this->writeLog('laravel.log', vlogApiLog());

        $this->getJson('/vlog/api/files/'.$id)
            ->assertNotFound()
            ->assertJsonPath('message', 'Log file not found.');
    })->with([
        'unknown ID' => ['0123456789abcdef0123'],
        'file name' => ['laravel.log'],
        'parent folder' => ['..'],
    ]);

    it('returns 404 for a path to a file outside the log folders', function () {
        $this->writeLog('laravel.log', vlogApiLog());

        $this->getJson('/vlog/api/files/..%2F..%2Fetc%2Fpasswd')->assertNotFound();
        $this->getJson('/vlog/api/files/..%2F..%2Fetc%2Fpasswd/entries')->assertNotFound();
    });
});

describe('entries', function () {
    it('returns a page of entries, newest first', function () {
        $this->writeLog('laravel.log', vlogApiLog());

        $this->getJson('/vlog/api/files/'.vlogFileId($this, 'laravel.log').'/entries?per_page=3')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.message', 'Order 4 failed')
            ->assertJsonPath('data.0.level', 'error')
            ->assertJsonPath('data.0.raw_level', 'ERROR')
            ->assertJsonPath('data.0.channel', 'local')
            ->assertJsonPath('meta.total', 4)
            ->assertJsonPath('meta.last_page', 2);
    });

    it('returns entries oldest first with their context', function () {
        $this->writeLog('laravel.log', vlogApiLog());

        $this->getJson('/vlog/api/files/'.vlogFileId($this, 'laravel.log').'/entries?order=oldest&per_page=1')
            ->assertOk()
            ->assertJsonPath('data.0.message', 'Order 1 placed')
            ->assertJsonPath('data.0.context.order_id', 1);
    });

    it('rejects invalid paging parameters', function (string $query, string $message) {
        $this->writeLog('laravel.log', vlogApiLog());

        $this->getJson('/vlog/api/files/'.vlogFileId($this, 'laravel.log').'/entries?'.$query)
            ->assertStatus(422)
            ->assertJsonPath('message', $message);
    })->with([
        'page below 1' => ['page=0', 'The page parameter must be a whole number from 1 to '.PHP_INT_MAX.'.'],
        'too many per page' => ['per_page=101', 'The per_page parameter must be a whole number from 1 to 100.'],
        'unknown order' => ['order=sideways', 'The order parameter must be one of: newest, oldest.'],
        'array instead of a value' => ['page[]=1', 'The page parameter must be a string.'],
    ]);

    it('replaces invalid UTF-8 instead of failing', function () {
        $this->writeLog('laravel.log', "[2026-11-01 09:00:00] local.INFO: Bad byte \xFF here\n");

        $this->getJson('/vlog/api/files/'.vlogFileId($this, 'laravel.log').'/entries')
            ->assertOk()
            ->assertJsonPath('data.0.message', "Bad byte \u{FFFD} here");
    });
});

describe('search', function () {
    it('finds entries matching levels and text', function () {
        $this->writeLog('laravel.log', vlogApiLog());

        $this->getJson('/vlog/api/files/'.vlogFileId($this, 'laravel.log').'/search?levels=error,warning&q=order%20[23]&regex=1')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.message', 'Order 3 slow')
            ->assertJsonPath('data.1.message', 'Order 2 failed')
            ->assertJsonPath('meta.has_more', false)
            ->assertJsonPath('meta.next_cursor', null);
    });

    it('returns a cursor for the next page of results', function () {
        $this->writeLog('laravel.log', vlogApiLog());
        $url = '/vlog/api/files/'.vlogFileId($this, 'laravel.log').'/search?q=order&limit=3';

        $cursor = $this->getJson($url)->assertJsonCount(3, 'data')->json('meta.next_cursor');

        $this->getJson($url.'&cursor='.$cursor)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.message', 'Order 1 placed');
    });

    it('rejects invalid search parameters', function (string $query, string $message) {
        $this->writeLog('laravel.log', vlogApiLog());

        $this->getJson('/vlog/api/files/'.vlogFileId($this, 'laravel.log').'/search?'.$query)
            ->assertStatus(422)
            ->assertJsonPath('message', $message);
    })->with([
        'unknown level' => ['levels=verbose', 'The level [verbose] is not a recognized log level.'],
        'invalid date' => ['from=yesterday-ish', 'The from parameter must be a valid date and time.'],
        'invalid boolean' => ['regex=maybe', 'The regex parameter must be true or false.'],
    ]);

    it('rejects an invalid regular expression with the reason', function () {
        $this->writeLog('laravel.log', vlogApiLog());

        $response = $this->getJson('/vlog/api/files/'.vlogFileId($this, 'laravel.log').'/search?regex=1&q=order%20(');

        $response->assertStatus(422);
        expect($response->json('message'))->toContain('missing closing parenthesis');
    });
});

describe('export', function () {
    it('exports matching entries as JSON with secrets hidden', function () {
        $this->writeLog('laravel.log', vlogApiLog().'[2026-11-05 09:00:00] local.ERROR: Login failed password=hunter2'."\n");

        $response = $this->get('/vlog/api/files/'.vlogFileId($this, 'laravel.log').'/export?format=json&levels=error');

        $response->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="laravel-export.json"');
        $entries = json_decode($response->streamedContent(), true);
        expect(array_column($entries, 'message'))->toBe(['Login failed password=[redacted]', 'Order 4 failed', 'Order 2 failed']);
    });

    it('exports entries as CSV, stopping spreadsheet formulas', function () {
        $this->writeLog('laravel.log', '[2026-11-01 09:00:00] local.INFO: =HYPERLINK("http://evil") {"id":1}'."\n");

        $response = $this->get('/vlog/api/files/'.vlogFileId($this, 'laravel.log').'/export?format=csv');

        expect($response->streamedContent())->toBe(
            "\"time\",\"level\",\"channel\",\"message\",\"context\"\r\n"
            ."\"2026-11-01T09:00:00+00:00\",\"info\",\"local\",\"'=HYPERLINK(\"\"http://evil\"\")\",\"{\"\"id\"\":1}\"\r\n"
        );
    });

    it('exports entries as plain text, oldest first', function () {
        $this->writeLog('laravel.log', vlogApiLog());

        $response = $this->get('/vlog/api/files/'.vlogFileId($this, 'laravel.log').'/export?format=txt&order=oldest&levels=warning');

        expect($response->streamedContent())->toBe("[2026-11-03T09:00:00+00:00] local.WARNING: Order 3 slow\n");
    });

    it('rejects an unknown export format', function () {
        $this->writeLog('laravel.log', vlogApiLog());

        $this->getJson('/vlog/api/files/'.vlogFileId($this, 'laravel.log').'/export?format=xml')
            ->assertStatus(422)
            ->assertJsonPath('message', 'The format parameter must be one of: json, csv, txt.');
    });
});

describe('bookmarks', function () {
    it('bookmarks an entry with a note and keeps it after the file is deleted', function () {
        $path = $this->writeLog('laravel.log', vlogApiLog());
        $id = vlogFileId($this, 'laravel.log');
        $offset = $this->getJson('/vlog/api/files/'.$id.'/entries?per_page=1')->json('data.0.offset');

        $this->withHeaders(['X-Vlog-Request' => '1'])
            ->postJson('/vlog/api/bookmarks', ['file' => $id, 'offset' => $offset, 'note' => 'Check the gateway'])
            ->assertCreated()
            ->assertJsonPath('data.note', 'Check the gateway')
            ->assertJsonPath('data.file', 'laravel.log')
            ->assertJsonPath('data.entry.message', 'Order 4 failed');

        unlink($path);

        $this->getJson('/vlog/api/bookmarks')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.entry.message', 'Order 4 failed');
    });

    it('stores bookmarked entries with secrets hidden', function () {
        $this->writeLog('laravel.log', '[2026-11-01 09:00:00] local.ERROR: Login failed password=hunter2'."\n");
        $id = vlogFileId($this, 'laravel.log');

        $this->withHeaders(['X-Vlog-Request' => '1'])
            ->postJson('/vlog/api/bookmarks', ['file' => $id, 'offset' => 0])
            ->assertJsonPath('data.entry.message', 'Login failed password=[redacted]');
    });

    it('returns 404 when no entry starts at the offset', function () {
        $this->writeLog('laravel.log', vlogApiLog());

        $this->withHeaders(['X-Vlog-Request' => '1'])
            ->postJson('/vlog/api/bookmarks', ['file' => vlogFileId($this, 'laravel.log'), 'offset' => 5])
            ->assertNotFound()
            ->assertJsonPath('message', 'Log entry not found. It may have been rotated or deleted.');
    });

    it('changes a note and deletes a bookmark', function () {
        $this->writeLog('laravel.log', vlogApiLog());
        $bookmark = $this->withHeaders(['X-Vlog-Request' => '1'])
            ->postJson('/vlog/api/bookmarks', ['file' => vlogFileId($this, 'laravel.log'), 'offset' => 0])
            ->json('data.id');

        $this->withHeaders(['X-Vlog-Request' => '1'])
            ->patchJson('/vlog/api/bookmarks/'.$bookmark, ['note' => 'Fixed in #42'])
            ->assertOk()
            ->assertJsonPath('data.note', 'Fixed in #42');

        $this->withHeaders(['X-Vlog-Request' => '1'])->deleteJson('/vlog/api/bookmarks/'.$bookmark)->assertOk();
        $this->withHeaders(['X-Vlog-Request' => '1'])->deleteJson('/vlog/api/bookmarks/'.$bookmark)->assertNotFound();
        $this->getJson('/vlog/api/bookmarks')->assertJsonCount(0, 'data');
    });

    it('rejects notes that are too long', function () {
        $this->writeLog('laravel.log', vlogApiLog());

        $this->withHeaders(['X-Vlog-Request' => '1'])
            ->postJson('/vlog/api/bookmarks', ['file' => vlogFileId($this, 'laravel.log'), 'offset' => 0, 'note' => str_repeat('a', 2001)])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Notes can be up to 2000 characters long.');
    });
});

describe('groups', function () {
    it('lists groups of similar entries, most frequent first, with a daily trend', function () {
        $this->writeLog('laravel.log', implode("\n", [
            '[2026-11-07 09:00:00] local.ERROR: Order 1 failed',
            '[2026-11-09 09:00:00] local.ERROR: Order 2 failed',
            '[2026-11-09 10:00:00] local.ERROR: Order 3 failed',
            '[2026-11-09 11:00:00] local.INFO: User 7 signed in password=hunter2',
        ])."\n");

        $response = $this->getJson('/vlog/api/files/'.vlogFileId($this, 'laravel.log').'/groups');

        $response->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.pattern', 'Order {n} failed')
            ->assertJsonPath('data.0.sample', 'Order 3 failed')
            ->assertJsonPath('data.0.count', 3)
            ->assertJsonPath('data.0.first_seen', '2026-11-07T09:00:00+00:00')
            ->assertJsonPath('data.0.last_seen', '2026-11-09T10:00:00+00:00')
            ->assertJsonPath('data.1.sample', 'User 7 signed in password=[redacted]');
        expect($response->json('meta.trend_days'))->toHaveCount(14);
        expect(array_slice($response->json('meta.trend_days'), -3))->toBe(['2026-11-07', '2026-11-08', '2026-11-09']);
        expect(array_slice($response->json('data.0.trend'), -3))->toBe([1, 0, 2]);
    });

    it('lists only groups at the chosen levels, sorted by when they were last seen', function () {
        $this->writeLog('laravel.log', vlogApiLog());

        $this->getJson('/vlog/api/files/'.vlogFileId($this, 'laravel.log').'/groups?levels=error,warning&sort=last_seen')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.level', 'error')
            ->assertJsonPath('data.1.level', 'warning');
    });

    it('finds the entries of one group through search', function () {
        $this->writeLog('laravel.log', vlogApiLog());
        $url = '/vlog/api/files/'.vlogFileId($this, 'laravel.log');
        $group = $this->getJson($url.'/groups?levels=error')->json('data.0.id');

        $this->getJson($url.'/search?group='.$group)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.message', 'Order 4 failed')
            ->assertJsonPath('data.1.message', 'Order 2 failed');
    });
});

describe('search all files', function () {
    it('returns the newest matches from each file that has any', function () {
        touch($this->writeLog('a.log', vlogApiLog()), 2000);
        touch($this->writeLog('b.log', '[2026-11-05 09:00:00] local.ERROR: Order 5 failed'."\n"), 1000);
        $this->writeLog('c.log', '[2026-11-05 09:00:00] local.INFO: Nothing to see'."\n");

        $this->getJson('/vlog/api/search?q=failed&per_file=1')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.file.path', 'a.log')
            ->assertJsonPath('data.0.entries.0.message', 'Order 4 failed')
            ->assertJsonCount(1, 'data.0.entries')
            ->assertJsonPath('data.0.has_more', true)
            ->assertJsonPath('data.1.file.path', 'b.log')
            ->assertJsonPath('data.1.has_more', false)
            ->assertJsonPath('meta.searched_files', 3)
            ->assertJsonPath('meta.total_files', 3)
            ->assertJsonPath('meta.next_after', null);
    });

    it('continues after a file', function () {
        touch($this->writeLog('a.log', vlogApiLog()), 2000);
        touch($this->writeLog('b.log', '[2026-11-05 09:00:00] local.ERROR: Order 5 failed'."\n"), 1000);

        $this->getJson('/vlog/api/search?q=failed&after='.vlogFileId($this, 'a.log'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.file.path', 'b.log')
            ->assertJsonPath('meta.searched_files', 1);
    });

    it('rejects invalid searches', function (string $query) {
        $this->writeLog('a.log', vlogApiLog());

        $this->getJson('/vlog/api/search?'.$query)->assertStatus(422);
    })->with([
        'invalid regular expression' => ['q=(&regex=1'],
        'too many entries per file' => ['q=a&per_file=21'],
    ]);
});

describe('exception origin', function () {
    it('returns where a logged exception was thrown', function () {
        $this->writeLog('laravel.log', '[2026-11-09 10:15:32] local.ERROR: Division by zero {"exception":"[object] (DivisionByZeroError(code: 0): Division by zero at /var/www/app/Http/Controllers/ReportController.php:21)\n[stacktrace]\n#0 /var/www/vendor/laravel/framework/src/Illuminate/Routing/Controller.php(54): show()\n"}'."\n");

        $this->getJson('/vlog/api/files/'.vlogFileId($this, 'laravel.log').'/entries')
            ->assertOk()
            ->assertJsonPath('data.0.origin', ['file' => '/var/www/app/Http/Controllers/ReportController.php', 'line' => 21]);
    });

    it('returns no origin for entries without an exception', function () {
        $this->writeLog('laravel.log', vlogApiLog());

        $this->getJson('/vlog/api/files/'.vlogFileId($this, 'laravel.log').'/entries')
            ->assertJsonPath('data.0.origin', null);
    });
});
