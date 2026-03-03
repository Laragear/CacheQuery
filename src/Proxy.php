<?php

namespace Laragear\CacheQuery;

use Closure;
use DateInterval;
use DateTimeInterface;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

use function app;
use function array_filter;
use function array_shift;
use function is_array;
use function max;
use function method_exists;
use function value;

/**
 * @internal
 */
class Proxy extends Connection
{
    /**
     * The Query Hasher closure.
     *
     * @var (\Closure(\Illuminate\Database\ConnectionInterface, string, array): string)
     */
    public static Closure $queryHasher;

    /**
     * Create a new Cache Aware Connection Proxy instance.
     *
     * @noinspection PhpMissingParentConstructorInspection
     */
    public function __construct(
        public ConnectionInterface $connection,
        protected Repository $repository,
        protected Cache $cache,
        protected string $cachePrefix,
        public string $computedKey = '',
        public string $queryKeySuffix = '',
    ) {
        if ($this->cache->key) {
            $this->cache->key = Str::start($this->cache->key, $this->cachePrefix.'|');
        }
    }

    /**
     * Returns the Cache Helper instance.
     *
     * @return \Laragear\CacheQuery\Cache
     */
    public function getCacheHelperInstance(): Cache
    {
        return $this->cache;
    }

    /**
     * Run a select statement against the database.
     *
     * @param  string  $query
     * @param  array  $bindings
     * @param  bool  $useReadPdo
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
    {
        // Create the unique hash for the query to avoid any duplicate query.
        $this->computedKey = (static::$queryHasher)($this->connection, $query, $bindings);

        // We will append the previous related query to the computed key.
        if ($this->queryKeySuffix) {
            $this->computedKey = $this->queryKeySuffix.'.'.$this->computedKey;
        }

        // We will use the prefix to operate on the cache directly.
        $key = $this->cachePrefix.'|'.$this->computedKey;

        // If the user is setting an array, we will steer return the results using "flexible".
        if (is_array($this->cache->ttl) && method_exists($this->repository, 'flexible')) {
            return $this->retrieveFlexibleResults($query, $key, $bindings, $useReadPdo);
        }

        return $this->retrieveResults($query, $key, $bindings, $useReadPdo);
    }

    /**
     * Run a select statement and return a single result.
     *
     * @param  string  $query
     * @param  array  $bindings
     * @param  bool  $useReadPdo
     * @return mixed
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function selectOne($query, $bindings = [], $useReadPdo = true): mixed
    {
        $records = $this->select($query, $bindings, $useReadPdo);

        return array_shift($records);
    }

    /**
     * Returns the results of the query using stale revalidation.
     */
    protected function retrieveFlexibleResults(string $query, string $key, array $bindings, bool $useReadPdo): mixed
    {
        // @phpstan-ignore-next-line
        return $this->repository->flexible(
            $key,
            $this->cache->ttl,
            function () use ($query, $bindings, $key, $useReadPdo): array {
                return $this->retrieveResults($query, $key, $bindings, $useReadPdo);
            },
            $this->cache->lock,
        );
    }

    /**
     * Retrieves the results normally from the cache store.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    protected function retrieveResults(string $query, string $key, array $bindings, bool $useReadPdo): array
    {
        [$key => $results, $this->cache->key => $list] = $this->retrieveResultsFromCache($key);

        // If there are no results for the cache, retrieve the results from the database.
        if ($results === null) {
            $results = $this->connection->select($query, $bindings, $useReadPdo);

            // If the results are empty, we will NOT save it if the developer instructed so.
            if ($results !== [] || $this->cache->saveEmptyResults) {
                $this->repository->put($key, $results, $this->cache->ttl);

                // If the user added a user key, we will append this computed key to it and save it.
                if ($this->cache->key) {
                    $this->addComputedKeyToUserKey($key, $list);
                }
            }
        }

        return $results;
    }

    /**
     * Retrieve the results from the cache.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    protected function retrieveResultsFromCache(string $key): array
    {
        // If the cache should be regenerated, just return empty results.
        if ($this->cache->regenFactor === (bool) value($this->cache->regenerate)) {
            return [$key => null, $this->cache->key => null];
        }

        $result = $this->repository->getMultiple(array_filter([$key, $this->cache->key]));

        $result[$this->cache->key] ??= null;

        return $result;
    }

    /**
     * Adds the computed key to the user key queries list.
     */
    protected function addComputedKeyToUserKey(string $key, ?array $list): void
    {
        $list['list'][] = $key;

        if ($this->cache->ttl === null) {
            $list['expires_at'] = 'never';
        }

        $list['expires_at'] ??= $this->getTimestamp($this->cache->ttl);

        if ($list['expires_at'] === 'never') {
            $this->repository->forever($this->cache->key, $list);
        } else {
            $list['expires_at'] = max($this->getTimestamp($this->cache->ttl), $list['expires_at']);
            $this->repository->put($this->cache->key, $list, $this->cache->ttl);
        }
    }

    /**
     * Gets the timestamp for the expiration time.
     */
    protected function getTimestamp(DateInterval|DateTimeInterface|array|int $expiration): int
    {
        if (is_array($expiration)) {
            $expiration = $expiration[1];
        }

        if ($expiration instanceof DateTimeInterface) {
            return $expiration->getTimestamp();
        }

        if ($expiration instanceof DateInterval) {
            return now()->add($expiration)->getTimestamp();
        }

        return now()->addSeconds($expiration)->getTimestamp();
    }

    /**
     * Pass-through all properties to the underlying connection.
     */
    public function __get(string $name): mixed
    {
        return $this->connection->{$name};
    }

    /**
     * Pass-through all properties to the underlying connection.
     */
    public function __set(string $name, mixed $value): void
    {
        $this->connection->{$name} = $value;
    }

    /**
     * Pass-through all method calls to the underlying connection.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return mixed
     *
     * @codeCoverageIgnore
     */
    public function __call($method, $parameters)
    {
        return $this->connection->{$method}(...$parameters);
    }

    /**
     * Create a new CacheAwareProxy instance.
     */
    public static function crateNewInstance(ConnectionInterface $connection, Cache $cache): static
    {
        $config = app('config');

        // @phpstan-ignore-next-line
        return new static(
            $connection,
            app('cache')->store($cache->store ?? $config->get('cache-query.store')),
            $cache,
            $config->get('cache-query.prefix')
        );
    }
}
