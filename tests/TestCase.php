<?php

declare(strict_types=1);

namespace Marcemarin\TypeSafe\Tests;

use Marcemarin\TypeSafe\Facades\TypeSafe;
use Marcemarin\TypeSafe\TypeSafeServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [TypeSafeServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['TypeSafe' => TypeSafe::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('typesafe.api_key', 'test-key-not-a-secret');
        $app['config']->set('cache.default', 'array');
    }
}
