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
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Laragear\CacheQuery\CacheAwareConnectionProxy;
use LogicException;
use Mockery;
use Orchestra\Testbench\Attributes\WithMigration;

use function floor;
use function max;
use function now;
use function today;

#[WithMigration]
class CacheAwareConnectionProxyTest extends TestCase
{
    use WithFaker;

    protected function setUp(): void
    {
        $this->afterApplicationCreated(function () {
            $this->app->make('db')->table('users')->insert(Collection::times(10, fn () => [
                'email' => $this->faker->freeEmail,
                'name' => $this->faker->name,
                'password' => 'password',
                'email_verified_at' => today(),
            ])->toArray());

            $this->app->make('db')->table('posts')->insert(Collection::times(6, fn ($i) => [
                'title' => $this->faker->text(20),
                'user_id' => (int) floor(max(1, $i / 2)),
            ])->toArray());
        });

        parent::setUp();
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

        $cached = $this->app->make('cache')->store()->get('cache-query|X/UPpOGQDQSgAtjm14OWzw');

        static::assertEquals(Collection::make($cached), $get);
    }

    public function test_caches_eloquent_query_into_default_store(): void
    {
        $first = User::query()->cache()->where('id', 1)->first();

        static::assertNotNull($this->app->make('cache')->store()->get('cache-query|fj8Xyz4K1Zh0tdAamPbG1A'));

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

    public function test_cached_base_query_stores_empty_array_and_null_results(): void
    {
        $hash = 'cache-query|6SHtUJfPv2GbKc4ikp7cLQ';

        $null = $this->app->make('db')->table('users')->cache()->where('id', 11)->first();

        static::assertNull($null);
        static::assertIsArray($this->app->make('cache')->store()->get($hash));
        static::assertEmpty($this->app->make('cache')->store()->get($hash));
        static::assertTrue($this->app->make('cache')->store()->has($hash));
    }

    public function test_cached_eloquent_query_stores_empty_array_and_null_results(): void
    {
        $hash = 'cache-query|6SHtUJfPv2GbKc4ikp7cLQ';

        $null = User::query()->cache()->where('id', 11)->first();

        static::assertNull($null);
        static::assertIsArray($this->app->make('cache')->store()->get($hash));
        static::assertEmpty($this->app->make('cache')->store()->get($hash));
        static::assertTrue($this->app->make('cache')->store()->has($hash));
    }

    public function test_cached_base_query_doesnt_intercepts_manually_cached_null_values(): void
    {
        $this->app->make('db')->table('users')->insert([
            'email' => $this->faker->freeEmail,
            'name' => $this->faker->name,
            'password' => 'password',
            'email_verified_at' => today(),
        ]);

        $hash = 'cache-query|k7FVGieZVUzWvOK44zPFeg';

        $this->app->make('cache')->store()->put($hash, null);

        $notNull = $this->app->make('db')->table('users')->cache()->where('id', 11)->first();

        static::assertEquals('11', $notNull->id);
    }

    public function test_cached_eloquent_query_doesnt_intercepts_manually_cached_null_values(): void
    {
        User::query()->insert([
            'email' => $this->faker->freeEmail,
            'name' => $this->faker->name,
            'password' => 'password',
            'email_verified_at' => today(),
        ]);

        $hash = 'cache-query|k7FVGieZVUzWvOK44zPFeg';

        $this->app->make('cache')->store()->put($hash, null);

        $notNull = User::query()->cache()->where('id', 11)->first();

        static::assertSame(11, $notNull->id);
    }

    public function test_cached_base_query_hash_differs_when_columns_are_different(): void
    {
        $this->app->make('db')->table('users')->cache()->where('id', 1)->first(['name']);

        $this->app->make('db')->table('users')->where('id', 1)->delete();

        $second = $this->app->make('db')->table('users')->cache()->where('id', 1)->first(['email']);

        static::assertNull($second);
    }

    public function test_cached_eloquent_query_hash_differs_when_columns_are_different(): void
    {
        User::query()->cache()->where('id', 1)->first(['name']);

        User::query()->where('id', 1)->delete();

        $second = User::query()->cache()->where('id', 1)->first(['email']);

        static::assertNull($second);
    }

    public function test_cached_base_query_works_as_before_last_method_with_different_columns(): void
    {
        $this->app->make('db')->table('users')->where('id', 1)->cache()->first(['name']);

        $this->app->make('db')->table('users')->where('id', 1)->delete();

        $second = $this->app->make('db')->table('users')->where('id', 1)->cache()->first(['email']);

        static::assertNull($second);
    }

    public function test_cached_eloquent_query_works_as_before_last_method_with_different_columns(): void
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

    public function test_caches_pagination_with_group_by(): void
    {
        User::query()->delete();

        foreach (['first@bogus.com', 'second@bogus.com'] as $email) {
            User::forceCreate([
                'email' => $email,
                'name' => 'foo',
                'password' => 'password',
                'email_verified_at' => today(),
            ]);
        }

        User::query()->cache()->groupBy('name')->paginate(perPage: 1, page: 1);
        User::query()->cache()->groupBy('name')->paginate(perPage: 1, page: 2);

        User::query()->delete();

        $this->assertDatabaseEmpty('users');

        $first = User::query()->cache()->groupBy('name')->paginate(perPage: 1, page: 1);
        $second = User::query()->cache()->groupBy('name')->paginate(perPage: 1, page: 2);

        static::assertCount(1, $first->items());
        static::assertSame(1, $first->total());
        static::assertSame(1, $first->firstItem());
        static::assertEmpty($second);
    }

    public function test_uses_custom_time_to_live(): void
    {
        $hash = 'cache-query|30250dGAv64n2ySOIxuL+g';

        $seconds = 120;
        $now = now()->addMinute();
        $interval = $now->diffAsCarbonInterval(now());

        $repository = $this->mock(Repository::class);
        $repository->expects('getMultiple')->with([$hash, ''])->times(4)->andReturn(['' => null, $hash => null]);
        $repository->allows('getStore')->never();
        $repository->expects('put')->with($hash, Mockery::type('array'), null);
        $repository->expects('put')->with($hash, Mockery::type('array'), $seconds);
        $repository->expects('put')->with($hash, Mockery::type('array'), $now);
        $repository->expects('put')->with($hash, Mockery::type('array'), $interval);

        $this->mock('cache')->allows('store')->with(null)->andReturn($repository);

        $this->app->make('db')->table('users')->cache(null)->first();
        $this->app->make('db')->table('users')->cache($seconds)->first();
        $this->app->make('db')->table('users')->cache($now)->first();
        $this->app->make('db')->table('users')->cache($interval)->first();
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
        $hash = 'cache-query|30250dGAv64n2ySOIxuL+g';

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
        $repository->expects('getMultiple')->with([$hash, ''])->andReturn(['' => null, $hash => null]);
        $repository->expects('getStore')->withNoArgs()->twice()->andReturn($store);
        $repository->expects('put')->with($hash, Mockery::type('array'), 60);

        $this->mock('cache')->shouldReceive('store')->with(null)->andReturn($repository);

        $this->app->make('db')->table('users')->cache(wait: 30)->first();
    }

    public function test_saves_user_key_with_real_computed_keys_list(): void
    {
        $this->travelTo(now());

        $this->app->make('db')->table('users')->cache(key: 'foo')->first();

        static::assertTrue($this->app->make('cache')->has('cache-query|30250dGAv64n2ySOIxuL+g'));
        static::assertSame(
            ['list' => ['cache-query|30250dGAv64n2ySOIxuL+g'], 'expires_at' => now()->addMinute()->getTimestamp()],
            $this->app->make('cache')->get('cache-query|foo')
        );
    }

    public function test_first_query_takes_precedence_over_second_query_with_different_key(): void
    {
        $this->app->make('db')->table('users')->cache(key: 'foo')->first();
        $this->app->make('db')->table('users')->cache(key: 'bar')->first();

        static::assertTrue($this->app->make('cache')->has('cache-query|foo'));
        static::assertFalse($this->app->make('cache')->has('cache-query|bar'));
    }

    public function test_largest_ttl_key_takes_precedence(): void
    {
        $this->travelTo(now()->startOfSecond());

        $this->app->make('db')->table('users')->where('id', 1)->cache(ttl: 120, key: 'foo')->first();
        $this->app->make('db')->table('users')->where('id', 1)->cache(ttl: 30, key: 'foo')->first();

        $this->app->make('db')->table('users')->where('id', 2)->cache(ttl: now()->addSeconds(120), key: 'bar')->first();
        $this->app->make('db')->table('users')->where('id', 2)->cache(ttl: now()->addSeconds(30), key: 'bar')->first();

        $this->app->make('db')->table('users')->where('id', 4)->cache(ttl: null, key: 'quz')->first();
        $this->app->make('db')->table('users')->where('id', 4)->cache(ttl: 30, key: 'quz')->first();

        $this->travelTo(now()->addMinute());

        static::assertTrue($this->app->make('cache')->has('cache-query|foo'));
        static::assertTrue($this->app->make('cache')->has('cache-query|bar'));
        static::assertTrue($this->app->make('cache')->has('cache-query|quz'));

        $this->travelTo(now()->addMinute()->subSecond());

        static::assertTrue($this->app->make('cache')->has('cache-query|foo'));
        static::assertTrue($this->app->make('cache')->has('cache-query|bar'));
        static::assertTrue($this->app->make('cache')->has('cache-query|quz'));

        $this->travelTo(now()->addSeconds(2));

        static::assertFalse($this->app->make('cache')->has('cache-query|foo'));
        static::assertFalse($this->app->make('cache')->has('cache-query|bar'));

        static::assertTrue($this->app->make('cache')->has('cache-query|quz'));
    }

    public function test_regenerates_cache_using_false_ttl(): void
    {
        $this->app->make('db')->table('users')->where('id', 1)->cache()->first();

        $this->app->make('db')->table('users')->where('id', 1)->update(['name' => 'test']);

        $result = $this->app->make('db')->table('users')->where('id', 1)->cache(false)->first();

        static::assertSame('test', $result->name);
    }

    public function test_regenerates_cache_using_ttl_with_negative_number(): void
    {
        $this->app->make('db')->table('users')->where('id', 1)->cache()->first();

        $this->app->make('db')->table('users')->where('id', 1)->update(['name' => 'test']);

        $result = $this->app->make('db')->table('users')->where('id', 1)->cache(-1)->first();

        static::assertSame('test', $result->name);
    }

    public function test_different_queries_with_same_key_add_to_same_list(): void
    {
        $this->app->make('db')->table('users')->cache(null, 'foo')->where('id', 1)->first();
        $this->app->make('db')->table('users')->cache(null, 'foo')->where('id', 2)->first();

        static::assertTrue($this->app->make('cache')->has('cache-query|fj8Xyz4K1Zh0tdAamPbG1A'));
        static::assertTrue($this->app->make('cache')->has('cache-query|u7YzPIzZNGNu7Dkr/kx4Iw'));
        static::assertSame(
            [
                'list' => ['cache-query|fj8Xyz4K1Zh0tdAamPbG1A', 'cache-query|u7YzPIzZNGNu7Dkr/kx4Iw'],
                'expires_at' => 'never',
            ],
            $this->app->make('cache')->get('cache-query|foo')
        );
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
            $posts->whereKey(2);
        })->whereKey(1)->first();

