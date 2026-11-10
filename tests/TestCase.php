<?php

namespace Apxcde\LaravelTelltale\Tests;

use Illuminate\Foundation\Testing\Concerns\MakesHttpRequests;
use Apxcde\LaravelTelltale\Facades\Telltale;

class TestCase extends \Orchestra\Testbench\TestCase
{
    use MakesHttpRequests;
    protected function getPackageProviders($app)
    {
        config()->set('telltale.key', 'dummy-key');

        return [];
    }

    protected function getPackageAliases($app)
    {
        return [
            'Telltale' => Telltale::class,
        ];
    }
}
