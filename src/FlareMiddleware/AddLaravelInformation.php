<?php

namespace Apxcde\LaravelTelltale\FlareMiddleware;

use Closure;
use Spatie\FlareClient\FlareMiddleware\FlareMiddleware;
use Spatie\FlareClient\ReportFactory;
use Apxcde\LaravelTelltale\AttributesProviders\LaravelAttributesProvider;

class AddLaravelInformation implements FlareMiddleware
{
    public function handle(ReportFactory $report, Closure $next): ReportFactory
    {
        $report->addAttributes(
            (new LaravelAttributesProvider())->toArray()
        );

        return $next($report);
    }
}
