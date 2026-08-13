<?php

declare(strict_types=1);

namespace AiProviderForElevenLabs\Tests\Unit\Jobs;

use AiProviderForElevenLabs\Jobs\NarrationJobRunner;
use AiProviderForElevenLabs\Jobs\NarrationJobStore;
use AiProviderForElevenLabs\Models\ProviderForElevenLabsTextToSpeechModel;
use AiProviderForElevenLabs\Tests\Support\MockNarrationJobStore;
use AiProviderForElevenLabs\Tests\Support\TemporaryNarrationDirectory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for NarrationJobRunner.
 *
 * Two properties carry most of the weight. Chunks must be joined in index
 * order, because audio joined in completion order plays perfectly while being
 * the wrong article. And a chunk another runner holds must produce no request
 * at all, because a duplicate chunk is a duplicate bill.
 *
 * @covers \AiProviderForElevenLabs\Jobs\NarrationJobRunner
 */
class NarrationJobRunnerTest extends TestCase
{
    use TemporaryNarrationDirectory;

    private MockNarrationJobStore $store;

    private MockWpCronNarrationQueue $queue;

    /**
     * Text passed to narrateChunk, in call order.
     *
     * @var list<string>
     */
    private array $narrated = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = $this->createNarrationStore();
        $this->queue = new MockWpCronNarrationQueue();
        $this->narrated = [];
    }

    protected function tearDown(): void
    {
        $this->removeNarrationDirectory();

        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(): array
    {
        return [
            'model_id'      => 'eleven_multilingual_v2',
            'voice_id'      => 'voice-1',
            'output_format' => 'mp3_44100_128',
            'mime_type'     => 'audio/mpeg',
        ];
    }

    /**
     * Builds a runner whose model returns predictable audio per chunk.
     *
     * @param callable|null $onNarrate Called with the chunk text; may throw.
     * @return MockNarrationJobRunner
     */
    private function createRunner(?callable $onNarrate = null): MockNarrationJobRunner
    {
        $model = $this->createMock(ProviderForElevenLabsTextToSpeechModel::class);
        $model->method('narrateChunk')->willReturnCallback(
            function (string $voiceId, string $chunk) use ($onNarrate): string {
                $this->narrated[] = $chunk;

                if ($onNarrate !== null) {
                    $onNarrate($chunk);
                }

                return '[' . $chunk . ']';
            }
        );

        return new MockNarrationJobRunner(
            $this->store,
            $this->queue,
            static fn(array $record) => $model
        );
    }

    /**
     * Fires the queue until nothing is pending, as cron eventually would.
     *
     * @param MockNarrationJobRunner $runner The runner.
     * @param string                 $jobId  The job id.
     * @param int                    $limit  Safety bound on iterations.
     * @return int How many times the runner ran.
     */
    private function drain(MockNarrationJobRunner $runner, string $jobId, int $limit = 20): int
    {
        $runs = 0;

        // Enqueueing the first chunk is the facade's job, so seed it here.
        $first = $this->store->nextPendingChunk($jobId);
        if ($first !== null) {
            $this->queue->scheduleChunk($jobId, $first);
        }

        for ($index = 0; $index < $limit; $index++) {
            $pending = null;

            for ($chunk = 0; $chunk < $limit; $chunk++) {
                if ($this->queue->isChunkPending($jobId, $chunk)) {
                    $pending = $chunk;
                    break;
                }
            }

            if ($pending === null) {
                break;
            }

            $this->queue->cancelChunk($jobId, $pending);
            $runner->run($jobId, $pending);
            $runs++;
        }

        return $runs;
    }

    // ------------------------------------------------------------------
    // Driving a job to completion
    // ------------------------------------------------------------------

    public function testAThreeChunkJobNarratesEveryChunkAndSchedulesTheNext(): void
    {
        $jobId = $this->store->create(['one', 'two', 'three'], $this->meta());
        $runner = $this->createRunner();

        $runner->run($jobId, 0);

        $this->assertSame(['one'], $this->narrated);
        $this->assertTrue($this->queue->isChunkPending($jobId, 1), 'The next chunk was not scheduled.');
    }

    public function testTheFinalChunkAssemblesRatherThanSchedulingAnother(): void
    {
        $jobId = $this->store->create(['one', 'two'], $this->meta());
        $runner = $this->createRunner();

        $this->drain($runner, $jobId);

        $this->assertSame(['one', 'two'], $this->narrated);
        $this->assertFalse($this->queue->isChunkPending($jobId, 0));
        $this->assertFalse($this->queue->isChunkPending($jobId, 1));
        $this->assertSame('[one][two]', $runner->joined);
    }

    public function testASingleChunkJobCompletesWithoutSchedulingAFollowOn(): void
    {
        $jobId = $this->store->create(['only'], $this->meta());
        $runner = $this->createRunner();

        $runner->run($jobId, 0);

        $this->assertSame('[only]', $runner->joined);
        $this->assertFalse($this->queue->isChunkPending($jobId, 1));

        $progress = $this->store->progress($jobId);
        $this->assertNotNull($progress);
        $this->assertSame(NarrationJobStore::STATUS_COMPLETE, $progress['status']);
    }

    public function testCompletionRecordsTheAttachmentAndFiresTheHook(): void
    {
        $jobId = $this->store->create(['only'], $this->meta());
        $runner = $this->createRunner();

        $runner->run($jobId, 0);

        $this->assertSame([NarrationJobRunner::HOOK_COMPLETE], $runner->firedHooks());

        $progress = $this->store->progress($jobId);
        $this->assertNotNull($progress);
        $this->assertSame(4242, $progress['attachment_id']);
    }

    /**
     * Retries can finish out of order. Joining by completion order produces a
     * file that plays fine and says the wrong thing.
     */
    public function testChunksAreJoinedInIndexOrderNotCompletionOrder(): void
    {
        $jobId = $this->store->create(['one', 'two', 'three'], $this->meta());
        $runner = $this->createRunner();

        $runner->run($jobId, 2);
        $runner->run($jobId, 0);
        $runner->run($jobId, 1);

        $this->assertSame('[one][two][three]', $runner->joined);
    }

    public function testTheJobDirectoryIsRemovedOnceTheAudioIsDelivered(): void
    {
        $jobId = $this->store->create(['only'], $this->meta());
        $runner = $this->createRunner();

        $runner->run($jobId, 0);

        $this->assertDirectoryDoesNotExist($this->store->jobDirectory($jobId));
    }

    // ------------------------------------------------------------------
    // Exclusivity
    // ------------------------------------------------------------------

    public function testAChunkHeldByAnotherRunnerProducesNoRequest(): void
    {
        $jobId = $this->store->create(['one', 'two'], $this->meta());

        $this->store->claimChunk($jobId, 0, 'someone-else', 300);

        $runner = $this->createRunner();
        $runner->run($jobId, 0);

        $this->assertSame([], $this->narrated, 'A claimed chunk was narrated anyway.');
    }

    public function testAnAlreadyCompletedChunkIsNotNarratedAgain(): void
    {
        $jobId = $this->store->create(['one', 'two'], $this->meta());
        $this->store->completeChunk($jobId, 0, '/tmp/one');

        $runner = $this->createRunner();
        $runner->run($jobId, 0);

        $this->assertSame([], $this->narrated);
        $this->assertTrue($this->queue->isChunkPending($jobId, 1), 'The job should still advance.');
    }

    // ------------------------------------------------------------------
    // Failure and retry
    // ------------------------------------------------------------------

    public function testAFailedChunkIsRescheduledRatherThanLost(): void
    {
        $jobId = $this->store->create(['one'], $this->meta());
        $runner = $this->createRunner(static function (): void {
            throw new RuntimeException('the API said no');
        });

        $runner->run($jobId, 0);

        $this->assertTrue($this->queue->isChunkPending($jobId, 0), 'The failed chunk was not retried.');

        $progress = $this->store->progress($jobId);
        $this->assertNotNull($progress);
        $this->assertSame(NarrationJobStore::STATUS_RUNNING, $progress['status']);
    }

    /**
     * A fresh runner per attempt, because that is what actually happens: each
     * retry is a separate cron fire in a separate PHP process with its own
     * claim owner. Reusing one runner hides the case where a chunk's own dead
     * predecessor refuses its retry.
     */
    public function testAChunkThatKeepsFailingEventuallyFailsTheJob(): void
    {
        $jobId = $this->store->create(['one'], $this->meta());
        $failing = static function (): void {
            throw new RuntimeException('the API said no');
        };

        $last = null;

        for ($attempt = 0; $attempt < NarrationJobRunner::MAX_ATTEMPTS; $attempt++) {
            $this->queue->cancelChunk($jobId, 0);
            $last = $this->createRunner($failing);
            $last->run($jobId, 0);
        }

        $this->assertSame(NarrationJobRunner::MAX_ATTEMPTS, count($this->narrated));

        $progress = $this->store->progress($jobId);
        $this->assertNotNull($progress);
        $this->assertSame(NarrationJobStore::STATUS_FAILED, $progress['status']);
        $this->assertStringContainsString('the API said no', (string) $progress['error']);
        $this->assertNotNull($last);
        $this->assertSame([NarrationJobRunner::HOOK_FAILED], $last->firedHooks());
    }

    /**
     * The regression behind the above. A failed chunk left its claim in place,
     * so the retry -- a different process, a different owner -- was refused by
     * its own dead predecessor for the remaining five minutes of the claim.
     * The job sat in "running" with no pending work and no error, forever.
     */
    public function testARetryIsNotBlockedByTheClaimOfTheAttemptThatFailed(): void
    {
        $jobId = $this->store->create(['one'], $this->meta());
        $failing = static function (): void {
            throw new RuntimeException('transient');
        };

        $this->createRunner($failing)->run($jobId, 0);

        $this->assertTrue($this->queue->isChunkPending($jobId, 0), 'The retry was never scheduled.');

        // A different process picks the retry up.
        $this->queue->cancelChunk($jobId, 0);
        $second = $this->createRunner();
        $second->run($jobId, 0);

        $this->assertSame(['one', 'one'], $this->narrated, 'The retry never ran.');
        $this->assertSame('[one]', $second->joined);
    }

    public function testAFailedJobCleansUpItsDirectory(): void
    {
        $jobId = $this->store->create(['one'], $this->meta());
        $runner = $this->createRunner(static function (): void {
            throw new RuntimeException('nope');
        });

        for ($attempt = 0; $attempt < NarrationJobRunner::MAX_ATTEMPTS; $attempt++) {
            $this->queue->cancelChunk($jobId, 0);
            $runner->run($jobId, 0);
        }

        $this->assertDirectoryDoesNotExist($this->store->jobDirectory($jobId));
    }

    public function testAFailedJobDoesNotKeepNarrating(): void
    {
        $jobId = $this->store->create(['one', 'two'], $this->meta());
        $this->store->failJob($jobId, 'stopped');

        $runner = $this->createRunner();
        $runner->run($jobId, 0);

        $this->assertSame([], $this->narrated);
    }

    /**
     * Delivery is the last step, and the audio on disk is the only copy. A job
     * that reports success with no attachment hands the caller nothing, and
     * deleting the audio on the way turns it into a wasted bill.
     */
    public function testAFailedAttachmentFailsTheJobAndKeepsTheAudio(): void
    {
        $jobId = $this->store->create(['one'], $this->meta());
        $runner = $this->createRunner();
        $runner->attachmentId = 0;

        $runner->run($jobId, 0);

        $progress = $this->store->progress($jobId);
        $this->assertNotNull($progress);
        $this->assertSame(NarrationJobStore::STATUS_FAILED, $progress['status']);
        $this->assertStringContainsString('media library', (string) $progress['error']);
        $this->assertSame([NarrationJobRunner::HOOK_FAILED], $runner->firedHooks());

        $this->assertDirectoryExists(
            $this->store->jobDirectory($jobId),
            'The narrated audio was destroyed when only delivery failed.'
        );
    }

    /**
     * Cancellation can land while a chunk is mid-flight. Delivering an
     * attachment the caller asked not to have is worse than doing nothing.
     */
    public function testAJobCancelledMidChunkIsNotDeliveredAnyway(): void
    {
        $jobId = $this->store->create(['one'], $this->meta());

        $runner = $this->createRunner(function () use ($jobId): void {
            // Cancelled after the status check at the top of run().
            $this->store->cancelJob($jobId);
        });

        $runner->run($jobId, 0);

        $progress = $this->store->progress($jobId);
        $this->assertNotNull($progress);
        $this->assertSame(NarrationJobStore::STATUS_CANCELLED, $progress['status']);
        $this->assertNull($runner->joined, 'A cancelled job was delivered anyway.');
    }

    // ------------------------------------------------------------------
    // Defensive exits
    // ------------------------------------------------------------------

    public function testAJobThatVanishedMidFlightStopsCleanly(): void
    {
        $jobId = $this->store->create(['one'], $this->meta());
        $this->store->delete($jobId);

        $runner = $this->createRunner();
        $runner->run($jobId, 0);

        $this->assertSame([], $this->narrated);
        $this->assertSame([], $runner->firedHooks());
    }

    public function testAChunkIndexThatDoesNotExistStopsCleanly(): void
    {
        $jobId = $this->store->create(['one'], $this->meta());

        $runner = $this->createRunner();
        $runner->run($jobId, 99);

        $this->assertSame([], $this->narrated);
    }

    public function testACancelledJobDoesNotNarrate(): void
    {
        $jobId = $this->store->create(['one'], $this->meta());
        $this->store->cancelJob($jobId);

        $runner = $this->createRunner();
        $runner->run($jobId, 0);

        $this->assertSame([], $this->narrated);
    }

    public function testACompletedJobIsNotNarratedAgain(): void
    {
        $jobId = $this->store->create(['one'], $this->meta());
        $this->store->completeJob($jobId, 1);

        $runner = $this->createRunner();
        $runner->run($jobId, 0);

        $this->assertSame([], $this->narrated);
    }
}
