<?php

class MeowPro_MWAI_Assistants {
  private $core = null;
  private $namespace = 'mwai/v1';

  public function __construct( $core ) {
    $this->core = $core;
    add_action( 'rest_api_init', [ $this, 'rest_api_init' ] );

    // Handle mwai_files_delete
    add_filter( 'mwai_files_delete', [ $this, 'files_delete_filter' ], 10, 2 );
  }

  #region REST API

  public function rest_api_init() {
    register_rest_route( $this->namespace, '/openai/assistants/list', [
      'methods' => 'GET',
      'permission_callback' => [ $this->core, 'can_access_settings' ],
      'callback' => [ $this, 'rest_assistants_list' ],
    ] );
    register_rest_route( $this->namespace, '/openai/assistants/set_functions', [
      'methods' => 'POST',
      'permission_callback' => [ $this->core, 'can_access_settings' ],
      'callback' => [ $this, 'rest_assistants_set_functions' ],
    ] );
  }

  public function rest_assistants_list( $request ) {
    try {
      $envId = $request->get_param( 'envId' );
      $openai = Meow_MWAI_Engines_Factory::get_openai( $this->core, $envId );
      $rawAssistants = [];
      $hasMore = true;
      $lastId = null;
      while ( $hasMore ) {
        $query = '/assistants?limit=25';
        if ( $lastId !== null ) {
          $query .= '&after=' . $lastId;
        }
        $res = $openai->execute( 'GET', $query, null, null, true, [
          'OpenAI-Beta' => 'assistants=v2'
        ] );
        $data = $res['data'];
        $rawAssistants = array_merge( $rawAssistants, $data );
        $lastId = $res['last_id'];
        $hasMore = $res['has_more'];
      }

      $deploymentsToModels = [];
      $aiEnv = $this->core->get_ai_env( $envId );
      if ( !empty( $aiEnv ) && $aiEnv['type'] === 'azure' ) {
        foreach ( $aiEnv['deployments'] as $deployment ) {
          $deploymentsToModels[ $deployment['name'] ] = $deployment['model'];
        }
      }

      $assistants = array_map( function ( $assistant ) use ( $deploymentsToModels ) {
        // Convert the deployment name to the model, if it's Azure
        if ( !empty( $deploymentsToModels ) ) {
          if ( isset( $deploymentsToModels[ $assistant['model'] ] ) ) {
            $assistant['model'] = $deploymentsToModels[ $assistant['model'] ];
          }
          else {
            Meow_MWAI_Logging::error( "Azure deployment not found for model: {$assistant['model']}." );
            error_log( "AI Engine: Azure deployment not found for model: {$assistant['model']}." );
            $assistant['model'] = null;
          }
        }
        $assistant['createdOn'] = date( 'Y-m-d H:i:s', $assistant['created_at'] );
        $has_code_interpreter = false;
        $has_file_search = false;
        foreach ( $assistant['tools'] as $tool ) {
          if ( $tool['type'] === 'code_interpreter' ) {
            $has_code_interpreter = true;
          }
          if ( $tool['type'] === 'file_search' ) {
            $has_file_search = true;
          }
        }
        $assistant['has_code_interpreter'] = $has_code_interpreter;
        $assistant['has_file_search'] = $has_file_search;
        unset( $assistant['file_ids'] );
        unset( $assistant['metadata'] );
        unset( $assistant['tools'] );
        unset( $assistant['created_at'] );
        unset( $assistant['updated_at'] );
        unset( $assistant['deleted_at'] );
        unset( $assistant['tools'] );
        unset( $assistant['object'] );
        return $assistant;
      }, $rawAssistants );
      $this->core->update_ai_env( $envId, 'assistants', $assistants );
      return new WP_REST_Response( [ 'success' => true, 'assistants' => $assistants ], 200 );
    }
    catch ( Exception $e ) {
      $message = apply_filters( 'mwai_ai_exception', $e->getMessage() );
      return new WP_REST_Response( [ 'success' => false, 'message' => $message ], 500 );
    }
  }

  public function rest_assistants_set_functions( $request ) {
    try {
      $envId = $request->get_param( 'envId' );
      $assistantId = $request->get_param( 'assistantId' );
      $functions = $request->get_param( 'functions' );

      // 1) Build array of new function tools
      $openai = Meow_MWAI_Engines_Factory::get_openai( $this->core, $envId );
      $new_tools = [];
      foreach ( $functions as $function ) {
        $queryFunction = MeowPro_MWAI_FunctionAware::get_function( $function['type'], $function['id'] );
        $new_tools[] = [ 'type' => 'function', 'function' => $queryFunction->serializeForOpenAI() ];
      }

      // 2) Fetch current assistant data
      $current_tools = $openai->execute( 'GET', "/assistants/{$assistantId}", [], null, true, [ 'OpenAI-Beta' => 'assistants=v2' ] );
      if ( empty( $current_tools ) || !is_array( $current_tools ) ) {
        throw new Exception( 'Could not fetch the assistant.' );
      }

      // 3) Remove old function entries from the existing tools
      $fresh_tools = [];
      if ( !empty( $current_tools['tools'] ) && is_array( $current_tools['tools'] ) ) {
        foreach ( $current_tools['tools'] as $tool ) {
          // Keep anything that isn't type 'function'
          if ( isset( $tool['type'] ) && $tool['type'] === 'function' ) {
            continue;
          }
          $fresh_tools[] = $tool;
        }
      }

      // 4) Merge new function tools with all the old non-function tools
      $fresh_tools = array_merge( $fresh_tools, $new_tools );

      // 5) Update the assistant with fresh_tools
      $res = $openai->execute( 'POST', "/assistants/{$assistantId}", [ 'tools' => $fresh_tools ], null, true, [ 'OpenAI-Beta' => 'assistants=v2' ] );

      return new WP_REST_Response( [ 'success' => !empty( $res ) ], 200 );
    }
    catch ( Exception $e ) {
      $message = apply_filters( 'mwai_ai_exception', $e->getMessage() );
      return new WP_REST_Response( [ 'success' => false, 'message' => $message ], 500 );
    }
  }

  #endregion

  #region Files Delete Filter
  public function get_env_id_from_assistant_id( $assistantId ) {
    $envs = $this->core->get_option( 'ai_envs' );
    foreach ( $envs as $env ) {
      if ( !empty( $env['assistants'] ) ) {
        foreach ( $env['assistants'] as $assistant ) {
          if ( $assistant['id'] === $assistantId ) {
            return $env['id'];
          }
        }
      }
    }
    return null;
  }

  public function files_delete_filter( $refIds ) {
    foreach ( $refIds as $refId ) {
      $metadata = $this->core->files->get_metadata( $refId );
      $assistantId = $metadata['assistant_id'] ?? null;
      $threadId = $metadata['assistant_threadId'] ?? null;
      if ( !empty( $assistantId ) && !empty( $threadId ) ) {
        $envId = $this->get_env_id_from_assistant_id( $assistantId );
        if ( !empty( $envId ) ) {
          $openai = Meow_MWAI_Engines_Factory::get_openai( $this->core, $envId );
          try {
            $openai->execute( 'DELETE', "/files/{$refId}", null, null, true, [ 'OpenAI-Beta' => 'assistants=v2' ] );
          }
          catch ( Exception $e ) {
            Meow_MWAI_Logging::error( 'OpenAI: ' . $e->getMessage() );
          }
        }
      }
    }
    return $refIds;
  }
  #endregion
}
