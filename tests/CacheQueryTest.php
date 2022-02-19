<?php

namespace Tests;

use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\Repository;
use Laragear\CacheQuery\Facades\CacheQuery;

class CacheQueryTest extends TestCase
{
    public function test_forgets_result(): void
    {
        $this->app->make('cache')->putMany([
            'cache-query|foo' => ['cache-query|bar', 'cache-query|baz'],
            'cache-query|bar' => true,
            'cache-query|baz' => true,
        ]);

        static::assertTrue(CacheQuery::forget('foo'));

        static::assertFalse($this->app->make('cache')->has('cache-query|foo'));
        static::assertFalse($this->app->make('cache')->has('cache-query|bar'));
        static::assertFalse($this->app->make('cache')->has('cache-query|baz'));

        static::assertFalse(CacheQuery::forget('foo'));
    }

    public function test_uses_given_store(): void
    {
        $repository = $this->mock(Repository::class);
        $repository->expects('get')->with('cache-query|foo', [])->andReturn(['bar', 'baz']);
        $repository->expects('deleteMultiple')->with(['cache-query|foo', 'bar', 'baz'])->andReturnTrue();

        $this->mock(Factory::class)->allows('store')->with('foo')->andReturn($repository);

        static::assertTrue(CacheQuery::store('foo')->forget('foo'));
    }
}
