<?php

namespace Apxcde\LaravelTelltale\Recorders\RequestRecorder;

use Apxcde\LaravelTelltale\AttributesProviders\LaravelRouteAttributesProvider;
use Spatie\FlareClient\AttributesProviders\UserAttributesProvider;
use Spatie\FlareClient\Contracts\RequestAttributesProvider;
use Spatie\FlareClient\Contracts\ResponseAttributesProvider;
use Spatie\FlareClient\Contracts\RouteAttributesProvider;
use Spatie\FlareClient\Recorders\RequestRecorder\RequestRecorder as BaseRequestRecorder;
use Spatie\FlareClient\Spans\Span;

class RequestRecorder extends BaseRequestRecorder
{
    public const DEFAULT_GROUP_UNMATCHED_ROUTE_ERRORS = true;

    protected bool $groupUnmatchedRouteErrors = self::DEFAULT_GROUP_UNMATCHED_ROUTE_ERRORS;

    protected function configure(array $config): void
    {
        parent::configure($config);

        $this->groupUnmatchedRouteErrors = $config['group_unmatched_route_errors'] ?? self::DEFAULT_GROUP_UNMATCHED_ROUTE_ERRORS;
    }

    public function recordEnd(
        ?RequestAttributesProvider $requestAttributesProvider = null,
        ?ResponseAttributesProvider $responseAttributesProvider = null,
        ?RouteAttributesProvider $routeAttributesProvider = null,
        ?UserAttributesProvider $userAttributesProvider = null,
        array $attributes = [],
    ): ?Span {
        $statusCode = $responseAttributesProvider?->statusCode();

        if (
            $this->groupUnmatchedRouteErrors
            && $statusCode !== null
            && $statusCode >= 400
            && $statusCode < 500
            && $routeAttributesProvider?->route() === null
            && ! array_key_exists('http.route', $attributes)
        ) {
            $attributes['http.route'] = "errors::{$statusCode}";
        }

        return parent::recordEnd(
            requestAttributesProvider: $requestAttributesProvider,
            responseAttributesProvider: $responseAttributesProvider,
            routeAttributesProvider: $routeAttributesProvider,
            userAttributesProvider: $userAttributesProvider,
            attributes: $attributes,
        );
    }

    /**
     * Put the route and the method on the open request span as soon as the
     * route is known.
     *
     * The rest of the request's attributes are added when the request
     * terminates. A request that never gets there, such as one whose client
     * went away before the response was sent, is still traced, and without
     * this it could only be filed under its full URL.
     */
    public function recordRouteMatched(LaravelRouteAttributesProvider $routeAttributesProvider): void
    {
        $span = end($this->stack);

        if (! $span instanceof Span) {
            return;
        }

        $route = $routeAttributesProvider->route();

        $span->updateName("Request - {$route}");
        $span->addAttributes([
            ...$this->entryPointResolver->get()->toAttributes(),
            'http.request.method' => strtoupper($routeAttributesProvider->method()),
            'http.route' => $route,
        ]);
    }

    protected function defaultIgnoredPaths(): array
    {
        return [
            '/_debugbar*',
            '/telescope*',
            '/horizon*',
            '/livewire/livewire.js',
            '/livewire/livewire.min.js',
            '/livewire-*/livewire.js',
            '/livewire-*/livewire.min.js',
        ];
    }
}
