<?php

namespace Rss\Vlog\Core\Parsers;

use Rss\Vlog\Core\Entry\EntryHeader;
use Rss\Vlog\Core\Entry\LogEntry;

/**
 * Treats each line as its own entry, for files in a format Vlog doesn't
 * recognize.
 */
final class PlainTextParser implements Parser
{
    public function name(): string
    {
        return 'plain';
    }

    public function startsEntry(string $line): bool
    {
        return ! $this->isNoise($line);
    }

    public function headerOf(string $line): ?EntryHeader
    {
        return $this->isNoise($line) ? null : new EntryHeader(null, null, $line);
    }

    public function isNoise(string $line): bool
    {
        return trim($line) === '';
    }

    public function parse(string $text): LogEntry
    {
        $text = rtrim($text);

        return new LogEntry($this->name(), $text, $text);
    }
}
