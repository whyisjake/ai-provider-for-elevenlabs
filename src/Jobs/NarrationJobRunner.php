<?php

declare(strict_types=1);

namespace AiProviderForElevenLabs\Jobs;

use AiProviderForElevenLabs\Models\ProviderForElevenLabsTextToSpeechModel;
use AiProviderForElevenLabs\Provider\ProviderForElevenLabs;
use Throwable;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;

/**
 * Narrates one chunk per run and drives a job to completion.
 *
 * Chunks run one after another, each scheduling the next, so only one event is
 * ever pending for a job. That keeps the cron array small, keeps the request
 * rate predictable, and makes the neighbouring text for prosody trivially
 * available from the record.
 *
 * Retries live here rather than in the queue because core will not retry: it
 * calls `wp_unschedule_event()` before firing the hook, so an event whose
 * process dies is simply gone. A chunk claimed but not completed before its
 * claim expires is reclaimed on the next fire, bounded by an attempt count so a
 * genuinely broken request stops instead of billing forever.
 *
 * @since n.e.x.t
 *
 * @phpstan-import-type JobRecord from NarrationJobStore
 */
class NarrationJobRunner
{
    /**
     * Fired when a job finishes and its audio is available.
     *
     * @since n.e.x.t
     *
     * @var string
     */
    public const HOOK_COMPLETE = 'ai_provider_elevenlabs_narration_complete';

    /**
     * Fired when a job stops without producing audio.
     *
     * @since n.e.x.t
     *
     * @var string
     */
    public const HOOK_FAILED = 'ai_provider_elevenlabs_narration_failed';

    /**
     * Attempts allowed per chunk before the job fails.
     *
     * @since n.e.x.t
     *
     * @var int
     */
    public const MAX_ATTEMPTS = 3;

    /**
     * Seconds a claim is held before another runner may reclaim the chunk.
     *
     * Comfortably longer than a chunk should take, so a slow request is not
     * mistaken for a dead one and narrated twice.
     *
     * @since n.e.x.t
     *
     * @var int
     */
    public const CLAIM_TTL = 300;

    /**
     * Seconds to wait before retrying a failed chunk.
     *
     * @since n.e.x.t
     *
     * @var int
     */
    public const RETRY_DELAY = 60;

    private NarrationJobStore $store;

    private NarrationQueue $queue;

    /**
     * Builds a text-to-speech model for a job record.
     *
     * @var (callable(JobRecord): mixed)|null
     */
    private $modelFactory;

    /**
     * Identifies this process when claiming chunks.
     *
     * @var string
     */
    private string $owner;

    /**
     * Constructor.
     *
     * @since n.e.x.t
     *
     * @param NarrationJobStore $store        The job store.
     * @param NarrationQueue    $queue        The queue scheduling further chunks.
     * @param callable|null     $modelFactory Builds a model from a record, or null for the provider's.
     */
    public function __construct(
        NarrationJobStore $store,
        NarrationQueue $queue,
        ?callable $modelFactory = null
    ) {
        $this->store = $store;
        $this->queue = $queue;
        $this->modelFactory = $modelFactory;
        $this->owner = $this->generateOwner();
    }

    /**
     * Narrates one chunk of a job, then schedules whatever comes next.
     *
     * Every exit is deliberate. A job that vanished, a chunk another runner
     * holds, and a job already finished all return without work rather than
     * raising, because this runs inside a cron callback where an exception is
     * invisible to anyone.
     *
     * @since n.e.x.t
     *
     * @param string $jobId      The job id.
     * @param int    $chunkIndex The chunk to narrate.
     * @return void
     */
    public function run(string $jobId, int $chunkIndex): void
    {
        $record = $this->store->get($jobId);

        if ($record === null) {
            return;
        }

        if (in_array($record['status'], $this->terminalStatuses(), true)) {
            return;
        }

        if (!isset($record['chunks'][$chunkIndex])) {
            return;
        }

        if ($record['chunks'][$chunkIndex]['status'] === NarrationJobStore::STATUS_COMPLETE) {
            $this->advance($jobId);

            return;
        }

        if (!$this->store->claimChunk($jobId, $chunkIndex, $this->owner, self::CLAIM_TTL)) {
            return;
        }

        try {
            $bytes = $this->narrate($record, $chunkIndex);
        } catch (Throwable $e) {
            $this->handleChunkFailure($jobId, $chunkIndex, $e->getMessage());

            return;
        }

        try {
            $path = $this->writeChunkAudio($jobId, $chunkIndex, $bytes);
        } catch (Throwable $e) {
            $this->fail($jobId, $e->getMessage());

            return;
        }

        $this->store->completeChunk($jobId, $chunkIndex, $path);
        $this->advance($jobId);
    }

