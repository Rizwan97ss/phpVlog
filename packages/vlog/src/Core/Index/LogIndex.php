<?php

namespace Rss\Vlog\Core\Index;

use Rss\Vlog\Core\Entry\EntryHeader;
use Rss\Vlog\Core\Entry\LogEntry;
use Rss\Vlog\Core\Exceptions\IndexException;
use Rss\Vlog\Core\Exceptions\LogFileException;
use Rss\Vlog\Core\Grouping\Groups;
use Rss\Vlog\Core\Parsers\FormatDetector;
use Rss\Vlog\Core\Parsers\Parser;
use Rss\Vlog\Core\Reader\LogFile;
use Rss\Vlog\Core\Reader\LogReader;
use Rss\Vlog\Core\Search\IndexSearcher;
use Rss\Vlog\Core\Search\SearchFilter;
use Rss\Vlog\Core\Search\SearchResult;
use Rss\Vlog\Core\Support\LogLevel;

/**
 * An index of where each entry in a log file starts, used to page through
 * large files without reading them from the beginning.
 *
 * The index is updated by scanning only the bytes added since the last
 * refresh. It's rebuilt when the file is truncated or replaced, such as by
 * log rotation.
 *
 * @phpstan-import-type IndexState from IndexStorage
 */
final class LogIndex
{
    /**
     * How many bytes at the start of the file are compared to detect that the
     * file was replaced.
     */
    private const FINGERPRINT_BYTES = 1024;

    /**
     * How many encoded records are buffered before writing them to disk.
     */
    private const RECORDS_PER_WRITE = 4096;

    private const MAX_PER_PAGE = 1000;

    private string $path;

    private Parser $parser;

    private IndexStorage $storage;

    private LogReader $reader;

    /**
     * @var IndexState
     */
    private array $state;

    public function __construct(string $path, string $indexDirectory, Parser $parser, int $maxEntryBytes = 1048576)
    {
        $this->path = $path;
        $this->parser = $parser;
        $this->storage = new IndexStorage($indexDirectory, $path);
        $this->reader = new LogReader($parser, $maxEntryBytes);
        $this->state = $this->loadState();
    }

    /**
     * Open the index for a log file, detecting the file's format.
     *
     * The index isn't updated until refresh() is called.
     *
     * @throws LogFileException
     */
    public static function open(string $path, string $indexDirectory, int $maxEntryBytes = 1048576): self
    {
        return new self($path, $indexDirectory, (new FormatDetector)->detect($path), $maxEntryBytes);
    }

    /**
     * Bring the index up to date with the log file.
     *
     * With a time limit, a large file is indexed over several calls, and the
     * entries indexed so far can be read in between. When another process is
     * already updating the index, this returns straight away.
     *
     * @param  float|null  $maxSeconds  The most time to spend scanning, or null for no limit.
     * @return bool Whether the index now covers the whole file.
     *
     * @throws LogFileException
     * @throws IndexException
     */
    public function refresh(?float $maxSeconds = null): bool
    {
        $finished = false;
        $deadline = $maxSeconds === null ? null : microtime(true) + $maxSeconds;

        $this->storage->withLock(function () use (&$finished, $deadline): void {
            $finished = $this->update($deadline);
        });

        $this->state = $this->loadState();

        return $finished;
    }

    /**
     * Get the name of the file's format, such as "laravel".
     */
    public function format(): string
    {
        return $this->parser->name();
    }

    /**
     * Get the number of entries indexed.
     */
    public function count(): int
    {
        return $this->state['entries'];
    }

    /**
     * Get the number of indexed entries at each level. Entries without a
     * recognized level are counted under "unknown".
     *
     * @return array<string, int>
     */
    public function levelCounts(): array
    {
        return $this->state['levels'];
    }

    /**
     * Get how many bytes of the file have been indexed.
     */
    public function indexedBytes(): int
    {
        return $this->state['indexedBytes'];
    }

