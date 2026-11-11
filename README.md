# Laravel Telltale: send Laravel errors, logs and traces to Telltale

`apxcde/laravel-telltale` is the Laravel client for Telltale, a self-hosted receiver for errors, logs and performance traces. It runs inside your application and reports to your own Telltale installation.

This package is a fork of [`spatie/laravel-flare`](https://github.com/spatie/laravel-flare) by [Spatie](https://spatie.be). It is not affiliated with Spatie or [Flare](https://flareapp.io). The fork does not follow upstream releases.

## What differs from `spatie/laravel-flare`

- **Names.** The namespace is `Apxcde\LaravelTelltale`, the config file is `config/telltale.php`, the log driver is `telltale`, the facade is `Telltale` and the test command is `telltale:test`.
- **Env variables** start with `TELLTALE_`: `TELLTALE_KEY`, `TELLTALE_BASE_URL`, `TELLTALE_REPORT`, `TELLTALE_TRACE`, `TELLTALE_SAMPLER_RATE` and `TELLTALE_LOG`.
- **No default server.** Nothing is sent unless both `TELLTALE_KEY` and `TELLTALE_BASE_URL` are set. The package never falls back to flareapp.io.
- **Tracing is off by default.** Enable it with `TELLTALE_TRACE=true`.
- **The error page's Share button is off by default**, because it uploads the error to flareapp.io.
- **Query bindings are not sent by default**, because bound values often hold personal data. Queries are reported with their `?` placeholders, and the message of a failed query is rewritten the same way. To send the values, set `include_bindings` to `true` for the queries collect in `config/telltale.php`:

  ```php
  'collects' => \Apxcde\LaravelTelltale\TelltaleConfig::defaultCollects(
      extra: [
          \Spatie\FlareClient\Enums\CollectType::Queries->value => ['include_bindings' => true],
      ],
  ),
  ```

  A value the database quotes in its own error text, such as the duplicate value in a unique key violation, is still part of the message.
- **Query strings are censored.** The `censor.body_fields` list also applies to query string parameters in URLs: the request's URL and entry point, the `Referer` header, the request shown as a stack frame argument, and the URLs of outgoing HTTP requests. On a web request the process command is not reported, because PHP can put the query string there. With `phone` in the list, `/search?phone=...` is sent as `/search?phone=<CENSORED:string>`. Values in the path of a URL (`/contacts/+15551234567`) are not censored.

The wire protocol is unchanged. The package still depends on [`spatie/flare-client-php`](https://github.com/spatie/flare-client-php), which builds and sends the payloads, so its classes keep the `Spatie\FlareClient` namespace and the payloads keep Flare's attribute names (for example `flare.entry_point.type`).

## Requirements

PHP 8.2 or higher and Laravel 10.14 or higher. The package conflicts with `spatie/laravel-ignition`, which needs an older `spatie/flare-client-php`; remove Ignition first.

## Installation

The package is not on Packagist. Add its repository to your `composer.json`, then require it:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/apxcde/laravel-telltale" }
]
```

```bash
composer require apxcde/laravel-telltale:dev-main
```

Set the project key and the URL of your Telltale installation in `.env`:

```dotenv
TELLTALE_KEY=your-project-key
TELLTALE_BASE_URL=https://telltale.example.com
```

Report exceptions from `bootstrap/app.php`:

```php
use Apxcde\LaravelTelltale\Facades\Telltale;

->withExceptions(function (Exceptions $exceptions) {
    Telltale::handles($exceptions);
})
```

To send logs, add a channel with the `telltale` driver to `config/logging.php` and include it in your stack:

```php
'telltale' => [
    'driver' => 'telltale',
],
```

To change the defaults, publish the config file:

```bash
php artisan vendor:publish --tag=telltale-config
```

Then check the setup. Each flag sends one test payload of that kind:

```bash
php artisan telltale:test --errors --logs --traces
```

## Keeping tests silent

Set an empty key in `phpunit.xml` so your test suite never sends anything:

```xml
<env name="TELLTALE_KEY" value=""/>
```

## Testing the package

The test suite includes integration tests that need a running workbench server and queue worker:

```bash
composer install
composer run build
vendor/bin/testbench serve &
vendor/bin/testbench queue:work &
vendor/bin/pest
```

To run only the unit tests:

```bash
vendor/bin/pest --exclude-group=integration
```

## Changelog

[CHANGELOG](CHANGELOG.md) and [UPGRADING](UPGRADING.md) record the history of `spatie/laravel-flare` up to the point of the fork, and use its names.

## Credits

- [Spatie](https://spatie.be) and [all contributors](https://github.com/spatie/laravel-flare/graphs/contributors) to `spatie/laravel-flare`, who wrote nearly all of this code.

## License

The MIT License (MIT). Please see the [License File](LICENSE.md) for more information.