    /**
     * Schedules the next chunk, or assembles when none remain.
     *
     * @since n.e.x.t
     *
     * @param string $jobId The job id.
     * @return void
     */
    protected function advance(string $jobId): void
    {
        $next = $this->store->nextPendingChunk($jobId);

        if ($next !== null) {
            $this->queue->scheduleChunk($jobId, $next);

            return;
        }

        $this->assemble($jobId);
    }

    /**
     * Narrates one chunk through the model's shared seam.
     *
     * @since n.e.x.t
     *
     * @param JobRecord $record     The job record.
     * @param int       $chunkIndex The chunk to narrate.
     * @return string The raw audio bytes.
     * @throws Throwable If the request fails.
     */
    protected function narrate(array $record, int $chunkIndex): string
    {
        $model = $this->modelFactory !== null
            ? ($this->modelFactory)($record)
            : $this->buildModel($record);

        if (!$model instanceof ProviderForElevenLabsTextToSpeechModel) {
            throw new \RuntimeException(
                'The ElevenLabs text-to-speech model could not be built for this narration job.'
            );
        }

        $chunks = $record['chunks'];
        $last = count($chunks) - 1;

        return $model->narrateChunk(
            $record['voice_id'],
            $chunks[$chunkIndex]['text'],
            $chunkIndex > 0 ? $chunks[$chunkIndex - 1]['text'] : null,
            $chunkIndex < $last ? $chunks[$chunkIndex + 1]['text'] : null,
            $record['output_format']
        );
    }

    /**
     * Handles a chunk that failed, retrying it or failing the job.
     *
     * @since n.e.x.t
     *
     * @param string $jobId      The job id.
     * @param int    $chunkIndex The chunk that failed.
     * @param string $reason     The failure reason.
     * @return void
     */
    protected function handleChunkFailure(string $jobId, int $chunkIndex, string $reason): void
    {
        if ($this->store->attempts($jobId, $chunkIndex) >= self::MAX_ATTEMPTS) {
            $this->fail(
                $jobId,
                sprintf(
                    'Chunk %d failed %d times and the narration was abandoned. Last error: %s',
                    $chunkIndex,
                    self::MAX_ATTEMPTS,
                    $reason
                )
            );

            return;
        }

        $this->queue->scheduleChunk($jobId, $chunkIndex, self::RETRY_DELAY);
    }

    /**
     * Joins the completed chunks and delivers the finished audio.
     *
     * @since n.e.x.t
     *
     * @param string $jobId The job id.
     * @return void
     */
    protected function assemble(string $jobId): void
    {
        $record = $this->store->get($jobId);

        if ($record === null) {
            return;
        }

        $paths = $this->store->completedPaths($jobId);

        if (count($paths) !== count($record['chunks'])) {
            $this->fail($jobId, 'Some narrated audio went missing before it could be joined.');

            return;
        }

        $target = $this->store->jobDirectory($jobId) . '/narration.' . $this->extensionFor($record);

        if (file_put_contents($target, '') === false) {
            $this->fail($jobId, sprintf('Could not write the joined narration to "%s".', $target));

            return;
        }

        /*
         * Appended one chunk at a time rather than concatenated in memory. A
         * long post is tens of megabytes, and holding all of it plus the joined
         * copy is how this falls over on a small host.
         */
        foreach ($paths as $path) {
            $chunkBytes = file_get_contents($path);

            if ($chunkBytes === false) {
                $this->fail($jobId, sprintf('Narrated audio at "%s" could not be read back.', $path));

                return;
            }

            file_put_contents($target, $chunkBytes, FILE_APPEND);
        }

        $attachmentId = $this->createAttachment($target, $record);

        // Only once the artefact is safely elsewhere. Never before.
        $this->store->completeJob($jobId, $attachmentId);
        $this->store->removeJobDirectory($jobId);

        $this->fire(self::HOOK_COMPLETE, $jobId, $attachmentId);
    }