    /**
     * Get one page of entries.
     *
     * @param  int  $page  The page number, starting at 1.
     * @param  int  $perPage  The entries per page, up to 1000.
     * @return list<LogEntry>
     *
     * @throws LogFileException
     */
    public function entries(int $page = 1, int $perPage = 25, bool $newestFirst = true): array
    {
        $count = $this->count();
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
        $skip = (max(1, $page) - 1) * $perPage;

        if ($skip >= $count) {
            return [];
        }

        $first = $newestFirst ? max(0, $count - $skip - $perPage) : $skip;
        $last = $newestFirst ? $count - $skip - 1 : min($count - 1, $skip + $perPage - 1);

        // Read one extra record, when there is one, to find where the last entry ends.
        $records = $this->storage->readRecords($first, $last - $first + 2);
        $positions = [];

        for ($index = 0; $index <= $last - $first && isset($records[$index]); $index++) {
            $offset = $records[$index]['offset'];
            $end = $first + $index + 1 < $count && isset($records[$index + 1])
                ? $records[$index + 1]['offset']
                : $this->state['indexedBytes'];

            $positions[] = ['offset' => $offset, 'length' => max(0, $end - $offset)];
        }

        $entries = $this->reader->entriesAt($this->path, $positions);

        return $newestFirst ? array_reverse($entries) : $entries;
    }

    /**
     * Get the entry that starts at a byte offset, or null when no indexed
     * entry starts there.
     *
     * @throws LogFileException
     */
    public function entryAt(int $offset): ?LogEntry
    {
        $low = 0;
        $high = $this->count() - 1;

        // Records are in file order, so their offsets are sorted.
        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);
            $record = $this->storage->readRecords($middle, 1)[0] ?? null;

            if ($record === null) {
                return null;
            }

