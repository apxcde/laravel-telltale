<?php

namespace Apxcde\LaravelTelltale\Support;

use Spatie\FlareClient\EntryPoint\EntryPoint;
use Spatie\FlareClient\Spans\Span;
use Throwable;

/**
 * Holds the exceptions jobs have finally failed with until it is known whether the exception
 * handler reports them.
 *
 * The worker fires JobFailed first and hands the exception to the handler afterwards. A
 * failure the handler reports is taken out again. Whatever is still here once the worker moves
 * on was dropped by the handler, or never given to it, and is reported then.
 *
 * @phpstan-type Failure array{exception: Throwable, entryPoint: EntryPoint|null, span: Span|null}
 */
class FailedJobReports
{
    /** @var array<int, Failure> */
    protected static array $pending = [];

    public static function remember(Throwable $exception, ?EntryPoint $entryPoint = null, ?Span $span = null): void
    {
        self::$pending[spl_object_id($exception)] = [
            'exception' => $exception,
            'entryPoint' => $entryPoint,
            'span' => $span,
        ];
    }

    public static function forget(Throwable $exception): void
    {
        unset(self::$pending[spl_object_id($exception)]);
    }

    /**
     * Take out every failure still waiting.
     *
     * @return array<int, Failure>
     */
    public static function pull(): array
    {
        $pending = array_values(self::$pending);

        self::$pending = [];

        return $pending;
    }

    public static function forgetAll(): void
    {
        self::$pending = [];
    }
}
