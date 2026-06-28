<?php

class MeowPro_MWAI_CrossSite {
  private $core = null;
  private $namespace = 'mwai-ui/v1';

  public function __construct( $core ) {
    $this->core = $core;

    // Only initialize if the module is enabled
    if ( $this->core->get_option( 'module_cross_site', false ) ) {
      add_action( 'rest_api_init', [ $this, 'rest_api_init' ] );
      add_action( 'init', [ $this, 'register_embed_script' ] );
      add_filter( 'mwai_chatbot_params', [ $this, 'add_cross_site_params' ], 10, 1 );
      add_filter( 'mwai_chatbot_server_params', [ $this, 'add_server_params' ], 10, 1 );

      // Set up the REST filter immediately, not in an action hook
      $this->setup_rest_filter();

      // Handle CORS early in the request
      add_action( 'init', [ $this, 'handle_cors' ], 1 );
    }
  }

  private function setup_rest_filter() {
    // Set up REST filter early to handle cross-site requests
    add_filter( 'rest_pre_dispatch', [ $this, 'handle_rest_request' ], 10, 3 );

    // Disable cookie authentication for cross-site endpoints
    add_filter( 'rest_authentication_errors', [ $this, 'bypass_cookie_auth' ], 5 );
  }

  public function bypass_cookie_auth( $result ) {
    // Check if this is a cross-site endpoint
    $request_uri = $_SERVER['REQUEST_URI'] ?? '';

    if ( strpos( $request_uri, '/wp-json/mwai-ui/v1/cross-site/' ) !== false ) {
      // Don't require authentication for cross-site endpoints
      // We'll handle validation in the endpoint itself
      return true;
    }

    return $result;
  }

  public function handle_rest_request( $result, $server, $request ) {
    $route = $request->get_route();

    // Only handle mwai-ui endpoints
    if ( strpos( $route, '/mwai-ui/v1/' ) === false ) {
      return $result;
    }

    // Get origin
    $origin = $this->get_request_origin();

    // Check if it's same-origin
    $site_url = get_site_url();
    $parsed_site = parse_url( $site_url );
    $parsed_origin = parse_url( $origin );

    $is_same_origin = false;
    if ( isset( $parsed_site['host'] ) && isset( $parsed_origin['host'] ) ) {
      if ( $parsed_site['host'] === $parsed_origin['host'] ) {
        $is_same_origin = true;
      }
    }

    // For same-origin requests, always allow
    if ( $is_same_origin ) {
      // Add CORS headers even for same-origin (helps with some browser configs)
      header( 'Access-Control-Allow-Origin: ' . $origin );
      header( 'Access-Control-Allow-Credentials: true' );
      return $result;
    }

    // For cross-origin requests, check if the domain is allowed for any chatbot
    // Get botId from request
    $params = $request->get_json_params() ?: [];
    $bot_id = $params['botId'] ?? $request->get_param( 'botId' );

    if ( $bot_id ) {
      // Get chatbot configuration
      $chatbot = $this->core->get_chatbot( $bot_id );
      if ( $chatbot ) {
        $cross_site = $chatbot['crossSite'] ?? [];
        if ( !empty( $cross_site['enabled'] ) ) {
          $allowed_domains = $cross_site['allowedDomains'] ?? [];
          if ( $this->validate_domain( $origin, $allowed_domains ) ) {
            // Add CORS headers for valid cross-origin requests
            header( 'Access-Control-Allow-Origin: ' . $origin );
            header( 'Access-Control-Allow-Credentials: true' );
            header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
            header( 'Access-Control-Allow-Headers: Content-Type, X-WP-Nonce' );
          }
        }
      }
    }

    return $result;
  }

  public function register_embed_script() {
    // Register the embed script for external use
    $physical_file = trailingslashit( MWAI_PATH ) . 'app/embed.js';
    $cache_buster = file_exists( $physical_file ) ? filemtime( $physical_file ) : MWAI_VERSION;
    wp_register_script( 'mwai_embed', trailingslashit( MWAI_URL ) . 'app/embed.js', [], $cache_buster, false );
  }

