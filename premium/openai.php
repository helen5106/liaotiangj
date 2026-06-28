<?php

class MeowPro_MWAI_OpenAI extends Meow_MWAI_Engines_OpenAI {
  public function __construct( $core, $env ) {
    parent::__construct( $core, $env );
  }

  private function get_cached_assistant( $envId, $assistantId ) {
    static $cache = [];
    if ( isset( $cache[$envId][$assistantId] ) ) {
      return $cache[$envId][$assistantId];
    }
    $assistant = $this->core->get_assistant( $envId, $assistantId );
    if ( empty( $assistant ) ) {
      throw new Exception( 'Assistant not found.' );
    }
    $cache[$envId][$assistantId] = $assistant;
    return $assistant;
  }

  public function remove_bracketed_substrings( $text ) {
    $text = preg_replace( '/【.*?】/s', '', $text );
    return $text;
  }

  public function run_assistant_query( Meow_MWAI_Query_Assistant $query, $streamCallback = null ): Meow_MWAI_Reply {
    $isStreaming = !is_null( $streamCallback );
    $envId = $query->envId;
    $assistantId = $query->assistantId;

    // If envId is empty but we have an assistantId, find the correct environment
    if ( empty( $envId ) && !empty( $assistantId ) ) {
      $envs = $this->core->get_option( 'ai_envs' );
      foreach ( $envs as $env ) {
        if ( isset( $env['assistants'] ) ) {
          foreach ( $env['assistants'] as $assistant ) {
            if ( $assistant['id'] === $assistantId ) {
              $envId = $env['id'];
              $query->envId = $envId; // Update the query object
              Meow_MWAI_Logging::info( "Assistant: Auto-detected envId '{$envId}' for assistant '{$assistantId}'" );
              break 2;
            }
          }
        }
      }
    }

    // If it's a form, there is no chatId, a new one will be generated, and a new thread will be created.
    $chatId = !empty( $query->chatId ) ? $query->chatId : $this->core->get_random_id( 10 );
    if ( empty( $envId ) || empty( $assistantId ) ) {
      throw new Exception( 'Assistant requires an envId and an assistantId.' );
    }
    $assistant = $this->get_cached_assistant( $envId, $assistantId );
    if ( !empty( $assistant['model'] ) ) {
      $query->set_model( $assistant['model'] );
    }

    // We will use the $chatId to see if there are any previous conversations.
    // If not, we need to create a new thread.

    // Let's check if the threadId of this discussion (chatId) exists.
    $threadId = get_transient( 'mwai_thread_id_' . $chatId );

    // If it doesn't exist, let's get it from the discussion.
    if ( empty( $threadId ) && $this->core->discussions ) {
      $chat = $this->core->discussions->get_discussion( $query->botId, $chatId );
      $threadId = empty( $chat['threadId'] ) ? null : $chat['threadId'];
    }

    $isFeedback = $query instanceof Meow_MWAI_Query_AssistFeedback;

    if ( $isFeedback ) {
      if ( empty( $threadId ) ) {
        throw new Exception( 'The threadId is required for feedback queries.' );
      }
    }

    // Create Thread
    if ( empty( $threadId ) ) {
      // TODO: Should probably be a formId
      $body = [ 'metadata' => [ 'chatId' => $chatId ] ];
      $body['messages'] = [];
      $res = $this->execute( 'POST', '/threads', $body, null, true, [ 'OpenAI-Beta' => 'assistants=v2' ] );
      $threadId = $res['id'];
      if ( empty( $threadId ) ) {
        throw new Exception( 'The thread could not be created on the assistant.' );
      }
    }

    if ( !empty( $threadId ) ) {
      set_transient( 'mwai_thread_id_' . $chatId, $threadId, 60 * 60 * 24 * 30 * 3 );
    }

    // Set the threadId in the query
    $query->setThreadId( $threadId );

    // Clear streaming buffer
    $this->streamBuffer = '';
    $this->streamContent = '';
    $this->streamFunctionCall = '';
    $this->streamToolCalls = [];
    $this->streamAnnotations = [];
    $this->streamImageIds = [];

    // Create Message
    $runId = null;
    try {
      $runId = $this->build_final_query( $query, $threadId, $streamCallback );
    }
    catch ( Exception $e ) {
      delete_transient( 'mwai_thread_id_' . $chatId );
      throw $e;
    }

    // It does not need to wait for the run to complete with streaming
    if ( $isStreaming ) {
      if ( empty( $this->streamContent ) ) {
        $error = $this->try_decode_error( $this->streamBuffer );
        if ( !is_null( $error ) ) {
          throw new Exception( $error );
        }
      }
      $reply = new Meow_MWAI_Reply( $query );
      $content = $this->streamContent;
      $message = [ 'role' => 'assistant', 'content' => '' ];
      if ( !empty( $this->streamFunctionCall ) ) {
        $message['function_call'] = $this->streamFunctionCall;
      }
      if ( !empty( $this->streamToolCalls ) ) {
        $message['tool_calls'] = $this->streamToolCalls;
      }
      if ( !empty( $this->streamImageIds ) ) {
        $images = $this->handle_images( $this->streamImageIds, $assistantId, $threadId, $envId );
        $content = $this->replace_images( $content, $images );
      }
      if ( !empty( $this->streamAnnotations ) ) {
        $handledAnnotations = $this->handle_annotations( $this->streamAnnotations, $assistantId, $threadId, $envId );
        $content = $this->replace_annotations( $content, $handledAnnotations );
      }
      $content = $this->remove_bracketed_substrings( $content );
      $message['content'] = $content;
      $returned_choices = [ [ 'message' => $message ] ];
      $reply->set_choices( $returned_choices );
      if ( !empty( $this->inThreadId ) ) {
        $query->setThreadId( $this->inThreadId );
      }
      $returned_id = $this->inId;
      if ( !empty( $returned_id ) ) {
        $reply->set_id( $returned_id );
      }
      if ( !empty( $returned_id ) ) {
        $reply->query->setRunId( $reply->id );
      }
      $reply->set_type( 'assistant' );
      $in_tokens = $query->get_in_tokens();
      $out_tokens = Meow_MWAI_Core::estimate_tokens( $reply->result );
      $usage = $this->core->record_tokens_usage( $query->model, $in_tokens, $out_tokens );
      $reply->set_usage( $usage );
      return $reply;
    }

    // Wait for the run to complete
    $runStatus = $this->handle_run( $query, $threadId, $runId, $streamCallback = null );
    if ( $runStatus !== 'completed' ) {
      throw new Exception( 'The assistant run did not complete.' );
    }

    // Get Messages
    $res = $this->execute( 'GET', "/threads/{$threadId}/messages", null, null, true, [ 'OpenAI-Beta' => 'assistants=v2' ] );
    $messages = $res['data'];
    $first = $messages[0];
    $content = $first['content'];
    $finalReply = '';
    foreach ( $content as $block ) {
      if ( $block['type'] === 'image_file' ) {
        $fileId = $block['image_file']['file_id'];
        $finalReply .= '<!-- IMG #' . $block['image_file']['file_id'] . ' -->';
        $images = $this->handle_images( [ $block['image_file']['file_id'] ], $assistantId, $threadId, $envId );
        $finalReply = $this->replace_images( $finalReply, $images );
      }
      if ( $block['type'] === 'text' ) {
        $finalReply .= $block['text']['value'];
        if ( !empty( $block['text']['annotations'] ) ) {
          $annotations = $this->handle_annotations( $block['text']['annotations'], $assistantId, $threadId, $envId );
          $finalReply = $this->replace_annotations( $finalReply, $annotations );
        }
        break;
      }
    }

    // If there are still sandbox elements, let's replace them with the URLs if we have them.
    if ( strpos( $finalReply, 'sandbox:/mnt/data/' ) !== false ) {
      preg_match_all( '/\((sandbox:\/mnt\/data\/.*?)\)/', $finalReply, $matches );
      if ( !empty( $matches[1] ) ) {
        foreach ( $matches[1] as $match ) {
          $file = pathinfo( $match );
          $files = $this->core->files->search( $this->core->get_user_id(), 'assistant-out', [
            'assistant_id' => $assistantId,
            'assistant_threadId' => $threadId,
            'assistant_sandboxPath' => $match
          ], $query->envId );
          if ( !empty( $files ) ) {
            $fileId = $files[0]['refId'];
            $url = $this->core->files->get_url( $fileId );
            $escapedMatch = preg_quote( $match, '/' );
            $finalReply = preg_replace( '/' . $escapedMatch . '/', $url, $finalReply, 1 );
          }
        }
      }
    }

    if ( empty( $finalReply ) ) {
      throw new Exception( 'No text reply from the assistant.' );
    }

    // TODO: In fact, this threadId should probably be in the query.
    // Update: There is also now the threadId accessible via transient.
    // The Discussions Module will also use that threadId. Currently, it's getting it from the $params.
    $query->setThreadId( $threadId );
    $reply = new Meow_MWAI_Reply( $query );
    $reply->set_choices( $this->remove_bracketed_substrings( $finalReply ) );
    $reply->set_type( 'assistant' );
    $in_tokens = $query->get_in_tokens();
    $out_tokens = Meow_MWAI_Core::estimate_tokens( $reply->result );
    $usage = $this->core->record_tokens_usage( $query->model, $in_tokens, $out_tokens );
    $reply->set_usage( $usage );
    return $reply;
  }

