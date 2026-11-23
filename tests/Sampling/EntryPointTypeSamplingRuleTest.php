<?php

use Apxcde\LaravelTelltale\Sampling\EntryPointTypeSamplingRule;
use Spatie\FlareClient\EntryPoint\EntryPoint;
use Spatie\FlareClient\Enums\EntryPointType;
use Spatie\FlareClient\Sampling\DeferredSamplerRule;

it('gives each kind of entry point its own rate', function () {
    $rule = new EntryPointTypeSamplingRule(['web' => 0.25, 'queue' => 1.0]);

    expect($rule->appliesTo(EntryPointType::Web))->toBeTrue();
    expect($rule->appliesTo(EntryPointType::Queue))->toBeTrue();
    expect($rule->appliesTo(EntryPointType::Cli))->toBeFalse();

    expect($rule->getMatchedRate(new EntryPoint(EntryPointType::Web, 'https://app.test/inbox')))->toBe(0.25);
    expect($rule->getMatchedRate(new EntryPoint(EntryPointType::Queue, 'App\\Jobs\\TranscribeVoicemail')))->toBe(1.0);
    expect($rule->getMatchedRate(new EntryPoint(EntryPointType::Cli, 'artisan reports:send')))->toBeNull();
});

it('decides when the trace starts, without waiting for the route or the command', function () {
    expect(new EntryPointTypeSamplingRule(['web' => 1.0]))->not->toBeInstanceOf(DeferredSamplerRule::class);
});

it('leaves out a kind it does not know and a rate that is no rate', function () {
    $rule = new EntryPointTypeSamplingRule(['web' => 'often', 'queue' => null, 'cli' => 7, 'scheduled' => 0.5]);

    expect($rule->isEmpty())->toBeTrue();
});

it('reads a rate the way an env variable gives it', function (mixed $rate, ?float $expected) {
    expect(EntryPointTypeSamplingRule::normalizeRate($rate))->toBe($expected);
})->with([
    'a float' => [0.5, 0.5],
    'an integer' => [1, 1.0],
    'zero' => [0, 0.0],
    'a numeric string' => ['0.25', 0.25],
    'a padded string' => [' 1 ', 1.0],
    'null' => [null, null],
    'an empty string' => ['', null],
    'a word' => ['often', null],
    'above one' => [1.5, null],
    'a percentage' => ['50', null],
    'below zero' => [-0.1, null],
    'a boolean' => [true, null],
    'a list' => [[0.5], null],
]);
