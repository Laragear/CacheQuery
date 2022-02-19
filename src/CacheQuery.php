<?php

namespace Laragear\CacheQuery;

use Illuminate\Contracts\Cache\Factory as CacheContract;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Config\Repository as ConfigContract;
use Illuminate\Support\Collection;

class CacheQuery
{
    /**
     * Create a new Cache Query instance.
     *
     * @param  \Illuminate\Contracts\Cache\Factory  $cache
     * @param  \Illuminate\Contracts\Config\Repository  $config
     * @param  string|null  $store
     */
    public function __construct(
        protected CacheContract $cache,
        protected ConfigContract $config,
        public ?string $store = null,
    ) {
        $this->store ??= $this->config->get('cache-query.store');
    }

    /**
     * Retrieves the repository using the set store name.
     *
     * @return \Illuminate\Contracts\Cache\Repository
     */
    protected function repository(): Repository
    {
        return $this->cache->store($this->store);
    }

    /**
     * Changes the cache store to work with.
     *
     * @param  string  $store
     * @return $this
     */
    public function store(string $store): static
    {
        $this->store = $store;

        return $this;
    }

    /**
     * Parses the given key with the default prefix.
     *
     * @param  array<int, string>  $keys
     * @return array<int, string>
     */
    protected function addPrefix(array $keys): array
    {
        $prefix = $this->config->get('cache-query.prefix');

        foreach ($keys as $index => $key) {
            $keys[$index] = $prefix.'|'.$key;
        }

        return $keys;
    }

    /**
     * Forgets a query using the user key used to persist it.
     *
     * @param  string  ...$keys
     * @return bool
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function forget(string ...$keys): bool
    {
        return $this->repository()->deleteMultiple($this->getQueries($keys));
    }

    /**
     * Returns a collection of query keys to delete.
     *
     * @param  array  $keys
     * @return \Illuminate\Support\Collection
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    protected function getQueries(array $keys): Collection
    {
        return Collection::make($this->repository()->getMultiple($this->addPrefix($keys)))
            ->map(static function (?array $queries, string $key): array {
                $queries[] = $key;

                return $queries;
            })
            ->flatten(1);
    }
}
