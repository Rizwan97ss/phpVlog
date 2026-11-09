<?php

namespace Rss\Vlog\Core\Bookmarks;

use InvalidArgumentException;
use Rss\Vlog\Core\Exceptions\IndexException;

/**
 * Stores bookmarked entries with notes in a JSON file.
 *
 * Each bookmark keeps a copy of its entry, with secrets already hidden, so
 * it stays readable after the log file is rotated or deleted.
 *
 * @phpstan-type Bookmark array{
 *     id: string,
 *     file_id: string,
 *     file: string,
 *     offset: int,
 *     note: string,
 *     created_at: string,
 *     entry: array<array-key, mixed>
 * }
 */
final class BookmarkStore
{
    public const MAX_BOOKMARKS = 1000;

    public const MAX_NOTE_LENGTH = 2000;

    private string $path;

    public function __construct(string $directory)
    {
        $this->path = rtrim($directory, '/\\').DIRECTORY_SEPARATOR.'bookmarks.json';
    }

    /**
     * Get every bookmark, newest first.
     *
     * @return list<Bookmark>
     */
    public function all(): array
    {
        return array_reverse($this->read());
    }

    /**
     * Bookmark an entry.
     *
     * @param  array<array-key, mixed>  $entry  The entry as the API presents it, with secrets hidden.
     * @return Bookmark
     *
     * @throws InvalidArgumentException When the note is too long or there are too many bookmarks.
     * @throws IndexException When the bookmarks file can't be written.
     */
    public function add(string $fileId, string $file, int $offset, string $note, array $entry): array
    {
        $this->assertNote($note);

        return $this->change(function (array $bookmarks) use ($fileId, $file, $offset, $note, $entry): array {
            if (count($bookmarks) >= self::MAX_BOOKMARKS) {
                throw new InvalidArgumentException('You can save up to '.self::MAX_BOOKMARKS.' bookmarks. Delete some to add more.');
            }

            $bookmark = [
                'id' => bin2hex(random_bytes(8)),
                'file_id' => $fileId,
                'file' => $file,
                'offset' => $offset,
                'note' => $note,
                'created_at' => date(DATE_ATOM),
                'entry' => $entry,
            ];

            $bookmarks[] = $bookmark;

            return [$bookmarks, $bookmark];
        });
    }

    /**
     * Change a bookmark's note. Returns null when there's no such bookmark.
     *
     * @return Bookmark|null
     *
     * @throws InvalidArgumentException When the note is too long.
     * @throws IndexException When the bookmarks file can't be written.
     */
    public function updateNote(string $id, string $note): ?array
    {
        $this->assertNote($note);

        return $this->change(function (array $bookmarks) use ($id, $note): array {
            foreach ($bookmarks as $index => $bookmark) {
                if (hash_equals($bookmark['id'], $id)) {
                    $bookmarks[$index]['note'] = $note;

                    return [$bookmarks, $bookmarks[$index]];
                }
            }

            return [$bookmarks, null];
        });
    }

    /**
     * Delete a bookmark. Returns whether it existed.
     *
     * @throws IndexException When the bookmarks file can't be written.
     */
    public function delete(string $id): bool
    {
        return $this->change(function (array $bookmarks) use ($id): array {
            $remaining = array_values(array_filter($bookmarks, function (array $bookmark) use ($id): bool {
                return ! hash_equals($bookmark['id'], $id);
            }));

            return [$remaining, count($remaining) < count($bookmarks)];
        });
    }

    /**
     * @throws InvalidArgumentException
     */
    private function assertNote(string $note): void
    {
        if (strlen($note) > self::MAX_NOTE_LENGTH) {
            throw new InvalidArgumentException('Notes can be up to '.self::MAX_NOTE_LENGTH.' characters long.');
        }
    }

    /**
     * Read, change and write the bookmarks while holding a lock, so changes
     * from two requests at once aren't lost.
     *
     * @template T
     *
     * @param  callable(list<Bookmark>): array{0: list<Bookmark>, 1: T}  $change
     * @return T
     *
     * @throws IndexException
     */
    private function change(callable $change)
    {
        $directory = dirname($this->path);

        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw IndexException::directoryNotWritable($directory);
        }

        $lock = @fopen($this->path.'.lock', 'c');

        if ($lock === false) {
            throw IndexException::fileNotWritable($this->path.'.lock');
        }

        try {
            flock($lock, LOCK_EX);
            [$bookmarks, $result] = $change($this->read());

            $temporaryPath = $this->path.'.'.getmypid().'.tmp';

            if (@file_put_contents($temporaryPath, (string) json_encode($bookmarks, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false
                || ! @rename($temporaryPath, $this->path)) {
                @unlink($temporaryPath);

                throw IndexException::fileNotWritable($this->path);
            }

            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @return list<Bookmark>
     */
    private function read(): array
    {
        $json = is_file($this->path) ? file_get_contents($this->path) : false;
        $decoded = $json === false ? null : json_decode($json, true);

        if (! is_array($decoded)) {
            return [];
        }

        $bookmarks = [];

        foreach ($decoded as $bookmark) {
            if (is_array($bookmark)
                && is_string($bookmark['id'] ?? null)
                && is_string($bookmark['file_id'] ?? null)
                && is_string($bookmark['file'] ?? null)
                && is_int($bookmark['offset'] ?? null)
                && is_string($bookmark['note'] ?? null)
                && is_string($bookmark['created_at'] ?? null)
                && is_array($bookmark['entry'] ?? null)) {
                $bookmarks[] = [
                    'id' => $bookmark['id'],
                    'file_id' => $bookmark['file_id'],
                    'file' => $bookmark['file'],
                    'offset' => $bookmark['offset'],
                    'note' => $bookmark['note'],
                    'created_at' => $bookmark['created_at'],
                    'entry' => $bookmark['entry'],
                ];
            }
        }

        return $bookmarks;
    }
}
