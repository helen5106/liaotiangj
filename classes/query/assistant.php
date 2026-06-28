<?php

class Meow_MWAI_Query_Assistant extends Meow_MWAI_Query_Base implements JsonSerializable {
  // Core Content
  public ?Meow_MWAI_Query_DroppedFile $attachedFile = null;
  public ?array $attachedFiles = null;

  // Parameters
  public ?string $chatId = null;
  public ?string $runId = null;
  public ?string $assistantId = null;
  public ?string $threadId = null;
  public ?string $storeId = null; // Vector Store ID (https://platform.openai.com/docs/api-reference/vector-stores)

  #region Constructors, Serialization

  public function __construct( ?string $message = '' ) {
    parent::__construct( $message );
    $this->feature = 'assistant';
  }

  #[\ReturnTypeWillChange]
  public function jsonSerialize(): array {
    $json = [
      'message' => $this->message,

      'ai' => [
        'model' => $this->model,
        'feature' => $this->feature,
        'assistantId' => $this->assistantId,
        'threadId' => $this->threadId,
        'storeId' => $this->storeId,
        'runId' => $this->runId,
      ],

      'system' => [
        'class' => get_class( $this ),
        'envId' => $this->envId,
        'scope' => $this->scope,
        'session' => $this->session,
        'customId' => $this->customId,
        'chatId' => $this->chatId,
      ]
    ];

    if ( !empty( $this->context ) ) {
      $json['context']['context'] = $this->context;
    }

    if ( !empty( $this->attachedFile ) ) {
      $json['context']['hasFile'] = true;
      if ( $this->attachedFile->get_type() === 'url' ) {
        $json['context']['fileUrl'] = $this->attachedFile->get_url();
      }
    }

    if ( !empty( $this->attachedFiles ) ) {
      $json['context']['hasFiles'] = true;
      $json['context']['fileCount'] = count( $this->attachedFiles );
    }

    return $json;
  }

  #endregion

  #region File Handling

  /**
   * Get all attached files as a normalized array.
   * @return Meow_MWAI_Query_DroppedFile[] Array of attached files
   */
  public function getAttachments(): array {
    $files = $this->attachedFiles ?? [];
    if ( $this->attachedFile && !in_array( $this->attachedFile, $files, true ) ) {
      array_unshift( $files, $this->attachedFile );
    }
    return $files;
  }

  public function add_file( Meow_MWAI_Query_DroppedFile $file ): void {
    if ( $this->attachedFiles === null ) {
      $this->attachedFiles = [];
    }
    $this->attachedFiles[] = $file;
  }

  public function set_files( array $files ): void {
    $this->attachedFiles = $files;
  }

  public function get_files(): ?array {
    return $this->attachedFiles;
  }

  #endregion

  #region Parameters

  public function setAssistantId( string $assistantId ): void {
    $this->assistantId = $assistantId;
  }

  public function setChatId( string $chatId ): void {
    $this->chatId = $chatId;
  }

  public function setThreadId( string $threadId ): void {
    $this->threadId = $threadId;
  }

  public function setStoreId( string $storeId ): void {
    $this->storeId = $storeId;
  }

  public function setRunId( string $runId ): void {
    $this->runId = $runId;
  }

  #endregion

  #region Inject Params

  // Based on the params of the query, update the attributes
  public function inject_params( array $params ): void {
    parent::inject_params( $params );

    // Those are for the keys passed directly by the shortcode.
    $params = $this->convert_keys( $params );

    // Additional for Assistant.
    if ( !empty( $params['chatId'] ) ) {
      $this->setChatId( $params['chatId'] );
    }
    if ( !empty( $params['assistantId'] ) ) {
      $this->setAssistantId( $params['assistantId'] );
    }
    if ( !empty( $params['threadId'] ) ) {
      $this->setThreadId( $params['threadId'] );
    }
  }

  #endregion
}
