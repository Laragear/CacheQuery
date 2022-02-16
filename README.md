# Cache Query 
[![Latest Version on Packagist](https://img.shields.io/packagist/v/laragear/cache-query.svg)](https://packagist.org/packages/laragear/cache-query) [![Latest stable test run](https://github.com/Laragear/CacheQuery/workflows/Tests/badge.svg)](https://github.com/Laragear/CacheQuery/actions) [![Codecov coverage](https://codecov.io/gh/Laragear/CacheQuery/branch/1.x/graph/badge.svg?token=IOZS1TFJ5G)](https://codecov.io/gh/Laragear/CacheQuery) [![Maintainability](https://api.codeclimate.com/v1/badges/7e7894f3eee3939333eb/maintainability)](https://codeclimate.com/github/Laragear/CacheQuery/maintainability) [![Laravel Octane Compatibility](https://img.shields.io/badge/Laravel%20Octane-Compatible-success?style=flat&logo=laravel)](https://laravel.com/docs/9.x/octane#introduction)

Remember your query results using only one method. Yes, only one.

```php
Articles::latest('published_at')->cache()->take(10)->get();
```

## Keep this package free

[![](.assets/patreon.png)](https://patreon.com/packagesforlaravel)[![](.assets/ko-fi.png)](https://ko-fi.com/DarkGhostHunter)[![](.assets/buymeacoffee.png)](https://www.buymeacoffee.com/darkghosthunter)[![](.assets/paypal.png)](https://www.paypal.com/paypalme/darkghosthunter)

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

$database = DB::table('articles')->latest('published_at')->take(10)->cache()->get();

$eloquent = Article::latest('published_at')->take(10)->cache()->get();
```

The next time you call the **same** query, the result will be retrieved from the cache instead of running the SQL statement in the database, even if the result is empty, `null` or `false`. 

Since it's [eager load unaware](#eager-load-unaware), you can also cache (or not) an eager loaded relation.

```php
use App\Models\User;

$eloquent = User::where('is_author')->with('posts' => function ($posts) {
    $post->cache()->where('published_at', '>', now());
})->paginate();
```

### Time-to-live

By default, results of a query are cached by 60 seconds, but you're free to use any length, `Datetime`, `DateInterval` or Carbon instance.

```php
use Illuminate\Support\Facades\DB;
use App\Models\Article;

DB::table('articles')->latest('published_at')->take(10)->cache(120)->get();

Article::latest('published_at')->take(10)->cache(now()->addHour())->get();
```

### Custom Cache Key

The auto-generated cache key is an BASE64-MD5 hash of the SQL query and its bindings, which avoids any collision with other queries while keeping the cache key short for a faster lookup in the cache store.

```php
Article::latest('published_at')->take(10)->cache(30, 'latest_articles')->get();
```

You can use this to your advantage to manually retrieve the result across your application:

```php
$cachedArticles = Cache::get('cache-query|latest_articles');
```

### Custom Cache Store

You can use any other Cache Store different from the application default by setting a third parameter, or a named parameter.

```php
Article::latest('published_at')->take(10)->cache(store: 'redis')->get();
```

### Cache Lock (data races)

On multiple processes, the query may be executed multiple times until the first process is able to store the result in the cache, specially when these take more than one second. To avoid this, set the `wait` parameter with the number of seconds to hold the acquired lock.

```php
Article::latest('published_at')->take(200)->cache(wait: 5)->get();
```

The first process will acquire the lock for the given seconds and execute the query. The next processes will wait the same amount of seconds until the first process stores the result in the cache to retrieve it.

> If you need to use this across multiple processes, use the [cache lock](https://laravel.com/docs/cache#managing-locks-across-processes) directly.

### Idempotent queries

While the reason behind remembering a Query is to cache the data retrieved from a database, you can use this to your advantage to create [idempotent](https://en.wikipedia.org/wiki/Idempotence) queries.

For example, you can make this query only execute once every day for a given user ID.

```php
$key = auth()->user()->getAuthIdentifier();

Article::whereKey(54)->cache(now()->addHour(), "user:$key")->increment('unique_views');
```

Subsequent executions of this query won't be executed at all until the cache expires, so in the above example we have surprisingly created a "unique views" mechanic.

## Caveats

This cache package does some clever things to always retrieve the data from the cache, or populate it with the results, in an opaque way and using just one method, but this world is far from perfect.

### Operations are **NOT** commutative

Altering the Builder methods order will change the auto-generated cache key. Even if two or more queries are _visually_ the same, the order of statements makes the hash completely different.

For example, given two similar queries in different parts of the application, these both will **not** share the same cached result:

```php
User::query()->cache()->whereName('Joe')->whereAge(20)->first();
// Cache key: "query-cache|/XreUO1yaZ4BzH2W6LtBSA=="

User::query()->cache()->whereAge(20)->whereName('Joe')->first();
// Cache key: "query-cache|muDJevbVppCsTFcdeZBxsA=="
```

To ensure you're hitting the same cache on similar queries, use a [custom cache key](#custom-cache-key). With this, all queries using the same key will share the same cached result:

```php
User::query()->cache(60, 'find_joe')->whereName('Joe')->whereAge(20)->first();
User::query()->cache(60, 'find_joe')->whereAge(20)->whereName('Joe')->first();
```

### Eager load **unaware**

Since caching only works for the current query builder instance, an underlying Eager Load query won't be cached. This may be a blessing or a curse depending on your scenario.

```php
$page = 1;

User::with('posts', function ($posts) use ($page) {
    return $posts()->forPage($page);
})->cache()->find(1);
```

In the example, the `posts` eager load query results are never cached. To avoid that, you can use `cache()` on the eager loaded query. This way both the parent `user` query and the child `posts` query will be saved into the cache.

```php
$page = 1;

User::with('posts', function ($posts) use ($page) {
    return $posts()->cache()->forPage($page);
})->find(1);
```

## PhpStorm stubs

For users of PhpStorm, there is a stub file and a meta file to aid in macro autocompletion for this package. You can publish them using the `phpstorm` tag:

```shell
php artisan vendor:publish --provider="Laragear\CacheQuery\CacheQueryServiceProvider" --tag="phpstorm"
```

The file gets published into the `.stubs` folder of your project, while the meta is located inside .phpstorm.meta.php directory. You should point your [PhpStorm to these stubs](https://www.jetbrains.com/help/phpstorm/php.html#advanced-settings-area).

## How it works?

When you use `cache()`, it will wrap the base builder into a `CacheAwareProxy` proxy calls to it. At the same time, it injects a callback that runs _before_ is sent to the database for execution.

This callback will check if the results are in the cache. On cache hit, it throws an exception to interrupt the query, which is recovered by the `CacheAwareProxy`, returning the results.

For the Eloquent Builder, this wraps happens below it, so all calls pass through the `CacheAwareProxy` before hitting the real base builder.

## Laravel Octane compatibility

- There are no singletons using a stale application instance.
- There are no singletons using a stale config instance.
- There are no singletons using a stale request instance.
- There are no static properties written during a request.

There should be no problems using this package with Laravel Octane.

## Security

If you discover any security related issues, please email darkghosthunter@gmail.com instead of using the issue tracker.

# License

This specific package version is licensed under the terms of the [MIT License](LICENSE.md), at time of publishing.

[Laravel](https://laravel.com) is a Trademark of [Taylor Otwell](https://github.com/TaylorOtwell/). Copyright © 2011-2022 Laravel LLC.
