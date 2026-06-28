<?php

class MeowPro_MWAI_MCP_Polylang {
  private $core = null;

  #region Initialize
  public function __construct( $core ) {
    $this->core = $core;
    add_action( 'rest_api_init', [ $this, 'rest_api_init' ] );
  }

  public function rest_api_init() {
    add_filter( 'mwai_mcp_tools', [ $this, 'register_rest_tools' ] );
    add_filter( 'mwai_mcp_callback', [ $this, 'handle_call' ], 10, 4 );
  }
  #endregion

  #region Helpers

  private function empty_schema(): array {
    return [ 'type' => 'object', 'properties' => (object) [] ];
  }

  private function get_polylang_languages(): array {
    if ( !function_exists( 'pll_languages_list' ) ) {
      return [];
    }
    $languages = [];
    $pll_languages = PLL()->model->get_languages_list();
    foreach ( $pll_languages as $lang ) {
      $languages[] = [
        'slug' => $lang->slug,
        'name' => $lang->name,
        'locale' => $lang->locale,
        'is_default' => $lang->is_default,
        'flag_url' => $lang->flag_url,
      ];
    }
    return $languages;
  }

  private function validate_language( string $lang ): bool {
    $languages = $this->get_polylang_languages();
    foreach ( $languages as $l ) {
      if ( $l['slug'] === $lang ) {
        return true;
      }
    }
    return false;
  }

  private function get_post_type_label( string $post_type ): string {
    $obj = get_post_type_object( $post_type );
    return $obj ? $obj->labels->singular_name : $post_type;
  }

  #endregion

  #region Tools
  private function tools(): array {
    return [

      /* ───────── Languages ───────── */
      'pll_get_languages' => [
        'name' => 'pll_get_languages',
        'description' => 'List all configured languages in Polylang (slug, name, locale, is_default, flag_url).',
        'inputSchema' => $this->empty_schema(),
        'accessLevel' => 'read',
      ],

      /* ───────── Post Language ───────── */
      'pll_get_post_language' => [
        'name' => 'pll_get_post_language',
        'description' => 'Get the language of a specific post.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'post_id' => [ 'type' => 'integer', 'description' => 'The post ID' ],
          ],
          'required' => [ 'post_id' ],
        ],
        'accessLevel' => 'read',
      ],

