<?php

use Rss\Vlog\Core\Grouping\Fingerprint;

it('replaces the parts of a message that change between occurrences', function (string $message, string $expected) {
    expect(Fingerprint::pattern($message))->toBe($expected);
})->with([
    'numbers' => ['Order 1042 failed after 3.5 seconds', 'Order {n} failed after {n} seconds'],
    'UUID' => ['Job 9f1c2b4e-3a5d-4e6f-8a9b-0c1d2e3f4a5b timed out', 'Job {uuid} timed out'],
    'email' => ['Mail to amy.smith+news@example.co.uk bounced', 'Mail to {email} bounced'],
    'IP address' => ['Blocked request from 192.168.10.4', 'Blocked request from {ip}'],
    'hex ID' => ['Cache key 5f3a9c0e81b2 missing', 'Cache key {hex} missing'],
    'repeated spaces' => ["Disk   almost\tfull", 'Disk almost full'],
]);

it('leaves out the JSON context written after the message', function (string $message) {
    expect(Fingerprint::pattern($message))->toBe('Payment failed');
})->with([
    'object' => ['Payment failed {"order_id":1042}'],
    'empty object and extra' => ['Payment failed {} []'],
    'empty arrays' => ['Payment failed [] []'],
]);

it('keeps words that only look like hex', function () {
    expect(Fingerprint::pattern('Called deadbeef handler'))->toBe('Called deadbeef handler');
});

it('gives the same key to the same level and pattern only', function () {
    $key = Fingerprint::key('error', 'Order {n} failed');

    expect(Fingerprint::key('error', 'Order {n} failed'))->toBe($key);
    expect(Fingerprint::key('warning', 'Order {n} failed'))->not->toBe($key);
    expect(Fingerprint::key('error', 'Order {n} shipped'))->not->toBe($key);
});

it('gives messages that differ only in their digits the same shape key and pattern', function (string $first, string $second) {
    expect(Fingerprint::shapeKey($first))->toBe(Fingerprint::shapeKey($second));
    expect(Fingerprint::pattern($first))->toBe(Fingerprint::pattern($second));
})->with([
    'numbers' => ['Order 1042 failed', 'Order 7731 failed'],
    'hex IDs' => ['Key 5f3a9c0e81b2 missing', 'Key 7f1a2c9e33b4 missing'],
    'IP addresses' => ['From 10.0.0.1', 'From 19.2.6.4'],
]);

it('gives messages whose digits change their pattern different shape keys', function (string $first, string $second) {
    expect(Fingerprint::shapeKey($first))->not->toBe(Fingerprint::shapeKey($second));
})->with([
    'hex ID and shorter word' => ['Key abcdef12 missing', 'Key abcdef1 missing'],
    'IP address and longer number' => ['From 1.2.3.4', 'From 1234.5.6.7'],
]);
