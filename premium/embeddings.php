<?php

require_once __DIR__ . '/conversation-context-builder.php';

class MeowPro_MWAI_Embeddings {
  private $core = null;
  private $wpdb = null;
  private $db_check = false;
  private $table_vectors = null;
  private $namespace = 'mwai/v1';

  // Embeddings Settings
  private $settings = [];
  private $sync_posts = false;
  private $sync_post_envId = null;
  private $sync_post_types = [];
  private $sync_post_status = [ 'publish' ];
  private $sync_post_categories = [];
  private $sync_post_languages = [];
  private $force_recreate = false;
  private $rewrite_content = false;
  private $rewrite_prompt = false;

  // Remote URL Embeddings Settings
  private $force_remote_recreate = false;
  private $rewrite_remote_content = false;
  private $rewrite_remote_prompt = false;
  private $sync_remote_urls_interval = '24h';

  // Vector DB Settings
  private $default_envId = null;

  public function __construct() {
    global $wpdb, $mwai_core;
    $this->core = $mwai_core;
    $this->wpdb = $wpdb;
    $this->table_vectors = $wpdb->prefix . 'mwai_vectors';

    // Embeddings Services
    new MeowPro_MWAI_Addons_Pinecone();
    new MeowPro_MWAI_Addons_Qdrant();
    new MeowPro_MWAI_Addons_OaiVectorStore();
    new MeowPro_MWAI_Addons_Chroma();

    $this->default_envId = $this->core->get_option( 'embeddings_default_env' );
    $this->settings = $this->core->get_option( 'embeddings' );
    $this->sync_posts = isset( $this->settings['syncPosts'] ) ? $this->settings['syncPosts'] : false;
    $this->sync_post_envId = isset( $this->settings['syncPostsEnvId'] ) ? $this->settings['syncPostsEnvId'] : null;
    $this->sync_post_types = isset( $this->settings['syncPostTypes'] ) ? $this->settings['syncPostTypes'] : [];
    $this->sync_post_status = isset( $this->settings['syncPostStatus'] ) ? $this->settings['syncPostStatus'] : [ 'publish' ];
    $this->sync_post_categories = isset( $this->settings['syncPostCategories'] ) ? $this->settings['syncPostCategories'] : [];
    $this->sync_post_languages = isset( $this->settings['syncPostLanguages'] ) ? $this->settings['syncPostLanguages'] : [];
    $this->force_recreate = isset( $this->settings['forceRecreate'] ) ? $this->settings['forceRecreate'] : false;
    $this->rewrite_content = isset( $this->settings['rewriteContent'] ) ? $this->settings['rewriteContent'] : false;
    $this->rewrite_prompt = isset( $this->settings['rewritePrompt'] ) ? $this->settings['rewritePrompt'] : false;

    // Remote URL Embeddings Settings
    $this->force_remote_recreate = isset( $this->settings['forceRemoteRecreate'] ) ? $this->settings['forceRemoteRecreate'] : false;
    $this->rewrite_remote_content = isset( $this->settings['rewriteRemoteContent'] ) ? $this->settings['rewriteRemoteContent'] : false;
    $default_remote_prompt = "Extract and clean the main content from this web page. Remove all website elements that are not part of the actual content: navigation menus, headers, footers, sidebars, breadcrumbs, ads, cookie notices, social sharing buttons, and related links sections.\n\nKeep only the primary content of the page. Use minimal formatting:\n- Use line breaks to separate distinct sections\n- Use simple lists (with dashes) only when the original has clear list items\n- For products or items: keep name, price, and key details on separate lines\n- Do not add markdown headers, bold, or other rich formatting\n\nThe result should be clean, readable text that preserves the essential information and structure. Keep it concise (under 1000 words). Output only the cleaned content, nothing else.\n\n{CONTENT}";
    $this->rewrite_remote_prompt = !empty( $this->settings['rewriteRemotePrompt'] ) ? $this->settings['rewriteRemotePrompt'] : $default_remote_prompt;
    $this->sync_remote_urls_interval = isset( $this->settings['syncRemoteUrlsInterval'] ) ? $this->settings['syncRemoteUrlsInterval'] : '24h';

    // Activate the synchronization only if the sync_post_envId is set.
    $this->sync_posts = $this->sync_posts && !empty( $this->sync_post_envId );

    // AI Engine Filters
    add_filter( 'mwai_context_search', [ $this, 'context_search' ], 10, 3 );
    add_filter( 'mwai_task_sync_embeddings', [ $this, 'run_tasks' ], 10, 2 );
    add_filter( 'mwai_task_sync_remote_urls', [ $this, 'run_remote_urls_task' ], 10, 2 );

    // WordPress Filters
    add_action( 'rest_api_init', [ $this, 'rest_api_init' ] );
    add_action( 'save_post', [ $this, 'action_save_post' ], 10, 3 );
    if ( $this->sync_posts ) {
      add_action( 'wp_trash_post', [ $this, 'action_delete_post' ] );
    }

    // Library Search: AI-powered Media Library search
    if ( $this->core->get_option( 'module_library_search' ) ) {
      add_action( 'pre_get_posts', [ $this, 'library_search_pre_get_posts' ] );
      add_filter( 'ajax_query_attachments_args', [ $this, 'library_search_ajax_attachments' ] );
      add_action( 'admin_enqueue_scripts', [ $this, 'library_search_enqueue' ] );
    }

    // Register embeddings sync task
    add_action( 'init', [ $this, 'register_task' ], 25 );
  }

  #region REST API

  public function rest_api_init() {
    try {
      // Vectors
      register_rest_route( $this->namespace, '/vectors/list', [
        'methods' => 'POST',
        'permission_callback' => [ $this->core, 'can_access_settings' ],
        'callback' => [ $this, 'rest_vectors_list' ],
      ] );
      register_rest_route( $this->namespace, '/vectors/add', [
        'methods' => 'POST',
        'permission_callback' => [ $this->core, 'can_access_settings' ],
        'callback' => [ $this, 'rest_vectors_add' ],
      ] );
      register_rest_route( $this->namespace, '/vectors/add_from_remote', [
        'methods' => 'POST',
        'permission_callback' => [ $this->core, 'can_access_settings' ],
        'callback' => [ $this, 'rest_vectors_add_from_remote' ],
      ] );
      register_rest_route( $this->namespace, '/vectors/ref', [
        'methods' => 'POST',
        'permission_callback' => [ $this->core, 'can_access_settings' ],
        'callback' => [ $this, 'rest_vectors_by_ref' ],
      ] );
      register_rest_route( $this->namespace, '/vectors/update', [
        'methods' => 'POST',
        'permission_callback' => [ $this->core, 'can_access_settings' ],
        'callback' => [ $this, 'rest_vectors_update' ],
      ] );
      register_rest_route( $this->namespace, '/vectors/sync', [
        'methods' => 'POST',
        'permission_callback' => [ $this->core, 'can_access_settings' ],
        'callback' => [ $this, 'rest_vectors_sync' ],
      ] );
      register_rest_route( $this->namespace, '/vectors/delete', [
        'methods' => 'POST',
        'permission_callback' => [ $this->core, 'can_access_settings' ],
        'callback' => [ $this, 'rest_vectors_delete' ],
      ] );
      // OpenAI Vector Store: hand a raw file straight to OpenAI (no local chunking).
      // Multipart body: `file` + `envId`. Only valid when the env is type=openai-vector-store.
      register_rest_route( $this->namespace, '/vectors/upload_file', [
        'methods' => 'POST',
        'permission_callback' => [ $this->core, 'can_access_settings' ],
        'callback' => [ $this, 'rest_vectors_upload_file' ],
      ] );
      // Poll OpenAI Vector Store for a file's ingestion status. Updates local row when
      // OpenAI flips from in_progress to completed/failed.
      register_rest_route( $this->namespace, '/vectors/refresh_status', [
        'methods' => 'POST',
        'permission_callback' => [ $this->core, 'can_access_settings' ],
        'callback' => [ $this, 'rest_vectors_refresh_status' ],
      ] );
      // Pull the file list from OpenAI Vector Store and create local rows for any
      // files we don't yet know about. Used after uploading directly via the OpenAI
      // platform (e.g. for files too large for this server's PHP limits).
      register_rest_route( $this->namespace, '/vectors/sync_remote_files', [
        'methods' => 'POST',
        'permission_callback' => [ $this->core, 'can_access_settings' ],
        'callback' => [ $this, 'rest_vectors_sync_remote_files' ],
      ] );
      // DEPRECATED: Chunking is now done client-side for better performance
      // Keeping endpoint for backward compatibility but it should not be used
      // register_rest_route( $this->namespace, '/vectors/chunk', [
      //   'methods' => 'POST',
      //   'permission_callback' => [ $this->core, 'can_access_settings' ],
      //   'callback' => [ $this, 'rest_vectors_chunk' ],
      // ] );
      register_rest_route( $this->namespace, '/vectors/delete_all', [
        'methods' => 'POST',
        'permission_callback' => [ $this->core, 'can_access_settings' ],
        'callback' => [ $this, 'rest_vectors_delete_all' ],
      ] );
      register_rest_route( $this->namespace, '/vectors/remote_list', [
        'methods' => 'POST',
        'permission_callback' => [ $this->core, 'can_access_settings' ],
        'callback' => [ $this, 'rest_vectors_remote_list' ],
      ] );
      register_rest_route( $this->namespace, '/vectors/fetch_titles', [
        'methods' => 'POST',
        'permission_callback' => [ $this->core, 'can_access_settings' ],
        'callback' => [ $this, 'rest_vectors_fetch_titles' ],
      ] );
      register_rest_route( $this->namespace, '/embeddings/test_pinecone', [
        'methods' => 'POST',
        'permission_callback' => [ $this->core, 'can_access_settings' ],
        'callback' => [ $this, 'rest_test_pinecone' ],
      ] );
      register_rest_route( $this->namespace, '/embeddings/test_chroma', [
        'methods' => 'POST',
        'permission_callback' => [ $this->core, 'can_access_settings' ],
        'callback' => [ $this, 'rest_test_chroma' ],
      ] );
      register_rest_route( $this->namespace, '/embeddings/test_qdrant', [
        'methods' => 'POST',
        'permission_callback' => [ $this->core, 'can_access_settings' ],
        'callback' => [ $this, 'rest_test_qdrant' ],
      ] );
      register_rest_route( $this->namespace, '/embeddings/chroma_cloud_identity', [
        'methods' => 'POST',
        'permission_callback' => [ $this->core, 'can_access_settings' ],
        'callback' => [ $this, 'rest_chroma_cloud_identity' ],
      ] );
      register_rest_route( $this->namespace, '/embeddings/create_openai_vector_store', [
        'methods' => 'POST',
        'permission_callback' => [ $this->core, 'can_access_settings' ],
        'callback' => [ $this, 'rest_create_openai_vector_store' ],
      ] );
      register_rest_route( $this->namespace, '/vectors/ignore', [
        'methods' => 'POST',
        'permission_callback' => [ $this->core, 'can_access_settings' ],
        'callback' => [ $this, 'rest_vectors_ignore' ],
      ] );
      register_rest_route( $this->namespace, '/vectors/unignore', [
        'methods' => 'POST',
        'permission_callback' => [ $this->core, 'can_access_settings' ],
        'callback' => [ $this, 'rest_vectors_unignore' ],
      ] );
      register_rest_route( $this->namespace, '/vectors/ignored', [
        'methods' => 'GET',
        'permission_callback' => [ $this->core, 'can_access_settings' ],
        'callback' => [ $this, 'rest_vectors_ignored' ],
      ] );

    }
    catch ( Exception $e ) {
      var_dump( $e );
    }
  }

  public function rest_vectors_list( $request ) {
    try {
      $params = $request->get_json_params();
      $page = isset( $params['page'] ) ? $params['page'] : null;
      $limit = isset( $params['limit'] ) ? $params['limit'] : null;
      $offset = ( !!$page && !!$limit ) ? ( $page - 1 ) * $limit : 0;
      $filters = isset( $params['filters'] ) ? $params['filters'] : [];
      $sort = isset( $params['sort'] ) ? $params['sort'] : null;

      // If envId is provided at the top level, add it to filters
      if ( isset( $params['envId'] ) && !isset( $filters['envId'] ) ) {
        $filters['envId'] = $params['envId'];
      }

      $vectors = $this->query_vectors( $offset, $limit, $filters, $sort );
      return new WP_REST_Response( [
        'success' => true,
        'total' => $vectors['total'],
        'vectors' => $vectors['rows']
      ], 200 );
    }
    catch ( Exception $e ) {
      return new WP_REST_Response( [ 'success' => false, 'message' => $e->getMessage() ], 500 );
    }
  }

  public function rest_vectors_remote_list( $request ) {
    try {
      $params = $request->get_json_params();
      $page = isset( $params['page'] ) ? $params['page'] : null;
      $limit = isset( $params['limit'] ) ? $params['limit'] : null;
      $offset = ( !!$page && !!$limit ) ? ( $page - 1 ) * $limit : 0;
      $filters = isset( $params['filters'] ) ? $params['filters'] : [];
      $envId = $filters['envId'];

      if ( empty( $envId ) ) {
        throw new Exception( 'The envId is required.' );
      }

      $vectors = apply_filters( 'mwai_embeddings_list_vectors', [], [
        'envId' => $envId,
        'limit' => $limit,
        'offset' => $offset,
      ] );

      // Ensure $vectors is an array (filter could return false)
      if ( !is_array( $vectors ) ) {
        $vectors = [];
      }

      return new WP_REST_Response( [
        'success' => true,
        'total' => count( $vectors ),
        'vectors' => $vectors
      ], 200 );
    }
    catch ( Exception $e ) {
      return new WP_REST_Response( [ 'success' => false, 'message' => $e->getMessage() ], 500 );
    }
  }

