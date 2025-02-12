<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Session Driver
    |--------------------------------------------------------------------------
    |
    | This is the default cache store to use when retrieving the cached results
    | or storing the query execution result. By default, this key is `null`,
    | so your application default cache store will be used to cache data.
    |
    */

    'store' => env('CACHE_QUERY_STORE'),

    /*
    |--------------------------------------------------------------------------
    | Prefix
    |--------------------------------------------------------------------------
    |
    | To avoid keys colliding with others, specially when setting the cache key
    | manually, this prefix is prepended to all cache keys. You can change it
    | if for some weird reason you are already using this for your own app.
    |
    */

    'prefix' => 'cache-query',


    /*
    |--------------------------------------------------------------------------
    | Commutative
    |--------------------------------------------------------------------------
    |
    | Cached queries are identified by a key hash created by sorting the query
    | data. This makes "visually" similar queries share the same cache key.
    | If you don't want that, you can turn off commutative queries here.
    |
    */

    'commutative' => false,
];
