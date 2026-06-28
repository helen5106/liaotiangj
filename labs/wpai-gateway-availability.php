<?php
/**
 * Availability check. Scoped: configured iff AI Engine has an env of the
 * given type with a key (or no key needed, like Ollama). Unscoped: any env.
 */

use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;

class Meow_MWAI_Labs_WPAI_Gateway_Availability implements ProviderAvailabilityInterface {

  private ?string $engine_type;

  public function __construct( ?string $engine_type = null ) {
    $this->engine_type = $engine_type;
  }

  public function isConfigured(): bool {
    $core = Meow_MWAI_Labs_WPAI_Gateway::$core;
    if ( ! $core ) {
      return false;
    }
    $options = $core->get_all_options();
    if ( empty( $options['ai_envs'] ) ) {
      return false;
    }
    foreach ( $options['ai_envs'] as $env ) {
      $type = $env['type'] ?? '';
      if ( $this->engine_type !== null && $type !== $this->engine_type ) {
        continue;
      }
      $key = $env['apikey'] ?? '';
      if ( $type === 'ollama' || ! empty( $key ) ) {
        return true;
      }
    }
    return false;
  }
}
