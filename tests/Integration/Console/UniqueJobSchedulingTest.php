<?php

namespace Illuminate\Tests\Integration\Console;

use Illuminate\Bus\Queueable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Events\UniqueJobSuppressed;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Orchestra\Testbench\TestCase;

class UniqueJobSchedulingTest extends TestCase
{
    public function testJobsPushedToQueue(): void
    {
        Queue::fake();
        $this->dispatch(
            TestJob::class,
            TestJob::class,
            TestJob::class,
            TestJob::class
        );

        Queue::assertPushed(TestJob::class, 4);
    }

    public function testUniqueJobsPushedToQueue(): void
    {
        Queue::fake();
        Event::fake([UniqueJobSuppressed::class]);

        $queuedJob = new UniqueTestJob;
        $firstSuppressedJob = new UniqueTestJob;
        $secondSuppressedJob = new UniqueTestJob;
        $thirdSuppressedJob = new UniqueTestJob;

        $this->dispatch(
            $queuedJob,
            $firstSuppressedJob,
            $secondSuppressedJob,
            $thirdSuppressedJob,
        );

        Queue::assertPushed(UniqueTestJob::class, 1);
        Event::assertDispatchedTimes(UniqueJobSuppressed::class, 3);

        foreach ([$firstSuppressedJob, $secondSuppressedJob, $thirdSuppressedJob] as $suppressedJob) {
            Event::assertDispatched(UniqueJobSuppressed::class, function ($event) use ($suppressedJob) {
                return $event->job === $suppressedJob
                    && $event->key === UniqueLock::getKey($suppressedJob);
            });
        }
    }

    private function dispatch(...$jobs)
    {
        /** @var \Illuminate\Console\Scheduling\Schedule $scheduler */
        $scheduler = $this->app->make(Schedule::class);
        foreach ($jobs as $job) {
            $scheduler->job($job)->name('')->everyMinute();
        }
        $events = $scheduler->events();
        foreach ($events as $event) {
            $event->run($this->app);
        }
    }
}

class TestJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, Dispatchable;
}

class UniqueTestJob extends TestJob implements ShouldBeUnique
{
}
