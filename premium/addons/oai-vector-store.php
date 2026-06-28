<?php

class MeowPro_MWAI_Addons_OaiVectorStore {
  private $core = null;

  // Current Vector DB
  private $env = null;
  private $openai_env_id = null;
  private $openai_env = null;
  private $apiKey = null;
  private $store_id = null;
  private $maxSelect = 10;

  public function __construct() {
    global $mwai_core;
    $this->core = $mwai_core;
    // init_settings is invoked lazily by each filter handler with the per-call envId, so
    // pre-validating the default env here would only emit misleading log noise.
    add_filter( 'mwai_embeddings_list_vectors', [ $this, 'list_vectors' ], 10, 2 );
    add_filter( 'mwai_embeddings_add_vector', [ $this, 'add_vector' ], 10, 3 );
    add_filter( 'mwai_embeddings_get_vector', [ $this, 'get_vector' ], 10, 4 );
    add_filter( 'mwai_embeddings_query_vectors', [ $this, 'query_vectors' ], 10, 4 );
    add_filter( 'mwai_embeddings_delete_vectors', [ $this, 'delete_vectors' ], 10, 2 );
    add_filter( 'mwai_embeddings_upload_file', [ $this, 'upload_raw_file' ], 10, 2 );
    add_filter( 'mwai_embeddings_refresh_status', [ $this, 'refresh_file_status' ], 10, 2 );
    add_filter( 'mwai_embeddings_sync_remote_files', [ $this, 'sync_remote_files' ], 10, 2 );
  }

  public function init_settings( $envId = null ) {
    $envId = $envId ?? $this->core->get_option( 'embeddings_env' );
    $this->env = $this->core->get_embeddings_env( $envId );

    // This class only handles OpenAI Vector Store.
    if ( empty( $this->env ) ) {
      Meow_MWAI_Logging::error( "OpenAI Vector Store: No embeddings environment found for envId '{$envId}'." );
      return false;
    }
    if ( $this->env['type'] !== 'openai-vector-store' ) {
      // Not an OpenAI Vector Store environment, silently skip (other handlers will process it)
      return false;
    }

    $this->openai_env_id = isset( $this->env['openai_env_id'] ) ? $this->env['openai_env_id'] : null;
    $this->store_id = isset( $this->env['store_id'] ) ? $this->env['store_id'] : null;
    $this->maxSelect = isset( $this->env['max_select'] ) ? (int) $this->env['max_select'] : 10;

    // Validate store_id
    if ( empty( $this->store_id ) ) {
      Meow_MWAI_Logging::error( 'OpenAI Vector Store ID is not configured. Please set the Vector Store ID in the embeddings environment settings.' );
      $this->env = null; // Disable this environment
      return false;
    }

    // Ensure store_id starts with 'vs_'
    if ( strpos( $this->store_id, 'vs_' ) !== 0 ) {
      Meow_MWAI_Logging::error( "Invalid Vector Store ID: '{$this->store_id}'. OpenAI Vector Store IDs must start with 'vs_'." );
      $this->env = null; // Disable this environment
      return false;
    }

    // Get the OpenAI environment to retrieve the API key
    if ( empty( $this->openai_env_id ) ) {
      Meow_MWAI_Logging::error( 'OpenAI Vector Store: No OpenAI environment selected. Please select an OpenAI environment in the embeddings settings.' );
      $this->env = null;
      return false;
    }

    $this->openai_env = $this->core->get_ai_env( $this->openai_env_id );
    if ( !$this->openai_env ) {
      Meow_MWAI_Logging::error( "OpenAI Vector Store: OpenAI environment '{$this->openai_env_id}' not found." );
      $this->env = null;
      return false;
    }

    if ( empty( $this->openai_env['apikey'] ) ) {
      Meow_MWAI_Logging::error( "OpenAI Vector Store: No API key found in OpenAI environment '{$this->openai_env_id}'." );
      $this->env = null;
      return false;
    }

    $this->apiKey = $this->openai_env['apikey'];
    return true;
  }

