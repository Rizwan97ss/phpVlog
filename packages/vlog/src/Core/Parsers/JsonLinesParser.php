<?php

namespace Rss\Vlog\Core\Parsers;

use Rss\Vlog\Core\Entry\EntryHeader;
use Rss\Vlog\Core\Entry\LogEntry;
use Rss\Vlog\Core\Entry\StackFrame;
use Rss\Vlog\Core\Support\Json;
use Rss\Vlog\Core\Support\LogLevel;
use Rss\Vlog\Core\Support\Time;

/**
 * Reads one JSON object per line, as Monolog's JSON formatter writes them:
 * {"message":"...","context":{},"level":400,"level_name":"ERROR",...}.
 */
final class JsonLinesParser implements Parser
{
    public function name(): string
    {
        return 'json';
    }

    public function startsEntry(string $line): bool
    {
        return $this->record($line) !== null;
    }

    public function headerOf(string $line): ?EntryHeader
    {
        $record = $this->record($line);

        if ($record === null) {
            return null;
        }

        return EntryHeader::fromText(
            $this->level($record),
            $this->stringValue($record['datetime'] ?? null),
            $this->stringValue($record['message']) ?? ''
        );
    }

    public function isNoise(string $line): bool
    {
        return trim($line) === '';
    }

    public function parse(string $text): LogEntry
    {
        $text = rtrim($text);
        $lines = explode("\n", $text, 2);
        $record = $this->record($lines[0]);

        if ($record === null) {
            return new LogEntry($this->name(), $text, $text);
        }

        $context = isset($record['context']) && is_array($record['context']) ? $record['context'] : null;

        return new LogEntry(
            $this->name(),
            $text,
            $this->stringValue($record['message']) ?? '',
            $this->level($record),
            Time::parse($this->stringValue($record['datetime'] ?? null) ?? ''),
            $this->stringValue($record['channel'] ?? null),
            $context,
            $this->stackFrames($context)
        );
    }

    /**
     * Decode a line as a log record, or return null when it isn't a JSON
     * object with a message.
     *
     * @return array<array-key, mixed>|null
     */
    private function record(string $line): ?array
    {
        $line = ltrim($line);

        if (! isset($line[0]) || $line[0] !== '{') {
            return null;
        }

        $record = Json::decodeArray($line);

        return $record !== null && array_key_exists('message', $record) ? $record : null;
    }

    /**
     * Get the level from "level_name", or from "level" as a name or a number.
     *
     * @param  array<array-key, mixed>  $record
     */
    private function level(array $record): ?string
    {
        if (isset($record['level_name']) && is_string($record['level_name'])) {
            return $record['level_name'];
        }

        $level = $record['level'] ?? null;

        if (is_int($level)) {
            return LogLevel::fromSeverity($level);
        }

        return is_string($level) ? $level : null;
    }

    /**
     * Get the frames of a logged exception, which Monolog writes either as a
     * normalized array with a "trace" of "file:line" strings, or as text.
     *
     * @param  array<array-key, mixed>|null  $context
     * @return list<StackFrame>
     */
    private function stackFrames(?array $context): array
    {
        $exception = $context['exception'] ?? null;

        if (is_array($exception) && isset($exception['trace']) && is_array($exception['trace'])) {
            return StackTraceParser::fromLocations($exception['trace']);
        }

        return is_string($exception) ? StackTraceParser::fromText($exception) : [];
    }

    /**
     * Convert a scalar to a string, or return null for anything else.
     *
     * @param  mixed  $value
     */
    private function stringValue($value): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        return is_scalar($value) ? (string) $value : null;
    }
}