      'pll_set_post_language' => [
        'name' => 'pll_set_post_language',
        'description' => 'Set the language of a post.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'post_id' => [ 'type' => 'integer', 'description' => 'The post ID' ],
            'lang' => [ 'type' => 'string', 'description' => 'Language slug (e.g., "en", "fr", "ja")' ],
          ],
          'required' => [ 'post_id', 'lang' ],
        ],
        'accessLevel' => 'write',
      ],

      /* ───────── Post Translations ───────── */
      'pll_get_post_translations' => [
        'name' => 'pll_get_post_translations',
        'description' => 'Get all translations of a post. Returns an object mapping language slugs to post IDs.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'post_id' => [ 'type' => 'integer', 'description' => 'The post ID' ],
          ],
          'required' => [ 'post_id' ],
        ],
        'accessLevel' => 'read',
      ],

      'pll_link_translations' => [
        'name' => 'pll_link_translations',
        'description' => 'Link multiple posts as translations of each other. All posts must have a language set.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'translations' => [
              'type' => 'object',
              'description' => 'Object mapping language slugs to post IDs, e.g., {"en": 123, "fr": 456}',
              'additionalProperties' => [ 'type' => 'integer' ],
            ],
          ],
          'required' => [ 'translations' ],
        ],
        'accessLevel' => 'write',
      ],

      /* ───────── Term Translations ───────── */
      'pll_get_term_translations' => [
        'name' => 'pll_get_term_translations',
        'description' => 'Get all translations of a term. Returns an object mapping language slugs to term IDs.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'term_id' => [ 'type' => 'integer', 'description' => 'The term ID' ],
          ],
          'required' => [ 'term_id' ],
        ],
        'accessLevel' => 'read',
      ],

      'pll_translate_term' => [
        'name' => 'pll_translate_term',
        'description' => 'Get a specific translation of a term. Returns the term ID in the target language, or null if not available.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'term_id' => [ 'type' => 'integer', 'description' => 'The term ID' ],
            'lang' => [ 'type' => 'string', 'description' => 'Target language slug' ],
          ],
          'required' => [ 'term_id', 'lang' ],
        ],
        'accessLevel' => 'read',
      ],

      /* ───────── Query Posts by Language ───────── */
      'pll_get_posts' => [
        'name' => 'pll_get_posts',
        'description' => 'Get posts filtered by language. Wraps wp_get_posts with Polylang language support.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'lang' => [ 'type' => 'string', 'description' => 'Language slug (e.g., "en", "ja"). Use empty string "" to get posts in all languages (bypasses Polylang filter).' ],
            'post_type' => [ 'type' => 'string', 'description' => 'Post type (default: post)' ],
            'post_status' => [ 'type' => 'string', 'description' => 'Post status (default: publish)' ],
            'limit' => [ 'type' => 'integer', 'description' => 'Maximum number of results (default: 10)' ],
            'offset' => [ 'type' => 'integer', 'description' => 'Number of posts to skip (default: 0)' ],
            'orderby' => [ 'type' => 'string', 'description' => 'Order by field (default: date)' ],
            'order' => [ 'type' => 'string', 'description' => 'Order direction: ASC or DESC (default: DESC)' ],
          ],
          'required' => [ 'lang' ],
        ],
        'accessLevel' => 'read',
      ],

      /* ───────── Missing Translations ───────── */
      'pll_get_posts_missing_translation' => [
        'name' => 'pll_get_posts_missing_translation',
        'description' => 'Find posts that are missing a translation in a specific language.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'source_lang' => [ 'type' => 'string', 'description' => 'Source language slug to search in' ],
            'target_lang' => [ 'type' => 'string', 'description' => 'Target language slug that is missing' ],
            'post_type' => [ 'type' => 'string', 'description' => 'Post type to filter (default: post)' ],
            'limit' => [ 'type' => 'integer', 'description' => 'Maximum number of results (default: 20)' ],
          ],
          'required' => [ 'source_lang', 'target_lang' ],
        ],
        'accessLevel' => 'read',
      ],

      /* ───────── Create Translation ───────── */
      'pll_create_translation' => [
        'name' => 'pll_create_translation',
        'description' => 'Create a new post as a translation of an existing post. Creates the post, sets its language, and links it to the source. Can optionally translate content, categories, and tags.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'source_post_id' => [ 'type' => 'integer', 'description' => 'The source post ID to translate from' ],
            'target_lang' => [ 'type' => 'string', 'description' => 'Target language slug for the new translation' ],
            'title' => [ 'type' => 'string', 'description' => 'Translated title' ],
            'content' => [ 'type' => 'string', 'description' => 'Translated content (optional, copies from source if not provided)' ],
            'excerpt' => [ 'type' => 'string', 'description' => 'Translated excerpt (optional)' ],
            'status' => [ 'type' => 'string', 'description' => 'Post status: draft, publish, pending, private, future (default: draft)' ],
            'post_date' => [ 'type' => 'string', 'description' => 'Post date in ISO 8601 or MySQL format (required for future status, e.g., "2025-12-31 10:00:00")' ],
            'translate_terms' => [ 'type' => 'boolean', 'description' => 'Automatically link translated categories/tags if they exist (default: true)' ],
            'copy_featured_image' => [ 'type' => 'boolean', 'description' => 'Copy the featured image to the new translation (default: true)' ],
          ],
          'required' => [ 'source_post_id', 'target_lang', 'title' ],
        ],
        'accessLevel' => 'write',
      ],

      /* ───────── Translation Status ───────── */
      'pll_translation_status' => [
        'name' => 'pll_translation_status',
        'description' => 'Get an overview of translation coverage. Shows how many posts exist in each language and the percentage translated.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'post_type' => [ 'type' => 'string', 'description' => 'Post type to analyze (default: post)' ],
          ],
        ],
        'accessLevel' => 'read',
      ],
    ];
  }
  #endregion

  #region Register
  public function register_rest_tools( array $prev ): array {
    $tools = $this->tools();

    // Add category and annotations to each tool
    foreach ( $tools as &$tool ) {
      if ( !isset( $tool['category'] ) ) {
        $tool['category'] = 'AI Engine (Polylang)';
      }

      // Add MCP tool annotations
      if ( !isset( $tool['annotations'] ) ) {
        $name = $tool['name'];

        // Read-only tools
        $is_readonly = (
          $name === 'pll_get_languages' ||
          $name === 'pll_get_posts' ||
          $name === 'pll_translation_status' ||
          strpos( $name, '_get_' ) !== false
        );

        // Destructive tools (none in Polylang integration)
        $is_destructive = false;

        $tool['annotations'] = [
          'readOnlyHint' => $is_readonly,
          'destructiveHint' => $is_destructive,
          'openWorldHint' => false,
        ];
      }
    }

    $merged = array_merge( $prev, array_values( $tools ) );
    return $merged;
  }
  #endregion

  #region Callback
  public function handle_call( $prev, string $tool, array $args, ?int $id ) {
    if ( !empty( $prev ) || !isset( $this->tools()[ $tool ] ) ) {
      return $prev; // handled elsewhere or unknown
    }
    if ( !current_user_can( 'administrator' ) ) {
      wp_set_current_user( 1 );
    }
    return $this->dispatch( $tool, $args, $id );
  }
  #endregion

  #region Dispatcher
  private function dispatch( string $tool, array $a, ?int $id ) {

    // Check if Polylang functions are available
    if ( !function_exists( 'pll_get_post_language' ) ) {
      throw new Exception( 'Polylang is not active' );
    }

    switch ( $tool ) {

      /* ───────── Languages ───────── */
      case 'pll_get_languages':
        return $this->get_polylang_languages();

        /* ───────── Post Language ───────── */
      case 'pll_get_post_language':
        $post_id = (int) ( $a['post_id'] ?? 0 );
        if ( !$post_id || !get_post( $post_id ) ) {
          throw new Exception( 'Invalid post ID' );
        }
        $lang = pll_get_post_language( $post_id );
        if ( !$lang ) {
          return [ 'post_id' => $post_id, 'language' => null, 'message' => 'No language set' ];
        }
        return [ 'post_id' => $post_id, 'language' => $lang ];

      case 'pll_set_post_language':
        $post_id = (int) ( $a['post_id'] ?? 0 );
        $lang = sanitize_text_field( $a['lang'] ?? '' );
        if ( !$post_id || !get_post( $post_id ) ) {
          throw new Exception( 'Invalid post ID' );
        }
        if ( !$lang || !$this->validate_language( $lang ) ) {
          throw new Exception( 'Invalid language slug' );
        }
        pll_set_post_language( $post_id, $lang );
        return [ 'post_id' => $post_id, 'language' => $lang, 'message' => 'Language set successfully' ];

        /* ───────── Post Translations ───────── */
      case 'pll_get_post_translations':
        $post_id = (int) ( $a['post_id'] ?? 0 );
        if ( !$post_id || !get_post( $post_id ) ) {
          throw new Exception( 'Invalid post ID' );
        }
        $translations = pll_get_post_translations( $post_id );
        $result = [];
        foreach ( $translations as $lang => $translated_post_id ) {
          $post = get_post( $translated_post_id );
          $result[ $lang ] = [
            'post_id' => $translated_post_id,
            'title' => $post ? $post->post_title : null,
            'status' => $post ? $post->post_status : null,
          ];
        }
        return [ 'source_post_id' => $post_id, 'translations' => $result ];

      case 'pll_link_translations':
        $translations = $a['translations'] ?? [];
        // Handle JSON strings (some MCP clients send objects as JSON strings)
        if ( is_string( $translations ) ) {
          $translations = json_decode( $translations, true ) ?? [];
        }
        if ( empty( $translations ) || !is_array( $translations ) ) {
          throw new Exception( 'translations must be an object mapping language slugs to post IDs' );
        }
        // Validate all posts and languages
        foreach ( $translations as $lang => $post_id ) {
          if ( !$this->validate_language( $lang ) ) {
            throw new Exception( "Invalid language: $lang" );
          }
          if ( !get_post( $post_id ) ) {
            throw new Exception( "Invalid post ID: $post_id" );
          }
          $post_lang = pll_get_post_language( $post_id );
          if ( $post_lang !== $lang ) {
            throw new Exception( "Post $post_id has language '$post_lang', expected '$lang'" );
          }
        }
        pll_save_post_translations( $translations );
        return [ 'linked' => $translations, 'message' => 'Posts linked as translations' ];

        /* ───────── Term Translations ───────── */
      case 'pll_get_term_translations':
        $term_id = (int) ( $a['term_id'] ?? 0 );
        if ( !$term_id || !get_term( $term_id ) ) {
          throw new Exception( 'Invalid term ID' );
        }
        $translations = pll_get_term_translations( $term_id );
        $result = [];
        foreach ( $translations as $lang => $translated_term_id ) {
          $term = get_term( $translated_term_id );
          $result[ $lang ] = [
            'term_id' => $translated_term_id,
            'name' => $term ? $term->name : null,
            'taxonomy' => $term ? $term->taxonomy : null,
          ];
        }
        return [ 'source_term_id' => $term_id, 'translations' => $result ];

      case 'pll_translate_term':
        $term_id = (int) ( $a['term_id'] ?? 0 );
        $lang = sanitize_text_field( $a['lang'] ?? '' );
        if ( !$term_id || !get_term( $term_id ) ) {
          throw new Exception( 'Invalid term ID' );
        }
        if ( !$lang || !$this->validate_language( $lang ) ) {
          throw new Exception( 'Invalid language slug' );
        }
        $translated_id = pll_get_term( $term_id, $lang );
        if ( !$translated_id ) {
          return [ 'term_id' => $term_id, 'target_lang' => $lang, 'translated_term_id' => null, 'message' => 'No translation found' ];
        }
        $term = get_term( $translated_id );
        return [
          'term_id' => $term_id,
          'target_lang' => $lang,
          'translated_term_id' => $translated_id,
          'name' => $term ? $term->name : null,
        ];

        /* ───────── Query Posts by Language ───────── */
      case 'pll_get_posts':
        $lang = $a['lang'] ?? null;
        $post_type = sanitize_text_field( $a['post_type'] ?? 'post' );
        $post_status = sanitize_text_field( $a['post_status'] ?? 'publish' );
        $limit = (int) ( $a['limit'] ?? 10 );
        $limit = max( 1, min( 100, $limit ) );
        $offset = (int) ( $a['offset'] ?? 0 );
        $orderby = sanitize_text_field( $a['orderby'] ?? 'date' );
        $order = strtoupper( sanitize_text_field( $a['order'] ?? 'DESC' ) );
        $order = in_array( $order, [ 'ASC', 'DESC' ], true ) ? $order : 'DESC';

        // Validate language if provided (empty string means all languages)
        if ( $lang !== '' && $lang !== null && !$this->validate_language( $lang ) ) {
          throw new Exception( 'Invalid language slug' );
        }

        $query_args = [
          'post_type' => $post_type,
          'post_status' => $post_status,
          'posts_per_page' => $limit,
          'offset' => $offset,
          'orderby' => $orderby,
          'order' => $order,
          'suppress_filters' => false, // Required for Polylang's lang filter to work
        ];

        // Handle language filter
        if ( $lang === '' ) {
          // Empty string: bypass Polylang filter to get all languages
          $query_args['lang'] = '';
        }
        elseif ( $lang !== null ) {
          // Specific language
          $query_args['lang'] = $lang;
        }
        // If lang is null, let Polylang use its default behavior

        $posts = get_posts( $query_args );
        $result = [];
        foreach ( $posts as $post ) {
          $post_lang = pll_get_post_language( $post->ID );
          $result[] = [
            'ID' => $post->ID,
            'post_title' => $post->post_title,
            'post_type' => $post->post_type,
            'post_status' => $post->post_status,
            'post_date' => $post->post_date,
            'language' => $post_lang,
            'permalink' => get_permalink( $post->ID ),
          ];
        }

        return [
          'lang' => $lang,
          'post_type' => $post_type,
          'count' => count( $result ),
          'posts' => $result,
        ];

        /* ───────── Missing Translations ───────── */
      case 'pll_get_posts_missing_translation':
        $source_lang = sanitize_text_field( $a['source_lang'] ?? '' );
        $target_lang = sanitize_text_field( $a['target_lang'] ?? '' );
        $post_type = sanitize_text_field( $a['post_type'] ?? 'post' );
        $limit = (int) ( $a['limit'] ?? 20 );
        $limit = max( 1, min( 100, $limit ) );

        if ( !$source_lang || !$this->validate_language( $source_lang ) ) {
          throw new Exception( 'Invalid source language' );
        }
        if ( !$target_lang || !$this->validate_language( $target_lang ) ) {
          throw new Exception( 'Invalid target language' );
        }
        if ( $source_lang === $target_lang ) {
          throw new Exception( 'Source and target languages must be different' );
        }

        // Get posts in source language
        $posts = get_posts( [
          'post_type' => $post_type,
          'post_status' => 'any',
          'lang' => $source_lang,
          'posts_per_page' => -1,
          'fields' => 'ids',
        ] );

        $missing = [];
        foreach ( $posts as $post_id ) {
          $translation = pll_get_post( $post_id, $target_lang );
          if ( !$translation ) {
            $post = get_post( $post_id );
            $missing[] = [
              'post_id' => $post_id,
              'title' => $post->post_title,
              'status' => $post->post_status,
              'post_type' => $post->post_type,
            ];
            if ( count( $missing ) >= $limit ) {
              break;
            }
          }
        }

        return [
          'source_lang' => $source_lang,
          'target_lang' => $target_lang,
          'post_type' => $post_type,
          'total_in_source' => count( $posts ),
          'missing_count' => count( $missing ),
          'posts' => $missing,
        ];

        /* ───────── Create Translation ───────── */
      case 'pll_create_translation':
        $source_post_id = (int) ( $a['source_post_id'] ?? 0 );
        $target_lang = sanitize_text_field( $a['target_lang'] ?? '' );
        $title = sanitize_text_field( $a['title'] ?? '' );
        $content = $a['content'] ?? null;
        $excerpt = $a['excerpt'] ?? null;
        $status = sanitize_text_field( $a['status'] ?? 'draft' );
        $post_date = isset( $a['post_date'] ) ? sanitize_text_field( $a['post_date'] ) : null;
        $translate_terms = $a['translate_terms'] ?? true;
        $copy_featured_image = $a['copy_featured_image'] ?? true;

        // Validate source post
        $source_post = get_post( $source_post_id );
        if ( !$source_post ) {
          throw new Exception( 'Invalid source post ID' );
        }

        // Validate target language
        if ( !$target_lang || !$this->validate_language( $target_lang ) ) {
          throw new Exception( 'Invalid target language' );
        }

        // Check if translation already exists
        $existing = pll_get_post( $source_post_id, $target_lang );
        if ( $existing ) {
          throw new Exception( "Translation already exists in $target_lang (post ID: $existing)" );
        }

        // Get source language
        $source_lang = pll_get_post_language( $source_post_id );
        if ( !$source_lang ) {
          throw new Exception( 'Source post has no language set' );
        }
        if ( $source_lang === $target_lang ) {
          throw new Exception( 'Source and target languages must be different' );
        }

        // Validate title
        if ( empty( $title ) ) {
          throw new Exception( 'Title is required' );
        }

        // Validate status
        $allowed_statuses = [ 'draft', 'publish', 'pending', 'private', 'future' ];
        if ( !in_array( $status, $allowed_statuses, true ) ) {
          $status = 'draft';
        }

        // Validate future status requires post_date
        if ( $status === 'future' && empty( $post_date ) ) {
          throw new Exception( 'post_date is required when status is "future"' );
        }

        // Prepare post data
        $post_data = [
          'post_type' => $source_post->post_type,
          'post_title' => $title,
          'post_content' => $content !== null ? $content : $source_post->post_content,
          'post_excerpt' => $excerpt !== null ? $excerpt : $source_post->post_excerpt,
          'post_status' => $status,
          'post_author' => $source_post->post_author,
        ];

        // Handle scheduled posts
        if ( $post_date ) {
          $post_data['post_date'] = $post_date;
          $post_data['post_date_gmt'] = get_gmt_from_date( $post_date );
        }

        // Create the new post
        $new_post_id = wp_insert_post( $post_data, true );
        if ( is_wp_error( $new_post_id ) ) {
          throw new Exception( 'Failed to create post: ' . $new_post_id->get_error_message() );
        }

        // Set language
        pll_set_post_language( $new_post_id, $target_lang );

        // Link translations
        $translations = pll_get_post_translations( $source_post_id );
        $translations[ $target_lang ] = $new_post_id;
        pll_save_post_translations( $translations );

        // Translate terms if requested
        $terms_linked = [];
        if ( $translate_terms ) {
          $taxonomies = get_object_taxonomies( $source_post->post_type );
          foreach ( $taxonomies as $taxonomy ) {
            if ( !pll_is_translated_taxonomy( $taxonomy ) ) {
              continue;
            }
            $terms = wp_get_object_terms( $source_post_id, $taxonomy, [ 'fields' => 'ids' ] );
            $translated_terms = [];
            foreach ( $terms as $term_id ) {
              $translated_term = pll_get_term( $term_id, $target_lang );
              if ( $translated_term ) {
                $translated_terms[] = $translated_term;
              }
            }
            if ( !empty( $translated_terms ) ) {
              wp_set_object_terms( $new_post_id, $translated_terms, $taxonomy );
              $terms_linked[ $taxonomy ] = count( $translated_terms );
            }
          }
        }

        // Copy featured image if requested
        $featured_image_copied = false;
        if ( $copy_featured_image ) {
          $thumbnail_id = get_post_thumbnail_id( $source_post_id );
          if ( $thumbnail_id ) {
            set_post_thumbnail( $new_post_id, $thumbnail_id );
            $featured_image_copied = true;
          }
        }

        $result = [
          'new_post_id' => $new_post_id,
          'source_post_id' => $source_post_id,
          'source_lang' => $source_lang,
          'target_lang' => $target_lang,
          'title' => $title,
          'status' => $status,
          'terms_linked' => $terms_linked,
          'featured_image_copied' => $featured_image_copied,
          'message' => 'Translation created successfully',
        ];
        if ( $post_date ) {
          $result['post_date'] = $post_date;
        }
        return $result;

        /* ───────── Translation Status ───────── */
      case 'pll_translation_status':
        $post_type = sanitize_text_field( $a['post_type'] ?? 'post' );
        $languages = $this->get_polylang_languages();
        $post_type_label = $this->get_post_type_label( $post_type );

        $stats = [];
        $total_posts = 0;
        $default_lang = null;

        // Count posts per language
        foreach ( $languages as $lang ) {
          $count = count( get_posts( [
            'post_type' => $post_type,
            'post_status' => 'any',
            'lang' => $lang['slug'],
            'posts_per_page' => -1,
            'fields' => 'ids',
          ] ) );
          $stats[ $lang['slug'] ] = [
            'name' => $lang['name'],
            'count' => $count,
          ];
          $total_posts += $count;
          if ( $lang['is_default'] ) {
            $default_lang = $lang['slug'];
          }
        }

        // Calculate translation coverage
        $default_count = $stats[ $default_lang ]['count'] ?? 0;
        foreach ( $stats as $lang => &$data ) {
          if ( $lang === $default_lang ) {
            $data['coverage'] = '100%';
          }
          elseif ( $default_count > 0 ) {
            $percentage = round( ( $data['count'] / $default_count ) * 100, 1 );
            $data['coverage'] = $percentage . '%';
          }
          else {
            $data['coverage'] = 'N/A';
          }
        }

        return [
          'post_type' => $post_type,
          'post_type_label' => $post_type_label,
          'default_language' => $default_lang,
          'total_posts' => $total_posts,
          'languages' => $stats,
        ];

      default:
        throw new Exception( 'Unknown tool' );
    }
  }
  #endregion
}
