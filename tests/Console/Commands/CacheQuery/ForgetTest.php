<?php

namespace Tests\Console\Commands\CacheQuery;

use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\Repository;
use Tests\TestCase;

class ForgetTest extends TestCase
{
    public function test_removes_key_from_default_cache_store(): void
    {
        $this->app->make('cache')->store()->put('cache-query|foo', true);

        $this->artisan('cache-query:forget foo')
            ->assertSuccessful()
            ->expectsOutput('Successfully removed [foo] from the [array] cache store.');
    }

    public function test_removes_key_from_named_cache_store(): void
    {
        $repository = $this->mock(Repository::class);

        $repository->expects('forget')->with('cache-query|foo')->andReturnTrue();

        $this->mock(Factory::class)->expects('store')->with('bar')->andReturn($repository);

        $this->artisan('cache-query:forget foo --store=bar')
            ->assertSuccessful()
            ->expectsOutput('Successfully removed [foo] from the [bar] cache store.');
    }

    public function test_removes_key_from_config_default_cache_store(): void
    {
        $this->app->make('config')->set('cache-query.store', 'bar');

        $repository = $this->mock(Repository::class);

        $repository->expects('forget')->with('cache-query|foo')->andReturnTrue();

        $this->mock(Factory::class)->expects('store')->with('bar')->andReturn($repository);

        $this->artisan('cache-query:forget foo')
            ->assertSuccessful()
            ->expectsOutput('Successfully removed [foo] from the [bar] cache store.');
    }

    public function test_warns_key_not_found_in_cache_store(): void
    {
        $this->artisan('cache-query:forget foo')
            ->assertSuccessful()
            ->expectsOutput('The [foo] was not found in [array] cache store.');
    }
}
