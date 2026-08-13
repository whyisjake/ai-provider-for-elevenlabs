<?php

declare(strict_types=1);

namespace AiProviderForElevenLabs\Tests\Unit\Jobs;

use AiProviderForElevenLabs\Jobs\NarrationQueue;
use AiProviderForElevenLabs\Jobs\WpCronNarrationQueue;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for WpCronNarrationQueue.
 *
 * The property worth guarding hardest is that a chunk is never scheduled twice.
 * A duplicate event is a duplicate API request and a duplicate bill, and unlike
 * most bugs it costs money every time it happens.
 *
 * @covers \AiProviderForElevenLabs\Jobs\WpCronNarrationQueue
 */
class WpCronNarrationQueueTest extends TestCase
{
    private MockWpCronNarrationQueue $queue;

    protected function setUp(): void
    {
        parent::setUp();

        $this->queue = new MockWpCronNarrationQueue();
    }

    public function testSchedulingAChunkMakesItPending(): void
    {
        $this->assertFalse($this->queue->isChunkPending('job-1', 0));

        $this->assertTrue($this->queue->scheduleChunk('job-1', 0));
        $this->assertTrue($this->queue->isChunkPending('job-1', 0));
    }

    /**
     * Core keys events by md5(serialize($args)), so the arguments are the
     * event's identity. They carry the two values that name the work and
     * nothing else -- the rest lives in the job record, because the cron option
     * is autoloaded on every page request.
     */
    public function testEventArgumentsCarryOnlyTheJobIdAndChunkIndex(): void
    {
        $this->assertSame(['job-1', 3], $this->queue->exposeEventArgs('job-1', 3));

        $this->queue->scheduleChunk('job-1', 3);

        $this->assertSame([['job-1', 3]], $this->queue->scheduled);
    }

    public function testSchedulingTheSameChunkTwiceDoesNotDuplicateIt(): void
    {
        $this->assertTrue($this->queue->scheduleChunk('job-1', 0));
        $this->assertTrue($this->queue->scheduleChunk('job-1', 0));

        $this->assertCount(1, $this->queue->scheduled, 'The chunk was scheduled more than once.');
    }

    public function testCancellingAChunkClearsItsPendingWork(): void
    {
        $this->queue->scheduleChunk('job-1', 0);
        $this->queue->cancelChunk('job-1', 0);

        $this->assertFalse($this->queue->isChunkPending('job-1', 0));
    }

    public function testCancellingAChunkThatWasNeverScheduledIsHarmless(): void
    {
        $this->queue->cancelChunk('job-1', 0);

        $this->assertFalse($this->queue->isChunkPending('job-1', 0));
    }

    public function testChunksOfTheSameJobAreTrackedIndependently(): void
    {
        $this->queue->scheduleChunk('job-1', 0);

        $this->assertTrue($this->queue->isChunkPending('job-1', 0));
        $this->assertFalse($this->queue->isChunkPending('job-1', 1));
    }

    public function testDifferentJobsAreTrackedIndependently(): void
    {
        $this->queue->scheduleChunk('job-1', 0);

        $this->assertTrue($this->queue->isChunkPending('job-1', 0));
        $this->assertFalse($this->queue->isChunkPending('job-2', 0));
    }

    public function testCancellingOneChunkLeavesOthersAlone(): void
    {
        $this->queue->scheduleChunk('job-1', 0);
        $this->queue->scheduleChunk('job-1', 1);

        $this->queue->cancelChunk('job-1', 0);

        $this->assertFalse($this->queue->isChunkPending('job-1', 0));
        $this->assertTrue($this->queue->isChunkPending('job-1', 1));
    }

    public function testACancelledChunkCanBeScheduledAgain(): void
    {
        $this->queue->scheduleChunk('job-1', 0);
        $this->queue->cancelChunk('job-1', 0);

        $this->assertTrue($this->queue->scheduleChunk('job-1', 0));
        $this->assertTrue($this->queue->isChunkPending('job-1', 0));
    }

    public function testResolveReturnsTheDefaultRunnerWhenNothingSubstitutesOne(): void
    {
        $queue = WpCronNarrationQueue::resolve();

        $this->assertInstanceOf(NarrationQueue::class, $queue);
        $this->assertInstanceOf(WpCronNarrationQueue::class, $queue);
    }
}
