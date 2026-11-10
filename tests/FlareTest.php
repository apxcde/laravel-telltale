<?php

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Spatie\FlareClient\Flare;
use Spatie\FlareClient\Resources\Resource;
use Spatie\FlareClient\Tests\Shared\FakeApi;
use Spatie\FlareClient\Tests\Shared\FakeTime;
use Apxcde\LaravelTelltale\TelltaleConfig;
use Apxcde\LaravelTelltale\TelltaleServiceProvider;

beforeEach(function () {
    Artisan::call('view:clear');

    app()['config']['logging.channels.telltale'] = [
        'driver' => 'telltale',
    ];

    config()->set('logging.channels.telltale.driver', 'telltale');
    config()->set('logging.default', 'telltale');
    config()->set('telltale.key', 'some-key');

    FakeTime::setup('2019-01-01 12:34:56');

    View::addLocation(__DIR__.'/stubs/views');
});

it('can manually report exceptions', function () {
    $flare = setupFlare();

    $flare->report(new Exception());

    FakeApi::assertSent(reports: 1);
});


it('can report exceptions using the Laravel report helper', function () {
    setupFlare();

    report(new Exception());

    FakeApi::assertSent(reports: 1);
});

it('will not trace, log or report when flare is disabled', function () {
    setupFlare(withoutApiKey: true, alwaysSampleTraces: true);

    $flare = app(Flare::class);

    // Todo: extend this with more checks

    report(new Exception());

    Log::critical("test");

    FakeApi::assertSent(reports: 0, traces: 0, logs: 0);
});

it('runs configuring callbacks against the config before it is used to boot Flare', function () {
    TelltaleServiceProvider::flushConfigurationCallbacks();
    config()->set('telltale.key', 'some-key');

    TelltaleServiceProvider::configure(fn (TelltaleConfig $config) => $config->applicationName = 'first');
    TelltaleServiceProvider::configure(function (TelltaleConfig $config) {
        $config->applicationName = 'from-hook';
        $config->configureResource(fn (Resource $resource) => $resource->addAttribute('custom.attribute', 'value'));
    });

    app()->register(new TelltaleServiceProvider(resolve(Application::class)));

    expect(app(TelltaleConfig::class)->applicationName)->toBe('from-hook');
    expect(app(Resource::class)->attributes)->toHaveKey('custom.attribute', 'value');

    TelltaleServiceProvider::flushConfigurationCallbacks();
});
