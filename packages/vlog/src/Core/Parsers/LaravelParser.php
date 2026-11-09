<?php

namespace Rss\Vlog\Core\Parsers;

use Rss\Vlog\Core\Entry\EntryHeader;
use Rss\Vlog\Core\Entry\LogEntry;
use Rss\Vlog\Core\Support\Json;
use Rss\Vlog\Core\Support\Time;

/**
 * Reads Monolog's line format, which Laravel writes by default:
 * "[2026-11-09 10:15:32] local.ERROR: message {context} {extra}".
 */
final class LaravelParser implements Parser
{
    private const HEADER = '/^\[(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?)\]\s(\S+)\.([A-Za-z]+):(?:\s(.*))?$/';

    /**
     * The most positions to try when looking for where the context starts.
     */
    private const MAX_CONTEXT_ATTEMPTS = 20;

    public function name(): string
    {
        return 'laravel';
    }

    public function startsEntry(string $line): bool
    {
        return isset($line[0]) && $line[0] === '[' && preg_match(self::HEADER, $line) === 1;
    }

    public function headerOf(string $line): ?EntryHeader
    {
        if (! isset($line[0]) || $line[0] !== '[' || preg_match(self::HEADER, $line, $matches) !== 1) {
            return null;
        }

        return EntryHeader::fromText($matches[3], $matches[1], $matches[4] ?? '');
    }

    public function isNoise(string $line): bool
    {
        return trim($line) === '';
    }

    public function parse(string $text): LogEntry
    {
        $text = rtrim($text);
        $lines = explode("\n", $text, 2);

        if (preg_match(self::HEADER, $lines[0], $matches) !== 1) {
            return new LogEntry($this->name(), $text, $text);
        }

        $rest = $lines[1] ?? '';
        $body = ($matches[4] ?? '').($rest === '' ? '' : "\n".$rest);
        [$message, $context] = $this->splitContext($body);

        $exception = $context['exception'] ?? null;
        $frames = StackTraceParser::fromText(is_string($exception) ? $exception : $rest);

        return new LogEntry(
            $this->name(),
            $text,
            $message,
            $matches[3],
            Time::parse($matches[1]),
            $matches[2],
            $context,
            $frames
        );
    }

    /**
     * Split the text after the header into the message and its JSON context.
     *
     * Monolog writes the context as JSON after the message, but messages can
     * contain JSON too. The context is taken to be the last JSON value that
     * starts on the first line and runs to the end of the entry.
     *
     * @return array{0: string, 1: array<array-key, mixed>|null}
     */
    private function splitContext(string $body): array
    {
        $newline = strpos($body, "\n");
        $firstLine = $newline === false ? $body : substr($body, 0, $newline);

        // Monolog 1 and 2 write an empty "extra" array after the context.
        if (preg_match('/[\]}] \[\]$/', $body) === 1) {
            $body = substr($body, 0, -3);
        }

        $before = strlen($firstLine);

        for ($attempt = 0; $attempt < self::MAX_CONTEXT_ATTEMPTS; $attempt++) {
            $start = $this->previousJsonStart($firstLine, $before);

            if ($start === null) {
                break;
            }

            $context = Json::decodeArray((string) substr($body, $start));

            if ($context !== null) {
                return [rtrim(substr($body, 0, $start)), $context];
            }

            $before = $start;
        }

        return [$body, null];
    }

    /**
     * Find the last "{" or "[" before a position that starts the line or
     * follows a space, since that's where Monolog places the context.
     */
    private function previousJsonStart(string $line, int $before): ?int
    {
        while ($before > 0) {
            $candidate = max(
                (int) strrpos(substr($line, 0, $before), '{'),
                (int) strrpos(substr($line, 0, $before), '[')
            );

            if ($candidate === 0) {
                return $line[0] === '{' || $line[0] === '[' ? 0 : null;
            }

            if ($line[$candidate - 1] === ' ') {
                return $candidate;
            }

            $before = $candidate;
        }

        return null;
    }
}