  // Generic function to run a request to OpenAI.
  public function run( $method, $url, $query = null, $json = true ) {
    if ( empty( $this->apiKey ) ) {
      throw new Exception( 'OpenAI API key not found. Please configure the OpenAI environment.' );
    }

    $headers = "accept: application/json, charset=utf-8\r\ncontent-type: application/json\r\n" .
      'Authorization: Bearer ' . $this->apiKey . "\r\n" .
      'OpenAI-Beta: assistants=v2' . "\r\n";

    $body = $query ? json_encode( $query ) : null;
    $url = 'https://api.openai.com/v1' . $url;
    $url = untrailingslashit( esc_url_raw( $url ) );

    $options = [
      'headers' => $headers,
      'method' => $method,
      'timeout' => MWAI_TIMEOUT,
      'body' => $body,
      'sslverify' => MWAI_SSL_VERIFY
    ];

    try {
      $response = wp_remote_request( $url, $options );
      if ( is_wp_error( $response ) ) {
        throw new Exception( $response->get_error_message() );
      }
      $status = (int) wp_remote_retrieve_response_code( $response );
      $body = wp_remote_retrieve_body( $response );

      // On HTTP errors, try to surface the OpenAI error message even in raw mode,
      // otherwise the JSON error body would be returned as if it were valid content.
      if ( $status >= 400 ) {
        $errorData = json_decode( $body, true );
        if ( is_array( $errorData ) && isset( $errorData['error']['message'] ) ) {
          throw new Exception( $errorData['error']['message'] );
        }
        throw new Exception( $body !== '' ? $body : ( 'OpenAI HTTP ' . $status ) );
      }

      $data = $body === '' ? true : ( $json ? json_decode( $body, true ) : $body );
      if ( !is_array( $data ) && empty( $data ) && is_string( $body ) ) {
        throw new Exception( $body );
      }
      if ( is_array( $data ) && isset( $data['error'] ) ) {
        throw new Exception( $data['error']['message'] ?? 'Unknown OpenAI error' );
      }
      return $data;
    }
    catch ( Exception $e ) {
      Meow_MWAI_Logging::error( 'OpenAI Vector Store: ' . $e->getMessage() );
      throw new Exception( $e->getMessage() . ' (OpenAI Vector Store)' );
    }
    return [];
  }

  // List all vectors from OpenAI Vector Store.
  public function list_vectors( $vectors, $options ) {
    if ( !empty( $vectors ) ) {
      return $vectors;
    }
    $envId = $options['envId'];
    $limit = $options['limit'];
    if ( !$this->init_settings( $envId ) ) {
      return false;
    }

    // OpenAI Vector Store doesn't provide a direct way to list vector IDs like Pinecone
    // We'll need to list files in the vector store instead
    $res = $this->run( 'GET', "/vector_stores/{$this->store_id}/files?limit={$limit}" );

    if ( isset( $res['data'] ) ) {
      $vectors = array_map( function ( $file ) {
        return $file['id'];
      }, $res['data'] );
    }

    return $vectors;
  }

  // Delete vectors from OpenAI Vector Store.
  public function delete_vectors( $success, $options ) {
    // Already handled.
    if ( $success ) {
      return $success;
    }
    $envId = $options['envId'];
    if ( !$this->init_settings( $envId ) ) {
      return false;
    }
    $ids = $options['ids'];
    $deleteAll = $options['deleteAll'];

    if ( $deleteAll ) {
      // OpenAI doesn't support deleting all files at once
      // We would need to list all files and delete them individually
      throw new Exception( 'Delete all is not supported for OpenAI Vector Store. Please delete files individually.' );
    }

    // Ensure $ids is an array
    if ( !is_array( $ids ) ) {
      $ids = [ $ids ];
    }

    // Delete individual files
    foreach ( $ids as $fileId ) {
      try {
        $this->run( 'DELETE', "/vector_stores/{$this->store_id}/files/{$fileId}" );
      }
      catch ( Exception $e ) {
        // Log the error but continue with other deletions
        Meow_MWAI_Logging::error( "Failed to delete file {$fileId}: " . $e->getMessage() );
      }
    }

    return true;
  }

