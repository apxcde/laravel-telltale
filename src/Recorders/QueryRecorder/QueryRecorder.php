<?php

namespace Apxcde\LaravelTelltale\Recorders\QueryRecorder;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use Spatie\FlareClient\Recorders\QueryRecorder\QueryRecorder as BaseQueryRecorder;
use Spatie\FlareClient\Spans\Span;
use Spatie\FlareClient\Support\BackTracer;
use Spatie\FlareClient\Time\TimeHelper;
use Spatie\FlareClient\Tracer;

class QueryRecorder extends BaseQueryRecorder
{
    /**
     * Bound values often hold personal data, so an application has to opt in to sending them.
     */
    public const DEFAULT_INCLUDE_BINDINGS = false;

    public function __construct(
        Tracer $tracer,
        BackTracer $backTracer,
        protected Dispatcher $dispatcher,
        array $config,
    ) {
        $config['include_bindings'] ??= self::DEFAULT_INCLUDE_BINDINGS;

        parent::__construct($tracer, $backTracer, $config);
    }

    public function boot(): void
    {
        $this->dispatcher->listen(QueryExecuted::class, [$this, 'recordEvent']);
    }

    public function recordEvent(QueryExecuted $event): ?Span
    {
        return $this->record(
            sql: $event->sql,
            duration: TimeHelper::milliseconds($event->time),
            bindings: $event->bindings,
            databaseName: $event->connection->getDatabaseName(),
            driverName: $event->connection->getDriverName(),
            attributes: [
                'laravel.db.connection' => $event->connectionName,
            ]
        );
    }
}
