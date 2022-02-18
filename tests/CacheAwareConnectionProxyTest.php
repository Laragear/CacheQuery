<?php

namespace Tests;

use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Laragear\CacheQuery\CacheAwareConnectionProxy;
use LogicException;
use Mockery;
use function now;
use function today;

class CacheAwareConnectionProxyTest extends TestCase
{
    use RefreshDatabase;
    use WithFaker;

    protected function setUp(): void
    {
        parent::setUp();

        for ($i = 0; $i < 10; $i++) {
            $users[] = [
                'email' => $this->faker->freeEmail,
                'name' => $this->faker->name,
                'password' => 'password',
                'email_verified_at' => today(),
            ];
        }

        $this->app->make('db')->table('users')->insert($users);

        for ($i = 0; $i < 6; $i++) {
            $posts[] = [
                'title' => $this->faker->text(20),
                'user_id' => (int) floor(max(1, $i / 2)),
            ];
        }

        $this->app->make('db')->table('posts')->insert($posts);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadLaravelMigrations();

        $this->app->make('db.schema')->create('posts', static function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->foreignIdFor(User::class);
            $table->timestamps();
        });

        $this->app->make('db.schema')->create('comments', static function (Blueprint $table): void {
            $table->id();
            $table->integer('likes')->default(0);
            $table->string('body');
            $table->foreignIdFor(Post::class);
            $table->timestamps();
        });
    }

    public function test_caches_base_query_into_default_store(): void
    {
        $get = $this->app->make('db')->table('users')->cache()->where('id', 1)->get();

        static::assertInstanceOf(Collection::class, $get);
        static::assertCount(1, $get);

        $cached = $this->app->make('cache')->store()->get('cache-query|X/UPpOGQDQSgAtjm14OWzw==');

        static::assertEquals(Collection::make($cached), $get);
    }

    public function test_caches_eloquent_query_into_default_store(): void
    {
        $first = User::query()->cache()->where('id', 1)->first();

        static::assertNotNull($this->app->make('cache')->store()->get('cache-query|fj8Xyz4K1Zh0tdAamPbG1A=='));

        User::query()->whereKey(1)->delete();

        $second = User::query()->cache()->where('id', 1)->first();

        static::assertNotNull($second);
        static::assertEquals($first, $second);
    }

    public function test_cached_base_query_returns_cached_results_from_same_query(): void
    {
        $first = $this->app->make('db')->table('users')->cache()->where('id', 1)->first();

        $this->app->make('db')->table('users')->where('id', 1)->delete();

        $second = $this->app->make('db')->table('users')->cache()->where('id', 1)->first();

        static::assertEquals($first, $second);
    }

    public function test_cached_eloquent_query_returns_cached_results_from_same_query(): void
    {
        $first = User::query()->cache()->where('id', 1)->first();

        User::query()->where('id', 1)->delete();

        $second = User::query()->cache()->where('id', 1)->first();

        static::assertEquals($first, $second);
    }

    public function test_cached_base_query_stores_null(): void
    {
        $hash = 'cache-query|6SHtUJfPv2GbKc4ikp7cLQ==';

        $null = $this->app->make('db')->table('users')->cache()->where('id', 11)->first();

        static::assertNull($null);
        static::assertIsArray($this->app->make('cache')->store()->get($hash));
        static::assertEmpty($this->app->make('cache')->store()->get($hash));
        static::assertTrue($this->app->make('cache')->store()->has($hash));
    }

    public function test_cached_base_query_doesnt_intercepts_cached_null_values(): void
    {
        $this->app->make('db')->table('users')->insert([
            'email' => $this->faker->freeEmail,
            'name' => $this->faker->name,
            'password' => 'password',
            'email_verified_at' => today(),
        ]);

        $hash = 'cache-query|k7FVGieZVUzWvOK44zPFeg==';

        $this->app->make('cache')->store()->put($hash, null);

        $notNull = $this->app->make('db')->table('users')->cache()->where('id', 11)->first();

        static::assertEquals('11', $notNull->id);
    }

    public function test_cached_eloquent_query_stores_empty_results(): void
    {
        $hash = 'cache-query|6SHtUJfPv2GbKc4ikp7cLQ==';

        $null = User::query()->cache()->where('id', 11)->first();

        static::assertNull($null);
        static::assertEmpty($this->app->make('cache')->store()->get($hash));
        static::assertTrue($this->app->make('cache')->store()->has($hash));
    }

    public function test_cached_eloquent_query_doesnt_intercepts_cached_null_values(): void
    {
        User::query()->insert([
            'email' => $this->faker->freeEmail,
            'name' => $this->faker->name,
            'password' => 'password',
            'email_verified_at' => today(),
        ]);

        $hash = 'cache-query|k7FVGieZVUzWvOK44zPFeg==';

        $this->app->make('cache')->store()->put($hash, null);

        $notNull = User::query()->cache()->where('id', 11)->first();

        static::assertSame(11, $notNull->id);
    }

    public function test_cached_base_query_hash_differs_when_retrieval_is_different(): void
    {
        $this->app->make('db')->table('users')->cache()->where('id', 1)->first(['name']);

        $this->app->make('db')->table('users')->where('id', 1)->delete();

        $second = $this->app->make('db')->table('users')->cache()->where('id', 1)->first(['email']);

        static::assertNull($second);
    }

    public function test_cached_eloquent_query_hash_differs_when_retrieval_is_different(): void
    {
        User::query()->cache()->where('id', 1)->first(['name']);

        User::query()->where('id', 1)->delete();

        $second = User::query()->cache()->where('id', 1)->first(['email']);

        static::assertNull($second);
    }

    public function test_cached_base_query_works_as_before_last_method_with_different_retrieval(): void
    {
        $this->app->make('db')->table('users')->where('id', 1)->cache()->first(['name']);

        $this->app->make('db')->table('users')->where('id', 1)->delete();

        $second = $this->app->make('db')->table('users')->where('id', 1)->cache()->first(['email']);

        static::assertNull($second);
    }

    public function test_cached_eloquent_query_works_as_before_last_method_with_different_retrieval(): void
    {
        User::query()->where('id', 1)->cache()->first(['name']);

        User::query()->where('id', 1)->delete();

        $second = User::query()->where('id', 1)->cache()->first(['email']);

        static::assertNull($second);
    }

    public function test_cached_base_query_hash_differs_when_pagination_changes(): void
    {
        $first = $this->app->make('db')->table('users')->cache()->paginate(perPage: 1, page: 1);
        $second = $this->app->make('db')->table('users')->cache()->paginate(perPage: 1, page: 2);

        static::assertSame(1, $first->firstItem());
        static::assertSame(2, $second->firstItem());
    }

    public function test_cached_eloquent_query_hash_differs_when_pagination_changes(): void
    {
        $first = User::query()->cache()->paginate(perPage: 1, page: 1);
        $second = User::query()->cache()->paginate(perPage: 1, page: 2);

        static::assertSame(1, $first->firstItem());
        static::assertSame(2, $second->firstItem());
    }

    public function test_cached_base_query_hash_differs_when_pagination_changes_through_instance(): void
    {
        $this->instance('request', Request::create('/', 'GET', ['page' => 1]));

        $first = $this->app->make('db')->table('users')->cache()->paginate(perPage: 1);

        $this->instance('request', Request::create('/', 'GET', ['page' => 2]));

        $second = $this->app->make('db')->table('users')->cache()->paginate(perPage: 1);

        static::assertSame(1, $first->firstItem());
        static::assertSame(2, $second->firstItem());
    }

    public function test_cached_eloquent_query_hash_differs_when_pagination_changes_through_instance(): void
    {
        $this->instance('request', Request::create('/', 'GET', ['page' => 1]));

        $first = User::query()->cache()->paginate(perPage: 1);

        $this->instance('request', Request::create('/', 'GET', ['page' => 2]));

        $second = User::query()->cache()->paginate(perPage: 1);

        static::assertSame(1, $first->firstItem());
        static::assertSame(2, $second->firstItem());
    }

    public function test_uses_custom_time_to_live(): void
    {
        $hash = 'cache-query|30250dGAv64n2ySOIxuL+g==';

        $seconds = 120;
        $now = now()->addMinute();
        $interval = $now->diffAsCarbonInterval(now());

        $repository = $this->mock(Repository::class);
        $repository->expects('get')->times(3)->andReturnFalse();
        $repository->allows('getStore')->never();
        $repository->expects('put')->with($hash, Mockery::type('array'), $seconds);
        $repository->expects('put')->with($hash, Mockery::type('array'), $now);
        $repository->expects('put')->with($hash, Mockery::type('array'), $interval);

        $this->mock('cache')->allows('store')->with(null)->andReturn($repository);

        $this->app->make('db')->table('users')->cache($seconds)->first();
        $this->app->make('db')->table('users')->cache($now)->first();
        $this->app->make('db')->table('users')->cache($interval)->first();
    }

    public function test_uses_custom_key(): void
    {
        $repository = $this->mock(Repository::class);
        $repository->expects('get')->with('cache-query|foo', false)->andReturnFalse();
        $repository->expects('put')->with('cache-query|foo', Mockery::type('array'), 60)->andReturnTrue();

        $this->mock('cache')->allows('store')->with(null)->andReturn($repository);

        $this->app->make('db')->table('users')->cache(key: 'foo')->first();
    }

    public function test_exception_if_repository_store_is_not_lockable_when_waiting(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The [foo] cache does not support atomic locks.');

        $store = $this->spy(Repository::class);

        $cache = $this->mock('cache');

        $cache->allows('store')->with(null)->andReturn($store);
        $cache->allows('getDefaultDriver')->andReturn('foo');

        $this->app->make('db')->table('users')->cache(wait: 30)->first();
    }

    public function test_locks_cache_when_waiting(): void
    {
        $hash = 'cache-query|30250dGAv64n2ySOIxuL+g==';

        $lock = $this->mock(Lock::class);
        $lock->expects('block')->withArgs(function ($time, $callback): bool {
            static::assertSame(30, $time);
            static::assertInstanceOf(Closure::class, $callback);

            $callback();

            return true;
        })->andReturnFalse();

        $store = $this->mock(LockProvider::class);
        $store->expects('lock')->with($hash, 30)->andReturn($lock);

        $repository = $this->mock(Repository::class);
        $repository->expects('getStore')->withNoArgs()->twice()->andReturn($store);
        $repository->expects('get')->with($hash, false)->andReturnFalse();
        $repository->expects('put')->with($hash, Mockery::type('array'), 60);

        $this->mock('cache')->shouldReceive('store')->with(null)->andReturn($repository);

        $this->app->make('db')->table('users')->cache(wait: 30)->first();
    }

    public function test_cached_eloquent_query_is_aware_of_eager_loaded_list(): void
    {
        $cached = User::query()->cache()->with('posts')->whereKey(1)->first();

        static::assertNotEquals($cached, User::query()->with('posts.comments')->cache()->whereKey(1)->first());
        static::assertTrue(User::query()->with('posts')->cache()->whereKey(1)->first()->relationLoaded('posts'));

        $cached = User::query()->cache()->with('posts')->whereKey(1)->first();

        static::assertEquals($cached, User::query()->with('posts')->whereKey(1)->first());
        static::assertTrue($cached->relationLoaded('posts'));
    }

    public function test_caches_eager_loaded_query(): void
    {
        $cached = User::query()->cache()->with('posts', function ($posts) {
            $posts->cache()->whereKey(2);
        })->whereKey(1)->first();

        User::query()->whereKey(1)->delete();
        Post::query()->whereKey(1)->delete();

        $renewed = User::query()->cache()->with('posts', function ($posts) {
            $posts->cache()->whereKey(2);
        })->whereKey(1)->first();

        static::assertEquals($cached, $renewed);
        static::assertEquals(2, $renewed->posts->first()->getKey());
    }

    public function test_calling_to_sql_does_not_cache_result(): void
    {
        $repository = $this->mock(Repository::class);
        $repository->expects('has')->never();
        $repository->expects('put')->never();

        $this->mock('cache')->shouldReceive('store')->with(null)->andReturn($repository);

        static::assertIsString($this->app->make('db')->table('users')->cache()->toSql());
        static::assertIsString(User::query()->cache()->toSql());
    }

    public function test_calling_non_executing_method_doesnt_caches_the_result(): void
    {
        $repository = $this->mock(Repository::class);
        $repository->expects('has')->never();
        $repository->expects('put')->never();

        $this->mock('cache')->shouldReceive('store')->with(null)->andReturn($repository);

        static::assertIsArray($this->app->make('db')->table('users')->cache()->getBindings());
        static::assertIsArray(User::query()->cache()->getBindings());
    }

    public function test_calling_non_returning_builder_method_does_not_cache_result(): void
    {
        $repository = $this->mock(Repository::class);
        $repository->expects('has')->never();
        $repository->expects('put')->never();

        $this->mock('cache')->shouldReceive('store')->with(null)->andReturn($repository);

        static::assertIsArray(User::query()->cache()->with('pages')->getEagerLoads());
    }

    public function test_no_exception_when_caching_eloquent_query_twice(): void
    {
        $builder = User::query()->cache();

        $proxy = $builder->getConnection();

        static::assertInstanceOf(CacheAwareConnectionProxy::class, $proxy);
        static::assertInstanceOf(ConnectionInterface::class, $proxy->connection);

        $sameConnection = $builder->cache()->getConnection();

        static::assertSame($proxy->connection, $sameConnection->connection);
    }

    public function test_pass_through_methods_to_wrapped_connection(): void
    {
        $this->app->make('db')->table('users')->cache()->getConnection()->setDatabaseName('foo');

        static::assertSame(
            'foo',
            $this->app->make('db')->table('users')->cache()->getConnection()->getDatabaseName('foo')
        );
    }

    public function test_pass_through_properties_set_and_get(): void
    {
        $this->app->make('db')->table('users')->cache()->getConnection()->foo = ['foo'];

        static::assertSame(['foo'], $this->app->make('db')->table('users')->cache()->getConnection()->foo);
    }

    public function test_select_one_uses_cache(): void
    {
        $results = $this->app->make('db')->table('users')->cache()->getConnection()->selectOne(
            'select id from users where id > ?', [1]
        );

        $this->app->make('db')->table('users')->delete();

        $retrieved = $this->app->make('db')->table('users')->cache()->getConnection()->selectOne(
            'select id from users where id > ?', [1]
        );

        static::assertSame($results, $retrieved);
    }
}

class User extends Authenticatable
{
    protected $table = 'users';

    public function posts()
    {
        return $this->hasMany(Post::class);
    }

    public function latestPost()
    {
        return $this->hasOne(Post::class)->ofMany();
    }

    public function comments()
    {
        return $this->hasManyThrough(Comment::class, Post::class);
    }
}

class Post extends Model
{
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function comments()
    {
        return $this->hasMany(Comment::class);
    }
}

class Comment extends Model
{
    public function post()
    {
        return $this->belongsTo(Post::class);
    }
}
