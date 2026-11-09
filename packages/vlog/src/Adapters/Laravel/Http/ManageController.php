<?php

namespace Rss\Vlog\Adapters\Laravel\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Rss\Vlog\Core\Http\ManageApi;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends requests that change log files, or show them unredacted, to the
 * management API, for users the "manageVlog" gate allows.
 */
final class ManageController
{
    private ManageApi $api;

    public function __construct(ManageApi $api)
    {
        $this->api = $api;
    }

    public function download(string $file): Response
    {
        $this->authorize();

        return Responder::send($this->api->download($file));
    }

    public function clear(string $file): Response
    {
        $this->authorize();

        return Responder::send($this->api->clear($file));
    }

    public function delete(string $file): Response
    {
        $this->authorize();

        return Responder::send($this->api->delete($file));
    }

    public function write(Request $request): Response
    {
        $this->authorize();

        return Responder::send($this->api->write($request->json()->all()));
    }

    public function source(Request $request): Response
    {
        $this->authorize();

        return Responder::send($this->api->source($request->query->all()));
    }

    private function authorize(): void
    {
        if (! Gate::check('manageVlog')) {
            abort(403, 'You are not allowed to manage logs.');
        }
    }
}
