<?php

use Apxcde\LaravelTelltale\Sampling\EntryPointTypeSamplingRule;
use Apxcde\LaravelTelltale\TelltaleConfig;
use Spatie\FlareClient\EntryPoint\EntryPoint;
use Spatie\FlareClient\Enums\EntryPointType;
use Spatie\FlareClient\Sampling\AlwaysSampler;
use Spatie\FlareClient\Sampling\DynamicSampler;
use Spatie\FlareClient\Sampling\RateSampler;
use Spatie\FlareClient\Sampling\Rules\JobSamplingRule;

it('censors the session and remember me cookies', function () {
    config()->set('telltale', require __DIR__.'/../config/telltale.php');
    config()->set('session.cookie', 'my_app_session');

    expect(TelltaleConfig::fromLaravelConfig()->censorCookieNames)->toBe([
        'my_app_session',
        'remember_*',
    ]);
});

it('censors the remember me cookies when a published config has no cookie names', function () {
    $flareConfig = require __DIR__.'/../config/telltale.php';

    unset($flareConfig['censor']['cookie_names']);

    config()->set('telltale', $flareConfig);
    config()->set('session.cookie', 'my_app_session');

    expect(TelltaleConfig::fromLaravelConfig()->censorCookieNames)->toBe([
        'my_app_session',
        'remember_*',
    ]);
});

it('ignores invalid cookie names in the config', function () {
    config()->set('telltale', require __DIR__.'/../config/telltale.php');
    config()->set('telltale.censor.cookie_names', null);
    config()->set('session.cookie', 'my_app_session');

    expect(TelltaleConfig::fromLaravelConfig()->censorCookieNames)->toBe([
        'my_app_session',
    ]);
});

it('disables sending when no base url is set, even with a key', function (mixed $baseUrl) {
    config()->set('telltale', require __DIR__.'/../config/telltale.php');
    config()->set('telltale.key', 'some-key');
    config()->set('telltale.base_url', $baseUrl);

    $config = TelltaleConfig::fromLaravelConfig();

    expect($config->apiToken)->toBeNull();
    expect($config->baseUrl)->toBe('');
})->with([
    'null' => [null],
    'empty string' => [''],
    'whitespace' => ['  '],
]);

it('uses the key and base url when both are set', function () {
    config()->set('telltale', require __DIR__.'/../config/telltale.php');
    config()->set('telltale.key', 'some-key');
    config()->set('telltale.base_url', 'https://telltale.example.com/');

    $config = TelltaleConfig::fromLaravelConfig();

    expect($config->apiToken)->toBe('some-key');
    expect($config->baseUrl)->toBe('https://telltale.example.com');
});

it('never falls back to the flare ingress url', function (string $file) {
    $config = require __DIR__.'/../config/'.$file;

    expect($config['base_url'])->toBeNull();
    expect(file_get_contents(__DIR__.'/../config/'.$file))->not->toContain('BASE_URL)');
})->with(['telltale.php', 'telltale.php.stub']);

it('has tracing and the share button off by default', function (string $file) {
    $config = require __DIR__.'/../config/'.$file;

    expect($config['trace'])->toBeFalse();
    expect($config['enable_share_button'])->toBeFalse();
})->with(['telltale.php', 'telltale.php.stub']);

it('publishes the package defaults in every block of the published config', function () {
    $defaults = require __DIR__.'/../config/telltale.php';
    $published = require __DIR__.'/../config/telltale.php.stub';

    // Laravel fills in the top-level keys a published config leaves out and nothing below
    // them, so a block the published config has replaces the package's block as a whole.
    $expected = array_intersect_key($defaults, $published);

    ksort($expected);
    ksort($published);

    expect($published)->toBe($expected);
});

it('hides cookies and the session by default', function (string $file) {
    config()->set('telltale', require __DIR__.'/../config/'.$file);

    $config = TelltaleConfig::fromLaravelConfig();

    expect($config->censorCookies)->toBeTrue()
        ->and($config->censorSession)->toBeTrue();
})->with(['telltale.php', 'telltale.php.stub']);

it('hides cookies and the session when a published config does not mention them', function () {
    $telltaleConfig = require __DIR__.'/../config/telltale.php';

    unset($telltaleConfig['censor']['cookies'], $telltaleConfig['censor']['session']);

    config()->set('telltale', $telltaleConfig);

    $config = TelltaleConfig::fromLaravelConfig();

    expect($config->censorCookies)->toBeTrue()
        ->and($config->censorSession)->toBeTrue();
});

