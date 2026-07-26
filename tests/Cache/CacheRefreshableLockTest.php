<?php

namespace Illuminate\Tests\Cache;

use Illuminate\Cache\ArrayLock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\CacheLock;
use Illuminate\Cache\DatabaseLock;
use Illuminate\Cache\DynamoDbLock;
use Illuminate\Cache\FileLock;
use Illuminate\Cache\MemcachedLock;
use Illuminate\Cache\NoLock;
use Illuminate\Cache\PhpRedisLock;
use Illuminate\Cache\RedisLock;
use Illuminate\Contracts\Cache\Lock as LockContract;
use Illuminate\Contracts\Cache\RefreshableLock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CacheRefreshableLockTest extends TestCase
{
    public static function refreshableLockProvider()
    {
        return [
            [ArrayLock::class],
            [DatabaseLock::class],
            [DynamoDbLock::class],
            [FileLock::class],
            [MemcachedLock::class],
            [NoLock::class],
            [PhpRedisLock::class],
            [RedisLock::class],
        ];
    }

    #[DataProvider('refreshableLockProvider')]
    public function testLocksThatSupportRefreshingImplementTheContract($lock)
    {
        $this->assertTrue(
            is_subclass_of($lock, RefreshableLock::class),
            "[{$lock}] overrides refresh() but does not implement RefreshableLock."
        );

        $this->assertTrue(is_subclass_of($lock, LockContract::class));
    }

    public function testLocksThatDoNotSupportRefreshingDoNotImplementTheContract()
    {
        // CacheLock is the base used by third party stores via the HasCacheLock
        // trait. It inherits the throwing default, so it must not advertise the
        // capability...
        $this->assertFalse(is_subclass_of(CacheLock::class, RefreshableLock::class));
    }

    public function testTheContractIsUsableAsACapabilityCheck()
    {
        $lock = (new ArrayStore)->lock('foo', 10);

        $this->assertInstanceOf(RefreshableLock::class, $lock);

        $this->assertTrue($lock->get());
        $this->assertTrue($lock->refresh(20));

        $lock->release();
    }
}
