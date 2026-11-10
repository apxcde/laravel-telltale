<?php

use Illuminate\Http\Request;
use Spatie\FlareClient\Support\Redactor;
use Apxcde\LaravelTelltale\AttributesProviders\LaravelRequestAttributesProvider;
use Apxcde\LaravelTelltale\Support\LivewireComponentFinder;

it('returns the url', function () {
    $request = Request::create('/route', 'GET');

    $attributes = (new LaravelRequestAttributesProvider(
        new Redactor(),
        app(LivewireComponentFinder::class),
        $request,
    ))->toArray();

    expect($attributes['url.full'])->toBe('http://localhost/route');
});

it('censors query string values in the url, the query and the referer', function () {
    $request = Request::create('/search?phone=%2B15551234567&page=2', 'GET', server: [
        'HTTP_REFERER' => 'http://localhost/contacts?phone=%2B15551234567',
    ]);

    $provider = new LaravelRequestAttributesProvider(
        new Redactor(censorBodyFields: ['phone']),
        app(LivewireComponentFinder::class),
        $request,
    );

    $attributes = $provider->toArray();

    expect($attributes['url.full'])->toBe('http://localhost/search?page=2&phone=<CENSORED:string>');
    expect($attributes['url.query'])->toBe('phone=<CENSORED:string>&page=2');
    expect($attributes['http.request.headers']['referer'])->toBe('http://localhost/contacts?phone=<CENSORED:string>');
    expect($provider->url())->toBe($attributes['url.full']);
    expect(json_encode($attributes))->not->toContain('15551234567');
});

it('leaves a url without censored parameters as it is', function () {
    $request = Request::create('/search?q=a+b&page=2', 'GET');

    $attributes = (new LaravelRequestAttributesProvider(
        new Redactor(censorBodyFields: ['phone']),
        app(LivewireComponentFinder::class),
        $request,
    ))->toArray();

    expect($attributes['url.full'])->toBe('http://localhost/search?page=2&q=a%20b');
    expect($attributes['url.query'])->toBe('q=a+b&page=2');
});

it('returns the cookies', function () {
    $request = Request::create('/route', 'GET', [], ['cookie' => 'noms']);

    $attributes = (new LaravelRequestAttributesProvider(
        new Redactor(),
        app(LivewireComponentFinder::class),
        $request,
    ))->toArray();

    expect($attributes['http.request.cookies'])->toBe(['cookie' => 'noms']);
});
