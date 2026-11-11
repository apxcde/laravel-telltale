<?php

use Spatie\FlareClient\Enums\FlareEntityType;
use Spatie\FlareClient\Resources\Resource;

afterEach(function () {
    unset($_ENV['FLARE_FAKE_WEB_REQUEST']);
});

it('does not report the query string as the process command of a web request', function () {
    $argv = $_SERVER['argv'] ?? null;

    $_ENV['FLARE_FAKE_WEB_REQUEST'] = true;
    $_SERVER['argv'] = ['phone=%2B15551234567&page=2'];

    try {
        setupFlare();

        $attributes = app(Resource::class)->export(FlareEntityType::Errors);
    } finally {
        $_SERVER['argv'] = $argv;
    }

    expect($attributes['process.command'] ?? null)->toBeNull();
    expect($attributes['process.command_args'] ?? null)->toBeNull();
    expect(json_encode($attributes))->not->toContain('15551234567');
});

it('still reports the process command in the console', function () {
    setupFlare();

    $attributes = app(Resource::class)->export(FlareEntityType::Errors);

    expect($attributes['process.command'])->toBe($_SERVER['argv'][0]);
});
