<?php

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Exceptions\Handler;
use Apxcde\LaravelTelltale\Facades\Telltale;
use Apxcde\LaravelTelltale\Senders\LaravelHttpSender;
use Apxcde\LaravelTelltale\TelltaleConfig;
use Illuminate\Support\Facades\Http;
use Spatie\FlareClient\Api;

afterEach(function () {
    unset($_SERVER['LARAVEL_CLOUD']);

    putenv('SHELL_VERBOSITY');
    unset($_ENV['SHELL_VERBOSITY'], $_SERVER['SHELL_VERBOSITY']);
});

it('can execute the test command', function () {
    setupFlare();

    config()->set('logging.channels.telltale', ['driver' => 'telltale']);
    config()->set('logging.default', 'telltale');

    app()->extend(ExceptionHandler::class, function (Handler $handler) {
        class_exists(Exceptions::class)
            ? Telltale::handles(new Exceptions($handler))
            : Telltale::handles();

        return $handler;
    });

    $this->artisan('telltale:test')->assertOk();
});

it('fails when no flare key is set', function () {
    config()->set('telltale.key', null);

    setupFlare(withoutApiKey: true);

    $this->artisan('telltale:test')
        ->expectsOutputToContain('Telltale key not specified')
        ->assertFailed();
});

it('fails when the log channel is not configured', function () {
    setupFlare();

    $this->artisan('telltale:test --logs')
        ->expectsOutputToContain('No logging channel')
        ->assertFailed();
});

it('fails when the log channel exists but is not in the default stack', function () {
    setupFlare();

    config()->set('logging.channels.telltale', ['driver' => 'telltale']);
    config()->set('logging.default', 'single');

    $this->artisan('telltale:test --logs')
        ->expectsOutputToContain('is not part of your default logging stack')
        ->assertFailed();
});

it('explains how to configure the log channel on Laravel Cloud', function () {
    setupFlare();

    config()->set('logging.channels.telltale', ['driver' => 'telltale']);
    config()->set('logging.default', 'laravel-cloud-socket');

    $_SERVER['LARAVEL_CLOUD'] = '1';

    $this->artisan('telltale:test --logs')
        ->expectsOutputToContain('LOG_STACK=laravel-cloud-socket,telltale')
        ->assertFailed();
});

it('dumps the logging and flare configuration when running with -vvv', function () {
    setupFlare();

    config()->set('logging.channels.telltale', ['driver' => 'telltale']);
    config()->set('logging.default', 'telltale');

    $this->artisan('telltale:test --logs -vvv')
        ->expectsOutputToContain('Telltale config')
        ->expectsOutputToContain('telltale.php config')
        ->expectsOutputToContain('logging.php config')
        ->expectsOutputToContain('<redacted>');
});

it('does not dump the configuration without -vvv', function () {
    setupFlare();

    config()->set('logging.channels.telltale', ['driver' => 'telltale']);
    config()->set('logging.default', 'telltale');

    $this->artisan('telltale:test --logs')
        ->doesntExpectOutputToContain('logging.php config');
});

it('fails when no base url is set', function () {
    setupFlare(fn (TelltaleConfig $config) => $config->baseUrl = '');

    $this->artisan('telltale:test')
        ->expectsOutputToContain('Telltale base URL not specified')
        ->assertFailed();
});

it('finds the error callback when the app registered another reportable callback first', function () {
    $handler = app(ExceptionHandler::class);

    if (! $handler instanceof Handler) {
        $this->markTestSkipped('The exception handler does not support reportable callbacks.');
    }

    $handler->reportable(function (Throwable $exception): void {
    });

    setupFlare();

    $this->artisan('telltale:test --errors')
        ->doesntExpectOutputToContain('error callback was not configured')
        ->assertOk();
});

it('names Telltale and leaves out Flare\'s own help', function () {
    setupFlare();

    $this->artisan('telltale:test --errors')
        ->expectsOutputToContain('Telltale key specified')
        ->expectsOutputToContain('Error sent to Telltale')
        ->doesntExpectOutputToContain('Flare')
        ->assertOk();
});

it('shows the reason Telltale gave for refusing a send', function () {
    Http::fake(['*' => Http::response(['message' => 'The project key is not valid.'], 403)]);

    setupFlare(function (TelltaleConfig $config) {
        $config->api = Api::class;
        $config->sender = LaravelHttpSender::class;
        $config->baseUrl = 'https://telltale.test';
    });

    $this->artisan('telltale:test --errors')
        ->expectsOutputToContain('403 - The project key is not valid.')
        ->assertFailed();
});

it('says when a refusal did not come from Telltale', function () {
    Http::fake(['*' => Http::response(
        '<html><head><title>Just a moment...</title><style>body{margin:0}</style></head><body>Checking your browser</body></html>',
        403,
    )]);

    setupFlare(function (TelltaleConfig $config) {
        $config->api = Api::class;
        $config->sender = LaravelHttpSender::class;
        $config->baseUrl = 'https://telltale.test';
    });

    $this->artisan('telltale:test --errors')
        ->expectsOutputToContain('403 - The answer was not JSON, so it may have come from a firewall or proxy in front of Telltale. It read: Just a moment...')
        ->assertFailed();
});
