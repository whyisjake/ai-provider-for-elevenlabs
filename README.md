# AI Provider for ElevenLabs

A third-party provider for [ElevenLabs](https://elevenlabs.io/) in the [PHP AI Client](https://github.com/WordPress/php-ai-client) SDK. Works as both a Composer package and a WordPress plugin.

This project is independent and is not affiliated with, endorsed by, or sponsored by ElevenLabs.

> Forked from [saarnilauri/ai-provider-for-elevenlabs](https://github.com/saarnilauri/ai-provider-for-elevenlabs)
> by [Lauri Saarni](https://profiles.wordpress.org/laurisaarni/), used under GPL-2.0-or-later.
> Maintained here by [Jake Spurlock](https://profiles.wordpress.org/whyisjake/).

## Features

- **Text-to-Speech** -- high-quality voice synthesis with many voices and models
- **Sound Effects Generation** -- generate sound effects from text prompts
- **Voice Directory** -- list and discover available voices (including cloned voices)
- Automatic provider registration in WordPress
- Dynamic model discovery from the ElevenLabs API

## Requirements

- PHP 7.4 or higher
- [wordpress/php-ai-client](https://github.com/WordPress/php-ai-client) ^1.2 must be installed

## Installation

### As a Composer Package

```bash
composer require whyisjake/ai-provider-for-elevenlabs
```

The Composer distribution is intended for library usage and excludes `plugin.php`.

### As a WordPress Plugin

1. Download `ai-provider-for-elevenlabs.zip` from [GitHub Releases](https://github.com/whyisjake/ai-provider-for-elevenlabs/releases) (do not use GitHub "Source code" archives)
2. Upload the ZIP in WordPress admin via Plugins > Add New Plugin > Upload Plugin
3. Ensure the PHP AI Client plugin is installed and activated
4. Activate the plugin through the WordPress admin

## Configuration

Set your ElevenLabs API key via the `ELEVENLABS_API_KEY` environment variable:

```php
putenv('ELEVENLABS_API_KEY=your-api-key');
```

You can obtain an API key at [https://elevenlabs.io/app/settings/api-keys](https://elevenlabs.io/app/settings/api-keys).

## API Key Permissions

ElevenLabs API keys can be scoped with specific permissions. The minimum permissions required depend on which features you use:

| Permission | Required for | Notes |
|---|---|---|
| Text-to-speech | Text-to-speech generation | Required for TTS functionality |
| Sound generation | Sound effects generation | Required for sound effects |
| Models | Dynamic model discovery | Optional -- the plugin falls back to a hardcoded model list when this permission is missing |
| Voices | Listing available voices | Needed to browse voices via `VoiceDirectory`, and to pick a voice automatically when `outputSpeechVoice` is not set |

For full functionality, grant **Text-to-speech**, **Sound generation**, **Models**, and **Voices** permissions.

For a minimal TTS-only setup, **Text-to-speech** alone is sufficient *provided you always set `outputSpeechVoice` explicitly*. Without the **Voices** permission the provider cannot discover a default voice, and a prompt that omits `outputSpeechVoice` will fail.

You can manage API key permissions at [https://elevenlabs.io/app/settings/api-keys](https://elevenlabs.io/app/settings/api-keys).

## Usage

### With WordPress

The provider automatically registers itself with the PHP AI Client on the `init` hook. Simply ensure both plugins are active and configure your API key.

### As a Standalone Package

```php
use WordPress\AiClient\AiClient;
use AiProviderForElevenLabs\Provider\ProviderForElevenLabs;

// Register the provider
$registry = AiClient::defaultRegistry();
$registry->registerProvider(ProviderForElevenLabs::class);

// Set your API key
putenv('ELEVENLABS_API_KEY=your-api-key');
```

### Text-to-Speech Generation

```php
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;

// Simplest form -- a voice is chosen automatically from your account.
$audio = AiClient::prompt( 'Hello, this is a test of ElevenLabs text to speech.' )
    ->usingProvider( 'elevenlabs' )
    ->convertTextToSpeech();

// Save the audio file.
file_put_contents( 'output.mp3', base64_decode( $audio->toAudioFile()->getBase64Data() ) );
```

To pick the voice yourself, set `outputSpeechVoice` to a voice ID:

```php
$audio = AiClient::prompt( 'Hello, this is a test of ElevenLabs text to speech.' )
    ->usingProvider( 'elevenlabs' )
    ->usingModelConfig( ModelConfig::fromArray( [
        'outputSpeechVoice' => 'JBFqnCBsd6RMkjVDRZzb',
    ] ) )
    ->convertTextToSpeech();
```

When `outputSpeechVoice` is omitted, the provider picks a voice from your account, preferring a
premade one. The voice list is cached, so this costs one extra API call at most.

### Text-to-Speech with Custom Voice Settings

```php
$audio = AiClient::prompt( 'Welcome to WordPress.' )
    ->usingProvider( 'elevenlabs' )
    ->usingModelPreference( [ 'eleven_multilingual_v2', 'elevenlabs' ] )
    ->usingModelConfig( ModelConfig::fromArray( [
        'outputSpeechVoice' => 'JBFqnCBsd6RMkjVDRZzb',
        'customOptions'     => [
            'stability'         => 0.7,
            'similarity_boost'  => 0.8,
            'style'             => 0.2,
            'use_speaker_boost' => true,
        ],
    ] ) )
    ->convertTextToSpeech();
```

### Sound Effects Generation

```php
$audio = AiClient::prompt( 'A thunderstorm with heavy rain and distant rolling thunder' )
    ->usingProvider( 'elevenlabs' )
    ->usingModelPreference( [ 'elevenlabs-sound-generation', 'elevenlabs' ] )
    ->usingModelConfig( ModelConfig::fromArray( [
        'customOptions' => [
            'duration_seconds' => 5.0,
            'prompt_influence' => 0.3,
        ],
    ] ) )
    ->generateSpeech();

file_put_contents( 'thunder.mp3', base64_decode( $audio->toAudioFile()->getBase64Data() ) );
```

### Listing Available Voices

The plugin provides a `VoiceDirectory` for discovering available voices from the ElevenLabs
`/v2/voices` endpoint. Every page of results is fetched, and the list is cached per API key.

```php
use WordPress\AiClient\AiClient;

// Get the provider instance from the registry.
$provider = AiClient::defaultRegistry()->getProvider( 'elevenlabs' );

// Get the voice directory.
$voiceDirectory = $provider->getVoiceDirectory();

// List all available voices.
$voices = $voiceDirectory->getVoices();
foreach ( $voices as $voice ) {
    echo $voice['id'] . ': ' . $voice['name'] . ' (' . $voice['category'] . ')' . PHP_EOL;
}

// Filter by category (premade, cloned, professional).
$premadeVoices = $voiceDirectory->getVoicesByCategory( 'premade' );

// Get a specific voice by ID.
$voice = $voiceDirectory->getVoice( 'JBFqnCBsd6RMkjVDRZzb' );
if ( $voice ) {
    echo 'Voice: ' . $voice['name'] . PHP_EOL;
}
```

## Available Models

Models are dynamically discovered from the ElevenLabs `/models` API endpoint. Common models include:

| Model ID | Name | Use Case |
|---|---|---|
| `eleven_multilingual_v2` | Multilingual v2 | Best quality multilingual TTS |
| `eleven_turbo_v2_5` | Turbo v2.5 | Low-latency TTS |
| `eleven_turbo_v2` | Turbo v2 | Low-latency TTS (English) |
| `eleven_flash_v2_5` | Flash v2.5 | Fastest TTS |
| `eleven_flash_v2` | Flash v2 | Fast TTS |
| `eleven_monolingual_v1` | English v1 | Legacy English TTS |
| `eleven_multilingual_v1` | Multilingual v1 | Legacy multilingual TTS |
| `elevenlabs-sound-generation` | Sound Generation | Sound effects from text |

The sound generation model is a hardcoded entry (the `/sound-generation` endpoint does not require a model ID).

## Voice Settings Defaults

When no custom voice settings are provided, the following defaults are used:

| Setting | Default | Range |
|---|---|---|
| `stability` | 0.5 | 0.0 -- 1.0 |
| `similarity_boost` | 0.75 | 0.0 -- 1.0 |
| `style` | 0.0 | 0.0 -- 1.0 |
| `use_speaker_boost` | true | boolean |

Override any setting via `customOptions` in `ModelConfig`.

## Supported Output Formats

| Format | MIME Type |
|---|---|
| `mp3_44100_128` (default) | audio/mpeg |
| `mp3_22050_32` | audio/mpeg |
| `pcm_16000`, `pcm_22050`, `pcm_24000`, `pcm_44100` | audio/pcm |
| `ulaw_8000` | audio/basic |
| `opus_48000_32`, `opus_48000_64`, `opus_48000_128` | audio/opus |
| `aac_44100_48`, `aac_44100_64`, `aac_44100_96`, `aac_44100_128`, `aac_44100_192` | audio/aac |

Set the format via `customOptions['output_format']` or `outputMimeType` in `ModelConfig`.

## Building the Plugin ZIP

Build a distributable plugin archive locally:

```bash
make dist
# or:
./scripts/build-plugin-zip.sh
```

The ZIP is created at `dist/ai-provider-for-elevenlabs.zip` and includes `plugin.php`.

## Development

Install development dependencies:

```bash
composer install
```

Run unit tests:

```bash
composer test
# or:
composer test:unit
```

Run linting:

```bash
composer lint
```

### Integration tests against the live API

The integration suite makes real calls to ElevenLabs and needs an API key. It does
not need WordPress. Copy the template and fill in your key:

```bash
cp .env.example .env
# then edit .env and set ELEVENLABS_API_KEY
composer test:integration
```

Individual tests skip themselves when the key is absent, so the suite is safe to
run without one. `.env` is both gitignored and excluded from the release ZIP.

Generated audio is written to `tests/Integration/audio/` for listening.

### Local WordPress environment

Some behaviour only exists inside WordPress -- provider registration on `init`,
the Settings > Connectors credential flow, and transient-backed voice caching --
and no amount of PHPUnit will exercise it. Use [`wp-env`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/)
(requires Docker):

```bash
npx @wordpress/env start
```

This boots current WordPress with the plugin mounted and activated. WordPress 7.0
ships the PHP AI Client in core, so no companion plugin is required.

To make the provider's API key available inside that environment, copy the
override template -- it defines `ELEVENLABS_API_KEY` as a PHP constant, which the
plugin reads:

```bash
cp .wp-env.override.json.example .wp-env.override.json
# then edit it and set your key, and restart:
npx @wordpress/env start
```

`.wp-env.override.json` is gitignored and excluded from the release ZIP. Do not
put the key in `.wp-env.json`, which is committed.

Useful commands:

```bash
npx @wordpress/env run cli wp plugin list     # confirm the plugin is active
npx @wordpress/env logs                       # tail PHP errors
npx @wordpress/env stop
npx @wordpress/env destroy                    # tear down completely
```

## License

GPL-2.0-or-later