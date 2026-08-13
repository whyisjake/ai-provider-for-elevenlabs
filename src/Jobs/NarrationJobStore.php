<?php

declare(strict_types=1);

namespace AiProviderForElevenLabs\Jobs;

use WordPress\AiClient\Common\Exception\InvalidArgumentException;

/**
 * Persists narration jobs and their per-chunk progress.
 *
 * WP-Cron deletes an event before running it, so a job whose state lived only
 * in the event arguments would vanish on the first fatal or timeout with no
 * record it ever existed. This class is what makes a job survivable: the
 * manifest outlives any single request, and a chunk that does not report back
 * can be reclaimed and retried.
 *
 * One option per job, autoload explicitly off. The cron array is autoloaded on
 * every page request, so chunk texts must never be stored there -- a long post
 * would be loaded into memory for every visitor for the life of the job.
 *
 * Audio never enters the database. Chunks are written to a per-job directory
 * under the uploads folder and the record carries paths only.
 *
 * @since n.e.x.t
 *
 * @phpstan-type ChunkRecord array{
 *     text: string,
 *     status: string,
 *     attempts: int,
 *     claim: string|null,
 *     claim_expires: int|null,
 *     path: string|null
 * }
 * @phpstan-type JobRecord array{
 *     version: int,
 *     id: string,
 *     status: string,
 *     model_id: string,
 *     voice_id: string,
 *     output_format: string,
 *     mime_type: string,
 *     created_at: int,
 *     updated_at: int,
 *     error: string|null,
 *     attachment_id: int|null,
 *     chunks: list<ChunkRecord>
 * }
 */
class NarrationJobStore
{
    /**
     * Record shape version.
     *
     * Stamped from the first commit so a plugin update that changes the shape
     * can ignore records it does not understand rather than half-reading them.
     *
     * @since n.e.x.t
     *
     * @var int
     */
    public const RECORD_VERSION = 1;

    /**
     * Prefix for the per-job option name.
     *
     * @since n.e.x.t
     *
     * @var string
     */
    public const OPTION_PREFIX = 'ai_provider_elevenlabs_narration_job_';

    /**
     * Job has been created but no chunk has completed yet.
     *
     * @since n.e.x.t
     *
     * @var string
     */
    public const STATUS_PENDING = 'pending';

    /**
     * At least one chunk has completed and more remain.
     *
     * @since n.e.x.t
     *
     * @var string
     */
    public const STATUS_RUNNING = 'running';

    /**
     * Every chunk completed and the audio was delivered.
     *
     * @since n.e.x.t
     *
     * @var string
     */
    public const STATUS_COMPLETE = 'complete';

    /**
     * The job stopped without producing audio.
     *
     * @since n.e.x.t
     *
     * @var string
     */
    public const STATUS_FAILED = 'failed';

    /**
     * The job was cancelled by a caller.
     *
     * @since n.e.x.t
     *
     * @var string
     */
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * In-memory store used when WordPress is unavailable.
     *
     * @since n.e.x.t
     *
     * @var array<string, mixed>
     */
    protected array $memory = [];

    /**
     * Creates a job for the given chunks and returns its id.
     *
     * @since n.e.x.t
     *
     * @param list<string>         $chunks The ordered text for each chunk.
     * @param array<string, mixed> $meta   Job metadata: model_id, voice_id, output_format, mime_type.
     * @return string The job id.
     * @throws InvalidArgumentException If there is nothing to narrate.
     */
    public function create(array $chunks, array $meta): string
    {
        if ($chunks === []) {
            throw new InvalidArgumentException('A narration job needs at least one chunk of text.');
        }

        $jobId = $this->generateJobId();
        $now = time();

        $chunkRecords = [];
        foreach ($chunks as $text) {
            $chunkRecords[] = [
                'text'          => $text,
                'status'        => self::STATUS_PENDING,
                'attempts'      => 0,
                'claim'         => null,
                'claim_expires' => null,
                'path'          => null,
            ];
        }

        $this->writeRecord($jobId, [
            'version'       => self::RECORD_VERSION,
            'id'            => $jobId,
            'status'        => self::STATUS_PENDING,
            'model_id'      => $this->metaString($meta, 'model_id'),
            'voice_id'      => $this->metaString($meta, 'voice_id'),
            'output_format' => $this->metaString($meta, 'output_format'),
            'mime_type'     => $this->metaString($meta, 'mime_type'),
            'created_at'    => $now,
            'updated_at'    => $now,
            'error'         => null,
            'attachment_id' => null,
            'chunks'        => $chunkRecords,
        ]);

        return $jobId;
    }

