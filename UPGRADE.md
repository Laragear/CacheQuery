# Upgrading

## From 4.x

### Regeneration

Regeneration has been moved into the `Laragear\CacheQuery\Cache` class. If you need to programmatically regenerate results, use the `regenWhen()` and `regenUnless()` methods of the aforementioned class.

### Locks

Locks have been removed in favour of flexible caching, which under the hood already uses locks. If you're using locks, you should migrate to flexible caching.

### Cache Store

Custom Cache Store has been moved into the `Laragear\CacheQuery\Cache` class. If you need to use a non-default cache store, use the `store()` method of the aforementioned class.

## From 2.x or 3.x

### Cache keys

Cache keys are now used exclusively to delete one or multiple queries, like tags. Multiple queries using the same key will yield different results, as these will not share the same cache key anymore.

If you need to keep the same functionality, use your application Cache directly.

```php
use App\Modesl\User;

$users = cache()->remember('users', 60, function () {
    return User::all();
})
```

## From 1.x

### Idempotent queries

[Idempotent](https://en.wikipedia.org/wiki/Idempotence) queries have been removed. Cached queries only work for `SELECT` procedures, like `first()` or `get()`.

As an alternative, you can use the `remember()` method of your application cache for the same effect:

```php
cache()->remember('idempotent', 60, function () {
    Article::whereKey(10)->increment('unique_views');
    
    return true;
})
```

### Query Hash

If for some reason you where using the query hashes, 2.x incorporates the connection name into the hash. This means that the cached query will be usable only for the given connection.
