<?php

namespace Apxcde\LaravelTelltale\Recorders\CommandRecorder;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Events\Dispatcher;
use Spatie\FlareClient\EntryPoint\EntryPointResolver;
use Spatie\FlareClient\Recorders\CommandRecorder\CommandRecorder as BaseCommandRecorder;
use Spatie\FlareClient\Support\BackTracer;
use Spatie\FlareClient\Tracer;
use Apxcde\LaravelTelltale\AttributesProviders\LaravelCommandAttributesProvider;

class CommandRecorder extends BaseCommandRecorder
{
    public function __construct(
        Tracer $tracer,
        BackTracer $backTracer,
        EntryPointResolver $entryPointResolver,
        protected Dispatcher $dispatcher,
        array $config,
    ) {
        parent::__construct($tracer, $backTracer, $entryPointResolver, $config);
    }

    public function boot(): void
    {
        $this->dispatcher->listen(CommandStarting::class, [$this, 'recordCommandStarting']);
        $this->dispatcher->listen(CommandFinished::class, [$this, 'recordCommandFinished']);
    }

    public function recordCommandStarting(CommandStarting $event): void
    {
        if ($event->command === null) {
            return;
        }

        $this->recordStart(
            new LaravelCommandAttributesProvider($event->input, $event->command),
        );
    }

    public function recordCommandFinished(CommandFinished $event): void
    {
        $this->recordEnd($event->exitCode);
    }

    /**
     * The commands that are never traced. Most of them run until they are
     * stopped, so a trace of one would measure how long the process lived.
     *
     * @return array<int, string>
     */
    protected function defaultIgnoredCommands(): array
    {
        return [
            'list',
            'queue:work',
            'queue:listen',
            'horizon',
            'horizon:supervisor',
            'horizon:work',
            'schedule:work',
            'reverb:start',
            'pulse:check',
            'pulse:work',
            'octane:start',
            'octane:reload',
            'vapor:work',
            'serve',
            'telltale:test',
        ];
    }
}
