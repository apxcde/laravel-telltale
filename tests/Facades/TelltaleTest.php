<?php

use Apxcde\LaravelTelltale\Facades\Telltale;
use Apxcde\LaravelTelltale\Http\Middleware\FlareTracingMiddleware;
use Apxcde\LaravelTelltale\Http\RouteDispatchers\CallableRouteDispatcher;
use Apxcde\LaravelTelltale\Http\RouteDispatchers\ControllerRouteDispatcher;
use Apxcde\LaravelTelltale\TelltaleConfig;
use Apxcde\LaravelTelltale\TelltaleServiceProvider;
use Apxcde\LaravelTelltale\Tests\stubs\Jobs\EmptyJob;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Contracts\CallableDispatcher;
use Illuminate\Routing\Contracts\ControllerDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\FlareClient\Enums\SpanType;
use Spatie\FlareClient\Tests\Shared\FakeApi;

beforeEach(function () {
    config()->set('database.default', 'testing');
    config()->set('database.connections.testing', [
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]);

    config()->set('logging.channels.telltale.driver', 'telltale');
    config()->set('logging.default', 'telltale');
});

/**
 * Boot the package the way an application does: from `config/telltale.php`.
 */
function bootTelltaleFromConfig(array $overrides = []): void
{
    TelltaleServiceProvider::flushConfigurationCallbacks();

    config()->set('telltale', [
        ...(require __DIR__.'/../../config/telltale.php'),
        'key' => 'some-key',
        'base_url' => 'https://telltale.example.com',
        ...$overrides,
    ]);

    TelltaleServiceProvider::configure(fn (TelltaleConfig $config) => $config->api = FakeApi::class);

    app()->register(new TelltaleServiceProvider(resolve(Application::class)));

    Telltale::handles();

    TelltaleServiceProvider::flushConfigurationCallbacks();
}

it('reports and logs when it is on', function () {
    bootTelltaleFromConfig();

    report(new RuntimeException('Carrier rejected the message'));
    Log::error('Carrier rejected the message');

    app(Spatie\FlareClient\Flare::class)->lifecycle->flush();

    FakeApi::assertSent(reports: 1, logs: 1);
});

it('records and sends nothing when it is turned off', function () {
    bootTelltaleFromConfig(['enabled' => false, 'trace' => true]);

    report(new RuntimeException('Carrier rejected the message'));
    Log::error('Carrier rejected the message');

    $value = Telltale::withoutTracing(fn () => 'still runs');

    app(Spatie\FlareClient\Flare::class)->lifecycle->flush();

    expect($value)->toBe('still runs');

    FakeApi::assertNothingSent();
});

it('installs no tracing middleware or route dispatchers when it is turned off', function () {
    bootTelltaleFromConfig(['enabled' => false, 'trace' => true]);

    expect(app(Kernel::class)->hasMiddleware(FlareTracingMiddleware::class))->toBeFalse()
        ->and(app(ControllerDispatcher::class))->not->toBeInstanceOf(ControllerRouteDispatcher::class)
        ->and(app(CallableDispatcher::class))->not->toBeInstanceOf(CallableRouteDispatcher::class);
});

it('traces nothing inside the code it is asked to leave out, and goes on afterwards', function () {
    $flare = setupFlare(alwaysSampleTraces: true);

    $flare->tracer->startTrace();

    DB::select('select 1');

    $result = Telltale::withoutTracing(function () {
        DB::select('select 2');

        return 'imported';
    });

    DB::select('select 3');

    $flare->tracer->endTrace();

    expect($result)->toBe('imported');

    $trace = FakeApi::lastTrace()->expectSpanCount(2, SpanType::Query);

    $trace->expectSpan(0)->expectName('Query - select 1');
    $trace->expectSpan(1)->expectName('Query - select 3');
});

it('goes on tracing after the code it left out throws', function () {
    $flare = setupFlare(alwaysSampleTraces: true);

    $flare->tracer->startTrace();

    try {
        Telltale::withoutTracing(function () {
            DB::select('select 2');

            throw new RuntimeException('Import failed');
        });
    } catch (RuntimeException) {
        //
    }

    expect($flare->tracer->isSampling())->toBeTrue();

    DB::select('select 3');

    $flare->tracer->endTrace();

    FakeApi::lastTrace()
        ->expectSpanCount(1)
        ->expectSpan(0)
        ->expectName('Query - select 3');
});

