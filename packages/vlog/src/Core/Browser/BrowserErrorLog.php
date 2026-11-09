<?php

namespace Rss\Vlog\Core\Browser;

/**
 * Writes errors reported by visitors' browsers to a log file, in the same
 * line format Laravel uses, so Vlog can read them like any other log.
 *
 * Reports come from anyone who can load the application's pages, so every
 * value is treated as untrusted: sizes are capped, line breaks are removed
 * from the message so a report can't forge other entries, and everything
 * else is written as JSON.
 */
final class BrowserErrorLog
{
    /**
     * The largest request body accepted, in bytes.
     */
    public const MAX_BODY_BYTES = 32768;

    /**
     * The most errors one request can report.
     */
    public const MAX_ERRORS_PER_REQUEST = 10;

    private const MAX_MESSAGE_LENGTH = 1000;

    private const MAX_URL_LENGTH = 1000;

    private const MAX_USER_AGENT_LENGTH = 300;

    private const MAX_FRAMES = 50;

    /**
     * Matches a Chrome or Edge stack frame: "at fn (url:line:column)" or "at url:line:column".
     */
    private const CHROME_FRAME = '/^\s*at\s+(?:(.+?)\s+\()?(.+?):(\d+):(\d+)\)?\s*$/';

    /**
     * Matches a Firefox or Safari stack frame: "fn@url:line:column".
     */
    private const FIREFOX_FRAME = '/^\s*(.*?)@(.+?):(\d+):(\d+)\s*$/';

    private string $path;

    private int $maxFileBytes;

    /**
     * @param  string  $path  The log file to write to.
     * @param  int  $maxFileBytes  When the file grows past this size, it's renamed to "{name}.old.log" and a new one is started.
     */
    public function __construct(string $path, int $maxFileBytes = 10485760)
    {
        $this->path = $path;
        $this->maxFileBytes = $maxFileBytes;
    }

    /**
     * Record the errors in a report sent by the browser script.
     *
     * The body is JSON: {"errors": [{"type", "message", "url", "source",
     * "line", "column", "stack"}]}. Invalid errors are skipped.
     *
     * @return int How many errors were written.
     */
    public function record(string $body, ?string $userAgent = null): int
    {
        if ($body === '' || strlen($body) > self::MAX_BODY_BYTES) {
            return 0;
        }

        $report = json_decode($body, true);
        $errors = is_array($report) && isset($report['errors']) && is_array($report['errors']) ? $report['errors'] : [];
        $lines = '';
        $count = 0;

        foreach (array_slice(array_values($errors), 0, self::MAX_ERRORS_PER_REQUEST) as $error) {
            $line = is_array($error) ? $this->format($error, $userAgent) : null;

            if ($line !== null) {
                $lines .= $line;
                $count++;
            }
        }

        if ($count === 0) {
            return 0;
        }

        return $this->append($lines) ? $count : 0;
    }

    /**
     * Format one error as a log entry, or null when it has no message.
     *
     * @param  array<array-key, mixed>  $error
     */
    private function format(array $error, ?string $userAgent): ?string
    {
        $message = $this->singleLine($this->text($error['message'] ?? null, self::MAX_MESSAGE_LENGTH));

        if ($message === '') {
            return null;
        }

        $type = $this->text($error['type'] ?? null, 30);
        $level = $type === 'resource' ? 'WARNING' : 'ERROR';
        $source = $this->url($error['source'] ?? null);
        $line = $this->number($error['line'] ?? null);
        $column = $this->number($error['column'] ?? null);

        $context = array_filter([
            'type' => in_array($type, ['error', 'unhandledrejection', 'resource'], true) ? $type : 'error',
            'url' => $this->url($error['url'] ?? null),
            'source' => $source,
            'line' => $line,
            'column' => $column,
            'user_agent' => $userAgent === null ? null : $this->singleLine($this->text($userAgent, self::MAX_USER_AGENT_LENGTH)),
        ], function ($value): bool {
            return $value !== null && $value !== '';
        });

        $trace = $this->trace($this->text($error['stack'] ?? null, 20000));

        if ($trace === '' && $source !== null && $line !== null) {
            $trace = '#0 '.$source.'('.$line.'): {main}';
        }

        if ($trace !== '') {
            // Laravel writes a logged exception's trace under this key, so
            // the viewer shows the frames the same way.
            $context['exception'] = $message."\n[stacktrace]\n".$trace;
        }

        $json = (string) json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return '['.date('Y-m-d H:i:s').'] browser.'.$level.': '.$message.' '.$json."\n";
    }

    /**
     * Convert a JavaScript stack trace to PHP's "#0 file(line): call" frames.
     */
    private function trace(string $stack): string
    {
        $frames = [];

        foreach (preg_split('/\R/', $stack) ?: [] as $line) {
            if (count($frames) >= self::MAX_FRAMES) {
                break;
            }

            if (preg_match(self::CHROME_FRAME, $line, $matches) !== 1 && preg_match(self::FIREFOX_FRAME, $line, $matches) !== 1) {
                continue;
            }

            $file = $this->url($matches[2]);

            if ($file === null) {
                continue;
            }

            $call = $this->singleLine($matches[1]);
            $frames[] = '#'.count($frames).' '.$file.'('.$matches[3].'): '.($call === '' ? '{anonymous}' : $call).':'.$matches[4];
        }

        return implode("\n", $frames);
    }

    /**
     * Get a URL without its query string or fragment, which can hold tokens.
     *
     * @param  mixed  $value
     */
    private function url($value): ?string
    {
        $url = $this->singleLine($this->text($value, self::MAX_URL_LENGTH));
        $url = (string) preg_replace('/[?#].*$/', '', $url);

        return $url === '' ? null : $url;
    }

    /**
     * @param  mixed  $value
     */
    private function text($value, int $maxLength): string
    {
        if (! is_string($value)) {
            return '';
        }

        return function_exists('mb_substr') ? mb_substr($value, 0, $maxLength, 'UTF-8') : substr($value, 0, $maxLength);
    }

    /**
     * @param  mixed  $value
     */
    private function number($value): ?int
    {
        return is_int($value) && $value > 0 ? $value : null;
    }

    private function singleLine(string $text): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text));
    }

    /**
     * Append to the log file, starting a new one when it's too large.
     */
    private function append(string $lines): bool
    {
        $path = $this->path;
        $maxFileBytes = $this->maxFileBytes;

        set_error_handler(function (): bool {
            return true;
        });

        try {
            $directory = dirname($path);

            if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
                return false;
            }

            clearstatcache(true, $path);

            if (is_file($path) && filesize($path) > $maxFileBytes) {
                $info = pathinfo($path);
                $extension = isset($info['extension']) ? '.'.$info['extension'] : '';
                rename($path, $directory.DIRECTORY_SEPARATOR.$info['filename'].'.old'.$extension);
            }

            return file_put_contents($path, $lines, FILE_APPEND | LOCK_EX) !== false;
        } finally {
            restore_error_handler();
        }
    }
}
