<?php

namespace Apxcde\LaravelTelltale\Support;

use Livewire\Livewire;
use Spatie\FlareClient\Contracts\FlareCollectType;
use Spatie\FlareClient\Enums\CollectType;
use Spatie\FlareClient\FlareMiddleware\AddJobInformation;
use Spatie\FlareClient\Recorders\ResponseRecorder\ResponseRecorder;
use Spatie\FlareClient\Support\CollectsResolver as BaseCollectsResolver;
use Apxcde\LaravelTelltale\Enums\LaravelCollectType;
use Apxcde\LaravelTelltale\FlareMiddleware\AddConsoleInformation;
use Apxcde\LaravelTelltale\FlareMiddleware\AddExceptionContextInformation;
use Apxcde\LaravelTelltale\FlareMiddleware\AddExceptionHandledStatus;
use Apxcde\LaravelTelltale\FlareMiddleware\AddLaravelInformation;
use Apxcde\LaravelTelltale\FlareMiddleware\AddRequestInformation;
use Apxcde\LaravelTelltale\FlareMiddleware\AddViewInformation;
use Apxcde\LaravelTelltale\Recorders\CacheRecorder\CacheRecorder;
use Apxcde\LaravelTelltale\Recorders\CommandRecorder\CommandRecorder;
use Apxcde\LaravelTelltale\Recorders\ContextRecorder\ContextRecorder;
use Apxcde\LaravelTelltale\Recorders\ExternalHttpRecorder\ExternalHttpRecorder;
use Apxcde\LaravelTelltale\Recorders\FilesystemRecorder\FilesystemRecorder;
use Apxcde\LaravelTelltale\Recorders\JobRecorder\JobRecorder;
use Apxcde\LaravelTelltale\Recorders\LivewireRecorder\LivewireRecorder;
use Apxcde\LaravelTelltale\Recorders\QueryRecorder\QueryRecorder;
use Apxcde\LaravelTelltale\Recorders\QueueRecorder\QueueRecorder;
use Apxcde\LaravelTelltale\Recorders\RedisCommandRecorder\RedisCommandRecorder;
use Apxcde\LaravelTelltale\Recorders\RequestRecorder\RequestRecorder;
use Apxcde\LaravelTelltale\Recorders\RoutingRecorder\RoutingRecorder;
use Apxcde\LaravelTelltale\Recorders\TransactionRecorder\TransactionRecorder;
use Apxcde\LaravelTelltale\Recorders\ViewRecorder\ViewRecorder;

class CollectsResolver extends BaseCollectsResolver
{
    protected ?string $requestsMiddlewareClass = null;

    protected function handleUnknownCollectType(FlareCollectType $type, array $options): void
    {
        match ($type) {
            LaravelCollectType::LaravelContext => $this->laravelContext($options),
            LaravelCollectType::LivewireComponents => $this->livewireComponents($options),
            LaravelCollectType::LaravelInfo => $this->laravelInfo($options),
            LaravelCollectType::ExceptionContext => $this->exceptionContext($options),
            LaravelCollectType::HandledExceptions => $this->handledExceptions($options),
            CollectType::Jobs => $this->jobs($options),
            default => null,
        };
    }

    protected function context(array $options): void
    {
        $this->laravelContext([
            'include_laravel_context' => false, // in case only context is being collected
        ]);
    }

    protected function laravelContext(array $options): void
    {
        $this->addRecorder(
            ContextRecorder::class,
            [
                'include_laravel_context' => $options['include_laravel_context'] ?? ContextRecorder::DEFAULT_INCLUDE_LARAVEL_CONTEXT,
            ]
        );
    }

    protected function requests(array $options): void
    {
        $middleware = $options['middleware'] ?? AddRequestInformation::class;

        $this->requestsMiddlewareClass = $middleware;
        $this->addMiddleware($middleware);

        $this->addRecorder(
            RequestRecorder::class,
            $this->only($options, ['group_unmatched_route_errors', 'ignored_urls', 'ignored_paths']),
        );
        $this->addRecorder(
            RoutingRecorder::class,
            $this->only($options, ['ignored_routes']),
        );
        $this->addRecorder(ResponseRecorder::class);
    }

    protected function console(array $options): void
    {
        $options['middleware'] ??= AddConsoleInformation::class;
        $options['recorder'] ??= CommandRecorder::class;

        parent::console($options);
    }

    protected function laravelInfo(array $options): void
    {
        $this->addMiddleware(AddLaravelInformation::class, $options);
    }

    protected function exceptionContext(array $options): void
    {
        $this->addMiddleware(AddExceptionContextInformation::class, $options);
    }

    protected function handledExceptions(array $options): void
    {
        $this->addMiddleware(AddExceptionHandledStatus::class, $options);
    }

    protected function jobs(array $options): void
    {
        $this->addRecorder($options['job_recorder'] ?? JobRecorder::class, $this->only($options, [
            'with_traces',
            'with_errors',
            'max_items_with_errors',
            'max_chained_job_reporting_depth',
            'ignored_classes',
        ]));

        $this->addRecorder($options['queue_recorder'] ?? QueueRecorder::class, $this->only($options, [
            'with_traces',
            'with_errors',
            'max_items_with_errors',
            'ignored_classes',
        ]));

        $this->addMiddleware($options['middleware'] ?? AddJobInformation::class);
    }

    protected function livewireComponents(
        array $options
    ): void {
        if ($this->requestsMiddlewareClass === null) {
            return;
        }

        if (! class_exists(Livewire::class)) {
            return;
        }

        $this->middlewares[$this->requestsMiddlewareClass]['include_livewire_components'] = $options['include_livewire_components'] ?? false;
        $this->middlewares[$this->requestsMiddlewareClass]['ignore_livewire_components'] = $options['ignore'] ?? [];

        $this->addRecorder($options['middleware'] ?? LivewireRecorder::class, $this->only($options, [
            'with_traces',
            'ignore',
            'split_by_phase',
        ]));
    }

    protected function views(array $options): void
    {
        $options['recorder'] ??= ViewRecorder::class;

        parent::views($options);

        $this->addMiddleware($options['middleware'] ?? AddViewInformation::class);
    }

    protected function redisCommands(array $options): void
    {
        $options['recorder'] ??= RedisCommandRecorder::class;

        parent::redisCommands($options);
    }

    protected function filesystem(array $options): void
    {
        $options['recorder'] ??= FilesystemRecorder::class;

        parent::filesystem($options);
    }

    protected function cache(array $options): void
    {
        $options['recorder'] ??= CacheRecorder::class;

        parent::cache($options);
    }

    protected function externalHttp(array $options): void
    {
        $options['recorder'] ??= ExternalHttpRecorder::class;

        parent::externalHttp($options);
    }

    protected function queries(array $options): void
    {
        $options['recorder'] ??= QueryRecorder::class;

        parent::queries($options);
    }

    protected function transactions(array $options): void
    {
        $options['recorder'] ??= TransactionRecorder::class;

        parent::transactions($options);
    }
}
