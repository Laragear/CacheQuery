<?php

namespace Tests;

use Carbon\CarbonInterval;
use InvalidArgumentException;
use Laragear\CacheQuery\Cache;
use PHPUnit\Framework\TestCase as BaseTestCase;
use function now;

class CacheTest extends BaseTestCase
{
    public function test_sets_ttl_as_minute_as_default(): void
    {
        $cache = new Cache();

        static::assertSame(60, $cache->ttl);
    }

    public function test_uses_ttl_as_integer(): void
    {
        $cache = Cache::for(30);

        static::assertSame(30, $cache->ttl);
    }

    public function test_uses_ttl_as_datetime_interface(): void
    {
        $datetime = now();

        $cache = Cache::for($datetime);

        static::assertSame($datetime, $cache->ttl);
    }

    public function test_uses_ttl_as_date_interval(): void
    {
        $interval = new CarbonInterval();

        $cache = Cache::for($interval);

        static::assertSame($interval, $cache->ttl);
    }

    public function test_uses_ttl_as_numeric_string_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The $ttl argument can only be "ever" or "forever" or "null".');

        Cache::for('10');
    }

    public function test_uses_ttl_as_ever_string(): void
    {
        $cache = Cache::for('ever');

        static::assertSame(null, $cache->ttl);
    }

    public function test_uses_ttl_as_forever_string(): void
    {
        $cache = Cache::for('forever');

        static::assertSame(null, $cache->ttl);
    }

    public function test_uses_ttl_as_array(): void
    {
        $cache = Cache::for([100, 200]);

        static::assertSame([100, 200], $cache->ttl);
    }

    public function test_uses_ttl_as_null(): void
    {
        $cache = Cache::for(null);

        static::assertNull($cache->ttl);
    }

    public function test_sets_store(): void
    {
        $cache = Cache::for(60);

        static::assertNull($cache->store);

        $cache->store('test');

        static::assertSame('test', $cache->store);
    }

    public function test_except_empty(): void
    {
        $cache = Cache::for(50);

        static::assertTrue($cache->saveEmptyResults);

        $cache->exceptEmpty();

        static::assertFalse($cache->saveEmptyResults);
    }

    public function test_except_nested(): void
    {
        $cache = Cache::for(60);

        static::assertTrue($cache->saveNestedQueries);

        $cache->exceptNested();

        static::assertFalse($cache->saveNestedQueries);
    }

    public function test_flexible(): void
    {
        $cache = Cache::flexible(20, 30);

        static::assertSame([20, 30], $cache->ttl);
        static::assertNull($cache->lock);
    }

    public function test_flexible_with_lock(): void
    {
        $cache = Cache::flexible(20, 30, [60, 'owner_test']);

        static::assertSame([20, 30], $cache->ttl);
        static::assertSame([60, 'owner_test'], $cache->lock);
    }

    public function test_regen_when(): void
    {
        $cache = Cache::for(60);

        $cache->regenWhen($condition = fn() => true);

        static::assertTrue($cache->regenFactor);
        static::assertSame($condition, $cache->regenerate);
    }

    public function test_regen_if(): void
    {
        $cache = Cache::for(60);

        $cache->regenIf($condition = fn() => true);

        static::assertTrue($cache->regenFactor);
        static::assertSame($condition, $cache->regenerate);
    }

    public function test_regen_unless(): void
    {
        $cache = Cache::for(60);

        $cache->regenUnless($condition = fn() => false);

        static::assertFalse($cache->regenFactor);
        static::assertSame($condition, $cache->regenerate);
    }

    public function test_as(): void
    {
        $cache = Cache::for(60);

        static::assertEmpty($cache->key);

        $cache->as('test');

        static::assertSame('test', $cache->key);
    }

    public function test_ever(): void
    {
        $cache = Cache::for(60);

        $cache->ever();

        static::assertNull($cache->ttl);
    }

    public function test_ttl(): void
    {
        $cache = Cache::for(60);

        $cache->ttl(30);

        static::assertSame(30, $cache->ttl);
    }

    public function test_until(): void
    {
        $cache = Cache::for(60);

        $cache->until(30);

        static::assertSame(30, $cache->ttl);
    }
}
