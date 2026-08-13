<?php

declare(strict_types=1);

namespace AiProviderForElevenLabs\Tests\Support;

use AiProviderForElevenLabs\Jobs\NarrationJobStore;

/**
 * A job store that keeps its audio somewhere disposable.
 *
 * Redirects job audio to a caller-supplied directory so the filesystem side can
 * be exercised without an uploads folder, and exposes the record seams so a
 * test can inject states -- an expired claim, say -- that would otherwise need
 * a controllable clock.
 *
 * Shared by the unit and integration suites: both need a store pointed at a
 * temporary directory, and there is no reason for two of these.
 */
class MockNarrationJobStore extends NarrationJobStore
{
    private string $root;

    public function __construct(string $root)
    {
        $this->root = $root;
    }

    protected function baseDirectory(): string
    {
        return $this->root;
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
