<?php

namespace Apxcde\LaravelTelltale\Senders;

use Closure;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Facades\Http;
use Spatie\FlareClient\Enums\FlareEntityType;
use Spatie\FlareClient\Senders\Sender;
use Spatie\FlareClient\Senders\Support\Response;

class LaravelHttpSender implements Sender
{
    protected int $timeout;

    protected bool $async;

    public function __construct(
        protected array $config = []
    ) {
        $this->timeout = $this->config['timeout'] ?? 10;
    }

    public function post(string $endpoint, string $apiKey, array $payload, FlareEntityType $type, bool $test, Closure $callback): void
    {
        $response = Http::withHeader('x-api-token', $apiKey)
            ->timeout($this->timeout)
            ->post($endpoint, $payload);

        $this->handleResponse($response, $callback);
    }

    /**
     * Hand the client the whole answer: the decoded JSON, or the raw text
     * when the answer is not JSON. `telltale:test` reads the reason for a
     * refusal from it, and an answer from something in front of Telltale,
     * such as a firewall, is usually not JSON.
     */
    protected function handleResponse(
        HttpResponse $response,
        Closure $callback
    ): void {
        $callback(new Response(
            $response->status(),
            $response->json() ?? $response->body(),
        ));
    }
}
