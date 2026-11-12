<?php

namespace Apxcde\LaravelTelltale;

use BackedEnum;
use Illuminate\Support\Arr;
use Spatie\FlareClient\Contracts\FlareCollectType;
use Spatie\FlareClient\Enums\CollectType;
use Spatie\FlareClient\FlareConfig as BaseFlareConfig;
use Spatie\FlareClient\FlareMiddleware\AddLogs;
use Spatie\FlareClient\Recorders\GlowRecorder\GlowRecorder;
use Spatie\FlareClient\Resources\Resource;
use Spatie\FlareClient\Tracer;
use Apxcde\LaravelTelltale\ArgumentReducers\CollectionArgumentReducer;
use Apxcde\LaravelTelltale\ArgumentReducers\ModelArgumentReducer;
use Apxcde\LaravelTelltale\ArgumentReducers\RequestArgumentReducer;
use Spatie\Backtrace\Arguments\Reducers\SymphonyRequestArgumentReducer;
use Apxcde\LaravelTelltale\ArgumentReducers\ViewArgumentReducer;
use Apxcde\LaravelTelltale\Enums\LaravelCollectType;
use Apxcde\LaravelTelltale\Recorders\CacheRecorder\CacheRecorder;
use Apxcde\LaravelTelltale\Recorders\CommandRecorder\CommandRecorder;
use Apxcde\LaravelTelltale\Recorders\ContextRecorder\ContextRecorder;
use Apxcde\LaravelTelltale\Recorders\ExternalHttpRecorder\ExternalHttpRecorder;
use Apxcde\LaravelTelltale\Recorders\FilesystemRecorder\FilesystemRecorder;
use Apxcde\LaravelTelltale\Recorders\JobRecorder\JobRecorder;
use Apxcde\LaravelTelltale\Recorders\LivewireRecorder\LivewireRecorder;
use Apxcde\LaravelTelltale\Recorders\QueryRecorder\QueryRecorder;
use Apxcde\LaravelTelltale\Recorders\RequestRecorder\RequestRecorder;
use Apxcde\LaravelTelltale\Recorders\TransactionRecorder\TransactionRecorder;
use Apxcde\LaravelTelltale\Recorders\ViewRecorder\ViewRecorder;
use Apxcde\LaravelTelltale\Sampling\SamplingRule;

class TelltaleConfig extends BaseFlareConfig
{
    public bool $enableShareButton = false;

    public static function fromLaravelConfig(): self
    {
        $baseUrl = static::normalizeBaseUrl(config('telltale.base_url'));
        $collects = [];

        foreach (config('telltale.collects') as $type => $options) {
            $collectType = CollectType::tryFrom($type) ?? LaravelCollectType::tryFrom($type) ?? null;

            if ($collectType === null || ! is_array($options)) {
                continue;
            }

            $collects[$collectType->value] = [
                'type' => $collectType,
                'ignored' => null,
                'options' => $options,
            ];
        }

        $samplerConfig = config('telltale.sampler.config') ?? [];

        if (is_array($samplerConfig['rules'] ?? null)) {
            $samplerConfig['rules'] = array_values(array_filter(array_map(
                fn (array $rule) => SamplingRule::fromArray($rule),
                $samplerConfig['rules'],
            )));
        }

        $config = new self(
            apiToken: $baseUrl === null ? null : config('telltale.key'),
            baseUrl: $baseUrl ?? '',
            collects: $collects,
            applicationPath: base_path(),
            applicationName: config('app.name'),
            applicationStage: app()->environment(),
            censorClientIps: config('telltale.censor.client_ips'),
            censorHeaders: config('telltale.censor.headers'),
            censorBodyFields: config('telltale.censor.body_fields'),
            censorCookies: config('telltale.censor.cookies', true),
            censorSession: config('telltale.censor.session', true),
            censorCookieNames: array_values(array_filter([
                config('session.cookie'),
                ...Arr::wrap(config('telltale.censor.cookie_names', ['remember_*'])),
            ])),
            report: config('telltale.report'),
            reportErrorLevels: config('telltale.report_error_levels'),
            overriddenGroupings: config('telltale.overridden_groupings'),
            trace: config('telltale.trace'),
            traceLimits: config('telltale.trace_limits'),
            log: config('telltale.log', true),
            minimalLogLevel: config('telltale.minimal_log_level'),
            sender: config('telltale.sender.class'),
            senderConfig: config('telltale.sender.config', []),
            sampler: config('telltale.sampler.class'),
            samplerConfig: $samplerConfig,
        );

        $config->enableShareButton = config('telltale.enable_share_button', false);

        return $config;
    }

