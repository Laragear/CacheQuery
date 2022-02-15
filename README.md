# Cache Query 

Remember your query results using only one method. Yes, only one.

```php
Articles::latest('published_at')->cache()->take(10)->get();
```

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

Since caching only works for the current query builder instance, an underlying Eager Load query won't be cached.

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

## How it works?

When you use `cache()`, it will wrap the base builder into a `CacheAwareProxy` proxy calls to it. At the same time, it injects a callback that runs _before_ is sent to the database for execution.

This callback checks if the results are in the cache. On cache hit, it throws an exception to interrupt the query, which is recovered by the `CacheAwareProxy`, returning the results.

For the Eloquent Builder, this wraps happens below it, so all calls pass through the `CacheAwareProxy` before hitting the real base builder.

## Security

If you discover any security related issues, please email darkghosthunter@gmail.com instead of using the issue tracker.