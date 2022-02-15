<?php

namespace Tests;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
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

    public function test_registers_macros(): void
    {
        static::assertTrue(Builder::hasMacro('cache'));
        static::assertTrue(EloquentBuilder::hasGlobalMacro('cache'));
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
}
