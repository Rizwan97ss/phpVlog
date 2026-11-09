<?php

use Rss\Vlog\Core\Index\IndexPruner;
use Rss\Vlog\Core\Index\LogIndex;

/**
 * Build an index for a new log file and return the log file's path.
 */
function vlogIndexedLog(string $indexDirectory): string
{
    $path = vlogTemporaryLog("[2026-11-09 10:00:00] local.INFO: Entry\n");
    LogIndex::open($path, $indexDirectory)->refresh();

    return $path;
}

/**
 * @return list<string>
 */
function vlogIndexFiles(string $directory): array
{
    $files = array_values(array_filter(scandir($directory) ?: [], function (string $name): bool {
        return preg_match('/^[0-9a-f]{40}\./', $name) === 1;
    }));
    sort($files);

    return $files;
}

it('deletes the indexes of log files that are not kept', function () {
    $directory = vlogTemporaryDirectory();
    $kept = vlogIndexedLog($directory);
    $before = vlogIndexFiles($directory);
    vlogIndexedLog($directory);

    $deleted = (new IndexPruner($directory))->prune([$kept]);

    // Each index has a records file, a state file, a groups file and a lock file.
    expect($deleted)->toBe(4);
    expect(vlogIndexFiles($directory))->toBe($before);
});

it('leaves files that are not index files alone', function () {
    $directory = vlogTemporaryDirectory();
    vlogIndexedLog($directory);
    file_put_contents($directory.'/notes.txt', 'keep me');

    (new IndexPruner($directory))->prune([]);

    expect(vlogIndexFiles($directory))->toBe([]);
    expect(is_file($directory.'/notes.txt'))->toBeTrue();
    expect(is_file($directory.'/.gitignore'))->toBeTrue();
});

it('prunes at most once per interval unless forced', function () {
    $directory = vlogTemporaryDirectory();
    $pruner = new IndexPruner($directory, 3600);
    $pruner->prune([]);
    vlogIndexedLog($directory);

    expect($pruner->prune([]))->toBe(0);
    expect($pruner->prune([], true))->toBe(4);
});

it('does nothing when the index folder does not exist', function () {
    expect((new IndexPruner(vlogTemporaryDirectory().'/missing'))->prune([], true))->toBe(0);
});

it('rebuilds a pruned index the next time its log file is opened', function () {
    $directory = vlogTemporaryDirectory();
    $path = vlogIndexedLog($directory);
    (new IndexPruner($directory))->prune([], true);

    $index = LogIndex::open($path, $directory);
    $index->refresh();

    expect($index->count())->toBe(1);
});
