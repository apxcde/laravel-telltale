<?php

use Apxcde\LaravelTelltale\Recorders\JobRecorder\JobRecorder;
use Apxcde\LaravelTelltale\Recorders\QueueRecorder\QueueRecorder;
use Apxcde\LaravelTelltale\Support\CurrentUser;
use Apxcde\LaravelTelltale\Tests\stubs\Jobs\QueueableJob;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Auth\User;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Spatie\Backtrace\Arguments\ReduceArgumentPayloadAction;
use Spatie\FlareClient\EntryPoint\EntryPointResolver;
use Spatie\FlareClient\Enums\SpanType;
use Spatie\FlareClient\Flare;
use Spatie\FlareClient\Support\BackTracer;
use Spatie\FlareClient\Support\Lifecycle;
use Spatie\FlareClient\Tests\Shared\FakeApi;
use Spatie\FlareClient\Tracer;

function dispatchingUser(int|string $id = 58714): User
{
    return (new User())->forceFill(['id' => $id, 'name' => 'Amina Otieno', 'email' => 'amina@example.com']);
}

/**
 * A job as a worker reserves it, dispatched by the given user or by nobody.
 */
function reservedJob(int|string|null $userId = null, string $class = 'App\\Jobs\\TranscribeVoicemail'): Job
{
    return new SyncJob(app(), json_encode(array_filter([
        'displayName' => $class,
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'data' => ['commandName' => $class, 'command' => ''],
        CurrentUser::PAYLOAD_KEY => $userId,
    ], fn (mixed $value) => $value !== null)), 'redis', 'default');
}

/**
 * Send what the application logs to Telltale.
 */
function logToTelltale(): void
{
    config()->set('logging.channels.telltale.driver', 'telltale');
    config()->set('logging.default', 'telltale');
}

/**
 * Queue jobs on the database connection, so a dispatched job's payload can be read back.
 */
function queueOnDatabase(): void
{
    Schema::dropIfExists('jobs');

    Schema::create('jobs', function ($table) {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });

    config()->set('queue.default', 'database');
}

/** @return array<string, mixed> */
function lastQueuedPayload(): array
{
    return json_decode(DB::table('jobs')->orderByDesc('id')->value('payload'), true);
}

it('sends the id of the signed-in user along with a job it dispatches', function () {
    setupFlare();
    queueOnDatabase();

    Auth::setUser(dispatchingUser(58714));

    dispatch(new QueueableJob([]));

    expect(lastQueuedPayload())
        ->toHaveKey(CurrentUser::PAYLOAD_KEY, 58714)
        ->not->toHaveKey('user.full_name');
});

it('sends no user along with a job nobody signed in dispatched', function () {
    setupFlare();
    queueOnDatabase();

    dispatch(new QueueableJob([]));

    expect(lastQueuedPayload())->not->toHaveKey(CurrentUser::PAYLOAD_KEY);
});

it('sends the user along when tracing is off', function () {
    setupFlare(fn ($config) => $config->trace(false));
    queueOnDatabase();

    Auth::setUser(dispatchingUser(58714));

    dispatch(new QueueableJob([]));

    expect(lastQueuedPayload())->toHaveKey(CurrentUser::PAYLOAD_KEY, 58714);
});

it('leaves the payload alone when nothing is reported', function () {
    setupFlare(withoutApiKey: true);
    queueOnDatabase();

    Auth::setUser(dispatchingUser(58714));

    dispatch(new QueueableJob([]));

    expect(lastQueuedPayload())->not->toHaveKey(CurrentUser::PAYLOAD_KEY);
});

