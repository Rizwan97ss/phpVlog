<?php

namespace Rss\Vlog\Core\Http;

use InvalidArgumentException;
use Rss\Vlog\Core\Bookmarks\BookmarkStore;
use Rss\Vlog\Core\Exceptions\IndexException;
use Rss\Vlog\Core\Exceptions\LogFileException;
use Rss\Vlog\Core\Files\LogFile;
use Rss\Vlog\Core\Files\LogFileFinder;
use Rss\Vlog\Core\Index\IndexPruner;
use Rss\Vlog\Core\Index\LogIndex;
use Rss\Vlog\Core\Search\SearchFilter;
use Rss\Vlog\Core\Security\Redactor;
use Rss\Vlog\Core\Support\LogLevel;

/**
 * The log viewer's JSON API, independent of any framework.
 *
 * Each adapter routes requests to these methods and sends the returned
 * response. Errors become JSON responses instead of exceptions, so a broken
 * log file can never break the host application's error handling.
 */
final class LogViewerApi
{
    private const MAX_PER_PAGE = 100;

    private const MAX_GROUPS = 1000;

    private const MAX_PER_FILE = 20;

    /**
     * How many days a group's trend covers.
     */
    private const TREND_DAYS = 14;

    private const STORAGE_ERROR = 'Vlog could not write its index. Check that the "index_path" folder in the Vlog config is writable.';

    private LogFileFinder $finder;

    private string $indexDirectory;

    private float $timeLimit;

    private int $maxEntryBytes;

    private Presenter $presenter;

    /**
     * @param  float  $timeLimit  The most seconds a request spends indexing or searching.
     * @param  int  $maxEntryBytes  The most bytes of an entry to read. Longer entries are truncated.
     * @param  Redactor|null  $redactor  Hides secrets in entries. Defaults to hiding the built-in secret formats.
     */
    public function __construct(
        LogFileFinder $finder,
        string $indexDirectory,
        float $timeLimit = 2.0,
        int $maxEntryBytes = 1048576,
        ?Redactor $redactor = null
    ) {
        $this->finder = $finder;
        $this->indexDirectory = $indexDirectory;
        $this->timeLimit = $timeLimit;
        $this->maxEntryBytes = $maxEntryBytes;
        $this->presenter = new Presenter($redactor ?? new Redactor);
    }

    /**
     * List the log files, and occasionally delete the indexes of log files
     * that no longer exist.
     */
    public function files(): ApiResponse
    {
        $files = $this->finder->all();

        (new IndexPruner($this->indexDirectory))->prune(array_map(function (LogFile $file): string {
            return $file->path();
        }, $files));

        return ApiResponse::ok(['data' => array_map([$this->presenter, 'file'], $files)]);
    }

    /**
     * Get a file's details and level counts, indexing new entries first.
     */
    public function file(string $id): ApiResponse
    {
        return $this->withIndex($id, function (LogFile $file, LogIndex $index, bool $complete): ApiResponse {
            return ApiResponse::ok(['data' => array_merge($this->presenter->file($file), [
                'format' => $index->format(),
                'entries' => $index->count(),
                'levels' => (object) $index->levelCounts(),
                'indexed_bytes' => $index->indexedBytes(),
                'complete' => $complete,
            ])]);
        });
    }

