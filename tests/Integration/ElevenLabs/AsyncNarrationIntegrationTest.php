<?php

declare(strict_types=1);

namespace AiProviderForElevenLabs\Tests\Integration\ElevenLabs;

use AiProviderForElevenLabs\Jobs\NarrationJobRunner;
use AiProviderForElevenLabs\Jobs\NarrationJobStore;
use AiProviderForElevenLabs\Jobs\NarrationJobs;
use AiProviderForElevenLabs\Jobs\WpCronNarrationQueue;
use AiProviderForElevenLabs\Tests\Integration\Traits\IntegrationTestTrait;
use PHPUnit\Framework\TestCase;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\ProviderRegistry;

/**
 * Integration tests for background narration against the live API.
 *
 * The synchronous path was measured at 37.2 seconds for 16,799 characters,
 * against a default `max_execution_time` of 30. This suite checks the fix
 * actually fixes that. The number worth watching is not the total, which is
 * necessarily similar since the same audio gets synthesised either way, but the
 * **slowest single chunk**. If one chunk still runs long, the chunk budget is
 * wrong and the feature is no more deployable than it was.
 *
 * @group integration
 * @group elevenlabs
 *
 * @coversNothing
 */
class AsyncNarrationIntegrationTest extends TestCase
{
    use IntegrationTestTrait;

    /**
     * @var string A known premade voice ID (Rachel).
     */
    private const VOICE_ID = '21m00Tcm4TlvDq8ikWAM';

    private ProviderRegistry $registry;

    private string $audioOutputDir;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requireApiKey('ELEVENLABS_API_KEY');

        $this->registry = $this->createElevenLabsRegistry();

        $this->audioOutputDir = dirname(__DIR__) . '/audio';
        if (!is_dir($this->audioOutputDir)) {
            mkdir($this->audioOutputDir, 0755, true);
        }

        $this->base = sys_get_temp_dir() . '/narration-integration-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->base)) {
            foreach ((array) glob($this->base . '/*') as $directory) {
                if (is_dir((string) $directory)) {
                    foreach ((array) glob($directory . '/*') as $file) {
                        unlink((string) $file);
                    }
                    rmdir((string) $directory);
                }
            }
            rmdir($this->base);
        }

        parent::tearDown();
    }

    /**
     * Builds text long enough to need several chunks.
     */
    private function articleText(int $sentences = 90): string
    {
        return trim(str_repeat(
            'The quick brown fox jumps over the lazy dog, and then pauses to consider the matter. ',
            $sentences
        ));
    }

    /**
     * Builds a store that keeps its audio somewhere disposable.
     */
    private function createStore(): NarrationJobStore
    {
        return new class ($this->base) extends NarrationJobStore {
            private string $root;

            public function __construct(string $root)
            {
                $this->root = $root;
            }

            protected function baseDirectory(): string
            {
                return $this->root;
            }
        };
    }

    public function testAnArticleIsNarratedThroughTheQueueAsOneJoinedFile(): void
    {
        $store = $this->createStore();
        $queue = new WpCronNarrationQueue();
        $registry = $this->registry;

        $modelFactory = static fn(string $modelId, array $config) => $registry->getProviderModel(
            'elevenlabs',
            $modelId,
            ModelConfig::fromArray($config)
        );

        $jobs = new NarrationJobs($store, $queue, $modelFactory);

        // No chunk_size override: this must exercise the shipped default.
        $text = $this->articleText();
        $jobId = $jobs->enqueue($text, ['voice' => self::VOICE_ID]);

        $progress = $jobs->progress($jobId);
        $this->assertNotNull($progress);
        $this->assertGreaterThan(1, $progress['total'], 'The article should need several chunks.');

        $chunkCount = $progress['total'];

        /*
         * The runner's factory takes a whole record, where the facade's takes a
         * model id and config. Adapt rather than reuse: passing the wrong arity
         * fails inside a try/catch as an ordinary chunk failure, which is very
         * hard to read from the outside.
         */
        $recordFactory = static fn(array $record) => $modelFactory(
            $record['model_id'],
            [
                'outputSpeechVoice' => $record['voice_id'],
                'customOptions'     => ['output_format' => $record['output_format']],
            ]
        );

        // Captures the joined audio, which assembly deletes moments later.
        $runner = new class ($store, $queue, $recordFactory) extends NarrationJobRunner {
            public ?string $captured = null;

            protected function createAttachment(string $path, array $record): int
            {
                $contents = file_get_contents($path);
                $this->captured = $contents === false ? null : $contents;

                return 1;
            }

            protected function fire(string $hook, string $jobId, $context): void
            {
                // No WordPress here; the assertions read the store instead.
            }
        };

        $timings = [];
        $totalStart = microtime(true);

        for ($index = 0; $index < $chunkCount; $index++) {
            $this->assertTrue(
                $queue->isChunkPending($jobId, $index),
                sprintf('Chunk %d was never scheduled, so the chain broke.', $index)
            );

            $queue->cancelChunk($jobId, $index);

            $chunkStart = microtime(true);
            $runner->run($jobId, $index);
            $timings[] = microtime(true) - $chunkStart;
        }

        $total = microtime(true) - $totalStart;

        $final = $jobs->progress($jobId);
        $this->assertNotNull($final);
        $this->assertSame(NarrationJobStore::STATUS_COMPLETE, $final['status']);
        $this->assertSame($chunkCount, $final['completed']);

        $audio = (string) $runner->captured;
        $this->assertNotSame('', $audio, 'No joined audio was delivered.');
        $this->assertGreaterThan(1000, strlen($audio));
        $this->assertSame('ID3', substr($audio, 0, 3), 'The joined file is not an MP3.');

        file_put_contents($this->audioOutputDir . '/async-narration.mp3', $audio);

        $slowest = max($timings);

        // STDERR, because PHPUnit marks a test risky for writing to STDOUT.
        fwrite(
            STDERR,
            sprintf(
                "\nAsync narration: %d chars, %d chunks, %.1fs total, slowest chunk %.1fs\n",
                mb_strlen($text),
                $chunkCount,
                $total,
                $slowest
            )
        );

        /*
         * The whole point of the exercise. Synthesis runs at roughly 92
         * characters per second, so the default 1,000-character chunk should
         * land near eleven seconds. Twenty-five leaves room for a slow day
         * while still failing well before the 30-second limit that would put
         * this back where it started.
         */
        $this->assertLessThan(
            25.0,
            $slowest,
            sprintf(
                'A single chunk took %.1fs against a default max_execution_time of 30. '
                . 'DEFAULT_CHUNK_SIZE needs lowering.',
                $slowest
            )
        );
    }

    public function testTheJobDirectoryIsCleanedUpAfterwards(): void
    {
        $store = $this->createStore();
        $queue = new WpCronNarrationQueue();
        $registry = $this->registry;

        $jobs = new NarrationJobs(
            $store,
            $queue,
            static fn(string $modelId, array $config) => $registry->getProviderModel(
                'elevenlabs',
                $modelId,
                ModelConfig::fromArray($config)
            )
        );

        $jobId = $jobs->enqueue('A short line of narration.', ['voice' => self::VOICE_ID]);

        $jobs->runChunk($jobId, 0);

        $this->assertDirectoryDoesNotExist($store->jobDirectory($jobId));
    }
}
