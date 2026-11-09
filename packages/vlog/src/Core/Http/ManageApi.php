<?php

namespace Rss\Vlog\Core\Http;

use InvalidArgumentException;
use Rss\Vlog\Core\Files\LogFile;
use Rss\Vlog\Core\Files\LogFileFinder;
use Rss\Vlog\Core\Index\IndexStorage;
use Rss\Vlog\Core\Security\Redactor;
use Rss\Vlog\Core\Source\SourcePreview;
use Rss\Vlog\Core\Support\LogLevel;

/**
 * The API actions that change log files or show what logs alone don't:
 * raw downloads, clearing, deleting and writing entries, and previewing the
 * source code a stack trace points to.
 *
 * Adapters must only call these methods for users allowed to manage logs.
 */
final class ManageApi
{
    public const MAX_MESSAGE_LENGTH = 5000;

    public const MAX_CONTEXT_BYTES = 10240;

    private const CHUNK_BYTES = 1048576;

    private LogFileFinder $finder;

    private string $indexDirectory;

    /**
     * @var (callable(string, string, array<array-key, mixed>): void)|null
     */
    private $entryWriter;

    private ?SourcePreview $sourcePreview;

    private Redactor $redactor;

    /**
     * @param  (callable(string, string, array<array-key, mixed>): void)|null  $entryWriter  Writes an entry to the application's log, given a level, message and context.
     * @param  SourcePreview|null  $sourcePreview  Reads source code for stack frames, or null to turn previews off.
     * @param  Redactor|null  $redactor  Hides secrets in previewed source code.
     */
    public function __construct(
        LogFileFinder $finder,
        string $indexDirectory,
        ?callable $entryWriter = null,
        ?SourcePreview $sourcePreview = null,
        ?Redactor $redactor = null
    ) {
        $this->finder = $finder;
        $this->indexDirectory = $indexDirectory;
        $this->entryWriter = $entryWriter;
        $this->sourcePreview = $sourcePreview;
        $this->redactor = $redactor ?? new Redactor;
    }

    /**
     * Get the source code around a line of a file named in a stack trace,
     * with secrets hidden.
     *
     * Query parameters: path (the file's path, as the stack trace wrote it)
     * and line.
     *
     * @param  array<array-key, mixed>  $query
     */
    public function source(array $query): ApiResponse
    {
        if ($this->sourcePreview === null) {
            return ApiResponse::error(501, 'Source previews are turned off.');
        }

        try {
            $input = new QueryInput($query);
            $path = $input->string('path') ?? '';
            $line = $input->integer('line', 0, 1, PHP_INT_MAX);
        } catch (InvalidArgumentException $exception) {
            return ApiResponse::error(422, $exception->getMessage());
        }

        $preview = $this->sourcePreview->around($path, $line);

        if ($preview === null) {
            return ApiResponse::error(404, 'This file is not available for preview.');
        }

        $preview['lines'] = array_map([$this->redactor, 'text'], $preview['lines']);

        return ApiResponse::ok(['data' => $preview]);
    }

    /**
     * Download a log file exactly as it is, without hiding secrets.
     */
    public function download(string $id): ApiResponse
    {
        return $this->withFile($id, function (LogFile $file): ApiResponse {
            $path = $file->path();

            return ApiResponse::download($file->name(), 'text/plain; charset=utf-8', function (callable $write) use ($path): void {
                $handle = $this->quietly(function () use ($path) {
                    return fopen($path, 'rb');
                });

                if (! is_resource($handle)) {
                    return;
                }

                try {
                    while (! feof($handle)) {
                        $chunk = fread($handle, self::CHUNK_BYTES);

                        if ($chunk === false || $chunk === '') {
                            break;
                        }

                        $write($chunk);
                    }
                } finally {
                    fclose($handle);
                }
            });
        });
    }

    /**
     * Empty a log file, keeping the file itself.
     */
    public function clear(string $id): ApiResponse
    {
        return $this->withFile($id, function (LogFile $file): ApiResponse {
            $path = $file->path();

            $cleared = $this->quietly(function () use ($path): bool {
                $handle = fopen($path, 'r+b');

                if ($handle === false) {
                    return false;
                }

                try {
                    return flock($handle, LOCK_EX) && ftruncate($handle, 0);
                } finally {
                    flock($handle, LOCK_UN);
                    fclose($handle);
                }
            });

            return $cleared === true
                ? ApiResponse::ok(['data' => ['cleared' => true]])
                : ApiResponse::error(409, 'Vlog could not clear this file. Check that it is writable.');
        });
    }

    /**
     * Delete a log file and its index.
     */
    public function delete(string $id): ApiResponse
    {
        return $this->withFile($id, function (LogFile $file): ApiResponse {
            $path = $file->path();

            $deleted = $this->quietly(function () use ($path): bool {
                return unlink($path);
            });

            if ($deleted !== true) {
                return ApiResponse::error(409, 'Vlog could not delete this file. Another program may be using it.');
            }

            (new IndexStorage($this->indexDirectory, $path))->delete();

            return ApiResponse::ok(['data' => ['deleted' => true]]);
        });
    }

    /**
     * Write an entry to the application's log, such as a note marking a
     * deployment.
     *
     * Input: level, message and an optional context object.
     *
     * @param  array<array-key, mixed>  $input
     */
    public function write(array $input): ApiResponse
    {
        if ($this->entryWriter === null) {
            return ApiResponse::error(501, 'Writing log entries is not available in this application.');
        }

        try {
            $values = new QueryInput($input);
            $level = $values->choice('level', LogLevel::all(), LogLevel::INFO);
            $message = trim($values->string('message') ?? '');
            $context = $values->object('context', self::MAX_CONTEXT_BYTES) ?? [];

            if ($message === '') {
                throw new InvalidArgumentException('The message parameter is required.');
            }

            if (strlen($message) > self::MAX_MESSAGE_LENGTH) {
                throw new InvalidArgumentException('The message can be up to '.self::MAX_MESSAGE_LENGTH.' characters long.');
            }
        } catch (InvalidArgumentException $exception) {
            return ApiResponse::error(422, $exception->getMessage());
        }

        ($this->entryWriter)($level, $message, $context);

        return ApiResponse::ok(['data' => ['written' => true]], 201);
    }

    /**
     * @param  callable(LogFile): ApiResponse  $respond
     */
    private function withFile(string $id, callable $respond): ApiResponse
    {
        $file = $this->finder->find($id);

        return $file === null ? ApiResponse::error(404, 'Log file not found.') : $respond($file);
    }

    /**
     * Run a filesystem operation without letting its warnings reach the
     * host application's error handler.
     *
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    private function quietly(callable $operation)
    {
        set_error_handler(function (): bool {
            return true;
        });

        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }
}
