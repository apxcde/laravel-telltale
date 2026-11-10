<?php

namespace Apxcde\LaravelTelltale\Commands;

use Illuminate\Console\Command;
use Apxcde\LaravelTelltale\Support\LaravelTester;

class TestCommand extends Command
{
    protected $signature = 'telltale:test {--errors} {--logs} {--traces}';

    protected $description = 'Send a test error, trace and log to Telltale';

    public function handle(): int
    {
        $tester = app(LaravelTester::class, [
            'input' => $this->input,
            'output' => $this->output,
        ]);

        return $tester->run($this->output->isDebug()) ? Command::SUCCESS : Command::FAILURE;
    }
}
