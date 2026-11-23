<?php

namespace Apxcde\LaravelTelltale\Facades;

use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Support\Facades\Facade;
use Spatie\FlareClient\Flare as FlareClient;
use Spatie\FlareClient\Recorders\GlowRecorder\GlowRecorder;
use Spatie\FlareClient\Recorders\ResponseRecorder\ResponseRecorder;
use Spatie\FlareClient\Tracer;
use Apxcde\LaravelTelltale\TelltaleConfig;
use Apxcde\LaravelTelltale\Recorders\FilesystemRecorder\FilesystemRecorder;
use Apxcde\LaravelTelltale\Recorders\RequestRecorder\RequestRecorder;
use Apxcde\LaravelTelltale\Recorders\RoutingRecorder\RoutingRecorder;
use Apxcde\LaravelTelltale\Support\FailedJobReports;
use Throwable;

/**
 * @method static FlareClient context(string|mixed[] $key, mixed $value = null)
 * @method static FlareClient withApplicationVersion(string|Closure $version)
 * @method static FlareClient filterExceptionsUsing(Closure $filterExceptionsCallable)
 * @method static FlareClient filterReportsUsing(Closure $filterReportsCallable)
 * @method static GlowRecorder|null glow()
 * @method static FilesystemRecorder|null filesystem()
 * @method static RoutingRecorder|null routing()
 * @method static ResponseRecorder|null response()
 * @method static RequestRecorder|null request()
 * @method static Tracer tracer()
 * @see \Spatie\FlareClient\Flare
 */
class Telltale extends Facade
{
    protected static function getFacadeAccessor()
    {
        return FlareClient::class;
    }

    /**
     * @param Exceptions $exceptions
     *
     * @return void
     */
    public static function handles(?Exceptions $exceptions = null): void
    {
        $reportable = static function (Throwable $exception): ?FlareClient {
            $config = app(TelltaleConfig::class);

            if ($config->apiToken === null) {
                return null;
            }

            // The handler reports it, so it is no longer a failed job waiting to be reported.
            FailedJobReports::forget($exception);

            $flare = app(FlareClient::class);

            $flare->report($exception);

            return $flare;
        };

        if ($exceptions) {
            $exceptions->reportable($reportable);

            return;
        }

        $handler = app(ExceptionHandler::class);

        if (method_exists($handler, 'reportable')) {
            $handler->reportable($reportable);
        }
    }

    /**
     * Run a piece of code without tracing it: its queries, cache calls, outgoing requests and
     * the like get no spans. The trace it runs in goes on as before once the code returns.
     *
     * Log records written and exceptions reported inside are still sent. An error reported
     * later in the same request, job or command still lists what ran inside among its recent
     * queries and calls, because those are kept apart from the trace.
     *
     * @template T
     *
     * @param Closure():T $callback
     *
     * @return T
     */
    public static function withoutTracing(Closure $callback): mixed
    {
        $tracer = app(Tracer::class);

        // Not traced, or already paused by whoever called this: nothing to pause or to resume.
        if (! $tracer->isSampling()) {
            return $callback();
        }

        // Not pauseSampling(): the recorders pause and resume the tracer for an ignored job or
        // command, and their resume inside the callback would turn tracing back on.
        $traceId = $tracer->currentTraceId();

        $tracer->sampling = false;

        try {
            return $callback();
        } finally {
            // dontSample() inside the callback drops the trace; leave tracing off then.
            if ($tracer->currentTraceId() === $traceId) {
                $tracer->sampling = true;
            }
        }
    }

    /**
     * Stop tracing the request, job or command that is running and drop what was traced of
     * it so far. Errors and log records are still sent.
     */
    public static function dontSample(): void
    {
        app(Tracer::class)->unsample();
    }
}
