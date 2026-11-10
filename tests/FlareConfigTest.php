<?php

use Apxcde\LaravelTelltale\TelltaleConfig;

it('censors the session and remember me cookies', function () {
    config()->set('telltale', require __DIR__.'/../config/telltale.php');
    config()->set('session.cookie', 'my_app_session');

    expect(TelltaleConfig::fromLaravelConfig()->censorCookieNames)->toBe([
        'my_app_session',
        'remember_*',
    ]);
});

it('censors the remember me cookies when a published config has no cookie names', function () {
    $flareConfig = require __DIR__.'/../config/telltale.php';

    unset($flareConfig['censor']['cookie_names']);

    config()->set('telltale', $flareConfig);
    config()->set('session.cookie', 'my_app_session');

    expect(TelltaleConfig::fromLaravelConfig()->censorCookieNames)->toBe([
        'my_app_session',
        'remember_*',
    ]);
});

it('ignores invalid cookie names in the config', function () {
    config()->set('telltale', require __DIR__.'/../config/telltale.php');
    config()->set('telltale.censor.cookie_names', null);
    config()->set('session.cookie', 'my_app_session');

    expect(TelltaleConfig::fromLaravelConfig()->censorCookieNames)->toBe([
        'my_app_session',
    ]);
});

it('disables sending when no base url is set, even with a key', function (mixed $baseUrl) {
    config()->set('telltale', require __DIR__.'/../config/telltale.php');
    config()->set('telltale.key', 'some-key');
    config()->set('telltale.base_url', $baseUrl);

    $config = TelltaleConfig::fromLaravelConfig();

    expect($config->apiToken)->toBeNull();
    expect($config->baseUrl)->toBe('');
})->with([
    'null' => [null],
    'empty string' => [''],
    'whitespace' => ['  '],
]);

it('uses the key and base url when both are set', function () {
    config()->set('telltale', require __DIR__.'/../config/telltale.php');
    config()->set('telltale.key', 'some-key');
    config()->set('telltale.base_url', 'https://telltale.example.com/');

    $config = TelltaleConfig::fromLaravelConfig();

    expect($config->apiToken)->toBe('some-key');
    expect($config->baseUrl)->toBe('https://telltale.example.com');
});

it('never falls back to the flare ingress url', function (string $file) {
    $config = require __DIR__.'/../config/'.$file;

    expect($config['base_url'])->toBeNull();
    expect(file_get_contents(__DIR__.'/../config/'.$file))->not->toContain('BASE_URL)');
})->with(['telltale.php', 'telltale.php.stub']);

it('has tracing and the share button off by default', function (string $file) {
    $config = require __DIR__.'/../config/'.$file;

    expect($config['trace'])->toBeFalse();
    expect($config['enable_share_button'])->toBeFalse();
})->with(['telltale.php', 'telltale.php.stub']);
