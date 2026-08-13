<?php

declare(strict_types=1);

namespace AiProviderForElevenLabs\Models;

use AiProviderForElevenLabs\Metadata\ProviderForElevenLabsModelMetadataDirectory;
use AiProviderForElevenLabs\Provider\ProviderForElevenLabs;
use AiProviderForElevenLabs\Text\TextChunker;
use AiProviderForElevenLabs\Voices\VoiceDirectory;
use Exception;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Http\Util\ResponseUtil;
use WordPress\AiClient\Providers\Models\TextToSpeechConversion\Contracts\TextToSpeechConversionModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;

/**
 * Class for text-to-speech models used by the provider for ElevenLabs.
 *
 * Calls the ElevenLabs `POST /text-to-speech/{voice_id}` endpoint to convert
 * text into audio. The binary audio response is base64-encoded and returned as
 * an inline {@see File} in the result.
 *
 * @since 0.1.0
 */
class ProviderForElevenLabsTextToSpeechModel extends AbstractApiBasedModel implements
    TextToSpeechConversionModelInterface
{
    /**
     * Default voice settings applied when no custom values are provided.
     *
     * @since 0.1.0
     *
     * @var array<string, mixed>
     */
    private const DEFAULT_VOICE_SETTINGS = [
        'stability'         => 0.5,
        'similarity_boost'  => 0.75,
        'style'             => 0.0,
        'use_speaker_boost' => true,
    ];

    /**
     * Custom option keys that belong inside `voice_settings` rather than at the
     * top level of the request body.
     *
     * `speed` is accepted but deliberately has no entry in
     * {@see self::DEFAULT_VOICE_SETTINGS}, so it is sent only when a caller asks
     * for it rather than pinning a value the API would otherwise choose.
     *
     * @since n.e.x.t
     *
     * @var list<string>
     */
    private const VOICE_SETTING_KEYS = [
        'stability',
        'similarity_boost',
        'style',
        'use_speaker_boost',
        'speed',
    ];

    /**
     * Body parameters the provider manages and a caller may not set.
     *
     * These carry the neighbouring text across chunk boundaries when long input
     * is narrated in several requests. They are reserved unconditionally rather
     * than only while chunking, so the contract does not change shape with the
     * length of the input: allowing them for short text and rejecting them for
     * long text would be surprising in exactly the case that is hardest to
     * reproduce.
     *
     * @since n.e.x.t
     *
     * @var list<string>
     */
    private const PROVIDER_MANAGED_KEYS = [
        'previous_text',
        'next_text',
    ];

    /**
     * Output format prefixes whose audio can be concatenated into one file.
     *
     * MP3 is frame-based and the raw formats are headerless, so joining their
     * bytes yields a playable file. Opus is carried in an Ogg container with
     * per-stream headers, and AAC has not been confirmed to be ADTS-framed
     * rather than MP4-contained, so both are excluded: emitting audio that is
     * subtly broken is worse than refusing.
     *
     * @since n.e.x.t
     *
     * @var list<string>
     */
    private const JOINABLE_FORMAT_PREFIXES = [
        'mp3',
        'pcm',
        'ulaw',
        'alaw',
    ];

    /**
     * Default output format when no outputMimeType is configured.
     *
     * @since 0.1.0
     *
     * @var string
     */
    private const DEFAULT_OUTPUT_FORMAT = 'mp3_44100_128';

    /**
     * Map of MIME types to ElevenLabs output_format values.
     *
     * @since 0.1.0
     *
     * @var array<string, string>
     */
    private const MIME_TYPE_TO_OUTPUT_FORMAT = [
        'audio/mpeg' => 'mp3_44100_128',
        'audio/mp3'  => 'mp3_44100_128',
        'audio/pcm'  => 'pcm_44100',
        'audio/wav'  => 'pcm_44100',
        'audio/ogg'  => 'opus_48000_128',
        'audio/opus' => 'opus_48000_128',
        'audio/aac'  => 'aac_44100_128',
    ];

    /**
     * Map of ElevenLabs output_format prefixes to MIME types.
     *
     * @since 0.1.0
     *
     * @var array<string, string>
     */
    private const OUTPUT_FORMAT_PREFIX_TO_MIME = [
        'mp3'  => 'audio/mpeg',
        'pcm'  => 'audio/pcm',
        'ulaw' => 'audio/basic',
        'opus' => 'audio/opus',
        'aac'  => 'audio/aac',
    ];

    /**
     * Lazily built voice directory used to resolve a default voice.
     *
     * @since n.e.x.t
     *
     * @var VoiceDirectory|null
     */
    private ?VoiceDirectory $voiceDirectory = null;

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    public function convertTextToSpeechResult(array $prompt): GenerativeAiResult
    {
        $text = $this->extractTextFromPrompt($prompt);
        $voiceId = $this->getVoiceId();
        $outputFormat = $this->resolveOutputFormat();

        $baseParams = $this->applyCustomOptions([
            'text'           => $text,
            'model_id'       => $this->metadata()->getId(),
            'voice_settings' => $this->resolveVoiceSettings(),
            'output_format'  => $outputFormat,
        ]);

        $chunks = $this->splitTextForRequests($text, $outputFormat);
        $chunkCount = count($chunks);

        $binaryData = '';
        foreach ($chunks as $index => $chunk) {
            $params = $baseParams;
            $params['text'] = $chunk;

            /*
             * Give the model the neighbouring text so prosody carries across a
             * seam. Only meaningful when the text was actually split, so a
             * request that fits stays byte-identical to before.
             */
            if ($chunkCount > 1) {
                if ($index > 0) {
                    $params['previous_text'] = $chunks[$index - 1];
                }
                if ($index < $chunkCount - 1) {
                    $params['next_text'] = $chunks[$index + 1];
                }
            }

            $binaryData .= $this->sendSpeechRequest($voiceId, $params);
        }

        $mimeType = $this->resolveMimeTypeFromFormat($outputFormat);
        $base64Data = base64_encode($binaryData);
        $audioFile = new File($base64Data, $mimeType);
        $parts = [new MessagePart($audioFile)];
        $message = new Message(MessageRoleEnum::model(), $parts);
        $candidate = new Candidate($message, FinishReasonEnum::stop());

        return new GenerativeAiResult(
            '',
            [$candidate],
            new TokenUsage(0, 0, 0),
            $this->providerMetadata(),
            $this->metadata(),
            []
        );
    }

    /**
     * Extracts text content from the prompt messages.
     *
     * Concatenates all text parts from all user messages. Throws if no text is found.
     *
     * @since 0.1.0
     *
     * @param list<Message> $messages The prompt messages.
     * @return string The extracted text.
     * @throws InvalidArgumentException If no text content is found.
     */
    protected function extractTextFromPrompt(array $messages): string
    {
        $textParts = [];
        foreach ($messages as $message) {
            foreach ($message->getParts() as $part) {
                $text = $part->getText();
                if ($text !== null) {
                    $textParts[] = $text;
                }
            }
        }

        if ($textParts === []) {
            throw new InvalidArgumentException(
                'The prompt must contain at least one text message.'
            );
        }

        return implode(' ', $textParts);
    }

    /**
     * Gets the voice ID to synthesise with.
     *
     * ElevenLabs requires a voice ID in the request path, but the AI Client's
     * `outputSpeechVoice` option is optional, so a caller that simply prompts
     * the provider supplies no voice at all. Rather than fail that case, fall
     * back to a default drawn from the account's own voices.
     *
     * @since 0.1.0
     *
     * @return string The voice ID.
     * @throws InvalidArgumentException If no voice is configured and none can be discovered.
     */
    protected function getVoiceId(): string
    {
        $voiceId = $this->getConfig()->getOutputSpeechVoice();
        if ($voiceId !== null && $voiceId !== '') {
            return $voiceId;
        }

        $failureReason = null;

        try {
            $defaultVoiceId = $this->voiceDirectory()->getDefaultVoiceId();
        } catch (Exception $e) {
            $defaultVoiceId = null;
            $failureReason = $e->getMessage();
        }

        if ($defaultVoiceId !== null && $defaultVoiceId !== '') {
            return $defaultVoiceId;
        }

        $message = 'No voice was configured and no default could be determined for this ElevenLabs '
            . 'account. Set the "outputSpeechVoice" option to a voice ID from '
            . 'https://elevenlabs.io/app/voice-library.';

        if ($failureReason !== null) {
            $message .= ' Voice lookup failed: ' . $failureReason;
        }

        throw new InvalidArgumentException($message);
    }

    /**
     * Splits the prompt text into the pieces each request will carry.
     *
     * Text that already fits produces a single piece, so the common case issues
     * exactly one request with the body it always had.
     *
     * @since n.e.x.t
     *
     * @param string $text         The full prompt text.
     * @param string $outputFormat The resolved ElevenLabs output format.
     * @return list<string> The text for each request, in order.
     * @throws InvalidArgumentException If splitting is required but the format cannot be joined.
     */
    protected function splitTextForRequests(string $text, string $outputFormat): array
    {
        $limit = $this->maxTextLength();

        if (mb_strlen($text) <= $limit) {
            return [$text];
        }

        /*
         * Checked before any request is sent. Discovering mid-narration that the
         * pieces cannot be reassembled would waste the credits already spent.
         */
        $this->assertFormatIsJoinable($outputFormat, $limit);

        $chunks = TextChunker::split($text, $limit);

        return $chunks === [] ? [$text] : $chunks;
    }

    /**
     * Returns the character limit for the model in use.
     *
     * @since n.e.x.t
     *
     * @return int The maximum characters accepted in a single request.
     */
    protected function maxTextLength(): int
    {
        $directory = ProviderForElevenLabs::modelMetadataDirectory();

        if ($directory instanceof ProviderForElevenLabsModelMetadataDirectory) {
            return $directory->getMaxTextLength($this->metadata()->getId());
        }

        return ProviderForElevenLabsModelMetadataDirectory::DEFAULT_MAX_TEXT_LENGTH;
    }

    /**
     * Fails when audio in the given format could not be reassembled.
     *
     * @since n.e.x.t
     *
     * @param string $outputFormat The resolved ElevenLabs output format.
     * @param int    $limit        The character limit that forced the split.
     * @return void
     * @throws InvalidArgumentException If the format cannot be safely joined.
     */
    protected function assertFormatIsJoinable(string $outputFormat, int $limit): void
    {
        $prefix = explode('_', $outputFormat)[0];

        if (in_array($prefix, self::JOINABLE_FORMAT_PREFIXES, true)) {
            return;
        }

        throw new InvalidArgumentException(
            sprintf(
                'Text longer than %d characters is narrated in several requests, but "%s" audio '
                . 'cannot be joined back into a single file. Choose an MP3 or PCM output format, '
                . 'or shorten the text.',
                $limit,
                $outputFormat
            )
        );
    }

    /**
     * Sends one speech request and returns its audio bytes.
     *
     * @since n.e.x.t
     *
     * @param string               $voiceId The voice to synthesise with.
     * @param array<string, mixed> $params  The request body.
     * @return string The raw audio bytes.
     * @throws ResponseException If the response carries no audio.
     */
    protected function sendSpeechRequest(string $voiceId, array $params): string
    {
        $request = new Request(
            HttpMethodEnum::POST(),
            ProviderForElevenLabs::url('text-to-speech/' . $voiceId),
            ['Content-Type' => 'application/json', 'Accept' => 'audio/mpeg'],
            $params,
            $this->getRequestOptions()
        );

        $request = $this->getRequestAuthentication()->authenticateRequest($request);
        $response = $this->getHttpTransporter()->send($request);

        ResponseUtil::throwIfNotSuccessful($response);

        $binaryData = $response->getBody();
        if ($binaryData === null || $binaryData === '') {
            throw ResponseException::fromInvalidData(
                $this->providerMetadata()->getName(),
                'text-to-speech/' . $voiceId,
                'The audio response body was empty.'
            );
        }

        return $binaryData;
    }

    /**
     * Gets a voice directory backed by this model's transport and credentials.
     *
     * Built from the model's own transporter and authentication rather than the
     * provider's shared instance, so it is always configured whenever the model
     * itself is able to make requests.
     *
     * @since n.e.x.t
     *
     * @return VoiceDirectory The voice directory.
     */
    protected function voiceDirectory(): VoiceDirectory
    {
        if ($this->voiceDirectory === null) {
            $voiceDirectory = new VoiceDirectory();
            $voiceDirectory->setHttpTransporter($this->getHttpTransporter());
            $voiceDirectory->setRequestAuthentication($this->getRequestAuthentication());

            $this->voiceDirectory = $voiceDirectory;
        }

        return $this->voiceDirectory;
    }

    /**
     * Resolves the ElevenLabs output_format parameter.
     *
     * Uses outputMimeType from config if set, otherwise falls back to the default.
     *
     * @since 0.1.0
     *
     * @return string The ElevenLabs output_format value.
     */
    protected function resolveOutputFormat(): string
    {
        $customOptions = $this->getConfig()->getCustomOptions();
        if (isset($customOptions['output_format']) && is_string($customOptions['output_format'])) {
            return $customOptions['output_format'];
        }

        $mimeType = $this->getConfig()->getOutputMimeType();
        if ($mimeType !== null && isset(self::MIME_TYPE_TO_OUTPUT_FORMAT[$mimeType])) {
            return self::MIME_TYPE_TO_OUTPUT_FORMAT[$mimeType];
        }

        return self::DEFAULT_OUTPUT_FORMAT;
    }

    /**
     * Resolves voice settings by merging defaults with custom options.
     *
     * @since 0.1.0
     *
     * @return array<string, mixed> The voice settings.
     */
    protected function resolveVoiceSettings(): array
    {
        $customOptions = $this->getConfig()->getCustomOptions();

        $voiceSettings = self::DEFAULT_VOICE_SETTINGS;
        foreach (self::VOICE_SETTING_KEYS as $key) {
            if (array_key_exists($key, $customOptions)) {
                $voiceSettings[$key] = $customOptions[$key];
            }
        }

        return $voiceSettings;
    }

    /**
     * Merges caller-supplied custom options into the request body.
     *
     * The model advertises `OptionEnum::customOptions()`, which promises callers
     * that provider-specific parameters reach the API. Only a handful were
     * honoured previously and the rest were dropped silently, so options such as
     * `language_code`, `seed`, and `apply_text_normalization` had no way through.
     *
     * Voice settings and `output_format` are excluded here because they are
     * consumed elsewhere: the former nests under `voice_settings`, the latter
     * selects the audio encoding.
     *
     * @since n.e.x.t
     *
     * @param array<string, mixed> $params Request parameters the provider has already set.
     * @return array<string, mixed> The parameters with custom options merged in.
     * @throws InvalidArgumentException If a custom option collides with a provider-set parameter.
     */
    protected function applyCustomOptions(array $params): array
    {
        foreach ($this->getConfig()->getCustomOptions() as $key => $value) {
            if (in_array($key, self::VOICE_SETTING_KEYS, true) || $key === 'output_format') {
                continue;
            }

            if (in_array($key, self::PROVIDER_MANAGED_KEYS, true)) {
                throw new InvalidArgumentException(
                    sprintf(
                        'The custom option "%s" is managed by the provider, which sets it when long '
                        . 'text is narrated across several requests.',
                        $key
                    )
                );
            }

            if (array_key_exists($key, $params)) {
                throw new InvalidArgumentException(
                    sprintf('The custom option "%s" conflicts with a parameter set by the provider.', $key)
                );
            }

            $params[$key] = $value;
        }

        return $params;
    }

    /**
     * Determines the MIME type from an ElevenLabs output_format string.
     *
     * @since 0.1.0
     *
     * @param string $outputFormat The ElevenLabs output_format value.
     * @return string The MIME type.
     */
    protected function resolveMimeTypeFromFormat(string $outputFormat): string
    {
        $prefix = explode('_', $outputFormat)[0];
        return self::OUTPUT_FORMAT_PREFIX_TO_MIME[$prefix] ?? 'audio/mpeg';
    }
}
