<?php

class MeowPro_MWAI_Addons_Chroma {
  private $core = null;

  // Current Vector DB
  private $env = null;
  private $apiKey = null;
  private $server = null;
  private $tenant = null;
  private $database = null;
  private $collection = null;
  private $maxSelect = 10;

  public function __construct() {
    global $mwai_core;
    $this->core = $mwai_core;
    // init_settings is invoked lazily by each filter handler with the per-call envId.
    add_filter( 'mwai_embeddings_list_vectors', [ $this, 'list_vectors' ], 10, 2 );
    add_filter( 'mwai_embeddings_add_vector', [ $this, 'add_vector' ], 10, 3 );
    add_filter( 'mwai_embeddings_get_vector', [ $this, 'get_vector' ], 10, 4 );
    add_filter( 'mwai_embeddings_query_vectors', [ $this, 'query_vectors' ], 10, 4 );
    add_filter( 'mwai_embeddings_delete_vectors', [ $this, 'delete_vectors' ], 10, 2 );
    add_filter( 'mwai_chroma_generate_embedding', [ $this, 'generate_embedding_handler' ], 10, 3 );
  }

  public function init_settings( $envId = null ) {
    $envId = $envId ?? $this->core->get_option( 'embeddings_env' );
    $this->env = $this->core->get_embeddings_env( $envId );

    // This class has only Chroma support.
    if ( empty( $this->env ) || $this->env['type'] !== 'chroma' ) {
      return false;
    }

    $this->apiKey = isset( $this->env['apikey'] ) ? $this->env['apikey'] : null;

    // Trim the server URL
    $server = isset( $this->env['server'] ) && !empty( $this->env['server'] ) ? $this->env['server'] : 'https://api.trychroma.com';
    $this->server = rtrim( trim( $server ), '/' );

    $this->tenant = isset( $this->env['tenant'] ) && !empty( $this->env['tenant'] ) ? $this->env['tenant'] : null;
    $this->database = isset( $this->env['database'] ) && !empty( $this->env['database'] ) ? $this->env['database'] : 'default_database';
    $this->collection = isset( $this->env['collection'] ) && !empty( $this->env['collection'] )
      ? $this->env['collection']
      : 'mwai';
    $this->maxSelect = isset( $this->env['max_select'] ) ? (int) $this->env['max_select'] : 10;
    return true;
  }

