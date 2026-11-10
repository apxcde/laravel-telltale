<?php

use Apxcde\LaravelTelltale\EntryPoint\CensoringEntryPointResolver;
use Apxcde\LaravelTelltale\Support\UrlCensor;
use Spatie\FlareClient\EntryPoint\EntryPointResolver;
use Spatie\FlareClient\Enums\EntryPointType;
use Spatie\FlareClient\Support\Redactor;

afterEach(function () {
    unset($_ENV['FLARE_FAKE_WEB_REQUEST'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI']);
});

it('is the entry point resolver the package registers', function () {
    setupFlare();

    expect(app(EntryPointResolver::class))->toBeInstanceOf(CensoringEntryPointResolver::class);
});

it('censors the query string of a web entry point', function () {
    $_ENV['FLARE_FAKE_WEB_REQUEST'] = true;
    $_SERVER['HTTP_HOST'] = 'example.com';
    $_SERVER['REQUEST_URI'] = '/search?phone=%2B15551234567&page=2';

    $entryPoint = (new CensoringEntryPointResolver(
        new UrlCensor(new Redactor(censorBodyFields: ['phone'])),
    ))->get();

    expect($entryPoint->type)->toBe(EntryPointType::Web);
    expect($entryPoint->value)->toBe('http://example.com/search?phone=<CENSORED:string>&page=2');
});

it('leaves a console entry point alone', function () {
    $entryPoint = (new CensoringEntryPointResolver(
        new UrlCensor(new Redactor(censorBodyFields: ['phone'])),
    ))->get();

    expect($entryPoint->type)->toBe(EntryPointType::Cli);
    expect($entryPoint->value)->toBe(implode(' ', $_SERVER['argv'] ?? []));
});
