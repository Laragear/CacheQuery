<?php

namespace Tests;

use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Collection;
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
        $repository
            ->expects('getMultiple')
            ->with(['cache-query|foo'])
            ->andReturn(['cache-query|foo' => ['bar', 'baz']]);
        $repository->expects('deleteMultiple')
            ->withArgs(function (Collection $queries): bool {
                static::assertSame(['bar', 'baz', 'cache-query|foo'], $queries->all());

                return true;
            })
            ->andReturnTrue();

        $this->mock(Factory::class)->allows('store')->with('foo')->andReturn($repository);

        static::assertTrue(CacheQuery::store('foo')->forget('foo'));
    }
}
