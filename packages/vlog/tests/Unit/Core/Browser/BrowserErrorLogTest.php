<?php

use Rss\Vlog\Core\Browser\BrowserErrorLog;
use Rss\Vlog\Core\Entry\LogEntry;
use Rss\Vlog\Core\Parsers\LaravelParser;
use Rss\Vlog\Core\Reader\LogReader;

/**
 * Read every entry in a log file written by the browser error log.
 *
 * @return list<LogEntry>
 */
function vlogBrowserEntries(string $path): array
{
    return iterator_to_array((new LogReader(new LaravelParser))->entries($path), false);
}

/**
 * @param  array<string, mixed>  ...$errors
 */
function vlogBrowserReport(array ...$errors): string
{
    return (string) json_encode(['errors' => $errors]);
}

it('writes errors as Laravel log entries with their stack frames', function () {
    $path = vlogTemporaryDirectory().'/browser.log';

    $written = (new BrowserErrorLog($path))->record(vlogBrowserReport([
        'type' => 'error',
        'message' => 'Uncaught TypeError: user is undefined',
        'url' => 'https://app.test/orders?token=secret#top',
        'source' => 'https://app.test/build/app.js?v=3',
        'line' => 10,
        'column' => 5,
        'stack' => "TypeError: user is undefined\n    at showOrder (https://app.test/build/app.js:10:5)\n    at https://app.test/build/vendor.js:2:9",
    ]), 'Mozilla/5.0');

    $entries = vlogBrowserEntries($path);

    expect($written)->toBe(1)
        ->and($entries)->toHaveCount(1)
        ->and($entries[0]->level())->toBe('error')
        ->and($entries[0]->channel())->toBe('browser')
        ->and($entries[0]->message())->toBe('Uncaught TypeError: user is undefined')
        ->and($entries[0]->context())->toMatchArray([
            'type' => 'error',
            'url' => 'https://app.test/orders',
            'source' => 'https://app.test/build/app.js',
            'line' => 10,
            'user_agent' => 'Mozilla/5.0',
        ]);

    $frames = $entries[0]->stackFrames();

    expect($frames)->toHaveCount(2)
        ->and($frames[0]->file())->toBe('https://app.test/build/app.js')
        ->and($frames[0]->line())->toBe(10)
        ->and($frames[0]->call())->toBe('showOrder:5')
        ->and($frames[1]->file())->toBe('https://app.test/build/vendor.js')
        ->and($frames[1]->call())->toBe('{anonymous}:9');
});

it('reads Firefox and Safari stack frames', function () {
    $path = vlogTemporaryDirectory().'/browser.log';

    (new BrowserErrorLog($path))->record(vlogBrowserReport([
        'message' => 'boom',
        'stack' => "showOrder@https://app.test/app.js:4:2\n@https://app.test/app.js:9:1",
    ]));

    $frames = vlogBrowserEntries($path)[0]->stackFrames();

    expect($frames)->toHaveCount(2)
        ->and($frames[0]->call())->toBe('showOrder:2')
        ->and($frames[1]->line())->toBe(9);
});

it('cannot be used to forge other log entries', function () {
    $path = vlogTemporaryDirectory().'/browser.log';

    (new BrowserErrorLog($path))->record(vlogBrowserReport([
        'message' => "Real error\n[2026-11-09 10:00:00] production.EMERGENCY: Forged entry",
        'stack' => "at x (https://app.test/a.js:1:1)\n[2026-11-09 10:00:00] production.EMERGENCY: Forged",
    ]), "Agent\n[2026-11-09 10:00:00] production.EMERGENCY: Forged");

    $entries = vlogBrowserEntries($path);

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->level())->toBe('error');
});

it('logs resources that fail to load as warnings', function () {
    $path = vlogTemporaryDirectory().'/browser.log';

    (new BrowserErrorLog($path))->record(vlogBrowserReport([
        'type' => 'resource',
        'message' => 'Failed to load img: https://app.test/logo.png',
    ]));

    expect(vlogBrowserEntries($path)[0]->level())->toBe('warning');
});

it('ignores reports it cannot use', function (string $body) {
    $path = vlogTemporaryDirectory().'/browser.log';

    expect((new BrowserErrorLog($path))->record($body))->toBe(0)
        ->and(is_file($path))->toBeFalse();
})->with([
    'empty body' => [''],
    'invalid JSON' => ['{nope'],
    'no errors list' => ['{"message":"boom"}'],
    'error without a message' => ['{"errors":[{"type":"error","message":"  "}]}'],
    'error that is not an object' => ['{"errors":["boom"]}'],
    'body over the size limit' => [vlogBrowserReport(['message' => str_repeat('a', BrowserErrorLog::MAX_BODY_BYTES)])],
]);

it('writes at most ten errors from one report', function () {
    $path = vlogTemporaryDirectory().'/browser.log';
    $errors = array_map(function (int $number): array {
        return ['message' => 'Error '.$number];
    }, range(1, 15));

    expect((new BrowserErrorLog($path))->record(vlogBrowserReport(...$errors)))->toBe(BrowserErrorLog::MAX_ERRORS_PER_REQUEST)
        ->and(vlogBrowserEntries($path))->toHaveCount(BrowserErrorLog::MAX_ERRORS_PER_REQUEST);
});

it('starts a new file when the log grows too large', function () {
    $directory = vlogTemporaryDirectory();
    $path = $directory.'/browser.log';
    file_put_contents($path, str_repeat('x', 200));

    (new BrowserErrorLog($path, 100))->record(vlogBrowserReport(['message' => 'New error']));

    expect(file_get_contents($directory.'/browser.old.log'))->toBe(str_repeat('x', 200))
        ->and(vlogBrowserEntries($path))->toHaveCount(1);
});
