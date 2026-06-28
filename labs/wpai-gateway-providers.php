<?php
/**
 * Per-id provider adapters. Each one is an AI Engine-backed class registered
 * in AiClient under a specific provider id (anthropic, google, openai, …) so
 * that `wp_ai_client_prompt()->using_provider('anthropic')` — wherever it
 * appears in the WP AI framework — transparently routes through AI Engine.
 *
 * All subclasses share `Meow_MWAI_Labs_WPAI_Gateway_Model` for dispatch. They
 * only differ in metadata (id, name, engine type) and are scoped to their
 * matching engine type for the directory and availability check.
 */

use WordPress\AiClient\Providers\AbstractProvider;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;

abstract class Meow_MWAI_Labs_WPAI_Gateway_Provider_Base extends AbstractProvider {

  /** Provider id, e.g. 'anthropic'. Must match the AI Engine engine type. */
  abstract protected static function provider_id(): string;

  /** Human-readable name, e.g. 'Anthropic'. */
  abstract protected static function provider_name(): string;

  /** Anchor URL for "where to get a key" (nullable). */
  protected static function credentials_url(): ?string { return null; }

  protected static function createModel(
    ModelMetadata $modelMetadata,
    ProviderMetadata $providerMetadata
  ): ModelInterface {
    foreach ( $modelMetadata->getSupportedCapabilities() as $cap ) {
      if ( $cap->isImageGeneration() ) {
        return new Meow_MWAI_Labs_WPAI_Gateway_ImageModel( $modelMetadata, $providerMetadata );
      }
    }
    return new Meow_MWAI_Labs_WPAI_Gateway_Model( $modelMetadata, $providerMetadata );
  }

  protected static function createProviderMetadata(): ProviderMetadata {
    return new ProviderMetadata(
      static::provider_id(),
      static::provider_name(),
      ProviderTypeEnum::cloud(),
      static::credentials_url(),
      RequestAuthenticationMethod::apiKey(),
      /* translators: %s: provider name. */
      sprintf( __( '%s via AI Engine. Keys and dispatch are handled by the AI Engine plugin.', 'ai-engine' ), static::provider_name() )
    );
  }

  protected static function createProviderAvailability(): ProviderAvailabilityInterface {
    return new Meow_MWAI_Labs_WPAI_Gateway_Availability( static::provider_id() );
  }

  protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface {
    return new Meow_MWAI_Labs_WPAI_Gateway_Directory( static::provider_id() );
  }
}

class Meow_MWAI_Labs_WPAI_Gateway_Provider_Anthropic extends Meow_MWAI_Labs_WPAI_Gateway_Provider_Base {
  protected static function provider_id(): string   { return 'anthropic'; }
  protected static function provider_name(): string { return 'Anthropic'; }
  protected static function credentials_url(): ?string { return 'https://console.anthropic.com/settings/keys'; }
}

class Meow_MWAI_Labs_WPAI_Gateway_Provider_OpenAI extends Meow_MWAI_Labs_WPAI_Gateway_Provider_Base {
  protected static function provider_id(): string   { return 'openai'; }
  protected static function provider_name(): string { return 'OpenAI'; }
  protected static function credentials_url(): ?string { return 'https://platform.openai.com/api-keys'; }
}

class Meow_MWAI_Labs_WPAI_Gateway_Provider_Google extends Meow_MWAI_Labs_WPAI_Gateway_Provider_Base {
  protected static function provider_id(): string   { return 'google'; }
  protected static function provider_name(): string { return 'Google'; }
  protected static function credentials_url(): ?string { return 'https://aistudio.google.com/api-keys'; }
}

class Meow_MWAI_Labs_WPAI_Gateway_Provider_Mistral extends Meow_MWAI_Labs_WPAI_Gateway_Provider_Base {
  protected static function provider_id(): string   { return 'mistral'; }
  protected static function provider_name(): string { return 'Mistral'; }
  protected static function credentials_url(): ?string { return 'https://console.mistral.ai/api-keys/'; }
}

class Meow_MWAI_Labs_WPAI_Gateway_Provider_OpenRouter extends Meow_MWAI_Labs_WPAI_Gateway_Provider_Base {
  protected static function provider_id(): string   { return 'openrouter'; }
  protected static function provider_name(): string { return 'OpenRouter'; }
  protected static function credentials_url(): ?string { return 'https://openrouter.ai/keys'; }
}

class Meow_MWAI_Labs_WPAI_Gateway_Provider_Perplexity extends Meow_MWAI_Labs_WPAI_Gateway_Provider_Base {
  protected static function provider_id(): string   { return 'perplexity'; }
  protected static function provider_name(): string { return 'Perplexity'; }
  protected static function credentials_url(): ?string { return 'https://www.perplexity.ai/settings/api'; }
}

class Meow_MWAI_Labs_WPAI_Gateway_Provider_Replicate extends Meow_MWAI_Labs_WPAI_Gateway_Provider_Base {
  protected static function provider_id(): string   { return 'replicate'; }
  protected static function provider_name(): string { return 'Replicate'; }
  protected static function credentials_url(): ?string { return 'https://replicate.com/account/api-tokens'; }
}

class Meow_MWAI_Labs_WPAI_Gateway_Provider_Ollama extends Meow_MWAI_Labs_WPAI_Gateway_Provider_Base {
  protected static function provider_id(): string   { return 'ollama'; }
  protected static function provider_name(): string { return 'Ollama'; }
}

class Meow_MWAI_Labs_WPAI_Gateway_Provider_Azure extends Meow_MWAI_Labs_WPAI_Gateway_Provider_Base {
  protected static function provider_id(): string   { return 'azure'; }
  protected static function provider_name(): string { return 'Azure OpenAI'; }
}
