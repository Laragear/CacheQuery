<?php

namespace Laragear\CacheQuery;

use DateInterval;
use DateTimeInterface;
use Illuminate\Cache\NoLock;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\ConnectionInterface;
use LogicException;
use function array_shift;
use function base64_encode;
use function cache;
use function config;
use function implode;
use function md5;

class CacheAwareConnectionProxy
{
    /**
     * Create a new Cache Aware Connection Proxy instance.
     *
     * @param  \Illuminate\Database\ConnectionInterface  $connection
     * @param  \Illuminate\Contracts\Cache\Repository  $repository
     * @param  string  $cachePrefix
     * @param  string  $queryKey
     * @param  \DateTimeInterface|\DateInterval|int  $ttl
     * @param  int  $lockWait
     */
    public function __construct(
        public ConnectionInterface $connection,
        protected Repository $repository,
        protected string $cachePrefix,
        protected string $queryKey,
        protected DateTimeInterface|DateInterval|int $ttl,
        protected int $lockWait,
    ) {
        //
    }

    /**
     * Run a select statement against the database.
     *
     * @param  string  $query
     * @param  array  $bindings
     * @param  bool  $useReadPdo
     * @return array
     */
    public function select($query, $bindings = [], $useReadPdo = true): mixed
    {
        $key = $this->cachePrefix.'|'.($this->queryKey ?: $this->getQueryHash($query, $bindings));

        [$cached, $results] = $this->checkForCachedResult($key);

        if ($cached) {
            return $results;
        }

        $results = $this->connection->select($query, $bindings, $useReadPdo);

        $this->repository->put($key, $results, $this->ttl);

        return $results;
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
     * Checks if the result exists in the cache, and returns in.
     *
     * @param  string  $key
     * @return mixed
     */
    protected function checkForCachedResult(string $key): mixed
    {
        return $this->retrieveLock($key)->block($this->lockWait, function () use ($key) {
            $has = $this->repository->has($key);

            return [$has, $has ? $this->repository->get($key) : null];
        });
    }

    /**
     * Hashes the incoming query for using as cache key.
     *
     * @param  string  $query
     * @param  array  $bindings
     * @return string
     */
    protected function getQueryHash(string $query, array $bindings): string
    {
        return base64_encode(md5($this->connection->getDatabaseName().$query.implode('', $bindings), true));
    }

    /**
     * Retrieves the lock to use before getting the results.
     *
     * @param  string  $key
     * @return \Illuminate\Contracts\Cache\Lock
     */
    protected function retrieveLock(string $key): Lock
    {
        if (!$this->lockWait) {
            return new NoLock($key, $this->lockWait);
        }

        return $this->repository->getStore()->lock($key, $this->lockWait);
    }

    /**
     * Pass-through all properties to the underlying connection.
     *
     * @param  string  $name
     * @return mixed
     */
    public function __get(string $name): mixed
    {
        return $this->connection->{$name};
    }

    /**
     * Pass-through all properties to the underlying connection.
     *
     * @param  string  $name
     * @param  mixed  $value
     * @return void
     * @noinspection MagicMethodsValidityInspection
     */
    public function __set(string $name, mixed $value): void
    {
        $this->connection->{$name} = $value;
    }

    /**
     * Pass-through all method calls to the underlying connection.
     *
     * @param  string  $name
     * @param  array  $arguments
     * @return mixed
     */
    public function __call(string $name, array $arguments)
    {
        return $this->connection->{$name}(...$arguments);
    }

    /**
     * Create a new CacheAwareProxy instance.
     *
     * @param  \Illuminate\Database\ConnectionInterface  $connection
     * @param  \DateTimeInterface|\DateInterval|int  $ttl
     * @param  string  $key
     * @param  int  $lockWait
     * @param  string|null  $store
     * @return static
     */
    public static function crateNewInstance(
        ConnectionInterface $connection,
        DateTimeInterface|DateInterval|int $ttl,
        string $key,
        int $lockWait,
        ?string $store,
    ): static {
        $repository = static::store($store, (bool) $lockWait);

        return new static($connection, $repository, config('cache-query.prefix'), $key, $ttl, $lockWait);
    }

    /**
     * Returns the store to se for caching.
     *
     * @param  string|null  $store
     * @param  bool  $lockable
     * @return \Illuminate\Contracts\Cache\Repository
     */
    protected static function store(?string $store, bool $lockable = false): Repository
    {
        $repository = cache()->store($store ?? config('cache-query.store'));

        if ($lockable && !$repository->getStore() instanceof LockProvider) {
            $store ??= cache()->getDefaultDriver();

            throw new LogicException("The [$store] cache does not support atomic locks.");
        }

        return $repository;
    }
}
