<?php

use Apxcde\LaravelTelltale\Support\UrlCensor;
use Spatie\FlareClient\Support\Redactor;

function urlCensor(string ...$fields): UrlCensor
{
    return new UrlCensor(new Redactor(censorBodyFields: $fields));
}

it('censors the value of a listed query parameter', function () {
    expect(urlCensor('phone')->url('https://example.com/search?phone=%2B15551234567&page=2'))
        ->toBe('https://example.com/search?phone=<CENSORED:string>&page=2');
});

it('matches names the way body fields are matched', function (string $field, string $url, string $expected) {
    expect(urlCensor($field)->url($url))->toBe($expected);
})->with([
    'case-insensitive' => ['phone', '/s?Phone=1', '/s?Phone=<CENSORED:string>'],
    'a prefix wildcard' => ['media*', '/s?MediaUrl0=a&q=b', '/s?MediaUrl0=<CENSORED:string>&q=b'],
    'a nested parameter' => ['email', '/s?filter%5Bemail%5D=a%40b.c&q=1', '/s?filter%5Bemail%5D=<CENSORED:string>&q=1'],
    'a dotted path' => ['filter.email', '/s?filter[email]=a&email=b', '/s?filter%5Bemail%5D=<CENSORED:string>&email=b'],
    'a whole array' => ['to', '/s?to[]=1&to[]=2', '/s?to=<CENSORED:array>'],
]);

it('keeps the fragment', function () {
    expect(urlCensor('token')->url('/callback?token=abc#done'))
        ->toBe('/callback?token=<CENSORED:string>#done');
});

it('returns a url without censored parameters exactly as given', function (string $url) {
    expect(urlCensor('phone')->url($url))->toBe($url);
})->with([
    'no query string' => ['https://example.com/search'],
    'an empty query string' => ['https://example.com/search?'],
    'other parameters, unusual encoding' => ['https://example.com/search?q=a+b&x=%7e&flag'],
    'not a url' => ['<CENSORED:string>'],
]);

it('censors a bare query string', function () {
    expect(urlCensor('phone')->query('phone=1&q=2'))->toBe('phone=<CENSORED:string>&q=2');
    expect(urlCensor('phone')->query(''))->toBe('');
});
