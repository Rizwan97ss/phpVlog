<?php

namespace Rss\Vlog\Core\Index;

use Rss\Vlog\Core\Exceptions\IndexException;
use Rss\Vlog\Core\Support\LogLevel;

/**
 * Stores one log file's index on disk.
 *
 * The index is two files: a binary file with a fixed-size record for each
 * entry, and a small JSON file with the scan's progress. The JSON file is
 * replaced atomically after records are written, and only the records it
 * counts are read, so an interrupted write never corrupts the index.
 *
 * @phpstan-type IndexState array{
 *     version: int,
 *     format: string,
 *     fingerprint: string,
 *     fingerprintBytes: int,
 *     indexedBytes: int,
 *     entries: int,
 *     levels: array<string, int>
 * }
 * @phpstan-type IndexRecord array{offset: int, timestamp: int|null, level: string|null, group: int}
 */
final class IndexStorage
{
    /**
     * The index layout version. Indexes from another version are rebuilt.
     */
    public const VERSION = 2;

    /**
     * Each record is the entry's byte offset (8 bytes), its Unix timestamp
     * (4 bytes, 0 when unknown), its level code (1 byte, 0 when unknown) and
     * its group number (3 bytes, 0 when ungrouped).
     */
    private const RECORD_BYTES = 16;

    private const RECORD_FORMAT = 'PVCvC';

    private string $directory;

    private string $basePath;

    public function __construct(string $directory, string $logPath)
    {
        $this->directory = rtrim($directory, '/\\');
        $this->basePath = $this->directory.DIRECTORY_SEPARATOR.self::keyFor($logPath);
    }

    /**
     * Get the name an index's files start with for a log file.
     */
    public static function keyFor(string $logPath): string
    {
        $realPath = realpath($logPath);

        return sha1($realPath === false ? $logPath : $realPath);
    }