  public function handle_images( $imageIds, $assistantId, $threadId, $envId ) {
    $handledImages = [];
    foreach ( $imageIds as $fileId ) {
      $purpose = 'assistant-out';
      $tmpFile = $this->download_file( $fileId );
      // // Create a random image filename (since the assistant doesn't give us one)
      $filename = $this->core->get_random_id( 10 ) . '.png';
      $refId = $this->core->files->upload_file( $tmpFile, $filename, $purpose, [
        'assistant_id' => $assistantId,
        'assistant_threadId' => $threadId,
        //'assistant_sandboxPath' => null // $image['file_path']
      ], $envId );
      $internalFileId = $this->core->files->get_id_from_refId( $refId );
      $this->core->files->update_refId( $internalFileId, $fileId );
      $url = $this->core->files->get_url( $fileId );
      $handledImages[] = [
        'file_id' => $fileId,
        'url' => $url
      ];
    }
    return $handledImages;
  }

  public function replace_images( $finalReply, $images ) {
    foreach ( $images as $image ) {
      $escapedImageText = preg_quote( '<!-- IMG #' . $image['file_id'] . ' -->', '/' );
      $finalReply = preg_replace( '/' . $escapedImageText . '/', '![Image](' . $image['url'] . ')', $finalReply, 1 );
    }
    return $finalReply;
  }

