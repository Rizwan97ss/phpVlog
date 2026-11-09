<?php

use Rss\Vlog\Core\Entry\LogEntry;
use Rss\Vlog\Core\Exceptions\IndexException;
use Rss\Vlog\Core\Index\LogIndex;
use Rss\Vlog\Core\Search\SearchFilter;

/**
 * Get the messages of a list of entries.
 *
 * @param  list<LogEntry>  $entries
 * @return list<string>
 */
function vlogMessagesOf(array $entries): array
{
    return array_map(function (LogEntry $entry): string {
        return $entry->message();
    }, $entries);
}

/**
 * Build a Laravel log with numbered INFO entries.
 */
function vlogNumberedLog(int $from, int $to): string
{
    $log = '';

    for ($number = $from; $number <= $to; $number++) {
        $log .= "[2026-11-09 10:00:00] local.INFO: Entry {$number}\n";
    }

    return $log;
}

it('indexes every entry with its level counts', function () {
    $path = vlogTemporaryLog((string) file_get_contents(vlogFixture('laravel.log')));
    $index = LogIndex::open($path, vlogTemporaryDirectory());

    $finished = $index->refresh();

    expect($finished)->toBeTrue();
    expect($index->format())->toBe('laravel');
    expect($index->count())->toBe(3);
    expect($index->levelCounts())->toBe(['info' => 1, 'error' => 1, 'warning' => 1]);
});

it('returns pages of entries newest first', function () {
    $index = LogIndex::open(vlogTemporaryLog(vlogNumberedLog(1, 5)), vlogTemporaryDirectory());
    $index->refresh();

    expect(vlogMessagesOf($index->entries(1, 2)))->toBe(['Entry 5', 'Entry 4']);
    expect(vlogMessagesOf($index->entries(3, 2)))->toBe(['Entry 1']);
    expect($index->entries(4, 2))->toBe([]);
});

it('returns pages of entries oldest first', function () {
    $index = LogIndex::open(vlogTemporaryLog(vlogNumberedLog(1, 5)), vlogTemporaryDirectory());
    $index->refresh();

    expect(vlogMessagesOf($index->entries(1, 2, false)))->toBe(['Entry 1', 'Entry 2']);
    expect(vlogMessagesOf($index->entries(3, 2, false)))->toBe(['Entry 5']);
});

it('returns fully parsed entries with their stack traces and positions', function () {
    $path = vlogTemporaryLog((string) file_get_contents(vlogFixture('laravel.log')));
    $index = LogIndex::open($path, vlogTemporaryDirectory());
    $index->refresh();

    $entries = $index->entries(1, 3, false);

    expect($entries[1]->message())->toBe('Division by zero');
    expect($entries[1]->stackFrames())->toHaveCount(3);
    expect($entries[2]->offset() + $entries[2]->length())->toBe(filesize($path));
});

it('keeps the index between instances', function () {
    $path = vlogTemporaryLog(vlogNumberedLog(1, 3));
    $directory = vlogTemporaryDirectory();
    LogIndex::open($path, $directory)->refresh();

    $reopened = LogIndex::open($path, $directory);

    expect($reopened->count())->toBe(3);
    expect(vlogMessagesOf($reopened->entries(1, 1)))->toBe(['Entry 3']);
});

it('adds entries written after the last refresh', function () {
    $path = vlogTemporaryLog(vlogNumberedLog(1, 2));
    $index = LogIndex::open($path, vlogTemporaryDirectory());
    $index->refresh();
    file_put_contents($path, vlogNumberedLog(3, 4), FILE_APPEND);

    $index->refresh();

    expect($index->count())->toBe(4);
    expect(vlogMessagesOf($index->entries(1, 4)))->toBe(['Entry 4', 'Entry 3', 'Entry 2', 'Entry 1']);
});

it('adds lines written after the last refresh to the last entry', function () {
    $path = vlogTemporaryLog("[2026-11-09 10:00:00] local.ERROR: Failed\n#0 /var/www/app/Job.php(5): run()\n");
    $index = LogIndex::open($path, vlogTemporaryDirectory());
    $index->refresh();
    file_put_contents($path, "#1 {main}\n", FILE_APPEND);

    $index->refresh();

    expect($index->count())->toBe(1);
    expect($index->entries()[0]->stackFrames())->toHaveCount(2);
});

it('waits for a line to be complete before indexing it', function () {
    $path = vlogTemporaryLog("[2026-11-09 10:00:00] local.INFO: Entry 1\n[2026-11-09 10:00:01] local.INFO: Entry");
    $index = LogIndex::open($path, vlogTemporaryDirectory());
    $index->refresh();

    expect($index->count())->toBe(1);

    file_put_contents($path, " 2\n", FILE_APPEND);
    $index->refresh();

    expect(vlogMessagesOf($index->entries()))->toBe(['Entry 2', 'Entry 1']);
});

