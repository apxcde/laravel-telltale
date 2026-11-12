<?php

return [
    /*
    |
    |--------------------------------------------------------------------------
    | Telltale project key
    |--------------------------------------------------------------------------
    |
    | The key of the Telltale project this application reports to. Nothing
    | is sent while the key is empty.
    |
    */

    'key' => env('TELLTALE_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Telltale base URL
    |--------------------------------------------------------------------------
    |
    | The URL of your Telltale installation, for example
    | `https://telltale.example.com`. There is no default: nothing is sent
    | while the base URL is empty, even when a key is set.
    |
    */

    'base_url' => env('TELLTALE_BASE_URL'),

    /*
    |--------------------------------------------------------------------------
    | Collects
    |--------------------------------------------------------------------------
    |
    | Telltale will collect a lot of information about your application. You can
    | disable some of the collectors here, configure them or add your own.
    |
    */

    'collects' => \Apxcde\LaravelTelltale\TelltaleConfig::defaultCollects(
        ignore: [],
        extra: []
    ),

    /*
    |--------------------------------------------------------------------------
    | Censor data
    |--------------------------------------------------------------------------
    |
    | It is possible to censor sensitive data from the reports and sent to
    | Telltale. Below you can specify which fields, headers and cookies should
    | be censored. The body fields also apply to query string parameters in
    | URLs. A trailing `*` in a cookie name matches every cookie
    | starting with that name. The session cookie is always censored. It
    | is also possible to hide the client's IP address.
    |
    */

    'censor' => [
        'body_fields' => [
            'password',
            'password_confirmation',
        ],
        'headers' => [
            'API-KEY',
            'Authorization',
            'Cookie',
            'Set-Cookie',
            'X-CSRF-TOKEN',
            'X-XSRF-TOKEN',
        ],
        'cookie_names' => [
            'remember_*',
        ],
        'client_ips' => false,
        // Cookie and session values are left out of reports. Set either to
        // false to send them; the cookies named above stay hidden even then.
        'cookies' => true,
        'session' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Sender
    |--------------------------------------------------------------------------
    |
    | The sender is responsible for sending the error reports and traces to
    | Telltale. By default, they are sent over HTTP. To use a local
    | Flare daemon, switch the sender class to
    | `Spatie\FlareClient\Senders\DaemonSender::class` and set `daemon_url`.
    | The daemon sender defaults to localhost on port 8787 and uses its own
    | default timeouts and fallback sender config unless you override them.
    |
    */

    'sender' => [
        'class' => \Apxcde\LaravelTelltale\Senders\LaravelHttpSender::class,
        'config' => [
            'timeout' => 10,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Report
    |--------------------------------------------------------------------------
    |
    | Telltale reports errors and exceptions happening within your application.
    |
    */

    'report' => env('TELLTALE_REPORT', true),

    /*
    |--------------------------------------------------------------------------
    | Report error levels
    |--------------------------------------------------------------------------
    | When reporting errors, you can specify which error levels should be
    | reported. By default, all error levels are reported by setting
    | this value to `null`.
     */

    'report_error_levels' => null,


    /*
    |--------------------------------------------------------------------------
    | Share button
    |--------------------------------------------------------------------------
    |
    | The Share button on the Laravel error page uploads the error to
    | flareapp.io, a third party, so it is disabled by default.
    |
    */

    'enable_share_button' => false,

    /*
    |--------------------------------------------------------------------------
    | Override grouping
    |--------------------------------------------------------------------------
    |
    | Telltale will try to group errors and exceptions as best as possible, that
    | being said, sometimes you might want to override the grouping. You can
    | do this by adding exception classes to this array which should always
    | be grouped by exception class, exception message or exception class
    | and message.
    |
    */

    'overridden_groupings' => [
        // Illuminate\Http\Client\ConnectionException::class => Spatie\FlareClient\Enums\OverriddenGrouping::ExceptionMessageAndClass,
    ],


    /*
    |--------------------------------------------------------------------------
    | Trace
    |--------------------------------------------------------------------------
    |
    | Tracing allows you to see the flow of your application. It shows you
    | which parts of your application are slow and which parts are fast.
    | It is disabled by default; enable it with `TELLTALE_TRACE=true`.
    |
    */

    'trace' => env('TELLTALE_TRACE', false),

    /*
   |--------------------------------------------------------------------------
   | Sampler
   |--------------------------------------------------------------------------
   |
   | The sampler is used to determine which traces should be recorded and
   | which traces should be dropped. It is possible to set the rate
   | at which traces should be recorded. The default rate is 0.1
   | which means that 10% of the traces will be recorded.
   |
   */

    'sampler' => [
        'class' => \Spatie\FlareClient\Sampling\RateSampler::class,
        'config' => [
            'rate' => env('TELLTALE_SAMPLER_RATE', 0.1),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Trace limits
    |--------------------------------------------------------------------------
    |
    | Limits for the tracing data. These limits are used to prevent
    | the tracing data from growing too large.
    |
    */

    'trace_limits' => [
        'max_spans' => 1024,
        'max_attributes_per_span' => 128,
        'max_span_events_per_span' => 128,
        'max_attributes_per_span_event' => 128,
        'max_attribute_size_in_kb' => 32,
    ],

    /*
    |--------------------------------------------------------------------------
    | Log
    |--------------------------------------------------------------------------
    |
    | Logging show you an overview of log entries within your application.
    | Logs are only sent when a channel using the `telltale` driver is part of
    | your logging stack in `config/logging.php`. You can disable the
    | collection of logs by setting `TELLTALE_LOG=false`.
    |
    */

    'log' => env('TELLTALE_LOG', true),

    /*
    |--------------------------------------------------------------------------
    | Minimal log level
    |--------------------------------------------------------------------------
    |
    | You can specify the minimal (Monolog) log level that should be sent to Telltale.
    | Log levels lower than the specified level will be ignored.
    | If null all log levels will be sent to Telltale.
    |
    */

    'minimal_log_level' => null,
];