it('puts the user who dispatched a job on the span of the job a worker runs', function () {
    setupFlare(alwaysSampleTraces: true, isUsingSubtasks: true);

    $job = reservedJob(userId: 58714);

    $recorder = app(JobRecorder::class);
    $recorder->recordProcessing(new JobProcessing('redis', $job));

    expect(app(CurrentUser::class)->id())->toBe(58714);

    $recorder->recordProcessed(new JobProcessed('redis', $job));

    expect(app(CurrentUser::class)->id())->toBeNull();

    FakeApi::lastTrace()
        ->expectSpanCount(1)
        ->expectSpan(0)
        ->expectType(SpanType::Job)
        ->expectAttribute('user.id', 58714)
        ->expectMissingAttribute('user.email')
        ->expectMissingAttribute('user.full_name');
});

it('puts no user on the span of a job nobody signed in dispatched', function () {
    setupFlare(alwaysSampleTraces: true, isUsingSubtasks: true);

    $job = reservedJob();

    $recorder = app(JobRecorder::class);
    $recorder->recordProcessing(new JobProcessing('redis', $job));
    $recorder->recordProcessed(new JobProcessed('redis', $job));

    FakeApi::lastTrace()
        ->expectSpan(0)
        ->expectType(SpanType::Job)
        ->expectMissingAttribute('user.id');
});

it('ignores a user id in the payload that cannot be an id', function () {
    setupFlare(alwaysSampleTraces: true, isUsingSubtasks: true);

    $job = new SyncJob(app(), json_encode([
        'displayName' => 'App\\Jobs\\TranscribeVoicemail',
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'data' => ['commandName' => 'App\\Jobs\\TranscribeVoicemail', 'command' => ''],
        CurrentUser::PAYLOAD_KEY => ['id' => 58714],
    ]), 'redis', 'default');

    $recorder = app(JobRecorder::class);
    $recorder->recordProcessing(new JobProcessing('redis', $job));
    $recorder->recordProcessed(new JobProcessed('redis', $job));

    FakeApi::lastTrace()->expectSpan(0)->expectMissingAttribute('user.id');
});

it('puts the signed-in user on the span of a job that runs right away', function () {
    $flare = setupFlare(alwaysSampleTraces: true);

    $flare->tracer->startTrace();

    Auth::setUser(dispatchingUser(58714));

    dispatch(fn () => 'ok')->onConnection('sync');

    $flare->tracer->endTrace();

    FakeApi::lastTrace()
        ->expectSpanCount(1)
        ->expectSpan(0)
        ->expectType(SpanType::Job)
        ->expectAttribute('user.id', 58714);
});

it('hands the user on to a job dispatched from inside a job', function () {
    setupFlare(isUsingSubtasks: true);
    queueOnDatabase();

    $job = reservedJob(userId: 58714);

    $recorder = app(JobRecorder::class);
    $recorder->recordProcessing(new JobProcessing('redis', $job));

    dispatch(new QueueableJob([]));

    $recorder->recordProcessed(new JobProcessed('redis', $job));

    expect(lastQueuedPayload())->toHaveKey(CurrentUser::PAYLOAD_KEY, 58714);

    dispatch(new QueueableJob([]));

    expect(lastQueuedPayload())->not->toHaveKey(CurrentUser::PAYLOAD_KEY);
});

it('does not hand the user of one job to the next job of a worker', function (Closure $endFirstJob) {
    setupFlare(alwaysSampleTraces: true, isUsingSubtasks: true);

    $recorder = app(JobRecorder::class);

    $first = reservedJob(userId: 58714);
    $recorder->recordProcessing(new JobProcessing('redis', $first));

    $endFirstJob($recorder, $first);

    $second = reservedJob(class: 'App\\Jobs\\PruneRecordings');
    $recorder->recordProcessing(new JobProcessing('redis', $second));

    expect(app(CurrentUser::class)->id())->toBeNull();

    $recorder->recordProcessed(new JobProcessed('redis', $second));

    // Nothing of the first job is left once the worker is idle again.
    expect(app(CurrentUser::class)->carried())->toBeNull();

    FakeApi::lastTrace()
        ->expectSpan(0)
        ->expectName('Job - App\\Jobs\\PruneRecordings')
        ->expectMissingAttribute('user.id');
})->with([
    'the first job was processed' => [fn (JobRecorder $recorder, Job $job) => $recorder->recordProcessed(new JobProcessed('redis', $job))],
    'the first job threw' => [fn (JobRecorder $recorder, Job $job) => $recorder->recordExceptionOccurred(new JobExceptionOccurred('redis', $job, new RuntimeException('Failed')))],
    // Deleting a failed job can throw, and then neither closing event is fired.
    'the first job ended without a closing event' => [fn () => event(new Looping('redis', 'default'))],
]);

