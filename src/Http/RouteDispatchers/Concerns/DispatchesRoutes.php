<?php

namespace Apxcde\LaravelTelltale\Http\RouteDispatchers\Concerns;

use Closure;
use Apxcde\LaravelTelltale\Facades\Telltale;

trait DispatchesRoutes
{
    protected function wrapDispatcher(Closure $dispatch): mixed
    {
        Telltale::routing()?->recordBeforeMiddlewareEnd();

        $dispatched = $dispatch();

        Telltale::response()?->recordStart();

        return $dispatched;
    }
}
