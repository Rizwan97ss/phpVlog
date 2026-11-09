<?php

namespace Rss\Vlog\Core\Entry;

use Rss\Vlog\Core\Support\LogLevel;
use Rss\Vlog\Core\Support\Time;

/**
 * The level, time and message read from an entry's first line, which is all
 * the index needs without parsing the whole entry.
 */
final class EntryHeader
{
    private ?string $level;

    private ?int $timestamp;

    private ?string $message;

    /**
     * The last time parsed, since consecutive entries often share a time.
     */
    private static ?string $lastTime = null;

    private static ?int $lastTimestamp = null;

    /**
     * @param  string|null  $level  A standard level name from LogLevel.
     * @param  string|null  $message  The message text on the first line, which may include context.
     */
    public function __construct(?string $level = null, ?int $timestamp = null, ?string $message = null)
    {
        $this->level = $level;
        $this->timestamp = $timestamp;
        $this->message = $message;
    }

    /**
     * Create a header from a level, time and message as written in the log file.
     */
    public static function fromText(?string $rawLevel, ?string $time, ?string $message = null): self
    {
        return new self(
            $rawLevel === null ? null : LogLevel::normalize($rawLevel),
            $time === null ? null : self::timestampOf($time),
            $message
        );
    }

    private static function timestampOf(string $time): ?int
    {
        // Times without a zone depend on the default zone, so it's part of the key.
        $key = date_default_timezone_get().'|'.$time;

        if ($key !== self::$lastTime) {
            $parsedTime = Time::parse($time);
            self::$lastTime = $key;
            self::$lastTimestamp = $parsedTime === null ? null : $parsedTime->getTimestamp();
        }

        return self::$lastTimestamp;
    }

    /**
     * Get the standard level name, or null when the level isn't recognized.
     */
    public function level(): ?string
    {
        return $this->level;
    }

    /**
     * Get the entry's time as a Unix timestamp.
     */
    public function timestamp(): ?int
    {
        return $this->timestamp;
    }

    /**
     * Get the message text on the entry's first line.
     */
    public function message(): ?string
    {
        return $this->message;
    }
}
