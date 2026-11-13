<?php

namespace Apxcde\LaravelTelltale\Recorders\JobRecorder;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobInterrupted;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\Events\Looping;
use Spatie\Backtrace\Arguments\ReduceArgumentPayloadAction;
use Spatie\FlareClient\EntryPoint\EntryPointResolver;
use Spatie\FlareClient\Enums\LifecycleStage;
use Spatie\FlareClient\Enums\SpanEventType;
use Spatie\FlareClient\Enums\SpanStatusCode;
use Spatie\FlareClient\Flare;
use Spatie\FlareClient\FlareMiddleware\AddJobInformation;
use Spatie\FlareClient\Recorders\JobRecorder\JobRecorder as BaseJobRecorder;
use Spatie\FlareClient\Spans\Span;
use Spatie\FlareClient\Spans\SpanEvent;
use Spatie\FlareClient\Support\BackTracer;
use Spatie\FlareClient\Support\Ids;
use Spatie\FlareClient\Support\Lifecycle;
use Spatie\FlareClient\Tracer;
use Apxcde\LaravelTelltale\AttributesProviders\LaravelJobAttributesProvider;
use Apxcde\LaravelTelltale\Jobs\SendFlarePayload;
use Apxcde\LaravelTelltale\Support\FailedJobReports;
use Apxcde\LaravelTelltale\TelltaleConfig;
use Throwable;

class JobRecorder extends BaseJobRecorder
{
    public const DEFAULT_MAX_CHAINED_JOB_REPORTING_DEPTH = 2;

    public const DEFAULT_REPORT_FAILED = true;

    public const INTERNAL_IGNORED_JOBS = [
        SendFlarePayload::class,
    ];

    protected int $maxChainedJobReportingDepth = self::DEFAULT_MAX_CHAINED_JOB_REPORTING_DEPTH;

    protected bool $reportFailed = self::DEFAULT_REPORT_FAILED;

    /** @var array<int, class-string<Throwable>> */
    protected array $dontReportFailed = [];

    public function __construct(
        Tracer $tracer,
        BackTracer $backTracer,
        EntryPointResolver $entryPointResolver,
        Lifecycle $lifecycle,
        protected Dispatcher $dispatcher,
        protected ReduceArgumentPayloadAction $reduceArgumentPayloadAction,
        array $config,
    ) {
        parent::__construct($tracer, $backTracer, $entryPointResolver, $lifecycle, $config);
    }

    protected function configure(array $config): void
    {
        parent::configure($config);

        $this->maxChainedJobReportingDepth = $config['maxChainedJobReportingDepth'] ?? self::DEFAULT_MAX_CHAINED_JOB_REPORTING_DEPTH;
        $this->reportFailed = $config['report_failed'] ?? self::DEFAULT_REPORT_FAILED;
        $this->dontReportFailed = $config['dont_report_failed'] ?? [];
    }

    public function boot(): void
    {
        $this->dispatcher->listen(JobProcessing::class, [$this, 'recordProcessing']);
        $this->dispatcher->listen(JobProcessed::class, [$this, 'recordProcessed']);
        $this->dispatcher->listen(JobExceptionOccurred::class, [$this, 'recordExceptionOccurred']);
        $this->dispatcher->listen(JobFailed::class, [$this, 'rememberFailed']);
        $this->dispatcher->listen(JobTimedOut::class, [$this, 'recordTimedOut']);
        $this->dispatcher->listen(Looping::class, [$this, 'reportUnreportedFailures']);
        $this->dispatcher->listen(Looping::class, [$this, 'endLeftoverSubtask']);

        app()->terminating(fn () => $this->reportUnreportedFailures());

        if (class_exists(JobInterrupted::class)) {
            $this->dispatcher->listen(JobInterrupted::class, [$this, 'recordInterrupted']);
        }
    }

    public function recordProcessing(JobProcessing $event): ?Span
    {
        $this->reportUnreportedFailures();

        $traceparent = $event->job->payload()[Ids::FLARE_TRACE_PARENT] ?? null;

        return $this->recordStart(
            jobAttributesProvider: new LaravelJobAttributesProvider(
                $this->reduceArgumentPayloadAction,
                $event->job,
                $event->connectionName,
                $this->maxChainedJobReportingDepth,
            ),
            traceparent: $traceparent,
        );
    }

    public function recordProcessed(JobProcessed $event): ?Span
    {
        return $this->recordEnd([
            'laravel.job.success' => true,
            'laravel.job.released' => $event->job->isReleased(),
            'laravel.job.deleted' => $event->job->isDeleted(),
        ]);
    }

    public function recordExceptionOccurred(JobExceptionOccurred $event): ?Span
    {
        $span = $this->recordFailed($event->exception, [
            'laravel.job.success' => false,
            'laravel.job.released' => $event->job->isReleased(),
            'laravel.job.deleted' => $event->job->isDeleted(),
        ]);

        // dispatchAfterResponse runs in the main HTTP lifecycle (Terminating stage). When the
        // job throws, Laravel renders the error and never reaches `terminated()`, so we force it
        // here to flush the report.
        if ($this->lifecycle->getStage() === LifecycleStage::Terminating) {
            $this->lifecycle->terminated();
        }

        return $span;
    }

