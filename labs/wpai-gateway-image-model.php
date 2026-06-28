<?php
/**
 * ImageGenerationModel for the AI Engine gateway.
 *
 * Routes AiClient image prompts through `Meow_MWAI_Query_Image` and wraps the
 * resulting image URLs as `File` parts on a `ModelMessage` inside a
 * `GenerativeAiResult`.
 */

use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\ImageGeneration\Contracts\ImageGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;

class Meow_MWAI_Labs_WPAI_Gateway_ImageModel implements ModelInterface, ImageGenerationModelInterface {

  private ModelMetadata $modelMetadata;
  private ProviderMetadata $providerMetadata;
  private ModelConfig $config;

  public function __construct( ModelMetadata $modelMetadata, ProviderMetadata $providerMetadata ) {
    $this->modelMetadata    = $modelMetadata;
    $this->providerMetadata = $providerMetadata;
    $this->config           = new ModelConfig();
  }

  public function metadata(): ModelMetadata         { return $this->modelMetadata; }
  public function providerMetadata(): ProviderMetadata { return $this->providerMetadata; }
  public function setConfig( ModelConfig $config ): void { $this->config = $config; }
  public function getConfig(): ModelConfig          { return $this->config; }

  public function generateImageResult( array $prompt ): GenerativeAiResult {
    $core = Meow_MWAI_Labs_WPAI_Gateway::$core;
    if ( ! $core ) {
      throw new RuntimeException( 'AI Engine core unavailable.' );
    }

    $model_id = $this->modelMetadata->getId();
    $env      = $this->resolve_env_for_model( $core, $model_id );
    if ( ! $env ) {
      throw new RuntimeException( sprintf(
        'No AI Engine environment is configured for model "%s".',
        $model_id
      ) );
    }

    // Collapse all text parts across the prompt into a single image prompt.
    $text = '';
    foreach ( $prompt as $msg ) {
      if ( ! $msg instanceof Message ) { continue; }
      foreach ( $msg->getParts() as $part ) {
        if ( $part instanceof MessagePart && $part->getType()->isText() ) {
          $text .= (string) $part->getText();
        }
      }
    }
    if ( $text === '' ) {
      throw new RuntimeException( 'Empty image prompt.' );
    }

    $query = new Meow_MWAI_Query_Image( $text );
    $query->set_scope( 'wp-ai-client' );
    $query->set_env_id( $env['id'] );
    $query->set_model( $model_id );

    $reply = $core->run_query( $query );

    // AI Engine places generated images in $reply->results as a list of URLs.
    // Fall back to $reply->result if results is empty.
    $urls = [];
    if ( ! empty( $reply->results ) && is_array( $reply->results ) ) {
      foreach ( $reply->results as $u ) {
        if ( is_string( $u ) && $u !== '' ) {
          $urls[] = $u;
        }
      }
    }
    if ( empty( $urls ) && ! empty( $reply->result ) ) {
      $urls[] = (string) $reply->result;
    }
    if ( empty( $urls ) ) {
      throw new RuntimeException( 'Image generation returned no URLs.' );
    }

    $candidates = [];
    foreach ( $urls as $url ) {
      $file = new File( $url );
      $part = new MessagePart( $file );
      $message = new ModelMessage( [ $part ] );
      $candidates[] = new Candidate( $message, FinishReasonEnum::stop() );
    }

    $tokens = new TokenUsage( 0, 0, 0 );
    $id     = (string) ( $reply->id ?? uniqid( 'mwai-img-', true ) );
    return new GenerativeAiResult(
      $id,
      $candidates,
      $tokens,
      $this->providerMetadata,
      $this->modelMetadata
    );
  }

  private function resolve_env_for_model( $core, string $model_id ): ?array {
    $options = $core->get_all_options();
    $engines = $options['ai_engines'] ?? [];
    $engine_type_for_model = null;
    foreach ( $engines as $engine ) {
      foreach ( $engine['models'] ?? [] as $m ) {
        if ( ( $m['model'] ?? '' ) === $model_id ) {
          $engine_type_for_model = $engine['type'] ?? null;
          break 2;
        }
      }
    }
    if ( ! $engine_type_for_model ) {
      return null;
    }
    foreach ( $options['ai_envs'] ?? [] as $env ) {
      if ( ( $env['type'] ?? '' ) === $engine_type_for_model ) {
        return $env;
      }
    }
    return null;
  }
}
