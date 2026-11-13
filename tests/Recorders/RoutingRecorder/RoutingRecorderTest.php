<?php

use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Routing\Events\Routing;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Event;
use Spatie\FlareClient\Enums\SpanType;
use Spatie\FlareClient\Tests\Shared\FakeApi;
use Symfony\Component\HttpFoundation\Response;

it('records and closes the routing span when a request did not match a route', function () {
    $flare = setupFlare(alwaysSampleTraces: true);

    $flare->tracer->startTrace();

    $request = Request::create('https://flare.test/missing-route', 'GET');

    $flare->request()?->recordStartFromSymfonyRequest($request);

    Event::dispatch(new Routing($request));
    Event::dispatch(new RequestHandled($request, new Response('', 404)));

    $flare->request()?->recordEndFromSymfonyResponse(new Response('', 404));

    $flare->tracer->endTrace();

    FakeApi::lastTrace()
        ->expectSpan(SpanType::Routing)
        ->expectType(SpanType::Routing);

    FakeApi::lastTrace()->expectAllSpansClosed();
});

it('puts the route and the method on the request span as soon as the route is matched', function () {
    $flare = setupFlare(alwaysSampleTraces: true);

    $flare->tracer->startTrace();

    $request = Request::create('https://flare.test/teams/178/contacts', 'GET');
    $route = (new Route(['GET'], 'teams/{team}/contacts', fn () => 'ok'))->bind($request);
    $request->setRouteResolver(fn () => $route);

    $flare->request()?->recordStartFromSymfonyRequest($request);

    Event::dispatch(new Routing($request));
    Event::dispatch(new RouteMatched($route, $request));

    // The request ends here without its terminate step, as it does when the client has gone away.
    $flare->tracer->endTrace();

    FakeApi::lastTrace()
        ->expectSpan(SpanType::Request)
        ->expectName('Request - teams/{team}/contacts')
        ->expectAttribute('flare.entry_point.handler.identifier', 'teams/{team}/contacts')
        ->expectAttribute('http.route', 'teams/{team}/contacts')
        ->expectAttribute('http.request.method', 'GET');
});
