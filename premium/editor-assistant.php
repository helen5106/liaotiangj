<?php

class MeowPro_MWAI_EditorAssistant extends Meow_MWAI_Modules_Editor_Assistant {
  public function __construct( $core ) {
    parent::__construct( $core );
    add_filter( 'mwai_functions_list', [ $this, 'functions_list' ], 10, 1 );
    add_filter( 'mwai_chatbot_query', [ $this, 'chatbot_query' ], 10, 2 );
  }

  public function rest_api_init() {
    parent::rest_api_init();
    register_rest_route( $this->namespace, '/editor/feedback', [
      'methods' => 'POST',
      'callback' => [ $this, 'rest_feedback' ],
      'permission_callback' => [ $this->core, 'check_rest_nonce' ],
    ] );
  }

  public function functions_list( $functions ) {
    $functions[] = new Meow_MWAI_Query_Function(
      'mwai_update_title',
      'Update the post title.',
      [
        new Meow_MWAI_Query_Parameter( 'title', 'The new title for the post.', 'string', true ),
      ],
      'editor-assistant',
      'mwai_update_title',
      'js'
    );
    $functions[] = new Meow_MWAI_Query_Function(
      'mwai_replace_block',
      'Replace the content of a specific block by its index. Use this to rewrite or modify existing paragraphs, headings, or other text blocks.',
      [
        new Meow_MWAI_Query_Parameter( 'blockIndex', 'The zero-based index of the block to replace.', 'integer', true ),
        new Meow_MWAI_Query_Parameter( 'newContent', 'The new HTML content for the block.', 'string', true ),
      ],
      'editor-assistant',
      'mwai_replace_block',
      'js'
    );
    $functions[] = new Meow_MWAI_Query_Function(
      'mwai_insert_block',
      'Insert a new block at a specific position in the post. The block type is auto-detected from the HTML (e.g. <h2> becomes a heading block, <ul> becomes a list block, plain text becomes a paragraph).',
      [
        new Meow_MWAI_Query_Parameter( 'position', 'Where to insert: "before" or "after" the reference block, or "start"/"end" of the post.', 'string', true ),
        new Meow_MWAI_Query_Parameter( 'referenceBlockIndex', 'The zero-based index of the reference block. Required when position is "before" or "after".', 'integer', false ),
        new Meow_MWAI_Query_Parameter( 'content', 'The HTML content for the new block. Use proper HTML tags: <h2> for headings, <ul>/<ol> for lists, <blockquote> for quotes, or plain text for paragraphs.', 'string', true ),
      ],
      'editor-assistant',
      'mwai_insert_block',
      'js'
    );
    $functions[] = new Meow_MWAI_Query_Function(
      'mwai_remove_block',
      'Remove a block from the post by its index.',
      [
        new Meow_MWAI_Query_Parameter( 'blockIndex', 'The zero-based index of the block to remove.', 'integer', true ),
      ],
      'editor-assistant',
      'mwai_remove_block',
      'js'
    );
    $functions[] = new Meow_MWAI_Query_Function(
      'mwai_move_block',
      'Move a block from one position to another without removing and re-inserting.',
      [
        new Meow_MWAI_Query_Parameter( 'fromIndex', 'Zero-based index of the block to move.', 'integer', true ),
        new Meow_MWAI_Query_Parameter( 'toIndex', 'Zero-based target position to move the block to.', 'integer', true ),
      ],
      'editor-assistant',
      'mwai_move_block',
      'js'
    );
    $functions[] = new Meow_MWAI_Query_Function(
      'mwai_convert_block',
      'Convert a block to a different type (e.g. paragraph to heading, heading to paragraph, paragraph to list/quote) or change attributes like heading level (h2 to h3).',
      [
        new Meow_MWAI_Query_Parameter( 'blockIndex', 'Zero-based index of the block to convert.', 'integer', true ),
        new Meow_MWAI_Query_Parameter( 'targetType', 'Target block type without "core/" prefix (e.g. "heading", "paragraph", "list", "quote", "preformatted").', 'string', true ),
        new Meow_MWAI_Query_Parameter( 'attributes', 'Optional JSON object of attributes to set on the converted block (e.g. {"level": 3} for heading level).', 'string', false ),
      ],
      'editor-assistant',
      'mwai_convert_block',
      'js'
    );
    $functions[] = new Meow_MWAI_Query_Function(
      'mwai_search_media',
      'Search the WordPress media library for images. Returns a list of matching images with their IDs, titles, and URLs. Use the returned ID with mwai_set_featured_image or mwai_insert_image_block.',
      [
        new Meow_MWAI_Query_Parameter( 'query', 'Search query to find images in the media library.', 'string', true ),
      ],
      'editor-assistant',
      'mwai_search_media',
      'js'
    );
    $functions[] = new Meow_MWAI_Query_Function(
      'mwai_set_featured_image',
      'Set or remove the featured image for the post. Use mwai_search_media first to find the media ID. Pass mediaId 0 to remove.',
      [
        new Meow_MWAI_Query_Parameter( 'mediaId', 'The WordPress media attachment ID, or 0 to remove.', 'integer', true ),
      ],
      'editor-assistant',
      'mwai_set_featured_image',
      'js'
    );
    $functions[] = new Meow_MWAI_Query_Function(
      'mwai_insert_image_block',
      'Insert an image block at a specific position. Use mwai_search_media first to find the media ID.',
      [
        new Meow_MWAI_Query_Parameter( 'mediaId', 'The WordPress media attachment ID.', 'integer', true ),
        new Meow_MWAI_Query_Parameter( 'position', 'Where to insert: "before" or "after" a reference block, or "start"/"end" of the post.', 'string', true ),
        new Meow_MWAI_Query_Parameter( 'referenceBlockIndex', 'Zero-based index of the reference block (required for "before"/"after").', 'integer', false ),
        new Meow_MWAI_Query_Parameter( 'alt', 'Alt text for the image.', 'string', false ),
        new Meow_MWAI_Query_Parameter( 'caption', 'Caption for the image.', 'string', false ),
      ],
      'editor-assistant',
      'mwai_insert_image_block',
      'js'
    );
    $functions[] = new Meow_MWAI_Query_Function(
      'mwai_update_excerpt',
      'Set or update the post excerpt.',
      [
        new Meow_MWAI_Query_Parameter( 'excerpt', 'The new excerpt text.', 'string', true ),
      ],
      'editor-assistant',
      'mwai_update_excerpt',
      'js'
    );
    $functions[] = new Meow_MWAI_Query_Function(
      'mwai_update_slug',
      'Set or update the post slug (URL permalink).',
      [
        new Meow_MWAI_Query_Parameter( 'slug', 'The new slug (e.g. "my-post-title").', 'string', true ),
      ],
      'editor-assistant',
      'mwai_update_slug',
      'js'
    );
    $functions[] = new Meow_MWAI_Query_Function(
      'mwai_update_status',
      'Change the post status (draft, publish, pending, or private).',
      [
        new Meow_MWAI_Query_Parameter( 'status', 'The new post status: "draft", "publish", "pending", or "private".', 'string', true ),
      ],
      'editor-assistant',
      'mwai_update_status',
      'js'
    );
    $functions[] = new Meow_MWAI_Query_Function(
      'mwai_set_categories',
      'Set the post categories by name. Replaces all current categories. Only existing categories can be used.',
      [
        new Meow_MWAI_Query_Parameter( 'categories', 'Comma-separated list of category names (e.g. "Technology, Science").', 'string', true ),
      ],
      'editor-assistant',
      'mwai_set_categories',
      'js'
    );
    $functions[] = new Meow_MWAI_Query_Function(
      'mwai_set_tags',
      'Set the post tags by name. Replaces all current tags. New tags are created automatically if they do not exist.',
      [
        new Meow_MWAI_Query_Parameter( 'tags', 'Comma-separated list of tag names (e.g. "AI, machine learning, WordPress").', 'string', true ),
      ],
      'editor-assistant',
      'mwai_set_tags',
      'js'
    );
    $functions[] = new Meow_MWAI_Query_Function(
      'mwai_search_posts',
      'Search through existing WordPress posts or pages by keyword. Returns a list with IDs, titles, dates, and excerpt snippets. Use this to find reference posts for style, structure, or content.',
      [
        new Meow_MWAI_Query_Parameter( 'query', 'Search keyword or phrase.', 'string', true ),
        new Meow_MWAI_Query_Parameter( 'postType', 'Post type to search: "post" (default) or "page".', 'string', false ),
      ],
      'editor-assistant',
      'mwai_search_posts',
      'js'
    );
    $functions[] = new Meow_MWAI_Query_Function(
      'mwai_get_post_content',
      'Get the full content of a specific post or page by its ID. Use mwai_search_posts first to find the post ID. Returns title, excerpt, and content as readable text.',
      [
        new Meow_MWAI_Query_Parameter( 'postId', 'The WordPress post ID.', 'integer', true ),
        new Meow_MWAI_Query_Parameter( 'postType', 'Post type: "post" (default) or "page".', 'string', false ),
      ],
      'editor-assistant',
      'mwai_get_post_content',
      'js'
    );
    return $functions;
  }

