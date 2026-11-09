<?php

namespace Rss\Vlog\Core\Grouping;

use Rss\Vlog\Core\Entry\EntryHeader;

/**
 * Counts how often each kind of entry occurs in a log file, with when it was
 * first and last seen and how many times it occurred each day.
 *
 * Groups are numbered from 1. The number is stored in each entry's index
 * record, so a group's entries can be found without reading the log file.
 *
 * @phpstan-type Group array{
 *     id: int,
 *     key: string,
 *     level: string|null,
 *     pattern: string,
 *     sample: string,
 *     count: int,
 *     first_seen: int|null,
 *     last_seen: int|null,
 *     first_offset: int,
 *     last_offset: int,
 *     days: array<string, int>
 * }
 */
final class Groups
{
    /**
     * The most groups tracked per file. Entries that would start a new group
     * after this are counted as ungrouped.
     */
    public const MAX_GROUPS = 5000;

    /**
     * The most days of daily counts kept per group.
     */
    public const MAX_DAYS = 90;

    /**
     * The most characters of a group's sample message kept.
     */
    private const MAX_SAMPLE_LENGTH = 500;

    /**
     * How many recent messages remember their key, so repeated messages skip
     * building their pattern again.
     */
    private const CACHE_SIZE = 1000;

    /**
     * @var array<string, Group>
     */
    private array $groups = [];

    private int $nextId = 1;

    private int $ungrouped = 0;

    /**
     * @var array<string, string>
     */
    private array $cache = [];

    private string $day = '';

    private int $dayStart = 0;

    private int $dayEnd = 0;

    /**
     * Restore groups saved with toArray(), or return null when the data isn't
     * valid or was saved for a different point in the file.
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data, int $indexedBytes): ?self
    {
        if (($data['indexedBytes'] ?? null) !== $indexedBytes
            || ! is_int($data['nextId'] ?? null)
            || ! is_int($data['ungrouped'] ?? null)
            || ! is_array($data['groups'] ?? null)) {
            return null;
        }

        $groups = new self;
        $groups->nextId = $data['nextId'];
        $groups->ungrouped = $data['ungrouped'];

        foreach ($data['groups'] as $group) {
            $restored = is_array($group) ? self::restore($group) : null;

            if ($restored === null) {
                return null;
            }

            $groups->groups[$restored['key']] = $restored;
        }

        return $groups;
    }

    /**
     * @param  int  $indexedBytes  The point in the file these groups are up to date with.
     * @return array<string, mixed>
     */
    public function toArray(int $indexedBytes): array
    {
        return [
            'indexedBytes' => $indexedBytes,
            'nextId' => $this->nextId,
            'ungrouped' => $this->ungrouped,
            'groups' => array_values($this->groups),
        ];
    }

    /**
     * Count an entry in its group, and get the group's number, or 0 when the
     * entry isn't grouped because there are too many groups.
     */
    public function add(int $offset, EntryHeader $header): int
    {
        $message = $header->message() ?? '';
        $level = $header->level();
        $cacheKey = ($level ?? '').'|'.Fingerprint::shapeKey($message);
        $key = $this->cache[$cacheKey] ?? null;
        $pattern = null;

        if ($key === null) {
            $pattern = Fingerprint::pattern($message);
            $key = Fingerprint::key($level, $pattern);
            $this->remember($cacheKey, $key);
        }

        if (! isset($this->groups[$key])) {
            if (count($this->groups) >= self::MAX_GROUPS) {
                $this->ungrouped++;

                return 0;
            }

            $this->groups[$key] = [
                'id' => $this->nextId++,
                'key' => $key,
                'level' => $level,
                'pattern' => $pattern ?? Fingerprint::pattern($message),
                'sample' => substr($message, 0, self::MAX_SAMPLE_LENGTH),
                'count' => 0,
                'first_seen' => null,
                'last_seen' => null,
                'first_offset' => $offset,
                'last_offset' => $offset,
                'days' => [],
            ];
        }

        $group = &$this->groups[$key];
        $timestamp = $header->timestamp();
        $group['count']++;
        $group['last_offset'] = $offset;
        $group['sample'] = substr($message, 0, self::MAX_SAMPLE_LENGTH);

        if ($timestamp !== null) {
            $group['first_seen'] = $group['first_seen'] === null ? $timestamp : min($group['first_seen'], $timestamp);
            $group['last_seen'] = $group['last_seen'] === null ? $timestamp : max($group['last_seen'], $timestamp);

            $day = $this->dayOf($timestamp);
            $group['days'][$day] = ($group['days'][$day] ?? 0) + 1;

            if (count($group['days']) > self::MAX_DAYS) {
                ksort($group['days']);
                $group['days'] = array_slice($group['days'], -self::MAX_DAYS, null, true);
            }
        }

        $id = $group['id'];
        unset($group);

        return $id;
    }

    /**
     * @return list<Group>
     */
    public function all(): array
    {
        return array_values($this->groups);
    }

    /**
     * Get the number of entries that weren't grouped because there were too
     * many groups.
     */
    public function ungrouped(): int
    {
        return $this->ungrouped;
    }

    /**
     * Get the "Y-m-d" date of a timestamp, reusing the last one while entries
     * are on the same day.
     */
    private function dayOf(int $timestamp): string
    {
        if ($timestamp < $this->dayStart || $timestamp >= $this->dayEnd) {
            $this->day = date('Y-m-d', $timestamp);
            $this->dayStart = (int) strtotime($this->day.' 00:00:00');
            $this->dayEnd = (int) strtotime('+1 day', $this->dayStart);
        }

        return $this->day;
    }

    private function remember(string $message, string $key): void
    {
        if (count($this->cache) >= self::CACHE_SIZE) {
            $this->cache = [];
        }

        $this->cache[$message] = $key;
    }

    /**
     * @param  array<array-key, mixed>  $group
     * @return Group|null
     */
    private static function restore(array $group): ?array
    {
        $level = $group['level'] ?? null;
        $firstSeen = $group['first_seen'] ?? null;
        $lastSeen = $group['last_seen'] ?? null;

        if (! is_int($group['id'] ?? null)
            || ! is_string($group['key'] ?? null)
            || ! (is_string($level) || $level === null)
            || ! is_string($group['pattern'] ?? null)
            || ! is_string($group['sample'] ?? null)
            || ! is_int($group['count'] ?? null)
            || ! (is_int($firstSeen) || $firstSeen === null)
            || ! (is_int($lastSeen) || $lastSeen === null)
            || ! is_int($group['first_offset'] ?? null)
            || ! is_int($group['last_offset'] ?? null)
            || ! is_array($group['days'] ?? null)) {
            return null;
        }

        $days = [];

        foreach ($group['days'] as $day => $count) {
            if (is_string($day) && is_int($count)) {
                $days[$day] = $count;
            }
        }

        return [
            'id' => $group['id'],
            'key' => $group['key'],
            'level' => $level,
            'pattern' => $group['pattern'],
            'sample' => $group['sample'],
            'count' => $group['count'],
            'first_seen' => $firstSeen,
            'last_seen' => $lastSeen,
            'first_offset' => $group['first_offset'],
            'last_offset' => $group['last_offset'],
            'days' => $days,
        ];
    }
}
