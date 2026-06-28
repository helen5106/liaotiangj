<?php

class MeowKitPro_MWAI_Rest_License {
  private $licenser = null;
  private $namespace = null;

  public function __construct( &$licenser ) {
    $this->licenser = $licenser;
    $this->namespace = "meow-licenser/{$licenser->prefix}/v1";
    add_action( 'rest_api_init', [ $this, 'rest_api_init' ] );
  }

  public function rest_api_init() {
    // Honour the per-plugin {$prefix}_allow_setup filter so roles granted access
    // to plugin settings can also manage the license. Default stays manage_options.
    $filter = $this->licenser->prefix . '_allow_setup';
    $permission = function () use ( $filter ) {
      return apply_filters( $filter, current_user_can( 'manage_options' ) );
    };
    register_rest_route( $this->namespace, '/get_license/', [
      'methods' => 'POST',
      'permission_callback' => $permission,
      'callback' => [ $this, 'get_license' ]
    ] );
    register_rest_route( $this->namespace, '/set_license/', [
      'methods' => 'POST',
      'permission_callback' => $permission,
      'callback' => [ $this, 'set_license' ]
    ] );
  }

  public function get_license() {
    $license = $this->licenser->license;
    if ( isset( $license['key'] ) ) {
      $license['key'] = trim( $license['key'] );
    }
    return new WP_REST_Response( [ 'success' => true, 'data' => $license ], 200 );
  }

  public function set_license( $request ) {
    $params = $request->get_json_params();
    $serialKey = isset( $params['serialKey'] ) ? trim( $params['serialKey'] ) : '';
    $override = isset( $params['override'] ) ? $params['override'] : false;
    $this->licenser->validate_pro( $serialKey, empty( $override ) ? false : true );
    return new WP_REST_Response( [ 'success' => true, 'data' => $this->licenser->license ], 200 );
  }
}