  public function handle_annotations( $annotations, $assistantId, $threadId, $envId ) {
    $handledAnnotations = [];
    foreach ( $annotations as $annotation ) {
      if ( $annotation['type'] === 'file_path' ) {
        $file = pathinfo( $annotation['text'] );
        $fileId = $annotation['file_path']['file_id'];
        $purpose = 'assistant-out';
        $tmpFile = $this->download_file( $fileId );
        $filename = !empty( $file['basename'] ) ? $file['basename'] : $file['name'];
        $refId = $this->core->files->upload_file( $tmpFile, $filename, $purpose, [
          'assistant_id' => $assistantId,
          'assistant_threadId' => $threadId,
          'assistant_sandboxPath' => $annotation['text']
        ], $envId );
        $internalFileId = $this->core->files->get_id_from_refId( $refId );
        $this->core->files->update_refId( $internalFileId, $fileId );
        $url = $this->core->files->get_url( $fileId );
        $handledAnnotations[] = [
          'type' => 'file_path',
          'text' => $annotation['text'],
          'url' => $url
        ];
      }
    }
    return $handledAnnotations;
  }

  public function replace_annotations( $finalReply, $annotations ) {
    foreach ( $annotations as $annotation ) {
      if ( $annotation['type'] === 'file_path' ) {
        $escapedAnnotationText = preg_quote( $annotation['text'], '/' );
        $finalReply = preg_replace( '/' . $escapedAnnotationText . '/', $annotation['url'], $finalReply, 1 );
      }
    }
    return $finalReply;
  }

