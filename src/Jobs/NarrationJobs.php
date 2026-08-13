<?php

declare(strict_types=1);

namespace AiProviderForElevenLabs\Jobs;

use AiProviderForElevenLabs\Models\ProviderForElevenLabsTextToSpeechModel;
use AiProviderForElevenLabs\Provider\ProviderForElevenLabs;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;

/**
 * Starts, inspects and cancels background narration.
 *
 * The entry point for narrating text that cannot finish inside one request.
 * Measured against the live API, 16,799 characters took 37.2 seconds; PHP's
 * default `max_execution_time` is 30. Chunk-sized jobs are what make a
 * full-length post narratable at all.
 *
 * This is deliberately separate from `convertTextToSpeechResult()` rather than
 * a mode of it. That method implements a synchronous SDK contract: it is handed
 * a prompt and must return a result. A method that sometimes returns audio and
 * sometimes returns a job handle would be a worse contract than two honest
 * ones, so the synchronous path is untouched.
 *
 * @since n.e.x.t
 *
 * @phpstan-import-type JobRecord from NarrationJobStore
 */
class NarrationJobs
{
    /**
     * Filters the characters per chunk.
     *
     * @since n.e.x.t
     *
     * @var string
     */
    public const FILTER_CHUNK_SIZE = 'ai_provider_elevenlabs_narration_chunk_size';

    /**
     * Characters per chunk by default.
     *
     * Measured against the live API rather than reasoned about. Synthesis is
     * linear in characters at roughly 92 per second, with no meaningful
     * per-request overhead:
     *
     * | characters | seconds |
     * |------------|---------|
     * | 1,000      | 11.0    |
     * | 2,000      | 21.7    |
     * | 4,000      | 42.4    |
     * | 8,000      | 83.8    |
     *
     * PHP's default `max_execution_time` is 30 seconds, so anything above about
     * 2,500 characters cannot finish in one request -- which is the very
     * failure this whole mechanism exists to avoid. A thousand characters lands
     * near eleven seconds, leaving room for a slow response without needing a
     * host that has raised its limits.
     *
     * Because the relationship is linear, smaller chunks cost no extra time
     * overall. They only trade one long request for several short ones, which
     * is exactly the trade being made here.
     *
     * @since n.e.x.t
     *
     * @var int
     */
    public const DEFAULT_CHUNK_SIZE = 1000;

    /**
     * Model used when the caller names none.
     *
     * @since n.e.x.t
     *
     * @var string
     */
    public const DEFAULT_MODEL = 'eleven_multilingual_v2';

    private NarrationJobStore $store;

    private NarrationQueue $queue;

    /**
     * Builds a text-to-speech model from a model id and config array.
     *
     * @var (callable(string, array<string, mixed>): mixed)|null
     */
    private $modelFactory;

    /**
     * Constructor.
     *
     * @since n.e.x.t
     *
     * @param NarrationJobStore|null $store        The job store.
     * @param NarrationQueue|null    $queue        The queue, or null for this site's.
     * @param callable|null          $modelFactory Builds a model, or null for the provider's.
     */
    public function __construct(
        ?NarrationJobStore $store = null,
        ?NarrationQueue $queue = null,
        ?callable $modelFactory = null
    ) {
        $this->store = $store ?? new NarrationJobStore();
        $this->queue = $queue ?? WpCronNarrationQueue::resolve();
        $this->modelFactory = $modelFactory;
    }

    /**
     * Queues text for narration and returns the job id.
     *
     * Chunking happens now rather than at run time, so the chunk count -- and
     * therefore progress -- is known immediately, and unusable input fails here
     * in front of the caller instead of silently inside a cron callback.
     *
     * @since n.e.x.t
     *
     * @param string               $text    The text to narrate.
     * @param array<string, mixed> $options model, voice, output_format, chunk_size.
     * @return string The job id.
     * @throws InvalidArgumentException If the text is empty or cannot be narrated.
     */
    public function enqueue(string $text, array $options = []): string
    {
        if (trim($text) === '') {
            throw new InvalidArgumentException('There is no text to narrate.');
        }

        $modelId = $this->stringOption($options, 'model', self::DEFAULT_MODEL);
        $model = $this->buildModel($modelId, $this->modelConfig($options));

        $voiceId = $model->getVoiceId();
        $outputFormat = $model->resolveOutputFormat();
        $mimeType = $model->resolveMimeTypeFromFormat($outputFormat);

        // Raises for a format that cannot be joined, before a single credit is spent.
        $chunks = $model->splitTextForRequests($text, $outputFormat, $this->chunkSize($options));

        $jobId = $this->store->create($chunks, [
            'model_id'      => $modelId,
            'voice_id'      => $voiceId,
            'output_format' => $outputFormat,
            'mime_type'     => $mimeType,
        ]);

        // Fails here if the uploads directory is unusable, rather than mid-narration.
        $this->store->createJobDirectory($jobId);

        $this->queue->scheduleChunk($jobId, 0);

        return $jobId;
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
        return $this->store->progress($jobId);
    }

