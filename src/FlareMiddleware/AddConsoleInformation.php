<?php

namespace Apxcde\LaravelTelltale\FlareMiddleware;

use Spatie\FlareClient\FlareMiddleware\AddConsoleInformation as BaseAddConsoleInformation;

class AddConsoleInformation extends BaseAddConsoleInformation
{
    protected function isRunningInConsole(): bool
    {
        return app()->runningInConsole();
    }
}
