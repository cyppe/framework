<?php

namespace Illuminate\Tests\Cache;

use Illuminate\Cache\ArrayLock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\CacheLock;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\DatabaseLock;
use Illuminate\Cache\DynamoDbLock;
use Illuminate\Cache\FailoverStore;
use Illuminate\Cache\FileLock;
use Illuminate\Cache\Lock;
use Illuminate\Cache\MemcachedLock;
use Illuminate\Cache\MemoizedStore;
use Illuminate\Cache\NoLock;
use Illuminate\Cache\PhpRedisLock;
use Illuminate\Cache\RedisLock;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Lock as LockContract;
use Illuminate\Contracts\Cache\RefreshableLock;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class CacheRefreshableLockTest extends TestCase
{
    public static function refreshableLockProvider()
    {
        return [
            'array' => [ArrayLock::class],
            'database' => [DatabaseLock::class],
            'dynamodb' => [DynamoDbLock::class],
            'file' => [FileLock::class],
            'memcached' => [MemcachedLock::class],
            'null' => [NoLock::class],
            'phpredis' => [PhpRedisLock::class],
            'predis' => [RedisLock::class],
        ];
    }

    #[DataProvider('refreshableLockProvider')]
    public function testLocksThatSupportRefreshingImplementTheContractAndOverrideTheThrowingDefault($lock)
    {
        $this->assertTrue(
            is_subclass_of($lock, RefreshableLock::class),
            "[{$lock}] overrides refresh() but does not implement RefreshableLock."
        );

        $this->assertTrue(is_subclass_of($lock, LockContract::class));

        $this->assertNotSame(
            Lock::class,
            (new ReflectionMethod($lock, 'refresh'))->getDeclaringClass()->getName(),
            "[{$lock}] advertises refresh support but inherits Lock::refresh(), which throws."
        );
    }

    public function testLocksThatDoNotSupportRefreshingDoNotImplementTheContract()
    {
        $this->assertFalse(is_subclass_of(CacheLock::class, RefreshableLock::class));
        $this->assertSame(
            Lock::class,
            (new ReflectionMethod(CacheLock::class, 'refresh'))->getDeclaringClass()->getName()
        );
    }

    public function testTheContractIsUsableAsACapabilityCheck()
    {
        Carbon::setTestNow($now = Carbon::now());

        $store = new ArrayStore;
        $lock = $store->lock('foo', 10);

        try {
            $this->assertInstanceOf(RefreshableLock::class, $lock);

            $this->assertTrue($lock->get());
            $this->assertTrue($lock->refresh(20));

            Carbon::setTestNow($now->addSeconds(11));

            $this->assertFalse($store->lock('foo', 10)->get());
        } finally {
            Carbon::setTestNow();
            $lock->release();
        }
    }

    public function testNoLockCanBeRefreshed()
    {
        $lock = new NoLock('foo', 10);

        $this->assertInstanceOf(RefreshableLock::class, $lock);
        $this->assertTrue($lock->refresh(20));
    }

    public function testPhpRedisLockInheritsTheContractFromRedisLock()
    {
        $this->assertTrue(is_subclass_of(PhpRedisLock::class, RedisLock::class));
        $this->assertTrue(is_subclass_of(PhpRedisLock::class, RefreshableLock::class));
    }

    public function testMemoizedStoreReturnsTheRefreshableLockFromItsUnderlyingStore()
    {
        $store = new MemoizedStore('array', new Repository(new ArrayStore));
        $lock = $store->lock('foo', 10);

        $this->assertInstanceOf(ArrayLock::class, $lock);
        $this->assertInstanceOf(RefreshableLock::class, $lock);
        $this->assertTrue($lock->get());
        $this->assertTrue($lock->refresh(20));
    }

    public function testFailoverStoreReturnsTheRefreshableLockFromItsUnderlyingStore()
    {
        $cache = m::mock(CacheManager::class);
        $cache->shouldReceive('store')
            ->once()
            ->with('array')
            ->andReturn(new Repository(new ArrayStore));

        $store = new FailoverStore($cache, m::mock(Dispatcher::class), ['array']);
        $lock = $store->lock('foo', 10);

        $this->assertInstanceOf(ArrayLock::class, $lock);
        $this->assertInstanceOf(RefreshableLock::class, $lock);
        $this->assertTrue($lock->get());
        $this->assertTrue($lock->refresh(20));
    }
}
