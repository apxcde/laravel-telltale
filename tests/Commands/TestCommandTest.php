<?php

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Exceptions\Handler;
use Apxcde\LaravelTelltale\Facades\Telltale;
use Apxcde\LaravelTelltale\TelltaleConfig;

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
        ->expectsOutputToContain('Flare key not specified')
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
        ->expectsOutputToContain('Flare config')
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
