<?php

declare(strict_types=1);

namespace AiProviderForElevenLabs\Tests\Unit\Jobs;

use AiProviderForElevenLabs\Jobs\NarrationJobStore;

/**
 * Mock class for testing NarrationJobStore.
 *
 * Redirects job audio to a caller-supplied directory so the filesystem side can
 * be exercised without an uploads folder, and exposes the record seams so a
 * test can inject states -- an expired claim, say -- that would otherwise need
 * a controllable clock.
 */
class MockNarrationJobStore extends NarrationJobStore
{
    private string $base;

    public function __construct(string $base)
    {
        $this->base = $base;
    }

    protected function baseDirectory(): string
    {
        return $this->base;
    }

    /**
     * Exposes readRecord for testing.
     *
     * @param string $jobId The job id.
     * @return array<string, mixed>|null The record.
     */
    public function exposeReadRecord(string $jobId): ?array
    {
        return $this->readRecord($jobId);
    }

    /**
     * Writes arbitrary data for a job, bypassing validation.
     *
     * @param string $jobId The job id.
     * @param mixed  $raw   The value to store.
     * @return void
     */
    public function injectRaw(string $jobId, $raw): void
    {
        $this->memory[$jobId] = $raw;
    }
}
