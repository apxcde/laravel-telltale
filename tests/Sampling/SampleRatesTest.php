<?php

use Apxcde\LaravelTelltale\Recorders\JobRecorder\JobRecorder;
use Apxcde\LaravelTelltale\TelltaleConfig;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use Spatie\FlareClient\Enums\SpanType;
use Spatie\FlareClient\Support\Ids;
use Spatie\FlareClient\Tests\Shared\FakeApi;

/**
 * Boot the package with the sampler `config/telltale.php` builds from the given rates.
 */
function setupFlareWithSampleRates(float $samplerRate, array $sampleRates): void
{
    config()->set('telltale', require __DIR__.'/../../config/telltale.php');
    config()->set('telltale.sampler.config.rate', $samplerRate);
    config()->set('telltale.sample_rates', $sampleRates);

    $built = TelltaleConfig::fromLaravelConfig();

    setupFlare(
        fn (TelltaleConfig $config) => $config->trace(true)->sampler($built->sampler, $built->samplerConfig),
        isUsingSubtasks: true,
    );
}

/**
 * Run a job the way a worker does. The request that dispatched it was traced or not.
 */
function runJobDispatchedByRequest(bool $requestWasTraced): void
{
    $job = new SyncJob(app(), json_encode([
        'displayName' => 'App\\Jobs\\TranscribeVoicemail',
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'data' => ['commandName' => 'App\\Jobs\\TranscribeVoicemail', 'command' => ''],
        Ids::FLARE_TRACE_PARENT => '00-1234567890abcdef1234567890abcdef-fedcba9876543210-'.($requestWasTraced ? '01' : '00'),
    ]), 'redis', 'default');

    $recorder = app(JobRecorder::class);
    $recorder->recordProcessing(new JobProcessing('redis', $job));
    $recorder->recordProcessed(new JobProcessed('redis', $job));
}

it('traces every job when jobs have a rate of 1, whatever the request did', function (bool $requestWasTraced) {
    setupFlareWithSampleRates(samplerRate: 0, sampleRates: ['jobs' => '1']);

    runJobDispatchedByRequest($requestWasTraced);

    FakeApi::assertTracesSent(1);

    FakeApi::lastTrace()->expectSpan(0)->expectType(SpanType::Job);
})->with([
    'the request was traced' => [true],
    'the request was not traced' => [false],
]);

it('traces no job when jobs have a rate of 0, even one a traced request dispatched', function () {
    setupFlareWithSampleRates(samplerRate: 1, sampleRates: ['jobs' => 0]);

    runJobDispatchedByRequest(requestWasTraced: true);

    FakeApi::assertTracesSent(0);
});

it('lets a job follow the request that dispatched it when jobs have no rate', function (bool $requestWasTraced, int $traces) {
    setupFlareWithSampleRates(samplerRate: 0, sampleRates: ['requests' => 1, 'jobs' => null]);

    runJobDispatchedByRequest($requestWasTraced);

    FakeApi::assertTracesSent($traces);
})->with([
    'the request was traced' => [true, 1],
    'the request was not traced' => [false, 0],
]);
