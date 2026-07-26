<?php

namespace Illuminate\Tests\Integration\Cache;

use Illuminate\Contracts\Cache\RefreshableLock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

#[RequiresPhpExtension('memcached')]
class MemcachedLockRefreshTest extends MemcachedIntegrationTestCase
{
    public function testLocksCanBeRefreshed()
    {
        $name = 'refreshable-lock-'.Str::random();
        $lock = Cache::store('memcached')->lock($name, 2);

        try {
            $this->assertInstanceOf(RefreshableLock::class, $lock);
            $this->assertTrue($lock->get());
            $this->assertTrue($lock->refresh(10));
            sleep(3);
            $this->assertFalse(Cache::store('memcached')->lock($name, 10)->get());
        } finally {
            $lock->forceRelease();
        }
    }
}
