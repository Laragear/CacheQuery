# Cache Query 
[![Latest Version on Packagist](https://img.shields.io/packagist/v/laragear/cache-query.svg)](https://packagist.org/packages/laragear/cache-query)
[![Latest stable test run](https://github.com/Laragear/CacheQuery/workflows/Tests/badge.svg)](https://github.com/Laragear/CacheQuery/actions)
[![Codecov coverage](https://codecov.io/gh/Laragear/CacheQuery/branch/1.x/graph/badge.svg?token=IOZS1TFJ5G)](https://codecov.io/gh/Laragear/CacheQuery)
[![Maintainability](https://api.codeclimate.com/v1/badges/7e7894f3eee3939333eb/maintainability)](https://codeclimate.com/github/Laragear/CacheQuery/maintainability)
[![Sonarcloud Status](https://sonarcloud.io/api/project_badges/measure?project=Laragear_CacheQuery&metric=alert_status)](https://sonarcloud.io/dashboard?id=Laragear_CacheQuery)
[![Laravel Octane Compatibility](https://img.shields.io/badge/Laravel%20Octane-Compatible-success?style=flat&logo=laravel)](https://laravel.com/docs/11.x/octane#introduction)

Remember your query results using only one method. Yes, only one.

```php
Articles::latest('published_at')->cache()->take(10)->get();
```

## Become a sponsor

[![](.github/assets/support.png)](https://github.com/sponsors/DarkGhostHunter)

Your support allows me to keep this package free, up-to-date and maintainable. Alternatively, you can **spread the word in social media**

## Requirements

* Laravel 11 or later

## Installation

You can install the package via composer:

```bash
composer require laragear/cache-query
```

## How it works?

This library wraps the connection into a proxy object. It proxies all method calls to it except `select()` and `selectOne()`.

Once a `SELECT` statement is executed through the aforementioned methods, it will check if the results are in the cache before executing the query. On cache hit, it will return the cached results, otherwise it will continue execution, save the results using the cache configuration, and return them.

## Usage

Just use the `cache()` method to remember the results of a query for a default of 60 seconds.

```php
use Illuminate\Support\Facades\DB;
use App\Models\Article;

DB::table('articles')->latest('published_at')->take(10)->cache()->get();

Article::latest('published_at')->take(10)->cache()->get();
```

The next time you call the **same** query, the result will be retrieved from the cache instead of running the `SELECT` SQL statement in the database, even if the results are empty, `null` or `false`. You may also desire to [not cache empty results](#cache-except-empty-results).

It's **eager load aware**. This means that it will cache an eager loaded relation automatically, but [you may also disable this](#eager-loaded-queries).

```php
use App\Models\User;

$usersWithPosts = User::where('is_author')->with('posts')->cache()->paginate();
```

### Time-to-live

By default, results of a query are cached by 60 seconds, which is mostly enough when your application is getting hammered with the same query results.

You're free to use any number of seconds from now, or a `DateTimeInterface` like Carbon. 

```php
use Illuminate\Support\Facades\DB;
use App\Models\Article;

DB::table('articles')->latest('published_at')->take(10)->cache(120)->get();

Article::latest('published_at')->take(10)->cache(now()->addHour())->get();
```

You can also use `null`, `ever` or `forever` to set the query results forever.

```php
use App\Models\Article;

Article::latest('published_at')->take(10)->cache('forever')->get();
```

### Stale while revalidate

You may take advantage of [Laravel Flexible Caching mechanism](https://laravel.com/docs/cache#swr) by issuing an array of values as first argument. (...) _The first value in the array represents the number of seconds the cache is considered fresh, while the second value defines how long it can be served as stale data before recalculation is necessary_.

```php
use App\Models\Article;

Article::latest('published_at')->take(200)->cache([300, 60])->get();
```

The above example will refresh the query results if there is 60 seconds lefts until the data dies.

## Advanced caching

You may use a callback to further change the query caching. The callback receives a `Laragear\CacheQuery\Cache` instance that allows to change how to cache the data.

```php
use Laragear\CacheQuery\Cache;
use App\Models\User;

User::query()->where('cool', true)->cache(function (Cache $cache) {
    $cache->ttl([300, 60])->regenWhen(true);
})->get();
```

Alternatively, you can create and configure an instance outside the query, and then pass it as an argument. You can do this with the `for()` method or `flexible()` method

```php
use Laragear\CacheQuery\Cache;
use App\Models\User;
use App\Models\Post;

$cacheUser = Cache::for(30)->regenWhen(true);

User::query()->where('cool', true)->cache($cacheUser)->get();

$cachePost = Cache::flexible(300, 50)->as('frontend-posts');

Post::query()->latest()->limit(10)->cache($cachePost)->get();
```

### Conditional Regeneration

You may want to forcefully regenerate the queried cache when the underlying data changes, or because other reason. For that, use the `regenWhen()` and a condition that evaluates to `true`, and `regenUnless()` for a condition that evaluates to `false`. If you pass a callback, it will be executed before retrieving the results from the cache. 

```php
use Laragear\CacheQuery\Cache;

Cache::for([300, 50])->regenWhen(true);

Cache::for(50)->regenUnless(fn() => false);
```

### Cache except empty results

By default, the `cache()` method will cache _any_ result from the query, empty or not. You can disable this with the `exceptEmpty()` method, which will only cache non-empty results.

```php
use Laragear\CacheQuery\Cache;

Cache::for(300)->exceptEmpty();
```

### Eager loaded queries

You may disable caching Eager Loaded Queries with the `exceptNested()` method. With that, only the query that invokes the `cache()` method will be cached.

```php
use Laragear\CacheQuery\Cache;

Cache::for(300)->exceptNested();
```

For example, in this query, only the `User` query will be cached, while the `posts` won't.

```php
use App\Models\User;
use App\Models\Post;
use Laragear\CacheQuery\Cache;

User::where('cool', true)
    ->cache(fn(Cache $cache) => $cache->exceptNested())
    ->with('posts', fn ($query) => $query->where('published_at', '<', now())
    ->get();
```

### Custom Store

By default, the cached results use your application default cache store. You may change the default store using the `store()` method.

```php
use Laragear\CacheQuery\Cache;

Cache::for(300)->store('redis');
```

### Forgetting cached results

If you plan to remove a query from the cache, you will need to identify the query with the `as()` method and an identifiable key name.

```php
use Laragear\CacheQuery\Cache;

Cache::for(300)->as('latest_articles');
```

Once done, you can later delete the query results using the `CacheQuery` facade.

```php
use Laragear\CacheQuery\Facades\CacheQuery;

CacheQuery::forget('latest_articles');
```

Or you may use the `cache-query:forget` command with the name of the key from the CLI.

```shell
php artisan cache-query:forget latest_articles

# Successfully removed [latest_articles] from the [file] cache store. 
```

You may use the same key for multiple queries to group them into a single list you can later delete in one go.

```php
use App\Models\Article;
use App\Models\Post;
use Laragear\CacheQuery\Facades\CacheQuery;
use Laragear\CacheQuery\Cache;

$cache = Cache::for(300)->as('latest_articles');

Article::latest('published_at')->with('drafts')->take(5)->cache($cache)->get();
Post::latest('posted_at')->take(10)->cache($cache)->get();

CacheQuery::forget('latest_articles');
```

> [!TIP]
>
> This functionality does not use cache tags, so it will work on any cache store you set, even the `file` driver!

## Custom Hash Function

You can set your own function to hash the incoming SQL Query. Just register your function in the `$queryHasher` static property of the `CacheAwareConnectionProxy` class. The function should receive the database Connection, the query string, and the SQL bindings in form of an array.

This can be done in the `register()` method of your `AppServiceProvider`.

```php
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laragear\CacheQuery\Proxy;

class AppServiceProvider extends ServiceProvider
{
    public function register()
    {
        Proxy::$queryHasher = function ($connection, $query, $bindings) {
            // ...
        }
    }
}
```

## Configuration

To further configure the package, publish the configuration file:

```shell
php artisan vendor:publish --provider="Laragear\CacheQuery\CacheQueryServiceProvider" --tag="config"
```

You will receive the `config/cache-query.php` config file with the following contents:

```php
<?php

return [
    'store' => env('CACHE_QUERY_STORE'),
    'prefix' => 'cache-query',
    'commutative' => false
];
```

### Cache Store

```php
return  [
    'store' => env('CACHE_QUERY_STORE'),
];
```

The default cache store to put the queried results. When not issued in the query, this setting will be used. If it's empty or `null`, the default cache store of your application will be used.

You can easily change this setting using your `.env` file:

```dotenv
CACHE_QUERY_STORE=redis
```

### Prefix

```php
return  [
    'prefix' => 'cache-query',
];
```

When storing query hashes and query named keys, this prefix will be appended, which will avoid conflicts with other cached keys. You can change in case it collides with other keys.

### Commutative operations

```php
return [
    'commutative' => false
]
```

When _hashing_ queries, the default [hasher function](#custom-hash-function) will create different hashes even on visually different queries. 

```php
User::query()->cache()->whereName('Joe')->whereAge(20)->first();
// Cache key: "cache-query|/XreUO1yaZ4BzH2W6LtBSA"

User::query()->cache()->whereAge(20)->whereName('Joe')->first();
// Cache key: "cache-query|muDJevbVppCsTFcdeZBxsA"
```

By setting `commutative` to `true`, the function will always sort the query elements so similar queries share the same hash.

```php
User::query()->cache()->whereName('Joe')->whereAge(20)->first();
// Cache key: "cache-query|muDJevbVppCsTFcdeZBxsA"

User::query()->cache()->whereAge(20)->whereName('Joe')->first();
// Cache key: "cache-query|muDJevbVppCsTFcdeZBxsA"
```

> [!TIP]
> 
> This can be also overridden using your own [custom hash function](#custom-hash-function).

## PhpStorm stubs

For users of PhpStorm, there is a stub file to aid in macro autocompletion for this package. You can publish them using the `phpstorm` tag:

```shell
php artisan vendor:publish --provider="Laragear\CacheQuery\CacheQueryServiceProvider" --tag="phpstorm"
```

The file gets published into the `.stubs` folder of your project. You should point your [PhpStorm to these stubs](https://www.jetbrains.com/help/phpstorm/php.html#advanced-settings-area).

## Laravel Octane compatibility

- There are no singletons using a stale application instance.
- There are no singletons using a stale config instance.
- There are no singletons using a stale request instance.
- There are no static properties written during a request.

There should be no problems using this package with Laravel Octane.

## [Upgrading](UPGRADE.md)

## Security

If you discover any security related issues, please email darkghosthunter@gmail.com instead of using the issue tracker.

# License

This specific package version is licensed under the terms of the [MIT License](LICENSE.md), at time of publishing.

[Laravel](https://laravel.com) is a Trademark of [Taylor Otwell](https://github.com/TaylorOtwell/). Copyright © 2011-2024 Laravel LLC.