  public function chatbot_query( $query, $params ) {
    if ( !is_array( $params ) ) {
      return $query;
    }
    $scope = $params['scope'] ?? '';
    if ( $scope !== 'editor-assistant' ) {
      return $query;
    }
    $allFunctions = apply_filters( 'mwai_functions_list', [] );
    $addedCount = 0;
    foreach ( $allFunctions as $function ) {
      if ( $function->type === 'editor-assistant' ) {
        $query->add_function( $function );
        $addedCount++;
      }
    }
    Meow_MWAI_Logging::log( "Editor Assistant: Added {$addedCount} functions to query." );
    return $query;
  }

  protected function build_response( $reply ) {
    $actions = [];
    $feedbackId = null;
    if ( !empty( $reply->needClientActions ) ) {
      foreach ( $reply->needClientActions as $action ) {
        $actions[] = [
          'type' => 'function',
          'data' => [
            'name' => $action['function']->name,
            'args' => $action['arguments'],
            'toolId' => $action['toolId'] ?? null,
          ]
        ];
      }
      $feedbackId = wp_generate_uuid4();
      set_transient( "mwai_editor_feedback_{$feedbackId}", serialize( $reply ), 300 );
      Meow_MWAI_Logging::log( "Editor Assistant: Stored feedback session {$feedbackId} with " . count( $actions ) . ' action(s).' );
    }
    return [
      'success' => true,
      'reply' => $reply->result,
      'actions' => $actions,
      'feedbackId' => $feedbackId,
      'usage' => $reply->usage,
    ];
  }

