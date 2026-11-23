<?php

use Apxcde\LaravelTelltale\Http\Middleware\FlareTracingMiddleware;
use Apxcde\LaravelTelltale\Support\CurrentUser;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Spatie\FlareClient\Enums\SpanType;
use Spatie\FlareClient\Tests\Shared\FakeApi;
use Symfony\Component\HttpFoundation\Response;

function applicationUser(int|string $id = 7, array $attributes = []): User
{
    return (new User())->forceFill(['id' => $id, ...$attributes]);
}

it('names nobody, and wakes no guard, when nobody asked who the user is', function () {
    setupFlare();

    expect(app(CurrentUser::class)->id())->toBeNull();
    expect(app(CurrentUser::class)->user())->toBeNull();
    expect(app('auth')->hasResolvedGuards())->toBeFalse();
});

it('names the user a guard has signed in', function () {
    setupFlare();

    Auth::setUser($user = applicationUser(7));

    expect(app(CurrentUser::class)->id())->toBe(7);
    expect(app(CurrentUser::class)->user())->toBe($user);
});

it('names a user whose id is a string', function () {
    setupFlare();

    Auth::setUser(new GenericUser(['id' => '01HZX4M7K2N8Q5R3T6V9W0Y1AB']));

    expect(app(CurrentUser::class)->id())->toBe('01HZX4M7K2N8Q5R3T6V9W0Y1AB');
});

it('does not load a user the guard has not looked up yet', function () {
    setupFlare();

    $lookups = 0;

    $provider = Mockery::mock(UserProvider::class);
    $provider->shouldReceive('retrieveById')->andReturnUsing(function () use (&$lookups) {
        $lookups++;

        return applicationUser(7);
    });

    Auth::provider('counting', fn () => $provider);
    config()->set('auth.providers.users.driver', 'counting');

    // The session says user 7 is signed in, and nothing has asked the guard yet.
    session()->put(Auth::guard()->getName(), 7);

    expect(app(CurrentUser::class)->id())->toBeNull();
    expect($lookups)->toBe(0);

    // The guard would have loaded the user had it been asked.
    expect(Auth::id())->toBe(7);
    expect($lookups)->toBe(1);
    expect(app(CurrentUser::class)->id())->toBe(7);
});

it('names the user a running job carries before anyone signed in', function () {
    setupFlare();

    $currentUser = app(CurrentUser::class);

    Auth::setUser(applicationUser(7));

    $currentUser->enterJob(42);

    expect($currentUser->id())->toBe(42);
    expect($currentUser->carried())->toBe(42);

    $currentUser->enterJob('91');

    expect($currentUser->id())->toBe('91');

    $currentUser->leaveJob();

    expect($currentUser->id())->toBe(42);

    $currentUser->leaveJob();

    expect($currentUser->carried())->toBeNull();
    expect($currentUser->id())->toBe(7);
});

it('falls back to the signed-in user inside a job that carries nobody', function () {
    setupFlare();

    $currentUser = app(CurrentUser::class);

    $currentUser->enterJob(null);

    expect($currentUser->id())->toBeNull();

    Auth::setUser(applicationUser(7));

    expect($currentUser->id())->toBe(7);
});

it('carries only what can be an id', function (mixed $id, int|string|null $expected) {
    expect(CurrentUser::normalizeId($id))->toBe($expected);
})->with([
    'a number' => [58714, 58714],
    'a string' => ['9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d', '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d'],
    'an empty string' => ['', null],
    'null' => [null, null],
    'a float' => [1.5, null],
    'a boolean' => [true, null],
    'a list' => [[1], null],
]);

it('remembers who signed out, for the request they signed out in', function () {
    setupFlare();

    $currentUser = app(CurrentUser::class);

    $request = Request::create('https://app.test/logout', 'POST');
    app()->instance('request', $request);

    Auth::setUser($user = applicationUser(7, ['email' => 'amina@example.com']));
    Auth::logout();

    expect(Auth::hasUser())->toBeFalse();
    expect($currentUser->user())->toBe($user);
    expect($currentUser->id())->toBe(7);
    expect($currentUser->forRequest($request)?->toArray())->toBe([
        'user.id' => 7,
        'user.email' => 'amina@example.com',
    ]);

    $next = Request::create('https://app.test/login', 'GET');
    app()->instance('request', $next);

    expect($currentUser->id())->toBeNull();
    expect($currentUser->forRequest($next))->toBeNull();
});

it('prefers the user a request still has over one who signed out', function () {
    setupFlare();

    $request = Request::create('https://app.test/impersonate/stop', 'POST');
    $request->setUserResolver(fn () => Auth::user());
    app()->instance('request', $request);

    Auth::setUser(applicationUser(7));
    Auth::logout();
    Auth::setUser(applicationUser(3));

    expect(app(CurrentUser::class)->forRequest($request)?->id())->toBe(3);
    expect(app(CurrentUser::class)->id())->toBe(3);
});

it('puts the user who signed out on the span of the request', function () {
    $flare = setupFlare(alwaysSampleTraces: true);

    $flare->lifecycle->start();

    $request = Request::create('https://app.test/logout', 'POST');
    $request->setUserResolver(fn () => Auth::user());
    app()->instance('request', $request);

    $middleware = app(FlareTracingMiddleware::class);

    $middleware->handle($request, function () {
        Auth::setUser(applicationUser(7, ['name' => 'Amina Otieno']));
        Auth::logout();

        return new Response('ok');
    });

    $middleware->terminate($request, new Response('ok'));

    $flare->lifecycle->terminated();

    FakeApi::lastTrace()
        ->expectSpan(SpanType::Request)
        ->expectAttribute('user.id', 7)
        ->expectAttribute('user.full_name', 'Amina Otieno');
});

it('forgets the previous job of a worker when it starts over', function () {
    setupFlare();

    $currentUser = app(CurrentUser::class);

    $currentUser->enterJob(42);
    $currentUser->rememberSignedOut(applicationUser(7));

    $currentUser->startOver();

    expect($currentUser->carried())->toBeNull();
    expect($currentUser->id())->toBeNull();
});
