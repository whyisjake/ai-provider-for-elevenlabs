<?php

declare(strict_types=1);

namespace AiProviderForElevenLabs\Provider;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;

/**
 * Class to check availability for the ElevenLabs provider.
 *
 * Checks whether an API key is available from any supported source:
 * the ELEVENLABS_API_KEY environment variable or constant, the request
 * authentication set on the AI client registry (covers the WordPress 7.0+
 * Connectors flow, which injects the key being validated before calling
 * this method), or the Connectors / legacy credential options.
 *
 * This avoids calling the /models endpoint (which requires the
 * models_read permission) just to verify configuration.
 *
 * @since 0.1.0
 */
class ElevenLabsProviderAvailability implements ProviderAvailabilityInterface
{
    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    public function isConfigured(): bool
    {
        $apiKey = getenv('ELEVENLABS_API_KEY');
        if ($apiKey !== false && $apiKey !== '') {
            return true;
        }

        if (defined('ELEVENLABS_API_KEY')) {
            $value = constant('ELEVENLABS_API_KEY');
            if (is_string($value) && $value !== '') {
                return true;
            }
        }

        try {
            $registry = AiClient::defaultRegistry();
            if (
                $registry->hasProvider(ProviderForElevenLabs::class)
                && $registry->getProviderRequestAuthentication(ProviderForElevenLabs::class) !== null
            ) {
                return true;
            }
        } catch (\Exception $e) {
            // Registry not available; fall through to the option checks.
        }

        if (function_exists('get_option')) {
            // WordPress 7.0+ core Connectors option.
            $connectorsKey = get_option('connectors_ai_elevenlabs_api_key', '');
            if (is_string($connectorsKey) && $connectorsKey !== '') {
                return true;
            }

            // Legacy wp-ai-client credentials option (pre-7.0).
            $option = get_option('wp_ai_client_provider_credentials');
            if (is_array($option) && isset($option['elevenlabs']) && $option['elevenlabs'] !== '') {
                return true;
            }
        }

        return false;
    }
}