    /**
     * Reads a job record.
     *
     * @since n.e.x.t
     *
     * @param string $jobId The job id.
     * @return JobRecord|null The record, or null when absent or unreadable.
     */
    public function get(string $jobId): ?array
    {
        return $this->readRecord($jobId);
    }

    /**
     * Reports how far a job has progressed.
     *
     * @since n.e.x.t
     *
     * @param string $jobId The job id.
     * @return array{status: string, completed: int, total: int, error: string|null, attachment_id: int|null}|null
     *         The progress, or null when the job is unknown.
     */
    public function progress(string $jobId): ?array
    {
        $record = $this->readRecord($jobId);

        if ($record === null) {
            return null;
        }

        $completed = 0;
        foreach ($record['chunks'] as $chunk) {
            if ($chunk['status'] === self::STATUS_COMPLETE) {
                $completed++;
            }
        }

        return [
            'status'        => $record['status'],
            'completed'     => $completed,
            'total'         => count($record['chunks']),
            'error'         => $record['error'],
            'attachment_id' => $record['attachment_id'],
        ];
    }

    /**
     * Claims a chunk for exclusive work, if it is free.
     *
     * A claim carries an expiry rather than being a plain flag. A process that
     * dies holding a boolean would strand the job forever; an expiring claim is
     * reclaimable, and reclaiming is exactly the retry path, because WP-Cron
     * will never retry the event for us.
     *
     * @since n.e.x.t
     *
     * @param string $jobId The job id.
     * @param int    $index The chunk index.
     * @param string $owner Identifier for the claiming process.
     * @param int    $ttl   Seconds before the claim expires.
     * @return bool True when the claim was taken.
     */
    public function claimChunk(string $jobId, int $index, string $owner, int $ttl): bool
    {
        $record = $this->readRecord($jobId);

        if ($record === null || !isset($record['chunks'][$index])) {
            return false;
        }

        $chunk = $record['chunks'][$index];

        if ($chunk['status'] === self::STATUS_COMPLETE) {
            return false;
        }

        $now = time();
        $heldByAnother = $chunk['claim'] !== null
            && $chunk['claim'] !== $owner
            && $chunk['claim_expires'] !== null
            && $chunk['claim_expires'] > $now;

        if ($heldByAnother) {
            return false;
        }

        $record['chunks'][$index]['claim'] = $owner;
        $record['chunks'][$index]['claim_expires'] = $now + max($ttl, 1);
        $record['chunks'][$index]['attempts'] = $chunk['attempts'] + 1;

        if ($record['status'] === self::STATUS_PENDING) {
            $record['status'] = self::STATUS_RUNNING;
        }

        $this->writeRecord($jobId, $this->touch($record));

        return true;
    }

    /**
     * Marks a chunk complete and records where its audio landed.
     *
     * @since n.e.x.t
     *
     * @param string $jobId The job id.
     * @param int    $index The chunk index.
     * @param string $path  Absolute path to the chunk's audio.
     * @return void
     */
    public function completeChunk(string $jobId, int $index, string $path): void
    {
        $record = $this->readRecord($jobId);

        if ($record === null || !isset($record['chunks'][$index])) {
            return;
        }

        $record['chunks'][$index]['status'] = self::STATUS_COMPLETE;
        $record['chunks'][$index]['path'] = $path;
        $record['chunks'][$index]['claim'] = null;
        $record['chunks'][$index]['claim_expires'] = null;

        if ($record['status'] === self::STATUS_PENDING) {
            $record['status'] = self::STATUS_RUNNING;
        }

        $this->writeRecord($jobId, $this->touch($record));
    }

