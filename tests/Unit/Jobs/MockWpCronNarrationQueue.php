<?php

declare(strict_types=1);

namespace AiProviderForElevenLabs\Tests\Unit\Jobs;

use AiProviderForElevenLabs\Jobs\WpCronNarrationQueue;

/**
 * Mock class for testing WpCronNarrationQueue.
 *
 * Without WordPress the queue already falls back to its in-memory event list,
 * which is the scheduling logic under test. This adds visibility into the
 * arguments and the raw scheduling calls, so the shape of what would reach
 * wp_schedule_single_event can be asserted directly.
 */
class MockWpCronNarrationQueue extends WpCronNarrationQueue
{
    /**
     * Every set of arguments passed to scheduleEvent, in order.
     *
     * @var list<array{0: string, 1: int}>
     */
    public array $scheduled = [];

    /**
     * Exposes eventArgs for testing.
     *
     * @param string $jobId      The job id.
     * @param int    $chunkIndex The chunk index.
     * @return array{0: string, 1: int} The event arguments.
     */
    public function exposeEventArgs(string $jobId, int $chunkIndex): array
    {
        return $this->eventArgs($jobId, $chunkIndex);
    }

    protected function scheduleEvent(int $timestamp, array $args): bool
    {
        $this->scheduled[] = $args;

        return parent::scheduleEvent($timestamp, $args);
    }
}
