<?php
/**
 * TextGenerationModel for the AI Engine gateway.
 *
 * Translates AiClient's `array<Message> $prompt` into `Meow_MWAI_Query_Text`,
 * dispatches it through AI Engine's engine for the matching provider, and
 * wraps the reply as a `GenerativeAiResult`.
 */

use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;

class Meow_MWAI_Labs_WPAI_Gateway_Model implements ModelInterface, TextGenerationModelInterface {

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

  public function generateTextResult( array $prompt ): GenerativeAiResult {
    // KNOWN LIMITATIONS (to address as we harden this gateway):
    //
    //  1. Phase 4 TODO — validate model-specific constraints before
    //     dispatching. E.g. cap `maxTokens` to the model's real ceiling
    //     (AI Engine stores it on each model), reject `temperature` on
    //     models that forbid it (o1 family), give a clear error up-front
    //     instead of a downstream API rejection.
    //  2. File parts (image, document, audio) are now round-tripped via
    //     `extract_parts` → `Meow_MWAI_Query_DroppedFile` → `$query->add_file`.
    //     Function-call / function-response parts on inbound messages are
    //     still ignored on purpose: AI Engine handles tools through its own
    //     filter pipeline (`mwai_functions_list`, `mwai_ai_feedback`).
    //  3. Streaming is not exposed. AiClient has no streaming hook today,
    //     but if it ever does, we need to bridge to `run_query( $q, $cb )`.
    //  4. Errors from `run_query()` surface as thrown RuntimeException;
    //     partial failures (e.g. a function-call tool erroring mid-loop)
    //     aren't translated into the `GenerativeAiResult` error shape.
    //
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

    // Translate AiClient messages → AI Engine role/content pairs, and collect
    // file parts (images, documents, audio) for attachment on the query.
    $messages = [];
    $files    = [];
    foreach ( $prompt as $msg ) {
      if ( ! $msg instanceof Message ) {
        continue;
      }
      $role = $msg->getRole()->isModel() ? 'assistant' : 'user';
      list( $text, $msg_files ) = $this->extract_parts( $msg );
      foreach ( $msg_files as $f ) {
        $files[] = $f;
      }
      if ( $text === '' && empty( $msg_files ) ) {
        continue;
      }
      // AI Engine expects a non-empty content per message; if the message
      // carried only files, leave a placeholder so the message slot exists.
      if ( $text === '' ) {
        $text = '[attachment]';
      }
      $messages[] = [ 'role' => $role, 'content' => $text ];
    }

    // Split off the last user turn as the main "message"; everything before
    // is history.
    $last_user_idx = null;
    for ( $i = count( $messages ) - 1; $i >= 0; $i-- ) {
      if ( $messages[ $i ]['role'] === 'user' ) {
        $last_user_idx = $i;
        break;
      }
    }
    $current = $last_user_idx !== null ? $messages[ $last_user_idx ]['content'] : '';
    $history = $last_user_idx !== null
      ? array_merge( array_slice( $messages, 0, $last_user_idx ), array_slice( $messages, $last_user_idx + 1 ) )
      : $messages;

    $query = new Meow_MWAI_Query_Text( $current );
    // Stamp the call with the framework name so it's identifiable in Insights.
    $query->set_scope( 'wp-ai-client' );
    $query->set_env_id( $env['id'] );
    $query->set_model( $model_id );
    if ( ! empty( $history ) ) {
      $query->set_messages( $history );
    }
    foreach ( $files as $dropped ) {
      $query->add_file( $dropped );
    }
    $system = $this->config->getSystemInstruction();
    if ( $system ) {
      $query->set_instructions( $system );
    }
    $maxTokens = $this->config->getMaxTokens();
    if ( $maxTokens !== null ) {
      $query->set_max_tokens( (int) $maxTokens );
    }
    $temperature = $this->config->getTemperature();
    if ( $temperature !== null ) {
      $query->set_temperature( (float) $temperature );
    }

    $reply = $core->run_query( $query );

    return $this->build_result( $reply );
  }

  // ───────────────────────────────────────────────────────────────────────

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

  /**
   * Pulls text and file attachments out of an AiClient Message.
   * Function-call / function-response parts are intentionally ignored —
   * AI Engine handles tool-calling through its own filter pipeline
   * (`mwai_functions_list`, `mwai_ai_feedback`), not via inbound messages.
   *
   * @return array{0: string, 1: array<int, Meow_MWAI_Query_DroppedFile>}
   */
  private function extract_parts( Message $msg ): array {
    $text  = '';
    $files = [];
    foreach ( $msg->getParts() as $part ) {
      if ( ! $part instanceof MessagePart ) {
        continue;
      }
      $type = $part->getType();
      if ( $type->isText() ) {
        $text .= (string) $part->getText();
      }
      elseif ( $type->isFile() ) {
        $file = $part->getFile();
        if ( $file === null ) {
          continue;
        }
        $dropped = $this->file_to_dropped( $file );
        if ( $dropped !== null ) {
          $files[] = $dropped;
        }
      }
    }
    return [ $text, $files ];
  }

  /**
   * Converts an AiClient `File` (URL, base64, or data URI) into AI Engine's
   * `Meow_MWAI_Query_DroppedFile` so the underlying engine can attach it to
   * the request (vision payload, document upload, etc.).
   */
  private function file_to_dropped( $file ): ?Meow_MWAI_Query_DroppedFile {
    try {
      $mime = $file->getMimeType();
      $url  = $file->getUrl();
      if ( $url ) {
        return Meow_MWAI_Query_DroppedFile::from_url( $url, 'analysis', $mime );
      }
      $b64 = $file->getBase64Data();
      if ( $b64 ) {
        return Meow_MWAI_Query_DroppedFile::from_data( base64_decode( $b64 ), 'analysis', $mime );
      }
    }
    catch ( \Throwable $e ) {
      // Skip the file rather than failing the whole prompt.
    }
    return null;
  }

  private function build_result( $reply ): GenerativeAiResult {
    $text = (string) ( $reply->result ?? '' );
    $part = new MessagePart( $text );
    $message = new ModelMessage( [ $part ] );
    $candidate = new Candidate( $message, FinishReasonEnum::stop() );

    $usage = $reply->usage ?? [];
    $prompt = (int) ( $usage['prompt_tokens'] ?? 0 );
    $completion = (int) ( $usage['completion_tokens'] ?? 0 );
    $total = (int) ( $usage['total_tokens'] ?? ( $prompt + $completion ) );
    $tokenUsage = new TokenUsage( $prompt, $completion, $total );

    $id = (string) ( $reply->id ?? uniqid( 'mwai-', true ) );
    return new GenerativeAiResult(
      $id,
      [ $candidate ],
      $tokenUsage,
      $this->providerMetadata,
      $this->modelMetadata
    );
  }
}