    /**
     * Returns the index of the first chunk still needing work.
     *
     * @since n.e.x.t
     *
     * @param string $jobId The job id.
     * @return int|null The index, or null when every chunk is done.
     */
    public function nextPendingChunk(string $jobId): ?int
    {
        $record = $this->readRecord($jobId);

        if ($record === null) {
            return null;
        }

        foreach ($record['chunks'] as $index => $chunk) {
            if ($chunk['status'] !== self::STATUS_COMPLETE) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Returns how many times a chunk has been attempted.
     *
     * @since n.e.x.t
     *
     * @param string $jobId The job id.
     * @param int    $index The chunk index.
     * @return int The attempt count.
     */
    public function attempts(string $jobId, int $index): int
    {
        $record = $this->readRecord($jobId);

        if ($record === null || !isset($record['chunks'][$index])) {
            return 0;
        }

        return $record['chunks'][$index]['attempts'];
    }

    /**
     * Returns the completed chunk audio paths, in chunk order.
     *
     * Ordered by index rather than by completion, because audio joined in the
     * order it happened to finish is silently wrong rather than loudly broken.
     *
     * @since n.e.x.t
     *
     * @param string $jobId The job id.
     * @return list<string> The paths, in order.
     */
    public function completedPaths(string $jobId): array
    {
        $record = $this->readRecord($jobId);

        if ($record === null) {
            return [];
        }

        $paths = [];
        foreach ($record['chunks'] as $chunk) {
            if ($chunk['status'] === self::STATUS_COMPLETE && $chunk['path'] !== null) {
                $paths[] = $chunk['path'];
            }
        }

        return $paths;
    }

    /**
     * Marks a job finished, recording the attachment it produced.
     *
     * @since n.e.x.t
     *
     * @param string $jobId        The job id.
     * @param int    $attachmentId The attachment holding the joined audio.
     * @return void
     */
    public function completeJob(string $jobId, int $attachmentId): void
    {
        $this->setJobStatus($jobId, self::STATUS_COMPLETE, null, $attachmentId);
    }

    /**
     * Marks a job failed, recording why.
     *
     * @since n.e.x.t
     *
     * @param string $jobId The job id.
     * @param string $error The failure reason.
     * @return void
     */
    public function failJob(string $jobId, string $error): void
    {
        $this->setJobStatus($jobId, self::STATUS_FAILED, $error, null);
    }

    /**
     * Marks a job cancelled.
     *
     * @since n.e.x.t
     *
     * @param string $jobId The job id.
     * @return void
     */
    public function cancelJob(string $jobId): void
    {
        $this->setJobStatus($jobId, self::STATUS_CANCELLED, null, null);
    }

    /**
     * Removes a job record entirely.
     *
     * @since n.e.x.t
     *
     * @param string $jobId The job id.
     * @return void
     */
    public function delete(string $jobId): void
    {
        $this->deleteRecord($jobId);
    }

    /**
     * Returns the directory holding a job's chunk audio.
     *
     * @since n.e.x.t
     *
     * @param string $jobId The job id.
     * @return string The absolute directory path.
     */
    public function jobDirectory(string $jobId): string
    {
        return $this->baseDirectory() . '/' . $jobId;
    }

    /**
     * Creates a job's audio directory.
     *
     * @since n.e.x.t
     *
     * @param string $jobId The job id.
     * @return string The absolute directory path.
     * @throws InvalidArgumentException If the directory cannot be created.
     */
    public function createJobDirectory(string $jobId): string
    {
        $directory = $this->jobDirectory($jobId);

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new InvalidArgumentException(
                sprintf('Could not create the narration job directory at "%s".', $directory)
            );
        }

        return $directory;
    }

    /**
     * Removes a job's audio directory and everything in it.
     *
     * @since n.e.x.t
     *
     * @param string $jobId The job id.
     * @return void
     */
    public function removeJobDirectory(string $jobId): void
    {
        $directory = $this->jobDirectory($jobId);

        if (!is_dir($directory)) {
            return;
        }

        $entries = glob($directory . '/*');

        foreach ($entries === false ? [] : $entries as $entry) {
            if (is_file($entry)) {
                unlink($entry);
            }
        }

        rmdir($directory);
    }

    /**
     * Sets a job's terminal status.
     *
     * @since n.e.x.t
     *
     * @param string      $jobId        The job id.
     * @param string      $status       The new status.
     * @param string|null $error        The failure reason, when failing.
     * @param int|null    $attachmentId The attachment id, when completing.
     * @return void
     */
    protected function setJobStatus(string $jobId, string $status, ?string $error, ?int $attachmentId): void
    {
        $record = $this->readRecord($jobId);

        if ($record === null) {
            return;
        }

        $record['status'] = $status;
        $record['error'] = $error;
        $record['attachment_id'] = $attachmentId;

        $this->writeRecord($jobId, $this->touch($record));
    }

    /**
     * Stamps the record's modification time.
     *
     * @since n.e.x.t
     *
     * @param JobRecord $record The record.
     * @return JobRecord The stamped record.
     */
    protected function touch(array $record): array
    {
        $record['updated_at'] = time();

        return $record;
    }

    /**
     * Reads and validates a stored record.
     *
     * A record whose shape this code does not understand is treated as absent
     * rather than half-read, so a future change to the shape degrades to "job
     * unknown" instead of a fatal in a cron callback.
     *
     * @since n.e.x.t
     *
     * @param string $jobId The job id.
     * @return JobRecord|null The record, or null when absent or unrecognised.
     */
    protected function readRecord(string $jobId): ?array
    {
        $raw = function_exists('get_option')
            ? get_option($this->optionName($jobId), null)
            : ($this->memory[$jobId] ?? null);

        return $this->normaliseRecord($raw);
    }

    /**
     * Writes a record.
     *
     * @since n.e.x.t
     *
     * @param string    $jobId  The job id.
     * @param JobRecord $record The record.
     * @return void
     */
    protected function writeRecord(string $jobId, array $record): void
    {
        if (function_exists('update_option')) {
            // Never autoloaded: chunk texts must not load on every page request.
            update_option($this->optionName($jobId), $record, false);

            return;
        }

        $this->memory[$jobId] = $record;
    }

    /**
     * Deletes a record.
     *
     * @since n.e.x.t
     *
     * @param string $jobId The job id.
     * @return void
     */
    protected function deleteRecord(string $jobId): void
    {
        if (function_exists('delete_option')) {
            delete_option($this->optionName($jobId));

            return;
        }

        unset($this->memory[$jobId]);
    }

    /**
     * Returns the directory holding all job audio.
     *
     * @since n.e.x.t
     *
     * @return string The absolute base directory.
     */
    protected function baseDirectory(): string
    {
        if (function_exists('wp_upload_dir')) {
            $uploads = wp_upload_dir();

            if (is_array($uploads) && isset($uploads['basedir']) && is_string($uploads['basedir'])) {
                return $uploads['basedir'] . '/elevenlabs-narration';
            }
        }

        return sys_get_temp_dir() . '/elevenlabs-narration';
    }

    /**
     * Builds the option name for a job.
     *
     * @since n.e.x.t
     *
     * @param string $jobId The job id.
     * @return string The option name.
     */
    protected function optionName(string $jobId): string
    {
        return self::OPTION_PREFIX . $jobId;
    }

    /**
     * Generates a job id.
     *
     * @since n.e.x.t
     *
     * @return string The job id.
     */
    protected function generateJobId(): string
    {
        if (function_exists('wp_generate_uuid4')) {
            $uuid = wp_generate_uuid4();

            if (is_string($uuid) && $uuid !== '') {
                return str_replace('-', '', $uuid);
            }
        }

        try {
            return bin2hex(random_bytes(16));
        } catch (\Exception $e) {
            return uniqid('narration', true);
        }
    }

    /**
     * Validates raw stored data against the record shape.
     *
     * @since n.e.x.t
     *
     * @param mixed $raw The stored value.
     * @return JobRecord|null The record, or null when it does not match.
     */
    protected function normaliseRecord($raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }

        if (($raw['version'] ?? null) !== self::RECORD_VERSION) {
            return null;
        }

        if (!isset($raw['chunks']) || !is_array($raw['chunks']) || $raw['chunks'] === []) {
            return null;
        }

        $chunks = [];
        foreach ($raw['chunks'] as $chunk) {
            if (!is_array($chunk) || !isset($chunk['text']) || !is_string($chunk['text'])) {
                return null;
            }

            $chunks[] = [
                'text'          => $chunk['text'],
                'status'        => is_string($chunk['status'] ?? null) ? $chunk['status'] : self::STATUS_PENDING,
                'attempts'      => is_int($chunk['attempts'] ?? null) ? $chunk['attempts'] : 0,
                'claim'         => is_string($chunk['claim'] ?? null) ? $chunk['claim'] : null,
                'claim_expires' => is_int($chunk['claim_expires'] ?? null) ? $chunk['claim_expires'] : null,
                'path'          => is_string($chunk['path'] ?? null) ? $chunk['path'] : null,
            ];
        }

        return [
            'version'       => self::RECORD_VERSION,
            'id'            => is_string($raw['id'] ?? null) ? $raw['id'] : '',
            'status'        => is_string($raw['status'] ?? null) ? $raw['status'] : self::STATUS_PENDING,
            'model_id'      => is_string($raw['model_id'] ?? null) ? $raw['model_id'] : '',
            'voice_id'      => is_string($raw['voice_id'] ?? null) ? $raw['voice_id'] : '',
            'output_format' => is_string($raw['output_format'] ?? null) ? $raw['output_format'] : '',
            'mime_type'     => is_string($raw['mime_type'] ?? null) ? $raw['mime_type'] : '',
            'created_at'    => is_int($raw['created_at'] ?? null) ? $raw['created_at'] : 0,
            'updated_at'    => is_int($raw['updated_at'] ?? null) ? $raw['updated_at'] : 0,
            'error'         => is_string($raw['error'] ?? null) ? $raw['error'] : null,
            'attachment_id' => is_int($raw['attachment_id'] ?? null) ? $raw['attachment_id'] : null,
            'chunks'        => $chunks,
        ];
    }

    /**
     * Reads a required string from the metadata array.
     *
     * @since n.e.x.t
     *
     * @param array<string, mixed> $meta The metadata.
     * @param string               $key  The key to read.
     * @return string The value, or an empty string when absent.
     */
    protected function metaString(array $meta, string $key): string
    {
        return isset($meta[$key]) && is_string($meta[$key]) ? $meta[$key] : '';
    }
}
