<?php

namespace Apxcde\LaravelTelltale\Http\Middleware;

use Closure;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Spatie\FlareClient\AttributesProviders\SymfonyResponseAttributesProvider;
use Spatie\FlareClient\Support\Lifecycle;
use Spatie\FlareClient\Support\Redactor;
use Apxcde\LaravelTelltale\AttributesProviders\LaravelRequestAttributesProvider;
use Apxcde\LaravelTelltale\AttributesProviders\LaravelRouteAttributesProvider;
use Apxcde\LaravelTelltale\AttributesProviders\LaravelUserAttributesProvider;
use Apxcde\LaravelTelltale\Enums\LaravelCollectType;
use Apxcde\LaravelTelltale\Facades\Telltale;
use Apxcde\LaravelTelltale\TelltaleConfig;
use Apxcde\LaravelTelltale\Support\LivewireComponentFinder;
use Symfony\Component\HttpFoundation\Response;

class FlareTracingMiddleware
{
    public static ?LaravelRouteAttributesProvider $routeAttributesProvider = null;

    private LaravelRequestAttributesProvider $requestAttributesProvider;

    public function __construct(
        protected Lifecycle $lifecycle,
        protected Application $app,
        protected Redactor $redactor,
        protected LivewireComponentFinder $livewireComponentFinder,
        protected TelltaleConfig $config,
    ) {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        self::$routeAttributesProvider = null;

        $livewireOptions = $this->config->collects[LaravelCollectType::LivewireComponents->value]['options'] ?? [];

        $this->requestAttributesProvider = new LaravelRequestAttributesProvider(
            $this->redactor,
            $this->livewireComponentFinder,
            $request,
            includeContents: true,
            includeLivewireComponents: $livewireOptions['include_livewire_components'] ?? false,
            ignoreLivewireComponents: $livewireOptions['ignore_livewire_components'] ?? [],
        );

        Telltale::request()?->recordStart($this->requestAttributesProvider);
        Telltale::routing()?->recordGlobalBeforeMiddlewareStart();

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (isset($this->requestAttributesProvider)) {
            Telltale::request()?->recordEnd(
                requestAttributesProvider: $this->requestAttributesProvider,
                responseAttributesProvider: new SymfonyResponseAttributesProvider($this->redactor, $response),
                routeAttributesProvider: self::$routeAttributesProvider ?? LaravelRouteAttributesProvider::fromRequest($request),
                userAttributesProvider: LaravelUserAttributesProvider::fromRequest($request),
            );
        }

        self::$routeAttributesProvider = null;

        $this->lifecycle->terminating();
    }
}