it('sends cookies and the session when an application turns hiding off', function () {
    config()->set('telltale', require __DIR__.'/../config/telltale.php');
    config()->set('telltale.censor.cookies', false);
    config()->set('telltale.censor.session', false);

    $config = TelltaleConfig::fromLaravelConfig();

    expect($config->censorCookies)->toBeFalse()
        ->and($config->censorSession)->toBeFalse();
});

it('is on by default, also for a published config that does not mention it', function () {
    $telltaleConfig = require __DIR__.'/../config/telltale.php';

    unset($telltaleConfig['enabled']);

    config()->set('telltale', $telltaleConfig);
    config()->set('telltale.key', 'some-key');
    config()->set('telltale.base_url', 'https://telltale.example.com');

    expect(TelltaleConfig::fromLaravelConfig()->apiToken)->toBe('some-key');
});

it('is turned off in one place', function (mixed $enabled) {
    config()->set('telltale', require __DIR__.'/../config/telltale.php');
    config()->set('telltale.key', 'some-key');
    config()->set('telltale.base_url', 'https://telltale.example.com');
    config()->set('telltale.enabled', $enabled);

    expect(TelltaleConfig::fromLaravelConfig()->apiToken)->toBeNull();
})->with([
    'false' => [false],
    'the string false' => ['false'],
    'zero' => ['0'],
    'off' => ['off'],
    'no' => ['no'],
    'an empty value' => [''],
]);

it('stays on when the setting does not say off', function (mixed $enabled) {
    config()->set('telltale', require __DIR__.'/../config/telltale.php');
    config()->set('telltale.key', 'some-key');
    config()->set('telltale.base_url', 'https://telltale.example.com');
    config()->set('telltale.enabled', $enabled);

    expect(TelltaleConfig::fromLaravelConfig()->apiToken)->toBe('some-key');
})->with([
    'true' => [true],
    'the string true' => ['true'],
    'one' => ['1'],
    'null' => [null],
    'a typo' => ['flase'],
]);

it('reads the switch and the rates per kind from env variables in both config files', function (string $file) {
    $contents = file_get_contents(__DIR__.'/../config/'.$file);

    expect($contents)
        ->toContain("'enabled' => env('TELLTALE_ENABLED', true)")
        ->toContain("'requests' => env('TELLTALE_REQUEST_SAMPLE_RATE')")
        ->toContain("'jobs' => env('TELLTALE_JOB_SAMPLE_RATE')")
        ->toContain("'commands' => env('TELLTALE_COMMAND_SAMPLE_RATE')");

    $config = require __DIR__.'/../config/'.$file;

    expect($config['enabled'])->toBeTrue();
    expect($config['sample_rates'])->toBe(['requests' => null, 'jobs' => null, 'commands' => null]);
})->with(['telltale.php', 'telltale.php.stub']);

it('keeps the rate sampler when no kind has a rate of its own', function () {
    config()->set('telltale', require __DIR__.'/../config/telltale.php');

    $config = TelltaleConfig::fromLaravelConfig();

    expect($config->sampler)->toBe(RateSampler::class);
    expect($config->samplerConfig)->toBe(['rate' => 0.1]);
});

it('keeps the rate sampler for a published config without rates per kind', function () {
    $telltaleConfig = require __DIR__.'/../config/telltale.php';

    unset($telltaleConfig['sample_rates']);

    config()->set('telltale', $telltaleConfig);

    expect(TelltaleConfig::fromLaravelConfig()->sampler)->toBe(RateSampler::class);
});

it('samples each kind at its own rate and the rest at the sampler rate', function () {
    config()->set('telltale', require __DIR__.'/../config/telltale.php');
    config()->set('telltale.sampler.config.rate', 0);
    config()->set('telltale.sample_rates', ['requests' => '1', 'jobs' => null, 'commands' => 0.0]);

    $config = TelltaleConfig::fromLaravelConfig();

    expect($config->sampler)->toBe(DynamicSampler::class);

    $sampler = new $config->sampler($config->samplerConfig);

    $request = new EntryPoint(EntryPointType::Web, 'https://app.test/inbox');
    $job = new EntryPoint(EntryPointType::Queue, 'App\\Jobs\\TranscribeVoicemail');
    $command = new EntryPoint(EntryPointType::Cli, 'artisan reports:send');

    expect($sampler->shouldSample($request))->toBeTrue();
    expect($sampler->shouldSample($command))->toBeFalse();

    // A job has no rate of its own here: it follows whoever dispatched it, else the sampler's rate.
    expect($sampler->shouldSample($job, parentSampled: true))->toBeTrue();
    expect($sampler->shouldSample($job, parentSampled: false))->toBeFalse();
    expect($sampler->shouldSample($job))->toBeFalse();
});