    /**
     * Get a page of a file's entries.
     *
     * Query parameters: page, per_page and order ("newest" or "oldest").
     *
     * @param  array<array-key, mixed>  $query
     */
    public function entries(string $id, array $query): ApiResponse
    {
        return $this->withIndex($id, function (LogFile $file, LogIndex $index, bool $complete) use ($query): ApiResponse {
            $input = new QueryInput($query);
            $page = $input->integer('page', 1, 1, PHP_INT_MAX);
            $perPage = $input->integer('per_page', 25, 1, self::MAX_PER_PAGE);
            $newestFirst = $input->choice('order', ['newest', 'oldest'], 'newest') === 'newest';

            return ApiResponse::ok([
                'data' => $this->presenter->entries($index->entries($page, $perPage, $newestFirst)),
                'meta' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $index->count(),
                    'last_page' => max(1, (int) ceil($index->count() / $perPage)),
                    'complete' => $complete,
                ],
            ]);
        });
    }

    /**
     * Search a file's entries.
     *
     * Query parameters: levels (comma-separated), from, to, q, regex, case,
     * cursor, limit and order ("newest" or "oldest").
     *
     * @param  array<array-key, mixed>  $query
     */
    public function search(string $id, array $query): ApiResponse
    {
        return $this->withIndex($id, function (LogFile $file, LogIndex $index, bool $complete) use ($query): ApiResponse {
            $input = new QueryInput($query);
            $filter = $this->filterFrom($input);

            $result = $index->search(
                $filter,
                $input->integer('limit', 25, 1, self::MAX_PER_PAGE),
                $input->optionalInteger('cursor', 0),
                $input->choice('order', ['newest', 'oldest'], 'newest') === 'newest',
                $this->timeLimit
            );

            return ApiResponse::ok([
                'data' => $this->presenter->entries($result->entries()),
                'meta' => [
                    'next_cursor' => $result->nextCursor(),
                    'has_more' => $result->hasMore(),
                    'reached_time_limit' => $result->reachedTimeLimit(),
                    'complete' => $complete,
                ],
            ]);
        });
    }

    /**
     * Search every log file, most recently modified first, returning the
     * newest matches in each.
     *
     * Query parameters: q, regex, case, levels, from, to, per_file, and
     * after (a file ID) to continue a search that reached the time limit.
     *
     * @param  array<array-key, mixed>  $query
     */
    public function searchAll(array $query): ApiResponse
    {
        try {
            $input = new QueryInput($query);
            $filter = new SearchFilter(
                $input->list('levels'),
                $input->dateTime('from'),
                $input->dateTime('to'),
                $input->string('q'),
                $input->boolean('regex'),
                $input->boolean('case')
            );
            $perFile = $input->integer('per_file', 5, 1, self::MAX_PER_FILE);
            $after = $input->string('after');
        } catch (InvalidArgumentException $exception) {
            return ApiResponse::error(422, $exception->getMessage());
        }

        $files = $this->finder->all();
        $start = 0;

        if ($after !== null) {
            $start = count($files);

            foreach ($files as $position => $file) {
                if (hash_equals($file->id(), $after)) {
                    $start = $position + 1;

                    break;
                }
            }
        }

        $deadline = microtime(true) + $this->timeLimit;
        $results = [];
        $searched = 0;
        $nextAfter = null;

        for ($position = $start; $position < count($files); $position++) {
            // Always search at least one file, so every request makes progress.
            if ($searched > 0 && microtime(true) >= $deadline) {
                $nextAfter = $files[$position - 1]->id();

                break;
            }

            $file = $files[$position];

            try {
                $index = LogIndex::open($file->path(), $this->indexDirectory, $this->maxEntryBytes);
                $complete = $index->refresh(max(0.1, $deadline - microtime(true)));
                $result = $index->search($filter, $perFile, null, true, max(0.1, $deadline - microtime(true)));
            } catch (LogFileException $exception) {
                continue;
            } catch (IndexException $exception) {
                return ApiResponse::error(500, self::STORAGE_ERROR);
            }

            $searched++;
            $finished = $complete && ! $result->reachedTimeLimit();

            if ($result->entries() !== [] || ! $finished) {
                $results[] = [
                    'file' => $this->presenter->file($file),
                    'entries' => $this->presenter->entries($result->entries()),
                    'has_more' => $result->hasMore(),
                    'complete' => $finished,
                ];
            }
        }

        return ApiResponse::ok([
            'data' => $results,
            'meta' => [
                'searched_files' => $searched,
                'total_files' => count($files),
                'next_after' => $nextAfter,
            ],
        ]);
    }

    /**
     * List the groups of similar entries in a file, with how often each
     * occurred, when it was first and last seen, and a daily trend.
     *
     * Query parameters: levels (comma-separated), sort ("count",
     * "last_seen" or "first_seen") and limit.
     *
     * @param  array<array-key, mixed>  $query
     */
    public function groups(string $id, array $query): ApiResponse
    {
        return $this->withIndex($id, function (LogFile $file, LogIndex $index, bool $complete) use ($query): ApiResponse {
            $input = new QueryInput($query);
            $levels = (new SearchFilter($input->list('levels')))->onlyLevels();
            $sort = $input->choice('sort', ['count', 'last_seen', 'first_seen'], 'count');
            $limit = $input->integer('limit', 100, 1, self::MAX_GROUPS);

            $groups = array_values(array_filter($index->groups(), function (array $group) use ($levels): bool {
                return $levels === null || in_array($group['level'] ?? LogLevel::UNKNOWN, $levels, true);
            }));

            usort($groups, function (array $first, array $second) use ($sort): int {
                return $sort === 'count'
                    ? [$second['count'], $second['last_seen']] <=> [$first['count'], $first['last_seen']]
                    : $second[$sort] <=> $first[$sort];
            });

            $trendDays = $this->trendDays($groups);

            return ApiResponse::ok([
                'data' => array_map(function (array $group) use ($trendDays): array {
                    return $this->presenter->group($group, $trendDays);
                }, array_slice($groups, 0, $limit)),
                'meta' => [
                    'total' => count($groups),
                    'trend_days' => $trendDays,
                    'complete' => $complete,
                ],
            ]);
        });
    }

    /**
     * Get the days a trend covers: the last TREND_DAYS days up to the most
     * recent day any of the groups was seen.
     *
     * @param  list<array{last_seen: int|null}>  $groups
     * @return list<string>
     */
    private function trendDays(array $groups): array
    {
        $lastSeen = 0;

        foreach ($groups as $group) {
            $lastSeen = max($lastSeen, $group['last_seen'] ?? 0);
        }

        $end = $lastSeen > 0 ? $lastSeen : time();
        $days = [];

        for ($daysAgo = self::TREND_DAYS - 1; $daysAgo >= 0; $daysAgo--) {
            $days[] = date('Y-m-d', strtotime('-'.$daysAgo.' days', $end) ?: $end);
        }

        return $days;
    }

    /**
     * Download the entries that match the search parameters, with secrets
     * hidden, as JSON, CSV or plain text.
     *
     * Query parameters: format ("json", "csv" or "txt") and the same filters
     * and order as search().
     *
     * @param  array<array-key, mixed>  $query
     */
    public function export(string $id, array $query): ApiResponse
    {
        return $this->withIndex($id, function (LogFile $file, LogIndex $index) use ($query): ApiResponse {
            $input = new QueryInput($query);
            $format = $input->choice('format', Exporter::FORMATS, 'json');
            $filter = $this->filterFrom($input);
            $newestFirst = $input->choice('order', ['newest', 'oldest'], 'newest') === 'newest';
            $exporter = new Exporter($this->presenter);

            return ApiResponse::download(
                pathinfo($file->name(), PATHINFO_FILENAME).'-export.'.$format,
                Exporter::contentType($format),
                function (callable $write) use ($exporter, $index, $filter, $newestFirst, $format): void {
                    $exporter->export($index, $filter, $newestFirst, $format, $write);
                }
            );
        });
    }

    /**
     * List the bookmarked entries, newest first.
     */
    public function bookmarks(): ApiResponse
    {
        return $this->withBookmarks(function (BookmarkStore $store): ApiResponse {
            return ApiResponse::ok(['data' => $store->all()]);
        });
    }

    /**
     * Bookmark an entry, keeping a copy of it with secrets hidden.
     *
     * Input: file (a file ID), offset (where the entry starts) and an
     * optional note.
     *
     * @param  array<array-key, mixed>  $input
     */
    public function addBookmark(array $input): ApiResponse
    {
        $values = new QueryInput($input);

        try {
            $fileId = $values->string('file') ?? '';
            $offset = $values->integer('offset', -1, 0, PHP_INT_MAX);
            $note = $values->string('note') ?? '';
        } catch (InvalidArgumentException $exception) {
            return ApiResponse::error(422, $exception->getMessage());
        }

        return $this->withIndex($fileId, function (LogFile $file, LogIndex $index) use ($offset, $note): ApiResponse {
            $entry = $offset < 0 ? null : $index->entryAt($offset);

            if ($entry === null) {
                return ApiResponse::error(404, 'Log entry not found. It may have been rotated or deleted.');
            }

            $bookmark = (new BookmarkStore($this->indexDirectory))
                ->add($file->id(), $file->relativePath(), $offset, $note, $this->presenter->entry($entry));

            return ApiResponse::ok(['data' => $bookmark], 201);
        });
    }

    /**
     * Change a bookmark's note.
     *
     * @param  array<array-key, mixed>  $input
     */
    public function updateBookmark(string $id, array $input): ApiResponse
    {
        return $this->withBookmarks(function (BookmarkStore $store) use ($id, $input): ApiResponse {
            $bookmark = $store->updateNote($id, (new QueryInput($input))->string('note') ?? '');

            return $bookmark === null
                ? ApiResponse::error(404, 'Bookmark not found.')
                : ApiResponse::ok(['data' => $bookmark]);
        });
    }

    public function deleteBookmark(string $id): ApiResponse
    {
        return $this->withBookmarks(function (BookmarkStore $store) use ($id): ApiResponse {
            return $store->delete($id)
                ? ApiResponse::ok(['data' => ['deleted' => true]])
                : ApiResponse::error(404, 'Bookmark not found.');
        });
    }

    /**
     * Build a search filter from the levels, from, to, q, regex and case
     * parameters.
     *
     * @throws InvalidArgumentException
     */
    private function filterFrom(QueryInput $input): SearchFilter
    {
        return new SearchFilter(
            $input->list('levels'),
            $input->dateTime('from'),
            $input->dateTime('to'),
            $input->string('q'),
            $input->boolean('regex'),
            $input->boolean('case'),
            $input->optionalInteger('group', 1)
        );
    }

    /**
     * Build a response with the bookmark store, turning its errors into
     * error responses.
     *
     * @param  callable(BookmarkStore): ApiResponse  $respond
     */
    private function withBookmarks(callable $respond): ApiResponse
    {
        try {
            return $respond(new BookmarkStore($this->indexDirectory));
        } catch (InvalidArgumentException $exception) {
            return ApiResponse::error(422, $exception->getMessage());
        } catch (IndexException $exception) {
            return ApiResponse::error(500, self::STORAGE_ERROR);
        }
    }

    /**
     * Find a file, bring its index up to date within the time limit, and
     * build a response with it.
     *
     * @param  callable(LogFile, LogIndex, bool): ApiResponse  $respond
     */
    private function withIndex(string $id, callable $respond): ApiResponse
    {
        $file = $this->finder->find($id);

        if ($file === null) {
            return ApiResponse::error(404, 'Log file not found.');
        }

        try {
            $index = LogIndex::open($file->path(), $this->indexDirectory, $this->maxEntryBytes);
            $complete = $index->refresh($this->timeLimit);

            return $respond($file, $index, $complete);
        } catch (LogFileException $exception) {
            return ApiResponse::error(404, 'Log file not found.');
        } catch (InvalidArgumentException $exception) {
            return ApiResponse::error(422, $exception->getMessage());
        } catch (IndexException $exception) {
            // The exception names the folder, which isn't sent to the browser.
            return ApiResponse::error(500, self::STORAGE_ERROR);
        }
    }
}