  // Add a vector to OpenAI Vector Store.
  public function add_vector( $success, $vector, $options ) {
    if ( $success ) {
      return $success;
    }
    $envId = $options['envId'];
    if ( !$this->init_settings( $envId ) ) {
      return false;
    }

    // Create a temporary file with the content
    $content = "Title: {$vector['title']}\n\n{$vector['content']}";
    $temp_file = tempnam( sys_get_temp_dir(), 'mwai_vector_' );
    file_put_contents( $temp_file, $content );

    try {
      // First, upload the file to OpenAI
      $file_upload_response = $this->upload_file( $temp_file, $vector['title'] );
      $file_id = $file_upload_response['id'];

      // Then, add the file to the vector store
      $body = [
        'file_id' => $file_id
      ];

      $metadata = isset( $vector['_metadata'] ) && is_array( $vector['_metadata'] ) ? $vector['_metadata'] : [];
      $attributes = $this->build_attributes( $metadata );
      if ( !empty( $attributes ) ) {
        $body['attributes'] = $attributes;
      }

      $res = $this->run( 'POST', "/vector_stores/{$this->store_id}/files", $body );

      // Clean up temp file
      unlink( $temp_file );

      if ( isset( $res['id'] ) ) {
        return $res['id'];
      }

      throw new Exception( 'Failed to add file to vector store' );
    }
    catch ( Exception $e ) {
      // Clean up temp file on error
      if ( file_exists( $temp_file ) ) {
        unlink( $temp_file );
      }
      throw $e;
    }
  }

  // Upload a raw file (PDF/DOCX/MD/...) directly to OpenAI Vector Store. Unlike add_vector,
  // we do NOT do any local text extraction or chunking — OpenAI handles parsing + chunking
  // + embedding internally. Returns [ 'file_id', 'status', 'bytes' ] or false.
  public function upload_raw_file( $result, $options ) {
    if ( !empty( $result ) ) {
      return $result;
    }
    if ( empty( $options['envId'] ) ) {
      return false;
    }
    if ( !$this->init_settings( $options['envId'] ) ) {
      return false;
    }

    $path = isset( $options['path'] ) ? $options['path'] : null;
    $filename = isset( $options['filename'] ) ? $options['filename'] : 'upload';
    $mime = isset( $options['mime'] ) && $options['mime'] ? $options['mime'] : 'application/octet-stream';
    $metadata = isset( $options['metadata'] ) && is_array( $options['metadata'] ) ? $options['metadata'] : [];

    if ( !$path || !file_exists( $path ) ) {
      throw new Exception( 'Uploaded file is missing or unreadable.' );
    }

    // Upload to OpenAI's Files API with purpose=user_data — the correct purpose for
    // vector store ingestion of arbitrary user files. (purpose=assistants is the legacy
    // path used by add_vector for plain-text chunks and stays untouched.)
    $file_upload_response = $this->multipart_upload_file( $path, $filename, $mime, 'user_data' );
    $file_id = $file_upload_response['id'] ?? null;
    $bytes = isset( $file_upload_response['bytes'] ) ? (int) $file_upload_response['bytes'] : null;
    if ( empty( $file_id ) ) {
      throw new Exception( 'OpenAI did not return a file_id for the upload.' );
    }

    // Attach the file to the vector store. OpenAI returns the file's current ingestion
    // status (in_progress / completed / cancelled / failed) so the caller can decide
    // whether to poll.
    $body = [ 'file_id' => $file_id ];
    $attributes = $this->build_attributes( $metadata );
    if ( !empty( $attributes ) ) {
      $body['attributes'] = $attributes;
    }
    $res = $this->run( 'POST', "/vector_stores/{$this->store_id}/files", $body );
    if ( empty( $res ) || empty( $res['id'] ) ) {
      throw new Exception( 'Failed to attach the uploaded file to the vector store.' );
    }

    return [
      'file_id' => $file_id,
      'status' => $res['status'] ?? 'in_progress',
      'bytes' => $bytes,
      'last_error' => $res['last_error'] ?? null,
    ];
  }

