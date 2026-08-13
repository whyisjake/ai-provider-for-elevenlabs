<?php

declare(strict_types=1);

namespace AiProviderForElevenLabs\Tests\Unit\Jobs;

use AiProviderForElevenLabs\Jobs\NarrationJobStore;
use AiProviderForElevenLabs\Jobs\NarrationJobs;
use AiProviderForElevenLabs\Models\ProviderForElevenLabsTextToSpeechModel;
use AiProviderForElevenLabs\Tests\Support\MockNarrationJobStore;
use AiProviderForElevenLabs\Tests\Support\TemporaryNarrationDirectory;
use PHPUnit\Framework\TestCase;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;

/**
 * Unit tests for NarrationJobs.
 *
 * Chunking happens at enqueue rather than at run time, so unusable input fails
 * in front of the caller instead of silently inside a cron callback nobody is
 * watching. Most of what follows is about that boundary.
 *
 * @covers \AiProviderForElevenLabs\Jobs\NarrationJobs
 */
class NarrationJobsTest extends TestCase
{
    use TemporaryNarrationDirectory;

    private MockNarrationJobStore $store;

    private MockWpCronNarrationQueue $queue;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = $this->createNarrationStore();
        $this->queue = new MockWpCronNarrationQueue();
    }

    protected function tearDown(): void
    {
        $this->removeNarrationDirectory();

        parent::tearDown();
    }

    /**
     * Builds a facade backed by a stub model.
     *
     * @param string $outputFormat The format the stub model reports.
     * @return NarrationJobs
     */
    private function createJobs(string $outputFormat = 'mp3_44100_128'): NarrationJobs
    {
        $model = $this->createMock(ProviderForElevenLabsTextToSpeechModel::class);
        $model->method('getVoiceId')->willReturn('voice-1');
        $model->method('resolveOutputFormat')->willReturn($outputFormat);
        $model->method('resolveMimeTypeFromFormat')->willReturn('audio/mpeg');
        $model->method('splitTextForRequests')->willReturnCallback(
            static function (string $text, string $format, ?int $limit = null): array {
                $limit = $limit ?? 10000;

                return $text === '' ? [] : (array) str_split($text, $limit);
            }
        );

        return new NarrationJobs($this->store, $this->queue, static fn() => $model);
    }

    // ------------------------------------------------------------------
    // Enqueueing
    // ------------------------------------------------------------------

    public function testEnqueueingReturnsAJobIdAndReportsNothingDoneYet(): void
    {
        $jobs = $this->createJobs();
        $jobId = $jobs->enqueue(str_repeat('a', 9000), ['chunk_size' => 4000]);

        $progress = $jobs->progress($jobId);

        $this->assertNotNull($progress);
        $this->assertSame(0, $progress['completed']);
        $this->assertSame(3, $progress['total']);
        $this->assertSame(NarrationJobStore::STATUS_PENDING, $progress['status']);
    }

    public function testEnqueueingSchedulesTheFirstChunkAndOnlyTheFirst(): void
    {
        $jobs = $this->createJobs();
        $jobId = $jobs->enqueue(str_repeat('a', 9000), ['chunk_size' => 4000]);

        $this->assertTrue($this->queue->isChunkPending($jobId, 0));
        $this->assertFalse($this->queue->isChunkPending($jobId, 1));
        $this->assertFalse($this->queue->isChunkPending($jobId, 2));
    }

    public function testEnqueueingRecordsTheResolvedVoiceAndFormat(): void
    {
        $jobs = $this->createJobs();
        $jobId = $jobs->enqueue('short text');

        $record = $this->store->get($jobId);
        $this->assertNotNull($record);
        $this->assertSame('voice-1', $record['voice_id']);
        $this->assertSame('mp3_44100_128', $record['output_format']);
        $this->assertSame('audio/mpeg', $record['mime_type']);
    }

    public function testEnqueueingCreatesTheJobDirectoryUpFront(): void
    {
        $jobs = $this->createJobs();
        $jobId = $jobs->enqueue('short text');

        $this->assertDirectoryExists($this->store->jobDirectory($jobId));
    }

    /**
     * Text short enough for one chunk is an ordinary one-chunk job, not a
     * special case, so there is only one path to keep working.
     */
    public function testShortTextBecomesAOneChunkJob(): void
    {
        $jobs = $this->createJobs();
        $jobId = $jobs->enqueue('short text');

        $progress = $jobs->progress($jobId);
        $this->assertNotNull($progress);
        $this->assertSame(1, $progress['total']);
    }

    public function testEmptyTextIsRefusedBeforeAnyJobExists(): void
    {
        $jobs = $this->createJobs();

        $this->expectException(InvalidArgumentException::class);
        $jobs->enqueue('   ');
    }

    public function testTheChunkSizeOptionIsHonoured(): void
    {
        $jobs = $this->createJobs();

        $small = $jobs->enqueue(str_repeat('a', 900), ['chunk_size' => 100]);
        $large = $jobs->enqueue(str_repeat('a', 900), ['chunk_size' => 500]);

        $smallProgress = $jobs->progress($small);
        $largeProgress = $jobs->progress($large);

        $this->assertNotNull($smallProgress);
        $this->assertNotNull($largeProgress);
        $this->assertSame(9, $smallProgress['total']);
        $this->assertSame(2, $largeProgress['total']);
    }

    // ------------------------------------------------------------------
    // Progress and cancellation
    // ------------------------------------------------------------------

    public function testProgressForAnUnknownJobIsNull(): void
    {
        $this->assertNull($this->createJobs()->progress('nope'));
    }

    public function testCancellingClearsPendingWorkAndStopsTheJob(): void
    {
        $jobs = $this->createJobs();
        $jobId = $jobs->enqueue(str_repeat('a', 9000), ['chunk_size' => 4000]);

        $jobs->cancel($jobId);

        $this->assertFalse($this->queue->isChunkPending($jobId, 0));

        $progress = $jobs->progress($jobId);
        $this->assertNotNull($progress);
        $this->assertSame(NarrationJobStore::STATUS_CANCELLED, $progress['status']);
    }

    public function testCancellingRemovesTheJobDirectory(): void
    {
        $jobs = $this->createJobs();
        $jobId = $jobs->enqueue('short text');

        $jobs->cancel($jobId);

        $this->assertDirectoryDoesNotExist($this->store->jobDirectory($jobId));
    }

    public function testCancellingAnUnknownJobIsHarmless(): void
    {
        $this->createJobs()->cancel('nope');

        $this->addToAssertionCount(1);
    }

    public function testCancellingClearsEveryChunkNotJustTheScheduledOne(): void
    {
        $jobs = $this->createJobs();
        $jobId = $jobs->enqueue(str_repeat('a', 9000), ['chunk_size' => 4000]);

        // Simulate a runner having scheduled a later chunk before cancellation.
        $this->queue->scheduleChunk($jobId, 2);

        $jobs->cancel($jobId);

        $this->assertFalse($this->queue->isChunkPending($jobId, 0));
        $this->assertFalse($this->queue->isChunkPending($jobId, 2));
    }
}
