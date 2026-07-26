<?php

namespace Illuminate\Tests\Queue;

use Exception;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\ReleasableWithoutAttempt;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\BeanstalkdJob;
use Illuminate\Queue\Jobs\SqsJob;
use Illuminate\Queue\Jobs\SyncJob;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class InteractsWithQueueTest extends TestCase
{
    public function testCreatesAnExceptionFromString()
    {
        $queueJob = m::mock(Job::class);
        $queueJob->shouldReceive('fail')->withArgs(function ($e) {
            $this->assertInstanceOf(Exception::class, $e);
            $this->assertSame('Whoops!', $e->getMessage());

            return true;
        });

        $job = new class
        {
            use InteractsWithQueue;

            public $job;
        };

        $job->job = $queueJob;
        $job->fail('Whoops!');
    }

    public function testReleaseRetainsItsOriginalSignatureAndBehavior()
    {
        $queueJob = m::mock(Job::class);
        $queueJob->shouldReceive('release')->once()->with(5);

        $job = new InteractsWithQueueOverridingReleaseJob;
        $job->job = $queueJob;

        $job->release(5);

        $this->assertSame(5, $job->releasedWith);
    }

    public function testReleaseWithoutAttemptUsesTheCapabilityOnSupportedDrivers()
    {
        $queueJob = m::mock(Job::class, ReleasableWithoutAttempt::class);
        $queueJob->shouldNotReceive('release');
        $queueJob->shouldReceive('releaseWithoutAttempt')->once()->with(5);

        $job = $this->jobUsingTheTrait();
        $job->job = $queueJob;

        $job->releaseWithoutAttempt(5);
    }

    #[DataProvider('unsupportedDriverProvider')]
    public function testReleaseWithoutAttemptThrowsWhenTheDriverDoesNotAdvertiseTheCapability($connection)
    {
        $queueJob = m::mock(Job::class);
        $queueJob->shouldNotReceive('release');
        $queueJob->shouldReceive('getConnectionName')->andReturn($connection);

        $job = $this->jobUsingTheTrait();
        $job->job = $queueJob;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("The [$connection] queue driver does not support releasing a job without counting an attempt.");

        $job->releaseWithoutAttempt(5);
    }

    public function testReleaseWithoutCountingAnAttemptIsSupportedByFakeQueueInteractions()
    {
        $job = $this->jobUsingTheTrait();

        $job->withFakeQueueInteractions()->releaseWithoutAttempt(5);

        $job->assertReleased(5);
    }

    #[DataProvider('unsupportedJobClassProvider')]
    public function testUnsupportedFirstPartyJobsDoNotAdvertiseTheCapability($jobClass)
    {
        $this->assertFalse(is_a($jobClass, ReleasableWithoutAttempt::class, true));
    }

    public static function unsupportedDriverProvider()
    {
        return [
            ['sync'],
            ['sqs'],
            ['beanstalkd'],
        ];
    }

    public static function unsupportedJobClassProvider()
    {
        return [
            [SyncJob::class],
            [SqsJob::class],
            [BeanstalkdJob::class],
        ];
    }

    protected function jobUsingTheTrait()
    {
        return new class
        {
            use InteractsWithQueue;
        };
    }
}

class InteractsWithQueueBaseJob
{
    use InteractsWithQueue;
}

class InteractsWithQueueOverridingReleaseJob extends InteractsWithQueueBaseJob
{
    public $releasedWith;

    public function release($delay = 0)
    {
        parent::release($delay);

        $this->releasedWith = $delay;
    }
}
