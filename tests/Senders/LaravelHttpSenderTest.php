<?php

use Apxcde\LaravelTelltale\Senders\LaravelHttpSender;
use Illuminate\Support\Facades\Http;
use Spatie\FlareClient\Enums\FlareEntityType;
use Spatie\FlareClient\Senders\Support\Response;

function sendThroughLaravelHttpSender(): Response
{
    $received = null;

    (new LaravelHttpSender())->post(
        'https://telltale.test/v1/errors',
        'fake-api-key',
        [],
        FlareEntityType::Errors,
        true,
        function (Response $response) use (&$received): void {
            $received = $response;
        },
    );

    return $received;
}

it('passes the whole JSON answer of a refused send to the client', function () {
    Http::fake(['*' => Http::response(['message' => 'The project key is not valid.'], 403)]);

    $response = sendThroughLaravelHttpSender();

    expect($response->code)->toBe(403)
        ->and($response->body)->toBe(['message' => 'The project key is not valid.']);
});

it('passes the raw text of an answer that is not JSON', function () {
    Http::fake(['*' => Http::response('<html><title>Just a moment...</title></html>', 403)]);

    $response = sendThroughLaravelHttpSender();

    expect($response->code)->toBe(403)
        ->and($response->body)->toBe('<html><title>Just a moment...</title></html>');
});

it('passes an empty JSON object as an empty array', function () {
    Http::fake(['*' => Http::response('{}', 201)]);

    $response = sendThroughLaravelHttpSender();

    expect($response->code)->toBe(201)
        ->and($response->body)->toBe([]);
});
