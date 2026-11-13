<?php

use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\ManuallyFailedException;
use Illuminate\Queue\TimeoutExceededException;
use Spatie\FlareClient\FlareConfig;
use Spatie\FlareClient\Tests\Shared\FakeApi;
use Apxcde\LaravelTelltale\Jobs\SendFlarePayload;
use Apxcde\LaravelTelltale\TelltaleConfig;

function failedJob(string $class = 'App\\Jobs\\TranscribeVoicemail'): Job
{
    return new SyncJob(app(), json_encode([
        'displayName' => $class,
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'data' => ['commandName' => $class, 'command' => ''],
    ]), 'redis', 'default');
}

/**
 * What a worker does with a job that finally fails: it starts the job, fires JobFailed and
 * JobExceptionOccurred, hands the same exception to the exception handler, and then loops to
 * reserve the next job.
 */
function failJobLikeAWorker(Throwable $exception, ?Job $job = null): void
{
    $job ??= failedJob();

    event(new JobProcessing('redis', $job));
    event(new JobFailed('redis', $job, $exception));
    event(new JobExceptionOccurred('redis', $job, $exception));

    report($exception);

    event(new Looping('redis', 'default'));
}

it('reports the exception a job failed with once when the handler reports it', function () {
    setupFlare(isUsingSubtasks: true);

    failJobLikeAWorker(new RuntimeException('Transcription service is down'));

    FakeApi::assertSent(reports: 1);

    FakeApi::lastReport()
        ->expectExceptionClass(RuntimeException::class)
        ->expectMessage('Transcription service is down')
        ->expectAttribute('flare.entry_point.type', 'queue')
        ->expectAttribute('flare.entry_point.handler.identifier', 'App\\Jobs\\TranscribeVoicemail');
});

it('reports a failed job whose exception the handler never reports', function () {
    setupFlare(isUsingSubtasks: true);

    $exception = (new ModelNotFoundException())->setModel('App\\Models\\Voicemail', [58714]);

    expect(app(Illuminate\Contracts\Debug\ExceptionHandler::class)->shouldReport($exception))->toBeFalse();

    failJobLikeAWorker($exception);

    FakeApi::assertSent(reports: 1);

    FakeApi::lastReport()
        ->expectExceptionClass(ModelNotFoundException::class)
        ->expectAttribute('flare.entry_point.handler.identifier', 'App\\Jobs\\TranscribeVoicemail');
});

it('reports a job that failed itself without throwing', function () {
    setupFlare(isUsingSubtasks: true);

    $job = failedJob();

    event(new JobProcessing('redis', $job));
    event(new JobFailed('redis', $job, new ManuallyFailedException()));
    event(new JobProcessed('redis', $job));

    FakeApi::assertSent(reports: 0);

    event(new Looping('redis', 'default'));

    FakeApi::assertSent(reports: 1);

    FakeApi::lastReport()
        ->expectExceptionClass(ManuallyFailedException::class)
        ->expectAttribute('flare.entry_point.handler.identifier', 'App\\Jobs\\TranscribeVoicemail');
});

it('reports a failure the handler left when the next job starts, without waiting for the worker to loop', function () {
    setupFlare(isUsingSubtasks: true);

    $exception = (new ModelNotFoundException())->setModel('App\\Models\\Voicemail', [58714]);
    $job = failedJob();

    event(new JobProcessing('redis', $job));
    event(new JobFailed('redis', $job, $exception));
    event(new JobExceptionOccurred('redis', $job, $exception));

    report($exception);

    FakeApi::assertSent(reports: 0);

    event(new JobProcessing('redis', failedJob('App\\Jobs\\SendInvoice')));

    FakeApi::assertSent(reports: 1);

    FakeApi::lastReport()->expectAttribute('flare.entry_point.handler.identifier', 'App\\Jobs\\TranscribeVoicemail');
});

it('reports a job that failed by timing out before the worker is killed', function () {
    setupFlare(isUsingSubtasks: true);

    $job = failedJob();

    event(new JobProcessing('redis', $job));
    event(new JobFailed('redis', $job, new TimeoutExceededException('App\\Jobs\\TranscribeVoicemail has timed out.')));
    event(new JobTimedOut('redis', $job));

    FakeApi::assertSent(reports: 1);

    FakeApi::lastReport()
        ->expectExceptionClass(TimeoutExceededException::class)
        ->expectAttribute('flare.entry_point.handler.identifier', 'App\\Jobs\\TranscribeVoicemail');
});

it('still reports an exception the handler is given when no job failed with it', function () {
    setupFlare(isUsingSubtasks: true);

    failJobLikeAWorker(new RuntimeException('First'));

    report(new RuntimeException('Second, outside any job'));

    FakeApi::assertSent(reports: 2);

    FakeApi::lastReport()->expectMessage('Second, outside any job');
});

it('leaves failed jobs to the exception handler when reporting them is turned off', function () {
    setupFlare(fn (FlareConfig $config) => $config->collectLaravelJobs(reportFailed: false), isUsingSubtasks: true);

    failJobLikeAWorker((new ModelNotFoundException())->setModel('App\\Models\\Voicemail', [58714]));

    FakeApi::assertSent(reports: 0);

    failJobLikeAWorker(new RuntimeException('Reported by the handler, as before'));

    FakeApi::assertSent(reports: 1);
});

it('does not report a failed job for an exception class on its own ignore list', function () {
    setupFlare(
        fn (TelltaleConfig $config) => $config->collectLaravelJobs(dontReportFailed: [ModelNotFoundException::class]),
        isUsingSubtasks: true,
    );

    failJobLikeAWorker((new ModelNotFoundException())->setModel('App\\Models\\Voicemail', [58714]));

    FakeApi::assertSent(reports: 0);

    failJobLikeAWorker(new RuntimeException('Not on the list'));

    FakeApi::assertSent(reports: 1);
});

it('does not report the failure of a job the application ignores', function () {
    setupFlare(
        fn (TelltaleConfig $config) => $config->collectLaravelJobs(ignoredClasses: ['App\\Jobs\\Noisy']),
        isUsingSubtasks: true,
    );

    $job = failedJob('App\\Jobs\\Noisy');

    event(new JobProcessing('redis', $job));
    event(new JobFailed('redis', $job, (new ModelNotFoundException())->setModel('App\\Models\\Voicemail', [1])));
    event(new Looping('redis', 'default'));

    FakeApi::assertSent(reports: 0);
});

it('does not report the failure of the job that sends payloads to Telltale', function () {
    setupFlare(isUsingSubtasks: true);

    $job = failedJob(SendFlarePayload::class);

    event(new JobProcessing('redis', $job));
    event(new JobFailed('redis', $job, new RuntimeException('Telltale is unreachable')));
    event(new Looping('redis', 'default'));

    FakeApi::assertSent(reports: 0);
});

it('reports nothing for a failed job without a key', function () {
    setupFlare(withoutApiKey: true, isUsingSubtasks: true);

    failJobLikeAWorker(new RuntimeException('No key'));

    FakeApi::assertSent(reports: 0);
});