    /**
     * Telltale has no hosted default to fall back on, so without a base URL
     * the client is disabled and nothing is sent anywhere.
     */
    protected static function normalizeBaseUrl(mixed $baseUrl): ?string
    {
        if (! is_string($baseUrl)) {
            return null;
        }

        $baseUrl = rtrim(trim($baseUrl), '/');

        return $baseUrl === '' ? null : $baseUrl;
    }

    /**
     * @param array<FlareCollectType> $ignore ,
     * @param array<string, array<string, mixed>> $extra
     */
    public static function defaultCollects(
        array $ignore = [],
        array $extra = []
    ): array {
        $collects = [
            CollectType::Requests->value => [
                'group_unmatched_route_errors' => RequestRecorder::DEFAULT_GROUP_UNMATCHED_ROUTE_ERRORS,
            ],
            CollectType::ErrorsWithTraces->value => [
                'with_traces' => Tracer::DEFAULT_COLLECT_ERRORS_WITH_TRACES,
            ],
            LaravelCollectType::LivewireComponents->value => [
                'with_traces' => LivewireRecorder::DEFAULT_WITH_TRACES,
                'with_errors' => LivewireRecorder::DEFAULT_WITH_ERRORS,
                'max_items_with_errors' => LivewireRecorder::DEFAULT_MAX_ITEMS_WITH_ERRORS,
                'ignored' => LivewireRecorder::DEFAULT_IGNORED,
                'split_by_phase' => LivewireRecorder::DEFAULT_SPLIT_BY_PHASE,
            ],
            CollectType::ServerInfo->value => [
                'host' => Resource::DEFAULT_HOST_ENTITY_TYPES,
                'php' => Resource::DEFAULT_PHP_ENTITY_TYPES,
                'os' => Resource::DEFAULT_OS_ENTITY_TYPES,
                'composer' => false,
                'composer_packages' => Resource::DEFAULT_COMPOSER_PACKAGES_ENTITY_TYPES,
            ],
            CollectType::GitInfo->value => [
                'use_process' => Resource::DEFAULT_GIT_USE_PROCESS,
                'entity_types' => Resource::DEFAULT_GIT_ENTITY_TYPES,
            ],
            CollectType::Context->value => [],
            LaravelCollectType::LaravelInfo->value => [],
            LaravelCollectType::LaravelContext->value => [
                'include_laravel_context' => ContextRecorder::DEFAULT_INCLUDE_LARAVEL_CONTEXT,
            ],
            LaravelCollectType::ExceptionContext->value => [],
            LaravelCollectType::HandledExceptions->value => [],
            CollectType::Commands->value => [
                'with_traces' => CommandRecorder::DEFAULT_WITH_TRACES,
                'with_errors' => CommandRecorder::DEFAULT_WITH_ERRORS,
                'max_items_with_errors' => CommandRecorder::DEFAULT_MAX_ITEMS_WITH_ERRORS,
            ],
            CollectType::Jobs->value => [
                'with_traces' => JobRecorder::DEFAULT_WITH_TRACES,
                'with_errors' => JobRecorder::DEFAULT_WITH_ERRORS,
                'max_items_with_errors' => JobRecorder::DEFAULT_MAX_ITEMS_WITH_ERRORS,
                'max_chained_job_reporting_depth' => JobRecorder::DEFAULT_MAX_CHAINED_JOB_REPORTING_DEPTH,
                'ignored_classes' => [],
            ],
            CollectType::Cache->value => [
                'with_traces' => CacheRecorder::DEFAULT_WITH_TRACES,
                'with_errors' => CacheRecorder::DEFAULT_WITH_ERRORS,
                'max_items_with_errors' => CacheRecorder::DEFAULT_MAX_ITEMS_WITH_ERRORS,
                'operations' => CacheRecorder::DEFAULT_OPERATIONS,
                'ignored_keys' => [],
            ],
            CollectType::LogsWithErrors->value => [
                'max_items' => AddLogs::DEFAULT_MAX_LOGS_WITH_ERRORS,
                'minimal_level' => AddLogs::DEFAULT_MINIMAL_LOG_LEVEL_WITH_ERRORS,
            ],
            CollectType::Queries->value => [
                'with_traces' => QueryRecorder::DEFAULT_WITH_TRACES,
                'with_errors' => QueryRecorder::DEFAULT_WITH_ERRORS,
                'max_items_with_errors' => QueryRecorder::DEFAULT_MAX_ITEMS_WITH_ERRORS,
                'include_bindings' => QueryRecorder::DEFAULT_INCLUDE_BINDINGS,
                'find_origin' => QueryRecorder::DEFAULT_FIND_ORIGIN,
                'find_origin_threshold' => QueryRecorder::DEFAULT_FIND_ORIGIN_THRESHOLD,
            ],
            CollectType::Transactions->value => [
                'with_traces' => TransactionRecorder::DEFAULT_WITH_TRACES,
                'with_errors' => TransactionRecorder::DEFAULT_WITH_ERRORS,
                'max_items_with_errors' => TransactionRecorder::DEFAULT_MAX_ITEMS_WITH_ERRORS,
            ],
            CollectType::Views->value => [
                'with_traces' => ViewRecorder::DEFAULT_WITH_TRACES,
                'with_errors' => ViewRecorder::DEFAULT_WITH_ERRORS,
                'max_items_with_errors' => ViewRecorder::DEFAULT_MAX_ITEMS_WITH_ERRORS,
            ],
            CollectType::Filesystem->value => [
                'with_traces' => FilesystemRecorder::DEFAULT_WITH_TRACES,
                'with_errors' => FilesystemRecorder::DEFAULT_WITH_ERRORS,
                'max_items_with_errors' => FilesystemRecorder::DEFAULT_MAX_ITEMS_WITH_ERRORS,
                'track_all_disks' => FilesystemRecorder::DEFAULT_TRACK_ALL_DISKS,
            ],
            CollectType::ExternalHttp->value => [
                'with_traces' => ExternalHttpRecorder::DEFAULT_WITH_TRACES,
                'with_errors' => ExternalHttpRecorder::DEFAULT_WITH_ERRORS,
                'max_items_with_errors' => ExternalHttpRecorder::DEFAULT_MAX_ITEMS_WITH_ERRORS,
            ],
            CollectType::Glows->value => [
                'with_traces' => GlowRecorder::DEFAULT_WITH_TRACES,
                'with_errors' => GlowRecorder::DEFAULT_WITH_ERRORS,
                'max_items_with_errors' => GlowRecorder::DEFAULT_MAX_ITEMS_WITH_ERRORS,
            ],
            CollectType::StackFrameArguments->value => [
                'argument_reducers' => static::defaultArgumentReducers(),
                'force_php_ini_setting' => true,
            ],
            CollectType::Recorders->value => [
                'recorders' => [],
            ],
            CollectType::FlareMiddleware->value => [
                'flare_middleware' => [],
            ],
        ];

        foreach ($extra as $collect => $options) {
            $collects[$collect] = array_merge(
                $collects[$collect] ?? [],
                $options
            );
        }

        foreach ($ignore as $ignored) {
            if (! $ignored instanceof BackedEnum) {
                continue;
            }

            if (! array_key_exists($ignored->value, $collects)) {
                continue;
            }

            unset($collects[$ignored->value]);
        }

        return $collects;
    }