  public function rest_api_init() {
    // Endpoint to serve the embed configuration
    register_rest_route( $this->namespace, '/cross-site/config', [
      'methods' => 'GET',
      'callback' => [ $this, 'get_embed_config' ],
      'permission_callback' => '__return_true', // Public endpoint, domain validation in callback
      'args' => [
        'botId' => [
          'required' => true,
          'type' => 'string',
          'sanitize_callback' => 'sanitize_text_field',
        ]
      ]
    ] );

    // Endpoint for Cross-Site authentication
    register_rest_route( $this->namespace, '/cross-site/auth', [
      'methods' => 'POST',
      'callback' => [ $this, 'authenticate_cross_site' ],
      'permission_callback' => '__return_true',
      'args' => [
        'botId' => [
          'required' => true,
          'type' => 'string',
          'sanitize_callback' => 'sanitize_text_field',
        ],
        'origin' => [
          'required' => true,
          'type' => 'string',
          'sanitize_callback' => 'esc_url_raw',
        ]
      ]
    ] );

    // Endpoint to serve theme CSS (both internal and custom themes)
    register_rest_route( $this->namespace, '/cross-site/theme-css', [
      'methods' => 'GET',
      'callback' => [ $this, 'serve_theme_css' ],
      'permission_callback' => '__return_true',
      'args' => [
        'themeId' => [
          'required' => true,
          'type' => 'string',
          'sanitize_callback' => 'sanitize_key',
        ]
      ]
    ] );
  }

  public function add_cross_site_params( $params ) {
    // Add Cross-Site parameters to chatbot params
    if ( !isset( $params['crossSite'] ) ) {
      $params['crossSite'] = [
        'enabled' => false,
        'allowedDomains' => []
      ];
    }
    return $params;
  }

  public function add_server_params( $params ) {
    // Add Cross-Site to server params list
    if ( !in_array( 'crossSite', $params ) ) {
      $params[] = 'crossSite';
    }
    return $params;
  }

  private function validate_domain( $origin, $allowed_domains ) {
    // Always allow same-origin requests
    $site_url = get_site_url();
    $parsed_site = parse_url( $site_url );
    $parsed_origin = parse_url( $origin );

    if ( isset( $parsed_site['host'] ) && isset( $parsed_origin['host'] ) ) {
      if ( $parsed_site['host'] === $parsed_origin['host'] ) {
        return true;
      }
    }

    // If no allowed domains configured, reject (except for same origin above)
    if ( empty( $allowed_domains ) ) {
      return false;
    }

    $origin_host = isset( $parsed_origin['host'] ) ? $parsed_origin['host'] : '';

    foreach ( $allowed_domains as $allowed_domain ) {
      // Remove protocol if present
      $allowed_domain = preg_replace( '#^https?://#', '', $allowed_domain );
      // Remove trailing slash
      $allowed_domain = rtrim( $allowed_domain, '/' );

      // Check for exact match or wildcard subdomain match
      if ( $origin_host === $allowed_domain ) {
        return true;
      }

      // Check for wildcard subdomain (*.example.com)
      if ( strpos( $allowed_domain, '*.' ) === 0 ) {
        $base_domain = substr( $allowed_domain, 2 );
        if ( preg_match( '/\.' . preg_quote( $base_domain, '/' ) . '$/', $origin_host ) || $origin_host === $base_domain ) {
          return true;
        }
      }
    }

    return false;
  }

  private function get_request_origin() {
    $origin = '';

    if ( isset( $_SERVER['HTTP_ORIGIN'] ) ) {
      $origin = $_SERVER['HTTP_ORIGIN'];
    }
    elseif ( isset( $_SERVER['HTTP_REFERER'] ) ) {
      $parsed = parse_url( $_SERVER['HTTP_REFERER'] );
      $origin = $parsed['scheme'] . '://' . $parsed['host'];
      if ( isset( $parsed['port'] ) && $parsed['port'] ) {
        $origin .= ':' . $parsed['port'];
      }
    }

    // If no origin header is present, assume it's a same-origin request
    // This happens when the request is made from the same domain
    if ( empty( $origin ) ) {
      $origin = get_site_url();
    }

    return $origin;
  }