  // List every file currently attached to the configured Vector Store, with the
  // metadata needed to materialize it as a local row: file_id, filename, bytes,
  // status. Used by the Sync action so files uploaded via the OpenAI Platform
  // (or via API outside this plugin) become visible in the Documents tab.
  // Returns an array of files, or false if this env isn't ours.
  public function sync_remote_files( $result, $options ) {
    if ( !empty( $result ) ) {
      return $result;
    }
    if ( empty( $options['envId'] ) ) {
      return false;
    }
    if ( !$this->init_settings( $options['envId'] ) ) {
      return false;
    }

    $files = [];
    $after = null;
    // OpenAI paginates at 100 per page; keep going until there's no more.
    do {
      $url = "/vector_stores/{$this->store_id}/files?limit=100";
      if ( $after ) {
        $url .= '&after=' . rawurlencode( $after );
      }
      $res = $this->run( 'GET', $url );
      $items = isset( $res['data'] ) && is_array( $res['data'] ) ? $res['data'] : [];
      if ( empty( $items ) ) {
        break;
      }
      foreach ( $items as $item ) {
        $fileId = $item['id'] ?? null;
        if ( !$fileId ) {
          continue;
        }
        // /vector_stores/{id}/files only carries store-level metadata. The filename
        // and byte count live on the underlying /files/{id} record, so fetch that
        // too. We swallow per-file errors so a single bad row doesn't break sync.
        $filename = null;
        $bytes = null;
        try {
          $info = $this->run( 'GET', "/files/{$fileId}" );
          $filename = $info['filename'] ?? null;
          $bytes = isset( $info['bytes'] ) ? (int) $info['bytes'] : null;
        }
        catch ( Exception $e ) {
          Meow_MWAI_Logging::log( "OpenAI Vector Store: could not fetch /files/{$fileId} during sync (" . $e->getMessage() . ')' );
        }
        $files[] = [
          'file_id' => $fileId,
          'filename' => $filename,
          'bytes' => $bytes,
          'status' => $item['status'] ?? 'in_progress',
          'last_error' => $item['last_error'] ?? null,
        ];
      }
      // Pagination cursor — OpenAI returns has_more + last_id.
      $hasMore = !empty( $res['has_more'] );
      $after = $hasMore ? ( $res['last_id'] ?? null ) : null;
    } while ( $after );

    return $files;
  }

  // Re-fetch the current status of a vector store file. Used by the UI to poll while
  // OpenAI processes large uploads. Returns [ 'status', 'last_error' ] or false.
  public function refresh_file_status( $result, $options ) {
    if ( !empty( $result ) ) {
      return $result;
    }
    if ( empty( $options['envId'] ) || empty( $options['fileId'] ) ) {
      return false;
    }
    if ( !$this->init_settings( $options['envId'] ) ) {
      return false;
    }
    $file_id = $options['fileId'];
    $res = $this->run( 'GET', "/vector_stores/{$this->store_id}/files/{$file_id}" );
    if ( empty( $res ) ) {
      return false;
    }
    return [
      'status' => $res['status'] ?? 'in_progress',
      'last_error' => $res['last_error'] ?? null,
    ];
  }

  // Convert a vector metadata array into OpenAI Vector Store file attributes.
  // OpenAI accepts up to 16 attribute keys; values must be strings, numbers, or booleans;
  // string values are capped at 512 chars.
  // Reference: https://platform.openai.com/docs/api-reference/vector-stores-files/createFile
  private function build_attributes( $metadata ) {
    $attributes = [];
    if ( !is_array( $metadata ) ) {
      return $attributes;
    }
    if ( !empty( $metadata['source'] ) ) {
      $attributes['source'] = mb_substr( (string) $metadata['source'], 0, 512 );
    }
    if ( isset( $metadata['partIndex'] ) && $metadata['partIndex'] !== null ) {
      $attributes['partIndex'] = (int) $metadata['partIndex'];
    }
    if ( isset( $metadata['partTotal'] ) && $metadata['partTotal'] !== null ) {
      $attributes['partTotal'] = (int) $metadata['partTotal'];
    }
    if ( !empty( $metadata['meta'] ) && is_array( $metadata['meta'] ) ) {
      foreach ( $metadata['meta'] as $k => $v ) {
        if ( !is_string( $k ) || $v === null ) {
          continue;
        }
        if ( count( $attributes ) >= 16 ) {
          Meow_MWAI_Logging::log( "OpenAI Vector Store: dropping attribute '{$k}' (exceeds 16-attribute limit)." );
          break;
        }
        if ( is_bool( $v ) || is_int( $v ) || is_float( $v ) ) {
          $attributes[ $k ] = $v;
        }
        elseif ( is_scalar( $v ) ) {
          $attributes[ $k ] = mb_substr( (string) $v, 0, 512 );
        }
      }
    }
    return $attributes;
  }

