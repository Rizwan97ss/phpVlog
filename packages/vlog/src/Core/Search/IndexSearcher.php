<?php

namespace Rss\Vlog\Core\Search;

use Rss\Vlog\Core\Exceptions\LogFileException;
use Rss\Vlog\Core\Index\IndexStorage;
use Rss\Vlog\Core\Reader\LogReader;

/**
 * Searches a log file's index in batches.
 *
 * The level and time conditions are checked against the index records, so
 * only entries that pass them are read from the log file, and only entries
 * that match are parsed.
 *
 * A cursor is the record number where the next search continues. Searching
 * newest first, it continues with the records before the cursor; searching
 * oldest first, with the record at the cursor and those after it.
 */
final class IndexSearcher
{
    /**
     * How many index records are checked at a time.
     */
    private const BATCH_SIZE = 2048;

    private const MAX_LIMIT = 1000;

    /**
     * A lower PCRE backtracking limit used while searching, so a pattern with
     * catastrophic backtracking fails quickly instead of stalling the request.
     */
    private const BACKTRACK_LIMIT = 100000;

    private IndexStorage $storage;

    private LogReader $reader;

    private string $path;

    private int $count;

    private int $indexedBytes;

    /**
     * @var array<string, int>
     */
    private array $levelCounts;

    /**
     * @param  array<string, int>  $levelCounts  The number of indexed entries at each level.
     */
    public function __construct(
        IndexStorage $storage,
        LogReader $reader,
        string $path,
        int $count,
        int $indexedBytes,
        array $levelCounts
    ) {
        $this->storage = $storage;
        $this->reader = $reader;
        $this->path = $path;
        $this->count = $count;
        $this->indexedBytes = $indexedBytes;
        $this->levelCounts = $levelCounts;
    }

    /**
     * @param  float|null  $deadline  A microtime(true) value to stop searching at. At least one batch is always searched.
     *
     * @throws LogFileException
     */
    public function search(SearchFilter $filter, int $limit, ?int $cursor, bool $newestFirst, ?float $deadline): SearchResult
    {
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $position = max(0, min($this->count, $cursor ?? ($newestFirst ? $this->count : 0)));
        $matches = [];
        $searchedBatch = false;

        // A new search of levels alone can stop once it has found every match.
        $totalMatches = $cursor === null ? $this->knownMatchCount($filter) : null;

        if ($totalMatches === 0) {
            return $this->result([], null, false);
        }

        $previousLimit = $filter->hasQuery() ? $this->lowerBacktrackLimit() : null;

        try {
            while ($newestFirst ? $position > 0 : $position < $this->count) {
                if ($searchedBatch && $deadline !== null && microtime(true) >= $deadline) {
                    return $this->result($matches, $position, true);
                }

                $batchStart = $newestFirst ? max(0, $position - self::BATCH_SIZE) : $position;
                $batchEnd = $newestFirst ? $position : min($this->count, $position + self::BATCH_SIZE);
                $candidates = $this->candidates($filter, $batchStart, $batchEnd);

                if ($candidates === null) {
                    break;
                }

                if ($newestFirst) {
                    $candidates = array_reverse($candidates, true);
                }

                foreach ($this->matching($filter, $candidates) as $record => $match) {
                    $matches[] = $match;

                    if (count($matches) === $totalMatches) {
                        return $this->result($matches, null, false);
                    }

                    if (count($matches) === $limit) {
                        $next = $newestFirst ? $record : $record + 1;
                        $hasMore = $newestFirst ? $next > 0 : $next < $this->count;

                        return $this->result($matches, $hasMore ? $next : null, false);
                    }
                }

                $position = $newestFirst ? $batchStart : $batchEnd;
                $searchedBatch = true;
            }
        } finally {
            if ($previousLimit !== null) {
                ini_set('pcre.backtrack_limit', $previousLimit);
            }
        }

        return $this->result($matches, null, false);
    }

    /**
     * Get the number of matching entries from the level counts, or null when
     * the filter has conditions other than levels.
     */
    private function knownMatchCount(SearchFilter $filter): ?int
    {
        $levels = $filter->onlyLevels();

        if ($levels === null) {
            return null;
        }

        $total = 0;

        foreach ($levels as $level) {
            $total += $this->levelCounts[$level] ?? 0;
        }

        return $total;
    }

    /**
     * Get the positions of the records in a batch that pass the level and
     * time conditions, keyed by record number. Returns null when the index
     * file has fewer records than expected.
     *
     * @return array<int, array{offset: int, length: int}>|null
     */
    private function candidates(SearchFilter $filter, int $batchStart, int $batchEnd): ?array
    {
        // Read one extra record, when there is one, to find where the last entry ends.
        $records = $this->storage->readRecords($batchStart, $batchEnd - $batchStart + 1);

        if (count($records) < $batchEnd - $batchStart) {
            return null;
        }

        $candidates = [];

        for ($record = $batchStart; $record < $batchEnd; $record++) {
            $current = $records[$record - $batchStart];

            if (! $filter->acceptsHeader($current['level'], $current['timestamp'], $current['group'])) {
                continue;
            }

            $next = $records[$record - $batchStart + 1] ?? null;
            $end = $record + 1 < $this->count && $next !== null ? $next['offset'] : $this->indexedBytes;

            $candidates[$record] = ['offset' => $current['offset'], 'length' => max(0, $end - $current['offset'])];
        }

        return $candidates;
    }

    /**
     * Keep the candidates whose text matches the query.
     *
     * @param  array<int, array{offset: int, length: int}>  $candidates
     * @return array<int, array{offset: int, length: int}>
     */
    private function matching(SearchFilter $filter, array $candidates): array
    {
        if (! $filter->hasQuery() || $candidates === []) {
            return $candidates;
        }

        $records = array_keys($candidates);
        $texts = $this->reader->textsAt($this->path, array_values($candidates));
        $matching = [];

        foreach ($texts as $index => $text) {
            if ($filter->acceptsText($text)) {
                $matching[$records[$index]] = $candidates[$records[$index]];
            }
        }

        return $matching;
    }

    /**
     * @param  list<array{offset: int, length: int}>  $matches
     *
     * @throws LogFileException
     */
    private function result(array $matches, ?int $nextCursor, bool $reachedTimeLimit): SearchResult
    {
        return new SearchResult($this->reader->entriesAt($this->path, $matches), $nextCursor, $reachedTimeLimit);
    }

    /**
     * Lower PCRE's backtracking limit when it's higher than the search limit.
     *
     * @return string|null The previous limit to restore, or null when it wasn't changed.
     */
    private function lowerBacktrackLimit(): ?string
    {
        $current = ini_get('pcre.backtrack_limit');

        if ($current === false || (int) $current <= self::BACKTRACK_LIMIT) {
            return null;
        }

        return ini_set('pcre.backtrack_limit', (string) self::BACKTRACK_LIMIT) === false ? null : $current;
    }
}
