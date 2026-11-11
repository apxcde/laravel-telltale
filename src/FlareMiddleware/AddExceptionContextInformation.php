<?php

namespace Apxcde\LaravelTelltale\FlareMiddleware;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\FlareClient\Contracts\ProvidesFlareContext;
use Spatie\FlareClient\FlareMiddleware\FlareMiddleware;
use Spatie\FlareClient\ReportFactory;
use Spatie\FlareClient\Support\Redactor;
use Throwable;

class AddExceptionContextInformation implements FlareMiddleware
{
    /**
     * @param array{include_query_bindings?: bool} $config
     */
    public function __construct(
        protected array $config = [],
    ) {
    }

    public function handle(ReportFactory $report, Closure $next): ReportFactory
    {
        $this->addUserDefinedContext($report);

        if (! $report->throwable instanceof QueryException) {
            return $next($report);
        }

        $report->addAttribute(
            'flare.exception.db_statement',
            $report->throwable->getSql(),
        );

        try {
            $report->addAttribute(
                'flare.exception.db_system',
                DB::connection($report->throwable->connectionName)->getDriverName(),
            );
        } catch (Throwable) {
            // Skip this
        }

        $this->rewriteQueryExceptionMessage($report);

        return $next($report);
    }

    private function rewriteQueryExceptionMessage(ReportFactory $report): void
    {
        if ($report->message === null) {
            return;
        }

        if (! preg_match('/^(.*, SQL: ).+\)$/s', $report->message, $matches)) {
            return;
        }

        /** @var QueryException $throwable */
        $throwable = $report->throwable;

        if (! ($this->config['include_query_bindings'] ?? false)) {
            // Laravel puts the bound values in the message, so they are taken out again.
            $report->message = $matches[1].$throwable->getSql().')';

            return;
        }

        $bindings = array_map(fn ($value) => match (true) {
            $value === null => 'NULL',
            is_bool($value) => $value ? '1' : '0',
            is_int($value), is_float($value) => (string) $value,
            default => "'".str_replace("'", "''", (string) $value)."'",
        }, $throwable->getBindings());

        $sql = Str::replaceArray('?', $bindings, $throwable->getSql());

        $report->message = $matches[1].$sql.')';
    }

    private function addUserDefinedContext(
        ReportFactory $report,
    ): void {
        if ($report->throwable instanceof ProvidesFlareContext) {
            // ProvidesFlareContext writes directly to context groups and is handled in the flare-client-php package.
            return;
        }

        if (! method_exists($report->throwable, 'context')) {
            return;
        }

        $context = $report->throwable->context();

        if (! is_array($context)) {
            return;
        }

        $report->addAttribute('context.exception', $this->censor($context));
    }

    /**
     * Censor the context with the same rules as the request body
     * (`censor.body_fields`), so a value hidden in the request is not sent
     * readable in the exception's own context. If the redactor cannot be
     * resolved, the context is left out.
     *
     * @param array<array-key, mixed> $context
     *
     * @return array<array-key, mixed>
     */
    private function censor(array $context): array
    {
        try {
            return app(Redactor::class)->censorBody($context);
        } catch (Throwable) {
            return [];
        }
    }
}