    /**
     * Fails a job, records why, and cleans up after it.
     *
     * @since n.e.x.t
     *
     * @param string $jobId  The job id.
     * @param string $reason The failure reason.
     * @return void
     */
    protected function fail(string $jobId, string $reason): void
    {
        $this->store->failJob($jobId, $reason);
        $this->store->removeJobDirectory($jobId);

        $this->fire(self::HOOK_FAILED, $jobId, $reason);
    }

    /**
     * Writes one chunk's audio and returns where it landed.
     *
     * @since n.e.x.t
     *
     * @param string $jobId      The job id.
     * @param int    $chunkIndex The chunk index.
     * @param string $bytes      The audio bytes.
     * @return string The absolute path.
     * @throws \RuntimeException If the audio cannot be written.
     */
    protected function writeChunkAudio(string $jobId, int $chunkIndex, string $bytes): string
    {
        $directory = $this->store->createJobDirectory($jobId);
        $path = sprintf('%s/chunk-%03d', $directory, $chunkIndex);

        if (file_put_contents($path, $bytes) === false) {
            throw new \RuntimeException(sprintf('Could not write narrated audio to "%s".', $path));
        }

        return $path;
    }

    /**
     * Adds the finished audio to the media library.
     *
     * @since n.e.x.t
     *
     * @param string    $path   Absolute path to the joined audio.
     * @param JobRecord $record The job record.
     * @return int The attachment id, or 0 when WordPress is unavailable.
     */
    protected function createAttachment(string $path, array $record): int
    {
        if (
            !function_exists('wp_upload_dir')
            || !function_exists('wp_insert_attachment')
            || !function_exists('wp_unique_filename')
        ) {
            return 0;
        }

        $uploads = wp_upload_dir();

        if (!is_array($uploads) || !isset($uploads['path']) || !is_string($uploads['path'])) {
            return 0;
        }

        $filename = wp_unique_filename($uploads['path'], basename($path));

        if (!is_string($filename) || $filename === '') {
            return 0;
        }

        $destination = $uploads['path'] . '/' . $filename;

        if (!copy($path, $destination)) {
            return 0;
        }

        $attachmentId = wp_insert_attachment(
            [
                'post_mime_type' => $record['mime_type'],
                'post_title'     => sprintf('Narration %s', $record['id']),
                'post_status'    => 'inherit',
            ],
            $destination
        );

        return is_int($attachmentId) && $attachmentId > 0 ? $attachmentId : 0;
    }

    /**
     * Builds the model for a job through the provider.
     *
     * @since n.e.x.t
     *
     * @param JobRecord $record The job record.
     * @return mixed The model.
     */
    protected function buildModel(array $record)
    {
        return ProviderForElevenLabs::model(
            $record['model_id'],
            ModelConfig::fromArray([
                'outputSpeechVoice' => $record['voice_id'],
                'customOptions'     => ['output_format' => $record['output_format']],
            ])
        );
    }

    /**
     * Fires an action, when running inside WordPress.
     *
     * @since n.e.x.t
     *
     * @param string $hook    The action name.
     * @param string $jobId   The job id.
     * @param mixed  $context The attachment id or failure reason.
     * @return void
     */
    protected function fire(string $hook, string $jobId, $context): void
    {
        if (!function_exists('do_action')) {
            return;
        }

        do_action($hook, $jobId, $context);
    }

    /**
     * Returns the statuses from which no further work should be done.
     *
     * @since n.e.x.t
     *
     * @return list<string> The terminal statuses.
     */
    protected function terminalStatuses(): array
    {
        return [
            NarrationJobStore::STATUS_COMPLETE,
            NarrationJobStore::STATUS_FAILED,
            NarrationJobStore::STATUS_CANCELLED,
        ];
    }

    /**
     * Returns the file extension for a job's audio.
     *
     * @since n.e.x.t
     *
     * @param JobRecord $record The job record.
     * @return string The extension.
     */
    protected function extensionFor(array $record): string
    {
        $prefix = explode('_', $record['output_format'])[0];

        return $prefix === '' ? 'audio' : $prefix;
    }

    /**
     * Builds an identifier for this process.
     *
     * @since n.e.x.t
     *
     * @return string The owner identifier.
     */
    protected function generateOwner(): string
    {
        try {
            return getmypid() . '-' . bin2hex(random_bytes(4));
        } catch (\Exception $e) {
            return uniqid('runner', true);
        }
    }
}
