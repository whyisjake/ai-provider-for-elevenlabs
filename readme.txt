=== AI Provider for ElevenLabs ===
Contributors: whyisjake
Tags: ai, elevenlabs, text-to-speech, tts, sound-effects
Requires at least: 6.9
Tested up to: 7.0
Stable tag: 0.5.0
Requires PHP: 8.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Independent WordPress AI Client provider for ElevenLabs text-to-speech and sound effects generation.

== Description ==

This plugin provides a third-party ElevenLabs integration for the PHP AI Client SDK. It enables WordPress sites to use ElevenLabs models for text-to-speech conversion and sound effects generation.
It is not affiliated with, endorsed by, or sponsored by ElevenLabs.

This is a fork of [ai-provider-for-elevenlabs](https://github.com/saarnilauri/ai-provider-for-elevenlabs) by Lauri Saarni, used under GPL-2.0-or-later.

**Features:**

* Text-to-speech conversion with high-quality ElevenLabs voices
* Automatic voice selection, so a prompt works without configuring a voice ID first
* Long-form narration, splitting text beyond the model's per-request limit and returning one audio file
* Background narration for post-length text, queued as small jobs so no single request outlives its time limit
* Sound effects generation from text descriptions
* Voice directory for discovering available voices, including cloned voices, cached per API key
* Dynamic model discovery from the ElevenLabs API
* Automatic provider registration

**Available Capabilities:**

* `TEXT_TO_SPEECH_CONVERSION` -- convert text to speech using any ElevenLabs TTS model
* `SPEECH_GENERATION` -- generate sound effects from text prompts

**Requirements:**

* PHP 8.1 or higher. WordPress itself allows 7.4, but classifies it as insecure and unsupported, and 7.4 has had no security support since November 2022.
* The PHP AI Client SDK. WordPress 7.0 and later bundle it in core, so there is nothing to install. On earlier WordPress it must be provided by something else on the site, as it is a Composer package rather than a plugin.
* ElevenLabs API key

== Installation ==

1. Ensure the PHP AI Client SDK is available (bundled in WordPress 7.0 and later)
2. Upload the plugin files to `/wp-content/plugins/ai-provider-for-elevenlabs/`
3. Activate the plugin through the 'Plugins' menu in WordPress
4. Configure your ElevenLabs API key via the `ELEVENLABS_API_KEY` environment variable or constant

== Frequently Asked Questions ==

= How do I get an ElevenLabs API key? =

Visit [https://elevenlabs.io/app/settings/api-keys](https://elevenlabs.io/app/settings/api-keys) to create an account and generate an API key.

= Does this plugin work without the PHP AI Client? =

No. This plugin provides the ElevenLabs-specific implementation that the PHP AI Client uses, so the client has to be present. WordPress 7.0 and later bundle it in core, so on a current WordPress there is nothing extra to install. Note that the client is a Composer package, not a plugin, so there is nothing to install from the plugin directory on older WordPress. Without it, this plugin registers nothing and stays inert.

= How do I specify which voice to use? =

Set the `outputSpeechVoice` option in your `ModelConfig` to the voice ID. You can discover available voices using the `VoiceDirectory` class or the ElevenLabs voice library.

If you do not set a voice, one is chosen automatically from your account, preferring a premade voice. This means a plain prompt works without any voice configuration.

= The AI plugin says I need a valid AI Connector. Is this one broken? =

No. The [AI plugin](https://wordpress.org/plugins/ai) treats a connector as valid only if it can generate text, because its features (alt text, summarisation, classification, comment moderation) all need a text model. ElevenLabs generates speech, not text, so it cannot satisfy that check no matter how it is configured.

The ElevenLabs connector is still working for what it does. To clear the warning, configure a text-generation connector such as Anthropic, OpenAI, or Google alongside it.

= Can it narrate a whole post? =

Yes, but check the timing first. ElevenLabs limits characters per request, so longer text is split on paragraph and sentence boundaries, narrated across several requests, and returned as one audio file.

Narration is slow. Synthesis runs at roughly 90 to 95 characters per second, measured across four request sizes: 1,000 characters took 11 seconds, 2,000 took 22, 4,000 took 42, and 8,000 took 84. The relationship is linear, so the time is synthesis itself rather than overhead. Against PHP's default `max_execution_time` of 30 seconds, that caps a synchronous call at roughly 2,500 characters, or about 400 words.

For anything longer, use background narration. It queues the work as small jobs of about a thousand characters each, so no single request has to outlive its time limit, and adds the finished audio to the media library. See the readme on GitHub for the code.

Each chunk is a separate billed request, so a long post costs proportionally more.

Splitting also needs an output format that can be joined. MP3 and the raw PCM and u-law formats can be. Opus is carried in an Ogg container and cannot, and AAC is excluded until confirmed otherwise. Asking for one of those with over-long text fails immediately rather than returning broken audio. Short text works in every format.

= Why has my background narration not finished? =

The default runner is WP-Cron, which only fires when somebody visits the site. On a quiet site a job can sit unfinished for a long time. It is not lost, and it resumes on the next visit.

If you need narration to progress predictably, either set `DISABLE_WP_CRON` and run real system cron, or substitute a proper queue through the `ai_provider_elevenlabs_narration_queue` filter.

A job that genuinely fails records why. Read it with `( new NarrationJobs() )->progress( $job_id )`, or hook `ai_provider_elevenlabs_narration_failed`.

= Can I pass ElevenLabs parameters the AI Client does not expose? =

Yes, through `customOptions`. Parameters such as `language_code`, `seed`, `apply_text_normalization`, and `pronunciation_dictionary_locators` are sent to the API. Voice settings (`stability`, `similarity_boost`, `style`, `use_speaker_boost`, `speed`) are nested under `voice_settings` automatically.

An option that collides with something the provider sets is rejected rather than silently overriding it. `previous_text` and `next_text` are reserved, since the provider uses them to keep long-form narration continuous.

= What audio formats are supported? =

The default output format is MP3 (mp3_44100_128). Other supported formats include PCM, ulaw, Opus, and AAC at various sample rates and bitrates.

== Changelog ==

= 0.5.0 =
* Narrate post-length text in the background. Work is queued as small jobs of about a thousand characters each, so no single request has to outlive its PHP time limit, and the finished audio is added to the media library
* Add `ai_provider_elevenlabs_narration_complete` and `ai_provider_elevenlabs_narration_failed` actions, and a progress reading for a job in flight
* Size chunks from measurement rather than assumption. Synthesis runs at roughly 92 characters per second, so a 4,000-character chunk takes 42 seconds and cannot survive a default 30-second limit. The default is 1,000 characters, near eleven seconds
* Retry a chunk up to three times before failing the job. WordPress deletes a scheduled event before running it, so nothing in core re-drives work whose process died; expiring claims handle it here
* Let a site substitute its own background runner through the `ai_provider_elevenlabs_narration_queue` filter, and adjust chunk size through `ai_provider_elevenlabs_narration_chunk_size`
* Store jobs one option per job with autoload disabled, and keep chunk audio on disk rather than in the database
* Fix a fatal error in installs without Composer: `TextChunker` was never loaded, so long-form narration failed with a missing class. Added a test that fails when any class is left unregistered

= 0.4.0 =
* **Breaking:** raise the minimum PHP version to 8.1. WordPress core still allows 7.4, but reports it as insecure and unsupported and recommends 8.3, and 7.4 has had no security support since November 2022. Sites on PHP below 8.1 will not be offered this update
* Narrate text longer than the model's per-request limit. It is split on paragraph and sentence boundaries, narrated across several requests that carry neighbouring text so the seams are not abrupt, and returned as a single audio file. Previously this failed with an API error
* Note that long-form narration is slow: 16,799 characters took 37 seconds in testing, longer than PHP's default max_execution_time. See the FAQ before using it during a page request
* Refuse to split when the output format cannot be joined, such as Opus, rather than returning audio that is subtly broken. The check happens before any request is billed
* Honour custom options in both models. They were advertised but only a handful were read, so `language_code`, `seed`, `apply_text_normalization` and `pronunciation_dictionary_locators` were silently discarded
* Add `speed` as a voice setting
* Reject a custom option that collides with a parameter the provider sets, instead of letting it override `text` or `model_id`
* Read each model's character limit from the API instead of ignoring it
* Add `eleven_v3` to the offline model list, where it was missing

= 0.3.0 =
* First release of the fork maintained by Jake Spurlock, continuing from 0.2.0 by Lauri Saarni
* Move voice listing from the deprecated `/v1/voices` endpoint to `/v2/voices`. The old endpoint stops working entirely once a workspace holds more than 500 voices
* Walk every page of the voice list, so accounts with more than one page of voices are no longer truncated
* Choose a voice automatically when `outputSpeechVoice` is not set, preferring a premade voice. Previously a prompt without an explicit voice threw an exception
* Cache the voice list per API key, so repeated lookups within a request no longer re-fetch it
* Fix a voice directory that could be cached in a permanently unusable state when credentials were not yet available, causing every later voice lookup to fail
* Report the provider as "ElevenLabs" in the connector UI, and add a provider description
* Stop packaging local development files into the release ZIP. A local `.env`, which the integration suite uses to hold an ElevenLabs API key, was not excluded and would have been published inside the plugin ZIP
* Add the GPL-2.0 licence text, which was declared but not included
* Fix PSR-4 autoloading for the test suite

= 0.2.0 =
* WordPress 7.0 compatibility: read the API key from the core Connectors option (`connectors_ai_elevenlabs_api_key`, Settings > Connectors)
* WordPress 7.0 compatibility: move the xi-api-key authentication restore hook to init priority 21, after core's Connectors credential pass (init 20) which overwrites provider auth with generic Bearer authentication
* Fix API key validation on the WordPress 7.0 Connectors page: the availability check now also considers the registry request authentication and the Connectors/legacy credential options, instead of only the ELEVENLABS_API_KEY environment variable or constant

= 0.1.2 =
* Fix issue if API key not yet set in options table

= 0.1.1 =
* Fix API key registration, if the API key is not set in constant, but in the connectors page in WordPress admin
* Add a workaround to restore ElevenLabs authentication when the AI client initiation overwrites it with default auth.

= 0.1.0 =
* Initial release
* Text-to-speech conversion with ElevenLabs TTS models
* Sound effects generation from text prompts
* Voice directory for listing and discovering available voices
* Dynamic model discovery from the ElevenLabs API
* Custom voice settings support (stability, similarity_boost, style, use_speaker_boost)
* Multiple output format support (MP3, PCM, Opus, AAC, ulaw)

== Upgrade Notice ==

= 0.1.0 =
Initial release.