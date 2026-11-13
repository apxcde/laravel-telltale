<?php

use Dotenv\Dotenv;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Spatie\FlareClient\Flare;
use Spatie\FlareClient\FlareMiddleware\AddJobInformation;
use Spatie\FlareClient\Tests\Shared\FakeApi;
use Spatie\FlareClient\Tests\Shared\FakeIds;
use Spatie\FlareClient\Tests\Shared\FakeTime;
use Apxcde\LaravelTelltale\Facades\Telltale as FlareFacade;
use Apxcde\LaravelTelltale\TelltaleConfig;
use Apxcde\LaravelTelltale\TelltaleServiceProvider;
use Apxcde\LaravelTelltale\Support\CollectsResolver;
use Apxcde\LaravelTelltale\Support\FailedJobReports;
use Apxcde\LaravelTelltale\Tests\TestCase;

uses(TestCase::class)->beforeEach(function () {
    FakeIds::reset();
    FakeApi::reset();
    AddJobInformation::clearLatestJobInfo();
    FailedJobReports::forgetAll();
})->in(__DIR__);

if (file_exists(__DIR__.'/../.env')) {
    $dotEnv = Dotenv::createImmutable(__DIR__.'/..');

    $dotEnv->load();
}

/**
 * @param ?Closure(TelltaleConfig):void $closure
 */
function setupFlare(
    ?Closure $closure = null,
    bool $withoutApiKey = false,
    bool $alwaysSampleTraces = false,
    bool $isUsingSubtasks = false,
): Flare {
    $config = new TelltaleConfig(
        apiToken: $withoutApiKey ? null : 'fake-api-key',
        collectsResolver: CollectsResolver::class,
    );

    $config->useDefaults();

    $config->trace(false);
    $config->log(true);

    if ($alwaysSampleTraces) {
        $config->trace(true);
        $config->alwaysSampleTraces();
    }

    $config->api = FakeApi::class;

    if (FakeTime::isSetup()) {
        $config->time = FakeTime::class;
    }

    if (FakeIds::isSetup()) {
        $config->ids = FakeIds::class;
    }

    if ($closure) {
        $closure($config);
    }

    app()->singleton(TelltaleConfig::class, fn () => $config);

    $provider = new TelltaleServiceProvider(
        resolve(Application::class),
        isUsingSubtasksClosure: $isUsingSubtasks ? fn () => true : null,
    );

    app()->register($provider);

    $flare = app()->make(Flare::class);

    class_exists(Exceptions::class)
        ? FlareFacade::handles(new Exceptions(app(ExceptionHandler::class)))
        : FlareFacade::handles();

    return $flare;
}
