<?php

namespace Illuminate\Tests\Queue;

use Exception;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\ReleasableWithoutAttempt;
use Illuminate\Queue\InteractsWithQueue;
use Mockery as m;
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

    public function testReleaseCountsAnAttemptByDefault()
    {
        $queueJob = m::mock(Job::class);
        $queueJob->shouldReceive('release')->once()->with(5);

        $job = $this->jobUsingTheTrait();
        $job->job = $queueJob;

        $job->release(5);
    }

    public function testReleaseCanSkipCountingAnAttemptOnSupportedDrivers()
    {
        $queueJob = m::mock(Job::class, ReleasableWithoutAttempt::class);
        $queueJob->shouldNotReceive('release');
        $queueJob->shouldReceive('releaseWithoutAttempt')->once()->with(5);

        $job = $this->jobUsingTheTrait();
        $job->job = $queueJob;

        $job->release(5, countAsAttempt: false);
    }

    public function testReleaseThrowsWhenTheDriverCannotSkipCountingAnAttempt()
    {
        $queueJob = m::mock(Job::class);
        $queueJob->shouldNotReceive('release');
        $queueJob->shouldReceive('getConnectionName')->andReturn('sqs');

        $job = $this->jobUsingTheTrait();
        $job->job = $queueJob;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The [sqs] queue driver does not support releasing a job without counting an attempt.');

        $job->release(5, countAsAttempt: false);
    }

    public function testReleaseWithoutCountingAnAttemptIsSupportedByFakeQueueInteractions()
    {
        $job = $this->jobUsingTheTrait();

        $job->withFakeQueueInteractions()->release(5, countAsAttempt: false);

        $job->assertReleased(5);
    }

    protected function jobUsingTheTrait()
    {
        return new class
        {
            use InteractsWithQueue;
        };
    }
}