it('leaves out nested code until the outermost returns', function () {
    $flare = setupFlare(alwaysSampleTraces: true);

    $flare->tracer->startTrace();

    Telltale::withoutTracing(function () use ($flare) {
        Telltale::withoutTracing(fn () => DB::select('select 1'));

        expect($flare->tracer->isSampling())->toBeFalse();

        DB::select('select 2');
    });

    DB::select('select 3');

    $flare->tracer->endTrace();

    FakeApi::lastTrace()
        ->expectSpanCount(1)
        ->expectSpan(0)
        ->expectName('Query - select 3');
});

it('keeps leaving out the code after an ignored job runs inside it', function () {
    config()->set('queue.default', 'sync');

    $flare = setupFlare(
        fn (TelltaleConfig $config) => $config->collectLaravelJobs(ignoredClasses: [EmptyJob::class]),
        alwaysSampleTraces: true,
    );

    $flare->tracer->startTrace();

    DB::select('select 1');

    Telltale::withoutTracing(function () use ($flare) {
        // The job recorder pauses and resumes the tracer around an ignored job.
        EmptyJob::dispatch();

        expect($flare->tracer->isSampling())->toBeFalse();

        DB::select('select 2');
    });

    DB::select('select 3');

    $flare->tracer->endTrace();

    $trace = FakeApi::lastTrace()->expectSpanCount(2, SpanType::Query);

    $trace->expectSpan(0)->expectName('Query - select 1');
    $trace->expectSpan(1)->expectName('Query - select 3');
});

it('leaves tracing off when the code it left out stops tracing', function () {
    $flare = setupFlare(alwaysSampleTraces: true);

    $flare->tracer->startTrace();

    DB::select('select 1');

    Telltale::withoutTracing(fn () => Telltale::dontSample());

    expect($flare->tracer->isSampling())->toBeFalse();

    DB::select('select 2');

    $flare->tracer->endTrace();

    FakeApi::assertTracesSent(0);
});

it('runs the code as it is when nothing is traced', function () {
    $flare = setupFlare();

    expect(Telltale::withoutTracing(fn () => DB::select('select 1')))->toHaveCount(1);
    expect($flare->tracer->isSampling())->toBeFalse();

    FakeApi::assertTracesSent(0);
});

it('still sends what the code it left out logs', function () {
    $flare = setupFlare(alwaysSampleTraces: true);

    $flare->tracer->startTrace();

    Telltale::withoutTracing(fn () => Log::warning('Import skipped 12 rows'));

    $flare->tracer->endTrace();
    $flare->logger->flush();

    FakeApi::lastLog()
        ->expectLogCount(1)
        ->expectLog(0)
        ->expectBody('Import skipped 12 rows');
});

it('still lists what the code it left out ran on an error reported afterwards', function () {
    $flare = setupFlare(alwaysSampleTraces: true);

    $flare->tracer->startTrace();

    Telltale::withoutTracing(fn () => DB::select('select 2'));

    report(new RuntimeException('Import failed'));

    $flare->tracer->endTrace();

    // Nothing was traced, so no trace was sent.
    FakeApi::assertTracesSent(0);

    FakeApi::lastReport()
        ->expectEventCount(1, SpanType::Query)
        ->expectEvent(SpanType::Query)
        ->expectAttribute('db.statement', 'select 2');
});

it('stops tracing what is running and drops what was traced', function () {
    $flare = setupFlare(alwaysSampleTraces: true);

    $flare->tracer->startTrace();

    DB::select('select 1');

    Telltale::dontSample();

    expect($flare->tracer->isSampling())->toBeFalse();

    DB::select('select 2');

    $flare->tracer->endTrace();

    FakeApi::assertTracesSent(0);
});

it('still reports an error after tracing was stopped', function () {
    $flare = setupFlare(alwaysSampleTraces: true);

    $flare->tracer->startTrace();

    Telltale::dontSample();

    report(new RuntimeException('Carrier rejected the message'));

    $flare->tracer->endTrace();

    FakeApi::assertSent(reports: 1);
});
