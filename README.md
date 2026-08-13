# AI Provider for ElevenLabs

A third-party provider for [ElevenLabs](https://elevenlabs.io/) in the [PHP AI Client](https://github.com/WordPress/php-ai-client) SDK. Works as both a Composer package and a WordPress plugin.

This project is independent and is not affiliated with, endorsed by, or sponsored by ElevenLabs.
`assets/images/elevenlabs.svg` is the official ElevenLabs symbol from their
[brand kit](https://elevenlabs.io/brand), used unmodified to identify the provider.
The ElevenLabs name and logo are trademarks of ElevenLabs.

> Forked from [saarnilauri/ai-provider-for-elevenlabs](https://github.com/saarnilauri/ai-provider-for-elevenlabs)
> by [Lauri Saarni](https://profiles.wordpress.org/laurisaarni/), used under GPL-2.0-or-later.
> Maintained here by [Jake Spurlock](https://profiles.wordpress.org/whyisjake/).
> See [Differences from upstream](#differences-from-upstream).

## Features

- **Text-to-Speech** -- high-quality voice synthesis with many voices and models
- **Automatic voice selection** -- a prompt works without configuring a voice ID first
- **Long-form narration** -- text beyond the model's per-request limit is narrated across
  several requests and returned as one audio file ([caveats](#long-form-narration))
- **Background narration** -- post-length text narrated as a queue of small jobs, so no
  single request has to outlive its time limit ([details](#background-narration))
- **Sound Effects Generation** -- generate sound effects from text prompts
- **Voice Directory** -- list and discover available voices, including cloned voices, cached per API key
- Automatic provider registration in WordPress
- Dynamic model discovery from the ElevenLabs API

## Differences from upstream

This fork starts from [upstream](https://github.com/saarnilauri/ai-provider-for-elevenlabs) 0.2.0.
The changes are fixes and verification rather than new capability -- the feature set is the same
one Lauri built.

Behaviour you would notice:

| | Upstream 0.2.0 | Here |
|---|---|---|
| Prompt with no `outputSpeechVoice` | Throws | Picks a voice from your account, preferring premade |
| Voice listing endpoint | `/v1/voices`, deprecated and capped at 500 voices | `/v2/voices`, every page fetched |
| Repeated voice lookups | One HTTP request each | Memoised per request, cached per API key |
| Voice directory built before credentials arrive | Stays broken for the rest of the request | Picks credentials up when they arrive |
| Provider shown in the connector UI as | "AI Provider for ElevenLabs", no description or logo | "ElevenLabs", with description and logo |
| A local `.env` when building the ZIP | Packaged into the release archive | Excluded, and CI fails if that regresses |

Project changes:

- Continuous integration: unit tests on PHP 8.1 through 8.5, phpcs, PHPStan at `max`, and a
  packaging job that plants canary secrets and fails if any reach the release ZIP
- Unit tests 46 to 59, integration tests 10 to 12, covering pagination, voice resolution, and the
  credentials-arrive-late regression
- `wp-env` environment, plus `.env` and override templates, for the WordPress-only paths that
  PHPUnit cannot reach
- PSR-4 autoloading fixed for the test suite, which previously skipped every test class
- The GPL-2.0 licence text, which was declared but not included

Most of this would be just as useful upstream, and none of it is a deliberate divergence.

## Requirements

- PHP 8.1 or higher. WordPress core's own floor is 7.4, but it reports 7.4 as
  insecure and unsupported and recommends 8.3, and 7.4 has had no security
  support since November 2022.
- The [PHP AI Client](https://github.com/WordPress/php-ai-client) SDK, ^1.2, must be loadable:
    - **WordPress 7.0 and later** bundle it in core (`wp-includes/php-ai-client/`). Nothing to install.
    - **Earlier WordPress** does not. The SDK is a Composer package, not a plugin -- there is
      nothing to install from the plugin directory -- so it has to be provided by something
      else on the site that requires `wordpress/php-ai-client`.

If the SDK is not available, this plugin registers nothing and stays inert.

## Installation

### As a Composer Package

```bash
composer require whyisjake/ai-provider-for-elevenlabs
```

The Composer distribution is intended for library usage and excludes `plugin.php`.

### As a WordPress Plugin

1. Download `ai-provider-for-elevenlabs.zip` from [GitHub Releases](https://github.com/whyisjake/ai-provider-for-elevenlabs/releases) (do not use GitHub "Source code" archives)
2. Upload the ZIP in WordPress admin via Plugins > Add New Plugin > Upload Plugin
3. Ensure the PHP AI Client SDK is available (bundled in WordPress 7.0+; see Requirements)
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

### Long-form narration

ElevenLabs caps the characters accepted in one request, and the cap depends on the
model:

| Model | Characters per request |
|---|---|
| `eleven_v3` | 5,000 |
| `eleven_multilingual_v2` (default) | 10,000 |
| `eleven_turbo_v2`, `eleven_flash_v2` | 30,000 |
| `eleven_turbo_v2_5`, `eleven_flash_v2_5` | 40,000 |

Longer text is split on paragraph and sentence boundaries, narrated in several
requests that carry their neighbouring text so prosody survives the seams, and
returned as a single audio file. Nothing changes for text that already fits: it
still makes exactly one request.

Two constraints are worth knowing before relying on this.

**It is slow, and will exceed your PHP time limit.** Narration is not instant, and
a long post is several sequential API calls inside one request. Synthesis runs at
roughly **90 to 95 characters per second**, measured across four request sizes:

| Characters | Seconds |
|---|---|
| 1,000 | 11.0 |
| 2,000 | 21.7 |
| 4,000 | 42.4 |
| 8,000 | 83.8 |

The relationship is linear, so there is no per-request overhead worth optimising
away — the time is the synthesis itself. Against a PHP default
`max_execution_time` of 30 seconds, that puts the ceiling for a synchronous call
at roughly 2,500 characters, or about 400 words.

An earlier run recorded a faster rate (16,799 characters in 37 seconds), so treat
these numbers as varying with API load rather than fixed. Planning for the slower
figure is the safer choice, and the variance is itself an argument for not
betting a page request on it.

For anything longer than a few hundred words, use
[background narration](#background-narration). Raising `max_execution_time` and
`WP_MEMORY_LIMIT` also works, but only on hosts where you control both. The audio
is held in memory before being returned, so memory scales with output length.

**It costs one request per chunk.** A long post is charged accordingly.

Chunking also requires an output format whose audio can be joined. MP3 and the raw
PCM and µ-law formats can be; Opus is carried in an Ogg container and cannot, and
AAC is excluded until confirmed to be ADTS-framed. Requesting an unjoinable format
for over-long text fails immediately, before any request is billed, rather than
returning audio that is subtly broken. Short text is unaffected in every format.

### Background narration

Narrating a full-length post cannot finish inside one PHP request, so it runs as
a queue of small jobs instead. Each job narrates about a thousand characters —
around eleven seconds — and schedules the next, so no single request has to
survive longer than a page load normally would.

```php
use AiProviderForElevenLabs\Jobs\NarrationJobs;

$jobs  = new NarrationJobs();
$jobId = $jobs->enqueue( $post_content, [
    'voice' => 'JBFqnCBsd6RMkjVDRZzb', // optional; resolved from your account otherwise
] );

// Later, from anywhere:
$progress = $jobs->progress( $jobId );
// [ 'status' => 'running', 'completed' => 3, 'total' => 9, 'error' => null, 'attachment_id' => null ]
```

The finished audio is added to the media library, and the job record carries the
attachment id. Two actions fire:

```php
add_action( 'ai_provider_elevenlabs_narration_complete', function ( $job_id, $attachment_id ) {
    update_post_meta( $post_id, 'narration', $attachment_id );
}, 10, 2 );

add_action( 'ai_provider_elevenlabs_narration_failed', function ( $job_id, $reason ) {
    error_log( "Narration {$job_id} failed: {$reason}" );
}, 10, 2 );
```

`$jobs->cancel( $jobId )` stops a job and removes its partial audio.

**What WP-Cron does and does not guarantee.** The default runner is WP-Cron,
which is available everywhere and needs no dependency. Its behaviour is worth
knowing before you rely on it:

- **It fires on traffic.** Unless your host sets `DISABLE_WP_CRON` and runs real
  system cron, a queue on a quiet site only advances when somebody visits. A job
  is not lost, but it can sit unfinished for a long time.
- **It does not retry.** WordPress deletes a scheduled event before running it,
  so nothing in core will re-drive work whose process died. This plugin handles
  that itself with expiring claims, retrying a chunk up to three times before
  failing the job.
- **It does not extend the time limit.** A cron callback is an ordinary PHP
  request. That is why the unit of work is one chunk rather than one post.

Sites that run background work properly can substitute their own runner:

```php
add_filter( 'ai_provider_elevenlabs_narration_queue', function ( $queue ) {
    return new My_Action_Scheduler_Queue(); // implements NarrationQueue
} );
```

Chunk size is filterable, though the default is measured rather than guessed and
larger values move back towards the timeout:

```php
add_filter( 'ai_provider_elevenlabs_narration_chunk_size', fn() => 800 );
```

Jobs are stored one option per job with autoload disabled, and chunk audio is
written under the uploads directory, never into the database. The job directory
is removed once the attachment exists.

### Provider-specific options

The provider supports `customOptions`, which pass through to the ElevenLabs API.
This covers parameters the AI Client has no dedicated option for:

```php
$audio = AiClient::prompt( 'Bonjour tout le monde.' )
    ->usingProvider( 'elevenlabs' )
    ->usingModelConfig( ModelConfig::fromArray( [
        'customOptions' => [
            'language_code'            => 'fr',   // force a language
            'speed'                    => 1.1,    // a voice setting
            'seed'                     => 42,     // deterministic output
            'apply_text_normalization' => 'on',
        ],
    ] ) )
    ->convertTextToSpeech();
```

Voice settings (`stability`, `similarity_boost`, `style`, `use_speaker_boost`,
`speed`) are nested under `voice_settings` automatically; everything else is sent
at the top level. An option that collides with a parameter the provider sets --
`text`, `model_id`, `voice_settings` -- is rejected rather than silently
overriding it. `previous_text` and `next_text` are reserved, because the provider
sets them when narrating long text.

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

This boots current WordPress with the plugin mounted and activated, at
<http://localhost:8890> (admin/password).

Two AI dependencies are involved, and they arrive differently:

- **The PHP AI Client SDK** is what this provider plugs into. It is a Composer
  package with no `Plugin Name:` header, so it cannot be installed as a plugin;
  it ships inside core at `wp-includes/php-ai-client/`. That is why `"core"` is
  the dependency here rather than anything in `"plugins"`.
- **The [AI plugin](https://wordpress.org/plugins/ai)** is the WordPress.org
  reference implementation built on top of that SDK -- Connectors approvals, an
  abilities explorer, AI request logging, and editor features. It *is* a real
  plugin, and `.wp-env.json` installs it, because it is the thing that actually
  exercises a registered provider end to end.

Confirm what an environment has:

```bash
npx @wordpress/env run cli wp plugin list
npx @wordpress/env run cli wp eval 'echo WordPress\AiClient\AiClient::VERSION;'
```

With only ElevenLabs configured, the AI plugin warns that it needs a valid AI
Connector. That is expected, not a fault in this provider: the AI plugin treats
a connector as valid only when it can generate text, and ElevenLabs generates
speech. Both facts are observable:

```bash
npx @wordpress/env run cli wp eval '
$p = wp_ai_client_prompt("Test");
var_dump($p->is_supported_for_text_generation());            // false
var_dump($p->is_supported_for_text_to_speech_conversion());  // true
'
```

Add a text-generation connector alongside it to exercise the AI plugin's own
features.

The ports are pinned to 8890/8891 rather than wp-env's 8888/8889 defaults, so
this environment can run alongside other wp-env projects without colliding.
`testsPort` is deprecated upstream and prints a warning, but it is kept
deliberately: without it the test site falls back to 8889, and wp-env reports
that collision while still exiting 0, so the failure is easy to miss.

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