  private function build_final_query( $query, $threadId, $streamCallback ): ?string {

    $res = null;
    if ( $query instanceof Meow_MWAI_Query_AssistFeedback ) {
      if ( !empty( $query->blocks ) ) {
        $body = [ 'tool_outputs' => [], 'stream' => !is_null( $streamCallback ) ];
        foreach ( $query->blocks as $feedback_block ) {
          foreach ( $feedback_block['feedbacks'] as $feedback ) {
            $body['tool_outputs'][] = [
              'tool_call_id' => $feedback['request']['toolId'],
              'output' => $feedback['reply']['value']
            ];
          }
        }
        $res = $this->execute(
          'POST',
          "/threads/{$query->threadId}/runs/{$query->runId}/submit_tool_outputs",
          $body,
          null,
          true,
          [ 'OpenAI-Beta' => 'assistants=v2' ],
          $streamCallback
        );

        // if ( !empty( $streamCallback ) ) {
        //   $this->handle_run( $query->threadId, $query->runId, $streamCallback );
        // }

        $runId = !empty( $res['id'] ) ? $res['id'] : null;
      }
      else {
        throw new Exception( 'AI Engine: No feedback blocks found.' );
      }
    }
    else {
      $body = [
        'role' => 'user',
        'content' => $query->message
      ];

      $openai = Meow_MWAI_Engines_Factory::get_openai( $this->core, $query->envId );

      // Assistants v2 supports vision, so we can send images directly.
      // However, it only supports URLs, and do not support data sent as base64, contrary to the Chat API.
      $messages[] = [ 'role' => 'user', 'content' => $query->get_message() ];
      $attachments = method_exists( $query, 'getAttachments' ) ? $query->getAttachments() : [];
      if ( !empty( $attachments ) ) {
        $file = $attachments[0]; // Assistants support single file currently

        if ( $file->is_image() ) {
          $finalUrl = $file->get_url();
          $content = [
            [
              'type' => 'image_url',
              'image_url' => [ 'url' => $finalUrl ]
            ]
          ];
          $message = $query->get_message();
          if ( !empty( $message ) ) {
            $content[] = [
              'type' => 'text',
              'text' => $query->get_message()
            ];
          }
          $body = [
            'role' => 'user',
            'content' => $content
          ];
        }
        else {
          // TODO: Heavily inspired from the chatbot.php, we should probably refactor this.
          $url = $file->get_url();
          $data = $file->get_data();
          $fileId = $file->get_fileId();
          $filename = basename( $url );
          $storeId = null;

          // Upload the file
          $file = $openai->upload_file( $filename, $data, 'assistants' );

          if ( empty( $file['id'] ) ) {
            throw new Exception( 'Failed to upload file to OpenAI.' );
          }

          // Create a store. We don't have a chatId or anything, so let's use the session.
          $storeName = 'mwai_' . strtolower( $file['id'] );

          // We don't need this metadata, since it's form, and not chatbot.
          $expiry = $this->core->get_option( 'image_expires' );
          $storeId = $openai->create_vector_store( $storeName, $expiry, null );

          if ( empty( $storeId ) ) {
            throw new Exception( 'Failed to create vector store.' );
          }

          $query->setStoreId( $storeId );

          // Add the file to the store - wait a moment for store to be ready
          sleep( 1 );
          $storeFileId = $openai->add_vector_store_file( $storeId, $file['id'] );

          if ( empty( $storeFileId ) ) {
            throw new Exception( 'Failed to add file to vector store.' );
          }

          // Update the local file with the OpenAI RefId, StoreId and StoreFileId
          $openAiRefId = $file['id'];
          $internalFileId = $this->core->files->get_id_from_refId( $fileId );
          $this->core->files->update_refId( $internalFileId, $openAiRefId );
          $this->core->files->update_envId( $internalFileId, $query->envId );
          $this->core->files->update_purpose( $internalFileId, 'analysis' );
          $this->core->files->add_metadata( $internalFileId, 'assistant_storeId', $storeId );
          $this->core->files->add_metadata( $internalFileId, 'assistant_storeFileId', $storeFileId );
          $newFileId = $openAiRefId;
          $id = $this->core->files->get_id_from_refId( $newFileId );
          $this->core->files->add_metadata( $id, 'assistant_scope', 'discussion' );
        }
      }

      if ( $query->storeId ) {
        $maxAttempts = 30; // Increased to 60 seconds for file processing
        $attempt = 0;
        $filesProcessed = false;

        while ( $attempt < $maxAttempts ) {
          $storeInfo = $openai->get_vector_store( $query->storeId );

          // Check both store status and file processing status
          if ( !empty( $storeInfo['status'] ) && $storeInfo['status'] === 'completed' ) {
            // Store is ready, now check if files are processed
            if ( !empty( $storeInfo['file_counts'] ) ) {
              $fileCounts = $storeInfo['file_counts'];
              $totalFiles = $fileCounts['total'] ?? 0;
              $completedFiles = $fileCounts['completed'] ?? 0;
              $inProgressFiles = $fileCounts['in_progress'] ?? 0;
              $failedFiles = $fileCounts['failed'] ?? 0;

              if ( $failedFiles > 0 ) {
                throw new Exception( 'Failed to process files in vector store ' . $query->storeId . '. ' . $failedFiles . ' file(s) failed processing.' );
              }

              if ( $totalFiles > 0 && $completedFiles === $totalFiles && $inProgressFiles === 0 ) {
                // All files have been successfully processed
                $filesProcessed = true;
                break;
              }
            }
          }

          sleep( 2 );
          $attempt++;
        }

        if ( !$filesProcessed ) {
          throw new Exception( 'Files in vector store ' . $query->storeId . ' were not processed within ' . ( $maxAttempts * 2 ) . ' seconds. Please ensure all files in the vector store are processed before retrying the run.' );
        }

        // Modify Thread to use the store
        $res = $this->execute( 'POST', "/threads/{$threadId}", [
          'tool_resources' => [
            'file_search' => [
              'vector_store_ids' => [ $query->storeId ]
            ],
          ],
        ], null, true, [ 'OpenAI-Beta' => 'assistants=v2' ] );
        // Check if the thread was updated
        if ( empty( $res['tool_resources']['file_search']['vector_store_ids'] ) ) {
          throw new Exception( 'The thread could not be updated with the store.' );
        }
      }

      // LATER: We keep this for the future, when we will support for files to be ran through the Code Interpreter.
      // if ( !empty( $query->file ) ) {
      //   if ( $query->fileType !== 'refId' || $query->filePurpose !== 'assistant-in' ) {
      //     throw new Exception( 'The file type should be refId and the file purpose should be assistant-in.' );
      //   }
      //   // Attachments if we add to tools
      //   // $body['attachments'] = [
      //   //   [
      //   //     'file_id' => $query->file,
      //   //     //'tools' =>
      //   //   ]
      //   // ];
      //   $fileId = $this->core->files->get_id_from_refId( $query->file );
      //   $this->core->files->add_metadata( $fileId, 'assistant_id', $assistantId );
      //   $this->core->files->add_metadata( $fileId, 'assistant_threadId', $threadId );
      // }

      foreach ( $query->messages as $message ) {
        if ( !empty( $message['functions'] ) ) {
          $body['functions'] = $message['functions'];
          $body['function_call'] = $message['function_call'];
        }
      }

      try {
        $this->execute(
          'POST',
          "/threads/{$query->threadId}/messages",
          $body,
          null,
          true,
          [ 'OpenAI-Beta' => 'assistants=v2' ]
        );
      }
      catch ( Exception $e ) {
        // If we have an unclosed run, we cancel it and try again.
        $unclosedRunMessage = "Can't add messages to " . $query->threadId . ' while a run';
        if ( strpos( $e->getMessage(), $unclosedRunMessage ) !== false ) {
          $resRuns = $this->execute(
            'GET',
            "/threads/{$query->threadId}/runs",
            null,
            null,
            true,
            [ 'OpenAI-Beta' => 'assistants=v2' ]
          );
          foreach ( $resRuns['data'] as $run ) {
            if ( $run['status'] === 'requires_action' ) {
              $this->execute(
                'POST',
                "/threads/{$query->threadId}/runs/{$run['id']}/cancel",
                null,
                null,
                true,
                [ 'OpenAI-Beta' => 'assistants=v2' ]
              );
            }
          }
          $this->execute(
            'POST',
            "/threads/{$query->threadId}/messages",
            $body,
            null,
            true,
            [ 'OpenAI-Beta' => 'assistants=v2' ]
          );
        }
        else {
          throw $e;
        }
      }

      // Create Run with support for Instructions and Context
      $runId = $this->create_run( $query, $streamCallback );
    }

    return $runId;
  }

