<?php

namespace Laragear\CacheQuery;

use Closure;
use DateInterval as Interval;
use DateTimeInterface as DateTime;
use Illuminate\Database\ConnectionInterface as DBConnection;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\ServiceProvider;

use function app;
use function base64_encode;
use function explode;
use function implode;
use function md5;
use function rtrim;
use function sort;

/**
 * @internal
 */
class CacheQueryServiceProvider extends ServiceProvider
{
    public const CONFIG = __DIR__.'/../config/cache-query.php';
    public const STUBS = __DIR__.'/../.stubs/stubs';

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfigFrom(static::CONFIG, 'cache-query');
    }

    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot(): void
    {
        if (! Builder::hasMacro('cache')) {
            Builder::macro('cache', $this->macro());
        }

        if (! EloquentBuilder::hasGlobalMacro('cache')) {
            EloquentBuilder::macro('cache', $this->eloquentMacro());
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([static::CONFIG => $this->app->configPath('cache-query.php')], 'config');
            $this->publishes([static::STUBS => $this->app->basePath('.stubs/cache-query.php')], 'phpstorm');

            $this->commands([
                Console\Commands\CacheQuery\Forget::class,
            ]);
        }

        Proxy::$queryHasher = static function (DBConnection $connection, string $query, array $bindings): string {
            // If the commutative operations is enabled, we will normalize the query and bindings.
            if (app('config')->get('cache-query.commutative')) {
                $query = Collection::make(explode(' ', $query))->sort()->implode('');
                sort($bindings);
            }

            return rtrim(base64_encode(md5($connection->getDatabaseName().$query.implode('', $bindings), true)), '=');
        };
    }

    /**
     * Creates a macro for the base Query Builder.
     *
     * @return \Closure
     */
    protected function macro(): Closure
    {
        return function (DateTime|Interval|Closure|Cache|int|bool|array|string|null $ttl = 60): Builder {
            /** @var \Illuminate\Database\Query\Builder $this */

            // Avoid re-wrapping the connection into another proxy.
            if ($this->connection instanceof Proxy) { // @phpstan-ignore-line
                $this->connection = $this->connection->connection;
            }

            // Normalize the TTL argument to a Cache instance.
            $this->connection = Proxy::crateNewInstance($this->connection, match (true) {
                $ttl instanceof Closure => $ttl(new Cache),
                ! $ttl instanceof Cache => (new Cache)->ttl($ttl),
                default => $ttl
            });

            return $this;
        };
    }

    /**
     * Creates a macro for the base Query Builder.
     *
     * @return \Closure
     */
    protected function eloquentMacro(): Closure
    {
        return function (DateTime|Interval|Closure|Cache|int|bool|array|string|null $ttl = 60): EloquentBuilder {
            /** @var \Illuminate\Database\Eloquent\Builder $this */
            $this->getQuery()->cache($ttl); // @phpstan-ignore-line

            // @phpstan-ignore-next-line
            if ($this->getQuery()->getConnection()->getCacheHelperInstance()->saveNestedQueries) {
                // This global scope is responsible for caching eager loaded relations.
                $this->withGlobalScope(Scopes\CacheRelations::class, new Scopes\CacheRelations());
            }

            return $this;
        };
    }
}
