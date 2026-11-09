<?php

namespace Rss\Vlog\Core\Index;

/**
 * Deletes indexes of log files that no longer exist, such as rotated logs
 * that were removed.
 *
 * Only files named like index files are ever deleted. Pruning runs at most
 * once per interval, and an index deleted by mistake is simply rebuilt the
 * next time its log file is opened.
 */
final class IndexPruner
{
    /**
     * Matches the files IndexStorage creates for one log file.
     */
    private const INDEX_FILE = '/^([0-9a-f]{40})\.(?:idx|json|groups\.json|lock)$/';

    /**
     * The file whose modification time records the last prune.
     */
    private const MARKER = '.pruned';

    private string $directory;

    private int $intervalSeconds;

    public function __construct(string $directory, int $intervalSeconds = 3600)
    {
        $this->directory = rtrim($directory, '/\\');
        $this->intervalSeconds = $intervalSeconds;
    }

    /**
     * Delete the indexes of every log file that isn't in the list.
     *
     * @param  list<string>  $logPaths  The log files whose indexes to keep.
     * @param  bool  $force  Whether to prune even if it ran within the interval.
     * @return int The number of files deleted.
     */
    public function prune(array $logPaths, bool $force = false): int
    {
        if (! is_dir($this->directory) || (! $force && ! $this->isDue())) {
            return 0;
        }

        $keep = [];

        foreach ($logPaths as $path) {
            $keep[IndexStorage::keyFor($path)] = true;
        }

        $deleted = 0;

        foreach (scandir($this->directory) ?: [] as $name) {
            if (preg_match(self::INDEX_FILE, $name, $matches) !== 1 || isset($keep[$matches[1]])) {
                continue;
            }

            if ($this->delete($this->directory.DIRECTORY_SEPARATOR.$name)) {
                $deleted++;
            }
        }

        $this->quietly(function (): void {
            touch($this->directory.DIRECTORY_SEPARATOR.self::MARKER);
        });

        return $deleted;
    }

    private function isDue(): bool
    {
        $marker = $this->directory.DIRECTORY_SEPARATOR.self::MARKER;

        return ! is_file($marker) || (int) filemtime($marker) <= time() - $this->intervalSeconds;
    }

    /**
     * Delete a file. A file another process holds open can't be deleted on
     * Windows, and is left for the next prune.
     */
    private function delete(string $path): bool
    {
        $deleted = false;

        $this->quietly(function () use ($path, &$deleted): void {
            $deleted = unlink($path);
        });

        return $deleted;
    }

    /**
     * Run a filesystem operation without letting its warnings reach the
     * host application's error handler.
     *
     * @param  callable(): void  $operation
     */
    private function quietly(callable $operation): void
    {
        set_error_handler(function (): bool {
            return true;
        });

        try {
            $operation();
        } finally {
            restore_error_handler();
        }
    }
}
