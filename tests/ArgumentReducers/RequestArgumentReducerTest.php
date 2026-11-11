<?php

use Apxcde\LaravelTelltale\ArgumentReducers\RequestArgumentReducer;
use Apxcde\LaravelTelltale\TelltaleConfig;
use Illuminate\Http\Request;
use Spatie\Backtrace\Arguments\ReducedArgument\UnReducedArgument;
use Spatie\Backtrace\Arguments\Reducers\SymphonyRequestArgumentReducer;
use Spatie\FlareClient\Support\Redactor;

it('describes a request with its query string censored', function () {
    app()->instance(Redactor::class, new Redactor(censorBodyFields: ['phone']));

    $reduced = (new RequestArgumentReducer())->execute(
        Request::create('https://example.com/search?phone=%2B15551234567&page=2', 'POST'),
    );

    expect($reduced->value)->toBe('POST https://example.com/search?page=2&phone=<CENSORED:string>');
    expect($reduced->originalType)->toBe(Request::class);
});

it('leaves other arguments to the next reducer', function () {
    expect((new RequestArgumentReducer())->execute('a string'))->toBeInstanceOf(UnReducedArgument::class);
});

it('replaces the uncensored request reducer in the defaults', function () {
    expect(TelltaleConfig::defaultArgumentReducers())
        ->toContain(RequestArgumentReducer::class)
        ->not->toContain(SymphonyRequestArgumentReducer::class);
});
