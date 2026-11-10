<?php

namespace Apxcde\LaravelTelltale\Http\RouteDispatchers;

use Illuminate\Routing\Contracts\CallableDispatcher;
use Illuminate\Routing\Route;
use Spatie\FlareClient\Tracer;
use Apxcde\LaravelTelltale\Http\RouteDispatchers\Concerns\DispatchesRoutes;

class CallableRouteDispatcher implements CallableDispatcher
{
    use DispatchesRoutes;

    public function __construct(
        protected Tracer $tracer,
        protected CallableDispatcher $dispatcher,
    ) {
    }

    public function dispatch(Route $route, $callable): mixed
    {
        return $this->wrapDispatcher(fn () => $this->dispatcher->dispatch($route, $callable));
    }
}