  private function create_run( $query, $streamCallback = null ) {
    $body = [ 'assistant_id' => $query->assistantId ];
    if ( !empty( $query->instructions ) ) {
      $body['additional_instructions'] = $query->instructions;
    }
    if ( !empty( $query->context ) ) {
      if ( isset( $body['additional_instructions'] ) ) {
        $body['additional_instructions'] .= "\n";
      }
      else {
        $body['additional_instructions'] = '';
      }
      $body['additional_instructions'] .= "Additional context:\n" . $query->context;
    }
    $body['assistant_id'] = $query->assistantId;

    // Enable streaming if a callback is provided
    if ( !is_null( $streamCallback ) ) {
      $body['stream'] = true;
    }

    $res = $this->execute(
      'POST',
      "/threads/{$query->threadId}/runs",
      $body,
      null,
      true,
      [ 'OpenAI-Beta' => 'assistants=v2' ],
      $streamCallback
    );
    if ( !is_null( $streamCallback ) ) {
      // If streaming is enabled, the response will be handled by the stream handler
      return null;
    }

    $runId = $res['id'];
    return $runId;
  }

  private function handle_run( $query, $threadId, $runId, $streamCallback = null ) {
    do {
      sleep( 0.25 ); // Consider implementing exponential backoff or similar strategy.
      $res = $this->execute(
        'GET',
        "/threads/{$threadId}/runs/{$runId}",
        null,
        null,
        true,
        [ 'OpenAI-Beta' => 'assistants=v2' ]
      );
      $status = $res['status'];
    }
    while ( in_array( $status, ['running', 'queued', 'in_progress'] ) );
    return $this->handle_run_actions( $query, $res, $threadId, $runId, $streamCallback );
  }

