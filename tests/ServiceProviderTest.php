<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\ServiceProvider;
use Laragear\CacheQuery\CacheQueryServiceProvider;

class ServiceProviderTest extends TestCase
{
    public function test_merges_config(): void
    {
        static::assertSame(
            $this->app->make('files')->getRequire(CacheQueryServiceProvider::CONFIG),
            $this->app->make('config')->get('cache-query')
        );
    }

    public function test_registers_macro(): void
    {
        static::assertTrue(Builder::hasMacro('cache'));
    }

    public function test_publishes_config(): void
    {
        static::assertSame(
            [CacheQueryServiceProvider::CONFIG => $this->app->configPath('cache-query.php')],
            ServiceProvider::pathsToPublish(CacheQueryServiceProvider::class, 'config')
        );
    }

    public function test_publishes_stub(): void
    {
        static::assertSame(
            [CacheQueryServiceProvider::STUBS => $this->app->basePath('.stubs/cache-query.php')],
            ServiceProvider::pathsToPublish(CacheQueryServiceProvider::class, 'phpstorm')
        );
    }

    public function test_registers_command(): void
    {
        static::assertArrayHasKey('cache-query:forget', $this->app->make(Kernel::class)->all());
    }
}