            if ($record['offset'] < $offset) {
                $low = $middle + 1;
            } elseif ($record['offset'] > $offset) {
                $high = $middle - 1;
            } else {
                $next = $this->storage->readRecords($middle + 1, 1)[0] ?? null;
                $end = $middle + 1 < $this->count() && $next !== null ? $next['offset'] : $this->state['indexedBytes'];

                return $this->reader->entriesAt($this->path, [['offset' => $offset, 'length' => max(0, $end - $offset)]])[0] ?? null;
            }
        }

        return null;
    }

    /**
     * Get the groups of similar entries, with their counts and daily counts.
     *
     * @return list<array{id: int, key: string, level: string|null, pattern: string, sample: string, count: int, first_seen: int|null, last_seen: int|null, first_offset: int, last_offset: int, days: array<string, int>}>
     */
    public function groups(): array
    {
        $saved = $this->storage->readGroups();
        $groups = $saved === null ? null : Groups::fromArray($saved, $this->indexedBytes());

        return $groups === null ? [] : $groups->all();
    }

    /**
     * Find indexed entries that match a filter.
     *
     * Results come in pages: pass the result's nextCursor() to get the next
     * page. With a time limit, a search of a large file can stop before the
     * page is full, and the cursor continues it.
     *
     * @param  int  $limit  The most entries to return, up to 1000.
     * @param  int|null  $cursor  A cursor from the previous page's result, or null to start.
     * @param  float|null  $maxSeconds  The most time to spend searching, or null for no limit.
     *
     * @throws LogFileException
     */
    public function search(
        SearchFilter $filter,
        int $limit = 25,
        ?int $cursor = null,
        bool $newestFirst = true,
        ?float $maxSeconds = null
    ): SearchResult {
        $searcher = new IndexSearcher(
            $this->storage,
            $this->reader,
            $this->path,
            $this->count(),
            $this->indexedBytes(),
            $this->levelCounts()
        );

        return $searcher->search($filter, $limit, $cursor, $newestFirst, $maxSeconds === null ? null : microtime(true) + $maxSeconds);
    }

    /**
     * Scan the bytes added since the last update, rebuilding the index first
     * when it no longer matches the file. Must be called with the lock held.
     *
     * @return bool Whether the scan reached the end of the file.
     */
    private function update(?float $deadline): bool
    {
        $handle = LogFile::open($this->path);

        try {
            $state = $this->storage->readState();
            $savedGroups = $this->storage->readGroups();
            $groups = $state === null || $savedGroups === null ? null : Groups::fromArray($savedGroups, $state['indexedBytes']);

            // The groups must describe exactly the indexed part of the file,
            // or every count would be wrong, so a mismatch rebuilds the index.
            $rebuilding = $state === null || $groups === null || ! $this->matchesFile($state, $handle);

            if ($rebuilding) {
                $state = $this->emptyState();
                $groups = new Groups;
            }

            $levels = $state['levels'];
            $entries = $state['entries'];
            $firstUnwritten = $entries;
            $buffer = '';

            $onEntry = function (int $offset, EntryHeader $header) use (&$levels, &$entries, &$firstUnwritten, &$buffer, $groups): void {
                $level = $header->level() ?? LogLevel::UNKNOWN;
                $levels[$level] = ($levels[$level] ?? 0) + 1;
                $entries++;
                $buffer .= IndexStorage::encodeRecord($offset, $header->timestamp(), $header->level(), $groups->add($offset, $header));

                if ($entries - $firstUnwritten >= self::RECORDS_PER_WRITE) {
                    $this->storage->writeRecords($firstUnwritten, $buffer);
                    $firstUnwritten = $entries;
                    $buffer = '';
                }
            };

            [$indexedBytes, $finished] = (new IndexScanner($this->parser))
                ->scan($handle, $state['indexedBytes'], $state['entries'] > 0, $onEntry, $deadline);

            // Nothing new was indexed, so the saved files are already current.
            if (! $rebuilding && $indexedBytes === $state['indexedBytes']) {
                return $finished;
            }

            $this->storage->writeRecords($firstUnwritten, $buffer);
            $this->storage->writeGroups($groups->toArray($indexedBytes));

            $fingerprintBytes = min(self::FINGERPRINT_BYTES, $indexedBytes);

            $this->storage->writeState([
                'version' => IndexStorage::VERSION,
                'format' => $this->parser->name(),
                'fingerprint' => $this->fingerprint($handle, $fingerprintBytes),
                'fingerprintBytes' => $fingerprintBytes,
                'indexedBytes' => $indexedBytes,
                'entries' => $entries,
                'levels' => $levels,
            ]);

            return $finished;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Determine whether an index still describes the file: same format, not
     * truncated, and the same bytes at the start.
     *
     * @param  IndexState  $state
     * @param  resource  $handle
     */
    private function matchesFile(array $state, $handle): bool
    {
        $stat = fstat($handle);
        $size = $stat === false ? 0 : $stat['size'];

        return $state['format'] === $this->parser->name()
            && $size >= $state['indexedBytes']
            && $this->fingerprint($handle, $state['fingerprintBytes']) === $state['fingerprint'];
    }

    /**
     * @param  resource  $handle
     */
    private function fingerprint($handle, int $bytes): string
    {
        if ($bytes < 1) {
            return sha1('');
        }

        fseek($handle, 0);

        return sha1((string) fread($handle, $bytes));
    }

    /**
     * Load the stored state, ignoring an index of a different format.
     *
     * @return IndexState
     */
    private function loadState(): array
    {
        $state = $this->storage->readState();

        return $state !== null && $state['format'] === $this->parser->name() ? $state : $this->emptyState();
    }

    /**
     * @return IndexState
     */
    private function emptyState(): array
    {
        return [
            'version' => IndexStorage::VERSION,
            'format' => $this->parser->name(),
            'fingerprint' => sha1(''),
            'fingerprintBytes' => 0,
            'indexedBytes' => 0,
            'entries' => 0,
            'levels' => [],
        ];
    }
}
