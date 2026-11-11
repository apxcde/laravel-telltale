<?php

namespace Apxcde\LaravelTelltale\ArgumentReducers;

use Apxcde\LaravelTelltale\Support\UrlCensor;
use Spatie\Backtrace\Arguments\ReducedArgument\ReducedArgument;
use Spatie\Backtrace\Arguments\ReducedArgument\ReducedArgumentContract;
use Spatie\Backtrace\Arguments\ReducedArgument\UnReducedArgument;
use Spatie\Backtrace\Arguments\Reducers\ArgumentReducer;
use Spatie\FlareClient\Support\Redactor;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

/**
 * Describes a request passed as a stack frame argument by its method and
 * URL, like the reducer it replaces, with the query string censored by the
 * `censor.body_fields` rules. Almost every frame of a web request has the
 * request as an argument, so an uncensored URL here is on every report.
 */
class RequestArgumentReducer implements ArgumentReducer
{
    public function execute(mixed $argument): ReducedArgumentContract
    {
        if (! $argument instanceof Request) {
            return UnReducedArgument::create();
        }

        return new ReducedArgument(
            "{$argument->getMethod()} {$this->censoredUrl($argument)}",
            get_class($argument),
        );
    }

    protected function censoredUrl(Request $request): string
    {
        try {
            return (new UrlCensor(app(Redactor::class)))->url($request->getUri());
        } catch (Throwable) {
            // Without a redactor the rules are unknown, so no part of the
            // query string is sent.
            return $request->getSchemeAndHttpHost().$request->getBaseUrl().$request->getPathInfo();
        }
    }
}
