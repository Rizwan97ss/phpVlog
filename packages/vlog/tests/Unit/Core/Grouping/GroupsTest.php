<?php

use Rss\Vlog\Core\Entry\EntryHeader;
use Rss\Vlog\Core\Grouping\Groups;

/**
 * A header logged at a UTC date and time.
 */
function vlogHeader(string $level, string $time, string $message): EntryHeader
{
    return new EntryHeader($level, (new DateTimeImmutable($time, new DateTimeZone('UTC')))->getTimestamp(), $message);
}

beforeEach(function () {
    $this->previousTimezone = date_default_timezone_get();
    date_default_timezone_set('UTC');
});

afterEach(function () {
    date_default_timezone_set($this->previousTimezone);
});

it('counts similar entries in one group with when they were first and last seen', function () {
    $groups = new Groups;

    $first = $groups->add(0, vlogHeader('error', '2026-11-08 10:00:00', 'Order 1 failed'));
    $other = $groups->add(50, vlogHeader('info', '2026-11-08 11:00:00', 'Order 2 placed'));
    $second = $groups->add(100, vlogHeader('error', '2026-11-09 09:00:00', 'Order 2 failed'));

    $group = $groups->all()[0];

    expect([$first, $other, $second])->toBe([1, 2, 1]);
    expect($group['pattern'])->toBe('Order {n} failed');
    expect($group['count'])->toBe(2);
    expect($group['first_seen'])->toBe(strtotime('2026-11-08 10:00:00 UTC'));
    expect($group['last_seen'])->toBe(strtotime('2026-11-09 09:00:00 UTC'));
    expect([$group['first_offset'], $group['last_offset']])->toBe([0, 100]);
    expect($group['days'])->toBe(['2026-11-08' => 1, '2026-11-09' => 1]);
});

it('keeps the latest message as the sample', function () {
    $groups = new Groups;
    $groups->add(0, vlogHeader('error', '2026-11-08 10:00:00', 'Order 1 failed'));
    $groups->add(1, vlogHeader('error', '2026-11-08 10:01:00', 'Order 2 failed'));

    expect($groups->all()[0]['sample'])->toBe('Order 2 failed');
});

it('keeps the same message at different levels in separate groups', function () {
    $groups = new Groups;

    expect($groups->add(0, vlogHeader('error', '2026-11-08 10:00:00', 'Timeout')))->toBe(1);
    expect($groups->add(1, vlogHeader('warning', '2026-11-08 10:00:00', 'Timeout')))->toBe(2);
});

it('keeps only the most recent days of daily counts', function () {
    $groups = new Groups;

    for ($day = 0; $day < Groups::MAX_DAYS + 5; $day++) {
        $groups->add($day, vlogHeader('error', '2026-01-01 00:00:00 +'.$day.' days', 'Failed'));
    }

    $days = $groups->all()[0]['days'];

    expect($days)->toHaveCount(Groups::MAX_DAYS);
    expect(array_key_first($days))->toBe('2026-01-06');
});

it('restores groups saved for the same point in the file', function () {
    $groups = new Groups;
    $groups->add(0, vlogHeader('error', '2026-11-08 10:00:00', 'Order 1 failed'));

    $restored = Groups::fromArray($groups->toArray(120), 120);

    expect($restored->all())->toBe($groups->all());
    expect($restored->add(130, vlogHeader('error', '2026-11-08 10:05:00', 'Order 9 failed')))->toBe(1);
    expect($restored->add(140, vlogHeader('error', '2026-11-08 10:05:00', 'Something new')))->toBe(2);
});

it('does not restore groups saved for a different point in the file or with invalid data', function (array $data) {
    expect(Groups::fromArray($data, 120))->toBeNull();
})->with([
    'different point' => [(new Groups)->toArray(80)],
    'missing fields' => [['indexedBytes' => 120]],
    'invalid group' => [['indexedBytes' => 120, 'nextId' => 2, 'ungrouped' => 0, 'groups' => [['id' => 'one']]]],
]);
