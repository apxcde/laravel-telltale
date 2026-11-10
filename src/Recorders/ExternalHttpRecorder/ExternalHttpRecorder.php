<?php

namespace Apxcde\LaravelTelltale\Recorders\ExternalHttpRecorder;

use Illuminate\Http\Client\Factory as HttpFactory;
use Spatie\FlareClient\Flare;
use Spatie\FlareClient\Recorders\ExternalHttpRecorder\ExternalHttpRecorder as BaseExternalHttpRecorder;
use Spatie\FlareClient\Recorders\ExternalHttpRecorder\Guzzle\FlareMiddleware;
use Spatie\FlareClient\Support\BackTracer;
use Spatie\FlareClient\Spans\Span;
use Spatie\FlareClient\Support\Redactor;
use Apxcde\LaravelTelltale\Support\UrlCensor;
use Spatie\FlareClient\Tracer;

class ExternalHttpRecorder extends BaseExternalHttpRecorder
{
    public function __construct(
        Tracer $tracer,
        BackTracer $backTracer,
        protected HttpFactory $httpFactory,
        array $config,
        Redactor $redactor,
    ) {
        parent::__construct($tracer, $backTracer, $config, $redactor);
    }

    /**
     * Censor the query string of an outgoing request's URL with the
     * `censor.body_fields` rules before it is recorded.
     */
    public function recordSending(
        string $url,
        string $method,
        ?int $bodySize = null,
        array $headers = [],
        array $attributes = [],
    ): ?Span {
        return parent::recordSending(
            (new UrlCensor($this->redactor))->url($url),
            $method,
            $bodySize,
            $headers,
            $attributes,
        );
    }

    public function boot(): void
    {
        $this->httpFactory->globalMiddleware(new FlareMiddleware(app(Flare::class)));
    }
}
