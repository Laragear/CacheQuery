<?php

namespace Laragear\CacheQuery;

use DateInterval;
use DateTimeInterface;
use Illuminate\Cache\NoLock;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use LogicException;

use function array_shift;
use function base64_encode;
use function cache;
use function config;
use function implode;
use function is_int;
use function max;
use function md5;
use function rtrim;

class CacheAwareConnectionProxy extends Connection
{
    /**
     * Create a new Cache Aware Connection Proxy instance.
     *
     * @noinspection PhpMissingParentConstructorInspection
     */
    public function __construct(
        public ConnectionInterface $connection,
        protected Repository $repository,
        protected DateTimeInterface|DateInterval|int|null $ttl,
        protected int $lockWait,
        protected string $cachePrefix,
        public string $userKey = '',
        public string $computedKey = '',
        public string $queryKeySuffix = '',
    ) {
        if ($this->userKey) {
            $this->userKey = $this->cachePrefix.'|'.$this->userKey;
        }
    }

    /**
     * Run a select statement against the database.
     *
     * @param  string  $query
     * @param  array  $bindings
     * @param  bool  $useReadPdo
     * @return mixed
     */
    public function select($query, $bindings = [], $useReadPdo = true)
    {
        // Create the unique hash for the query to avoid any duplicate query.
        $this->computedKey = $this->getQueryHash($query, $bindings);

        // We will append the previous related query to the computed key.
        if ($this->queryKeySuffix) {
            $this->computedKey = $this->queryKeySuffix.'.'.$this->computedKey;
        }

        // We will use the prefix to operate on the cache directly.
        $key = $this->cachePrefix.'|'.$this->computedKey;

        return $this
            ->retrieveLock($key)
            ->block($this->lockWait, function () use ($query, $bindings, $useReadPdo, $key): array {
                [$key => $results, $this->userKey => $list] = $this->retrieveResultsFromCache($key);

                if ($results === null) {
                    $results = $this->connection->select($query, $bindings, $useReadPdo);

                    if ($this->ttl === null) {
                        $this->repository->forever($key, $results);
                    } else {
                        $this->repository->put($key, $results, $this->ttl);
                    }

                    // If the user added a user key, we will append this computed key to it and save it.
                    if ($this->userKey) {
                        $this->addComputedKeyToUserKey($key, $list);
                    }
                }

                return $results;
            });
    }

    /**
     * Run a select statement and return a single result.
     *
     * @param  string  $query
     * @param  array  $bindings
     * @param  bool  $useReadPdo
     * @return mixed
     */
    public function selectOne($query, $bindings = [], $useReadPdo = true)
    {
        $records = $this->select($query, $bindings, $useReadPdo);

        return array_shift($records);
    }

    /**
     * Hashes the incoming query for using as cache key.
     */
    protected function getQueryHash(string $query, array $bindings): string
    {
        return rtrim(base64_encode(md5($this->connection->getDatabaseName().$query.implode('', $bindings), true)), '=');
    }

    /**
     * Retrieves the lock to use before getting the results.
     */
    protected function retrieveLock(string $key): Lock
    {
        if (! $this->lockWait) {
            return new NoLock($key, $this->lockWait);
        }

        // @phpstan-ignore-next-line
        return $this->repository->getStore()->lock($key, $this->lockWait);
    }

    /**
     * Retrieve the results from the cache.
     */
    protected function retrieveResultsFromCache(string $key): array
    {
        // If the ttl is negative, regenerate the results.
        if (is_int($this->ttl) && $this->ttl < 1) {
            return [$key => null, $this->userKey => null];
        }

        return $this->repository->getMultiple([$key, $this->userKey]);
    }

    /**
     * Adds the computed key to the user key queries list.
     */
    protected function addComputedKeyToUserKey(string $key, ?array $list): void
    {
        $list['list'][] = $key;

        if ($this->ttl === null) {
            $list['expires_at'] = 'never';
        }

        $list['expires_at'] ??= $this->getTimestamp($this->ttl);

        if ($list['expires_at'] === 'never') {
            $this->repository->forever($this->userKey, $list);

            return;
        }

        $list['expires_at'] = max($this->getTimestamp($this->ttl), $list['expires_at']);

        $this->repository->put($this->userKey, $list, $this->ttl);
    }

    /**
     * Gets the timestamp for the expiration time.
     */
    protected function getTimestamp(DateInterval|DateTimeInterface|int $expiration): int
    {
        if ($expiration instanceof DateTimeInterface) {
            return $expiration->getTimestamp();
        }

        if ($expiration instanceof DateInterval) {
            return now()->add($expiration)->getTimestamp();
        }

        return now()->addRealSeconds($expiration)->getTimestamp();
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
     * @param  array  $arguments
     * @return mixed
     */
    public function __call($method, $arguments)
    {
        return $this->connection->{$method}(...$arguments);
    }

    /**
     * Create a new CacheAwareProxy instance.
     */
    public static function crateNewInstance(
        ConnectionInterface $connection,
        DateTimeInterface|DateInterval|int|null $ttl,
        string $key,
        int $wait,
        ?string $store,
    ): static {
        // @phpstan-ignore-next-line
        return new static(
            $connection,
            static::store($store, (bool) $wait),
            $ttl,
            $wait,
            config('cache-query.prefix'),
            $key
        );
    }

    /**
     * Returns the store for caching.
     */
    protected static function store(?string $store, bool $lockable): Repository
    {
        $repository = cache()->store($store ?? config('cache-query.store'));

        if ($lockable && ! $repository->getStore() instanceof LockProvider) {
            $store ??= cache()->getDefaultDriver();

            throw new LogicException("The [$store] cache does not support atomic locks.");
        }

        return $repository;
    }
}