    /**
     * Stops a job and cleans up after it.
     *
     * @since n.e.x.t
     *
     * @param string $jobId The job id.
     * @return void
     */
    public function cancel(string $jobId): void
    {
        $record = $this->store->get($jobId);

        if ($record === null) {
            return;
        }

        foreach (array_keys($record['chunks']) as $index) {
            $this->queue->cancelChunk($jobId, $index);
        }

        $this->store->cancelJob($jobId);
        $this->store->removeJobDirectory($jobId);
    }

    /**
     * Narrates one chunk. This is what the scheduled event calls.
     *
     * @since n.e.x.t
     *
     * @param string $jobId      The job id.
     * @param int    $chunkIndex The chunk to narrate.
     * @return void
     */
    public function runChunk(string $jobId, int $chunkIndex): void
    {
        $this->runner()->run($jobId, $chunkIndex);
    }

    /**
     * Builds the runner for this site.
     *
     * @since n.e.x.t
     *
     * @return NarrationJobRunner The runner.
     */
    protected function runner(): NarrationJobRunner
    {
        return new NarrationJobRunner(
            $this->store,
            $this->queue,
            $this->modelFactory === null ? null : $this->modelForRecord(...)
        );
    }

    /**
     * Builds the model a stored job needs, through the injected factory.
     *
     * @since n.e.x.t
     *
     * @param JobRecord $record The job record.
     * @return mixed The model.
     */
    protected function modelForRecord(array $record)
    {
        return $this->buildModel($record['model_id'], [
            'outputSpeechVoice' => $record['voice_id'],
            'customOptions'     => ['output_format' => $record['output_format']],
        ]);
    }

    /**
     * Builds a text-to-speech model.
     *
     * @since n.e.x.t
     *
     * @param string $modelId The model id.
     * @param array{outputSpeechVoice?: string, customOptions?: array<string, mixed>} $config The model config.
     * @return ProviderForElevenLabsTextToSpeechModel The model.
     * @throws InvalidArgumentException If the model is not an ElevenLabs speech model.
     */
    protected function buildModel(string $modelId, array $config): ProviderForElevenLabsTextToSpeechModel
    {
        $model = $this->modelFactory !== null
            ? ($this->modelFactory)($modelId, $config)
            : ProviderForElevenLabs::model($modelId, ModelConfig::fromArray($config));

        if (!$model instanceof ProviderForElevenLabsTextToSpeechModel) {
            throw new InvalidArgumentException(
                sprintf('"%s" is not an ElevenLabs text-to-speech model.', $modelId)
            );
        }

        return $model;
    }

    /**
     * Builds the model config from the caller's options.
     *
     * @since n.e.x.t
     *
     * @param array<string, mixed> $options The caller's options.
     * @return array{outputSpeechVoice?: string, customOptions?: array<string, mixed>} The model config.
     */
    protected function modelConfig(array $options): array
    {
        $config = [];

        $voice = $this->stringOption($options, 'voice', '');
        if ($voice !== '') {
            $config['outputSpeechVoice'] = $voice;
        }

        $format = $this->stringOption($options, 'output_format', '');
        if ($format !== '') {
            $config['customOptions'] = ['output_format' => $format];
        }

        return $config;
    }

    /**
     * Returns the characters per chunk for this job.
     *
     * @since n.e.x.t
     *
     * @param array<string, mixed> $options The caller's options.
     * @return int The chunk size.
     */
    protected function chunkSize(array $options): int
    {
        $size = isset($options['chunk_size']) && is_int($options['chunk_size'])
            ? $options['chunk_size']
            : self::DEFAULT_CHUNK_SIZE;

        if (function_exists('apply_filters')) {
            $filtered = apply_filters(self::FILTER_CHUNK_SIZE, $size);

            if (is_int($filtered)) {
                $size = $filtered;
            }
        }

        return max($size, 1);
    }

    /**
     * Reads a string option, falling back to a default.
     *
     * @since n.e.x.t
     *
     * @param array<string, mixed> $options The options.
     * @param string               $key     The key to read.
     * @param string               $default The fallback.
     * @return string The value.
     */
    protected function stringOption(array $options, string $key, string $default): string
    {
        return isset($options[$key]) && is_string($options[$key]) && $options[$key] !== ''
            ? $options[$key]
            : $default;
    }
}
