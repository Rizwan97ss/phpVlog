<?php

namespace Rss\Vlog\Adapters\Laravel\Http;

use Illuminate\Http\Request;
use Rss\Vlog\Core\Http\LogViewerApi;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends Laravel requests to the framework-independent log viewer API.
 */
final class ApiController
{
    private LogViewerApi $api;

    public function __construct(LogViewerApi $api)
    {
        $this->api = $api;
    }

    public function files(): Response
    {
        return Responder::send($this->api->files());
    }

    public function file(string $file): Response
    {
        return Responder::send($this->api->file($file));
    }

    public function entries(Request $request, string $file): Response
    {
        return Responder::send($this->api->entries($file, $request->query->all()));
    }

    public function search(Request $request, string $file): Response
    {
        return Responder::send($this->api->search($file, $request->query->all()));
    }

    public function searchAll(Request $request): Response
    {
        return Responder::send($this->api->searchAll($request->query->all()));
    }

    public function groups(Request $request, string $file): Response
    {
        return Responder::send($this->api->groups($file, $request->query->all()));
    }

    public function export(Request $request, string $file): Response
    {
        return Responder::send($this->api->export($file, $request->query->all()));
    }

    public function bookmarks(): Response
    {
        return Responder::send($this->api->bookmarks());
    }

    public function addBookmark(Request $request): Response
    {
        return Responder::send($this->api->addBookmark($request->json()->all()));
    }

    public function updateBookmark(Request $request, string $bookmark): Response
    {
        return Responder::send($this->api->updateBookmark($bookmark, $request->json()->all()));
    }

    public function deleteBookmark(string $bookmark): Response
    {
        return Responder::send($this->api->deleteBookmark($bookmark));
    }
}
