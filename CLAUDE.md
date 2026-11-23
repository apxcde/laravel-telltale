# CLAUDE.md

Guidance for working in this repository.

## Architecture

`apxcde/laravel-telltale` is the Laravel adapter for `spatie/flare-client-php`, forked from `spatie/laravel-flare`. It reports to a Telltale installation, which speaks Flare's wire protocol. The fork does not follow upstream releases. The client package owns all framework-agnostic behaviour (reporting, tracing, recorders, senders, the HTTP API, the lifecycle state machine). This package adds the Laravel-specific glue.

### Package boundary

Belongs in `spatie/flare-client-php`:
- Anything that can run without Laravel installed.
- Core abstractions: `Flare`, `Tracer`, `FlareProvider`, the base `FlareConfig`, `Lifecycle`, `Report`, `Span`, plus recorders/middleware/senders that only need PHP.
- The `Tester` base class.

Belongs in `apxcde/laravel-telltale`:
- Anything that touches `Illuminate\*`, facades, Eloquent, Blade, Livewire, Octane, or Vapor.
- Laravel subclasses of client base classes (e.g. `LaravelStacktraceMapper`, `LaravelTester`, `LaravelHttpSender`, recorders under `src/Recorders/` that consume Laravel events).
- The service provider that wires everything into the container.

When adding behaviour, default to extending an existing client class on the Laravel side rather than spinning up a new value object. If a feature is useful outside Laravel, push it down into the client package and subclass here.

### Config hydration

`TelltaleConfig::fromLaravelConfig()` reads `config/telltale.php` into a typed `TelltaleConfig`. Three rules apply:

1. Helpers called from a config file must never throw. Return `null` on bad input and filter at the call site. Config files load extremely early; an exception there breaks the whole app.
2. Unknown `collects` types are silently skipped via `tryFrom`. This is deliberate so configs can reference recorders that may not exist in older versions.
3. An empty `base_url` disables the client: `fromLaravelConfig()` passes a null API token, which puts the client's `FlareProvider` in disabled mode. There is no fallback to flareapp.io.

### Naming

Names an application sees are Telltale's: the `Apxcde\LaravelTelltale` namespace, `config/telltale.php`, the `telltale` log driver and filesystem disk key, the `Telltale` facade, `TelltaleServiceProvider`, `TelltaleConfig`, `telltale:test` and the `TELLTALE_*` env variables. Internal classes keep their upstream names (`FlareTracingMiddleware`, `FlareLogHandler`, `SendFlarePayload`, the `FlareMiddleware/` folder), as does everything in the client package.

Never rename anything that goes over the wire: the `/v1/*` endpoints, the `x-api-token` header, and attribute names such as `flare.span_type` and `flare.entry_point.*`. Telltale accepts the stock Flare clients too.

## Running the test suite

The suite includes integration tests that hit a running workbench HTTP server and queue worker. Without those running, ~64 integration tests will fail. The full sequence:

```bash
composer install
composer run build                  # builds the workbench (migrations, storage symlink, etc.)
vendor/bin/testbench serve &        # http server
vendor/bin/testbench queue:work &   # queue worker
vendor/bin/pest                     # run all tests
```

After the suite finishes, kill the background processes:

```bash
pkill -f "testbench serve"
pkill -f "testbench queue:work"
```

For unit tests only (no workbench needed):

```bash
vendor/bin/pest --exclude-group=integration
```