  public function rest_vectors_fetch_titles( $request ) {
    try {
      $params = $request->get_json_params();
      $urls = isset( $params['urls'] ) ? $params['urls'] : [];
      if ( empty( $urls ) || !is_array( $urls ) ) {
        throw new Exception( 'A non-empty array of URLs is required.' );
      }
      if ( count( $urls ) > 10 ) {
        $urls = array_slice( $urls, 0, 10 );
      }
      $titles = [];
      foreach ( $urls as $url ) {
        $url = esc_url_raw( $url );
        if ( !wp_http_validate_url( $url ) ) {
          $titles[$url] = null;
          continue;
        }
        $response = wp_safe_remote_get( $url, [
          'timeout' => 10,
          'user-agent' => 'AI Engine WordPress Plugin',
        ] );
        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
          $titles[$url] = null;
          continue;
        }
        $body = wp_remote_retrieve_body( $response );
        if ( preg_match( '/<title[^>]*>(.*?)<\/title>/si', $body, $matches ) ) {
          $titles[$url] = sanitize_text_field( html_entity_decode( $matches[1], ENT_QUOTES, 'UTF-8' ) );
        }
        else {
          $titles[$url] = null;
        }
      }
      return new WP_REST_Response( [ 'success' => true, 'titles' => $titles ], 200 );
    }
    catch ( Exception $e ) {
      return new WP_REST_Response( [ 'success' => false, 'message' => $e->getMessage() ], 500 );
    }
  }

  public function rest_vectors_add_from_remote( $request ) {
    try {
      $params = $request->get_json_params();
      $envId = $params['envId'];
      $dbId = $params['dbId'];
      $metadata = $this->get_vector_metadata_from_remote( $dbId, $envId );
      $title = isset( $metadata['title'] ) ? $metadata['title'] : "Missing Title #$dbId";
      $type = isset( $metadata['type'] ) ? $metadata['type'] : 'manual';
      $refId = isset( $metadata['refId'] ) ? $metadata['refId'] : null;
      $content = isset( $metadata['content'] ) ? $metadata['content'] : '';

      // Check if the postId exists.
      if ( $type === 'postId' ) {
        if ( !$refId ) {
          $type = 'manual';
        }
        else {
          $post = get_post( $refId );
          if ( !$post ) {
            $type = 'manual';
          }
        }
      }

      $status = !empty( $content ) ? 'ok' : 'orphan';

      $vector = [
        'type' => $type,
        'title' => $title,
        'envId' => $envId,
        'dbId' => $dbId,
        'content' => $content,
      ];
      $vector = $this->vectors_add( $vector, $status, true );
      return new WP_REST_Response( [ 'success' => !!$vector, 'vector' => $vector ], 200 );
    }
    catch ( Exception $e ) {
      return new WP_REST_Response( [ 'success' => false, 'message' => $e->getMessage() ], 500 );
    }
  }

  public function rest_vectors_add( $request ) {
    try {
      $params = $request->get_json_params();
      $vector = $params['vector'];

      // Sanitize refUrl if present
      if ( isset( $vector['refUrl'] ) ) {
        $vector['refUrl'] = esc_url_raw( $vector['refUrl'] );
      }

      // Validate remoteUrl type requires refUrl
      if ( isset( $vector['type'] ) && $vector['type'] === 'remoteUrl' && empty( $vector['refUrl'] ) ) {
        return new WP_REST_Response( [
          'success' => false,
          'message' => 'URL is required for Remote URL type'
        ], 400 );
      }

      $options = [ 'envId' => $vector['envId'] ];
      $vector = $this->vectors_add( $vector, $options );
      return new WP_REST_Response( [ 'success' => !!$vector, 'vector' => $vector ], 200 );
    }
    catch ( Exception $e ) {
      return new WP_REST_Response( [ 'success' => false, 'message' => $e->getMessage() ], 500 );
    }
  }

  // Whitelist of file extensions that OpenAI Vector Store accepts for ingestion.
  // Reference: https://platform.openai.com/docs/assistants/tools/file-search/supported-files
  private static $openai_vector_store_extensions = [
    'pdf', 'docx', 'doc', 'pptx', 'xlsx', 'csv',
    'md', 'txt', 'json', 'html', 'htm', 'tex',
    'c', 'cpp', 'cs', 'css', 'go', 'java', 'js', 'ts', 'php', 'py', 'rb', 'sh',
  ];

  public function rest_vectors_upload_file( $request ) {
    try {
      $envId = $request->get_param( 'envId' );
      if ( empty( $envId ) ) {
        return new WP_REST_Response( [ 'success' => false, 'message' => 'envId is required.' ], 400 );
      }
      $env = $this->core->get_embeddings_env( $envId );
      if ( empty( $env ) ) {
        return new WP_REST_Response( [ 'success' => false, 'message' => 'Embeddings environment not found.' ], 400 );
      }
      if ( ( $env['type'] ?? null ) !== 'openai-vector-store' ) {
        return new WP_REST_Response( [
          'success' => false,
          'message' => 'Direct file upload is only supported for OpenAI Vector Store environments.'
        ], 400 );
      }

      $files = $request->get_file_params();
      if ( empty( $files['file'] ) || empty( $files['file']['tmp_name'] ) ) {
        // If $_FILES is empty but the request actually carried a body, the upload almost
        // certainly exceeded post_max_size and PHP silently dropped it before we got a
        // chance to see it. Surface the actual limits so the user knows what to raise.
        $contentLength = isset( $_SERVER['CONTENT_LENGTH'] ) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
        $postMax = $this->ini_size_in_bytes( 'post_max_size' );
        $uploadMax = $this->ini_size_in_bytes( 'upload_max_filesize' );
        $serverMax = min( $postMax ?: PHP_INT_MAX, $uploadMax ?: PHP_INT_MAX );
        if ( $contentLength > 0 && $serverMax > 0 && $contentLength > $serverMax ) {
          $msg = sprintf(
            'File is too large for this server (%s). The PHP limits are upload_max_filesize=%s and post_max_size=%s. Increase both in php.ini (or your hosting control panel) and try again.',
            $this->format_bytes( $contentLength ),
            ini_get( 'upload_max_filesize' ),
            ini_get( 'post_max_size' )
          );
          return new WP_REST_Response( [ 'success' => false, 'message' => $msg ], 413 );
        }
        return new WP_REST_Response( [
          'success' => false,
          'message' => 'No file received. If you tried a large file, check your php.ini limits: upload_max_filesize=' . ini_get( 'upload_max_filesize' ) . ', post_max_size=' . ini_get( 'post_max_size' ) . '.'
        ], 400 );
      }
      $file = $files['file'];
      if ( isset( $file['error'] ) && (int) $file['error'] !== UPLOAD_ERR_OK ) {
        return new WP_REST_Response( [
          'success' => false,
          'message' => $this->describe_upload_error( (int) $file['error'] )
        ], 400 );
      }

      $filename = sanitize_file_name( $file['name'] ?? 'upload' );
      $ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
      if ( !in_array( $ext, self::$openai_vector_store_extensions, true ) ) {
        return new WP_REST_Response( [
          'success' => false,
          'message' => "File type .{$ext} is not supported by OpenAI Vector Store."
        ], 400 );
      }

      $tmp = $file['tmp_name'];
      $mime = function_exists( 'mime_content_type' ) ? mime_content_type( $tmp ) : null;
      if ( empty( $mime ) ) {
        $mime = !empty( $file['type'] ) ? $file['type'] : 'application/octet-stream';
      }
      $bytes = isset( $file['size'] ) ? (int) $file['size'] : ( file_exists( $tmp ) ? filesize( $tmp ) : 0 );
      $checksum = file_exists( $tmp ) ? hash_file( 'sha256', $tmp ) : null;

      // Hand the file to the addon (OpenAI Vector Store) via filter.
      $result = apply_filters( 'mwai_embeddings_upload_file', false, [
        'envId' => $envId,
        'path' => $tmp,
        'filename' => $filename,
        'mime' => $mime,
        'metadata' => [ 'source' => $filename ],
      ] );

      if ( empty( $result ) || empty( $result['file_id'] ) ) {
        return new WP_REST_Response( [
          'success' => false,
          'message' => 'OpenAI did not accept the file. Check the environment configuration.'
        ], 500 );
      }

      $file_id = $result['file_id'];
      $remote_status = $result['status'] ?? 'in_progress';
      $local_status = $this->map_openai_file_status( $remote_status );
      $sizeLabel = $this->format_bytes( $bytes );
      $source = $sizeLabel ? "{$filename} ({$sizeLabel})" : $filename;

      if ( !$this->check_db() ) {
        return new WP_REST_Response( [ 'success' => false, 'message' => 'Database not available.' ], 500 );
      }

      $now = date( 'Y-m-d H:i:s' );
      $inserted = $this->wpdb->insert(
        $this->table_vectors,
        [
          'type' => 'oai_file',
          'title' => $filename,
          'content' => '',
          'refId' => null,
          'refUrl' => null,
          'refChecksum' => $checksum,
          'source' => $source,
          'partIndex' => null,
          'partTotal' => null,
          'envId' => $envId,
          'dbId' => $file_id,
          'status' => $local_status,
          'updated' => $now,
          'created' => $now,
        ]
      );
      if ( !$inserted ) {
        return new WP_REST_Response( [
          'success' => false,
          'message' => 'Failed to record the upload: ' . $this->wpdb->last_error
        ], 500 );
      }

      $vector = $this->get_vector_by_remoteId( $file_id );
      return new WP_REST_Response( [ 'success' => true, 'vector' => $vector ], 200 );
    }
    catch ( Exception $e ) {
      return new WP_REST_Response( [ 'success' => false, 'message' => $e->getMessage() ], 500 );
    }
  }

  public function rest_vectors_refresh_status( $request ) {
    try {
      $params = $request->get_json_params();
      $vectorId = isset( $params['vectorId'] ) ? (int) $params['vectorId'] : 0;
      if ( !$vectorId ) {
        return new WP_REST_Response( [ 'success' => false, 'message' => 'vectorId is required.' ], 400 );
      }
      $row = $this->get_vector( $vectorId );
      if ( empty( $row ) ) {
        return new WP_REST_Response( [ 'success' => false, 'message' => 'Vector not found.' ], 404 );
      }
      if ( empty( $row['dbId'] ) || empty( $row['envId'] ) ) {
        return new WP_REST_Response( [ 'success' => false, 'message' => 'This vector has no remote file to refresh.' ], 400 );
      }
      $result = apply_filters( 'mwai_embeddings_refresh_status', false, [
        'envId' => $row['envId'],
        'fileId' => $row['dbId'],
      ] );
      if ( empty( $result ) || empty( $result['status'] ) ) {
        return new WP_REST_Response( [ 'success' => false, 'message' => 'Could not retrieve status from remote.' ], 500 );
      }
      $local_status = $this->map_openai_file_status( $result['status'] );
      if ( $local_status !== $row['status'] ) {
        $this->wpdb->update(
          $this->table_vectors,
          [ 'status' => $local_status, 'updated' => date( 'Y-m-d H:i:s' ) ],
          [ 'id' => $vectorId ]
        );
        $row = $this->get_vector( $vectorId );
      }
      return new WP_REST_Response( [
        'success' => true,
        'vector' => $row,
        'remote_status' => $result['status'],
        'last_error' => $result['last_error'] ?? null,
      ], 200 );
    }
    catch ( Exception $e ) {
      return new WP_REST_Response( [ 'success' => false, 'message' => $e->getMessage() ], 500 );
    }
  }

  public function rest_vectors_sync_remote_files( $request ) {
    try {
      $params = $request->get_json_params();
      $envId = $params['envId'] ?? null;
      if ( empty( $envId ) ) {
        return new WP_REST_Response( [ 'success' => false, 'message' => 'envId is required.' ], 400 );
      }
      $env = $this->core->get_embeddings_env( $envId );
      if ( ( $env['type'] ?? null ) !== 'openai-vector-store' ) {
        return new WP_REST_Response( [
          'success' => false,
          'message' => 'Remote sync is only supported for OpenAI Vector Store environments.'
        ], 400 );
      }

      $remoteFiles = apply_filters( 'mwai_embeddings_sync_remote_files', false, [ 'envId' => $envId ] );
      if ( $remoteFiles === false || !is_array( $remoteFiles ) ) {
        return new WP_REST_Response( [
          'success' => false,
          'message' => 'Could not retrieve the file list from OpenAI.'
        ], 500 );
      }

      if ( !$this->check_db() ) {
        return new WP_REST_Response( [ 'success' => false, 'message' => 'Database not available.' ], 500 );
      }

      $added = 0;
      $skipped = 0;
      $now = date( 'Y-m-d H:i:s' );
      foreach ( $remoteFiles as $file ) {
        $fileId = $file['file_id'] ?? null;
        if ( !$fileId ) {
          continue;
        }
        $existing = $this->get_vector_by_remoteId( $fileId );
        if ( !empty( $existing ) ) {
          $skipped++;
          continue;
        }
        $filename = !empty( $file['filename'] ) ? $file['filename'] : $fileId;
        $bytes = isset( $file['bytes'] ) ? (int) $file['bytes'] : 0;
        $sizeLabel = $this->format_bytes( $bytes );
        $source = $sizeLabel ? "{$filename} ({$sizeLabel})" : $filename;
        $this->wpdb->insert(
          $this->table_vectors,
          [
            'type' => 'oai_file',
            'title' => $filename,
            'content' => '',
            'refId' => null,
            'refUrl' => null,
            'refChecksum' => null,
            'source' => $source,
            'partIndex' => null,
            'partTotal' => null,
            'envId' => $envId,
            'dbId' => $fileId,
            'status' => $this->map_openai_file_status( $file['status'] ?? 'in_progress' ),
            'updated' => $now,
            'created' => $now,
          ]
        );
        $added++;
      }

      return new WP_REST_Response( [
        'success' => true,
        'added' => $added,
        'skipped' => $skipped,
        'total' => count( $remoteFiles ),
      ], 200 );
    }
    catch ( Exception $e ) {
      return new WP_REST_Response( [ 'success' => false, 'message' => $e->getMessage() ], 500 );
    }
  }

  private function map_openai_file_status( $remote ) {
    switch ( $remote ) {
      case 'completed': return 'ok';
      case 'failed':
      case 'cancelled': return 'error';
      case 'in_progress':
      default: return 'processing';
    }
  }

  // Translate php.ini shorthand notation ("8M", "512K", "1G") to bytes. Returns 0 if
  // the directive is empty or unset.
  private function ini_size_in_bytes( $key ) {
    $raw = trim( (string) ini_get( $key ) );
    if ( $raw === '' ) {
      return 0;
    }
    $unit = strtolower( substr( $raw, -1 ) );
    $value = (int) $raw;
    switch ( $unit ) {
      case 'g': $value *= 1024 * 1024 * 1024; break;
      case 'm': $value *= 1024 * 1024; break;
      case 'k': $value *= 1024; break;
    }
    return $value;
  }

  private function describe_upload_error( $code ) {
    switch ( $code ) {
      case UPLOAD_ERR_INI_SIZE:
        return 'File is larger than the server allows (upload_max_filesize=' . ini_get( 'upload_max_filesize' ) . '). Raise the limit in php.ini and try again.';
      case UPLOAD_ERR_FORM_SIZE:
        return 'File exceeds the MAX_FILE_SIZE limit set by the form.';
      case UPLOAD_ERR_PARTIAL:
        return 'The file was only partially uploaded — likely a network interruption. Try again.';
      case UPLOAD_ERR_NO_FILE:
        return 'No file was uploaded.';
      case UPLOAD_ERR_NO_TMP_DIR:
        return 'Missing temporary folder on the server.';
      case UPLOAD_ERR_CANT_WRITE:
        return 'Could not write the uploaded file to disk.';
      case UPLOAD_ERR_EXTENSION:
        return 'A PHP extension stopped the upload.';
    }
    return 'File upload failed (PHP code ' . $code . ').';
  }

  private function format_bytes( $bytes ) {
    if ( $bytes <= 0 ) {
      return null;
    }
    $units = [ 'B', 'KB', 'MB', 'GB' ];
    $i = 0;
    $val = (float) $bytes;
    while ( $val >= 1024 && $i < count( $units ) - 1 ) {
      $val /= 1024;
      $i++;
    }
    return ( $i === 0 ? (int) $val : number_format( $val, $val >= 10 ? 0 : 1 ) ) . ' ' . $units[ $i ];
  }

  public function rest_vectors_by_ref( $request ) {
    try {
      $params = $request->get_json_params();
      $refId = $params['refId'];
      $vectors = $this->get_vectors_by_refId( $refId );
      return new WP_REST_Response( [ 'success' => true, 'vectors' => $vectors ], 200 );
    }
    catch ( Exception $e ) {
      return new WP_REST_Response( [ 'success' => false, 'message' => $e->getMessage() ], 500 );
    }
  }

  public function rest_vectors_update( $request ) {
    try {
      $params = $request->get_json_params();
      $vector = $params['vector'];
      $vector = $this->update_vector( $vector );
      $success = !empty( $vector );
      return new WP_REST_Response( [ 'success' => $success, 'vector' => $vector ], 200 );
    }
    catch ( Exception $e ) {
      return new WP_REST_Response( [ 'success' => false, 'message' => $e->getMessage() ], 500 );
    }
  }

  public function rest_vectors_sync( $request ) {
    try {
      $params = $request->get_json_params();
      $envId = !empty( $params['envId'] ) ? $params['envId'] : null;
      $vectorId = !empty( $params['vectorId'] ) ? $params['vectorId'] : null;
      $postId = !empty( $params['postId'] ) ? $params['postId'] : null;
      $result = $this->sync_vector_with_action( $vectorId, $postId, $envId );

      if ( is_string( $result ) ) {
        // Handle string responses (errors or skipped messages)
        return new WP_REST_Response( [
          'success' => true,
          'message' => $result,
          'vector' => null,
          'action' => 'skipped'
        ], 200 );
      }

      return new WP_REST_Response( [
        'success' => true,
        'message' => 'The vector has been synchronized.',
        'vector' => $result['vector'],
        'action' => $result['action'] ?? 'processed'
      ], 200 );
    }
    catch ( Exception $e ) {
      return new WP_REST_Response( [ 'success' => false, 'message' => $e->getMessage() ], 500 );
    }
  }

  public function rest_vectors_ignore( $request ) {
    try {
      $params = $request->get_json_params();
      $postId = !empty( $params['postId'] ) ? intval( $params['postId'] ) : null;
      $envId = !empty( $params['envId'] ) ? $params['envId'] : null;
      if ( empty( $postId ) ) {
        throw new Exception( 'The postId is required.' );
      }
      $post = get_post( $postId );
      if ( !$post ) {
        throw new Exception( 'Post not found.' );
      }

      // Set the ignore meta
      update_post_meta( $postId, '_mwai_embedding_ignore', true );

      // Delete existing vectors for this post (scoped to current environment if provided)
      $vectors = $this->get_vectors_by_refId( $postId, $envId );
      $deleted = 0;
      foreach ( $vectors as $vector ) {
        $this->vectors_delete( $vector['envId'], [ $vector['id'] ] );
        $deleted++;
      }

      return new WP_REST_Response( [
        'success' => true,
        'message' => sprintf( 'Post #%d ignored (%d vectors deleted).', $postId, $deleted ),
        'deletedCount' => $deleted,
      ], 200 );
    }
    catch ( Exception $e ) {
      return new WP_REST_Response( [ 'success' => false, 'message' => $e->getMessage() ], 500 );
    }
  }

  public function rest_vectors_unignore( $request ) {
    try {
      $params = $request->get_json_params();
      $postId = !empty( $params['postId'] ) ? intval( $params['postId'] ) : null;
      if ( empty( $postId ) ) {
        throw new Exception( 'The postId is required.' );
      }
      delete_post_meta( $postId, '_mwai_embedding_ignore' );
      return new WP_REST_Response( [
        'success' => true,
        'message' => sprintf( 'Post #%d is no longer ignored.', $postId ),
      ], 200 );
    }
    catch ( Exception $e ) {
      return new WP_REST_Response( [ 'success' => false, 'message' => $e->getMessage() ], 500 );
    }
  }

  public function rest_vectors_ignored( $request ) {
    try {
      global $wpdb;
      $posts = $wpdb->get_results(
        "SELECT p.ID, p.post_title, p.post_type
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
        WHERE pm.meta_key = '_mwai_embedding_ignore'
        ORDER BY p.post_title ASC",
        ARRAY_A
      );
      return new WP_REST_Response( [
        'success' => true,
        'posts' => $posts,
      ], 200 );
    }
    catch ( Exception $e ) {
      return new WP_REST_Response( [ 'success' => false, 'message' => $e->getMessage() ], 500 );
    }
  }

  public function rest_vectors_delete( $request ) {
    try {
      $params = $request->get_json_params();
      $envId = $params['envId'] ?? null;
      $localIds = $params['ids'] ?? null;
      if ( empty( $envId ) || empty( $localIds ) ) {
        throw new Exception( 'The envId and ids are required.' );
      }
      $force = isset( $params['force'] ) ? $params['force'] : false;
      $success = $this->vectors_delete( $envId, $localIds, $force );
      return new WP_REST_Response( [ 'success' => $success ], 200 );
    }
    catch ( Exception $e ) {
      return new WP_REST_Response( [ 'success' => false, 'message' => $e->getMessage() ], 500 );
    }
  }

  public function rest_vectors_delete_all( $request ) {
    try {
      $params = $request->get_json_params();
      $envId = $params['envId'];
      if ( empty( $envId ) ) {
        throw new Exception( 'The envId is required.' );
      }
      $success = $this->vectors_delete_all( $envId );
      return new WP_REST_Response( [ 'success' => $success ], 200 );
    }
    catch ( Exception $e ) {
      return new WP_REST_Response( [ 'success' => false, 'message' => $e->getMessage() ], 500 );
    }
  }

  public function rest_vectors_chunk( $request ) {
    try {
      $params = $request->get_json_params();
      $text = $params['text'] ?? '';
      $pageTexts = $params['pageTexts'] ?? [];
      $density = $params['density'] ?? 3;
      $overlapPercent = $params['overlap'] ?? 15; // Get overlap percentage from frontend (default 15% for optimal RAG)
      $fileName = $params['fileName'] ?? 'document';
      $chunkingType = $params['chunkingType'] ?? 'tokens';
      $detectedHeadings = $params['detectedHeadings'] ?? [];

      // Increase execution time limit for large PDFs
      // Very High density needs more time
      $originalTimeLimit = ini_get( 'max_execution_time' );
      if ( $density >= 5 ) {
        set_time_limit( 600 ); // 10 minutes for Very High density
        error_log( '[AI Engine Chunking] Extended time limit to 10 minutes for Very High density' );
      }
      else {
        set_time_limit( 300 ); // 5 minutes for normal densities
      }

      if ( empty( $text ) ) {
        throw new Exception( 'Text content is required.' );
      }

      // Log chunking parameters for debugging
      error_log( "[AI Engine Chunking] Starting chunking - Density: $density, Overlap: $overlapPercent%, Text length: " . strlen( $text ) . ' chars' );

      // Handle chapter-based chunking
      // TODO: Consider removing chapter-based chunking in future versions
      // Most modern RAG systems use consistent token-based chunking for better performance
      // Chapter detection adds complexity without significant benefits
      if ( $chunkingType === 'chapters' && !empty( $detectedHeadings ) ) {
        $chunks = $this->chapter_based_chunking( $text, $pageTexts, $detectedHeadings, $fileName );
      }
      else {
        // Token-based chunking
        // Calculate chunk sizes based on density (1-5 scale)
        // Optimized for RAG performance based on Pinecone and industry best practices
        $chunkSizes = [
          1 => 1200, // Very Low - Larger chunks for general context
          2 => 800,  // Low - Good for documentation and articles
          3 => 600,  // Medium - Balanced for most use cases (default)
          4 => 400,  // High - Better precision for Q&A and specific retrieval
          5 => 200   // Very High - Maximum precision for detailed retrieval
        ];

        $maxChunkSize = $chunkSizes[$density] ?? 1000;
        // Calculate overlap based on percentage from frontend
        $overlap = intval( $maxChunkSize * ( $overlapPercent / 100 ) );

        error_log( "[AI Engine Chunking] Token-based chunking - Max chunk size: $maxChunkSize tokens, Overlap: $overlap tokens" );

        $startTime = microtime( true );
        $chunks = $this->smart_text_chunking( $text, $pageTexts, $maxChunkSize, $overlap, $fileName );
        $elapsedTime = microtime( true ) - $startTime;

        error_log( '[AI Engine Chunking] Completed - Generated ' . count( $chunks ) . ' chunks in ' . round( $elapsedTime, 2 ) . ' seconds' );
      }

      // Restore original time limit
      set_time_limit( $originalTimeLimit );

      return new WP_REST_Response( [
        'success' => true,
        'chunks' => $chunks,
        'debug' => [
          'density' => $density,
          'maxChunkSize' => $maxChunkSize ?? null,
          'overlap' => $overlap ?? null,
          'totalChunks' => count( $chunks )
        ]
      ], 200 );
    }
    catch ( Exception $e ) {
      error_log( '[AI Engine Chunking] Error: ' . $e->getMessage() );
      return new WP_REST_Response( [ 'success' => false, 'message' => $e->getMessage() ], 500 );
    }
  }
  #endregion

  #region Helpers

  /**
   * Check if the environment should use Chroma Cloud embeddings.
   * Only returns true if the environment is of type 'chroma' AND has a non-AI-Engine embeddings source.
   */
  private function should_use_chroma_embeddings( $env ) {
    $isChromaEnv = isset( $env['type'] ) && $env['type'] === 'chroma';
    $embeddings_source = isset( $env['embeddings_source'] ) ? $env['embeddings_source'] : null;
    return $isChromaEnv && !empty( $embeddings_source ) && $embeddings_source !== 'ai-engine';
  }

  #endregion

  #region Events (WP & AI Engine)

  /**
   * Convert interval setting to cron expression.
   */
  private function get_cron_schedule_for_interval( $interval ) {
    $schedules = [
      '1h' => '0 * * * *',       // Every hour at minute 0
      '6h' => '0 */6 * * *',     // Every 6 hours at minute 0
      '12h' => '0 */12 * * *',    // Every 12 hours at minute 0
      '24h' => '0 3 * * *',       // Daily at 3 AM
      '48h' => '0 3 */2 * *',     // Every 2 days at 3 AM
      '72h' => '0 3 */3 * *',     // Every 3 days at 3 AM
      '1w' => '0 3 * * 0',       // Weekly on Sunday at 3 AM
      '1m' => '0 3 1 * *',       // Monthly on 1st at 3 AM
    ];
    return isset( $schedules[$interval] ) ? $schedules[$interval] : $schedules['24h'];
  }

  public function register_task() {
    // Register post embeddings sync task (only if enabled)
    if ( $this->sync_posts && !empty( $this->sync_post_envId ) ) {
      $this->core->tasks->ensure( [
        'name' => 'sync_embeddings',
        'description' => 'Synchronize post embeddings with vector database.',
        'category' => 'embeddings',
        'schedule' => '*/5 * * * *', // Every 5 minutes
      ] );
    }

    // Register Remote URL sync task (always, checks for remoteUrl vectors on run)
    $schedule = $this->get_cron_schedule_for_interval( $this->sync_remote_urls_interval );
    $this->core->tasks->ensure( [
      'name' => 'sync_remote_urls',
      'description' => 'Check Remote URL embeddings for content updates.',
      'category' => 'embeddings',
      'schedule' => $schedule,
    ] );
  }

  public function run_tasks( $result, $job ) {
    if ( get_transient( 'mwai_embeddings_tasks_sync' ) ) {
      return [
        'ok' => true,
        'message' => 'Sync already running',
      ];
    }

    set_transient( 'mwai_embeddings_tasks_sync', true, 60 * 10 );

    // Set the current user to the first admin to avoid guest limits
    $admin_users = get_users( [ 'role' => 'administrator', 'number' => 1 ] );
    if ( !empty( $admin_users ) ) {
      $admin_user = $admin_users[0];
      wp_set_current_user( $admin_user->ID );
    }

    try {
      $outdated = $this->get_outdated_vectors( 100, $this->sync_post_envId );

      if ( empty( $outdated ) ) {
        delete_transient( 'mwai_embeddings_tasks_sync' );
        return [
          'ok' => true,
          'message' => 'No outdated vectors to sync',
        ];
      }

      $this->sync_vector( $outdated[0], null, $this->sync_post_envId );
      delete_transient( 'mwai_embeddings_tasks_sync' );

      $remaining = count( $outdated ) - 1;
      return [
        'ok' => true,
        'message' => sprintf( 'Synced 1 vector, %d remaining', $remaining ),
      ];

    }
    catch ( Exception $e ) {
      delete_transient( 'mwai_embeddings_tasks_sync' );
      return [
        'ok' => false,
        'message' => 'Sync failed: ' . $e->getMessage(),
      ];
    }
  }

  /**
   * Task handler for syncing Remote URL embeddings.
   * Runs daily to check for content changes.
   */
  public function run_remote_urls_task( $result, $job ) {
    $batch_size = 3;
    $defaults = [ 'checked' => 0, 'updated' => 0, 'errors' => 0, 'last_id' => 0 ];
    $data = array_merge( $defaults, $job['data'] ?? [] );
    $last_id = $data['last_id'];

    // Get Remote URL vectors using keyset pagination (stable when updated changes)
    // Include errors only if not retried in 24h
    $vectors = $this->wpdb->get_results( $this->wpdb->prepare(
      "SELECT * FROM {$this->table_vectors}
       WHERE type = 'remoteUrl'
       AND id > %d
       AND (
         status IN ('ok', 'outdated', 'stale')
         OR (status = 'error' AND updated < DATE_SUB(NOW(), INTERVAL 1 DAY))
       )
       ORDER BY id ASC LIMIT %d",
      $last_id,
      $batch_size
    ), ARRAY_A );

    if ( empty( $vectors ) ) {
      // Always reset data for next run to avoid stale last_id after table reset/restore
      $reset_data = [ 'checked' => 0, 'updated' => 0, 'errors' => 0, 'last_id' => 0 ];
      if ( $data['checked'] === 0 ) {
        return [
          'ok' => true,
          'done' => true,
          'data' => $reset_data,
          'message' => 'No Remote URL embeddings to sync',
        ];
      }
      return [
        'ok' => true,
        'done' => true,
        'data' => $reset_data,
        'message' => sprintf(
          'Completed: %d checked, %d updated, %d errors',
          $data['checked'],
          $data['updated'],
          $data['errors']
        )
      ];
    }

    // Set admin user to avoid guest limits
    $admin_users = get_users( [ 'role' => 'administrator', 'number' => 1 ] );
    if ( !empty( $admin_users ) ) {
      wp_set_current_user( $admin_users[0]->ID );
    }

    foreach ( $vectors as $vector ) {
      $old_checksum = $vector['refChecksum'];
      $updated_vector = $this->sync_remote_url_vector( $vector );
      $data['checked']++;
      $data['last_id'] = (int) $vector['id']; // Track for keyset pagination

      if ( $updated_vector['status'] === 'error' ) {
        $data['errors']++;
      }
      elseif ( $updated_vector['refChecksum'] !== $old_checksum ) {
        $data['updated']++;
      }

      // Rate limit: 0.5s delay between requests
      usleep( 500000 );
    }

    return [
      'ok' => true,
      'done' => false,
      'next_run_delay' => 60,
      'data' => $data,
      'message' => sprintf( '%d checked so far', $data['checked'] )
    ];
  }

  public function prepare_content( $post ) {
    $content = $this->core->get_post_content( $post['postId'] );
    if ( !empty( $content ) && $this->rewrite_content && !empty( $this->rewrite_prompt ) ) {
      global $mwai;
      $rewrite_prompt = apply_filters( 'mwai_embeddings_rewrite_prompt', $this->rewrite_prompt, $content, $post );
      $prompt = str_replace( '{CONTENT}', $content, $rewrite_prompt );
      $prompt = str_replace( '{TITLE}', $post['title'], $prompt );
      $prompt = str_replace( '{URL}', get_permalink( $post['postId'] ), $prompt );
      $prompt = str_replace( '{EXCERPT}', $post['excerpt'], $prompt );
      $prompt = str_replace( '{LANGUAGE}', $this->core->get_post_language( $post['postId'] ), $prompt );
      $prompt = str_replace( '{ID}', $post['postId'], $prompt );
      if ( strpos( $prompt, '{CATEGORY}' ) !== false ) {
        $categories = get_the_category( $post['postId'] );
        $category = count( $categories ) > 0 ? $categories[0]->name : '';
        $prompt = str_replace( '{CATEGORY}', $category, $prompt );
      }
      if ( strpos( $prompt, '{CATEGORIES}' ) !== false ) {
        $categories = get_the_category( $post['postId'] );
        $categoryNames = [];
        foreach ( $categories as $category ) {
          $categoryNames[] = $category->name;
        }
        $prompt = str_replace( '{CATEGORIES}', implode( ', ', $categoryNames ), $prompt );
      }
      if ( strpos( $prompt, '{AUTHOR}' ) !== false ) {
        $author = get_the_author_meta( 'display_name', $post['author'] );
        $prompt = str_replace( '{AUTHOR}', $author, $prompt );
      }
      if ( strpos( $prompt, '{PUBLISH_DATE}' ) !== false ) {
        $publishDate = get_the_date( 'Y-m-d', $post['postId'] );
        $prompt = str_replace( '{PUBLISH_DATE}', $publishDate, $prompt );
      }
      $content = $mwai->simpleTextQuery( $prompt, [ 'scope' => 'text-rewrite' ] );
    }
    return $content;
  }

  public function prepare_media_content( $attachmentId ) {
    if ( !wp_attachment_is_image( $attachmentId ) ) {
      return null;
    }
    $filePath = get_attached_file( $attachmentId );
    if ( !$filePath || !file_exists( $filePath ) ) {
      return null;
    }
    $fileSize = filesize( $filePath );
    if ( $fileSize > 10 * 1024 * 1024 ) {
      return null;
    }
    $mimeType = get_post_mime_type( $attachmentId );
    $imageData = base64_encode( file_get_contents( $filePath ) );

    // Build metadata text
    $title = get_the_title( $attachmentId );
    $alt = get_post_meta( $attachmentId, '_wp_attachment_image_alt', true );
    $post = get_post( $attachmentId );
    $caption = $post ? $post->post_excerpt : '';
    $description = $post ? $post->post_content : '';
    $filename = basename( $filePath );

    $parts = [];
    if ( !empty( $title ) ) {
      $parts[] = "Title: {$title}";
    }
    if ( !empty( $alt ) ) {
      $parts[] = "Alt text: {$alt}";
    }
    if ( !empty( $caption ) ) {
      $parts[] = "Caption: {$caption}";
    }
    if ( !empty( $description ) ) {
      $parts[] = "Description: {$description}";
    }
    $parts[] = "Filename: {$filename}";
    $content = implode( "\n", $parts );

    return [
      'content' => $content,
      'imageData' => $imageData,
      'imageMimeType' => $mimeType,
      'title' => !empty( $title ) ? $title : $filename,
    ];
  }

  /**
   * Fetch content from a remote URL for Remote URL embeddings.
   * Uses wp_safe_remote_get() to prevent SSRF attacks.
   */
  public function fetch_url_content( $url ) {
    // Validate URL format and security
    if ( !wp_http_validate_url( $url ) ) {
      return [ 'success' => false, 'error' => 'Invalid or unsafe URL' ];
    }

    // Only allow http/https schemes
    $scheme = wp_parse_url( $url, PHP_URL_SCHEME );
    if ( !in_array( $scheme, [ 'http', 'https' ], true ) ) {
      return [ 'success' => false, 'error' => 'Only HTTP/HTTPS URLs are allowed' ];
    }

    // Fetch with wp_safe_remote_get (blocks local/private IPs)
    $response = wp_safe_remote_get( $url, [
      'timeout' => 30,
      'user-agent' => 'AI Engine WordPress Plugin',
    ] );

    if ( is_wp_error( $response ) ) {
      return [ 'success' => false, 'error' => $response->get_error_message() ];
    }

    $status_code = wp_remote_retrieve_response_code( $response );
    if ( $status_code !== 200 ) {
      return [ 'success' => false, 'error' => "HTTP $status_code" ];
    }

    $body = wp_remote_retrieve_body( $response );
    $content = $this->extract_text_from_html( $body );

    // Enforce content size limit (~64k to match TEXT column)
    if ( strlen( $content ) > 64000 ) {
      $content = substr( $content, 0, 64000 );
    }

    // Empty content detection (JS-rendered pages, etc.)
    if ( empty( trim( $content ) ) ) {
      return [ 'success' => false, 'error' => 'No text content found (page may require JavaScript)' ];
    }

    return [
      'success' => true,
      'content' => $content,
      'checksum' => md5( $content )
    ];
  }

  /**
   * Extract text content from HTML, removing scripts, styles, and tags.
   */
  private function extract_text_from_html( $html ) {
    // Remove scripts, styles, comments
    $html = preg_replace( '/<script\b[^>]*>(.*?)<\/script>/is', '', $html );
    $html = preg_replace( '/<style\b[^>]*>(.*?)<\/style>/is', '', $html );
    $html = preg_replace( '/<!--.*?-->/s', '', $html );

    // Strip tags and decode entities
    $text = wp_strip_all_tags( $html );
    $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

    // Clean up whitespace
    $text = preg_replace( '/\s+/', ' ', $text );
    $text = trim( $text );

    return $text;
  }

  /**
   * Prepare content for a Remote URL embedding, applying AI rewrite if enabled.
   * Uses the remote-specific settings (rewriteRemoteContent, rewriteRemotePrompt).
   */
  public function prepare_remote_url_content( $url, $raw_content ) {
    if ( !$this->rewrite_remote_content || empty( $this->rewrite_remote_prompt ) ) {
      return $raw_content;
    }

    // Match existing prepare_content() pattern using simpleTextQuery
    global $mwai;
    $prompt = str_replace( '{CONTENT}', $raw_content, $this->rewrite_remote_prompt );
    $prompt = str_replace( '{URL}', $url, $prompt );
    $prompt = str_replace( '{TITLE}', '', $prompt );
    $prompt = str_replace( '{EXCERPT}', '', $prompt );

    $result = $mwai->simpleTextQuery( $prompt, [ 'scope' => 'text-rewrite' ] );
    return !empty( $result ) ? $result : $raw_content;
  }

  /**
   * Sync a Remote URL vector by fetching content and updating if changed.
   */
  public function sync_remote_url_vector( $vector ) {
    global $mwai_sync_action;

    $fetch_result = $this->fetch_url_content( $vector['refUrl'] );

    if ( !$fetch_result['success'] ) {
      // Keep old content, update status directly via SQL
      $this->wpdb->update(
        $this->table_vectors,
        [
          'status' => 'error',
          'error' => $fetch_result['error'],
          'updated' => date( 'Y-m-d H:i:s' )
        ],
        [ 'id' => $vector['id'] ]
      );
      $mwai_sync_action = 'skipped';
      return $this->get_vector( $vector['id'] );
    }

    // Check if content changed
    $contentUnchanged = $fetch_result['checksum'] === $vector['refChecksum'];
    $needsRecovery = $vector['status'] !== 'ok' || empty( $vector['dbId'] );

    // Use force_remote_recreate setting for remote URLs (separate from local content setting)
    if ( $contentUnchanged && !$needsRecovery && !$this->force_remote_recreate ) {
      // Content unchanged, vector is healthy, and force recreate not enabled - no update needed
      $mwai_sync_action = 'up-to-date';
      return $vector;
    }

    // Content changed OR needs recovery OR force recreate enabled - regenerate embedding
    $content = $fetch_result['content'];
    if ( $this->rewrite_remote_content && !empty( $this->rewrite_remote_prompt ) ) {
      $content = $this->prepare_remote_url_content( $vector['refUrl'], $content );
    }

    // If recovery needed OR force recreate, but content unchanged, set status to 'error' in DB first.
    // This ensures update_vector() sees $wasError = true and triggers re-embedding.
    if ( $contentUnchanged && ( $needsRecovery || $this->force_remote_recreate ) ) {
      $this->wpdb->update(
        $this->table_vectors,
        [ 'status' => 'error' ],
        [ 'id' => $vector['id'] ]
      );
    }

    // update_vector() expects array; add updated fields
    $vector['content'] = $content;
    $vector['refChecksum'] = $fetch_result['checksum'];
    $vector['status'] = 'pending';

    $mwai_sync_action = 'updated';
    return $this->update_vector( $vector, $vector['envId'] );
  }

  /**
   * Get vectors by refUrl for Remote URL embeddings.
   */
  public function get_vectors_by_refUrl( $refUrl, $envId = null ) {
    if ( !$this->check_db() ) {
      return [];
    }
    $query = "SELECT * FROM {$this->table_vectors}";
    $where = [];
    $where[] = "refUrl = '" . esc_sql( $refUrl ) . "'";
    if ( !empty( $envId ) ) {
      $where[] = "envId = '" . esc_sql( $envId ) . "'";
    }
    $query .= ' WHERE ' . implode( ' AND ', $where );
    $vectors = $this->wpdb->get_results( $query, ARRAY_A );
    return $vectors;
  }

  public function sync_vector_with_action( $vector = null, $postId = null, $envId = null ) {
    global $mwai_sync_action; // Track the action globally during sync
    $mwai_sync_action = 'up-to-date'; // Default

    $result = $this->sync_vector( $vector, $postId, $envId );

    // If it's a string, it's a skip message
    if ( is_string( $result ) ) {
      return $result;
    }

    // Return the result with the tracked action
    return [
      'vector' => $result,
      'action' => $mwai_sync_action
    ];
  }

  public function sync_vector( $vector = null, $postId = null, $envId = null ) {
    if ( $postId ) {
      // Check if post is ignored
      if ( get_post_meta( $postId, '_mwai_embedding_ignore', true ) ) {
        return 'This post is ignored for embeddings sync.';
      }
      if ( !apply_filters( 'mwai_embeddings_sync_post', true, $postId ) ) {
        return 'This post was excluded by the mwai_embeddings_sync_post filter.';
      }
      if ( !$this->is_post_language_synced( $postId ) ) {
        return 'This post was excluded by the language filter.';
      }

      $previousVectors = $this->get_vectors_by_refId( $postId, $envId );
      if ( count( $previousVectors ) > 1 ) {
        Meow_MWAI_Logging::warn( "There are more than one vector with the same refId ({$postId}). It is not handled yet." );
        return;
      }
      else if ( count( $previousVectors ) === 1 ) {
        $vector = $previousVectors[0];
      }
      else {
        // It's a new vector.
        // Check if this is a media attachment (image)
        if ( wp_attachment_is_image( $postId ) ) {
          $mediaData = $this->prepare_media_content( $postId );
          if ( !$mediaData ) {
            return 'This media file could not be read.';
          }
          global $mwai_sync_action;
          $mwai_sync_action = 'added';
          return $this->vectors_add( [
            'type' => 'mediaId',
            'title' => $mediaData['title'],
            'refId' => $postId,
            'refChecksum' => md5_file( get_attached_file( $postId ) ),
            'envId' => !empty( $envId ) ? $envId : $this->sync_post_envId,
            'content' => $mediaData['content'],
            '_imageData' => $mediaData['imageData'],
            '_imageMimeType' => $mediaData['imageMimeType'],
            'behavior' => 'context'
          ], 'ok' );
        }

        $post = $this->core->get_post( $postId );
        if ( !$post ) {
          return;
        }
        // Prepare and return the addition of a new vector based on the provided postId.
        $content = $this->prepare_content( $post );

        // If the content is empty, we don't do anything.
        if ( empty( $content ) ) {
          return "This vector has no content; it won't be added or it will be deleted.";
        }

        global $mwai_sync_action;
        $mwai_sync_action = 'added';
        return $this->vectors_add( [
          'type' => 'postId',
          'title' => $post['title'],
          'refId' => $post['postId'],
          'refChecksum' => $post['checksum'],
          'envId' => !empty( $envId ) ? $envId : $this->sync_post_envId,
          'content' => $content,
          'behavior' => 'context'
        ], 'ok' );
      }
    }

    // Proceed with the original function logic if $postId is not provided.
    if ( is_numeric( $vector ) ) {
      $vector = $this->get_vector( $vector );
    }
    if ( empty( $vector ) ) {
      return;
    }

    // Handle Remote URL vectors - sync from external URL
    if ( $vector['type'] === 'remoteUrl' && !empty( $vector['refUrl'] ) ) {
      return $this->sync_remote_url_vector( $vector );
    }

    // If the vector does not have a refId, it is not linked to a post, and only need to be updated.
    if ( empty( $vector['refId'] ) ) {
      return $this->update_vector( $vector, $envId );
    }

    // Check if post is ignored (when syncing via vector with refId)
    if ( get_post_meta( $vector['refId'], '_mwai_embedding_ignore', true ) ) {
      return 'This post is ignored for embeddings sync.';
    }
    if ( !apply_filters( 'mwai_embeddings_sync_post', true, $vector['refId'] ) ) {
      return 'This post was excluded by the mwai_embeddings_sync_post filter.';
    }

    $matchedVectors = $this->get_vectors_by_refId( $vector['refId'], $vector['envId'] );
    if ( count( $matchedVectors ) > 1 ) {
      // Handle multiple vectors related to the same post.
      Meow_MWAI_Logging::warn( "There are more than one vector with the same refId ({$vector['refId']}). It is not handled yet." );
      return;
    }
    $matchedVector = $matchedVectors[0];

    // Handle mediaId type vectors
    if ( $matchedVector['type'] === 'mediaId' ) {
      $filePath = get_attached_file( $matchedVector['refId'] );
      if ( !$filePath || !file_exists( $filePath ) ) {
        $this->vectors_delete( $matchedVector['envId'], [ $matchedVector['id'] ] );
        return;
      }

      $env = $this->core->get_embeddings_env( $matchedVector['envId'] );
      $override = isset( $env['ai_embeddings_override'] ) && $env['ai_embeddings_override'] === true;
      $modelMismatch = false;
      $dimensionsMismatch = false;
      if ( $override ) {
        $modelMismatch = $env['ai_embeddings_model'] !== $vector['model'];
        $dimensionsMismatch = isset( $env['ai_embeddings_dimensions'] ) &&
          (string) $env['ai_embeddings_dimensions'] !== (string) $vector['dimensions'];
      }
      $technicalMismatch = $modelMismatch || $dimensionsMismatch;

      $currentChecksum = md5_file( $filePath );
      if ( !$technicalMismatch && !$this->force_recreate && $currentChecksum === $matchedVector['refChecksum']
            && $matchedVector['status'] === 'ok' ) {
        global $mwai_sync_action;
        $mwai_sync_action = 'up-to-date';
        return $matchedVector;
      }

      $mediaData = $this->prepare_media_content( $matchedVector['refId'] );
      if ( !$mediaData ) {
        return 'This media file could not be read.';
      }

      $this->vectors_delete( $matchedVector['envId'], [ $matchedVector['id'] ] );

      global $mwai_sync_action;
      $mwai_sync_action = 'updated';
      $title = !empty( $matchedVector['title'] ) ? $matchedVector['title'] : $mediaData['title'];
      return $this->vectors_add( [
        'type' => 'mediaId',
        'title' => $title,
        'refId' => $matchedVector['refId'],
        'refChecksum' => $currentChecksum,
        'envId' => !empty( $envId ) ? $envId : $matchedVector['envId'],
        'content' => $mediaData['content'],
        '_imageData' => $mediaData['imageData'],
        '_imageMimeType' => $mediaData['imageMimeType'],
        'behavior' => 'context'
      ], 'ok' );
    }

    $post = $this->core->get_post( $matchedVector['refId'] );
    if ( !$post ) {
      if ( $matchedVector['type'] === 'postId' ) {
        // If the post is not found, we delete the vector.
        $this->vectors_delete( $matchedVector['envId'], [ $matchedVector['id'] ] );
      }
      return;
    }

    // Check if the model is not the same as the one used for the vector.
    $env = $this->core->get_embeddings_env( $matchedVector['envId'] );

    // Only check for mismatches if override is enabled
    $override = isset( $env['ai_embeddings_override'] ) && $env['ai_embeddings_override'] === true;
    $modelMismatch = false;
    $dimensionsMismatch = false;

    if ( $override ) {
      $modelMismatch = $env['ai_embeddings_model'] !== $vector['model'];
      $dimensionsMismatch = isset( $env['ai_embeddings_dimensions'] ) &&
        (string) $env['ai_embeddings_dimensions'] !== (string) $vector['dimensions'];
    }

    $technicalMismatch = $modelMismatch || $dimensionsMismatch;

    // If the vector is already up-to-date, we don't do anything.
    if ( !$technicalMismatch && !$this->force_recreate && $post['checksum'] === $matchedVector['refChecksum']
          && $matchedVector['status'] === 'ok' ) {
      global $mwai_sync_action;
      $mwai_sync_action = 'up-to-date';
      return $matchedVector;
    }

    // If the vector is outdated, we delete it.
    $this->vectors_delete( $matchedVector['envId'], [ $matchedVector['id'] ] );

    // Prepare and return the addition of a new vector based on the provided postId.
    $content = $this->prepare_content( $post );

    // If the content is empty, we don't do anything.
    if ( empty( $content ) ) {
      return "This vector has no content; it won't be added or it will be deleted.";
    }

    global $mwai_sync_action;
    $mwai_sync_action = 'updated';

    // Preserve the existing title if it exists, otherwise use the post title
    $title = !empty( $matchedVector['title'] ) ? $matchedVector['title'] : $post['title'];

    return $this->vectors_add( [
      'type' => 'postId',
      'title' => $title,
      'refId' => $post['postId'],
      'refChecksum' => $post['checksum'],
      'envId' => !empty( $envId ) ? $envId : $matchedVector['envId'],
      'content' => $content,
      'behavior' => 'context'
    ], 'ok' );
  }

  // Returns false only when a Polylang language filter is set and the post's
  // language is not in it. No filter set, or Polylang inactive, means sync everything.
  private function is_post_language_synced( $postId ) {
    if ( empty( $this->sync_post_languages ) || !function_exists( 'pll_get_post_language' ) ) {
      return true;
    }
    $lang = pll_get_post_language( $postId );
    return !empty( $lang ) && in_array( $lang, $this->sync_post_languages );
  }

  public function action_save_post( $postId, $post, $update ) {
    if ( !$this->check_db() ) {
      return false;
    }

    // ALWAYS check for existing embeddings and mark as stale if content changed
    // This happens regardless of sync settings
    $vectors = $this->get_vectors_by_refId( $postId );
    if ( !empty( $vectors ) ) {
      $cleanPost = $this->core->get_post( $post );
      foreach ( $vectors as $vector ) {
        if ( $cleanPost['checksum'] === $vector['refChecksum'] ) {
          continue;
        }
        // Mark as stale when content changes
        $this->wpdb->update(
          $this->table_vectors,
          [ 'status' => 'stale' ],
          [ 'id' => $vector['id'] ]
        );
      }
    }

    // Only auto-create new embeddings if sync is enabled AND configured
    if ( !$this->sync_posts ) {
      return;
    }

    // Check if post is ignored
    if ( get_post_meta( $postId, '_mwai_embedding_ignore', true ) ) {
      return;
    }
    if ( !apply_filters( 'mwai_embeddings_sync_post', true, $postId ) ) {
      return;
    }

    // Check sync configuration for auto-creating new embeddings
    if ( !in_array( $post->post_type, $this->sync_post_types ) ) {
      return;
    }
    if ( !in_array( $post->post_status, $this->sync_post_status ) ) {
      return;
    }
    if ( !empty( $this->sync_post_categories ) ) {
      $categories = get_the_category( $postId );
      $categorySlugs = [];
      foreach ( $categories as $category ) {
        $categorySlugs[] = $category->slug;
      }
      $intersect = array_intersect( $categorySlugs, $this->sync_post_categories );
      if ( empty( $intersect ) ) {
        return;
      }
    }
    if ( !$this->is_post_language_synced( $postId ) ) {
      return;
    }

    // Only create new embedding if it doesn't exist and sync is enabled
    if ( empty( $vectors ) ) {
      $cleanPost = $this->core->get_post( $post );
      $vector = [
        'type' => 'postId',
        'title' => $cleanPost['title'],
        'refId' => $postId,
        'envId' => $this->sync_post_envId,
      ];
      $this->vectors_add( $vector, 'pending' );
    }
  }

  public function action_delete_post( $postId ) {
    if ( !$this->check_db() ) {
      return false;
    }
    $vectorIds = $this->wpdb->get_col( $this->wpdb->prepare(
      "SELECT id FROM $this->table_vectors WHERE refId = %d AND type = 'postId'",
      $postId
    ) );
    if ( !$vectorIds ) {
      return;
    }
    $this->vectors_delete( $this->sync_post_envId, $vectorIds );
  }

  public function pull_vector_from_remote( $embedId, $envId ) {
    $remoteVector = $this->get_vector_metadata_from_remote( $embedId, $envId );
    if ( empty( $remoteVector ) ) {
      Meow_MWAI_Logging::warn( "A vector was returned by the Vector DB, but it is not available in the local DB and we could not retrieve it more information about it from the Vector DB (ID {$embedId})." );
    }
    $type = isset( $remoteVector['type'] ) ? $remoteVector['type'] : 'manual';
    $title = isset( $remoteVector['title'] ) ? $remoteVector['title'] : 'N/A';
    $content = isset( $remoteVector['content'] ) ? $remoteVector['content'] : '';
    $isOk = !empty( $content );
    // If there is no content, it is marked as 'orphan'
    // (and only written locally since it's already in the Vector DB).
    $vector = $this->vectors_add( [
      'type' => $type,
      'title' => $title,
      'content' => $content,
      'dbId' => $embedId,
      'envId' => $envId,
    ], $isOk ? 'ok' : 'orphan', true );
    return $vector;
  }

  public function context_search( $context, $query, $options = [] ) {
    $embeddingsEnvId = !empty( $options['embeddingsEnvId'] ) ? $options['embeddingsEnvId'] : null;

    // Context already provided? We don't do anything.
    if ( !$embeddingsEnvId || !empty( $context ) ) {
      return $context;
    }

    // Debug logging if enabled
    if ( $this->core->get_option( 'debug_embeddings' ) ) {
      error_log( 'AI Engine - context_search called with embeddingsEnvId: ' . $embeddingsEnvId );
      error_log( 'AI Engine - Query type: ' . get_class( $query ) );
      if ( property_exists( $query, 'messages' ) ) {
        error_log( 'AI Engine - Messages count: ' . ( is_array( $query->messages ) ? count( $query->messages ) : 'not array' ) );
      }
    }

    // Use ConversationContextBuilder if messages are available
    $searchQuery = $query;
    if ( $query instanceof Meow_MWAI_Query_Text ) {
      // Check if we have messages array or need to use get_message()
      if ( !empty( $query->messages ) ) {
        $contextBuilder = new Meow_MWAI_Embeddings_ConversationContextBuilder( $this->core );

        // Get embeddings settings
        $embeddingsSettings = $this->core->get_option( 'embeddings_settings', [] );

        // Build settings for context builder
        $builderSettings = [
          'search_method' => $embeddingsSettings['search_method'] ?? 'simple',
          'context_messages' => $embeddingsSettings['context_messages'] ?? 10,
          'include_instructions' => $embeddingsSettings['include_instructions'] ?? false
        ];

        // Build optimized search query
        $searchContext = $contextBuilder->build_search_query( $query->messages, $builderSettings, $query );
        $searchQuery = $searchContext['query'];

        // If search query is empty, fallback to get_message()
        if ( empty( $searchQuery ) ) {
          $searchQuery = $query->get_message();
          if ( $this->core->get_option( 'debug_embeddings' ) ) {
            error_log( 'AI Engine - ConversationContextBuilder returned empty, using get_message() fallback' );
          }
        }

        // Log method used if debug is enabled
        if ( $this->core->get_option( 'debug_embeddings' ) ) {
          error_log( 'AI Engine - Embeddings search method: ' . $searchContext['method'] );
          error_log( 'AI Engine - Search query: ' . substr( $searchQuery, 0, 200 ) . '...' );
          error_log( 'AI Engine - Messages count: ' . count( $query->messages ) );
          if ( !empty( $query->messages ) ) {
            $lastMsg = end( $query->messages );
            error_log( 'AI Engine - Last message role: ' . ( $lastMsg['role'] ?? 'unknown' ) );
          }
        }
      }
      else {
        // Fallback to get_message() if no messages array
        $searchQuery = $query->get_message();
        if ( $this->core->get_option( 'debug_embeddings' ) ) {
          error_log( 'AI Engine - No messages array, using get_message() directly' );
        }
      }
    }

    $env = $this->core->get_embeddings_env( $embeddingsEnvId );

    // Check if this is an OpenAI Vector Store environment
    if ( isset( $env['type'] ) && $env['type'] === 'openai-vector-store' ) {
      // For Vector Store, pass the search query text directly without generating embeddings
      $options = [
        'envId' => $embeddingsEnvId,
        'query' => $query,
        'searchQuery' => $searchQuery // Pass the text query directly
      ];
      $embeds = apply_filters( 'mwai_embeddings_query_vectors', [], $searchQuery, $options );
    }
    else {
      if ( $this->should_use_chroma_embeddings( $env ) ) {
        // Use Chroma Cloud for query embedding generation
        $queryEmbedding = apply_filters( 'mwai_chroma_generate_embedding', null, $searchQuery, $embeddingsEnvId );

        if ( !$queryEmbedding ) {
          return null;
        }

        $embeds = $this->query_db( $queryEmbedding, $embeddingsEnvId, $query );
      }
      else {
        // Generate embeddings using AI Engine (default)
        // This works for other environments (Pinecone, Qdrant, Chroma with AI Engine)
        $queryEmbed = new Meow_MWAI_Query_Embed( $searchQuery );

        // Set scope from original query if available
        if ( $query instanceof Meow_MWAI_Query_Text && !empty( $query->scope ) ) {
          $queryEmbed->set_scope( $query->scope );
        }

        $override = isset( $env['ai_embeddings_override'] ) && $env['ai_embeddings_override'] === true;
        if ( $override ) {
          $queryEmbed->set_env_id( $env['ai_embeddings_env'] );
          $queryEmbed->set_model( $env['ai_embeddings_model'] );
          if ( !empty( $env['ai_embeddings_dimensions'] ) ) {
            $queryEmbed->set_dimensions( $env['ai_embeddings_dimensions'] );
          }
        }

        $reply = $this->core->run_query( $queryEmbed );
        if ( empty( $reply->result ) ) {
          return null;
        }
        $embeds = $this->query_db( $reply->result, $embeddingsEnvId, $query );
      }
    }
    if ( empty( $embeds ) ) {
      return null;
    }
    $minScore = isset( $env['min_score'] ) ? (float) $env['min_score'] : 35;
    $maxSelect = empty( $env['max_select'] ) ? 10 : (int) $env['max_select'];
    $embeds = array_slice( $embeds, 0, $maxSelect );

    // Prepare the context
    $context = [];
    $context['content'] = '';
    $context['type'] = 'embeddings';
    $context['embeddingIds'] = [];
    foreach ( $embeds as $embed ) {
      if ( ( $embed['score'] * 100 ) < $minScore ) {
        continue;
      }
      $embedId = $embed['id'];
      $data = $this->get_vector_by_remoteId( $embedId );

      // If the vector is not available locally, we try to get it from the Vector DB.
      if ( empty( $data ) ) {
        $data = $this->pull_vector_from_remote( $embedId, $embeddingsEnvId );
        if ( empty( $data['content'] ) ) {
          continue;
        }
      }

      if ( $data['type'] === 'mediaId' && !empty( $data['refId'] ) ) {
        $imageUrl = wp_get_attachment_url( $data['refId'] );
        $imageInfo = $data['title'] ?: 'Image';
        $alt = get_post_meta( $data['refId'], '_wp_attachment_image_alt', true );
        if ( !empty( $alt ) ) {
          $imageInfo .= " ({$alt})";
        }
        $context['content'] .= "Relevant image: {$imageInfo} - URL: {$imageUrl}\n";
      }
      else {
        $context['content'] .= $data['content'] . "\n";
      }
      $context['embeddings'][] = [
        'id' => $embedId,
        'type' => $data['type'],
        'title' => $data['title'],
        'ref' => $data['refId'],
        'score' => (float) $embed['score'],
      ];
    }

    return empty( $context['content'] ) ? null : $context;
  }

  private function library_search_get_ids( $search ) {
    $envId = $this->core->get_option( 'library_search_env_id' );
    if ( empty( $envId ) ) {
      return null;
    }

    // Check transient cache (avoids re-generating embeddings on back/forward navigation)
    $cacheKey = 'mwai_libsearch_' . md5( $search . '|' . $envId );
    $cached = get_transient( $cacheKey );
    if ( $cached !== false ) {
      return $cached;
    }

    $env = $this->core->get_embeddings_env( $envId );

    $queryEmbed = new Meow_MWAI_Query_Embed( $search );
    $queryEmbed->set_scope( 'library-search' );
    $override = isset( $env['ai_embeddings_override'] ) && $env['ai_embeddings_override'] === true;
    if ( $override ) {
      $queryEmbed->set_env_id( $env['ai_embeddings_env'] );
      $queryEmbed->set_model( $env['ai_embeddings_model'] );
      if ( !empty( $env['ai_embeddings_dimensions'] ) ) {
        $queryEmbed->set_dimensions( $env['ai_embeddings_dimensions'] );
      }
    }

    $reply = $this->core->run_query( $queryEmbed );
    if ( empty( $reply->result ) ) {
      return null;
    }

    $embeds = $this->query_db( $reply->result, $envId );
    if ( empty( $embeds ) ) {
      return null;
    }

    $minScore = isset( $env['min_score'] ) ? (float) $env['min_score'] : 35;
    $maxSelect = empty( $env['max_select'] ) ? 40 : (int) $env['max_select'];

    $attachmentIds = [];
    foreach ( array_slice( $embeds, 0, $maxSelect ) as $embed ) {
      if ( ( $embed['score'] * 100 ) < $minScore ) {
        continue;
      }
      $data = $this->get_vector_by_remoteId( $embed['id'] );
      if ( !empty( $data ) && $data['type'] === 'mediaId' && !empty( $data['refId'] ) ) {
        $attachmentIds[] = (int) $data['refId'];
      }
    }

    $result = !empty( $attachmentIds ) ? $attachmentIds : null;
    if ( $result ) {
      set_transient( $cacheKey, $result, 2 * MINUTE_IN_SECONDS );
    }
    return $result;
  }

  // List mode: intercepts upload.php?mwai_library_search=TERM
  public function library_search_pre_get_posts( $query ) {
    global $pagenow;
    if ( !is_admin() || !$query->is_main_query() || $pagenow !== 'upload.php' ) {
      return;
    }
    $search = isset( $_GET['mwai_library_search'] ) ? sanitize_text_field( $_GET['mwai_library_search'] ) : '';
    if ( empty( $search ) ) {
      return;
    }

    try {
      $ids = $this->library_search_get_ids( $search );
      if ( $ids ) {
        $query->set( 's', '' );
        $query->set( 'post__in', $ids );
        $query->set( 'orderby', 'post__in' );
      }
    }
    catch ( Exception $e ) {
      Meow_MWAI_Logging::error( 'Library Search error: ' . $e->getMessage() );
    }
  }

  // Grid mode: intercepts AJAX attachment queries with mwai_library_search
  public function library_search_ajax_attachments( $query ) {
    $search = isset( $query['mwai_library_search'] ) ? sanitize_text_field( $query['mwai_library_search'] ) : '';
    if ( empty( $search ) ) {
      return $query;
    }
    try {
      $ids = $this->library_search_get_ids( $search );
      if ( $ids ) {
        $query['post__in'] = $ids;
        $query['orderby'] = 'post__in';
        unset( $query['s'] );
      }
    }
    catch ( Exception $e ) {
      Meow_MWAI_Logging::error( 'Library Search error: ' . $e->getMessage() );
    }
    return $query;
  }

  public function library_search_enqueue( $hook ) {
    if ( $hook !== 'upload.php' ) {
      return;
    }
    $file = trailingslashit( MWAI_PATH ) . 'premium/library-search.js';
    if ( !file_exists( $file ) ) {
      return;
    }

    // Hide native search controls immediately to prevent flash
    wp_add_inline_style( 'wp-admin', '
      .search-box { visibility: hidden !important; }
      .media-toolbar-primary > label,
      .media-toolbar-primary > input { display: none !important; }
    ' );

    $cache_buster = filemtime( $file );
    wp_enqueue_script(
      'mwai-library-search',
      trailingslashit( MWAI_URL ) . 'premium/library-search.js',
      [ 'wp-element' ],
      $cache_buster,
      true
    );
    wp_localize_script( 'mwai-library-search', 'mwaiLibrarySearch', [
      'restUrl' => rest_url( $this->namespace ),
      'nonce' => wp_create_nonce( 'wp_rest' ),
    ] );
  }
  #endregion

  #region DB Queries

  public function query_db( $searchVectors, $envId = null, $query = null ) {
    $envId = $envId ? $envId : $this->default_envId;
    $options = [ 'envId' => $envId ];
    if ( $query ) {
      $options['query'] = $query;
    }
    $vectors = apply_filters( 'mwai_embeddings_query_vectors', [], $searchVectors, $options );
    return $vectors;
  }

  public function get_outdated_vectors( $limit = 100, $envId = null ) {
    if ( !$this->check_db() ) {
      return false;
    }
    $query = "SELECT * FROM {$this->table_vectors} WHERE (status = 'stale' OR status = 'outdated' OR status = 'pending')";

    if ( !empty( $envId ) ) {
      $query .= " AND envId = '" . esc_sql( $envId ) . "'";
    }

    $query .= ' ORDER BY updated ASC LIMIT ' . intval( $limit );

    $vectors = $this->wpdb->get_results( $query, ARRAY_A );
    return $vectors;
  }

  public function vectors_delete( $envId, $localIds, $force = false ) {
    if ( !$this->check_db() ) {
      return false;
    }

    $toDelete = [];
    foreach ( $localIds as $id ) {
      $vector = $this->get_vector( $id );
      if ( $vector ) {
        $toDelete[] = [ 'localId' => $id, 'dbId' => $vector['dbId'] ];
      }
    }

    $dbIds = array_map( function ( $mapping ) { return $mapping['dbId']; }, $toDelete );
    $dbIds = array_values( array_filter( $dbIds, function ( $dbId ) { return !is_null( $dbId ); } ) );

    if ( !empty( $dbIds ) ) {
      try {
        $options = [ 'envId' => $envId, 'ids' => $dbIds, 'deleteAll' => false ];
        apply_filters( 'mwai_embeddings_delete_vectors', [], $options );
      }
      catch ( Exception $e ) {
        if ( $force ) {
          Meow_MWAI_Logging::error( $e->getMessage() );
        }
        else {
          throw $e;
        }
      }
    }

    // If everything went well, we can delete the local vectors.
    foreach ( $toDelete as $toDeleteItem ) {
      $this->wpdb->delete( $this->table_vectors, [ 'id' => $toDeleteItem['localId'] ], ['%d'] );
    }

    return true;
  }

  public function vectors_delete_all( $envId ) {
    if ( !$this->check_db() ) {
      return false;
    }

    while ( true ) {
      $vectors = $this->query_vectors( 0, 20, [ 'envId' => $envId ], null );
      if ( empty( $vectors['rows'] ) ) {
        break;
      }
      $localIds = array_map( function ( $v ) { return $v['id']; }, $vectors['rows'] );
      $dbIds = array_values( array_filter( array_map( function ( $v ) { return $v['dbId']; }, $vectors['rows'] ) ) );
      if ( !empty( $dbIds ) ) {
        $options = [ 'envId' => $envId, 'ids' => $dbIds, 'deleteAll' => false ];
        apply_filters( 'mwai_embeddings_delete_vectors', [], $options );
      }
      foreach ( $localIds as $localId ) {
        $this->wpdb->delete( $this->table_vectors, [ 'id' => $localId ], ['%d'] );
      }
    }

    return true;
  }

  // function vectors_delete_all( $success, $index, $syncPineCone = true ) {
  //   if ( $success ) { return $success; }
  //   if ( !$this->check_db() ) { return false; }
  //   if ( $syncPineCone ) { $this->pinecode_delete( null, true ); }
  //   $this->wpdb->delete( $this->table_vectors, [ 'dbIndex' => $index ], array( '%s' ) );
  //   return true;
  // }

  // Derive a sensible default for the chunk's "source" field when the client didn't provide one.
  // Source = where this chunk came from (filename, URL, post title, or "manual").
  private function resolve_source_default( $vector ) {
    $resolved = null;
    if ( !empty( $vector['source'] ) ) {
      $resolved = $vector['source'];
    }
    else {
      $type = isset( $vector['type'] ) ? $vector['type'] : 'manual';
      if ( $type === 'remoteUrl' && !empty( $vector['refUrl'] ) ) {
        $resolved = $vector['refUrl'];
      }
      elseif ( ( $type === 'postId' || $type === 'mediaId' ) && !empty( $vector['refId'] ) ) {
        $title = get_the_title( (int) $vector['refId'] );
        if ( !empty( $title ) ) {
          $resolved = $title;
        }
      }
      elseif ( $type === 'manual' ) {
        $resolved = 'manual';
      }
    }
    // Cap at the column width (VARCHAR 2048). Multibyte-safe so we don't slice through a UTF-8
    // codepoint and break the row insert.
    if ( is_string( $resolved ) && mb_strlen( $resolved ) > 2048 ) {
      $resolved = mb_substr( $resolved, 0, 2048 );
    }
    return $resolved;
  }

  // Build the metadata payload that addons write into the remote vector DB, and let users hook in.
  // Returns [ title, source, partIndex, partTotal, meta ]. The 'meta' array is merged flat into
  // provider metadata (Pinecone metadata, Qdrant payload, Chroma metadatas, OpenAI attributes).
  private function build_vector_metadata( $vector ) {
    $partIndex = isset( $vector['partIndex'] ) && $vector['partIndex'] !== '' ? (int) $vector['partIndex'] : null;
    $partTotal = isset( $vector['partTotal'] ) && $vector['partTotal'] !== '' ? (int) $vector['partTotal'] : null;
    $data = [
      'title' => isset( $vector['title'] ) ? $vector['title'] : '',
      'source' => $this->resolve_source_default( $vector ),
      'partIndex' => $partIndex,
      'partTotal' => $partTotal,
      'meta' => [],
    ];
    $prefilter = $data;
    $data = apply_filters( 'mwai_embeddings_vector_metadata', $data, $vector );
    // Defensive: a misbehaving filter that returns null/non-array shouldn't blank out
    // the title/source we already computed.
    if ( !is_array( $data ) ) {
      $data = $prefilter;
    }
    foreach ( [ 'title', 'source', 'partIndex', 'partTotal' ] as $k ) {
      if ( !array_key_exists( $k, $data ) ) {
        $data[$k] = $prefilter[$k];
      }
    }
    if ( !isset( $data['meta'] ) || !is_array( $data['meta'] ) ) {
      $data['meta'] = [];
    }
    return $data;
  }

  public function vectors_add( $vector = [], $status = 'processing', $localOnly = false ) {
    if ( !$this->check_db() ) {
      return false;
    }

    // Ensure type is never null - default to 'manual' if not set
    if ( !isset( $vector['type'] ) || is_null( $vector['type'] ) ) {
      $vector['type'] = 'manual';
    }

    // Validate remoteUrl type requires refUrl - coerce to manual if missing (safer for imports)
    if ( $vector['type'] === 'remoteUrl' && empty( $vector['refUrl'] ) ) {
      $vector['type'] = 'manual';
    }

    // Handle Remote URL type - always fetch content from URL (ignore any provided content)
    if ( $vector['type'] === 'remoteUrl' && !empty( $vector['refUrl'] ) ) {
      $fetch_result = $this->fetch_url_content( $vector['refUrl'] );
      if ( !$fetch_result['success'] ) {
        throw new Exception( 'Failed to fetch URL: ' . $fetch_result['error'] );
      }

      $vector['content'] = $fetch_result['content'];
      $vector['refChecksum'] = $fetch_result['checksum'];

      // Apply rewrite if enabled (uses remote-specific settings)
      if ( $this->rewrite_remote_content && !empty( $this->rewrite_remote_prompt ) ) {
        $vector['content'] = $this->prepare_remote_url_content( $vector['refUrl'], $vector['content'] );
      }
    }

    // Handle Media type - read image and build metadata content
    if ( $vector['type'] === 'mediaId' && !empty( $vector['refId'] ) ) {
      $mediaData = $this->prepare_media_content( $vector['refId'] );
      if ( !$mediaData ) {
        throw new Exception( 'Could not read the media file.' );
      }
      $vector['content'] = $mediaData['content'];
      $vector['title'] = !empty( $vector['title'] ) ? $vector['title'] : $mediaData['title'];
      $vector['_imageData'] = $mediaData['imageData'];
      $vector['_imageMimeType'] = $mediaData['imageMimeType'];
    }

    // If it doesn't have content, it's basically an empty vector
    // that needs to be processed later, through the UI.
    $hasContent = isset( $vector['content'] ) && !empty( $vector['content'] );

    if ( $hasContent && strlen( $vector['content'] ) > 65535 ) {
      throw new Exception( 'The content of the embedding is too long (max 65535 characters).' );
    }

    $envId = isset( $vector['envId'] ) && !empty( $vector['envId'] ) ? $vector['envId'] : $this->default_envId;

    if ( empty( $envId ) ) {
      throw new Exception( 'Cannot add vector: no environment specified and no default environment configured' );
    }

    // Resolve a default source value here so it is persisted on the row even if no filter runs.
    $resolvedSource = $this->resolve_source_default( $vector );
    $partIndexInsert = isset( $vector['partIndex'] ) && $vector['partIndex'] !== '' ? (int) $vector['partIndex'] : null;
    $partTotalInsert = isset( $vector['partTotal'] ) && $vector['partTotal'] !== '' ? (int) $vector['partTotal'] : null;

    $success = $this->wpdb->insert(
      $this->table_vectors,
      [
        'type' => $vector['type'],
        'title' => $vector['title'],
        'content' => $hasContent ? $vector['content'] : '',
        'refId' => !empty( $vector['refId'] ) ? $vector['refId'] : null,
        'refUrl' => !empty( $vector['refUrl'] ) ? $vector['refUrl'] : null,
        'refChecksum' => !empty( $vector['refChecksum'] ) ? $vector['refChecksum'] : null,
        'source' => $resolvedSource,
        'partIndex' => $partIndexInsert,
        'partTotal' => $partTotalInsert,
        'envId' => $envId,
        'dbId' => isset( $vector['dbId'] ) ? $vector['dbId'] : null,
        'status' => $status,
        'updated' => date( 'Y-m-d H:i:s' ),
        'created' => date( 'Y-m-d H:i:s' )
      ]
    );

    if ( !$success ) {
      $error = $this->wpdb->last_error;
      throw new Exception( $error );
    }

    if ( !$localOnly ) {
      if ( !$hasContent ) {
        return true;
      }
      $vector['id'] = $this->wpdb->insert_id;

      $env = $this->core->get_embeddings_env( $envId );

      // Check if this is an OpenAI Vector Store (server-managed embeddings)
      $isOpenAIVectorStore = isset( $env['type'] ) && $env['type'] === 'openai-vector-store';

      try {
        $embeddings_source = isset( $env['embeddings_source'] ) ? $env['embeddings_source'] : null;

        if ( $this->should_use_chroma_embeddings( $env ) ) {
          // Use Chroma Cloud for embedding generation
          $embeddingVector = apply_filters( 'mwai_chroma_generate_embedding', null, $vector['content'], $envId );

          if ( !$embeddingVector ) {
            throw new Exception( 'Failed to generate embedding using Chroma Cloud.' );
          }

          $vector['embedding'] = $embeddingVector;
          $vector['model'] = $embeddings_source;
          $vector['dimensions'] = count( $embeddingVector );
        }
        else {
          // Generate embeddings using AI Engine (default)
          // This works for all vector stores (including OpenAI Vector Store)
          // and allows external access to work properly
          $queryEmbed = new Meow_MWAI_Query_Embed( $vector['content'] );
          $queryEmbed->set_scope( 'admin-tools' );

          if ( !empty( $vector['_imageData'] ) ) {
            $queryEmbed->set_image_data( $vector['_imageData'], $vector['_imageMimeType'] );
          }

          $override = isset( $env['ai_embeddings_override'] ) && $env['ai_embeddings_override'] === true;
          if ( $override ) {
            $queryEmbed->set_env_id( $env['ai_embeddings_env'] );
            $queryEmbed->set_model( $env['ai_embeddings_model'] );
            if ( !empty( $env['ai_embeddings_dimensions'] ) ) {
              $queryEmbed->set_dimensions( $env['ai_embeddings_dimensions'] );
            }
          }

          $reply = $this->core->run_query( $queryEmbed );
          $vector['embedding'] = $reply->result;
          $vector['model'] = $queryEmbed->model;
          $vector['dimensions'] = count( $reply->result );
        }

        // Resolve and stash metadata so each provider addon writes the same payload (built-ins + meta).
        $vector['_metadata'] = $this->build_vector_metadata( $vector );

        $dbId = apply_filters( 'mwai_embeddings_add_vector', false, $vector, [
          'envId' => $envId,
        ] );
        if ( $dbId ) {
          $vector['dbId'] = $dbId;
          $this->wpdb->update( $this->table_vectors, [
            'dbId' => $dbId,
            'model' => $vector['model'],
            'dimensions' => $vector['dimensions'],
            'status' => 'ok',
            'error' => null  // Clear any previous errors
          ], [ 'id' => $vector['id'] ], [ '%s', '%s', '%d', '%s', '%s' ], [ '%d' ] );
        }
        else {
          $envName = isset( $env['name'] ) ? $env['name'] : $envId;
          $envType = isset( $env['type'] ) ? $env['type'] : 'unknown';
          throw new Exception( "AI Engine: Could not add the vector to the Vector DB. The embeddings environment '{$envName}' (type: {$envType}) is not configured properly. Check your environment settings and the PHP error logs for more details." );
        }
      }
      catch ( Exception $e ) {
        $error = $e->getMessage();
        // Skip duplicate logging for our own "Could not add the vector" wrapper — the addon
        // already logged a more specific reason. The error is preserved on the row's `error`
        // column for the user to see.
        if ( strpos( $error, 'AI Engine: Could not add the vector' ) !== 0 ) {
          Meow_MWAI_Logging::error( $error );
        }
        $this->wpdb->update(
          $this->table_vectors,
          [ 'dbId' => null, 'status' => 'error', 'error' => $error ],
          [ 'id' => $vector['id'] ],
          [ '%s', '%s', '%s' ],
          [ '%d' ]
        );
        return $this->get_vector( $vector['id'] );
      }
    }

    if ( !empty( $vector['dbId'] ) ) {
      return $this->get_vector_by_remoteId( $vector['dbId'] );
    }

    return null;
  }

  public function get_vectors_by_refId( $refId, $envId = null ) {
    if ( !$this->check_db() ) {
      return false;
    }
    $query = "SELECT * FROM {$this->table_vectors}";
    $where = [];
    $where[] = "refId = '" . esc_sql( $refId ) . "'";
    if ( !empty( $envId ) ) {
      $where[] = "envId = '" . esc_sql( $envId ) . "'";
    }
    $query .= ' WHERE ' . implode( ' AND ', $where );
    $vectors = $this->wpdb->get_results( $query, ARRAY_A );
    return $vectors;
  }

  // This function is a bit tricky, because it can do many things.
  // $fallbackEnvId can be used when the current envId is null or not available anymore.
  public function update_vector( $vector = [], $fallbackEnvId = null ) {
    if ( !$this->check_db() ) {
      return false;
    }
    if ( empty( $vector['id'] ) ) {
      throw new Exception( 'Missing ID' );
    }
    $originalVector = $this->get_vector( $vector['id'] );
    if ( !$originalVector ) {
      throw new Exception( 'Vector not found' );
    }
    $newContent = $originalVector['content'] !== $vector['content'];
    $wasError = $originalVector['status'] === 'error';
    $envId = isset( $vector['envId'] ) ? $vector['envId'] : $originalVector['envId'];
    $env = $this->core->get_embeddings_env( $envId );
    if ( !$env ) {
      if ( $fallbackEnvId ) {
        $env = $this->core->get_embeddings_env( $fallbackEnvId );
        if ( !$env ) {
          throw new Exception( "The fallback environment (envId: $fallbackEnvId) is not available." );
        }
        $envId = $fallbackEnvId;
      }
      else {
        throw new Exception( "The environment (envId: $envId) is not available." );
      }
    }
    $newEnv = $envId !== $originalVector['envId'];
    $override = isset( $env['ai_embeddings_override'] ) && $env['ai_embeddings_override'] === true;
    $ai_model = $override ? $env['ai_embeddings_model'] : null;
    $newModel = $ai_model !== $originalVector['model'];
    $ai_dimensions = $override ?
    ( isset( $env['ai_embeddings_dimensions'] ) ? $env['ai_embeddings_dimensions'] : null ) : null;
    $newDimensions = !empty( $ai_dimensions ) && $ai_dimensions !== $originalVector['dimensions'];

    if ( $newContent || $wasError || $newModel || $newEnv || $newDimensions ) {

      // Update the vector (to mark it as processing)
      $sourceUpdate = $this->resolve_source_default( $vector );
      $partIndexUpdate = isset( $vector['partIndex'] ) && $vector['partIndex'] !== '' ? (int) $vector['partIndex'] : null;
      $partTotalUpdate = isset( $vector['partTotal'] ) && $vector['partTotal'] !== '' ? (int) $vector['partTotal'] : null;
      $this->wpdb->update(
        $this->table_vectors,
        [
          'type' => $vector['type'],
          'title' => $vector['title'],
          'content' => $vector['content'],
          'refId' => !empty( $vector['refId'] ) ? $vector['refId'] : null,
          'refUrl' => !empty( $vector['refUrl'] ) ? $vector['refUrl'] : null,
          'envId' => $envId,
          'refChecksum' => !empty( $vector['refChecksum'] ) ? $vector['refChecksum'] : null,
          'source' => $sourceUpdate,
          'partIndex' => $partIndexUpdate,
          'partTotal' => $partTotalUpdate,
          'status' => ( $newContent || $wasError ) ? 'processing' : 'ok',
          'updated' => date( 'Y-m-d H:i:s' )
        ],
        [ 'id' => $vector['id'] ]
      );

      try {

        // Delete the original vector
        if ( !empty( $originalVector['dbId'] ) ) {
          $options = [
            'envId' => $originalVector['envId'],
            'ids' => [ $originalVector['dbId'] ],
            'deleteAll' => false
          ];
          apply_filters( 'mwai_embeddings_delete_vectors', [], $options );
        }

        // Check if this is an OpenAI Vector Store (server-managed embeddings)
        $isOpenAIVectorStore = isset( $env['type'] ) && $env['type'] === 'openai-vector-store';

        if ( $isOpenAIVectorStore ) {
          // For OpenAI Vector Store, embeddings are handled server-side
          $vector['embedding'] = null;
          $vector['model'] = null;
          $vector['dimensions'] = null;
        }
        else {
          $embeddings_source = isset( $env['embeddings_source'] ) ? $env['embeddings_source'] : null;

          if ( $this->should_use_chroma_embeddings( $env ) ) {
            // Use Chroma Cloud for embedding generation
            $embeddingVector = apply_filters( 'mwai_chroma_generate_embedding', null, $vector['content'], $envId );

            if ( !$embeddingVector ) {
              throw new Exception( 'Failed to generate embedding using Chroma Cloud.' );
            }

            $vector['embedding'] = $embeddingVector;
            $vector['model'] = $embeddings_source;
            $vector['dimensions'] = count( $embeddingVector );
          }
          else {
            // Create the embedding using AI Engine (default)
            $queryEmbed = new Meow_MWAI_Query_Embed( $vector['content'] );
            $queryEmbed->set_scope( 'admin-tools' );
            $ai_env = $override ? $env['ai_embeddings_env'] : null;
            if ( !empty( $ai_env ) && !empty( $ai_model ) ) {
              $queryEmbed->set_env_id( $ai_env );
              $queryEmbed->set_model( $ai_model );
              if ( !empty( $ai_dimensions ) ) {
                $queryEmbed->set_dimensions( $ai_dimensions );
              }
            }

            $reply = $this->core->run_query( $queryEmbed );
            $vector['embedding'] = $reply->result;
            $vector['model'] = $queryEmbed->model;
            $vector['dimensions'] = count( $reply->result );
          }
        }

        // Re-add the vector
        $vector['_metadata'] = $this->build_vector_metadata( $vector );
        $dbId = apply_filters( 'mwai_embeddings_add_vector', false, $vector, [ 'envId' => $envId ] );
        if ( $dbId ) {
          $this->wpdb->update(
            $this->table_vectors,
            [
              'dbId' => $dbId,
              'status' => 'ok',
              'model' => $vector['model'],
              'dimensions' => $vector['dimensions'],
              'refUrl' => !empty( $vector['refUrl'] ) ? $vector['refUrl'] : null,
              'refChecksum' => !empty( $vector['refChecksum'] ) ? $vector['refChecksum'] : null,
              'error' => null,  // Clear any previous errors
              'updated' => date( 'Y-m-d H:i:s' )
            ],
            [ 'id' => $vector['id'] ]
          );
        }
        else {
          $envName = isset( $env['name'] ) ? $env['name'] : $envId;
          $envType = isset( $env['type'] ) ? $env['type'] : 'unknown';
          throw new Exception( "AI Engine: Could not update the vector in the Vector DB. The embeddings environment '{$envName}' (type: {$envType}) is not configured properly. Check your environment settings and the PHP error logs for more details." );
        }
      }
      catch ( Exception $e ) {
        $error = $e->getMessage();
        // Same de-duplication as vectors_add: skip the wrapper if the addon already logged the cause.
        if ( strpos( $error, 'AI Engine: Could not update the vector' ) !== 0 ) {
          Meow_MWAI_Logging::error( $error );
        }
        $this->wpdb->update(
          $this->table_vectors,
          [ 'dbId' => null, 'status' => 'error', 'error' => $error, 'updated' => date( 'Y-m-d H:i:s' ) ],
          [ 'id' => $vector['id'] ]
        );
      }
    }
    else {
      // Metadata-only edit (no content/env/model change). Persist locally; users can trigger a
      // remote resync by editing the content, which will re-run the metadata filter.
      $sourceEdit = $this->resolve_source_default( $vector );
      $partIndexEdit = isset( $vector['partIndex'] ) && $vector['partIndex'] !== '' ? (int) $vector['partIndex'] : null;
      $partTotalEdit = isset( $vector['partTotal'] ) && $vector['partTotal'] !== '' ? (int) $vector['partTotal'] : null;
      $changed = $originalVector['type'] !== $vector['type']
        || $originalVector['title'] !== $vector['title']
        || ( $originalVector['source'] ?? null ) !== $sourceEdit
        || ( $originalVector['partIndex'] ?? null ) != $partIndexEdit
        || ( $originalVector['partTotal'] ?? null ) != $partTotalEdit;
      if ( $changed ) {
        $this->wpdb->update(
          $this->table_vectors,
          [
            'type' => $vector['type'],
            'title' => $vector['title'],
            'source' => $sourceEdit,
            'partIndex' => $partIndexEdit,
            'partTotal' => $partTotalEdit,
            'updated' => date( 'Y-m-d H:i:s' )
          ],
          [ 'id' => $vector['id'] ]
        );
      }
    }

    return $this->get_vector( $vector['id'] );
  }

  public function get_vector( $id ) {
    if ( !$this->check_db() ) {
      return null;
    }
    $vector = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM $this->table_vectors WHERE id = %d", $id ), ARRAY_A );
    return $vector;
  }

  public function get_vector_by_remoteId( $remoteId ) {
    if ( !$this->check_db() ) {
      return null;
    }
    $vector = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM $this->table_vectors WHERE dbId = %s", $remoteId ), ARRAY_A );
    return $vector;
  }

  public function get_vector_metadata_from_remote( $vectorId, $envId ) {
    $options = [ 'envId' => $envId ];
    $vector = apply_filters( 'mwai_embeddings_get_vector', null, $vectorId, $envId, $options );
    return $vector;
  }

  public function query_vectors( $offset = 0, $limit = null, $filters = null, $sort = null ) {
    if ( !$this->check_db() ) {
      return [ 'total' => 0, 'rows' => [] ];
    }
    $filters = !empty( $filters ) ? $filters : [];
    $envId = $filters['envId'];
    $debugMode = isset( $filters['debugMode'] ) ? $filters['debugMode'] : false;
    if ( empty( $envId ) ) {
      throw new Exception( 'The envId is required.' );
    }
    $includeAll = $debugMode === 'includeAll';
    $includeOrphans = $debugMode === 'includeOrphans';

    if ( $includeAll ) {
      unset( $filters['envId'] );
    }

    // Is AI Search
    $isAiSearch = !empty( $filters['search'] );
    $matchedVectors = [];
    if ( $isAiSearch ) {
      $query = $filters['search'];
      $env = $this->core->get_embeddings_env( $envId );

      // Check if this is an OpenAI Vector Store environment
      if ( isset( $env['type'] ) && $env['type'] === 'openai-vector-store' ) {
        // For Vector Store, pass the search query text directly without generating embeddings
        $options = [
          'envId' => $envId,
          'searchQuery' => $query // Pass the text query directly
        ];
        $matchedVectors = apply_filters( 'mwai_embeddings_query_vectors', [], $query, $options );
      }
      else {
        if ( $this->should_use_chroma_embeddings( $env ) ) {
          // Use Chroma Cloud for query embedding generation
          $queryEmbedding = apply_filters( 'mwai_chroma_generate_embedding', null, $query, $envId );

          if ( !$queryEmbedding ) {
            return [ 'total' => 0, 'rows' => [] ];
          }

          $matchedVectors = $this->query_db( $queryEmbedding, $envId );
        }
        else {
          // For other environments (Pinecone, Qdrant, Chroma with AI Engine), generate embeddings as usual
          $queryEmbed = new Meow_MWAI_Query_Embed( $query );
          $queryEmbed->set_scope( 'admin-tools' );

          $override = isset( $env['ai_embeddings_override'] ) && $env['ai_embeddings_override'] === true;
          if ( $override ) {
            $queryEmbed->set_env_id( $env['ai_embeddings_env'] );
            $queryEmbed->set_model( $env['ai_embeddings_model'] );
            if ( !empty( $env['ai_embeddings_dimensions'] ) ) {
              $queryEmbed->set_dimensions( $env['ai_embeddings_dimensions'] );
            }
          }

          $reply = $this->core->run_query( $queryEmbed );
          $matchedVectors = $this->query_db( $reply->result, $envId );
        }
      }
      if ( empty( $matchedVectors ) ) {
        return [ 'total' => 0, 'rows' => [] ];
      }
      $minScore = isset( $env['min_score'] ) ? (float) $env['min_score'] : 35;
      $matchedVectors = array_filter( $matchedVectors, function ( $vector ) use ( $minScore ) {
        return ( $vector['score'] * 100 ) >= $minScore;
      } );
    }

    $offset = !empty( $offset ) ? intval( $offset ) : 0;
    $limit = !empty( $limit ) ? intval( $limit ) : 100;
    $sort = !empty( $sort ) ? $sort : [ 'accessor' => 'created', 'by' => 'desc' ];
    $query = "SELECT * FROM $this->table_vectors";

    // Filters
    $where = [];
    if ( isset( $filters['type'] ) ) {
      $where[] = "type = '" . esc_sql( $filters['type'] ) . "'";
    }
    // Exclude one or more types — used by the Embeddings tab (when an env is OpenAI
    // Vector Store) to hide rows that belong in the Documents tab.
    if ( !empty( $filters['excludeTypes'] ) ) {
      $excluded = is_array( $filters['excludeTypes'] ) ? $filters['excludeTypes'] : [ $filters['excludeTypes'] ];
      $excluded = array_map( 'esc_sql', $excluded );
      $where[] = "type NOT IN ('" . implode( "','", $excluded ) . "')";
    }
    if ( !empty( $filters['title'] ) ) {
      $where[] = "title LIKE '%" . esc_sql( $filters['title'] ) . "%'";
    }
    if ( !empty( $filters['ref'] ) ) {
      $ref = esc_sql( $filters['ref'] );
      $where[] = "(refId LIKE '%{$ref}%' OR refChecksum LIKE '%{$ref}%')";
    }

    if ( $includeOrphans ) {
      $envs = $this->core->get_option( 'embeddings_envs' );
      $envIds = array_map( function ( $env ) { return $env['id']; }, $envs );
      $envIds = array_diff( $envIds, [ $envId ] );
      $where[] = "envId NOT IN ('" . implode( "','", $envIds ) . "')";
    }
    else if ( isset( $filters['envId'] ) ) {
      $where[] = "envId = '" . esc_sql( $filters['envId'] ) . "'";
    }

    // $dbIds is an array of strings
    $dbIds = [];
    $rawDbIds = [];
    if ( $isAiSearch ) {
      if ( empty( $matchedVectors ) ) {
        return [ 'total' => 0, 'rows' => [] ];
      }
      foreach ( $matchedVectors as $vector ) {
        $dbIds[] = "'" . $vector['id'] . "'";
        $rawDbIds[] = $vector['id'];
      }
      if ( !empty( $dbIds ) ) {
        $where[] = 'dbId IN (' . implode( ',', $dbIds ) . ')';
      }
    }
    if ( count( $where ) > 0 ) {
      $query .= ' WHERE ' . implode( ' AND ', $where );
    }

    // Count based on this query
    $vectors['total'] = (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM ($query) AS t" );

    // Order by
    if ( !$isAiSearch ) {
      $query .= ' ORDER BY ' . esc_sql( $sort['accessor'] ) . ' ' . esc_sql( $sort['by'] );
    }

    // Limits
    if ( !$isAiSearch && $limit > 0 ) {
      $query .= " LIMIT $offset, $limit";
    }

    $vectors['rows'] = $this->wpdb->get_results( $query, ARRAY_A );

    // Consolidate results
    foreach ( $vectors['rows'] as $key => &$vectorRow ) {
      if ( $vectorRow['type'] === 'postId' ) {
        // Get the Post Type
        $vectorRow['subType'] = get_post_type( $vectorRow['refId'] );
      }
    }

    // If it's an AI Search, we need to update the score of the vectors
    if ( $isAiSearch ) {

      // If the count of the result vectors is less than the $ids, then we need to add the missing ones
      if ( $vectors['total'] < count( $rawDbIds ) ) {
        $missingIds = array_diff( $rawDbIds, array_column( $vectors['rows'], 'dbId' ) );
        foreach ( $missingIds as $missingId ) {
          $newRow = $this->pull_vector_from_remote( $missingId, $envId );
          if ( !empty( $newRow ) ) {
            $vectors['rows'][] = $newRow;
          }
        }
      }

      foreach ( $vectors['rows'] as &$vectorRow ) {
        $dbId = $vectorRow['dbId'];
        $queryVector = null;
        foreach ( $matchedVectors as $vector ) {
          if ( (string) $vector['id'] === (string) $dbId ) {
            $queryVector = $vector;
            break;
          }
        }
        if ( !empty( $queryVector ) ) {
          $vectorRow['score'] = $queryVector['score'];
        }
      }
      unset( $vectorRow );
    }

    return $vectors;
  }

  #endregion

  #region DB Setup

  public function create_db() {
    $charset_collate = $this->wpdb->get_charset_collate();
    $sqlVectors = "CREATE TABLE $this->table_vectors (
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          id BIGINT(20) NOT NULL AUTO_INCREMENT,
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            type VARCHAR(32) NULL,
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              title VARCHAR(255) NULL,
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                content TEXT NULL,
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                behavior VARCHAR(32) DEFAULT 'context' NOT NULL,
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  status VARCHAR(32) NULL,
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    envId VARCHAR(64) NULL,
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      model VARCHAR(64) NULL,
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        dimensions SMALLINT NULL,
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        dbId VARCHAR(64) NULL,
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          refId BIGINT(20) NULL,
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            refChecksum VARCHAR(64) NULL,
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              source VARCHAR(2048) NULL,
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              partIndex SMALLINT NULL,
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              partTotal SMALLINT NULL,
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              error TEXT NULL,
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              created DATETIME NOT NULL,
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              updated DATETIME NOT NULL,
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              PRIMARY KEY  (id)
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              ) $charset_collate;";
    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sqlVectors );
  }

  public function check_db() {
    if ( $this->db_check ) {
      return true;
    }

    // Per-module version check: skip SHOW TABLES if already verified for this version.
    if ( get_option( 'mwai_db_version_embeddings' ) === MWAI_VERSION ) {
      $this->db_check = true;
      return true;
    }

    $tableExists = !( strtolower( $this->wpdb->get_var( "SHOW TABLES LIKE '$this->table_vectors'" ) ) != strtolower( $this->table_vectors ) );
    if ( !$tableExists ) {
      $this->create_db();
      $tableExists = !( strtolower( $this->wpdb->get_var( "SHOW TABLES LIKE '$this->table_vectors'" ) ) != strtolower( $this->table_vectors ) );
    }
    $this->db_check = $tableExists;

    // Migration: Add refUrl column for Remote URL embeddings
    if ( $tableExists && !$this->wpdb->get_var( "SHOW COLUMNS FROM $this->table_vectors LIKE 'refUrl'" ) ) {
      $this->wpdb->query( "ALTER TABLE $this->table_vectors ADD COLUMN refUrl VARCHAR(2048) NULL AFTER refId" );
    }

    // Migration: Add source / partIndex / partTotal for chunk provenance and metadata filtering.
    if ( $tableExists && !$this->wpdb->get_var( "SHOW COLUMNS FROM $this->table_vectors LIKE 'source'" ) ) {
      $this->wpdb->query( "ALTER TABLE $this->table_vectors ADD COLUMN source VARCHAR(2048) NULL AFTER refChecksum" );
    }
    else if ( $tableExists ) {
      // Widen pre-release installs that got VARCHAR(512). Idempotent at the SQL level.
      $sourceCol = $this->wpdb->get_row( "SHOW COLUMNS FROM $this->table_vectors LIKE 'source'", ARRAY_A );
      if ( $sourceCol && stripos( $sourceCol['Type'] ?? '', 'varchar(2048)' ) === false ) {
        $this->wpdb->query( "ALTER TABLE $this->table_vectors MODIFY COLUMN source VARCHAR(2048) NULL" );
      }
    }
    if ( $tableExists && !$this->wpdb->get_var( "SHOW COLUMNS FROM $this->table_vectors LIKE 'partIndex'" ) ) {
      $this->wpdb->query( "ALTER TABLE $this->table_vectors ADD COLUMN partIndex SMALLINT NULL AFTER source" );
    }
    if ( $tableExists && !$this->wpdb->get_var( "SHOW COLUMNS FROM $this->table_vectors LIKE 'partTotal'" ) ) {
      $this->wpdb->query( "ALTER TABLE $this->table_vectors ADD COLUMN partTotal SMALLINT NULL AFTER partIndex" );
    }

    if ( $this->db_check ) {
      update_option( 'mwai_db_version_embeddings', MWAI_VERSION, true );
    }

    return $this->db_check;
  }

  #endregion

  #region Text Processing

  public function smart_text_chunking( $text, $pageTexts, $maxChunkSize, $overlap, $fileName ) {
    $chunks = [];
    $sentences = $this->split_into_sentences( $text );
    $currentChunk = '';
    $currentTokens = 0;
    $chunkIndex = 0;
    $sentenceIndex = 0;

    // Use fast mode for very large documents or high chunk counts
    $estimatedChunks = ceil( strlen( $text ) / ( $maxChunkSize * 3 ) ); // Rough estimate
    $useFastMode = $estimatedChunks > 20 || strlen( $text ) > 100000;

    if ( $useFastMode ) {
      Meow_MWAI_Logging::log( "Using fast chunking mode for large document (estimated chunks: $estimatedChunks)" );
    }

    // Debug page texts
    if ( !empty( $pageTexts ) ) {
      Meow_MWAI_Logging::log( 'PDF has ' . count( $pageTexts ) . ' pages' );
    }

    // Log token estimation comparison for the entire document
    $total_tiktoken_tokens = $this->estimate_tokens( $text );
    $old_estimate_total = ceil( strlen( $text ) / 4 );
    $estimated_chunks_tiktoken = ceil( $total_tiktoken_tokens / $maxChunkSize );
    $estimated_chunks_old = ceil( $old_estimate_total / $maxChunkSize );
    error_log( '[AI Engine Chunking] Document analysis - Length: ' . strlen( $text ) . ' chars' );
    error_log( "[AI Engine Chunking] Tiktoken: $total_tiktoken_tokens tokens → ~$estimated_chunks_tiktoken chunks" );
    error_log( "[AI Engine Chunking] Old method: $old_estimate_total tokens → ~$estimated_chunks_old chunks" );
    error_log( '[AI Engine Chunking] Difference: ' . abs( $estimated_chunks_tiktoken - $estimated_chunks_old ) . ' chunks' );

    // Skip page range calculation for documents that will create too many chunks
    // This is the main bottleneck for Very High density
    $skipPageRange = $estimated_chunks_tiktoken > 500 || $maxChunkSize <= 200;
    if ( $skipPageRange ) {
      error_log( '[AI Engine Chunking] Skipping page range calculation for performance (too many chunks)' );
    }

    while ( $sentenceIndex < count( $sentences ) ) {
      $sentence = $sentences[$sentenceIndex];
      $sentenceTokens = $this->estimate_tokens( $sentence );

      // If adding this sentence would exceed the max chunk size
      if ( $currentTokens + $sentenceTokens > $maxChunkSize && !empty( $currentChunk ) ) {
        // Create chunk
        $title = $this->generate_chunk_title( $currentChunk, $chunkIndex, $fileName, $useFastMode );
        $pageRange = $skipPageRange ? '' : $this->calculate_page_range( $currentChunk, $pageTexts );

        $chunks[] = [
          'title' => $title,
          'content' => trim( $currentChunk ),
          'tokens' => $currentTokens,
          'pageRange' => $pageRange
        ];

        // Calculate overlap start point
        $overlapStart = max( 0, $sentenceIndex - $this->calculate_overlap_sentences( $overlap, $sentences, $sentenceIndex ) );

        // Reset for next chunk with overlap
        $currentChunk = '';
        $currentTokens = 0;
        $sentenceIndex = $overlapStart;
        $chunkIndex++;
      }
      else {
        // Add sentence to current chunk
        $currentChunk .= $sentence . ' ';
        $currentTokens += $sentenceTokens;
        $sentenceIndex++;
      }
    }

    // Add remaining content as final chunk
    if ( !empty( trim( $currentChunk ) ) ) {
      $title = $this->generate_chunk_title( $currentChunk, $chunkIndex, $fileName, $useFastMode );
      $pageRange = $skipPageRange ? '' : $this->calculate_page_range( $currentChunk, $pageTexts );

      $chunks[] = [
        'title' => $title,
        'content' => trim( $currentChunk ),
        'tokens' => $currentTokens,
        'pageRange' => $pageRange
      ];
    }

    return $chunks;
  }

  public function split_into_sentences( $text ) {
    // Split by sentence endings, keeping the punctuation
    $sentences = preg_split( '/(?<=[.!?])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY );

    // Further split very long sentences
    $result = [];
    foreach ( $sentences as $sentence ) {
      if ( strlen( $sentence ) > 500 ) {
        // Split long sentences by commas or semicolons
        $parts = preg_split( '/(?<=[,;])\s+/', $sentence );
        $result = array_merge( $result, $parts );
      }
      else {
        $result[] = $sentence;
      }
    }

    return $result;
  }

  public function estimate_tokens( $text, $model = null ) {
    // For very short text (common with Very High density), use fallback to avoid memory issues
    if ( strlen( $text ) < 100 ) {
      // Simple estimation for short text to avoid tiktoken overhead
      return (int) ( strlen( $text ) / 4 );
    }

    // Use centralized token estimation (always uses cl100k_base encoder regardless of model)
    return Meow_MWAI_Core::estimate_tokens( $text, $model );
  }

  public function calculate_overlap_sentences( $overlapTokens, $sentences, $currentIndex ) {
    $tokens = 0;
    $count = 0;

    // Work backwards from current position
    for ( $i = $currentIndex - 1; $i >= 0 && $tokens < $overlapTokens; $i-- ) {
      $tokens += $this->estimate_tokens( $sentences[$i] );
      $count++;
    }

    return $count;
  }

  public function generate_chunk_title( $content, $index, $fileName, $useFastMode = false ) {
    global $mwai;

    // Clean filename for fallback
    $cleanFileName = pathinfo( $fileName, PATHINFO_FILENAME );

    // Skip AI generation in fast mode
    if ( !$useFastMode ) {
      try {
        // Extract first 1-2 paragraphs for faster title generation (skip overlap if present)
        $paragraphs = preg_split( '/\n\n+/', $content, -1, PREG_SPLIT_NO_EMPTY );
        $snippetParagraphs = array_slice( $paragraphs, 0, 2 ); // Get first 2 paragraphs only

        // If we have overlap, try to skip the first paragraph if it's likely overlap content
        if ( count( $paragraphs ) > 3 && $index > 0 ) {
          // Use paragraph 2 and 3 instead of 1 and 2 to avoid overlap content
          $snippetParagraphs = array_slice( $paragraphs, 1, 2 );
        }

        $snippet = implode( "\n\n", $snippetParagraphs );

        // Limit to max 500 characters for faster title generation
        if ( strlen( $snippet ) > 500 ) {
          $snippet = substr( $snippet, 0, 500 );
        }

        // Use AI to generate a meaningful title with simpler prompt
        $prompt = "Generate a concise title (max 40 chars) for this text. Reply with ONLY the title:\n\n" . $snippet;

        // Use fast model for title generation
        $title = $mwai->simpleFastTextQuery( $prompt, [
          'scope' => 'embeddings-title',
          'temperature' => 0.1
        ] );

        // Clean up the AI response
        $title = trim( $title );
        $title = trim( $title, '"' ); // Remove quotes if present
        $title = trim( $title, "'" );

        // Validate the title
        if ( !empty( $title ) && strlen( $title ) <= 100 && strlen( $title ) > 5 ) {
          return $title;
        }
      }
      catch ( Exception $e ) {
        // If AI fails, fall back to simpler method
        Meow_MWAI_Logging::error( 'Failed to generate chunk title: ' . $e->getMessage() );
      }
    }

    // Fallback: Extract first sentence or meaningful text
    $sentences = $this->split_into_sentences( $content );
    if ( !empty( $sentences ) ) {
      $firstSentence = $sentences[0];

      // Try to find a more meaningful sentence if the first is too short
      foreach ( $sentences as $sentence ) {
        if ( strlen( trim( $sentence ) ) > 20 ) {
          $firstSentence = $sentence;
          break;
        }
      }

      // Truncate if too long
      if ( strlen( $firstSentence ) > 100 ) {
        $firstSentence = substr( $firstSentence, 0, 97 ) . '...';
      }

      // Use first sentence if it's meaningful
      if ( strlen( trim( $firstSentence ) ) > 10 ) {
        return trim( $firstSentence );
      }
    }

    // Final fallback: filename + chunk number
    return sprintf( '%s - Part %d', $cleanFileName, $index + 1 );
  }

  public function chapter_based_chunking( $text, $pageTexts, $detectedHeadings, $fileName ) {
    $chunks = [];
    $lines = explode( "\n", $text );

    // Sort headings by their position in the text
    usort( $detectedHeadings, function ( $a, $b ) {
      return $a['pageIndex'] <=> $b['pageIndex'];
    } );

    // Find heading positions in the full text
    $headingPositions = [];
    foreach ( $detectedHeadings as $heading ) {
      $headingText = $heading['text'];
      $position = strpos( $text, $headingText );
      if ( $position !== false ) {
        $headingPositions[] = [
          'position' => $position,
          'text' => $headingText,
          'pageIndex' => $heading['pageIndex']
        ];
      }
    }

    // Add a virtual ending position
    $headingPositions[] = [
      'position' => strlen( $text ),
      'text' => 'END',
      'pageIndex' => count( $pageTexts )
    ];

    // Create chunks based on chapters
    for ( $i = 0; $i < count( $headingPositions ) - 1; $i++ ) {
      $startPos = $headingPositions[$i]['position'];
      $endPos = $headingPositions[$i + 1]['position'];
      $chapterTitle = $headingPositions[$i]['text'];

      // Extract chapter content
      $chapterContent = substr( $text, $startPos, $endPos - $startPos );
      $chapterContent = trim( $chapterContent );

      if ( !empty( $chapterContent ) ) {
        // Calculate page range
        $startPage = $headingPositions[$i]['pageIndex'] + 1;
        $endPage = $headingPositions[$i + 1]['pageIndex'];
        if ( $endPage > count( $pageTexts ) ) {
          $endPage = count( $pageTexts );
        }

        $pageRange = $startPage === $endPage
          ? sprintf( 'Page %d', $startPage )
          : sprintf( 'Pages %d-%d', $startPage, $endPage );

        $chunks[] = [
          'title' => $chapterTitle,
          'content' => $chapterContent,
          'tokens' => $this->estimate_tokens( $chapterContent ),
          'pageRange' => $pageRange
        ];
      }
    }

    return $chunks;
  }

  public function calculate_page_range( $chunkContent, $pageTexts ) {
    if ( empty( $pageTexts ) ) {
      return '';
    }

    $startPage = 0;
    $endPage = 0;
    $foundPages = [];

    // Clean and normalize chunk content
    $cleanChunkContent = trim( preg_replace( '/\s+/', ' ', $chunkContent ) );

    // Method 1: Try to find exact text matches from the beginning and end of the chunk
    $chunkStart = substr( $cleanChunkContent, 0, 100 ); // First 100 chars
    $chunkEnd = substr( $cleanChunkContent, -100 ); // Last 100 chars

    foreach ( $pageTexts as $pageNum => $pageText ) {
      if ( empty( trim( $pageText ) ) ) {
        continue;
      }

      $cleanPageText = trim( preg_replace( '/\s+/', ' ', $pageText ) );

      // Check if this page contains the start of the chunk
      if ( $startPage === 0 && strlen( $chunkStart ) > 20 ) {
        if ( stripos( $cleanPageText, $chunkStart ) !== false ) {
          $startPage = $pageNum + 1;
          $foundPages[] = $pageNum + 1;
        }
      }

      // Check if this page contains the end of the chunk
      if ( strlen( $chunkEnd ) > 20 && stripos( $cleanPageText, $chunkEnd ) !== false ) {
        $endPage = $pageNum + 1;
        $foundPages[] = $pageNum + 1;
      }
    }

    // Method 2: If exact match didn't work, try finding which pages have content in the chunk
    if ( $startPage === 0 || $endPage === 0 ) {
      $pageMatches = [];

      foreach ( $pageTexts as $pageNum => $pageText ) {
        if ( empty( trim( $pageText ) ) ) {
          continue;
        }

        $cleanPageText = trim( preg_replace( '/\s+/', ' ', $pageText ) );

        // Look for meaningful segments of page text in the chunk
        $segments = $this->extract_text_segments( $cleanPageText, 50 ); // Get 50-char segments
        $matchCount = 0;

        foreach ( $segments as $segment ) {
          if ( strlen( $segment ) > 30 && stripos( $cleanChunkContent, $segment ) !== false ) {
            $matchCount++;
          }
        }

        if ( $matchCount > 0 ) {
          $pageMatches[$pageNum + 1] = $matchCount;
        }
      }

      if ( !empty( $pageMatches ) ) {
        // Only include pages with significant matches
        $maxMatches = max( $pageMatches );
        $threshold = max( 1, $maxMatches * 0.3 ); // At least 30% of max matches

        $significantPages = [];
        foreach ( $pageMatches as $page => $count ) {
          if ( $count >= $threshold ) {
            $significantPages[] = $page;
          }
        }

        if ( !empty( $significantPages ) ) {
          $startPage = min( $significantPages );
          $endPage = max( $significantPages );
        }
      }
    }

    // Fallback: if still no match, use sequential logic based on the PDF
    if ( $startPage === 0 ) {
      // This shouldn't happen with proper text extraction, but provide a fallback
      return 'Pages 1-2';
    }

    // Return appropriate format
    if ( $startPage === $endPage ) {
      return sprintf( 'Page %d', $startPage );
    }

    return sprintf( 'Pages %d-%d', $startPage, $endPage );
  }

  private function extract_text_segments( $text, $segmentLength = 50 ) {
    $segments = [];
    $sentences = preg_split( '/[.!?]+/', $text );

    foreach ( $sentences as $sentence ) {
      $sentence = trim( $sentence );
      if ( strlen( $sentence ) >= $segmentLength ) {
        // Get the beginning of the sentence
        $segments[] = substr( $sentence, 0, $segmentLength );
        // Get the middle if the sentence is long enough
        if ( strlen( $sentence ) > $segmentLength * 2 ) {
          $midPoint = floor( strlen( $sentence ) / 2 );
          $segments[] = substr( $sentence, $midPoint - $segmentLength / 2, $segmentLength );
        }
      }
    }

    return $segments;
  }

  public function rest_test_pinecone( $request ) {
    try {
      $params = $request->get_json_params();
      $env_id = isset( $params['env_id'] ) ? $params['env_id'] : null;

      if ( !$env_id ) {
        throw new Exception( 'Environment ID is required' );
      }

      // Get the environment configuration
      $env = $this->core->get_embeddings_env( $env_id );
      if ( !$env ) {
        throw new Exception( 'Environment not found' );
      }

      if ( $env['type'] !== 'pinecone' ) {
        throw new Exception( 'This test is only for Pinecone environments' );
      }

      // Extract the index name from the server URL
      // Format: https://[index-name]-[project-id].svc.[region].pinecone.io
      // Note: index names can contain hyphens, so we need to capture everything before the last hyphen+alphanumeric segment
      $server_url = $env['server'];
      if ( preg_match( '/https:\/\/(.+)-([a-z0-9]+)\.svc\.[^.]+\.pinecone\.io/', $server_url, $matches ) ) {
        $index_name = $matches[1];
      }
      else {
        throw new Exception( 'Invalid Pinecone server URL format' );
      }

      // Make API call to Pinecone to describe the index
      $url = "https://api.pinecone.io/indexes/{$index_name}";

      $response = wp_remote_get( $url, [
        'headers' => [
          'Api-Key' => $env['apikey'],
          'Content-Type' => 'application/json'
        ],
        'timeout' => 30
      ] );

      if ( is_wp_error( $response ) ) {
        throw new Exception( 'Failed to connect to Pinecone: ' . $response->get_error_message() );
      }

      $body = wp_remote_retrieve_body( $response );
      $data = json_decode( $body, true );
      $http_code = wp_remote_retrieve_response_code( $response );

      if ( $http_code !== 200 ) {
        $error_message = isset( $data['error']['message'] ) ? $data['error']['message'] : 'Unknown error';
        throw new Exception( "Pinecone API error: {$error_message}" );
      }

      // Get expected dimensions
      $expected_dimension = null;
      if ( isset( $env['ai_embeddings_override'] ) && $env['ai_embeddings_override'] && !empty( $env['ai_embeddings_dimensions'] ) ) {
        $expected_dimension = $env['ai_embeddings_dimensions'];
      }
      else {
        $expected_dimension = get_option( 'mwai_ai_embeddings_default_dimensions', 1536 );
      }

      // Prepare response
      $result = [
        'success' => true,
        'index_name' => $data['name'],
        'dimension' => $data['dimension'],
        'expected_dimension' => $expected_dimension,
        'dimension_match' => (int) $data['dimension'] === (int) $expected_dimension,
        'metric' => $data['metric'],
        'ready' => $data['status']['ready'],
        'state' => $data['status']['state'],
        'host' => $data['host']
      ];

      return new WP_REST_Response( $result, 200 );
    }
    catch ( Exception $e ) {
      return new WP_REST_Response( [
        'success' => false,
        'error' => $e->getMessage()
      ], 200 );
    }
  }

  public function rest_test_chroma( $request ) {
    try {
      $params = $request->get_json_params();
      $env_id = isset( $params['env_id'] ) ? $params['env_id'] : null;

      if ( !$env_id ) {
        throw new Exception( 'Environment ID is required' );
      }

      // Get the environment configuration
      $env = $this->core->get_embeddings_env( $env_id );
      if ( !$env ) {
        throw new Exception( 'Environment not found' );
      }

      if ( $env['type'] !== 'chroma' ) {
        throw new Exception( 'This test is only for Chroma environments' );
      }

      // Test connection by listing collections
      $server = isset( $env['server'] ) && !empty( $env['server'] ) ? $env['server'] : 'https://api.trychroma.com';
      // Trim the server URL
      $server = rtrim( trim( $server ), '/' );

      $tenant = isset( $env['tenant'] ) ? $env['tenant'] : null;
      $database = isset( $env['database'] ) && !empty( $env['database'] ) ? $env['database'] : 'default_database';

      // Detect if this is Chroma Cloud
      $isChromaCloud = strpos( $server, 'trychroma.com' ) !== false || strpos( $server, 'chroma.com' ) !== false;

      // Both Chroma Cloud and self-hosted use v2 API
      $tenant = $tenant ?: 'default_tenant';
      $url = $server . "/api/v2/tenants/{$tenant}/databases/{$database}/collections";

      $headers = [
        'Content-Type' => 'application/json'
      ];

      if ( $isChromaCloud ) {
        // Chroma Cloud uses special headers
        if ( isset( $env['apikey'] ) && !empty( $env['apikey'] ) ) {
          $headers['X-Chroma-Token'] = $env['apikey'];
        }
        if ( isset( $env['tenant'] ) && !empty( $env['tenant'] ) ) {
          $headers['X-Chroma-Tenant'] = $env['tenant'];
        }
      }
      else {
        // Self-hosted uses Bearer token
        if ( isset( $env['apikey'] ) && !empty( $env['apikey'] ) ) {
          $headers['Authorization'] = 'Bearer ' . $env['apikey'];
        }
      }

      $response = wp_remote_get( $url, [
        'headers' => $headers,
        'timeout' => 30
      ] );

      if ( is_wp_error( $response ) ) {
        throw new Exception( 'Failed to connect to Chroma: ' . $response->get_error_message() );
      }

      $body = wp_remote_retrieve_body( $response );
      $data = json_decode( $body, true );
      $http_code = wp_remote_retrieve_response_code( $response );

      if ( $http_code !== 200 ) {
        $error_message = 'Unknown error';
        if ( isset( $data['detail'] ) ) {
          $error_message = $data['detail'];
        }
        elseif ( isset( $data['message'] ) ) {
          $error_message = $data['message'];
        }
        elseif ( is_string( $data ) ) {
          $error_message = $data;
        }

        // Provide more helpful error messages
        if ( strpos( $error_message, 'Missing or invalid token' ) !== false ) {
          $error_message = 'Invalid API key or Tenant ID. Please check your Chroma Cloud credentials.';
        }
        elseif ( $http_code === 401 ) {
          $error_message = 'Authentication failed. Please verify your API key and Tenant ID are correct.';
        }

        throw new Exception( "Chroma API error: {$error_message}" );
      }

      // Look for our collection
      $collection_name = isset( $env['collection'] ) ? $env['collection'] : 'mwai';
      $collection_found = false;
      $collection_info = null;

      if ( isset( $data['collections'] ) ) {
        foreach ( $data['collections'] as $col ) {
          if ( isset( $col['name'] ) && $col['name'] === $collection_name ) {
            $collection_found = true;
            $collection_info = $col;
            break;
          }
        }
      }

      // Get expected dimensions
      $expected_dimension = null;
      if ( isset( $env['ai_embeddings_override'] ) && $env['ai_embeddings_override'] && !empty( $env['ai_embeddings_dimensions'] ) ) {
        $expected_dimension = $env['ai_embeddings_dimensions'];
      }
      else {
        $expected_dimension = get_option( 'mwai_ai_embeddings_default_dimensions', 1536 );
      }

      // Prepare response
      $result = [
        'success' => true,
        'collections_count' => count( $data['collections'] ?? [] ),
        'collection_name' => $collection_name,
        'collection_exists' => $collection_found,
        'dimension' => $expected_dimension, // Chroma doesn't store dimensions in collection metadata
        'expected_dimension' => $expected_dimension,
        'dimension_match' => true, // Chroma is flexible with dimensions
        'ready' => true,
        'server' => $server
      ];

      if ( $collection_info ) {
        $result['collection_id'] = $collection_info['id'] ?? null;
        $result['collection_metadata'] = $collection_info['metadata'] ?? [];
      }

      return new WP_REST_Response( $result, 200 );
    }
    catch ( Exception $e ) {
      return new WP_REST_Response( [
        'success' => false,
        'error' => $e->getMessage()
      ], 200 );
    }
  }

  public function rest_test_qdrant( $request ) {
    try {
      $params = $request->get_json_params();
      $env_id = isset( $params['env_id'] ) ? $params['env_id'] : null;

      if ( !$env_id ) {
        throw new Exception( 'Environment ID is required' );
      }

      // Get the environment configuration
      $env = $this->core->get_embeddings_env( $env_id );
      if ( !$env ) {
        throw new Exception( 'Environment not found' );
      }

      if ( $env['type'] !== 'qdrant' ) {
        throw new Exception( 'This test is only for Qdrant environments' );
      }

      // Get Qdrant server and collection info
      $server = isset( $env['server'] ) ? $env['server'] : null;
      $apikey = isset( $env['apikey'] ) ? $env['apikey'] : null;
      $collection = isset( $env['collection'] ) && !empty( $env['collection'] ) ? $env['collection'] : 'mwai';

      if ( !$server ) {
        throw new Exception( 'Qdrant server URL is required' );
      }

      // Test connection by getting collection info
      $url = rtrim( $server, '/' ) . "/collections/{$collection}";

      $headers = [
        'accept' => 'application/json',
        'content-type' => 'application/json'
      ];

      if ( $apikey ) {
        $headers['api-key'] = $apikey;
      }

      $response = wp_remote_get( $url, [
        'headers' => $headers,
        'timeout' => 30,
        'sslverify' => MWAI_SSL_VERIFY
      ] );

      if ( is_wp_error( $response ) ) {
        throw new Exception( 'Failed to connect to Qdrant: ' . $response->get_error_message() );
      }

      $body = wp_remote_retrieve_body( $response );
      $data = json_decode( $body, true );
      $http_code = wp_remote_retrieve_response_code( $response );

      $collection_exists = false;
      $vector_size = null;
      $points_count = 0;

      // Check if this is a "404 page not found" which means the server doesn't exist
      if ( $http_code === 404 && strpos( $body, '404 page not found' ) !== false ) {
        throw new Exception( 'Qdrant server not found. This often happens when a free Qdrant Cloud instance is deleted after being idle for too long. Please check your Qdrant dashboard and create a new cluster if needed.' );
      }

      if ( $http_code === 200 ) {
        // Collection exists
        $collection_exists = true;
        if ( isset( $data['result']['config']['params']['vectors']['size'] ) ) {
          $vector_size = $data['result']['config']['params']['vectors']['size'];
        }
        if ( isset( $data['result']['points_count'] ) ) {
          $points_count = $data['result']['points_count'];
        }
      }
      elseif ( $http_code === 404 ) {
        // Collection doesn't exist (but server exists) - this is OK, it will be created when needed
        $collection_exists = false;
      }
      else {
        // Other error
        $error_message = 'Unknown error';
        if ( isset( $data['status']['error'] ) ) {
          $error_message = $data['status']['error'];
        }
        elseif ( is_string( $body ) && !empty( $body ) ) {
          $error_message = $body;
        }

        throw new Exception( "Qdrant API error: {$error_message}" );
      }

      // Get expected dimensions
      $expected_dimension = null;
      if ( isset( $env['ai_embeddings_override'] ) && $env['ai_embeddings_override'] && !empty( $env['ai_embeddings_dimensions'] ) ) {
        $expected_dimension = $env['ai_embeddings_dimensions'];
      }
      else {
        $expected_dimension = get_option( 'mwai_ai_embeddings_default_dimensions', 1536 );
      }

      // Prepare response
      $result = [
        'success' => true,
        'server' => $server,
        'collection' => $collection,
        'collection_exists' => $collection_exists,
        'dimension' => $vector_size,
        'expected_dimension' => $expected_dimension,
        'dimension_match' => $vector_size ? (int) $vector_size === (int) $expected_dimension : null,
        'points_count' => $points_count
      ];

      if ( !$collection_exists ) {
        $result['message'] = "Collection '{$collection}' does not exist yet. It will be created automatically when you add your first vector.";
      }
      elseif ( $vector_size && $vector_size !== $expected_dimension ) {
        $result['warning'] = "Dimension mismatch: Collection has {$vector_size} dimensions but embedding model expects {$expected_dimension} dimensions.";
      }

      return new WP_REST_Response( $result, 200 );
    }
    catch ( Exception $e ) {
      return new WP_REST_Response( [
        'success' => false,
        'error' => $e->getMessage()
      ], 200 );
    }
  }

  public function rest_chroma_cloud_identity( $request ) {
    try {
      $params = $request->get_json_params();
      $env_id = isset( $params['env_id'] ) ? $params['env_id'] : null;
      $api_key = isset( $params['api_key'] ) ? $params['api_key'] : null;

      if ( !$api_key ) {
        throw new Exception( 'API key is required' );
      }

      // Call Chroma's auth identity endpoint
      // https://api.trychroma.com/docs/
      $url = 'https://api.trychroma.com/api/v2/auth/identity';

      $response = wp_remote_get( $url, [
        'headers' => [
          'X-Chroma-Token' => $api_key,
          'Content-Type' => 'application/json'
        ],
        'timeout' => 30
      ] );

      if ( is_wp_error( $response ) ) {
        throw new Exception( 'Failed to connect to Chroma Cloud: ' . $response->get_error_message() );
      }

      $body = wp_remote_retrieve_body( $response );
      $data = json_decode( $body, true );
      $http_code = wp_remote_retrieve_response_code( $response );

      if ( $http_code !== 200 ) {
        $error = 'Invalid API key or authentication failed';
        if ( isset( $data['detail'] ) ) {
          $error = $data['detail'];
        }
        elseif ( isset( $data['message'] ) ) {
          $error = $data['message'];
        }
        throw new Exception( $error );
      }

      // Extract identity information
      // Response format: {"user_id":"18332","tenant":"20e7f2a5-89d4-4332-9449-e1e0394291e6","databases":["nekod"]}
      $databases = $data['databases'] ?? [];
      $database = !empty( $databases ) ? $databases[0] : 'default_database';

      $result = [
        'success' => true,
        'tenant' => $data['tenant'] ?? '',
        'database' => $database,
        'user_id' => $data['user_id'] ?? '',
      ];

      return new WP_REST_Response( $result, 200 );
    }
    catch ( Exception $e ) {
      return new WP_REST_Response( [
        'success' => false,
        'error' => $e->getMessage()
      ], 200 );
    }
  }

  public function rest_create_openai_vector_store( $request ) {
    try {
      $params = $request->get_json_params();
      $openai_env_id = isset( $params['openai_env_id'] ) ? $params['openai_env_id'] : null;
      $name = isset( $params['name'] ) ? sanitize_text_field( $params['name'] ) : '';

      if ( !$openai_env_id ) {
        throw new Exception( 'OpenAI environment is required. Pick one above first.' );
      }

      $ai_env = $this->core->get_ai_env( $openai_env_id );
      if ( !$ai_env || empty( $ai_env['apikey'] ) ) {
        throw new Exception( 'The selected OpenAI environment has no API key. Add one in Settings → Environments for AI.' );
      }

      if ( empty( $name ) ) {
        $site_name = get_bloginfo( 'name' );
        $name = $site_name ? $site_name . ' - AI Engine' : 'AI Engine';
      }

      $response = wp_remote_post( 'https://api.openai.com/v1/vector_stores', [
        'headers' => [
          'Authorization' => 'Bearer ' . $ai_env['apikey'],
          'Content-Type' => 'application/json',
        ],
        'body' => wp_json_encode( [ 'name' => $name ] ),
        'timeout' => 30,
      ] );

      if ( is_wp_error( $response ) ) {
        throw new Exception( 'Failed to reach OpenAI: ' . $response->get_error_message() );
      }

      $body = wp_remote_retrieve_body( $response );
      $data = json_decode( $body, true );
      $http_code = wp_remote_retrieve_response_code( $response );

      if ( $http_code < 200 || $http_code >= 300 ) {
        $error = isset( $data['error']['message'] ) ? $data['error']['message'] : 'OpenAI returned HTTP ' . $http_code;
        throw new Exception( $error );
      }

      if ( empty( $data['id'] ) ) {
        throw new Exception( 'OpenAI did not return a vector store ID.' );
      }

      return new WP_REST_Response( [
        'success' => true,
        'store_id' => $data['id'],
        'name' => $data['name'] ?? $name,
      ], 200 );
    }
    catch ( Exception $e ) {
      return new WP_REST_Response( [
        'success' => false,
        'error' => $e->getMessage()
      ], 200 );
    }
  }

  #endregion
}
