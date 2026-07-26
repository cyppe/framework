<?php

namespace Illuminate\Tests\Integration\Queue;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\BatchAlreadyExistsException;
use Illuminate\Bus\DynamoBatchRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Orchestra\Testbench\Attributes\RequiresEnv;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;

#[RequiresOperatingSystem('Linux|Darwin')]
#[RequiresEnv('DYNAMODB_ENDPOINT')]
class DynamoBatchTest extends TestCase
{
    protected function setUp(): void
    {
        $this->afterApplicationCreated(function () {
            BatchRunRecorder::reset();
            app(DynamoBatchRepository::class)->createAwsDynamoTable();
        });

        $this->beforeApplicationDestroyed(function () {
            app(DynamoBatchRepository::class)->deleteAwsDynamoTable();
        });

        parent::setUp();
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('queue.batching', [
            'driver' => 'dynamodb',
            'region' => 'us-west-2',
            'endpoint' => Env::get('DYNAMODB_ENDPOINT'),
            'key' => 'key',
            'secret' => 'secret',
        ]);
    }

    public function test_running_a_batch()
    {
        Bus::batch([
            new BatchJob('1'),
            new BatchJob('2'),
        ])->dispatch();

        $this->assertEquals(['1', '2'], BatchRunRecorder::$results);
    }

    public function test_retrieve_batch_by_id()
    {
        $batch = Bus::batch([
            new BatchJob('1'),
            new BatchJob('2'),
        ])->dispatch();

        /** @var DynamoBatchRepository */
        $repo = app(DynamoBatchRepository::class);
        $retrieved = $repo->find($batch->id);
        $this->assertEquals(2, $retrieved->totalJobs);
        $this->assertEquals(0, $retrieved->failedJobs);
        $this->assertTrue($retrieved->finishedAt->between(Carbon::now()->subSeconds(3), Carbon::now()));
    }

    public function test_batch_may_be_stored_with_a_given_id()
    {
        $id = (string) Str::uuid7();

        $batch = Bus::batch([
            new BatchJob('1'),
        ])->withId($id)->dispatch();

        $this->assertSame($id, $batch->id);
        $this->assertSame($id, app(DynamoBatchRepository::class)->find($id)->id);
    }

    public function test_duplicate_batch_id_does_not_overwrite_the_existing_batch()
    {
        $id = (string) Str::uuid7();

        Bus::batch([new BatchJob('1')])->withId($id)->name('first batch')->dispatch();

        try {
            Bus::batch([new BatchJob('2')])->withId($id)->name('second batch')->dispatch();

            $this->fail('A duplicate batch ID did not throw an exception.');
        } catch (BatchAlreadyExistsException $e) {
            $this->assertSame($id, $e->batchId);
        }

        $this->assertSame('first batch', app(DynamoBatchRepository::class)->find($id)->name);
    }

    public function test_custom_batch_ids_preserve_listing_order_and_pagination()
    {
        $ids = [
            '01890f2d-3b5a-7cc0-98c4-dc0c0c07398f',
            '01890f2d-3b5b-7cc0-98c4-dc0c0c07398f',
            '01890f2d-3b5c-7cc0-98c4-dc0c0c07398f',
        ];

        foreach ($ids as $id) {
            Bus::batch([new BatchJob($id)])->withId($id)->dispatch();
        }

        /** @var DynamoBatchRepository */
        $repository = app(DynamoBatchRepository::class);

        $this->assertSame(array_reverse($ids), array_column($repository->get(), 'id'));
        $this->assertSame([$ids[0]], array_column($repository->get(50, $ids[1]), 'id'));
    }

    public function test_retrieve_non_existent_batch()
    {
        /** @var DynamoBatchRepository */
        $repo = app(DynamoBatchRepository::class);
        $retrieved = $repo->find(Str::orderedUuid());
        $this->assertNull($retrieved);
    }

    public function test_delete_batch_by_id()
    {
        $batch = Bus::batch([
            new BatchJob('1'),
        ])->dispatch();

        /** @var DynamoBatchRepository */
        $repo = app(DynamoBatchRepository::class);
        $retrieved = $repo->find($batch->id);
        $this->assertNotNull($retrieved);
        $repo->delete($retrieved->id);
        $retrieved = $repo->find($batch->id);
        $this->assertNull($retrieved);
    }

    public function test_delete_non_existent_batch()
    {
        /** @var DynamoBatchRepository */
        $repo = app(DynamoBatchRepository::class);
        $repo->delete(Str::orderedUuid());
        // Ensure we didn't throw an exception
        $this->assertTrue(true);
    }

    public function test_batch_with_failing_job()
    {
        $batch = Bus::batch([
            new BatchJob('1'),
            new FailingJob('2'),
        ])->dispatch();

        /** @var DynamoBatchRepository */
        $repo = app(DynamoBatchRepository::class);
        $retrieved = $repo->find($batch->id);
        $this->assertEquals(2, $retrieved->totalJobs);
        $this->assertEquals(1, $retrieved->failedJobs);
        $this->assertTrue($retrieved->finishedAt->between(Carbon::now()->subSeconds(3), Carbon::now()));
        $this->assertTrue($retrieved->cancelledAt->between(Carbon::now()->subSeconds(3), Carbon::now()));
    }

    public function test_get_batches()
    {
        $batches = [
            Bus::batch([new BatchJob('1')])->dispatch(),
            Bus::batch([new BatchJob('1')])->dispatch(),
            Bus::batch([new BatchJob('1')])->dispatch(),
            Bus::batch([new BatchJob('1')])->dispatch(),
            Bus::batch([new BatchJob('1')])->dispatch(),
            Bus::batch([new BatchJob('1')])->dispatch(),
            Bus::batch([new BatchJob('1')])->dispatch(),
            Bus::batch([new BatchJob('1')])->dispatch(),
            Bus::batch([new BatchJob('1')])->dispatch(),
            Bus::batch([new BatchJob('1')])->dispatch(),
        ];

        /** @var DynamoBatchRepository */
        $repo = app(DynamoBatchRepository::class);
        $this->assertCount(10, $repo->get());
        $this->assertCount(6, $repo->get(6));
        $this->assertCount(6, $repo->get(100, $batches[6]->id));
        $this->assertCount(0, $repo->get(100, $batches[0]->id));
        $this->assertCount(9, $repo->get(100, $batches[9]->id));
        $this->assertCount(10, $repo->get(100, Str::orderedUuid()));
    }
}

class BatchJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable;

    public static $results = [];

    public string $id;

    public function __construct(string $id)
    {
        $this->id = $id;
    }

    public function handle()
    {
        BatchRunRecorder::record($this->id);
    }
}

class FailingJob extends BatchJob
{
    public function handle()
    {
        BatchRunRecorder::recordFailure($this->id);
        $this->fail();
    }
}

class BatchRunRecorder
{
    public static $results = [];

    public static $failures = [];

    public static function record(string $id)
    {
        self::$results[] = $id;
    }

    public static function recordFailure(string $message)
    {
        self::$failures[] = $message;

        return $message;
    }

    public static function reset()
    {
        self::$results = [];
        self::$failures = [];
    }
}