  // Upload a file to OpenAI's /files endpoint. Generic multipart helper used by both
  // the legacy text-chunk path (add_vector — purpose=assistants, text/plain) and the
  // direct file-upload path (upload_raw_file — purpose=user_data, real MIME).
  private function multipart_upload_file( $file_path, $filename, $mime_type, $purpose ) {
    global $wp_filter;

    $boundary = wp_generate_password( 24, false );
    $file_contents = file_get_contents( $file_path );

    $body = "--{$boundary}\r\n";
    $body .= "Content-Disposition: form-data; name=\"purpose\"\r\n\r\n";
    $body .= "{$purpose}\r\n";
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\n";
    $body .= "Content-Type: {$mime_type}\r\n\r\n";
    $body .= $file_contents . "\r\n";
    $body .= "--{$boundary}--\r\n";

    // Temporarily remove ALL http_api_curl hooks to prevent streaming hook interference.
    // NOTE: load-bearing — leaving streaming hooks in place breaks the multipart body.
    $saved_hooks = null;
    if ( isset( $wp_filter['http_api_curl'] ) ) {
      $saved_hooks = $wp_filter['http_api_curl'];
      unset( $wp_filter['http_api_curl'] );
    }

    $response = wp_remote_post( 'https://api.openai.com/v1/files', [
      'headers' => [
        'Authorization' => 'Bearer ' . $this->apiKey,
        'Content-Type' => 'multipart/form-data; boundary=' . $boundary
      ],
      'body' => $body,
      'timeout' => MWAI_TIMEOUT,
      'sslverify' => MWAI_SSL_VERIFY
    ] );

    // Restore hooks
    if ( $saved_hooks !== null ) {
      $wp_filter['http_api_curl'] = $saved_hooks;
    }

    if ( is_wp_error( $response ) ) {
      throw new Exception( $response->get_error_message() );
    }

    $response_body = wp_remote_retrieve_body( $response );
    $data = json_decode( $response_body, true );

    if ( isset( $data['error'] ) ) {
      throw new Exception( $data['error']['message'] ?? 'Unknown OpenAI error' );
    }

    return $data;
  }

  // Backwards-compatible wrapper preserving the historical add_vector() byte shape:
  // purpose=assistants, filename forced to "<title>.txt", Content-Type: text/plain.
  private function upload_file( $file_path, $filename ) {
    return $this->multipart_upload_file( $file_path, $filename . '.txt', 'text/plain', 'assistants' );
  }

