<?php

declare(strict_types=1);

namespace AiProviderForElevenLabs\Tests\Support;

/**
 * Gives a test a job store backed by a throwaway directory.
 *
 * Narration jobs write real audio to disk, so every suite that touches one
 * needs somewhere disposable to put it and a way to clear up afterwards. This
 * keeps that in one place rather than repeating the same teardown in every
 * file that happens to run a job.
 */
trait TemporaryNarrationDirectory
{
    private string $narrationBase;

    /**
     * Builds a store rooted in a fresh temporary directory.
     *
     * @return MockNarrationJobStore The store.
     */
    protected function createNarrationStore(): MockNarrationJobStore
    {
        $this->narrationBase = sprintf(
            '%s/narration-test-%s',
            sys_get_temp_dir(),
            bin2hex(random_bytes(6))
        );

        return new MockNarrationJobStore($this->narrationBase);
    }

    /**
     * Removes the temporary directory and everything under it.
     *
     * @return void
     */
    protected function removeNarrationDirectory(): void
    {
        if (!isset($this->narrationBase) || !is_dir($this->narrationBase)) {
            return;
        }

        foreach ((array) glob($this->narrationBase . '/*') as $entry) {
            if (!is_dir((string) $entry)) {
                continue;
            }

            foreach ((array) glob($entry . '/*') as $file) {
                unlink((string) $file);
            }

            rmdir((string) $entry);
        }

        rmdir($this->narrationBase);
    }
}
