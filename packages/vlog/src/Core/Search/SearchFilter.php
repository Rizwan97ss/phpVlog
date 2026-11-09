<?php

namespace Rss\Vlog\Core\Search;

use DateTimeInterface;
use InvalidArgumentException;
use Rss\Vlog\Core\Exceptions\InvalidSearchPatternException;
use Rss\Vlog\Core\Support\LogLevel;

/**
 * The conditions an entry must meet to appear in search results.
 *
 * Every condition is optional, and an entry must meet all of the ones given.
 */
final class SearchFilter
{
    /**
     * @var list<string>|null
     */
    private ?array $levels;

    private ?int $from;

    private ?int $to;

    private ?TextMatcher $matcher;

    private ?int $group;

    /**
     * @param  list<string>|null  $levels  Levels to include, which may include "unknown".
     * @param  DateTimeInterface|null  $from  The earliest entry time to include.
     * @param  DateTimeInterface|null  $to  The latest entry time to include.
     * @param  string|null  $query  Text to find anywhere in the entry, including its context and stack trace.
     * @param  bool  $regex  Whether the query is a regular expression, written without delimiters.
     * @param  int|null  $group  A group number from the index, to find only that group's entries.
     *
     * @throws InvalidArgumentException When a level isn't recognized.
     * @throws InvalidSearchPatternException
     */
    public function __construct(
        ?array $levels = null,
        ?DateTimeInterface $from = null,
        ?DateTimeInterface $to = null,
        ?string $query = null,
        bool $regex = false,
        bool $caseSensitive = false,
        ?int $group = null
    ) {
        $this->group = $group;
        $this->levels = $levels === null ? null : $this->normalizeLevels($levels);
        $this->from = $from === null ? null : $from->getTimestamp();
        $this->to = $to === null ? null : $to->getTimestamp();

        if ($query === null || $query === '') {
            $this->matcher = null;
        } else {
            $this->matcher = $regex
                ? TextMatcher::regex($query, $caseSensitive)
                : TextMatcher::plain($query, $caseSensitive);
        }
    }

    /**
     * Determine whether the filter has a text query, which needs each
     * entry's text to be read.
     */
    public function hasQuery(): bool
    {
        return $this->matcher !== null;
    }

    /**
     * Get the chosen levels when they're the filter's only condition, so the
     * number of matches can be known from the index's level counts.
     *
     * @return list<string>|null
     */
    public function onlyLevels(): ?array
    {
        if ($this->matcher !== null || $this->from !== null || $this->to !== null || $this->group !== null) {
            return null;
        }

        return $this->levels;
    }

    /**
     * Determine whether an entry with this level and time can match.
     *
     * Entries without a known time never match a date range.
     *
     * @param  string|null  $level  A standard level name, or null when it isn't recognized.
     * @param  int  $group  The entry's group number, or 0 when it isn't grouped.
     */
    public function acceptsHeader(?string $level, ?int $timestamp, int $group = 0): bool
    {
        if ($this->group !== null && $group !== $this->group) {
            return false;
        }

        if ($this->levels !== null && ! in_array($level ?? LogLevel::UNKNOWN, $this->levels, true)) {
            return false;
        }

        if ($this->from === null && $this->to === null) {
            return true;
        }

        return $timestamp !== null
            && ($this->from === null || $timestamp >= $this->from)
            && ($this->to === null || $timestamp <= $this->to);
    }

    /**
     * Determine whether an entry's text matches the query. Text always
     * matches when there's no query.
     */
    public function acceptsText(string $text): bool
    {
        return $this->matcher === null || $this->matcher->matches($text);
    }

    /**
     * @param  list<string>  $levels
     * @return list<string>
     *
     * @throws InvalidArgumentException
     */
    private function normalizeLevels(array $levels): array
    {
        $normalized = [];

        foreach ($levels as $level) {
            $standard = strtolower(trim($level)) === LogLevel::UNKNOWN ? LogLevel::UNKNOWN : LogLevel::normalize($level);

            if ($standard === null) {
                throw new InvalidArgumentException("The level [{$level}] is not a recognized log level.");
            }

            $normalized[] = $standard;
        }

        return array_values(array_unique($normalized));
    }
}
