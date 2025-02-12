<?php

namespace Laragear\CacheQuery;

use DateInterval;
use DateTimeInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;

use function in_array;
use function is_numeric;
use function is_string;

class Cache
{
    /**
     * The condition factor.
     *
     * @internal
     */
    public bool $regenFactor = true;

    /**
     * If it should cache empty results or not.
     *
     * @internal
     */
    public bool $saveEmptyResults = true;

    /**
     * If it should cache nested Eloquent Queries.
     *
     * @internal
     */
    public bool $saveNestedQueries = true;

    /**
     * Which cache store to keep the cached results.
     *
     * @internal
     */
    public ?string $store = null;

    /**
     * The lock configuration to use when the caching is flexible (stale revalidation).
     *
     * @internal
     */
    public ?array $lock = null;

    /**
     * If the results should be regenerated, instead of retrieving it from the cache.
     *
     * @internal
     */
    public mixed $regenerate = false;

    /**
     * The cache key that should be used to store the results.
     *
     * @internal
     */
    public string $key = '';

    /**
     * Create a new Cache store.
     *
     * @param  \DateTimeInterface|\DateInterval|int|array{ 0: \DateTimeInterface|\DateInterval|int, 1: \DateTimeInterface|\DateInterval|int }|string|null  $ttl
     *
     * @internal
     */
    public function __construct(public DateTimeInterface|DateInterval|array|int|string|null $ttl = 60)
    {
        $this->ttl($ttl);
    }

    /**
     * Sets the custom store to use to save the query results.
     *
     * @return $this
     */
    public function store(?string $store): static
    {
        $this->store = $store;

        return $this;
    }

    /**
     * Only save the cache results if these are not empty or null.
     *
     * @return $this
     */
    public function exceptEmpty(): static
    {
        $this->saveEmptyResults = false;

        return $this;
    }

    /**
     * Only save the cache results for the query that invokes "cache()".
     *
     * @return $this
     */
    public function exceptNested(): static
    {
        $this->saveNestedQueries = false;

        return $this;
    }

    /**
     * Regenerate the results of the query if the condition is truthy.
     *
     * @param  (\Closure(\Illuminate\Database\Eloquent\Builder):mixed)|mixed  $condition
     * @return $this
     */
    public function regenWhen(mixed $condition): static
    {
        $this->regenerate = $condition;

        return $this;
    }

    /**
     * Regenerate the results of the query if the condition is truthy.
     *
     * @param  (\Closure(\Illuminate\Database\Eloquent\Builder):mixed)|mixed  $condition
     * @return $this
     */
    public function regenIf(mixed $condition): static
    {
        return $this->regenWhen($condition);
    }

    /**
     * Regenerate the results of the query if the condition is falsy.
     *
     * @param  (\Closure(\Illuminate\Database\Eloquent\Builder):mixed)|mixed  $condition
     * @return $this
     */
    public function regenUnless(mixed $condition): static
    {
        $this->regenFactor = false;

        return $this->regenWhen($condition);
    }

    /**
     * Sets the key for the results of this query, so these can be forgotten later.
     *
     * @return $this
     */
    public function as(string $key): static
    {
        $this->key = $key;

        return $this;
    }

    /**
     * Stores the cached results forever.
     *
     * @return $this
     */
    public function ever(): static
    {
        $this->ttl = null;

        return $this;
    }

    /**
     * Stores the cached results until a given amount of seconds or datetime.
     *
     * @param  \DateTimeInterface|\DateInterval|int|array{ 0: \DateTimeInterface|\DateInterval|int, 1: \DateTimeInterface|\DateInterval|int }|string|null  $ttl
     * @return $this
     */
    public function ttl(DateTimeInterface|DateInterval|int|array|null|string $ttl): static
    {
        if (is_string($ttl)) {
            $ttl = in_array(Str::lower($ttl), ['ever', 'forever', 'null'], true)
                ? null
                : throw new InvalidArgumentException('The $ttl argument can only be "ever" or "forever" or "null".');
        }

        if (is_numeric($ttl)) {
            $ttl = (int) $ttl;
        }

        $this->ttl = $ttl;

        return $this;
    }

    /**
     * Stores the cached results until a given amount of seconds or datetime.
     *
     * @param  \DateTimeInterface|\DateInterval|int|array{ 0: \DateTimeInterface|\DateInterval|int, 1: \DateTimeInterface|\DateInterval|int }|string|null  $ttl
     * @return $this
     */
    public function until(DateTimeInterface|DateInterval|int|array|null|string $ttl): static
    {
        return $this->ttl($ttl);
    }

    /**
     * Create a new Cache instance.
     *
     * @param  \DateTimeInterface|\DateInterval|int|array{ 0: \DateTimeInterface|\DateInterval|int, 1: \DateTimeInterface|\DateInterval|int }|string|null  $ttl
     */
    public static function for(DateTimeInterface|DateInterval|int|array|null|string $ttl): static
    {
        return new static($ttl);
    }

    /**
     * Regenerates the cached results before a specific amount of seconds before the data dies.
     *
     * @param  array{ seconds?: int, owner?: string }|null  $lock
     */
    public static function flexible(int $seconds, int $stale, ?array $lock = null): static
    {
        $instance = new static([$seconds, $stale]);
        $instance->lock = $lock;

        return $instance;
    }
}