  // Query vectors from OpenAI Vector Store using the search API.
  public function query_vectors( $vectors, $vector, $options ) {
    if ( !empty( $vectors ) ) {
      return $vectors;
    }
    $envId = $options['envId'];
    if ( !$this->init_settings( $envId ) ) {
      return false;
    }

    // Check if direct integration will be used (file_search tool in Responses API)
    // This happens when the query's AI environment matches our OpenAI environment
    $query = isset( $options['query'] ) ? $options['query'] : null;

    if ( $query && $query instanceof Meow_MWAI_Query_Text && $query->envId === $this->openai_env_id ) {
      // Check if model supports Responses API using the engine's model info
      try {
        $engine = Meow_MWAI_Engines_Factory::get( $this->core, $query->envId );
        $modelInfo = $engine->retrieve_model_info( $query->model );

        $supportsResponsesApi = $modelInfo && !empty( $modelInfo['tags'] ) && in_array( 'responses', $modelInfo['tags'] );

        if ( $supportsResponsesApi ) {
          // Direct integration will be used - return empty to skip local embeddings
          return [];
        }
      }
      catch ( Exception $e ) {
        // If we can't get the engine or model info, fall back to local embeddings
        Meow_MWAI_Logging::error( 'Failed to check model capabilities: ' . $e->getMessage() );
      }
    }

    // Use the OpenAI Vector Store search API to find relevant vectors
    try {
      // For Vector Store, we need the original text query, not embeddings
      // The $vector parameter contains embeddings when called from context_search
      // We need to get the original query text from the options
      $searchQuery = '';

      if ( isset( $options['query'] ) && is_object( $options['query'] ) ) {
        // Get the message from the query object
        if ( method_exists( $options['query'], 'get_message' ) ) {
          $searchQuery = $options['query']->get_message();
        }
        elseif ( property_exists( $options['query'], 'message' ) ) {
          $searchQuery = $options['query']->message;
        }
      }

      // If we still don't have a query, check if we have searchQuery in options
      if ( empty( $searchQuery ) && isset( $options['searchQuery'] ) ) {
        $searchQuery = $options['searchQuery'];
      }

      // For OpenAI Vector Store, the search query is passed as the second parameter
      if ( empty( $searchQuery ) && is_string( $vector ) ) {
        $searchQuery = $vector;
      }

      if ( empty( $searchQuery ) ) {
        Meow_MWAI_Logging::error( 'No search query text available for Vector Store search' );
        return [];
      }

      $body = [
        'query' => $searchQuery,
        'max_num_results' => $this->maxSelect,
        'rewrite_query' => true // Enable query rewriting for better search results
      ];

      $res = $this->run( 'POST', "/vector_stores/{$this->store_id}/search", $body );

      if ( !isset( $res['data'] ) || !is_array( $res['data'] ) ) {
        Meow_MWAI_Logging::error( 'Vector Store search returned no data' );
        return [];
      }

      // Map the search results to our expected format
      global $wpdb;
      $table_vectors = $wpdb->prefix . 'mwai_vectors';
      $results = [];

      foreach ( $res['data'] as $searchResult ) {
        $fileId = $searchResult['file_id'];
        $score = isset( $searchResult['score'] ) ? $searchResult['score'] : 0.5;

        // Find the local vector ID based on the dbId (file_id)
        $localVectorId = $wpdb->get_var( $wpdb->prepare(
          "SELECT id FROM {$table_vectors} WHERE dbId = %s AND envId = %s",
          $fileId,
          $envId
        ) );

        if ( $localVectorId ) {
          $results[] = [
            'id' => $fileId,  // Use dbId as the id (this is what embeddings.php expects)
            'score' => $score
          ];
        }
        else {
          // If we don't have a local record, still include it but log a warning
          Meow_MWAI_Logging::warn( "Vector Store file {$fileId} not found in local database" );
          $results[] = [
            'id' => $fileId,
            'score' => $score
          ];
        }
      }

      return $results;

    }
    catch ( Exception $e ) {
      Meow_MWAI_Logging::error( 'Vector Store search failed: ' . $e->getMessage() );

      // Fall back to returning all vectors if search fails
      global $wpdb;
      $table_vectors = $wpdb->prefix . 'mwai_vectors';

      $query = $wpdb->prepare(
        "SELECT id, dbId FROM {$table_vectors} 
         WHERE envId = %s AND dbId IS NOT NULL AND status = 'ok'
         ORDER BY updated DESC
         LIMIT %d",
        $envId,
        $this->maxSelect
      );

      $localVectors = $wpdb->get_results( $query, ARRAY_A );

      if ( empty( $localVectors ) ) {
        return [];
      }

      $results = [];
      $baseScore = 0.9;
      $scoreDecrement = 0.05;

      foreach ( $localVectors as $index => $localVector ) {
        $score = max( 0.1, $baseScore - ( $index * $scoreDecrement ) );
        $results[] = [
          'id' => $localVector['dbId'],
          'score' => $score
        ];
      }

      return $results;
    }
  }

  // Get a vector from OpenAI Vector Store.
  public function get_vector( $vector, $vectorId, $envId, $options ) {
    if ( !empty( $vector ) ) {
      return $vector;
    }
    if ( !$this->init_settings( $envId ) ) {
      return false;
    }

    try {
      // Get file details from vector store
      $res = $this->run( 'GET', "/vector_stores/{$this->store_id}/files/{$vectorId}" );

      if ( isset( $res['id'] ) ) {
        // Only files we uploaded ourselves (purpose=user_data/assistants_output) are downloadable.
        // Files attached with purpose=assistants are rejected by /files/{id}/content, so we skip
        // them rather than persisting the JSON error body as the embedding content.
        $fileInfo = null;
        try {
          $fileInfo = $this->run( 'GET', "/files/{$res['id']}" );
        }
        catch ( Exception $e ) {
          Meow_MWAI_Logging::error( 'Failed to fetch file metadata: ' . $e->getMessage() );
        }
        $purpose = is_array( $fileInfo ) ? ( $fileInfo['purpose'] ?? '' ) : '';
        if ( $purpose === 'assistants' ) {
          return null;
        }

        $file_content = $this->run( 'GET', "/files/{$res['id']}/content", null, false );

        return [
          'id' => $res['id'],
          'content' => $file_content,
          'metadata' => $res
        ];
      }
    }
    catch ( Exception $e ) {
      Meow_MWAI_Logging::error( 'Failed to get vector: ' . $e->getMessage() );
    }

    return null;
  }
}