    /**
     * Run a callback while holding this index's write lock.
     *
     * Returns false without running the callback when another process holds
     * the lock and $wait is false.
     *
     * @param  callable(): void  $callback
     *
     * @throws IndexException
     */
    public function withLock(callable $callback, bool $wait = false): bool
    {
        $this->ensureDirectory();
        $handle = @fopen($this->basePath.'.lock', 'c');

        if ($handle === false) {
            throw IndexException::fileNotWritable($this->basePath.'.lock');
        }

        try {
            if (! flock($handle, $wait ? LOCK_EX : LOCK_EX | LOCK_NB)) {
                return false;
            }

            try {
                $callback();
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }

        return true;
    }

    /**
     * Delete the index's files. A file another process holds open can't be
     * deleted on Windows, and is left for the pruner.
     */
    public function delete(): void
    {
        set_error_handler(function (): bool {
            return true;
        });

        try {
            foreach (['.idx', '.json', '.groups.json', '.lock'] as $extension) {
                if (is_file($this->basePath.$extension)) {
                    unlink($this->basePath.$extension);
                }
            }
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Read the index state, or return null when there's no valid index.
     *
     * @return IndexState|null
     */
    public function readState(): ?array
    {
        $json = is_file($this->basePath.'.json') ? @file_get_contents($this->basePath.'.json') : false;
        $state = $json === false ? null : json_decode($json, true);

        if (! is_array($state)
            || ($state['version'] ?? null) !== self::VERSION
            || ! is_string($state['format'] ?? null)
            || ! is_string($state['fingerprint'] ?? null)
            || ! is_int($state['fingerprintBytes'] ?? null)
            || ! is_int($state['indexedBytes'] ?? null)
            || ! is_int($state['entries'] ?? null)
            || ! is_array($state['levels'] ?? null)) {
            return null;
        }

        $levels = [];

        foreach ($state['levels'] as $level => $count) {
            if (is_string($level) && is_int($count)) {
                $levels[$level] = $count;
            }
        }

        return [
            'version' => self::VERSION,
            'format' => $state['format'],
            'fingerprint' => $state['fingerprint'],
            'fingerprintBytes' => $state['fingerprintBytes'],
            'indexedBytes' => $state['indexedBytes'],
            'entries' => $state['entries'],
            'levels' => $levels,
        ];
    }

    /**
     * Replace the index state atomically.
     *
     * @param  IndexState  $state
     *
     * @throws IndexException
     */
    public function writeState(array $state): void
    {
        $this->writeJson($this->basePath.'.json', $state);
    }

    /**
     * Read the saved groups, or return null when there are none.
     *
     * @return array<array-key, mixed>|null
     */
    public function readGroups(): ?array
    {
        $path = $this->basePath.'.groups.json';
        $json = is_file($path) ? @file_get_contents($path) : false;
        $groups = $json === false ? null : json_decode($json, true);

        return is_array($groups) ? $groups : null;
    }

    /**
     * Replace the saved groups atomically.
     *
     * @param  array<string, mixed>  $groups
     *
     * @throws IndexException
     */
    public function writeGroups(array $groups): void
    {
        $this->writeJson($this->basePath.'.groups.json', $groups);
    }

    /**
     * Write JSON to a file by writing a temporary file and renaming it, so
     * readers never see a half-written file.
     *
     * @param  array<array-key, mixed>  $data
     *
     * @throws IndexException
     */
    private function writeJson(string $path, array $data): void
    {
        $temporaryPath = $path.'.'.getmypid().'.tmp';
        $json = (string) json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (@file_put_contents($temporaryPath, $json) === false || ! @rename($temporaryPath, $path)) {
            @unlink($temporaryPath);

            throw IndexException::fileNotWritable($path);
        }
    }

    /**
     * Encode one entry's record.
     */
    public static function encodeRecord(int $offset, ?int $timestamp, ?string $level, int $group = 0): string
    {
        $levelCode = $level === null ? false : array_search($level, LogLevel::all(), true);
        $storableTime = $timestamp !== null && $timestamp > 0 && $timestamp <= 0xFFFFFFFF ? $timestamp : 0;
        $storableGroup = $group > 0 && $group <= 0xFFFFFF ? $group : 0;

        return pack(
            self::RECORD_FORMAT,
            $offset,
            $storableTime,
            $levelCode === false ? 0 : $levelCode + 1,
            $storableGroup & 0xFFFF,
            $storableGroup >> 16
        );
    }

    /**
     * Write encoded records, starting at a record number.
     *
     * @throws IndexException
     */
    public function writeRecords(int $firstRecord, string $records): void
    {
        $path = $this->basePath.'.idx';
        $handle = @fopen($path, 'c+b');

        if ($handle === false) {
            throw IndexException::fileNotWritable($path);
        }

        try {
            fseek($handle, $firstRecord * self::RECORD_BYTES);

            if ($records !== '' && fwrite($handle, $records) !== strlen($records)) {
                throw IndexException::fileNotWritable($path);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Read records, starting at a record number. Fewer records are returned
     * when the index file is shorter than expected.
     *
     * @return list<IndexRecord>
     */
    public function readRecords(int $firstRecord, int $count): array
    {
        if ($count < 1 || ! is_file($this->basePath.'.idx')) {
            return [];
        }

        $handle = @fopen($this->basePath.'.idx', 'rb');

        if ($handle === false) {
            return [];
        }

        try {
            fseek($handle, $firstRecord * self::RECORD_BYTES);
            $data = (string) fread($handle, $count * self::RECORD_BYTES);
        } finally {
            fclose($handle);
        }

        $levels = LogLevel::all();
        $records = [];

        for ($start = 0; $start + self::RECORD_BYTES <= strlen($data); $start += self::RECORD_BYTES) {
            /** @var array{offset: int, timestamp: int, level: int, groupLow: int, groupHigh: int} $record */
            $record = unpack('Poffset/Vtimestamp/Clevel/vgroupLow/CgroupHigh', substr($data, $start, self::RECORD_BYTES));

            $records[] = [
                'offset' => $record['offset'],
                'timestamp' => $record['timestamp'] === 0 ? null : $record['timestamp'],
                'level' => $levels[$record['level'] - 1] ?? null,
                'group' => $record['groupLow'] | ($record['groupHigh'] << 16),
            ];
        }

        return $records;
    }

    /**
     * @throws IndexException
     */
    private function ensureDirectory(): void
    {
        $existing = $this->directory;

        while (! file_exists($existing) && dirname($existing) !== $existing) {
            $existing = dirname($existing);
        }

        if (! is_dir($existing) || ! is_writable($existing)) {
            throw IndexException::directoryNotWritable($this->directory);
        }

        if (! is_dir($this->directory) && ! @mkdir($this->directory, 0775, true) && ! is_dir($this->directory)) {
            throw IndexException::directoryNotWritable($this->directory);
        }

        if (! is_writable($this->directory)) {
            throw IndexException::directoryNotWritable($this->directory);
        }

        // Keep index files out of the application's version control.
        $gitignore = $this->directory.DIRECTORY_SEPARATOR.'.gitignore';

        if (! is_file($gitignore)) {
            @file_put_contents($gitignore, "*\n");
        }
    }
}
