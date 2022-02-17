<?php

namespace Laragear\CacheQuery;

use Closure;
use DateInterval;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\ServiceProvider;
use function func_get_args;

/**
 * @internal
 */
class CacheQueryServiceProvider extends ServiceProvider
{
    public const CONFIG = __DIR__.'/../config/cache-query.php';
    public const STUBS = __DIR__.'/../.stubs/stubs.php';

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
                Console\Commands\CacheQuery\Forget::class
            ]);
        }
    }

    /**
     * Creates a macro for the query builders.
     *
     * @return \Closure
     */
    protected function macro(): Closure
    {
        return function (
            int|DateTimeInterface|DateInterval $ttl = 60,
            string $key = '',
            string $store = null,
            int $wait = 0,
        ): CacheAwareProxy {
            /** @var \Illuminate\Database\Query\Builder $this */
            return new CacheAwareProxy($this, Helpers::store($store, (bool) $wait), $key, $ttl, $wait);
        };
    }

    /**
     * Creates a macro for the Eloquent Query Builder.
     *
     * @return \Closure
     */
    protected function eloquentMacro(): Closure
    {
        return function (
            int|DateTimeInterface|DateInterval $ttl = 60,
            string $key = '',
            string $store = null,
            int $wait = 0,
        ): EloquentBuilder {
            /** @var \Illuminate\Database\Eloquent\Builder $this */
            $this->setQuery($this->getQuery()->cache(...func_get_args()));

            return $this;
        };
    }
}
