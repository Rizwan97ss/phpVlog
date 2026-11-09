<?php

namespace Rss\Vlog\Adapters\Laravel\Http;

use Illuminate\Http\Response;
use Rss\Vlog\Core\Http\ApiResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Converts API responses to Laravel responses.
 */
final class Responder
{
    public static function send(ApiResponse $response): SymfonyResponse
    {
        if ($response->isDownload()) {
            return new StreamedResponse(function () use ($response): void {
                $response->stream(function (string $chunk): void {
                    echo $chunk;
                    flush();
                });
            }, $response->status(), $response->headers());
        }

        return new Response($response->json(), $response->status(), $response->headers());
    }
}
