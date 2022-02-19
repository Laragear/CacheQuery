<?php

namespace Tests\Console\Commands\CacheQuery;

use Laragear\CacheQuery\CacheQuery;
use Tests\TestCase;

class ForgetTest extends TestCase
{
    public function test_removes_key_from_default_cache_store(): void
    {
        $cacheQuery = $this->mock(CacheQuery::class);
        $cacheQuery->expects('store')->with('array')->andReturnSelf();
        $cacheQuery->expects('forget')->with('foo')->andReturnTrue();

        $this->artisan('cache-query:forget foo')
            ->assertSuccessful()
            ->expectsOutput('Successfully removed [foo] from the [array] cache store.');
    }

    public function test_removes_key_from_named_cache_store(): void
    {
        $cacheQuery = $this->mock(CacheQuery::class);
        $cacheQuery->expects('store')->with('bar')->andReturnSelf();
        $cacheQuery->expects('forget')->with('foo')->andReturnTrue();

        $this->artisan('cache-query:forget foo --store=bar')
            ->assertSuccessful()
            ->expectsOutput('Successfully removed [foo] from the [bar] cache store.');
    }

    public function test_removes_key_from_config_default_cache_store(): void
    {
        $this->app->make('config')->set('cache-query.store', 'bar');

        $cacheQuery = $this->mock(CacheQuery::class);
        $cacheQuery->expects('store')->with('bar')->andReturnSelf();
        $cacheQuery->expects('forget')->with('foo')->andReturnTrue();

        $this->artisan('cache-query:forget foo')
            ->assertSuccessful()
            ->expectsOutput('Successfully removed [foo] from the [bar] cache store.');
    }

    public function test_warns_key_not_found_in_cache_store(): void
    {
        $cacheQuery = $this->mock(CacheQuery::class);
        $cacheQuery->expects('store')->with('array')->andReturnSelf();
        $cacheQuery->expects('forget')->with('foo')->andReturnFalse();

        $this->artisan('cache-query:forget foo')
            ->assertSuccessful()
            ->expectsOutput('The [foo] was not found in [array] cache store.');
    }
}
