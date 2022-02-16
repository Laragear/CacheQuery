<?php

namespace Laragear\CacheQuery;

use DateInterval;
use DateTimeInterface;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Traits\ForwardsCalls;
use LogicException;

/**
 * This class wraps the Query Builder and should not be used externally.
 *
 * @internal
 */
class CacheAwareProxy implements Builder
{
    use ForwardsCalls;

    /**
     * Create a new Cache Aware Proxy.
     *
     * @param  \Illuminate\Contracts\Database\Query\Builder  $queryBuilder
     * @param  \Illuminate\Contracts\Cache\Repository  $cacheStore
     * @param  string  $cacheKey
     * @param  \DateTimeInterface|\DateInterval|int  $ttl
     * @param  int  $lockWait
     * @param  bool  $executingCallback
     * @param  \Illuminate\Contracts\Cache\Lock|null  $lockInstance
     * @param  bool  $bypassCacheCheck
     */
    public function __construct(
        protected Builder $queryBuilder,
        protected Repository $cacheStore,
        protected string $cacheKey,
        protected DateTimeInterface|DateInterval|int $ttl,
        protected int $lockWait,
        protected bool $executingCallback = false,
        protected ?Lock $lockInstance = null,
        protected bool $bypassCacheCheck = false,
    ) {
        // This callback interrupts the query execution on cache hit.
        $this->queryBuilder->beforeQuery(function (): void {
            // We will avoid looping this callback if it's executing.
            if ($this->executingCallback || $this->bypassCacheCheck) {
                return;
            }

            $this->executingCallback = true;

            $key = Helpers::cacheKey($this->queryBuilder, $this->cacheKey);

            // If there is another process locking this cache procedure, we will
            // wait the same amount of time until its clear. If we couldn't get
            // the lock, let the timeout exception go so the dev may catch it.
            if ($this->lockWait) {
                $this->lockInstance = $this->cacheStore->getStore()->lock($key, $this->lockWait);

                $this->lockInstance->block($this->lockWait);
            }

            // With or without lock, the cache should be populated by now.
            if ($this->cacheStore->has($key)) {
                throw new Exceptions\CacheKeyFound($this->cacheStore->get($key));
            }
        });
    }

    /**
     * Pass through all properties to the underlying builder instance.
     *
     * @param  string  $name
     * @return mixed
     */
    public function __get(string $name): mixed
    {
        return $this->queryBuilder->{$name};
    }

    /**
     * Pass through all properties to the underlying builder instance.
     *
     * @param  string  $name
     * @param  mixed  $value
     * @return void
     */
    public function __set(string $name, mixed $value): void
    {
        $this->queryBuilder->{$name} = $value;
    }

    /**
     * Pass through all methods to the underlying Query Builder.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return $this|mixed
     */
    public function __call(string $method, array $parameters)
    {
        $this->bypassCacheCheck = $method === 'toSql';

        if ($method === 'cache') {
            throw new LogicException('This builder instance is already wrapped into a cache proxy.');
        }

        try {
            $results = $this->forwardCallTo($this->queryBuilder, $method, $parameters);
        } catch (Exceptions\CacheKeyFound $e) {
            $this->lockInstance?->release();

            return $e->results;
        }

        // If the user returns a Builder, then it means we can keep building.
        if ($results instanceof Builder) {
            return $this;
        }

        // If the callback was executed, it was sent to the database, so check results.
        if ($this->executingCallback) {
            // If the user didn't return a builder, cache and store the results.
            $this->cacheStore->put(Helpers::cacheKey($this->queryBuilder, $this->cacheKey), $results, $this->ttl);

            // If there is a lock blocked, we will release it now.
            $this->lockInstance?->release();
        }

        return $results;
    }
}
