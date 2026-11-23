<?php

namespace Apxcde\LaravelTelltale\Tests\stubs\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class EmptyJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function handle(): void
    {
    }
}
