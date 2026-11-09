<?php

use Rss\Vlog\Core\Browser\BrowserScript;

it('adds the script before the closing head tag', function () {
    $html = BrowserScript::inject('<html><head><title>App</title></HEAD><body></body></html>', '<script data-vlog-browser></script>');

    expect($html)->toBe('<html><head><title>App</title><script data-vlog-browser></script></HEAD><body></body></html>');
});

it('adds the script before the closing body tag when there is no head', function () {
    expect(BrowserScript::inject('<body>Hi</body>', '<script data-vlog-browser></script>'))
        ->toBe('<body>Hi<script data-vlog-browser></script></body>');
});

it('leaves pages without a head or body, or that already have the script, unchanged', function (string $html) {
    expect(BrowserScript::inject($html, '<script data-vlog-browser></script>'))->toBe($html);
})->with([
    'fragment' => ['<div>Partial</div>'],
    'already added' => ['<head><script data-vlog-browser></script></head>'],
]);

it('escapes the endpoint and nonce', function () {
    $tag = BrowserScript::tag('/vlog/browser-errors?a=</script>', 'abc"><x');

    expect($tag)->toStartWith('<script data-vlog-browser nonce="abc&quot;&gt;&lt;x">')
        ->and($tag)->toContain('"/vlog/browser-errors?a=\\u003C/script\\u003E"')
        ->and(substr_count($tag, '</script>'))->toBe(1);
});
