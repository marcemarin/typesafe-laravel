<?php

declare(strict_types=1);

namespace Marcemarin\TypeSafe;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use Marcemarin\TypeSafe\Contracts\Client;

final class TypeSafeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/typesafe.php', 'typesafe');

        $this->app->singleton(Client::class, static function (Application $app): TypeSafeClient {
            /** @var array<string, mixed> $config */
            $config = $app->make('config')->get('typesafe', []);
            $store = $config['cache_store'] ?? null;

            return new TypeSafeClient(
                apiKey: is_string($config['api_key'] ?? null) ? $config['api_key'] : null,
                model: is_string($config['model'] ?? null) ? $config['model'] : 'jev-latest',
                baseUrl: is_string($config['base_url'] ?? null) ? $config['base_url'] : 'https://api.typesafe.ai',
                timeout: is_numeric($config['timeout'] ?? null) ? (int) $config['timeout'] : 20,
                retries: is_numeric($config['retries'] ?? null) ? (int) $config['retries'] : 3,
                http: $app->make(HttpFactory::class),
                cache: $app->make(CacheFactory::class)->store(is_string($store) ? $store : null),
            );
        });

        $this->app->alias(Client::class, 'typesafe');
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'typesafe');

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/typesafe.php' => $this->app->configPath('typesafe.php')], 'typesafe-config');
            $this->publishes([__DIR__.'/../resources/lang' => $this->app->langPath('vendor/typesafe')], 'typesafe-lang');
        }
    }
}
