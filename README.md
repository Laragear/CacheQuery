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

Your support allows me to keep this package free, up-to-date and maintainable. Alternatively, you can **[spread the word!](http://twitter.com/share?text=I%20am%20using%20this%20cool%20PHP%20package&url=https://github.com%2FLaragear%2FCacheQuery&hashtags=PHP,Laravel)**

## Requirements

* Laravel 10 or later

## Installation

You can install the package via composer:

```bash
composer require laragear/cache-query
```

## Usage

Just use the `cache()` method to remember the results of a query for a default of 60 seconds.

```php
use Illuminate\Support\Facades\DB;
use App\Models\Article;

DB::table('articles')->latest('published_at')->take(10)->cache()->get();

Article::latest('published_at')->take(10)->cache()->get();
```

The next time you call the **same** query, the result will be retrieved from the cache instead of running the `SELECT` SQL statement in the database, even if the results are empty, `null` or `false`. 

It's **eager load aware**. This means that it will cache an eager loaded relation automatically.

```php
use App\Models\User;

$usersWithPosts = User::where('is_author')->with('posts')->cache()->paginate();
```

### Time-to-live

By default, results of a query are cached by 60 seconds, which is mostly enough when your application is getting hammered with the same query results.

You're free to use any number of seconds from now, or just a Carbon instance.

```php
use Illuminate\Support\Facades\DB;
use App\Models\Article;

DB::table('articles')->latest('published_at')->take(10)->cache(120)->get();

Article::latest('published_at')->take(10)->cache(now()->addHour())->get();
```

You can also use `null` to set the query results forever.

```php
use App\Models\Article;

Article::latest('published_at')->take(10)->cache(null)->get();
```

Sometimes you may want to regenerate the results programmatically. To do that, set the time as `false`. This will repopulate the cache with the new results, even if these were not cached before.

```php
use App\Models\Article;

$regen = request()->isNotFilled('no-cache');

Article::latest('published_at')->take(10)->cache($regen)->get();
```

Finally, you can bypass the cache entirely using the query builder `when()` and `unless()` methods easily, as these are totally compatible with the `cache()` method.

```php
use App\Models\Article;

Article::latest('published_at')->whereBelongsTo($user)->take(10)->unless(Auth::check(), function ($articles) {
    // If the user is a guest, use the cache to show the latest articles of the given user.
    $articles->cache();
})->get();
```

### Custom Cache Store

You can use any other Cache Store different from the application default by setting a third parameter, or a named parameter.

```php
use App\Models\Article;

Article::latest('published_at')->take(10)->cache(store: 'redis')->get();
```

### Cache Lock (data races)

On multiple processes, the query may be executed multiple times until the first process is able to store the result in the cache, specially when these take more than one second. Take, for example, 1,000 users reading the latest 10 post of a site at the same time will call the database 100 times. 

To avoid this, set the `wait` parameter with the number of seconds to hold the acquired lock.

```php
use App\Models\Article;

Article::latest('published_at')->take(200)->cache(wait: 5)->get();
```

The first process will acquire the lock for the given seconds and execute the query. The next processes will wait the same amount of seconds until the first process stores the result in the cache to retrieve it. If the first process takes too much, the second will try again.

> If you need a more advanced locking mechanism, use the [cache lock](https://laravel.com/docs/cache#managing-locks-across-processes) directly.

## Forgetting results with a key

Cache keys are used to identify multiple queries cached with an identifiable name. These are not mandatory, but if you expect to remove a query from the cache, you will need to identify the query with the `key` argument. 

```php
use App\Models\Article;

Article::latest('published_at')->with('drafts')->take(5)->cache(key: 'latest_articles')->get();
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

Article::latest('published_at')->with('drafts')->take(5)->cache(key: 'latest_articles')->get();
Post::latest('posted_at')->take(10)->cache(key: 'latest_articles')->get();

CacheQuery::forget('latest_articles');
```

> This functionality does not use cache tags, so it will work on any cache store you set, even the `file` driver!

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

## Caveats

This cache package does some clever things to always retrieve the data from the cache, or populate it with the results, in an opaque way and using just one method, but this world is far from perfect.

### Operations are **NOT** commutative

Altering the Builder methods order will change the auto-generated cache key. Even if two or more queries are _visually_ the same, the order of statements makes the hash completely different.

For example, given two similar queries in different parts of the application, these both will **not** share the same cached result:

```php
User::query()->cache()->whereName('Joe')->whereAge(20)->first();
// Cache key: "cache-query|/XreUO1yaZ4BzH2W6LtBSA=="

User::query()->cache()->whereAge(20)->whereName('Joe')->first();
// Cache key: "cache-query|muDJevbVppCsTFcdeZBxsA=="
```

To avoid this, ensure you always execute the same query, or centralize the query somewhere in your application (like using a [query scope](https://laravel.com/docs/11.x/eloquent#query-scopes)).

> **Note** This is by design. Ordering the query bindings would make operations commutative, but also disrupt [query-index optimizations](https://use-the-index-luke.com/sql/where-clause/the-equals-operator/concatenated-keys). Consider this not a bug, but a _feature_.

### Cannot delete autogenerated keys

All queries are cached using a BASE64 encoded MD5 hash of the connection name, SQL query and its bindings. This avoids any collision with other queries even from different databases, and also makes the cache lookup faster thanks to a shorter cache key.

```php
User::query()->cache()->whereAge(20)->whereName('Joe')->first();
// Cache key: "cache-query|muDJevbVppCsTFcdeZBxsA=="
```

This makes extremely difficult to remove keys from the cache. If you need to invalidate or regenerate the cached results, [use a custom key](#forgetting-results-with-a-key).

## PhpStorm stubs

For users of PhpStorm, there is a stub file to aid in macro autocompletion for this package. You can publish them using the `phpstorm` tag:

```shell
php artisan vendor:publish --provider="Laragear\CacheQuery\CacheQueryServiceProvider" --tag="phpstorm"
```

The file gets published into the `.stubs` folder of your project. You should point your [PhpStorm to these stubs](https://www.jetbrains.com/help/phpstorm/php.html#advanced-settings-area).

## How it works?

When you use `cache()`, it will wrap the connection into a proxy object. It proxies all method calls to it except `select()` and `selectOne()`.

Once a `SELECT` statement is executed through the aforementioned methods, it will check if the results are in the cache before executing the query. On cache hit, it will return the cached results, otherwise it will continue execution, save the results using the cache configuration, and return them.

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
