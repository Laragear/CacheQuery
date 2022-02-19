<?php

namespace Laragear\CacheQuery;

use Illuminate\Contracts\Cache\Factory as CacheContract;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Config\Repository as ConfigContract;
use function array_merge;

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
        protected CacheContract $cache, protected ConfigContract $config, public ?string $store = null,
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
     * @param  string  $key
     * @return string
     */
    protected function addPrefix(string $key): string
    {
        return $this->config->get('cache-query.prefix') . '|' . $key;
    }

    /**
     * Forgets a query using the user key used to persist it.
     *
     * @param  string  $key
     * @return bool
     */
    public function forget(string $key): bool
    {
        $key = $this->addPrefix($key);

        return $this->repository()->deleteMultiple(
            array_merge([$key], $this->repository()->get($key, []))
        );
    }
}