    /**
     * Laravel fires JobFailed once, when a job is finally failed: its tries are used up, it
     * timed out for good, its model could not be restored, or it called fail(). The exception
     * handler does not see every one of these. It drops the classes Laravel never reports, such
     * as ModelNotFoundException, and what the application lists as not to report, and it is
     * never called for a timeout or for a job that failed itself without throwing.
     *
     * The failure is only remembered here. The worker hands the exception to the handler after
     * this event, and when the handler reports it, nothing more is needed.
     */
    public function rememberFailed(JobFailed $event): void
    {
        if (! $this->reportFailed || app(TelltaleConfig::class)->apiToken === null) {
            return;
        }

        foreach ($this->dontReportFailed as $ignoredException) {
            if ($event->exception instanceof $ignoredException) {
                return;
            }
        }

        if ($this->shouldIgnoreJob($event->job->resolveName(), $event->job->payload()['data']['commandName'] ?? null)) {
            return;
        }

        FailedJobReports::remember(
            $event->exception,
            $this->lifecycle->usesSubtasks ? $this->entryPointResolver->get() : null,
            $this->stack === [] ? null : $this->stack[array_key_last($this->stack)],
        );
    }

    /**
     * Report the failures the exception handler left unreported. Called once the worker has
     * moved on from the failed job: before it reserves the next one, when a timeout is about to
     * kill it, and when the application terminates.
     *
     * The job's span and entry point go on the report the same way they do for a failure the
     * handler reports. They are usually still set from the job's last exception. When the job
     * ended another way, the ones kept when it failed are used.
     */
    public function reportUnreportedFailures(): void
    {
        foreach (FailedJobReports::pull() as $failure) {
            if (AddJobInformation::$latestJob === null && $failure['span'] !== null) {
                AddJobInformation::setLatestJob($failure['span']);

                if ($failure['entryPoint'] !== null) {
                    AddJobInformation::setEntryPoint($failure['entryPoint']);
                }
            }

            app(Flare::class)->report($failure['exception']);
        }
    }

    public function recordTimedOut(JobTimedOut $event): ?Span
    {
        $timeout = $this->resolveTimeout($event);

        $span = $this->endSpan(
            additionalAttributes: array_filter([
                'laravel.job.success' => false,
                'laravel.job.timed_out' => true,
                'laravel.job.timeout' => $timeout,
                'laravel.job.released' => $event->job->isReleased(),
                'laravel.job.deleted' => $event->job->isDeleted(),
            ], fn (mixed $value) => $value !== null),
            spanCallback: fn (Span $span) => $span->setStatus(
                SpanStatusCode::Error,
                $timeout === null
                    ? 'Job timed out'
                    : "Job timed out after {$timeout} seconds",
            ),
            includeMemoryUsage: true,
        );

        // The worker kills the process right after dispatching this event, so this is the last
        // chance to close the span, report a failure the timeout caused, and flush the trace.
        $this->reportUnreportedFailures();

        if ($this->lifecycle->usesSubtasks) {
            $this->lifecycle->endSubtask();
        }

        return $span;
    }

    /**
     * A worker that receives a termination signal while running an Interruptible job hands the
     * signal to the job and lets it finish, so the job still ends through JobProcessed or
     * JobExceptionOccurred. Only the signal is recorded here, the span stays open.
     */
    public function recordInterrupted(JobInterrupted $event): ?SpanEvent
    {
        $span = $this->stack === []
            ? null
            : $this->stack[array_key_last($this->stack)];

        if ($span === null) {
            return null;
        }

        if (count($span->events) >= $this->tracer->limits['max_span_events_per_span']) {
            $span->droppedEventsCount++;

            return null;
        }

        $spanEvent = new SpanEvent(
            name: 'Job interrupted',
            timestamp: $this->tracer->time->getCurrentTime(),
            attributes: [
                'flare.span_event_type' => SpanEventType::Custom,
                'laravel.job.interrupted' => true,
                'laravel.job.signal' => $event->signal,
            ],
        );

        $span->addEvent($spanEvent);

        return $spanEvent;
    }

    /**
     * The worker dispatches Looping before it reserves the next job. When the lifecycle is still
     * in the subtask stage at that point, the previous job ended without JobProcessed or
     * JobExceptionOccurred, for example because deleting the failed job threw. The subtask is
     * ended here so its trace and the reports queued since then are sent, and the next job
     * starts with a clean recorder.
     */
    public function endLeftoverSubtask(): void
    {
        if (! $this->lifecycle->usesSubtasks) {
            return;
        }

        if ($this->lifecycle->getStage() !== LifecycleStage::Subtask) {
            return;
        }

        $this->stack = [];

        $this->lifecycle->endSubtask();
    }

    /**
     * The timeout was only added to the event in Laravel 13, reading it through
     * get_object_vars keeps this working on older versions.
     */
    protected function resolveTimeout(JobTimedOut $event): ?int
    {
        $timeout = get_object_vars($event)['timeout'] ?? null;

        return is_numeric($timeout) ? (int) $timeout : null;
    }

    /** @return array<int, class-string> */
    protected function defaultIgnoredJobClasses(): array
    {
        return self::INTERNAL_IGNORED_JOBS;
    }
}
