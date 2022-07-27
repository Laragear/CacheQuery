# Cache Query 
[![Latest Version on Packagist](https://img.shields.io/packagist/v/laragear/cache-query.svg)](https://packagist.org/packages/laragear/cache-query) [![Latest stable test run](https://github.com/Laragear/CacheQuery/workflows/Tests/badge.svg)](https://github.com/Laragear/CacheQuery/actions) [![Codecov coverage](https://codecov.io/gh/Laragear/CacheQuery/branch/1.x/graph/badge.svg?token=IOZS1TFJ5G)](https://codecov.io/gh/Laragear/CacheQuery) [![Maintainability](https://api.codeclimate.com/v1/badges/7e7894f3eee3939333eb/maintainability)](https://codeclimate.com/github/Laragear/CacheQuery/maintainability) [![Sonarcloud Status](https://sonarcloud.io/api/project_badges/measure?project=Laragear_CacheQuery&metric=alert_status)](https://sonarcloud.io/dashboard?id=Laragear_CacheQuery) [![Laravel Octane Compatibility](https://img.shields.io/badge/Laravel%20Octane-Compatible-success?style=flat&logo=laravel)](https://laravel.com/docs/9.x/octane#introduction)

Remember your query results using only one method. Yes, only one.

```php
Articles::latest('published_at')->cache()->take(10)->get();
```

## Keep this package free

[![](.github/assets/patreon.png)](https://patreon.com/packagesforlaravel)[![](.github/assets/ko-fi.png)](https://ko-fi.com/DarkGhostHunter)[![](.github/assets/buymeacoffee.png)](https://www.buymeacoffee.com/darkghosthunter)[![](.github/assets/paypal.png)](https://www.paypal.com/paypalme/darkghosthunter)

Your support allows me to keep this package free, up-to-date and maintainable. Alternatively, you can **[spread the word!](http://twitter.com/share?text=I%20am%20using%20this%20cool%20PHP%20package&url=https://github.com%2FLaragear%2FCacheQuery&hashtags=PHP,Laravel)**

## Requirements

* PHP 8.0
* Laravel 9.x

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

The next time you call the same query, the result will be retrieved from the cache instead of running the `SELECT` SQL statement in the database, even if the results are empty, `null` or `false`. 

It's **eager load aware**. This means that it will cache an eager loaded relation automatically, even its children.

```php
use App\Models\User;

User::where('is_author')->with('posts')->cache()->paginate();
```

### Time-to-live

By default, results of a query are cached by 60 seconds, which is mostly enough when your application is getting hammered with the same query results.

You're free to use any number of seconds from now, or just a Carbon instance.

```php
use Illuminate\Support\Facades\DB;
use App\Models\Article;

DB::table('articles')->latest('published_at')->take(10)->cache(60 * 60)->get();

Article::latest('published_at')->take(10)->cache(now()->addHour())->get();
```

You can also use `null` to set the query results forever in the cache.

```php
use App\Models\Article;

Article::latest('published_at')->take(10)->cache(null)->get();
```

You may also want the ability to regenerate the results programmatically. To do that, set the time as `false`. This will _refresh_ the cache with the result, even if it was already cached before.

```php
use App\Models\Article;

Article::latest('published_at')->take(10)->cache(
    $user->isAdmin() ? false : 60 * 120
)->get();
```

Finally, you can bypass the cache entirely using the query builder `when()` and `unless()` methods easily, as these are totally compatible with the `cache()` method.

```php
use App\Models\Article;

Article::latest('published_at')
       ->whereBelongsTo($user)
       ->take(10)
       ->when(Auth::guest(), function ($articles) {
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

Or, you can just [change the default](#cache-store):

```dotenv
CACHE_QUERY_STORE=redis
```

### Cache Lock (data races)

On multiple processes, the query may be executed multiple times until the first process is able to store the result in the cache, specially when these take more than one second. To avoid this, set the `wait` parameter with the number of seconds to hold the acquired lock.

```php
use App\Models\Article;

Article::latest('published_at')->take(200)->cache(wait: 5)->get();
```

The first process will acquire the lock for the given seconds and execute the query. The next processes will wait the same amount of seconds until the first process stores the result in the cache to retrieve it. If the first process takes too much, the second will try again.

> **Note** If you need a more advanced locking mechanism, use the [cache lock](https://laravel.com/docs/cache#managing-locks-across-processes) directly.

## Forgetting results with a key

This package generates cache keys using the BASE64-encoded MD5 hashes of the executed query. If you expect to remove a query from the cache, you will need to identify the query with the `key` argument. 

```php
use App\Models\Article;

Article::latest('published_at')->with('drafts')->take(5)->cache(key: 'latest_articles')->get();
```

Once done, you can later delete the query results using the `CacheQuery` facade.

```php
use Laragear\CacheQuery\Facades\CacheQuery;

CacheQuery::forget('latest_articles');
```

Or, you may use the `cache-query:forget` command with the name of the key from the CLI.

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

> **Note** This functionality does not use cache tags, so it will work on any cache store you set, even the `file` driver!

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

## PhpStorm stubs

For users of PhpStorm, there is a stub file to aid in macro autocompletion for this package. You can publish them using the `phpstorm` tag:

```shell
php artisan vendor:publish --provider="Laragear\CacheQuery\CacheQueryServiceProvider" --tag="phpstorm"
```

The file gets published into the `.stubs` folder of your project. You should point your [PhpStorm to these stubs](https://www.jetbrains.com/help/phpstorm/php.html#advanced-settings-area).

## How it works?

When you use `cache()`, it will wrap the connection into a proxy object. It proxies all method calls to it except `select()` and `selectOne()`.

Once a `SELECT` statement is executed through the aforementioned methods, it will check if the results are in the cache before executing the query. On cache hit, it will return the cached results, even if these are empty, otherwise it will continue execution, save the incoming results into the cache, and return them as normal.

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

[Laravel](https://laravel.com) is a Trademark of [Taylor Otwell](https://github.com/TaylorOtwell/). Copyright © 2011-2022 Laravel LLC.
