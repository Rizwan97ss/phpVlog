<?php

namespace Rss\Vlog\Core\Parsers;

use Rss\Vlog\Core\Entry\EntryHeader;
use Rss\Vlog\Core\Entry\LogEntry;
use Rss\Vlog\Core\Support\Time;

/**
 * Reads the format CodeIgniter 3 and 4 write by default:
 * "ERROR - 2026-11-09 10:15:32 --> message".
 */
final class CodeIgniterParser implements Parser
{
    private const HEADER = '/^([A-Za-z]+) - (\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) -->\s?(.*)$/';

    public function name(): string
    {
        return 'codeigniter';
    }

    public function startsEntry(string $line): bool
    {
        return preg_match(self::HEADER, $line) === 1;
    }

    public function headerOf(string $line): ?EntryHeader
    {
        if (preg_match(self::HEADER, $line, $matches) !== 1) {
            return null;
        }

        return EntryHeader::fromText($matches[1], $matches[2], $matches[3]);
    }

    /**
     * CodeIgniter 3 log files start with a PHP guard line that stops them
     * being served directly, so it's skipped along with blank lines.
     */
    public function isNoise(string $line): bool
    {
        $line = trim($line);

        return $line === '' || strpos($line, '<?php') === 0;
    }

    public function parse(string $text): LogEntry
    {
        $text = rtrim($text);
        $lines = explode("\n", $text, 2);

        if (preg_match(self::HEADER, $lines[0], $matches) !== 1) {
            return new LogEntry($this->name(), $text, $text);
        }

        $rest = $lines[1] ?? '';
        $message = $matches[3].($rest === '' ? '' : "\n".$rest);

        return new LogEntry(
            $this->name(),
            $text,
            $message,
            $matches[1],
            Time::parse($matches[2]),
            null,
            null,
            StackTraceParser::fromText($rest)
        );
    }
}
