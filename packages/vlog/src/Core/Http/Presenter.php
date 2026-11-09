<?php

namespace Rss\Vlog\Core\Http;

use DateTimeInterface;
use Rss\Vlog\Core\Entry\LogEntry;
use Rss\Vlog\Core\Entry\StackFrame;
use Rss\Vlog\Core\Files\LogFile;
use Rss\Vlog\Core\Security\Redactor;

/**
 * Converts log files and entries to the arrays the API sends as JSON, hiding
 * secrets in entries on the way.
 */
final class Presenter
{
    private Redactor $redactor;

    public function __construct(Redactor $redactor)
    {
        $this->redactor = $redactor;
    }

    /**
     * @return array<string, mixed>
     */
    public function file(LogFile $file): array
    {
        return [
            'id' => $file->id(),
            'name' => $file->name(),
            'path' => $file->relativePath(),
            'folder' => $file->folder(),
            'size' => $file->size(),
            'modified_at' => date(DateTimeInterface::ATOM, $file->modifiedAt()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function entry(LogEntry $entry): array
    {
        $time = $entry->time();

        return [
            'offset' => $entry->offset(),
            'length' => $entry->length(),
            'truncated' => $entry->isTruncated(),
            'level' => $entry->level(),
            'raw_level' => $entry->rawLevel(),
            'time' => $time === null ? null : $time->format(DateTimeInterface::ATOM),
            'channel' => $entry->channel(),
            'message' => $this->redactor->text($entry->message()),
            'context' => $this->redactor->context($entry->context()),
            'origin' => $this->origin($entry->context()),
            'stack' => array_map(function (StackFrame $frame): array {
                $call = $frame->call();

                return [
                    'index' => $frame->index(),
                    'file' => $frame->file(),
                    'line' => $frame->line(),
                    'call' => $call === null ? null : $this->redactor->text($call),
                ];
            }, $entry->stackFrames()),
        ];
    }

    /**
     * Get where a logged exception was thrown: Laravel writes it as
     * "... at /path/file.php:12)" and Monolog's JSON formatter as a "file"
     * value of "/path/file.php:12".
     *
     * @param  array<array-key, mixed>|null  $context
     * @return array{file: string, line: int}|null
     */
    private function origin(?array $context): ?array
    {
        $exception = $context['exception'] ?? null;
        $location = null;

        if (is_string($exception)) {
            $firstLine = strtok($exception, "\n");

            if (is_string($firstLine) && preg_match('/^.* at (.+?):(\d+)\)?\s*$/', $firstLine, $matches) === 1) {
                $location = [$matches[1], $matches[2]];
            }
        } elseif (is_array($exception) && isset($exception['file']) && is_string($exception['file'])) {
            if (preg_match('/^(.+):(\d+)$/', $exception['file'], $matches) === 1) {
                $location = [$matches[1], $matches[2]];
            }
        }

        return $location === null ? null : ['file' => $location[0], 'line' => (int) $location[1]];
    }

    /**
     * Present a group of similar entries, with its daily counts for the days
     * of the trend.
     *
     * @param  array{id: int, level: string|null, pattern: string, sample: string, count: int, first_seen: int|null, last_seen: int|null, days: array<string, int>}  $group
     * @param  list<string>  $trendDays  The days to count, as "Y-m-d" dates.
     * @return array<string, mixed>
     */
    public function group(array $group, array $trendDays): array
    {
        return [
            'id' => $group['id'],
            'level' => $group['level'],
            'pattern' => $this->redactor->text($group['pattern']),
            'sample' => $this->redactor->text($group['sample']),
            'count' => $group['count'],
            'first_seen' => $group['first_seen'] === null ? null : date(DateTimeInterface::ATOM, $group['first_seen']),
            'last_seen' => $group['last_seen'] === null ? null : date(DateTimeInterface::ATOM, $group['last_seen']),
            'trend' => array_map(function (string $day) use ($group): int {
                return $group['days'][$day] ?? 0;
            }, $trendDays),
        ];
    }

    /**
     * @param  list<LogEntry>  $entries
     * @return list<array<string, mixed>>
     */
    public function entries(array $entries): array
    {
        return array_map([$this, 'entry'], $entries);
    }
}
