<?php

namespace Illuminate\Tests\Integration\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Events\UniqueJobSuppressed;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Orchestra\Testbench\Attributes\WithMigration;

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

    public function testEventIsDispatchedWhenUniquenessSuppressesTheDispatch()
    {
        Queue::fake();

        UniqueJobSuppressedTestJob::dispatch();

        Event::fake([UniqueJobSuppressed::class]);

        UniqueJobSuppressedTestJob::dispatch();

        Event::assertDispatched(UniqueJobSuppressed::class, function ($event) {
            return $event->job instanceof UniqueJobSuppressedTestJob
                && $event->key === UniqueLock::getKey(new UniqueJobSuppressedTestJob);
        });

        Queue::assertPushed(UniqueJobSuppressedTestJob::class, 1);
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
