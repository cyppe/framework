<?php

namespace Illuminate\Tests\Integration\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Events\UniqueJobSuppressed;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Mockery as m;
use Orchestra\Testbench\Attributes\WithMigration;
use RuntimeException;

#[WithMigration]
#[WithMigration('cache')]
#[WithMigration('queue')]
class UniqueJobSuppressedTest extends QueueTestCase
{
    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'database');
    }

    public function testNoEventIsDispatchedWhenTheJobIsQueued()
    {
        Queue::fake();
        Event::fake([UniqueJobSuppressed::class]);

        UniqueJobSuppressedTestJob::dispatch();

        Event::assertNotDispatched(UniqueJobSuppressed::class);
    }

    public function testEventIsDispatchedWhenUniquenessSuppressesTheDispatchUsingQueueFake()
    {
        Queue::fake();

        UniqueJobSuppressedTestJob::dispatch();

        Event::fake([UniqueJobSuppressed::class]);

        dispatch($suppressedJob = new UniqueJobSuppressedTestJob);

        Event::assertDispatchedTimes(UniqueJobSuppressed::class, 1);
        Event::assertDispatched(UniqueJobSuppressed::class, function ($event) use ($suppressedJob) {
            return $event->job === $suppressedJob
                && $event->key === UniqueLock::getKey(new UniqueJobSuppressedTestJob);
        });

        Queue::assertPushed(UniqueJobSuppressedTestJob::class, 1);
    }

    public function testEventIsDispatchedWhenUniquenessSuppressesTheDispatchUsingBusFake()
    {
        Bus::fake();

        dispatch(new UniqueJobSuppressedTestJob);

        Event::fake([UniqueJobSuppressed::class]);

        dispatch($suppressedJob = new UniqueJobSuppressedTestJob);

        Event::assertDispatchedTimes(UniqueJobSuppressed::class, 1);
        Event::assertDispatched(UniqueJobSuppressed::class, function ($event) use ($suppressedJob) {
            return $event->job === $suppressedJob
                && $event->key === UniqueLock::getKey(new UniqueJobSuppressedTestJob);
        });

        Bus::assertDispatchedOnce(UniqueJobSuppressedTestJob::class);
    }

    public function testEventContainsTheExactKeyUsedForTheFailedAcquisition()
    {
        Queue::fake();
        Event::fake([UniqueJobSuppressed::class]);

        $job = new StatefulUniqueJobSuppressedTestJob;
        $expectedKey = 'laravel_unique_job:'.StatefulUniqueJobSuppressedTestJob::class.':1';

        $this->assertTrue($this->app->make(Cache::class)->lock($expectedKey)->get());

        dispatch($job);

        $this->assertSame(1, $job->uniqueIdCalls);
        Event::assertDispatchedTimes(UniqueJobSuppressed::class, 1);
        Event::assertDispatched(UniqueJobSuppressed::class, function ($event) use ($expectedKey, $job) {
            return $event->job === $job && $event->key === $expectedKey;
        });

        Queue::assertNothingPushed();
    }

    public function testUniqueJobContextIsPreservedWhenASuppressionListenerThrows()
    {
        Queue::fake();

        dispatch(new UniqueJobSuppressedTestJob);

        Context::addHidden([
            'laravel_unique_job_cache_store' => 'database',
            'laravel_unique_job_key' => 'stale-key',
        ]);

        Event::listen(UniqueJobSuppressed::class, function () {
            $this->assertSame('database', Context::getHidden('laravel_unique_job_cache_store'));
            $this->assertSame('stale-key', Context::getHidden('laravel_unique_job_key'));

            throw new RuntimeException('Suppression listener failed.');
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Suppression listener failed.');

        try {
            dispatch(new UniqueJobSuppressedTestJob);
        } finally {
            try {
                $this->assertSame('database', Context::getHidden('laravel_unique_job_cache_store'));
                $this->assertSame('stale-key', Context::getHidden('laravel_unique_job_key'));
            } finally {
                Context::forgetHidden([
                    'laravel_unique_job_cache_store',
                    'laravel_unique_job_key',
                ]);
            }
        }
    }

    public function testUniqueJobContextIsRemovedWhenDispatchThrows()
    {
        $dispatcher = m::mock(BusDispatcher::class);
        $dispatcher->shouldReceive('dispatch')->once()->andReturnUsing(function () {
            $this->assertSame('database', Context::getHidden('laravel_unique_job_cache_store'));
            $this->assertSame(
                UniqueLock::getKey(new UniqueJobSuppressedTestJob),
                Context::getHidden('laravel_unique_job_key')
            );

            throw new RuntimeException('Dispatch failed.');
        });

        $this->app->instance(BusDispatcher::class, $dispatcher);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Dispatch failed.');

        try {
            dispatch(new UniqueJobSuppressedTestJob);
        } finally {
            $this->assertTrue(Context::missingHidden('laravel_unique_job_cache_store'));
            $this->assertTrue(Context::missingHidden('laravel_unique_job_key'));
        }
    }

    public function testNoEventIsDispatchedForJobsThatAreNotUnique()
    {
        Queue::fake();
        Event::fake([UniqueJobSuppressed::class]);

        NotUniqueJobSuppressedTestJob::dispatch();
        NotUniqueJobSuppressedTestJob::dispatch();

        Event::assertNotDispatched(UniqueJobSuppressed::class);
    }
}

class UniqueJobSuppressedTestJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle()
    {
        //
    }
}

class NotUniqueJobSuppressedTestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle()
    {
        //
    }
}

class StatefulUniqueJobSuppressedTestJob extends UniqueJobSuppressedTestJob
{
    public $uniqueIdCalls = 0;

    public function uniqueId()
    {
        return ++$this->uniqueIdCalls;
    }
}