  public function get_embed_config( $request ) {
    $bot_id = $request['botId'];
    $origin = $this->get_request_origin();

    // Get chatbot configuration
    $chatbot = $this->core->get_chatbot( $bot_id );
    if ( !$chatbot ) {
      return new WP_Error( 'invalid_bot', 'Chatbot not found', [ 'status' => 404 ] );
    }

    // Check if Cross-Site is enabled for this chatbot
    $cross_site = $chatbot['crossSite'] ?? [];

    // Always check if it's a same-origin request first
    $site_url = get_site_url();
    $parsed_site = parse_url( $site_url );
    $parsed_origin = parse_url( $origin );

    $is_same_origin = false;
    if ( isset( $parsed_site['host'] ) && isset( $parsed_origin['host'] ) ) {
      if ( $parsed_site['host'] === $parsed_origin['host'] ) {
        $is_same_origin = true;
      }
    }

    // If it's a same-origin request, always allow it regardless of Cross-Site settings
    if ( $is_same_origin ) {
      // Continue to serve the config
    }
    // If it's not same-origin, check Cross-Site settings
    else if ( empty( $cross_site['enabled'] ) ) {
      // Cross-Site is not enabled and it's not same-origin
      return new WP_Error( 'cross_site_disabled', 'Cross-Site is not enabled for this chatbot', [ 'status' => 403 ] );
    }
    else {
      // Cross-Site is enabled, validate against allowed domains
      $allowed_domains = $cross_site['allowedDomains'] ?? [];
      if ( !$this->validate_domain( $origin, $allowed_domains ) ) {
        return new WP_Error( 'domain_not_allowed', 'Domain not allowed', [ 'status' => 403 ] );
      }
    }

    // Set CORS headers
    header( 'Access-Control-Allow-Origin: ' . $origin );
    header( 'Access-Control-Allow-Credentials: true' );
    header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
    header( 'Access-Control-Allow-Headers: Content-Type, X-WP-Nonce' );

    // Prepare full chatbot parameters
    $frontParams = [];
    foreach ( MWAI_CHATBOT_FRONT_PARAMS as $param ) {
      if ( isset( $chatbot[$param] ) ) {
        $frontParams[$param] = $chatbot[$param];
      }
    }

    // Get theme data (includes cssUrl automatically)
    $theme_id = $chatbot['themeId'] ?? 'chatgpt';
    $theme_data = $this->core->get_theme( $theme_id );

    // Run init filters for shortcuts, blocks, and actions (Quick Actions, GDPR, etc.)
    $filterParams = [
      'step' => 'init',
      'botId' => $bot_id,
      'params' => $frontParams
    ];
    $actions = apply_filters( 'mwai_chatbot_actions', [], $filterParams );
    $blocks = apply_filters( 'mwai_chatbot_blocks', [], $filterParams );
    $shortcuts = apply_filters( 'mwai_chatbot_shortcuts', [], $filterParams );
    if ( $this->core->chatbot ) {
      $actions = $this->core->chatbot->sanitize_actions( $actions );
      $blocks = $this->core->chatbot->sanitize_blocks( $blocks );
      $shortcuts = $this->core->chatbot->sanitize_shortcuts( $shortcuts );
      $shortcuts = $this->core->chatbot->prepare_shortcuts_for_client( $shortcuts, $bot_id );
    }

    // Return configuration
    return [
      'success' => true,
      'botId' => $bot_id,
      'restUrl' => untrailingslashit( get_rest_url() ),
      'pluginUrl' => untrailingslashit( MWAI_URL ),
      'theme' => $theme_id,
      'themeData' => $theme_data,
      'themeCssUrl' => $theme_data['cssUrl'] ?? null,
      'speech_recognition' => $this->core->get_option( 'speech_recognition' ),
      'params' => $frontParams,
      'stream' => $this->core->get_option( 'ai_streaming' ),
      'typewriter' => $this->core->get_option( 'chatbot_typewriter' ),
      'actions' => $actions,
      'blocks' => $blocks,
      'shortcuts' => $shortcuts
    ];
  }