  public function rest_feedback( $request ) {
    try {
      $params = $request->get_json_params();
      $feedbackId = $params['feedbackId'] ?? null;
      $results = $params['results'] ?? [];

      if ( !$feedbackId ) {
        return $this->create_response( [ 'success' => false, 'message' => 'Missing feedbackId.' ], 400 );
      }

      $serialized = get_transient( "mwai_editor_feedback_{$feedbackId}" );
      if ( !$serialized ) {
        return $this->create_response( [ 'success' => false, 'message' => 'Feedback session expired.' ], 410 );
      }
      delete_transient( "mwai_editor_feedback_{$feedbackId}" );

      $reply = unserialize( $serialized );
      $query = $reply->query;
      if ( !$reply || !$query ) {
        return $this->create_response( [ 'success' => false, 'message' => 'Invalid feedback session.' ], 400 );
      }

      $feedbackQuery = new Meow_MWAI_Query_Feedback( $reply, $query );

      $feedback_blocks = [];
      foreach ( $reply->needClientActions as $action ) {
        $rawMessageKey = md5( serialize( $action['rawMessage'] ) );
        if ( !isset( $feedback_blocks[$rawMessageKey] ) ) {
          $feedback_blocks[$rawMessageKey] = [
            'rawMessage' => $action['rawMessage'],
            'feedbacks' => [],
          ];
        }
        $resultValue = 'Executed successfully.';
        foreach ( $results as $r ) {
          if ( ( $r['toolId'] ?? '' ) === ( $action['toolId'] ?? '' ) ) {
            $resultValue = $r['result'] ?? 'Executed successfully.';
            break;
          }
        }
        $feedback_blocks[$rawMessageKey]['feedbacks'][] = [
          'request' => $action,
          'reply' => [ 'value' => $resultValue ],
        ];
      }

      $feedbackQuery->clear_feedback_blocks();
      foreach ( $feedback_blocks as $block ) {
        $feedbackQuery->add_feedback_block( $block );
      }

      Meow_MWAI_Logging::log( 'Editor Assistant: Processing feedback with ' . count( $feedback_blocks ) . ' block(s), ' . count( $results ) . ' result(s).' );

      $feedbackReply = $this->core->run_query( $feedbackQuery );

      return $this->create_response( $this->build_response( $feedbackReply ) );
    }
    catch ( Exception $e ) {
      Meow_MWAI_Logging::error( 'Editor Assistant Feedback: ' . $e->getMessage() );
      return $this->create_response( [
        'success' => false,
        'message' => apply_filters( 'mwai_ai_exception', $e->getMessage() ),
      ], 500 );
    }
  }
}
