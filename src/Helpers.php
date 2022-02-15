<?php

namespace Laragear\CacheQuery;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Database\Query\Builder;
use LogicException;
use function base64_encode;
use function cache;
use function config;
use function implode;
use function md5;
use function str;

/**
 * This class is used internally to avoid adding methods to the CacheAwareProxy.
 *
 * @internal
 */
class Helpers
{
    /**
     * Returns the store to se for caching.
     *
     * @param  string|null  $store
     * @return \Illuminate\Contracts\Cache\Repository
     */
    public static function store(?string $store, bool $lockable = false): Repository
    {
        $repository = cache()->store($store ?? config('cache-query.store'));

        if ($lockable && !$repository->getStore() instanceof LockProvider) {
            $store ??= cache()->getDefaultDriver();

            throw new LogicException("The [$store] cache does not support atomic locks.");
        }

        return $repository;
    }

    /**
     * Normalizes the cache key.
     *
     * @param  \Illuminate\Contracts\Database\Query\Builder  $builder
     * @param  string  $key
     * @return string
     */
    public static function cacheKey(Builder $builder, string $key): string
    {\dump(static::hashBuilder($builder));
        return str(config('cache-query.prefix'))
            ->finish('|')
            ->append($key ?: static::hashBuilder($builder));
    }

    /**
     * Hash the query builder signature.
     *
     * @param  \Illuminate\Contracts\Database\Query\Builder  $builder
     * @return string
     */
    protected static function hashBuilder(Builder $builder): string
    {
        return base64_encode(md5($builder->toSql().implode('', $builder->getBindings()), true));
    }
}
