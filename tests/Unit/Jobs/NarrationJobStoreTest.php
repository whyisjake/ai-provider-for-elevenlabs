<?php

declare(strict_types=1);

namespace AiProviderForElevenLabs\Tests\Unit\Jobs;

use AiProviderForElevenLabs\Jobs\NarrationJobStore;
use PHPUnit\Framework\TestCase;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;

/**
 * Unit tests for NarrationJobStore.
 *
 * The properties that matter most are durability and exclusivity. A job must
 * survive the death of the request that created it, and two runners must never
 * narrate the same chunk -- the first because WP-Cron deletes an event before
 * running it, the second because a duplicate chunk is a duplicate bill.
 *
 * @covers \AiProviderForElevenLabs\Jobs\NarrationJobStore
 */
class NarrationJobStoreTest extends TestCase
{
    private string $base;

    private MockNarrationJobStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->base = sys_get_temp_dir() . '/narration-store-test-' . bin2hex(random_bytes(6));
        $this->store = new MockNarrationJobStore($this->base);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->base)) {
            foreach ((array) glob($this->base . '/*') as $directory) {
                if (is_dir((string) $directory)) {
                    foreach ((array) glob($directory . '/*') as $file) {
                        unlink((string) $file);
                    }
                    rmdir((string) $directory);
                }
            }
            rmdir($this->base);
        }

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

    // ------------------------------------------------------------------
    // Creation and reading
    // ------------------------------------------------------------------

    public function testCreatedJobIsRetrievableWithItsChunksInOrder(): void
    {
        $jobId = $this->store->create(['one', 'two', 'three'], $this->meta());
        $record = $this->store->get($jobId);

        $this->assertNotNull($record);
        $this->assertSame(['one', 'two', 'three'], array_column($record['chunks'], 'text'));
        $this->assertSame('voice-1', $record['voice_id']);
        $this->assertSame(NarrationJobStore::STATUS_PENDING, $record['status']);
    }

    public function testCreatingAJobWithNoChunksRaises(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->store->create([], $this->meta());
    }

    public function testAnUnknownJobReadsAsAbsentRatherThanRaising(): void
    {
        $this->assertNull($this->store->get('does-not-exist'));
        $this->assertNull($this->store->progress('does-not-exist'));
        $this->assertNull($this->store->nextPendingChunk('does-not-exist'));
        $this->assertSame(0, $this->store->attempts('does-not-exist', 0));
        $this->assertSame([], $this->store->completedPaths('does-not-exist'));
    }

    public function testEachJobGetsItsOwnIdentity(): void
    {
        $first = $this->store->create(['a'], $this->meta());
        $second = $this->store->create(['a'], $this->meta());

        $this->assertNotSame($first, $second);
    }

    /**
     * A record written by a future version of this plugin must read as absent,
     * not be half-interpreted. Half-reading a job inside a cron callback fatals
     * on every fire; reading it as unknown simply stops.
     */
    public function testARecordFromAnUnknownVersionReadsAsAbsent(): void
    {
        $this->store->injectRaw('future', [
            'version' => NarrationJobStore::RECORD_VERSION + 1,
            'chunks'  => [['text' => 'a']],
        ]);

        $this->assertNull($this->store->get('future'));
    }

    public function testAMalformedRecordReadsAsAbsent(): void
    {
        $this->store->injectRaw('broken', 'not-an-array');
        $this->assertNull($this->store->get('broken'));

        $this->store->injectRaw('chunkless', ['version' => NarrationJobStore::RECORD_VERSION, 'chunks' => []]);
        $this->assertNull($this->store->get('chunkless'));
    }

    // ------------------------------------------------------------------
    // Progress
    // ------------------------------------------------------------------

    public function testProgressCountsCompletedChunksAgainstTheTotal(): void
    {
        $jobId = $this->store->create(['a', 'b', 'c'], $this->meta());

        $progress = $this->store->progress($jobId);
        $this->assertNotNull($progress);
        $this->assertSame(0, $progress['completed']);
        $this->assertSame(3, $progress['total']);

        $this->store->completeChunk($jobId, 0, '/tmp/a.mp3');

        $progress = $this->store->progress($jobId);
        $this->assertNotNull($progress);
        $this->assertSame(1, $progress['completed']);
        $this->assertSame(NarrationJobStore::STATUS_RUNNING, $progress['status']);
    }

    public function testCompletingAChunkIsVisibleToASubsequentRead(): void
    {
        $jobId = $this->store->create(['a', 'b'], $this->meta());
        $this->store->completeChunk($jobId, 1, '/tmp/b.mp3');

        $record = $this->store->get($jobId);
        $this->assertNotNull($record);
        $this->assertSame(NarrationJobStore::STATUS_COMPLETE, $record['chunks'][1]['status']);
        $this->assertSame('/tmp/b.mp3', $record['chunks'][1]['path']);
    }

    public function testNextPendingChunkWalksForwardAndEndsAtNull(): void
    {
        $jobId = $this->store->create(['a', 'b'], $this->meta());

        $this->assertSame(0, $this->store->nextPendingChunk($jobId));

        $this->store->completeChunk($jobId, 0, '/tmp/a.mp3');
        $this->assertSame(1, $this->store->nextPendingChunk($jobId));

        $this->store->completeChunk($jobId, 1, '/tmp/b.mp3');
        $this->assertNull($this->store->nextPendingChunk($jobId));
    }

    /**
     * Chunks may finish out of order once retries are involved. Joining audio in
     * completion order rather than index order produces a file that plays but is
     * wrong, which is the worst kind of failure.
     */
    public function testCompletedPathsComeBackInChunkOrderNotCompletionOrder(): void
    {
        $jobId = $this->store->create(['a', 'b', 'c'], $this->meta());

        $this->store->completeChunk($jobId, 2, '/tmp/c.mp3');
        $this->store->completeChunk($jobId, 0, '/tmp/a.mp3');
        $this->store->completeChunk($jobId, 1, '/tmp/b.mp3');

        $this->assertSame(
            ['/tmp/a.mp3', '/tmp/b.mp3', '/tmp/c.mp3'],
            $this->store->completedPaths($jobId)
        );
    }

    /**
     * R9: audio is megabytes and the record lives in an option. Paths only.
     */
    public function testAudioBytesNeverEnterTheRecord(): void
    {
        $jobId = $this->store->create(['a'], $this->meta());
        $this->store->completeChunk($jobId, 0, '/tmp/a.mp3');

        $record = $this->store->get($jobId);
        $this->assertNotNull($record);

        $encoded = (string) json_encode($record, JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('/tmp/a.mp3', $encoded);
        $this->assertStringNotContainsString('bytes', $encoded);
        $this->assertArrayNotHasKey('audio', $record['chunks'][0]);
    }

    // ------------------------------------------------------------------
    // Claims
    // ------------------------------------------------------------------

    public function testAFreeChunkCanBeClaimed(): void
    {
        $jobId = $this->store->create(['a'], $this->meta());

        $this->assertTrue($this->store->claimChunk($jobId, 0, 'runner-1', 60));
    }

    public function testClaimingIncrementsTheAttemptCount(): void
    {
        $jobId = $this->store->create(['a'], $this->meta());

        $this->store->claimChunk($jobId, 0, 'runner-1', 60);
        $this->assertSame(1, $this->store->attempts($jobId, 0));

        $this->store->claimChunk($jobId, 0, 'runner-1', 60);
        $this->assertSame(2, $this->store->attempts($jobId, 0));
    }

    public function testASecondRunnerCannotTakeAnUnexpiredClaim(): void
    {
        $jobId = $this->store->create(['a'], $this->meta());

        $this->assertTrue($this->store->claimChunk($jobId, 0, 'runner-1', 60));
        $this->assertFalse($this->store->claimChunk($jobId, 0, 'runner-2', 60));
    }

    /**
     * The reclaim path is the retry mechanism. Core unschedules an event before
     * running it, so nothing else will ever re-drive a chunk whose process died.
     */
    public function testAnExpiredClaimCanBeTakenByAnotherRunner(): void
    {
        $jobId = $this->store->create(['a'], $this->meta());
        $this->store->claimChunk($jobId, 0, 'runner-1', 60);

        $record = $this->store->exposeReadRecord($jobId);
        $this->assertNotNull($record);
        $record['chunks'][0]['claim_expires'] = time() - 1;
        $this->store->injectRaw($jobId, $record);

        $this->assertTrue($this->store->claimChunk($jobId, 0, 'runner-2', 60));
    }

    public function testAClaimHolderCanReclaimItsOwnChunk(): void
    {
        $jobId = $this->store->create(['a'], $this->meta());

        $this->assertTrue($this->store->claimChunk($jobId, 0, 'runner-1', 60));
        $this->assertTrue($this->store->claimChunk($jobId, 0, 'runner-1', 60));
    }

    public function testACompletedChunkCannotBeClaimed(): void
    {
        $jobId = $this->store->create(['a'], $this->meta());
        $this->store->completeChunk($jobId, 0, '/tmp/a.mp3');

        $this->assertFalse($this->store->claimChunk($jobId, 0, 'runner-1', 60));
    }

    public function testClaimingAChunkThatDoesNotExistFails(): void
    {
        $jobId = $this->store->create(['a'], $this->meta());

        $this->assertFalse($this->store->claimChunk($jobId, 99, 'runner-1', 60));
        $this->assertFalse($this->store->claimChunk('nope', 0, 'runner-1', 60));
    }

    public function testCompletingAChunkReleasesItsClaim(): void
    {
        $jobId = $this->store->create(['a'], $this->meta());
        $this->store->claimChunk($jobId, 0, 'runner-1', 60);
        $this->store->completeChunk($jobId, 0, '/tmp/a.mp3');

        $record = $this->store->get($jobId);
        $this->assertNotNull($record);
        $this->assertNull($record['chunks'][0]['claim']);
    }

    // ------------------------------------------------------------------
    // Terminal states
    // ------------------------------------------------------------------

    public function testFailingAJobRecordsTheReason(): void
    {
        $jobId = $this->store->create(['a'], $this->meta());
        $this->store->failJob($jobId, 'the API said no');

        $progress = $this->store->progress($jobId);
        $this->assertNotNull($progress);
        $this->assertSame(NarrationJobStore::STATUS_FAILED, $progress['status']);
        $this->assertSame('the API said no', $progress['error']);
    }

    public function testCompletingAJobRecordsTheAttachment(): void
    {
        $jobId = $this->store->create(['a'], $this->meta());
        $this->store->completeJob($jobId, 4242);

        $progress = $this->store->progress($jobId);
        $this->assertNotNull($progress);
        $this->assertSame(NarrationJobStore::STATUS_COMPLETE, $progress['status']);
        $this->assertSame(4242, $progress['attachment_id']);
    }

    public function testCancellingAJobSetsItsStatus(): void
    {
        $jobId = $this->store->create(['a'], $this->meta());
        $this->store->cancelJob($jobId);

        $progress = $this->store->progress($jobId);
        $this->assertNotNull($progress);
        $this->assertSame(NarrationJobStore::STATUS_CANCELLED, $progress['status']);
    }

    public function testDeletingAJobRemovesItEntirely(): void
    {
        $jobId = $this->store->create(['a'], $this->meta());
        $this->store->delete($jobId);

        $this->assertNull($this->store->get($jobId));
    }

    // ------------------------------------------------------------------
    // Job directories
    // ------------------------------------------------------------------

    public function testAJobDirectoryIsCreatedAndRemoved(): void
    {
        $jobId = $this->store->create(['a'], $this->meta());

        $directory = $this->store->createJobDirectory($jobId);
        $this->assertDirectoryExists($directory);

        file_put_contents($directory . '/chunk-0.mp3', 'audio');
        $this->store->removeJobDirectory($jobId);

        $this->assertDirectoryDoesNotExist($directory);
    }

    public function testRemovingAJobDirectoryThatIsAlreadyGoneIsHarmless(): void
    {
        $jobId = $this->store->create(['a'], $this->meta());

        $this->store->removeJobDirectory($jobId);
        $this->store->removeJobDirectory($jobId);

        $this->assertDirectoryDoesNotExist($this->store->jobDirectory($jobId));
    }

    public function testEachJobGetsItsOwnDirectory(): void
    {
        $first = $this->store->create(['a'], $this->meta());
        $second = $this->store->create(['a'], $this->meta());

        $this->assertNotSame(
            $this->store->jobDirectory($first),
            $this->store->jobDirectory($second)
        );
    }

    // ------------------------------------------------------------------
    // Storage guarantees that need WordPress to exercise
    // ------------------------------------------------------------------

    /**
     * The cron array is autoloaded on every page request, which is why chunk
     * texts are not stored there. That reasoning is wasted if the job option is
     * itself autoloaded, and unit tests cannot catch it: without WordPress the
     * store falls back to memory and update_option is never reached. Assert the
     * call shape in the source instead.
     */
    public function testJobRecordsAreWrittenWithAutoloadDisabled(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Jobs/NarrationJobStore.php');

        $this->assertMatchesRegularExpression(
            '~update_option\(\s*\$this->optionName\(\$jobId\),\s*\$record,\s*false\s*\)~',
            $source,
            'Job records must be written with autoload disabled.'
        );
    }
}
