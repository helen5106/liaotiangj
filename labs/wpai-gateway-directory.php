<?php
/**
 * Exposes AI Engine's models to the WP AI framework.
 *
 * The directory can be scoped to a single engine type (e.g. only the
 * OpenAI-engine models), or list every model AI Engine knows when no scope
 * is given. Source of truth is `options.ai_engines[].models`.
 */

use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;

class Meow_MWAI_Labs_WPAI_Gateway_Directory implements ModelMetadataDirectoryInterface {

  private ?string $engine_type;

  public function __construct( ?string $engine_type = null ) {
    $this->engine_type = $engine_type;
  }

  public function listModelMetadata(): array {
    $models = [];
    foreach ( $this->iterate_models() as $id => $model ) {
      $models[] = $this->make_metadata( $id, $model );
    }
    return $models;
  }

  public function hasModelMetadata( string $modelId ): bool {
    foreach ( $this->iterate_models() as $id => $_m ) {
      if ( $id === $modelId ) {
        return true;
      }
    }
    return false;
  }

  public function getModelMetadata( string $modelId ): ModelMetadata {
    foreach ( $this->iterate_models() as $id => $model ) {
      if ( $id === $modelId ) {
        return $this->make_metadata( $id, $model );
      }
    }
    throw new \WordPress\AiClient\Common\Exception\InvalidArgumentException(
      sprintf( 'Unknown AI Engine model: %s', $modelId )
    );
  }

  private function iterate_models(): iterable {
    $core = Meow_MWAI_Labs_WPAI_Gateway::$core;
    if ( ! $core ) {
      return;
    }
    $options = $core->get_all_options();
    $engines = $options['ai_engines'] ?? [];
    foreach ( $engines as $engine ) {
      $engine_type = $engine['type'] ?? '';
      if ( $this->engine_type !== null && $engine_type !== $this->engine_type ) {
        continue;
      }
      if ( empty( $engine['models'] ) || ! is_array( $engine['models'] ) ) {
        continue;
      }
      foreach ( $engine['models'] as $m ) {
        if ( empty( $m['model'] ) ) {
          continue;
        }
        $id = $m['model'];
        $m['_engine_type'] = $engine_type;
        yield $id => $m;
      }
    }
  }

  private function make_metadata( string $id, array $model ): ModelMetadata {
    // Phase 3 TODO — gate `webSearch` on a future 'web_search' tag. Low
    // priority; native plugins do this by model-id regex today.

    $capabilities  = [];
    $tags          = $model['tags'] ?? [];
    $is_image      = in_array( 'image', $tags, true ) || in_array( 'image-generation', $tags, true );
    $is_chat       = in_array( 'chat', $tags, true ) || in_array( 'completion', $tags, true );
    $has_functions = in_array( 'functions', $tags, true );
    $has_json      = in_array( 'json', $tags, true );
    $has_vision    = in_array( 'vision', $tags, true );
    $has_files     = in_array( 'files', $tags, true );
    $has_audio     = in_array( 'audio', $tags, true );

    if ( $is_image ) {
      $capabilities[] = CapabilityEnum::imageGeneration();
    }
    if ( $is_chat ) {
      $capabilities[] = CapabilityEnum::textGeneration();
      $capabilities[] = CapabilityEnum::chatHistory();
    }
    if ( empty( $capabilities ) ) {
      // Unknown tag shape: assume text generation as the safest default.
      $capabilities[] = CapabilityEnum::textGeneration();
      $capabilities[] = CapabilityEnum::chatHistory();
    }

    // Option support. Base options every chat model accepts; plus a tag-gated
    // set for features that engines actually enforce at dispatch time
    // (classes/engines/*.php). If we declared these without the tag, the
    // WP AI Client would think we support them and the call would fail deep
    // in the engine with an opaque message.
    $option_methods = [
      'outputModalities', 'outputMimeType', 'outputSchema',
      'systemInstruction', 'maxTokens', 'temperature', 'topP', 'topK',
      'candidateCount', 'stopSequences', 'frequencyPenalty', 'presencePenalty',
      'logprobs', 'topLogprobs', 'webSearch',
      'outputFileType', 'outputMediaAspectRatio', 'outputMediaOrientation',
      'outputSpeechVoice', 'customOptions',
    ];
    // AI Engine's 'json' tag is narrow (OpenAI only today), but every chat
    // engine handles structured output — via native JSON mode, tool use, or
    // "reply in JSON" prompting. Declaring outputSchema permissively matches
    // reality; gating by tag would falsely advertise Claude/Google as
    // incapable of JSON responses.
    //
    // functionDeclarations IS tag-gated because dispatch-time checks in
    // classes/engines/*.php actively skip tool payloads for non-`functions`
    // models; declaring here would claim support the engine refuses.
    if ( $has_functions ) {
      $option_methods[] = 'functionDeclarations';
    }
    unset( $has_json ); // reserved for future finer-grained JSON-mode gating
    $supportedOptions = [];
    foreach ( $option_methods as $m ) {
      // OptionEnum uses __callStatic, so method_exists is blind. Try/catch
      // lets options not in this WP build degrade quietly.
      try {
        $supportedOptions[] = new SupportedOption( OptionEnum::$m() );
      }
      catch ( \Throwable $e ) {
        // Skip options not present in this WP build.
      }
    }

    // inputModalities is declared with the exact combinations we'll accept.
    // Anthropic's reference adapter does the same — see
    // AnthropicModelMetadataDirectory.php:95-104. Declaring `null` (any)
    // would silently let image-only prompts reach a text-only model.
    $input_combos = [ [ ModalityEnum::text() ] ];
    if ( $has_vision ) {
      $input_combos[] = [ ModalityEnum::text(), ModalityEnum::image() ];
    }
    if ( $has_files ) {
      $input_combos[] = [ ModalityEnum::text(), ModalityEnum::document() ];
      if ( $has_vision ) {
        $input_combos[] = [ ModalityEnum::text(), ModalityEnum::image(), ModalityEnum::document() ];
      }
    }
    if ( $has_audio ) {
      $input_combos[] = [ ModalityEnum::text(), ModalityEnum::audio() ];
    }
    $supportedOptions[] = new SupportedOption( OptionEnum::inputModalities(), $input_combos );

    $name = $model['rawName'] ?? $model['name'] ?? $id;
    return new ModelMetadata( $id, is_string( $name ) ? $name : $id, array_values( array_unique( $capabilities, SORT_REGULAR ) ), $supportedOptions );
  }
}