  // Generic function to run a request to Chroma.
  public function run( $method, $url, $query = null, $json = true, $isAbsoluteUrl = false ) {
    // Detect if this is Chroma Cloud based on the server URL
    $isChromaCloud = strpos( $this->server, 'trychroma.com' ) !== false || strpos( $this->server, 'chroma.com' ) !== false;

    $headers = [
      'Accept' => 'application/json',
      'Content-Type' => 'application/json'
    ];

    if ( $isChromaCloud ) {
      // Chroma Cloud uses special headers
      if ( $this->apiKey ) {
        $headers['X-Chroma-Token'] = $this->apiKey;
      }
      if ( $this->tenant ) {
        $headers['X-Chroma-Tenant'] = $this->tenant;
      }
    }
    else {
      // Self-hosted uses Bearer token
      if ( $this->apiKey ) {
        $headers['Authorization'] = 'Bearer ' . $this->apiKey;
      }
    }

    $body = $query ? json_encode( $query ) : null;

    // Construct URL based on the server type
    if ( !$isAbsoluteUrl ) {
      // Both Chroma Cloud and self-hosted now use v2 API
      $tenant = $this->tenant ?: 'default_tenant';
      $url = $this->server . "/api/v2/tenants/{$tenant}/databases/{$this->database}" . $url;
    }

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

      // Check HTTP status code
      $httpCode = wp_remote_retrieve_response_code( $response );
      $responseBody = wp_remote_retrieve_body( $response );

      // Handle error responses
      if ( $httpCode >= 400 ) {
        $errorData = json_decode( $responseBody, true );
        $errorMsg = isset( $errorData['detail'] ) ? $errorData['detail'] :
                   ( isset( $errorData['message'] ) ? $errorData['message'] :
                   ( isset( $errorData['error'] ) ? $errorData['error'] : $responseBody ) );
        throw new Exception( $errorMsg );
      }

      $data = $responseBody === '' ? true : ( $json ? json_decode( $responseBody, true ) : $responseBody );
      if ( !is_array( $data ) && empty( $data ) && is_string( $responseBody ) ) {
        throw new Exception( $responseBody );
      }
      return $data;
    }
    catch ( Exception $e ) {
      Meow_MWAI_Logging::error( 'Chroma: ' . $e->getMessage() );
      throw new Exception( $e->getMessage() . ' (Chroma)' );
    }
    return [];
  }

  // List all vectors from Chroma.
  public function list_vectors( $vectors, $options ) {
    if ( !empty( $vectors ) ) {
      return $vectors;
    }
    $envId = $options['envId'];
    $limit = $options['limit'];
    if ( !$this->init_settings( $envId ) ) {
      return false;
    }

    try {
      // Get collection ID first
      $collectionId = $this->get_collection_id( $this->collection );

      // Get documents from the collection
      $body = [ 'limit' => $limit ?: 1000 ];
      $res = $this->run( 'POST', "/collections/{$collectionId}/get", $body );

      // Extract IDs from the response
      $vectors = isset( $res['ids'] ) ? $res['ids'] : [];

      return $vectors;
    }
    catch ( Exception $e ) {
      // If collection doesn't exist, return empty array
      return [];
    }
  }

  // Delete vectors from Chroma.
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

    try {
      // Get collection ID
      $collectionId = $this->get_collection_id( $this->collection );

      if ( $deleteAll ) {
        // For delete all, we need to get all IDs first, then delete them
        $allVectors = $this->list_vectors( [], [ 'envId' => $envId, 'limit' => null ] );
        if ( empty( $allVectors ) ) {
          return true; // Nothing to delete
        }
        $ids = $allVectors;
      }

      if ( !empty( $ids ) ) {
        $body = [ 'ids' => array_values( $ids ) ];
        $this->run( 'POST', "/collections/{$collectionId}/delete", $body );
      }

      return true;
    }
    catch ( Exception $e ) {
      // If collection doesn't exist, consider it a success
      return true;
    }
  }

  // Add a vector to Chroma.
  public function add_vector( $success, $vector, $options ) {
    if ( $success ) {
      return $success;
    }
    $envId = $options['envId'];
    if ( !$this->init_settings( $envId ) ) {
      return false;
    }

    try {
      $collectionId = $this->ensure_collection_exists();
      $randomId = $this->get_uuid();

      $metadata = isset( $vector['_metadata'] ) && is_array( $vector['_metadata'] ) ? $vector['_metadata'] : [];
      $chromaMetadata = [
        'type' => $vector['type'],
        'title' => isset( $metadata['title'] ) ? $metadata['title'] : $vector['title'],
        'model' => $vector['model']
      ];
      if ( !empty( $metadata['source'] ) ) {
        $chromaMetadata['source'] = (string) $metadata['source'];
      }
      if ( isset( $metadata['partIndex'] ) && $metadata['partIndex'] !== null ) {
        $chromaMetadata['partIndex'] = (int) $metadata['partIndex'];
      }
      if ( isset( $metadata['partTotal'] ) && $metadata['partTotal'] !== null ) {
        $chromaMetadata['partTotal'] = (int) $metadata['partTotal'];
      }
      if ( !empty( $metadata['meta'] ) && is_array( $metadata['meta'] ) ) {
        // Chroma rejects nested objects; only scalar values are accepted.
        foreach ( $metadata['meta'] as $k => $v ) {
          if ( is_string( $k ) && is_scalar( $v ) && $v !== null ) {
            $chromaMetadata[$k] = $v;
          }
        }
      }

      $body = [
        'ids' => [ $randomId ],
        'embeddings' => [ $vector['embedding'] ],
        'metadatas' => [ $chromaMetadata ]
      ];

      // Add document content if available
      if ( !empty( $vector['content'] ) ) {
        $body['documents'] = [ $vector['content'] ];
      }

      $res = $this->run( 'POST', "/collections/{$collectionId}/add", $body );

      // Check if the response contains an error
      if ( isset( $res['error'] ) ) {
        $errorType = $res['error'];
        $errorMsg = isset( $res['message'] ) ? $res['message'] : 'Unknown error';

        // Check if this is a dimension mismatch error
        if ( strpos( $errorMsg, 'dimension' ) !== false ) {
          $dimensions = count( $vector['embedding'] );
          throw new Exception( "Dimension mismatch: This embedding has {$dimensions} dimensions. {$errorMsg}" );
        }

        throw new Exception( "{$errorType}: {$errorMsg}" );
      }

      // Chroma v2 returns success without returning the IDs
      return $randomId;
    }
    catch ( Exception $e ) {
      throw new Exception( 'Failed to add vector to Chroma: ' . $e->getMessage() );
    }
  }

  // Query vectors from Chroma.
  public function query_vectors( $vectors, $vector, $options ) {
    if ( !empty( $vectors ) ) {
      return $vectors;
    }
    $envId = $options['envId'];
    if ( !$this->init_settings( $envId ) ) {
      return false;
    }

    try {
      // Get collection ID
      $collectionId = $this->get_collection_id( $this->collection );

      $body = [
        'query_embeddings' => [ $vector ],
        'n_results' => $this->maxSelect
      ];

      $res = $this->run( 'POST', "/collections/{$collectionId}/query", $body );

      // Chroma returns results in a nested array format
      if ( isset( $res['ids'] ) && isset( $res['ids'][0] ) ) {
        $vectors = [];
        $ids = $res['ids'][0];
        $distances = isset( $res['distances'][0] ) ? $res['distances'][0] : [];
        $metadatas = isset( $res['metadatas'][0] ) ? $res['metadatas'][0] : [];
        $documents = isset( $res['documents'][0] ) ? $res['documents'][0] : [];

        // Format results to match expected structure
        for ( $i = 0; $i < count( $ids ); $i++ ) {
          // Chroma uses cosine distance (0 = identical, 2 = opposite)
          // Convert to similarity score (1 = identical, 0 = opposite)
          $distance = isset( $distances[$i] ) ? $distances[$i] : 0;
          $score = 1 - ( $distance / 2 );

          $vectors[] = [
            'id' => $ids[$i],
            'score' => $score,
            'metadata' => isset( $metadatas[$i] ) ? $metadatas[$i] : [],
            'document' => isset( $documents[$i] ) ? $documents[$i] : ''
          ];
        }
      }

      return $vectors;
    }
    catch ( Exception $e ) {
      // If collection doesn't exist, return empty array
      return [];
    }
  }

  // Get a vector from Chroma.
  public function get_vector( $vector, $vectorId, $envId, $options ) {
    // Check if the filter has been already handled.
    if ( !empty( $vector ) ) {
      return $vector;
    }
    if ( !$this->init_settings( $envId ) ) {
      return false;
    }

    try {
      // Get collection ID
      $collectionId = $this->get_collection_id( $this->collection );

      // Query for the specific vector
      $body = [ 'ids' => [ $vectorId ] ];

      $res = $this->run( 'POST', "/collections/{$collectionId}/get", $body );

      if ( isset( $res['ids'] ) && in_array( $vectorId, $res['ids'] ) ) {
        $index = array_search( $vectorId, $res['ids'] );
        $metadata = isset( $res['metadatas'][$index] ) ? $res['metadatas'][$index] : [];

        return [
          'id' => $vectorId,
          'type' => isset( $metadata['type'] ) ? $metadata['type'] : 'manual',
          'title' => isset( $metadata['title'] ) ? $metadata['title'] : '',
          'content' => isset( $res['documents'][$index] ) ? $res['documents'][$index] : '',
          'model' => isset( $metadata['model'] ) ? $metadata['model'] : '',
          'values' => isset( $res['embeddings'][$index] ) ? $res['embeddings'][$index] : []
        ];
      }

      return null;
    }
    catch ( Exception $e ) {
      return null;
    }
  }

  // Ensure database exists, create if not.
  private function ensure_database_exists() {
    $tenant = $this->tenant ?: 'default_tenant';

    try {
      // Check if database exists by listing databases
      $url = $this->server . "/api/v2/tenants/{$tenant}/databases/{$this->database}";
      $this->run( 'GET', $url, null, true, true );
      return true;
    }
    catch ( Exception $e ) {
      // Database doesn't exist, create it
      if ( strpos( $e->getMessage(), 'not found' ) !== false ||
           strpos( $e->getMessage(), 'NotFound' ) !== false ) {
        $url = $this->server . "/api/v2/tenants/{$tenant}/databases";
        $this->run( 'POST', $url, [ 'name' => $this->database ], true, true );
        return true;
      }
      throw $e;
    }
  }

  // Ensure collection exists, create if not. Returns the collection ID.
  private function ensure_collection_exists() {
    // First ensure the database exists
    $this->ensure_database_exists();

    try {
      // Try to get collection ID
      return $this->get_collection_id( $this->collection );
    }
    catch ( Exception $e ) {
      // Only create if the error is "not found"
      if ( strpos( $e->getMessage(), 'not found' ) !== false ||
           strpos( $e->getMessage(), 'NotFound' ) !== false ) {
        return $this->create_collection();
      }
      // Re-throw other exceptions
      throw $e;
    }
  }

  // Create a new collection and return its ID
  private function create_collection() {
    $body = [
      'name' => $this->collection
    ];

    // Add metadata if it's not empty
    $metadata = [
      'description' => 'AI Engine Pro vectors',
      'created_by' => 'mwai'
    ];
    if ( !empty( $metadata ) ) {
      $body['metadata'] = $metadata;
    }

    try {
      $res = $this->run( 'POST', '/collections', $body );
      if ( isset( $res['id'] ) ) {
        return $res['id'];
      }
      return $this->get_collection_id( $this->collection );
    }
    catch ( Exception $e ) {
      // Collection might already exist (race condition)
      if ( strpos( $e->getMessage(), 'already exists' ) !== false ||
           strpos( $e->getMessage(), 'UniqueConstraintError' ) !== false ) {
        return $this->get_collection_id( $this->collection );
      }
      throw $e;
    }
  }

  // Get collection ID by name
  private function get_collection_id( $name ) {
    $collections = $this->run( 'GET', '/collections' );
    if ( is_array( $collections ) ) {
      foreach ( $collections as $collection ) {
        if ( isset( $collection['name'] ) && $collection['name'] === $name ) {
          return $collection['id'];
        }
      }
    }
    throw new Exception( "Collection not found: {$name}" );
  }

  // Generate UUID for vector IDs
  private function get_uuid( $len = 32, $strong = true ) {
    $data = openssl_random_pseudo_bytes( $len, $strong );
    $data[6] = chr( ord( $data[6] ) & 0x0f | 0x40 ); // set version to 0100
    $data[8] = chr( ord( $data[8] ) & 0x3f | 0x80 ); // set bits 6-7 to 10
    return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $data ), 4 ) );
  }

  /**
   * Filter handler for generating embeddings using Chroma Cloud
   *
   * @param mixed $result Previous filter result
   * @param string $text Text to embed
   * @param string $envId Environment ID
   * @return array|false Embedding vector or false
   */
  public function generate_embedding_handler( $result, $text, $envId ) {
    // If already handled by another filter, return it
    if ( $result ) {
      return $result;
    }

    // Initialize settings for this environment
    if ( !$this->init_settings( $envId ) ) {
      return false;
    }

    // Check if this environment is configured to use Chroma embeddings
    $embeddings_source = isset( $this->env['embeddings_source'] ) ? $this->env['embeddings_source'] : null;

    // If not set or set to 'ai-engine', don't handle it here
    if ( empty( $embeddings_source ) || $embeddings_source === 'ai-engine' ) {
      return false;
    }

    // Generate embedding using Chroma Cloud
    return $this->generate_embedding( $text, $embeddings_source );
  }

  /**
   * Generate embeddings using Chroma Cloud's embedding API
   *
   * @param string $text Text to embed
   * @param string $model Model identifier (e.g., 'Qwen/Qwen3-Embedding-0.6B')
   * @return array Embedding vector
   * @throws Exception If embedding generation fails
   */
  private function generate_embedding( $text, $model = 'Qwen/Qwen3-Embedding-0.6B' ) {
    if ( empty( $this->apiKey ) ) {
      throw new Exception( 'Chroma API key is required for embedding generation' );
    }

    $url = 'https://embed.trychroma.com';

    // Chroma Cloud embedding API expects an array of texts and returns an array of embeddings
    $body = json_encode( [
      'instructions' => '', // Empty for document embeddings
      'texts' => [ $text ]
    ] );

    $response = wp_remote_post( $url, [
      'headers' => [
        'x-chroma-token' => $this->apiKey,
        'x-chroma-embedding-model' => $model,
        'Content-Type' => 'application/json'
      ],
      'body' => $body,
      'timeout' => 60
    ] );

    if ( is_wp_error( $response ) ) {
      throw new Exception( 'Chroma embedding generation failed: ' . $response->get_error_message() );
    }

    $http_code = wp_remote_retrieve_response_code( $response );
    $response_body = wp_remote_retrieve_body( $response );
    $data = json_decode( $response_body, true );

    if ( $http_code !== 200 ) {
      $error_message = isset( $data['error'] ) ? $data['error'] : 'Unknown error';
      throw new Exception( 'Chroma embedding API error: ' . $error_message );
    }

    if ( !isset( $data['embeddings'][0] ) ) {
      throw new Exception( 'Invalid embedding response from Chroma Cloud' );
    }

    // Log usage for statistics (use actual token count from Chroma)
    $num_tokens = isset( $data['num_tokens'] ) ? $data['num_tokens'] : 0;
    $this->log_embedding_usage( $model, $text, $data['embeddings'][0], $num_tokens );

    return $data['embeddings'][0];
  }

  /**
   * Log embedding usage to statistics
   *
   * @param string $model Model used
   * @param string $text Input text
   * @param array $embedding Generated embedding
   * @param int $num_tokens Actual token count from Chroma API
   */
  private function log_embedding_usage( $model, $text, $embedding, $num_tokens = 0 ) {
    global $mwai_stats;
    if ( !$mwai_stats ) {
      return;
    }

    // Use actual token count from Chroma, or estimate if not provided
    $input_tokens = $num_tokens > 0 ? $num_tokens : intval( strlen( $text ) / 4 );
    $dimensions = count( $embedding );

    // Create a stats object for logging
    $statsObject = new MeowPro_MWAI_Stats();
    $statsObject->scope = 'admin-tools';
    $statsObject->feature = 'embeddings';
    $statsObject->model = $model;
    $statsObject->envId = $this->env['id'] ?? null;
    $statsObject->type = 'tokens';
    $statsObject->units = $input_tokens;
    $statsObject->price = null; // No pricing available for Chroma Cloud embeddings
    $statsObject->stats = [
      'inUnits' => $input_tokens,
      'outUnits' => 0,
      'dimensions' => $dimensions
    ];

    // Record the usage directly
    $mwai_stats->commit_stats( $statsObject );
  }
}
