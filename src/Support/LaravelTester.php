<?php

namespace Apxcde\LaravelTelltale\Support;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Exceptions\ReportableHandler;
use Illuminate\Support\Str;
use Laravel\SerializableClosure\Support\ReflectionClosure;
use ReflectionException;
use ReflectionNamedType;
use ReflectionProperty;
use Spatie\FlareClient\Api;
use Spatie\FlareClient\EntryPoint\EntryPoint;
use Spatie\FlareClient\Enums\EntryPointType;
use Spatie\FlareClient\Enums\FlareEntityType;
use Spatie\FlareClient\Flare;
use Spatie\FlareClient\FlareConfig;
use Spatie\FlareClient\Memory\Memory;
use Spatie\FlareClient\ReportFactory;
use Spatie\FlareClient\Resources\Resource;
use Spatie\FlareClient\Senders\DaemonSender;
use Spatie\FlareClient\Senders\Exceptions\BadResponseCode;
use Spatie\FlareClient\Support\Ids;
use Spatie\FlareClient\Support\SymfonyTester;
use Spatie\FlareClient\Time\Time;
use Exception;
use Throwable;
use Apxcde\LaravelTelltale\Commands\TestCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class LaravelTester extends SymfonyTester
{
    public function __construct(
        Api $api,
        Ids $ids,
        Time $time,
        Memory $memory,
        Resource $resource,
        ReportFactory $reportFactory,
        FlareConfig $config,
        InputInterface $input,
        OutputInterface $output,
        protected Repository $repository,
        protected Application $app,
    ) {
        parent::__construct(
            api: $api,
            ids: $ids,
            time: $time,
            memory: $memory,
            resource: $resource,
            reportFactory: $reportFactory,
            config: $config,
            input: $input,
            output: $output
        );
    }

    protected function runTests(): bool
    {
        if ($this->config->baseUrl === '') {
            $this->writeLine('❌ Telltale base URL not specified. Set `TELLTALE_BASE_URL` to the URL of your Telltale installation.', self::STYLE_ERROR);

            return false;
        }

        return parent::runTests();
    }

    /**
     * The core client words every line for Flare, and after a failed send
     * points to Flare's subscription, docs, status page and support. Here
     * the lines name Telltale and the Flare-only help is left out.
     */
    protected function writeLine(string $message, string $style = self::STYLE_PLAIN): void
    {
        if (str_contains($message, 'flareapp.io')) {
            return;
        }

        $message = str_replace(
            'Make sure that your key is correct and that you have a valid subscription.',
            'Make sure that your key and `TELLTALE_BASE_URL` are correct.',
            $message,
        );

        parent::writeLine($this->namesTelltale($message), $style);
    }

    /**
     * Telltale has no daemon, so the core client's advice to send logs
     * through the Flare daemon is skipped.
     */
    protected function warnAboutEntity(FlareEntityType $type): void
    {
        if ($type === FlareEntityType::Logs && $this->config->sender !== DaemonSender::class) {
            return;
        }

        parent::warnAboutEntity($type);
    }

    /**
     * Telltale refuses a send with JSON that carries a `message`. Any other
     * answer is shown as its page title or its first words, and flagged,
     * because it likely came from a firewall or proxy in front of Telltale.
     */
    protected function describeBadResponse(BadResponseCode $exception): string
    {
        $body = $exception->response->body;

        if (! is_string($body) || trim($body) === '') {
            return parent::describeBadResponse($exception);
        }

        $text = preg_match('/<title[^>]*>(.*?)<\/title>/is', $body, $matches) === 1
            ? $matches[1]
            : strip_tags($body);

        $text = Str::limit(Str::squish(html_entity_decode($text)), 200);

        return "{$exception->response->code} - The answer was not JSON, so it may have come from a firewall or proxy in front of Telltale. It read: {$text}";
    }

    protected function buildThrowable(): Throwable
    {
        return new Exception('This is an exception to test if the integration with Telltale works.');
    }

    protected function buildLog(): array
    {
        return array_map(function (array $log): array {
            if (is_string($log['body'] ?? null)) {
                $log['body'] = $this->namesTelltale($log['body']);
            }

            return $log;
        }, parent::buildLog());
    }

    /**
     * Replace the product name, leaving class names such as
     * `Spatie\FlareClient\Flare` and the `spatie/flare-client-php` package alone.
     */
    protected function namesTelltale(string $text): string
    {
        return (string) preg_replace('/(?<![\\\\\w])Flare(?!\w)/', 'Telltale', $text);
    }

    /** @return array<string, mixed> */
    protected function debugSections(): array
    {
        $flare = $this->repository->get('telltale', []);

        if (isset($flare['key'])) {
            $flare['key'] = '<redacted>';
        }

        return [
            ...parent::debugSections(),
            'telltale.php config' => $flare,
            'logging.php config' => $this->repository->get('logging', []),
        ];
    }

    protected function environmentInfo(): array
    {
        return [
            ...parent::environmentInfo(),
            ['Laravel', $this->app->version()],
            ['apxcde/laravel-telltale', Telemetry::getVersion()],
        ];
    }

    protected function buildEntryPoint(): EntryPoint
    {
        $entryPoint = new EntryPoint(
            type: EntryPointType::Cli,
            value: 'artisan telltale:test',
        );

        $entryPoint->setHandler(
            handlerIdentifier: 'telltale:test',
            handlerName: TestCommand::class,
            handlerType: 'php_command',
        );

        return $entryPoint;
    }

    protected function preCheckEntity(FlareEntityType $type): bool
    {
        return match ($type) {
            FlareEntityType::Errors => $this->checkErrorHandler(),
            FlareEntityType::Logs => $this->checkLogChannel(),
            default => true,
        };
    }

    protected function checkErrorHandler(): bool
    {
        if ($this->hasReportableFlareCallback()) {
            return true;
        }

        $this->writeLine('❌ The Telltale error callback was not configured correctly.', self::STYLE_ERROR);
        $this->writeNewline();
        $this->io->writeln('<fg=default;bg=default>Please ensure the following code is present in your `<fg=green>bootstrap/app.php</>` file:</>');
        $this->writeNewline();
        $this->io->writeln('<fg=default;bg=default>-><fg=green>withExceptions</>(<fg=blue>function</> (<fg=red>Exceptions</> $exceptions) {</>');
        $this->io->writeln('<fg=default;bg=default>    <fg=red>Telltale</>::<fg=green>handles</>($exceptions);</>');
        $this->io->writeln('<fg=default;bg=default>})-><fg=green>create</>();</>');

        return false;
    }

    protected function checkLogChannel(): bool
    {
        $channels = $this->repository->get('logging.channels', []);

        $flareChannelName = null;

        foreach ($channels as $name => $channel) {
            if (($channel['driver'] ?? null) === 'telltale') {
                $flareChannelName = $name;

                break;
            }
        }

        if ($flareChannelName === null) {
            $this->writeLine('❌ No logging channel with the `telltale` driver found. Please add a `telltale` channel to your `config/logging.php` file.', self::STYLE_ERROR);

            return false;
        }

        $defaultChannel = $this->repository->get('logging.default');

        $isActive = $defaultChannel === $flareChannelName
            || (($channels[$defaultChannel]['driver'] ?? null) === 'stack' && in_array($flareChannelName, $channels[$defaultChannel]['channels'] ?? []));

        if ($isActive) {
            return true;
        }

        if ($this->runningOnLaravelCloud()) {
            $this->writeLaravelCloudLogChannelInstructions($flareChannelName);

            return false;
        }

        $this->writeLine("❌ The `{$flareChannelName}` log channel exists but is not part of your default logging stack. Please add it to your `{$defaultChannel}` channel in `config/logging.php`.", self::STYLE_ERROR);

        return false;
    }

    protected function runningOnLaravelCloud(): bool
    {
        return ($_ENV['LARAVEL_CLOUD'] ?? false) === '1'
            || ($_SERVER['LARAVEL_CLOUD'] ?? false) === '1';
    }

    protected function writeLaravelCloudLogChannelInstructions(string $flareChannelName): void
    {
        $this->writeLine("❌ The `{$flareChannelName}` log channel exists but is not part of your default logging stack.", self::STYLE_ERROR);
        $this->writeNewline();
        $this->io->writeln('<fg=default;bg=default>Laravel Cloud sets its own log channel and overrides the one in your config.</>');
        $this->io->writeln('<fg=default;bg=default>These settings send your logs to both the Laravel Cloud dashboard and Telltale.</>');
        $this->io->writeln('<fg=default;bg=default>Add them on <fg=green>cloud.laravel.com</> under Application Settings -> General, in the</>');
        $this->io->writeln('<fg=default;bg=default>Custom environment variables section:</>');
        $this->writeNewline();
        $this->io->writeln('<fg=default;bg=default>    <fg=green>LOG_CHANNEL</>=<fg=blue>stack</></>');
        $this->io->writeln("<fg=default;bg=default>    <fg=green>LOG_STACK</>=<fg=blue>laravel-cloud-socket,{$flareChannelName}</></>");
    }

    protected function hasReportableFlareCallback(): bool
    {
        try {
            $handler = $this->app->make(ExceptionHandler::class);

            if ($handler instanceof \NunoMaduro\Collision\Adapters\Laravel\ExceptionHandler) {
                $reflection = new ReflectionProperty($handler, 'appExceptionHandler');
                $handler = $reflection->getValue($handler);
            }

            $reflection = new ReflectionProperty($handler, 'reportCallbacks');
            $reportCallbacks = $reflection->getValue($handler);

            foreach ($reportCallbacks as $reportCallback) {
                if (! $reportCallback instanceof ReportableHandler) {
                    continue;
                }

                $reflection = new ReflectionProperty($reportCallback, 'callback');
                $callback = $reflection->getValue($reportCallback);

                if (! $callback instanceof Closure) {
                    continue;
                }

                $reflection = new ReflectionClosure($callback);
                $closureReturnTypeReflection = $reflection->getReturnType();

                if (! $closureReturnTypeReflection instanceof ReflectionNamedType) {
                    continue;
                }

                if ($closureReturnTypeReflection->getName() === Flare::class) {
                    return true;
                }
            }
        } catch (ReflectionException $exception) {
            return false;
        }

        return false;
    }
}
