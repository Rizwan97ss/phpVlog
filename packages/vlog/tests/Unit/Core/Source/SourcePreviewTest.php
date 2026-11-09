<?php

use Rss\Vlog\Core\Source\SourcePreview;

/**
 * Create an application folder with a PHP file of 30 numbered lines, a
 * hidden file and a non-PHP file.
 */
function vlogSourceRoot(): string
{
    $root = vlogTemporaryDirectory();
    mkdir($root.'/app');
    mkdir($root.'/.git');

    $lines = array_map(function (int $number): string {
        return '// line '.$number;
    }, range(1, 30));

    file_put_contents($root.'/app/Order.php', implode("\n", $lines)."\n");
    file_put_contents($root.'/.git/hook.php', "<?php\n");
    file_put_contents($root.'/.env.php', "<?php\n");
    file_put_contents($root.'/notes.txt', "secret\n");

    return $root;
}

it('reads the lines around a line', function () {
    $root = vlogSourceRoot();

    $preview = (new SourcePreview([$root]))->around($root.'/app/Order.php', 12, 3);

    expect($preview)->toBe([
        'path' => 'app/Order.php',
        'line' => 12,
        'start' => 9,
        'lines' => ['// line 9', '// line 10', '// line 11', '// line 12', '// line 13', '// line 14', '// line 15'],
    ]);
});

it('stops at the start and end of the file', function () {
    $root = vlogSourceRoot();
    $preview = new SourcePreview([$root]);

    expect($preview->around($root.'/app/Order.php', 2, 3)['start'] ?? null)->toBe(1)
        ->and($preview->around($root.'/app/Order.php', 30, 3)['lines'] ?? null)->toHaveCount(4);
});

it('refuses files it must not show', function (string $path, int $line) {
    $root = vlogSourceRoot();
    $outside = vlogTemporaryDirectory().'/Outside.php';
    file_put_contents($outside, "<?php\n");
    file_put_contents(dirname($root).'/Climb.php', "<?php\n");

    $path = str_replace(['{root}', '{outside}'], [$root, $outside], $path);

    expect((new SourcePreview([$root]))->around($path, $line))->toBeNull();
})->with([
    'outside the folders' => ['{outside}', 1],
    'path that climbs out of the folder' => ['{root}/app/../../Climb.php', 1],
    'inside a hidden folder' => ['{root}/.git/hook.php', 1],
    'hidden file' => ['{root}/.env.php', 1],
    'not a PHP file' => ['{root}/notes.txt', 1],
    'missing file' => ['{root}/app/Missing.php', 1],
    'line past the end' => ['{root}/app/Order.php', 31],
    'line zero' => ['{root}/app/Order.php', 0],
    'URL' => ['https://example.com/app.php', 1],
]);
