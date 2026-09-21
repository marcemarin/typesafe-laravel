<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Marcemarin\TypeSafe\Contracts\Client;
use Marcemarin\TypeSafe\Exceptions\AuthenticationException;
use Marcemarin\TypeSafe\Facades\TypeSafe;
use Marcemarin\TypeSafe\Questions\Noul;
use Marcemarin\TypeSafe\TypeSafeClient;
use Marcemarin\TypeSafe\TypeSafeServiceProvider;

it('registers a singleton client', function () {
    expect(app(Client::class))->toBeInstanceOf(TypeSafeClient::class)
        ->and(app(Client::class))->toBe(app(Client::class))
        ->and(app('typesafe'))->toBe(app(Client::class));
});

it('ships defaults for every option', function () {
    $config = require __DIR__.'/../../config/typesafe.php';

    expect(array_keys($config))->toBe(['api_key', 'model', 'base_url', 'timeout', 'retries', 'fail_open', 'cache_store'])
        ->and(config('typesafe.model'))->toBe('jev-latest')
        ->and(config('typesafe.base_url'))->toBe('https://api.typesafe.ai')
        ->and(config('typesafe.timeout'))->toBe(20)
        ->and(config('typesafe.retries'))->toBe(3)
        ->and(config('typesafe.fail_open'))->toBeFalse();
});

it('builds the client from config', function () {
    Http::fake(['https://staging.example.test/*' => Http::response(jevBody())]);
    config([
        'typesafe.api_key' => 'another-key',
        'typesafe.base_url' => 'https://staging.example.test',
        'typesafe.model' => 'jev-1.13.0',
        'typesafe.timeout' => '5',
        'typesafe.retries' => '1',
    ]);
    app()->forgetInstance(Client::class);
    TypeSafe::clearResolvedInstances();

    TypeSafe::state('x')->ask('a', Noul::make('A?'))->get();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://staging.example.test/v1/systemone'
        && $request->hasHeader('Authorization', 'Bearer another-key')
        && $request['model'] === 'jev-1.13.0');
});

it('falls back to safe defaults when config values are missing or malformed', function () {
    config(['typesafe' => ['api_key' => 123, 'model' => null, 'base_url' => null, 'timeout' => 'soon', 'retries' => null]]);
    app()->forgetInstance(Client::class);
    Http::fake(['api.typesafe.ai/*' => Http::response(jevBody())]);

    // A non-string key is treated as missing.
    expect(fn () => app(Client::class)->state('x')->ask('a', Noul::make('A?'))->get())
        ->toThrow(AuthenticationException::class);
    expect(app(Client::class)->defaultModel())->toBe('jev-latest');
});

it('uses the configured cache store for cacheFor()', function () {
    config(['cache.stores.typesafe_store' => ['driver' => 'array'], 'typesafe.cache_store' => 'typesafe_store']);
    app()->forgetInstance(Client::class);
    TypeSafe::clearResolvedInstances();
    fakeApi();

    TypeSafe::state('same')->ask('a', Noul::make('A?'))->cacheFor(60)->get();
    $second = TypeSafe::state('same')->ask('a', Noul::make('A?'))->cacheFor(60)->get();

    expect($second->cached)->toBeTrue()
        ->and(Cache::store('typesafe_store')->get('typesafe:'.TypeSafe::state('same')->ask('a', Noul::make('A?'))->request()->fingerprint()))->toBeArray()
        ->and(Cache::store('array')->get('typesafe:'.TypeSafe::state('same')->ask('a', Noul::make('A?'))->request()->fingerprint()))->toBeNull();
});

it('publishes the config and the translations', function () {
    $paths = TypeSafeServiceProvider::pathsToPublish(TypeSafeServiceProvider::class);

    expect(array_map('realpath', array_keys($paths)))->toContain(realpath(__DIR__.'/../../config/typesafe.php'))
        ->and(TypeSafeServiceProvider::pathsToPublish(TypeSafeServiceProvider::class, 'typesafe-config'))->toHaveCount(1)
        ->and(TypeSafeServiceProvider::pathsToPublish(TypeSafeServiceProvider::class, 'typesafe-lang'))->toHaveCount(1);
});

it('loads the packaged translations under the typesafe namespace', function () {
    expect(trans('typesafe::validation.flagged'))->toContain(':attribute')
        ->and(trans('typesafe::validation.unavailable'))->not->toBe('typesafe::validation.unavailable');
});

it('registers the TypeSafe alias for auto-discovery', function () {
    $composer = json_decode((string) file_get_contents(__DIR__.'/../../composer.json'), true);

    expect($composer['extra']['laravel']['providers'])->toBe([TypeSafeServiceProvider::class])
        ->and($composer['extra']['laravel']['aliases'])->toBe(['TypeSafe' => TypeSafe::class]);
});