  private function handle_run_actions( $query, $res, $threadId, $runId, $streamCallback = null ) {
    $runStatus = $res['status'];
    if ( $runStatus === 'failed' ) {
      if ( isset( $res['last_error']['message'] ) ) {
        $message = $res['last_error']['message'];
        throw new Exception( $message );
      }
      else {
        throw new Exception( 'Unknown error.' );
      }
    }

    if ( $runStatus === 'requires_action' ) {
      $functions = [];
      $calls = [];

      // First, let's collect the function definitions.
      foreach ( $res['tools'] as $tool ) {
        if ( $tool['type'] === 'function' ) {
          $functionDetails = $tool['function'];
          $parameters = [];

          foreach ( $functionDetails['parameters']['properties'] as $paramKey => $paramValue ) {
            $parameters[] = new Meow_MWAI_Query_Parameter(
              $paramKey,
              isset( $paramValue['description'] ) ? $paramValue['description'] : '',
              isset( $paramValue['type'] ) ? $paramValue['type'] : 'string',
              in_array( $paramKey, $functionDetails['parameters']['required'] )
            );
          }

          // Create new function with the details.
          $name = isset( $functionDetails['name'] ) ? $functionDetails['name'] : '';
          if ( empty( $name ) ) {
            throw new Exception( 'AI Engine: The function "name" cannot be empty.' );
          }
          $description = isset( $functionDetails['description'] ) ? $functionDetails['description'] : '';
          $functions[$name] = new Meow_MWAI_Query_Function( $name, $description, $parameters );
        }
      }

      // Then let's process the calls.
      foreach ( $res['required_action']['submit_tool_outputs']['tool_calls'] as $call ) {
        $callId = $call['id'];
        $funcName = $call['function']['name'];
        $funcArgs = $call['function']['arguments'];
        $decodedFuncArgs = json_decode( $funcArgs, true );

        // Now, match the call to the function definition.
        if ( array_key_exists( $funcName, $functions ) ) {
          $parameterValues = [];

          foreach ( $decodedFuncArgs as $argKey => $argValue ) {
            $parameterValues[$argKey] = $argValue;
          }

          // Store the call with its matched function and parameter values.
          $calls[] = [
            'id' => $callId,
            'func' => $functions[$funcName],
            'args' => $parameterValues
          ];
        }
      }
      $tool_outputs = [];
      foreach ( $calls as $call ) {
        $foundFunction = null;
        foreach ( $query->functions as $function ) {
          if ( $function->name == $call['func']->name ) {
            $foundFunction = $function;
            break;
          }
        }
        $value = apply_filters( 'mwai_ai_feedback', null, [
          'toolId' => $call['id'],
          //'mode' => 'interactive',
          'type' => 'tool_call',
          'name' => $call['func']->name,
          'arguments' => $call['args'],
          'rawMessage' => $query->message,
          'function' => $foundFunction
        ], $call['args'] );
        if ( $value !== null ) {
          $tool_outputs[] = [ 'tool_call_id' => $call['id'], 'output' => $value ];
        }
      }
      if ( empty( $tool_outputs ) ) {
        throw new Exception( 'This assistant use functions. In this case, the function "' . $call['func']->name . '" was called with the arguments ' . json_encode( $call['args'] ) . '. Please use the mwai_ai_feedback filter to handle this.' );
      }
      $body = [ 'tool_outputs' => $tool_outputs ];
      $res = $this->execute(
        'POST',
        "/threads/{$threadId}/runs/{$runId}/submit_tool_outputs",
        $body,
        null,
        true,
        [ 'OpenAI-Beta' => 'assistants=v2' ]
      );
      return $this->handle_run( $query, $threadId, $runId, $streamCallback );
    }
    return $runStatus;
  }
}
