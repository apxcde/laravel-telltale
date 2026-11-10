<?php

use Spatie\FlareClient\Enums\CollectType;
use Apxcde\LaravelTelltale\AttributesProviders\LaravelUserAttributesProvider;
use Apxcde\LaravelTelltale\Enums\LaravelCollectType;
use Apxcde\LaravelTelltale\TelltaleConfig;
use Workbench\App\Jobs\IgnoredJob;
use Workbench\App\Senders\FileSender;

return [
    'key' => 'fake-api-key-for-testing-only',

    'base_url' => 'https://telltale.invalid',

    'collects' => TelltaleConfig::defaultCollects(
        ignore: [],
        extra: [
            CollectType::Jobs->value => [
                'ignored_classes' => [
                    IgnoredJob::class,
                ],
            ]
        ]
    ),

    'sender' => [
        'class' => FileSender::class,
        'config' => [
            'timeout' => 10,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Report
    |--------------------------------------------------------------------------
    |
    | Flare reports errors and exceptions happening within your application.
    |
    */

    'report' => true,

    'trace' => true,

    'sampler' => [
        'class' => \Spatie\FlareClient\Sampling\AlwaysSampler::class,
        'config' => [],
    ],

    'log' => true,
];