    public static function defaultArgumentReducers(): array
    {
        $reducers = array_map(
            fn (mixed $reducer) => $reducer === SymphonyRequestArgumentReducer::class
                ? RequestArgumentReducer::class
                : $reducer,
            parent::defaultArgumentReducers(),
        );

        return [
            ...$reducers,
            ModelArgumentReducer::class,
            CollectionArgumentReducer::class,
            ViewArgumentReducer::class,
        ];
    }

    public function useDefaults(): static
    {
        return parent::useDefaults()
            ->collectLivewireComponents()
            ->collectLaravelInfo()
            ->collectLaravelContext()
            ->collectExceptionContext()
            ->collectLaravelJobs()
            ->collectHandledExceptions();
    }

    public function collectLivewireComponents(
        bool $withTraces = LivewireRecorder::DEFAULT_WITH_TRACES,
        bool $withErrors = LivewireRecorder::DEFAULT_WITH_ERRORS,
        ?int $maxItemsWithErrors = LivewireRecorder::DEFAULT_MAX_ITEMS_WITH_ERRORS,
        array $ignored = LivewireRecorder::DEFAULT_IGNORED,
        bool $splitByPhase = LivewireRecorder::DEFAULT_SPLIT_BY_PHASE,
        array $extra = []
    ): static {
        return $this->addCollect(LaravelCollectType::LivewireComponents, [
            'with_traces' => $withTraces,
            'with_errors' => $withErrors,
            'max_items_with_errors' => $maxItemsWithErrors,
            'ignored' => $ignored,
            'split_by_phase' => $splitByPhase,
            ...$extra,
        ]);
    }

