<?php

declare(strict_types=1);

namespace AiProviderForElevenLabs\Jobs;

/**
 * Schedules narration chunks with WP-Cron.
 *
 * The default runner, chosen because it is available on every WordPress install
 * and needs no dependency. Its limits are real and worth stating plainly rather
 * than discovering in production:
 *
 * - **It fires on traffic.** Unless the host has set `DISABLE_WP_CRON` and wired
 *   real system cron, a queue on a quiet site drains only when someone visits.
 * - **It does not retry.** Core calls `wp_unschedule_event()` before firing the
 *   hook, so an event whose process dies is gone with no record. Retrying is
 *   {@see NarrationJobStore}'s expiring claims, not this class.
 * - **It does not extend the clock.** The callback runs in an ordinary PHP
 *   request under the usual `max_execution_time`. That is why the unit of work
 *   is one chunk rather than one post.
 *
 * Event arguments carry the job id and chunk index only. Everything else lives
 * in the job record, because cron arguments are stored in the `cron` option,
 * which core autoloads on every page request.
 *
 * @since n.e.x.t
 */
class WpCronNarrationQueue implements NarrationQueue
{
    /**
     * The action fired for each scheduled chunk.
     *
     * @since n.e.x.t
     *
     * @var string
     */
    public const HOOK = 'ai_provider_elevenlabs_narrate_chunk';

    /**
     * Filter allowing a site to substitute a different runner.
     *
     * @since n.e.x.t
     *
     * @var string
     */
    public const FILTER = 'ai_provider_elevenlabs_narration_queue';

    /**
     * Events scheduled in memory, used when WordPress is unavailable.
     *
     * @since n.e.x.t
     *
     * @var array<string, int>
     */
    protected array $memory = [];

    /**
     * Returns the queue this site should use.
     *
     * @since n.e.x.t
     *
     * @return NarrationQueue The queue implementation.
     */
    public static function resolve(): NarrationQueue
    {
        $queue = new self();

        if (!function_exists('apply_filters')) {
            return $queue;
        }

        $filtered = apply_filters(self::FILTER, $queue);

        return $filtered instanceof NarrationQueue ? $filtered : $queue;
    }

    /**
     * {@inheritDoc}
     *
     * @since n.e.x.t
     */
    public function scheduleChunk(string $jobId, int $chunkIndex, int $delay = 0): bool
    {
        // Scheduling a chunk twice would narrate it twice and bill for both.
        if ($this->isChunkPending($jobId, $chunkIndex)) {
            return true;
        }

        return $this->scheduleEvent(time() + max($delay, 0), $this->eventArgs($jobId, $chunkIndex));
    }

    /**
     * {@inheritDoc}
     *
     * @since n.e.x.t
     */
    public function cancelChunk(string $jobId, int $chunkIndex): void
    {
        $this->clearEvents($this->eventArgs($jobId, $chunkIndex));
    }

    /**
     * {@inheritDoc}
     *
     * @since n.e.x.t
     */
    public function isChunkPending(string $jobId, int $chunkIndex): bool
    {
        return $this->nextScheduled($this->eventArgs($jobId, $chunkIndex)) !== null;
    }

    /**
     * Builds the event arguments for a chunk.
     *
     * Kept to the two values that identify the work. Core keys events by
     * `md5( serialize( $args ) )`, so arguments are identity: putting mutable
     * progress in them would make every fire a different event.
     *
     * @since n.e.x.t
     *
     * @param string $jobId      The job id.
     * @param int    $chunkIndex The chunk index.
     * @return array{0: string, 1: int} The event arguments.
     */
    protected function eventArgs(string $jobId, int $chunkIndex): array
    {
        return [$jobId, $chunkIndex];
    }

    /**
     * Schedules a single event.
     *
     * @since n.e.x.t
     *
     * @param int                    $timestamp When to run.
     * @param array{0: string, 1: int} $args    The event arguments.
     * @return bool True when scheduled.
     */
    protected function scheduleEvent(int $timestamp, array $args): bool
    {
        if (!function_exists('wp_schedule_single_event')) {
            $this->memory[$this->memoryKey($args)] = $timestamp;

            return true;
        }

        return wp_schedule_single_event($timestamp, self::HOOK, $args) === true;
    }

    /**
     * Returns when a matching event is next due.
     *
     * @since n.e.x.t
     *
     * @param array{0: string, 1: int} $args The event arguments.
     * @return int|null The timestamp, or null when nothing is pending.
     */
    protected function nextScheduled(array $args): ?int
    {
        if (!function_exists('wp_next_scheduled')) {
            return $this->memory[$this->memoryKey($args)] ?? null;
        }

        $timestamp = wp_next_scheduled(self::HOOK, $args);

        return is_int($timestamp) ? $timestamp : null;
    }

    /**
     * Clears every matching event.
     *
     * @since n.e.x.t
     *
     * @param array{0: string, 1: int} $args The event arguments.
     * @return void
     */
    protected function clearEvents(array $args): void
    {
        if (!function_exists('wp_clear_scheduled_hook')) {
            unset($this->memory[$this->memoryKey($args)]);

            return;
        }

        wp_clear_scheduled_hook(self::HOOK, $args);
    }

    /**
     * Builds the in-memory key for a set of event arguments.
     *
     * @since n.e.x.t
     *
     * @param array{0: string, 1: int} $args The event arguments.
     * @return string The key.
     */
    protected function memoryKey(array $args): string
    {
        return $args[0] . ':' . $args[1];
    }
}
