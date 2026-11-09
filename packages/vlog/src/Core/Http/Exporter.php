<?php

namespace Rss\Vlog\Core\Http;

use Rss\Vlog\Core\Exceptions\LogFileException;
use Rss\Vlog\Core\Index\LogIndex;
use Rss\Vlog\Core\Search\SearchFilter;

/**
 * Writes the entries that match a filter as JSON, CSV or plain text, with
 * secrets hidden.
 */
final class Exporter
{
    /**
     * The most entries one export includes.
     */
    public const MAX_ENTRIES = 10000;

    public const FORMATS = ['json', 'csv', 'txt'];

    private const CONTENT_TYPES = [
        'json' => 'application/json; charset=utf-8',
        'csv' => 'text/csv; charset=utf-8',
        'txt' => 'text/plain; charset=utf-8',
    ];

    private const CSV_COLUMNS = ['time', 'level', 'channel', 'message', 'context'];

    private Presenter $presenter;

    public function __construct(Presenter $presenter)
    {
        $this->presenter = $presenter;
    }

    public static function contentType(string $format): string
    {
        return self::CONTENT_TYPES[$format] ?? self::CONTENT_TYPES['txt'];
    }

    /**
     * Write the matching entries in a format, one chunk at a time.
     *
     * @param  callable(string): void  $write
     */
    public function export(LogIndex $index, SearchFilter $filter, bool $newestFirst, string $format, callable $write): void
    {
        $exported = 0;
        $cursor = null;

        $write($this->header($format));

        try {
            do {
                $result = $index->search($filter, 1000, $cursor, $newestFirst);

                foreach ($result->entries() as $entry) {
                    if ($exported >= self::MAX_ENTRIES) {
                        break 2;
                    }

                    $write($this->row($format, $this->presenter->entry($entry), $exported === 0));
                    $exported++;
                }

                $cursor = $result->nextCursor();
            } while ($cursor !== null);
        } catch (LogFileException $exception) {
            // The file was removed while exporting. Finish with what was written.
        }

        $write($format === 'json' ? "\n]\n" : '');
    }

    private function header(string $format): string
    {
        if ($format === 'json') {
            return '[';
        }

        return $format === 'csv' ? $this->csvLine(self::CSV_COLUMNS) : '';
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function row(string $format, array $entry, bool $isFirst): string
    {
        $context = $entry['context'] === null ? '' : (string) json_encode($entry['context'], ApiResponse::JSON_OPTIONS);

        if ($format === 'json') {
            return ($isFirst ? "\n" : ",\n").json_encode($entry, ApiResponse::JSON_OPTIONS);
        }

        $time = is_string($entry['time']) ? $entry['time'] : '';
        $level = is_string($entry['level']) ? $entry['level'] : (is_string($entry['raw_level']) ? $entry['raw_level'] : '');
        $channel = is_string($entry['channel']) ? $entry['channel'] : '';
        $message = is_string($entry['message']) ? $entry['message'] : '';

        if ($format === 'csv') {
            return $this->csvLine([$time, $level, $channel, $message, $context]);
        }

        $prefix = trim('['.$time.'] '.($channel === '' ? '' : $channel.'.').strtoupper($level));

        return $prefix.': '.$message.($context === '' ? '' : ' '.$context)."\n";
    }

    /**
     * Build one CSV line. Values that a spreadsheet would run as a formula
     * are prefixed with a quote, since log content can't be trusted.
     *
     * @param  list<string>  $values
     */
    private function csvLine(array $values): string
    {
        $cells = array_map(function (string $value): string {
            if ($value !== '' && strpbrk($value[0], "=+-@\t\r") !== false) {
                $value = "'".$value;
            }

            return '"'.str_replace('"', '""', $value).'"';
        }, $values);

        return implode(',', $cells)."\r\n";
    }
}
