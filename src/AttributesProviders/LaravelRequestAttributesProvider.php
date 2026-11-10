<?php

namespace Apxcde\LaravelTelltale\AttributesProviders;

use Illuminate\Http\Request as LaravelRequest;
use Spatie\FlareClient\AttributesProviders\SymfonyRequestAttributesProvider;
use Spatie\FlareClient\Support\Redactor;
use Apxcde\LaravelTelltale\Support\LivewireComponentFinder;
use Apxcde\LaravelTelltale\Support\UrlCensor;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

class LaravelRequestAttributesProvider extends SymfonyRequestAttributesProvider
{
    protected UrlCensor $urlCensor;

    /** @param array<string> $ignoreLivewireComponents */
    public function __construct(
        Redactor $redactor,
        protected LivewireComponentFinder $livewireComponentFinder,
        ?Request $request = null,
        bool $includeContents = true,
        protected bool $includeLivewireComponents = false,
        protected array $ignoreLivewireComponents = [],
    ) {
        parent::__construct($redactor, $request, $includeContents);

        $this->urlCensor = new UrlCensor($redactor);
    }

    public function url(): string
    {
        return $this->urlCensor->url(parent::url());
    }

    /**
     * The core client censors the parsed body and query parameters but
     * sends the URL as it is. The same rules are applied to the query
     * string here.
     */
    protected function getRequest(): array
    {
        $payload = parent::getRequest();

        $payload['url.full'] = $this->urlCensor->url($payload['url.full']);
        $payload['url.query'] = $this->urlCensor->query($payload['url.query']);

        return $payload;
    }

    /**
     * The Referer header is the URL of the previous page, query string
     * included, so it gets the same treatment.
     */
    protected function getHeaders(): array
    {
        $headers = parent::getHeaders();

        if (is_string($headers['http.request.headers']['referer'] ?? null)) {
            $headers['http.request.headers']['referer'] = $this->urlCensor->url(
                $headers['http.request.headers']['referer']
            );
        }

        return $headers;
    }

    public function toArray(): array
    {
        $attributes = parent::toArray();

        if (! $this->request instanceof LaravelRequest) {
            return $attributes;
        }

        if (! $this->includeLivewireComponents || ! $this->isRunningLiveWire($this->request)) {
            return $attributes;
        }

        try {
            $livewireAttributesProvider = new LivewireAttributesProvider(
                livewireComponentFinder: $this->livewireComponentFinder,
                request: $this->request,
                ignoreLivewireComponents: $this->ignoreLivewireComponents,
                urlCensor: $this->urlCensor,
            );

            return [
                ...$attributes,
                ...$livewireAttributesProvider->toArray(),
            ];
        } catch (Throwable) {
            return $attributes;
        }
    }

    protected function isRunningLiveWire(LaravelRequest $request): bool
    {
        return $request->hasHeader('x-livewire') && $request->hasHeader('referer');
    }
}
