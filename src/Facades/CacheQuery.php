<?php

namespace Laragear\CacheQuery\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Laragear\CacheQuery\CacheQuery store(string $store)
 * @method static bool forget(string ...$keys)
 *
 * @method static \Laragear\CacheQuery\CacheQuery getFacadeRoot()
 */
class CacheQuery extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return \Laragear\CacheQuery\CacheQuery::class;
    }
}
