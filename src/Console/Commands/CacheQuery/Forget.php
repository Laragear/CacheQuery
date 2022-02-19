<?php

namespace Laragear\CacheQuery\Console\Commands\CacheQuery;

use Illuminate\Console\Command;
use Laragear\CacheQuery\CacheQuery;

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
     * @param  \Laragear\CacheQuery\CacheQuery  $cacheQuery
     * @return void
     */
    public function handle(CacheQuery $cacheQuery): void
    {
        $store = $this->option('store') ?: config('cache-query.store') ?? cache()->getDefaultDriver();

        $key = $this->argument('key');

        if ($cacheQuery->store($store)->forget($key)) {
            $this->line("Successfully removed [$key] from the [$store] cache store.");
        } else {
            $this->warn("The [$key] was not found in [$store] cache store.");
        }
    }
}