it('does not name a user an earlier job of the worker signed in', function () {
    setupFlare(alwaysSampleTraces: true, isUsingSubtasks: true);
    queueOnDatabase();
    logToTelltale();

    $recorder = app(JobRecorder::class);

    // Auth::login(), once() and onceUsingId() leave the user on the guard, and a worker does
    // not reset its guards between jobs.
    $first = reservedJob();
    $recorder->recordProcessing(new JobProcessing('redis', $first));
    Auth::setUser(dispatchingUser(7));
    $recorder->recordProcessed(new JobProcessed('redis', $first));

    $second = reservedJob(class: 'App\\Jobs\\PruneRecordings');
    $recorder->recordProcessing(new JobProcessing('redis', $second));

    Log::info('Pruning recordings');
    dispatch(new QueueableJob([]));

    $recorder->recordProcessed(new JobProcessed('redis', $second));
    app(Flare::class)->logger->flush();

    expect(Auth::id())->toBe(7);
    FakeApi::lastLog()->expectLog(0)->expectMissingAttribute('user.id');
    expect(lastQueuedPayload())->not->toHaveKey(CurrentUser::PAYLOAD_KEY);
});

it('names the user a job carries on its logs and its reports, not a user it signs in', function () {
    setupFlare(isUsingSubtasks: true);
    logToTelltale();

    $job = reservedJob(userId: 58714);

    $recorder = app(JobRecorder::class);
    $recorder->recordProcessing(new JobProcessing('redis', $job));

    Auth::setUser(dispatchingUser(7));

    Log::info('Signed in as the owner of the voicemail');
    app(Flare::class)->reportHandled(new RuntimeException('Could not reach the carrier, retrying'));

    $recorder->recordProcessed(new JobProcessed('redis', $job));
    app(Flare::class)->logger->flush();

    FakeApi::lastLog()->expectLog(0)->expectAttribute('user.id', 58714);
    FakeApi::lastReport()->expectAttribute('user.id', 58714);
});

it('names nobody on the logs and reports of a job nobody dispatched, even one that signs a user in', function () {
    setupFlare(isUsingSubtasks: true);
    logToTelltale();

    $job = reservedJob();

    $recorder = app(JobRecorder::class);
    $recorder->recordProcessing(new JobProcessing('redis', $job));

    Auth::setUser(dispatchingUser(7));

    Log::info('Signed in as the owner of the voicemail');
    app(Flare::class)->reportHandled(new RuntimeException('Could not reach the carrier, retrying'));

    $recorder->recordProcessed(new JobProcessed('redis', $job));
    app(Flare::class)->logger->flush();

    FakeApi::lastLog()->expectLog(0)->expectMissingAttribute('user.id');
    FakeApi::lastReport()->expectMissingAttribute('user.id');
});

it('names nobody on the failure of a job nobody dispatched, even one that signs a user in', function () {
    setupFlare(isUsingSubtasks: true);
    logToTelltale();

    $job = reservedJob();
    $exception = new RuntimeException('Transcription service is down');

    // What a worker does with a job that finally fails: it reports after the job has ended.
    event(new JobProcessing('redis', $job));
    Auth::setUser(dispatchingUser(7));
    Log::info('Signed in as the owner of the voicemail');
    event(new JobFailed('redis', $job, $exception));
    event(new JobExceptionOccurred('redis', $job, $exception));

    report($exception);

    event(new Looping('redis', 'default'));
    app(Flare::class)->logger->flush();

    FakeApi::lastLog()->expectLog(0)->expectMissingAttribute('user.id');
    FakeApi::lastReport()->expectMissingAttribute('user.id');
});

