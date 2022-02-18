<?php

namespace Laragear\CacheQuery\Console\Commands\CacheQuery;

use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Factory as CacheContract;
use Illuminate\Contracts\Config\Repository as ConfigContract;
use JetBrains\PhpStorm\ArrayShape;
use function str;

class Forget extends Command
{
    /**
     * Indicates whether the command should be shown in the Artisan command list.
     *
     * @var bool
     */
    protected $hidden = true;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cache-query:forget
                            {key : The key name of the query to forget from the cache}
                            {--store= : The cache store to use}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Removes a cached query from the cache store.';

    /**
     * Execute the console command.
     *
     * @param  \Illuminate\Contracts\Cache\Factory  $cache
     * @param  \Illuminate\Contracts\Config\Repository  $config
     * @return void
     */
    public function handle(CacheContract $cache, ConfigContract $config): void
    {
        $store = $this->option('store') ?: $config->get('cache-query.store') ?? $cache->getDefaultDriver();

        [$key, $cacheKey] = $this->retrieveKey($config);

        if ($cache->store($store)->forget($cacheKey)) {
            $this->line("Successfully removed [$key] from the [$store] cache store.");
        } else {
            $this->warn("The [$key] was not found in [$store] cache store.");
        }
    }

    /**
     * Returns the original key and the cache key.
     *
     * @param  \Illuminate\Contracts\Config\Repository  $config
     * @return array<int, string>
     */
    #[ArrayShape([0 => 'string', 1 => 'string'])]
    protected function retrieveKey(ConfigContract $config): array
    {
        return [
            $this->argument('key'),
            (string) str($config->get('cache-query.prefix'))->finish('|')->append($this->argument('key')),
        ];
    }
}
