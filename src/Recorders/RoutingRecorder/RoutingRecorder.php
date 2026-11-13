<?php

namespace Apxcde\LaravelTelltale\Recorders\RoutingRecorder;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Routing\Events\Routing;
use Spatie\FlareClient\EntryPoint\EntryPointResolver;
use Spatie\FlareClient\Enums\RecorderType;
use Spatie\FlareClient\Recorders\RoutingRecorder\RoutingRecorder as BaseRoutingRecorder;
use Spatie\FlareClient\Support\BackTracer;
use Spatie\FlareClient\Support\Lifecycle;
use Spatie\FlareClient\Tracer;
use Apxcde\LaravelTelltale\AttributesProviders\LaravelRouteAttributesProvider;
use Apxcde\LaravelTelltale\Facades\Telltale;
use Apxcde\LaravelTelltale\Http\Middleware\FlareTracingMiddleware;

class RoutingRecorder extends BaseRoutingRecorder
{
    public static function type(): string|RecorderType
    {
        return RecorderType::Routing;
    }

    public function __construct(
        Tracer $tracer,
        EntryPointResolver $entryPointResolver,
        protected Lifecycle $lifecycle,
        protected Application $app,
        protected Dispatcher $dispatcher,
        BackTracer $backTracer,
        array $config,
    ) {
        parent::__construct($tracer, $backTracer, $entryPointResolver, $config);
    }

    public function boot(): void
    {
        $this->dispatcher->listen(Routing::class, function () {
            $this->recordGlobalBeforeMiddlewareEnd();
            $this->recordRoutingStart();
        });

        $this->dispatcher->listen(RouteMatched::class, function (RouteMatched $event) {
            FlareTracingMiddleware::$routeAttributesProvider = LaravelRouteAttributesProvider::fromRequest($event->request);

            FlareTracingMiddleware::$routeAttributesProvider !== null
                ? $this->recordRoutingEnd(FlareTracingMiddleware::$routeAttributesProvider)
                : $this->recordForcedRoutingEnd();

            if (FlareTracingMiddleware::$routeAttributesProvider !== null) {
                Telltale::request()?->recordRouteMatched(FlareTracingMiddleware::$routeAttributesProvider);
            }

            $this->recordBeforeMiddlewareStart();
        });

        $this->dispatcher->listen(RequestHandled::class, function (RequestHandled $event) {
            if (! $this->tracer->isSampling()) {
                return;
            }

            FlareTracingMiddleware::$routeAttributesProvider ??= LaravelRouteAttributesProvider::fromRequest($event->request);

            // In some cases when an error happens in one of these stages (or an abort is thrown) the only event to catch this is the RequestHandled event.
            Telltale::routing()?->recordGlobalBeforeMiddlewareEnd();
            Telltale::routing()?->recordBeforeMiddlewareEnd();

            FlareTracingMiddleware::$routeAttributesProvider !== null
                ? Telltale::routing()?->recordRoutingEnd(FlareTracingMiddleware::$routeAttributesProvider)
                : $this->recordForcedRoutingEnd();

            Telltale::response()?->recordEnd();
        });
    }
}