it('keeps the user of a job while a job inside it runs and ends', function () {
    setupFlare(isUsingSubtasks: true);

    $recorder = app(JobRecorder::class);

    $outer = reservedJob(userId: 58714);
    $inner = reservedJob(userId: 58714, class: 'App\\Jobs\\NotifyTeam');

    $recorder->recordProcessing(new JobProcessing('redis', $outer));
    $recorder->recordProcessing(new JobProcessing('sync', $inner));
    $recorder->recordProcessed(new JobProcessed('sync', $inner));

    expect(app(CurrentUser::class)->id())->toBe(58714);

    $recorder->recordProcessed(new JobProcessed('redis', $outer));

    expect(app(CurrentUser::class)->id())->toBeNull();
});

it('names the user who dispatched a job on the report of its failure', function () {
    setupFlare(isUsingSubtasks: true);

    $job = reservedJob(userId: 58714);
    $exception = new RuntimeException('Transcription service is down');

    // What a worker does with a job that finally fails.
    event(new JobProcessing('redis', $job));
    event(new JobFailed('redis', $job, $exception));
    event(new JobExceptionOccurred('redis', $job, $exception));

    report($exception);

    event(new Looping('redis', 'default'));

    FakeApi::assertSent(reports: 1);

    FakeApi::lastReport()
        ->expectExceptionClass(RuntimeException::class)
        ->expectAttribute('flare.entry_point.type', 'queue')
        ->expectAttribute('user.id', 58714)
        ->expectMissingAttribute('user.email');
});

it('names the user on the report of a failed job the handler never reports', function () {
    setupFlare(isUsingSubtasks: true);

    $job = reservedJob(userId: 58714);
    $exception = (new Illuminate\Database\Eloquent\ModelNotFoundException())->setModel('App\\Models\\Voicemail', [58714]);

    event(new JobProcessing('redis', $job));
    event(new JobFailed('redis', $job, $exception));
    event(new JobExceptionOccurred('redis', $job, $exception));
    event(new Looping('redis', 'default'));

    FakeApi::assertSent(reports: 1);

    FakeApi::lastReport()->expectAttribute('user.id', 58714);
});

it('names the user on an exception a job reports while it runs', function () {
    setupFlare(isUsingSubtasks: true);

    $job = reservedJob(userId: 58714);

    $recorder = app(JobRecorder::class);
    $recorder->recordProcessing(new JobProcessing('redis', $job));

    app(Flare::class)->reportHandled(new RuntimeException('Could not reach the carrier, retrying'));

    $recorder->recordProcessed(new JobProcessed('redis', $job));

    FakeApi::lastReport()
        ->expectHandled()
        ->expectAttribute('user.id', 58714);
});

it('names no user on the report of a job nobody signed in dispatched', function () {
    setupFlare(isUsingSubtasks: true);

    $job = reservedJob();
    $exception = new RuntimeException('Transcription service is down');

    event(new JobProcessing('redis', $job));
    event(new JobFailed('redis', $job, $exception));
    event(new JobExceptionOccurred('redis', $job, $exception));

    report($exception);

    FakeApi::lastReport()->expectMissingAttribute('user.id');
});

it('builds the recorders from the arguments they took before they followed the user', function () {
    setupFlare();

    // What a subclass that calls the parent constructor with positional arguments passes.
    $jobRecorder = fn () => new JobRecorder(
        app(Tracer::class),
        app(BackTracer::class),
        app(EntryPointResolver::class),
        app(Lifecycle::class),
        app('events'),
        app(ReduceArgumentPayloadAction::class),
        [],
    );

    $queueRecorder = fn () => new QueueRecorder(
        app(Tracer::class),
        app(BackTracer::class),
        app('events'),
        [],
    );

    expect($jobRecorder)->not->toThrow(TypeError::class);
    expect($queueRecorder)->not->toThrow(TypeError::class);
});
