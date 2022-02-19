<?php

namespace Laragear\CacheQuery\Scopes;

use DateInterval;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\Builder as EloquentBuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class AddCacheToRelations implements Scope
{
    public function __construct(
        protected DateTimeInterface|DateInterval|int $ttl,
        protected string $key,
        protected ?string $store,
        protected int $wait,
    ) {
        //
    }

    /**
     * Apply the scope to a given Eloquent query builder.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $builder
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return void
     */
    public function apply(Builder $builder, Model $model): void
    {
        // Since scopes are applied last, we can safely wrap eager loaded relations
        // with a cache, but using a custom cache key for each of these.
        $eager = $builder->getEagerLoads();

        foreach ($eager as $key => $callback) {
            $eager[$key] = function (EloquentBuilderContract $eloquent) use ($callback, $builder): void {
                $callback($eloquent);

                // Always override the previous eloquent builder with the base cache parameters.
                $eloquent->cache($this->ttl, $this->key, $this->store, $this->wait);

                $eloquent->getConnection()->queryKeySuffix = $builder->getConnection()->computedKey;
            };
        }

        $builder->setEagerLoads($eager);
    }
}
