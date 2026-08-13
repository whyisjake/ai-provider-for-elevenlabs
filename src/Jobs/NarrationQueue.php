<?php

declare(strict_types=1);

namespace AiProviderForElevenLabs\Jobs;

/**
 * Schedules narration work for later execution.
 *
 * Deliberately small, and deliberately about a single chunk. Sites differ
 * enormously in how they run background work, and hardcoding WP-Cron would make
 * this plugin worse on the hosts that have already solved the problem properly.
 *
 * The default implementation needs no dependency and works everywhere. A site
 * running Action Scheduler, a system queue, or anything else can substitute its
 * own through the `ai_provider_elevenlabs_narration_queue` filter.
 *
 * @since n.e.x.t
 */
interface NarrationQueue
{
    /**
     * Schedules one chunk of a job to be narrated.
     *
     * Implementations must not create a duplicate for a chunk that is already
     * scheduled: a duplicate is a duplicate API request and a duplicate bill.
     *
     * @since n.e.x.t
     *
     * @param string $jobId      The job id.
     * @param int    $chunkIndex The chunk to narrate.
     * @param int    $delay      Seconds to wait before running.
     * @return bool True when the work is scheduled.
     */
    public function scheduleChunk(string $jobId, int $chunkIndex, int $delay = 0): bool;

    /**
     * Cancels any pending work for one chunk of a job.
     *
     * @since n.e.x.t
     *
     * @param string $jobId      The job id.
     * @param int    $chunkIndex The chunk to cancel.
     * @return void
     */
    public function cancelChunk(string $jobId, int $chunkIndex): void;

    /**
     * Reports whether work is already pending for one chunk of a job.
     *
     * @since n.e.x.t
     *
     * @param string $jobId      The job id.
     * @param int    $chunkIndex The chunk to check.
     * @return bool True when work is pending.
     */
    public function isChunkPending(string $jobId, int $chunkIndex): bool;
}
