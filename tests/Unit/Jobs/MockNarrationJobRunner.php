<?php

declare(strict_types=1);

namespace AiProviderForElevenLabs\Tests\Unit\Jobs;

use AiProviderForElevenLabs\Jobs\NarrationJobRunner;

/**
 * Mock class for testing NarrationJobRunner.
 *
 * Intercepts the two things that need WordPress. Capturing the joined audio at
 * delivery matters most: assembly removes the job directory immediately
 * afterwards, so this is the only moment the finished file exists to assert on.
 */
class MockNarrationJobRunner extends NarrationJobRunner
{
    /**
     * The joined audio handed to delivery.
     *
     * @var string|null
     */
    public ?string $joined = null;

    /**
     * Every action fired, as [hook, jobId, context].
     *
     * @var list<array{0: string, 1: string, 2: mixed}>
     */
    public array $fired = [];

    /**
     * The attachment id delivery should report.
     *
     * @var int
     */
    public int $attachmentId = 4242;

    protected function createAttachment(string $path, array $record): int
    {
        $contents = file_get_contents($path);
        $this->joined = $contents === false ? null : $contents;

        return $this->attachmentId;
    }

    protected function fire(string $hook, string $jobId, $context): void
    {
        $this->fired[] = [$hook, $jobId, $context];
    }

    /**
     * Returns the hooks fired so far, by name.
     *
     * @return list<string>
     */
    public function firedHooks(): array
    {
        return array_column($this->fired, 0);
    }
}