it('rebuilds the index when the file is truncated', function () {
    $path = vlogTemporaryLog(vlogNumberedLog(1, 3));
    $index = LogIndex::open($path, vlogTemporaryDirectory());
    $index->refresh();
    file_put_contents($path, "[2026-11-09 11:00:00] local.ERROR: After rotation\n");

    $index->refresh();

    expect($index->count())->toBe(1);
    expect($index->levelCounts())->toBe(['error' => 1]);
    expect(vlogMessagesOf($index->entries()))->toBe(['After rotation']);
});

it('rebuilds the index when the file is replaced by a larger one', function () {
    $path = vlogTemporaryLog(vlogNumberedLog(1, 2));
    $index = LogIndex::open($path, vlogTemporaryDirectory());
    $index->refresh();
    file_put_contents($path, str_replace('Entry', 'Other', vlogNumberedLog(1, 5)));

    $index->refresh();

    expect($index->count())->toBe(5);
    expect(vlogMessagesOf($index->entries(1, 1)))->toBe(['Other 5']);
});

it('continues indexing on the next refresh after reaching the time limit', function () {
    $path = vlogTemporaryLog(vlogNumberedLog(1, 5000));
    $index = LogIndex::open($path, vlogTemporaryDirectory());

    $finished = $index->refresh(0.0);

    expect($finished)->toBeFalse();
    expect($index->count())->toBeLessThan(5000);

    $finished = $index->refresh();

    expect($finished)->toBeTrue();
    expect($index->count())->toBe(5000);
    expect(vlogMessagesOf($index->entries(1, 1)))->toBe(['Entry 5000']);
});

it('rebuilds an index whose state file is corrupted', function () {
    $path = vlogTemporaryLog(vlogNumberedLog(1, 3));
    $directory = vlogTemporaryDirectory();
    LogIndex::open($path, $directory)->refresh();
    file_put_contents((string) glob($directory.'/*.json')[0], '{not json');

    $index = LogIndex::open($path, $directory);
    $index->refresh();

    expect($index->count())->toBe(3);
});

it('indexes the same entries the reader reads', function (string $file, int $expectedCount) {
    $index = LogIndex::open(vlogFixture($file), vlogTemporaryDirectory());
    $index->refresh();

    expect($index->count())->toBe($expectedCount);
})->with([
    'CodeIgniter 3 without its PHP guard line' => ['codeigniter3.php', 2],
    'CodeIgniter 4' => ['codeigniter4.log', 3],
    'Monolog JSON' => ['monolog.json', 2],
]);

it('counts entries without a recognized level as unknown', function () {
    $index = LogIndex::open(vlogTemporaryLog("leftover line\n[2026-11-09 10:00:00] local.AUDIT: Changed\n"), vlogTemporaryDirectory());

    $index->refresh();

    expect($index->levelCounts())->toBe(['unknown' => 2]);
});

it('returns no entries before the first refresh', function () {
    $index = LogIndex::open(vlogTemporaryLog(vlogNumberedLog(1, 3)), vlogTemporaryDirectory());

    expect($index->count())->toBe(0);
    expect($index->entries())->toBe([]);
});

it('throws when the index directory cannot be created', function () {
    $blockingFile = vlogTemporaryLog('');
    $index = LogIndex::open(vlogTemporaryLog(vlogNumberedLog(1, 1)), $blockingFile.DIRECTORY_SEPARATOR.'index');

    $index->refresh();
})->throws(IndexException::class);

it('groups similar entries and finds the entries of one group', function () {
    $log = "[2026-11-09 10:00:00] local.ERROR: Order 1 failed\n"
        ."[2026-11-09 10:00:01] local.INFO: Order 1 placed\n"
        ."[2026-11-09 10:00:02] local.ERROR: Order 2 failed {\"order_id\":2}\n";
    $index = LogIndex::open(vlogTemporaryLog($log), vlogTemporaryDirectory());
    $index->refresh();

    $groups = $index->groups();
    $failed = $groups[0];
    $result = $index->search(new SearchFilter(null, null, null, null, false, false, $failed['id']));

    expect(array_column($groups, 'pattern'))->toBe(['Order {n} failed', 'Order {n} placed']);
    expect($failed['count'])->toBe(2);
    expect(vlogMessagesOf($result->entries()))->toBe(['Order 2 failed', 'Order 1 failed']);
});

it('adds entries written after the last refresh to their groups', function () {
    $path = vlogTemporaryLog("[2026-11-09 10:00:00] local.ERROR: Order 1 failed\n");
    $index = LogIndex::open($path, vlogTemporaryDirectory());
    $index->refresh();
    file_put_contents($path, "[2026-11-09 10:00:05] local.ERROR: Order 7 failed\n", FILE_APPEND);

    $index->refresh();

    expect($index->groups())->toHaveCount(1);
    expect($index->groups()[0]['count'])->toBe(2);
});

it('rebuilds an index whose groups no longer match it', function () {
    $path = vlogTemporaryLog("[2026-11-09 10:00:00] local.ERROR: Order 1 failed\n");
    $directory = vlogTemporaryDirectory();
    LogIndex::open($path, $directory)->refresh();
    unlink((string) glob($directory.'/*.groups.json')[0]);

    $index = LogIndex::open($path, $directory);
    $index->refresh();

    expect($index->count())->toBe(1);
    expect($index->groups()[0]['count'])->toBe(1);
});