        User::query()->whereKey(1)->delete();
        Post::query()->whereKey(2)->delete();

        $renewed = User::query()->cache()->with('posts', function ($posts) {
            $posts->whereKey(2);
        })->whereKey(1)->first();

        static::assertTrue($cached->is($renewed));
        static::assertCount(1, $renewed->posts);
    }

    public function test_caches_eager_loaded_query_with_user_key(): void
    {
        $cached = User::query()->cache(key: 'foo')->with('posts', function ($posts) {
            $posts->whereKey(2);
        })->whereKey(1)->first();

        User::query()->whereKey(1)->delete();
        Post::query()->whereKey(2)->delete();

        $renewed = User::query()->cache(key: 'foo')->with('posts', function ($posts) {
            $posts->whereKey(2);
        })->whereKey(1)->first();

        static::assertTrue($cached->is($renewed));
        static::assertCount(1, $renewed->posts);
    }

    public function test_cached_eager_loaded_query_with_user_key_saves_computed_query_keys_list(): void
    {
        User::query()->cache(null, 'foo')->with('posts', function ($posts) {
            $posts->whereKey(2);
        })->whereKey(1)->first();

        static::assertSame(
            [
                'list' => ['cache-query|O18ompNwDpTOa5rNZczCSw', 'cache-query|O18ompNwDpTOa5rNZczCSw.NF0RBjEJ/bDl95d8ryoKeg'],
                'expires_at' => 'never',
            ],
            $this->app->make('cache')->get('cache-query|foo')
        );
    }

    public function test_overrides_cached_eager_load_query_with_parent_user_keys(): void
    {
        User::query()->cache(null, 'foo')->with('posts', function ($posts) {
            $posts->whereKey(2)->cache(key: 'bar');
        })->whereKey(1)->first();

        static::assertSame(
            [
                'list' => ['cache-query|O18ompNwDpTOa5rNZczCSw', 'cache-query|O18ompNwDpTOa5rNZczCSw.NF0RBjEJ/bDl95d8ryoKeg'],
                'expires_at' => 'never',
            ],
            $this->app->make('cache')->get('cache-query|foo')
        );

        static::assertFalse($this->app->make('cache')->has('cache-query|bar'));
        static::assertFalse($this->app->make('cache')->has('cache-query|foo.bar'));
    }

    public function test_caches_deep_eager_loaded_query(): void
    {
        $this->app->make('db')->table('comments')->insert(['likes' => 1, 'body' => 'test', 'post_id' => 2]);

        $cached = User::query()->cache()->with('posts', function ($posts) {
            $posts->whereKey(2)->with('comments');
        })->whereKey(1)->first();

        User::query()->whereKey(1)->delete();
        Post::query()->whereKey(2)->delete();
        Comment::query()->whereKey(1)->delete();

        $renewed = User::query()->cache()->with('posts', function ($posts) {
            $posts->whereKey(2)->with('comments');
        })->whereKey(1)->first();

        static::assertTrue($cached->is($renewed));
        static::assertCount(1, $renewed->posts);
        static::assertCount(1, $renewed->posts->first()->comments);
    }

    public function test_cached_deep_eager_loaded_query_with_user_key_saves_computed_query_keys_list(): void
    {
        User::query()->cache(null, 'foo')->with('posts', function ($posts) {
            $posts->whereKey(2)->with('comments');
        })->whereKey(1)->first();

        static::assertSame(
            [
                'list' => [
                    'cache-query|O18ompNwDpTOa5rNZczCSw',
                    'cache-query|O18ompNwDpTOa5rNZczCSw.NF0RBjEJ/bDl95d8ryoKeg',
                    'cache-query|O18ompNwDpTOa5rNZczCSw.NF0RBjEJ/bDl95d8ryoKeg.ULZsLi343YS0xbuO0VteEA',
                ],
                'expires_at' => 'never',
            ],
            $this->app->make('cache')->get('cache-query|foo')
        );
    }

    public function test_caches_above_one_level_deep_eager_load_relation_query(): void
    {
        User::query()->with('posts', function ($posts) {
            $posts->whereKey(2)->cache(null, 'foo')->with('comments');
        })->whereKey(1)->first();

        static::assertSame(
            [
                'list' => [
                    'cache-query|NF0RBjEJ/bDl95d8ryoKeg',
                    'cache-query|NF0RBjEJ/bDl95d8ryoKeg.ULZsLi343YS0xbuO0VteEA',
                ],
                'expires_at' => 'never',
            ],
            $this->app->make('cache')->get('cache-query|foo')
        );
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
        $connection = $this->app->make('db')->table('users')->cache()->getConnection();

        $connection->setDatabaseName('foo');

        static::assertSame('foo', $connection->getDatabaseName());
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