    public function ignoreLivewireComponents(array $extra = []): static
    {
        return $this->addCollect(LaravelCollectType::LivewireComponents, [
            'include_livewire_components' => false,
            ...$extra,
        ]); // Explicitly ignore livewire components
    }

    public function collectLaravelInfo(array $extra = []): static
    {
        return $this->addCollect(LaravelCollectType::LaravelInfo, $extra);
    }

    public function ignoreLaravelInfo(): static
    {
        return $this->ignoreCollect(LaravelCollectType::LaravelInfo);
    }

    public function collectLaravelContext(array $extra = []): static
    {
        return $this->addCollect(LaravelCollectType::LaravelContext, $extra);
    }

    public function ignoreLaravelContext(): static
    {
        return $this->ignoreCollect(LaravelCollectType::LaravelContext);
    }

    public function collectExceptionContext(array $extra = []): static
    {
        return $this->addCollect(LaravelCollectType::ExceptionContext, $extra);
    }

    public function ignoreExceptionContext(): static
    {
        return $this->ignoreCollect(LaravelCollectType::ExceptionContext);
    }

    public function collectHandledExceptions(array $extra = []): static
    {
        return $this->addCollect(LaravelCollectType::HandledExceptions, $extra);
    }

    public function ignoreHandledExceptions(): static
    {
        return $this->ignoreCollect(LaravelCollectType::HandledExceptions);
    }

    /**
     * @param array<int, class-string> $ignoredClasses
     */
    public function collectLaravelJobs(
        bool $withTraces = JobRecorder::DEFAULT_WITH_TRACES,
        bool $withErrors = JobRecorder::DEFAULT_WITH_ERRORS,
        ?int $maxItemsWithErrors = JobRecorder::DEFAULT_MAX_ITEMS_WITH_ERRORS,
        int $maxChainedJobReportingDepth = JobRecorder::DEFAULT_MAX_CHAINED_JOB_REPORTING_DEPTH,
        array $ignoredClasses = [],
        array $extra = [],
    ): static {
        return $this->collectJobs(
            withTraces: $withTraces,
            withErrors: $withErrors,
            maxItemsWithErrors: $maxItemsWithErrors,
            ignoredClasses: $ignoredClasses,
            extra: ['max_chained_job_reporting_depth' => $maxChainedJobReportingDepth, ...$extra],
        );
    }

    public function collectQueries(
        bool $withTraces = QueryRecorder::DEFAULT_WITH_TRACES,
        bool $withErrors = QueryRecorder::DEFAULT_WITH_ERRORS,
        ?int $maxItemsWithErrors = QueryRecorder::DEFAULT_MAX_ITEMS_WITH_ERRORS,
        bool $includeBindings = QueryRecorder::DEFAULT_INCLUDE_BINDINGS,
        bool $findOrigin = QueryRecorder::DEFAULT_FIND_ORIGIN,
        ?int $findOriginThreshold = QueryRecorder::DEFAULT_FIND_ORIGIN_THRESHOLD,
        array $extra = [],
    ): static {
        return parent::collectQueries(
            withTraces: $withTraces,
            withErrors: $withErrors,
            maxItemsWithErrors: $maxItemsWithErrors,
            includeBindings: $includeBindings,
            findOrigin: $findOrigin,
            findOriginThreshold: $findOriginThreshold,
            extra: $extra,
        );
    }

    public function collectFilesystemOperations(
        bool $withTraces = FilesystemRecorder::DEFAULT_WITH_TRACES,
        bool $withErrors = FilesystemRecorder::DEFAULT_WITH_ERRORS,
        ?int $maxItemsWithErrors = FilesystemRecorder::DEFAULT_MAX_ITEMS_WITH_ERRORS,
        array $extra = [
            'track_all_disks' => FilesystemRecorder::DEFAULT_TRACK_ALL_DISKS,
        ]
    ): static {
        return parent::collectFilesystemOperations(...func_get_args());
    }
}
