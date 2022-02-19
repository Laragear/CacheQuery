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
use function array_values;
use function base64_encode;
use function cache;
use function config;
use function implode;
use function md5;
use function rtrim;

class CacheAwareConnectionProxy
{
    /**
     * Create a new Cache Aware Connection Proxy instance.
     *
     * @param  \Illuminate\Database\ConnectionInterface  $connection
     * @param  \Illuminate\Contracts\Cache\Repository  $repository
     * @param  \DateTimeInterface|\DateInterval|int  $ttl
     * @param  int  $lockWait
     * @param  string  $cachePrefix
     * @param  string  $userKey
     * @param  string  $computedKey
     * @param  string  $queryKeySuffix
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
                [$list, $results] = array_values($this->repository->getMultiple([$this->userKey, $key]));

                if ($results === null) {
                    $results = $this->connection->select($query, $bindings, $useReadPdo);

                    // If the user added a user key, we will append this computed key to it and save it.
                    if ($this->userKey) {
                        $list[] = $key;
                        $this->repository->putMany([$key => $results, $this->userKey => $list], $this->ttl);
                    } else {
                        $this->repository->put($key, $results, $this->ttl);
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
     *
     * @param  string  $query
     * @param  array  $bindings
     * @return string
     */
    protected function getQueryHash(string $query, array $bindings): string
    {
        return rtrim(base64_encode(md5($this->connection->getDatabaseName().$query.implode('', $bindings), true)), '=');
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
     * @param  \DateTimeInterface|\DateInterval|int|null  $ttl
     * @param  string  $key
     * @param  int  $wait
     * @param  string|null  $store
     * @return static
     */
    public static function crateNewInstance(
        ConnectionInterface $connection,
        DateTimeInterface|DateInterval|int|null $ttl,
        string $key,
        int $wait,
        ?string $store,
    ): static {
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
     * Returns the store to se for caching.
     *
     * @param  string|null  $store
     * @param  bool  $lockable
     * @return \Illuminate\Contracts\Cache\Repository
     */
    protected static function store(?string $store, bool $lockable): Repository
    {
        $repository = cache()->store($store ?? config('cache-query.store'));

        if ($lockable && !$repository->getStore() instanceof LockProvider) {
            $store ??= cache()->getDefaultDriver();

            throw new LogicException("The [$store] cache does not support atomic locks.");
        }

        return $repository;
    }
}
