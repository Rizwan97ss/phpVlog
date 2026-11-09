<?php

namespace Rss\Vlog\Core\Source;

/**
 * Reads the lines of source code around a line named in a stack trace.
 *
 * Paths come from log entries, which can contain anything an attacker
 * managed to get logged, so only files that pass every check are read:
 * the real path must be inside one of the allowed folders, have an allowed
 * extension, and not be inside a hidden folder or be a hidden file, such as
 * ".env" or ".git".
 */
final class SourcePreview
{
    /**
     * How many lines to show before and after the line.
     */
    public const CONTEXT_LINES = 8;

    private const MAX_FILE_BYTES = 5242880;

    private const MAX_LINE_LENGTH = 1000;

    /**
     * @var list<string>
     */
    private array $roots = [];

    /**
     * @var list<string>
     */
    private array $extensions;

    /**
     * @param  list<string>  $roots  The folders whose files can be previewed.
     * @param  list<string>  $extensions  The file extensions that can be previewed.
     */
    public function __construct(array $roots, array $extensions = ['php'])
    {
        foreach ($roots as $root) {
            $real = $this->quietly(function () use ($root) {
                return realpath($root);
            });

            if (is_string($real) && is_dir($real)) {
                $this->roots[] = rtrim($real, '\\/');
            }
        }

        $this->extensions = array_map('strtolower', $extensions);
    }

    /**
     * Get the lines around a line of a file, or null when the file can't be
     * previewed.
     *
     * @return array{path: string, line: int, start: int, lines: list<string>}|null
     */
    public function around(string $path, int $line, int $context = self::CONTEXT_LINES): ?array
    {
        $resolved = $this->resolve($path);

        if ($resolved === null || $line < 1) {
            return null;
        }

        [$realPath, $relativePath] = $resolved;
        $start = max(1, $line - $context);
        $end = $line + $context;

        $lines = $this->quietly(function () use ($realPath, $start, $end): ?array {
            $handle = fopen($realPath, 'rb');

            if ($handle === false) {
                return null;
            }

            try {
                $lines = [];
                $number = 0;

                while ($number < $end && ($text = fgets($handle)) !== false) {
                    $number++;

                    if ($number >= $start) {
                        $lines[] = substr(rtrim($text, "\r\n"), 0, self::MAX_LINE_LENGTH);
                    }
                }

                return $lines;
            } finally {
                fclose($handle);
            }
        });

        if (! is_array($lines) || $lines === [] || $start + count($lines) - 1 < $line) {
            return null;
        }

        return ['path' => $relativePath, 'line' => $line, 'start' => $start, 'lines' => $lines];
    }

    /**
     * Get the real path and the path inside its folder, when the file can be
     * previewed.
     *
     * @return array{0: string, 1: string}|null
     */
    private function resolve(string $path): ?array
    {
        if ($path === '' || strpos($path, "\0") !== false) {
            return null;
        }

        $real = $this->quietly(function () use ($path) {
            return realpath($path);
        });

        if (! is_string($real) || ! is_file($real) || ! is_readable($real)) {
            return null;
        }

        if (! in_array(strtolower(pathinfo($real, PATHINFO_EXTENSION)), $this->extensions, true)) {
            return null;
        }

        $size = filesize($real);

        if ($size === false || $size > self::MAX_FILE_BYTES) {
            return null;
        }

        foreach ($this->roots as $root) {
            if (strpos($real, $root.DIRECTORY_SEPARATOR) !== 0) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($real, strlen($root) + 1));

            foreach (explode('/', $relative) as $segment) {
                if ($segment === '' || $segment[0] === '.') {
                    return null;
                }
            }

            return [$real, $relative];
        }

        return null;
    }

    /**
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