it('lets every job decide for itself once jobs have a rate', function () {
    config()->set('telltale', require __DIR__.'/../config/telltale.php');
    config()->set('telltale.sampler.config.rate', 0);
    config()->set('telltale.sample_rates.jobs', 1);

    $config = TelltaleConfig::fromLaravelConfig();
    $sampler = new $config->sampler($config->samplerConfig);

    $job = new EntryPoint(EntryPointType::Queue, 'App\\Jobs\\TranscribeVoicemail');

    expect($sampler->shouldSample($job, parentSampled: false))->toBeTrue();
});

it('ignores a rate per kind that is no rate', function (mixed $rate) {
    config()->set('telltale', require __DIR__.'/../config/telltale.php');
    config()->set('telltale.sample_rates', ['requests' => $rate]);

    expect(TelltaleConfig::fromLaravelConfig()->sampler)->toBe(RateSampler::class);
})->with([
    'a word' => ['often'],
    'above one' => [2],
    'below zero' => [-1],
    'an empty string' => [''],
    'a list' => [[0.5]],
]);

it('ignores rates per kind that are not a list of rates', function () {
    config()->set('telltale', require __DIR__.'/../config/telltale.php');
    config()->set('telltale.sample_rates', 0.5);

    expect(TelltaleConfig::fromLaravelConfig()->sampler)->toBe(RateSampler::class);
});

it('falls back to the default rate when the sampler rate is no rate, so the sampler never throws', function (mixed $rate) {
    config()->set('telltale', require __DIR__.'/../config/telltale.php');
    config()->set('telltale.sampler.config.rate', $rate);

    $config = TelltaleConfig::fromLaravelConfig();

    expect($config->samplerConfig)->toBe([]);
    expect(new $config->sampler($config->samplerConfig))->toBeInstanceOf(RateSampler::class);
})->with([
    'a word' => ['often'],
    'above one' => ['1.5'],
    'below zero' => [-1],
]);

it('reads the sampler rate the way an env variable gives it', function () {
    config()->set('telltale', require __DIR__.'/../config/telltale.php');
    config()->set('telltale.sampler.config.rate', '0.5');

    expect(TelltaleConfig::fromLaravelConfig()->samplerConfig)->toBe(['rate' => 0.5]);
});

it('asks the rules an application wrote before the rate of the kind', function () {
    config()->set('telltale', require __DIR__.'/../config/telltale.php');
    config()->set('telltale.sampler', [
        'class' => DynamicSampler::class,
        'config' => [
            'base_rate' => 0,
            'rules' => [
                ['type' => JobSamplingRule::class, 'pattern' => 'App\\Jobs\\TranscribeVoicemail', 'rate' => 0.0],
            ],
        ],
    ]);
    config()->set('telltale.sample_rates.jobs', 1);

    $config = TelltaleConfig::fromLaravelConfig();

    expect($config->sampler)->toBe(DynamicSampler::class);
    expect($config->samplerConfig['rules'])->toHaveCount(2);
    expect($config->samplerConfig['rules'][1])->toBeInstanceOf(EntryPointTypeSamplingRule::class);

    $sampler = new $config->sampler($config->samplerConfig);

    $transcribe = new EntryPoint(EntryPointType::Queue, 'App\\Jobs\\TranscribeVoicemail');
    $transcribe->setHandler('App\\Jobs\\TranscribeVoicemail', null, 'laravel_job');

    $prune = new EntryPoint(EntryPointType::Queue, 'App\\Jobs\\PruneRecordings');
    $prune->setHandler('App\\Jobs\\PruneRecordings', null, 'laravel_job');

    expect($sampler->shouldSample($transcribe))->toBeFalse();
    expect($sampler->shouldSample($prune))->toBeTrue();
});

it('leaves a sampler of the application alone', function () {
    config()->set('telltale', require __DIR__.'/../config/telltale.php');
    config()->set('telltale.sampler', ['class' => AlwaysSampler::class, 'config' => ['anything' => 'goes']]);
    config()->set('telltale.sample_rates.requests', 0.5);

    $config = TelltaleConfig::fromLaravelConfig();

    expect($config->sampler)->toBe(AlwaysSampler::class);
    expect($config->samplerConfig)->toBe(['anything' => 'goes']);
});