  public function authenticate_cross_site( $request ) {
    $bot_id = $request['botId'];
    $origin = $request['origin'];

    // Get chatbot configuration
    $chatbot = $this->core->get_chatbot( $bot_id );
    if ( !$chatbot ) {
      return new WP_Error( 'invalid_bot', 'Chatbot not found', [ 'status' => 404 ] );
    }

    // Check if Cross-Site is enabled
    $cross_site = $chatbot['crossSite'] ?? [];
    if ( empty( $cross_site['enabled'] ) ) {
      return new WP_Error( 'cross_site_disabled', 'Cross-Site is not enabled', [ 'status' => 403 ] );
    }

    // Validate domain
    $allowed_domains = $cross_site['allowedDomains'] ?? [];
    if ( !$this->validate_domain( $origin, $allowed_domains ) ) {
      return new WP_Error( 'domain_not_allowed', 'Domain not allowed', [ 'status' => 403 ] );
    }

    // Set CORS headers
    header( 'Access-Control-Allow-Origin: ' . $origin );
    header( 'Access-Control-Allow-Credentials: true' );

    // Generate a session-specific nonce for Cross-Site usage
    $session_id = $this->core->get_session_id();
    $nonce = wp_create_nonce( 'wp_rest' );

    // Get user data (for guest users)
    $user_data = $this->core->get_user_data();

    return [
      'success' => true,
      'nonce' => $nonce,
      'sessionId' => $session_id,
      'userData' => $user_data,
      'restUrl' => untrailingslashit( get_rest_url() )
    ];
  }

  /**
   * Serve theme CSS for both internal and custom themes
   * This allows custom themes to work with Cross-Site since they don't have physical files
   */
  public function serve_theme_css( $request ) {
    $theme_id = $request['themeId'];

    // Set CORS headers to allow cross-site access
    header( 'Access-Control-Allow-Origin: *' );
    header( 'Content-Type: text/css; charset=UTF-8' );
    header( 'Cache-Control: public, max-age=3600' );

    // Check if it's an internal theme with a physical file
    $internal_themes = [ 'chatgpt', 'messages', 'timeless' ];
    if ( in_array( $theme_id, $internal_themes, true ) ) {
      $file_path = MWAI_PATH . 'themes/' . $theme_id . '.css';
      if ( file_exists( $file_path ) ) {
        readfile( $file_path );
        exit;
      }
    }

    // Custom theme - get from database
    $theme = $this->core->get_theme( $theme_id );
    if ( !$theme ) {
      status_header( 404 );
      echo '/* Theme not found */';
      exit;
    }

    // Return the processed CSS (get_theme already adds theme class prefixes)
    $css = $theme['style'] ?? '';
    echo $css;
    exit;
  }

  /**
   * Handle CORS for Cross-Site requests (mainly for OPTIONS preflight)
   */
  public function handle_cors() {
    // Only handle REST API requests
    $request_uri = $_SERVER['REQUEST_URI'] ?? '';

    if ( strpos( $request_uri, '/wp-json/mwai-ui/v1/' ) === false ) {
      return;
    }

    $origin = $this->get_request_origin();

    // For OPTIONS preflight requests
    if ( isset( $_SERVER['REQUEST_METHOD'] ) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS' ) {
      $this->handle_preflight( $origin );
      return;
    }
  }

  private function handle_preflight( $origin ) {
    // For OPTIONS requests, check if ANY chatbot allows this origin
    $chatbots = $this->core->get_chatbots();

    foreach ( $chatbots as $chatbot ) {
      $cross_site = $chatbot['crossSite'] ?? [];
      if ( !empty( $cross_site['enabled'] ) ) {
        $allowed_domains = $cross_site['allowedDomains'] ?? [];
        if ( $this->validate_domain( $origin, $allowed_domains ) ) {
          header( 'Access-Control-Allow-Origin: ' . $origin );
          header( 'Access-Control-Allow-Credentials: true' );
          header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
          header( 'Access-Control-Allow-Headers: Content-Type, X-WP-Nonce' );
          header( 'Access-Control-Max-Age: 3600' );
          exit;
        }
      }
    }
  }

}
