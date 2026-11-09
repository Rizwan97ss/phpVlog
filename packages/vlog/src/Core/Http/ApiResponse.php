<?php

namespace Rss\Vlog\Core\Http;

/**
 * A response from the log viewer API, for an adapter to send with its
 * framework's response class.
 *
 * Most responses are JSON. Downloads are streamed: the adapter calls
 * stream() with a function that sends each chunk to the browser.
 */
final class ApiResponse
{
    /**
     * Encoding options. Invalid UTF-8 in log files is replaced rather than
     * failing the whole response.
     */
    public const JSON_OPTIONS = JSON_INVALID_UTF8_SUBSTITUTE
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_PARTIAL_OUTPUT_ON_ERROR;

    private int $status;

    /**
     * @var array<string, mixed>
     */
    private array $body;

    /**
     * @var (callable(callable(string): void): void)|null
     */
    private $streamer = null;

    /**
     * @var array<string, string>
     */
    private array $downloadHeaders = [];

    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(int $status, array $body)
    {
        $this->status = $status;
        $this->body = $body;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public static function ok(array $body, int $status = 200): self
    {
        return new self($status, $body);
    }

    public static function error(int $status, string $message): self
    {
        return new self($status, ['message' => $message]);
    }

    /**
     * Create a download whose content is written in chunks.
     *
     * @param  callable(callable(string): void): void  $streamer  Writes the content by calling the function it's given with each chunk.
     */
    public static function download(string $filename, string $contentType, callable $streamer): self
    {
        $response = new self(200, []);
        $response->streamer = $streamer;
        $safeName = (string) preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
        $response->downloadHeaders = [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'attachment; filename="'.$safeName.'"',
        ];

        return $response;
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, mixed>
     */
    public function body(): array
    {
        return $this->body;
    }

    public function isDownload(): bool
    {
        return $this->streamer !== null;
    }

    /**
     * Send a download's content, one chunk at a time.
     *
     * @param  callable(string): void  $write
     */
    public function stream(callable $write): void
    {
        if ($this->streamer !== null) {
            ($this->streamer)($write);
        }
    }

    public function json(): string
    {
        return (string) json_encode($this->body, self::JSON_OPTIONS);
    }

    /**
     * Get the response's headers. Logs can hold sensitive data, so responses
     * are never cached.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        return array_merge([
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ], $this->downloadHeaders);
    }
}
