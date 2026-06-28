<?php

// phpcs:disable

if ( !class_exists( 'MeowKitPro_MWAI_Licenser' ) ) {

  class MeowKitPro_MWAI_Licenser {  
    public $license = null;
    public $prefix;     // prefix used for actions, filters (mfrh)
    public $mainfile;   // plugin main file (media-file-renamer.php)
    public $domain;     // domain used for translation (media-file-renamer)
    public $item;       // name of the Pro plugin (Media File Renamer Pro)
    public $version;    // version of the plugin (Media File Renamer Pro)
    public $item_id;

    private $primary_url = 'https://check.meowapps.com/'; // Primary license check URL
    private $fallback_enabled = true; // Set to false to disable fallback to old URL

    /**
    * Constructor for the MeowKitPro_MWAI_Licenser class.
    *
    * @param string $prefix The prefix used for actions and filters.
    * @param string $mainfile The plugin main file.
    * @param string $domain The domain used for translation.
    * @param string $item The name of the Pro plugin.
    * @param string $version The version of the plugin.
    */
    public function __construct( $prefix, $mainfile, $domain, $item, $version ) {
      $this->prefix = $prefix;
      $this->mainfile = $mainfile;
      $this->domain = $domain;
      $this->item = $item;
      $this->version = $version;
      $item_id_key = strtoupper( $this->prefix ) . '_ITEM_ID';
      if ( defined( $item_id_key ) ) {
        $this->item_id = constant( $item_id_key );
      }

      if ( $this->is_registered() ) {
        add_filter( $this->prefix . '_meowapps_is_registered', [ $this, 'is_registered' ], 10 );
      }

      if ( MeowKit_MWAI_Helpers::is_rest() ) {
        new MeowKitPro_MWAI_Rest_License( $this );
      }
      else if ( is_admin() ) {
        $license_key = isset( $this->license['key'] ) ? $this->license['key'] : '';
        $updater_options = [
          'version' => $this->version,
          'license' => $license_key,
          'wp_override' => true,
          'author' => 'Jordy Meow',
          'url' => strtolower( home_url() ),
          'beta' => false
        ];
        if ( $this->item_id ) {
          $updater_options['item_id'] = $this->item_id;
        }
        else {
          $updater_options['item_name'] = $this->item;
        }
        $api_url = ( get_option( 'force_sslverify', false ) ? 'https' : 'http' ) . '://meowapps.com';
        new MeowKitPro_MWAI_Updater( $api_url, $this->mainfile, $updater_options );
      }
    }

    /**
    * Retry validation of the license.
    */
    public function retry_validation() {
      if ( isset( $_POST[$this->prefix . '_pro_serial'] ) ) {
        $serial = sanitize_text_field( $_POST[$this->prefix . '_pro_serial'] );
        $this->validate_pro( $serial );
      }
    }

    /**
    * Check if the plugin is registered.
    *
    * @param bool $force Force re-check.
    * @return bool
    */
    public function is_registered( $force = false ) {
      $constant_name = 'MEOWAPPS_' . strtoupper( $this->prefix ) . '_LICENSE';
      if ( defined( $constant_name ) ) {
        $license = constant( $constant_name );
        if ( !empty( $license ) ) {
          $this->license = [
            'key' => $license,
            'logs' => 'Enabled by constant.'
          ];
          return true;
        }
      }

      if ( !$force && !empty( $this->license ) ) {
        $has_no_issues = empty( $this->license['issue'] );
        return $has_no_issues;
      }
      $this->license = get_option( $this->prefix . '_license', '' );
      if ( empty( $this->license ) || !empty( $this->license['issue'] ) ) {
        return false;
      }
      if ( $this->license['expires'] == 'lifetime' ) {
        return true;
      }
      $datediff = strtotime( $this->license['expires'] ) - time();
      $days = floor( $datediff / ( 60 * 60 * 24 * 7 * 3 ) );
      if ( $days < 0 ) {
        $this->validate_pro( $this->license['key'] );
      }
      return true;
    }

    /**
    * Validate the Pro license.
    *
    * @param string $subscr_id The subscription ID.
    * @param bool $override Whether to override existing validation.
    * @return bool
    */
    public function validate_pro( $subscr_id, $override = false ) {
      $prefix = $this->prefix;
      delete_option( $prefix . '_license', '' );

      // Trim whitespace from license key to prevent user input errors
      $subscr_id = trim( $subscr_id );

      if ( empty( $subscr_id ) ) {
        $this->license = null;
        return false;
      }

      if ( $override ) {
        // This doesn't work with updates.
        $current_user = wp_get_current_user();
        delete_option( '_site_transient_update_plugins' );
        $url = $this->primary_url;
        if ( strpos( $url, '?' ) === false ) {
          $url .= '?';
        }
        else {
          $url .= '&';
        }
        $query_params = [
          'item_id' => $this->item_id ? $this->item_id : null,
          'item_name' => $this->item_id ? null : urlencode( $this->item ),
          'license' => $subscr_id,
          'url' => strtolower( home_url() ),
        ];
        $url .= http_build_query( array_filter( $query_params ) );
        update_option( $prefix . '_license', [ 'key' => $subscr_id, 'issue' => null,
          'logs' => sprintf( 'Forced by %s on %s.', $current_user->user_email, date( 'Y/m/d' ) ),
          'expires' => 'lifetime', 'license' => null, 'check_url' => $url ] );
      }
      else {
        $license_valid = false;
        $status = null;
        $license = null;
        $expires = null;
        $debug = null;
        $urls_to_try = [ $this->primary_url ];

        if ( $this->fallback_enabled ) {
          $fallback_url = $this->getFallbackUrl();
          if ( $fallback_url ) {
            $urls_to_try[] = $fallback_url;
          }
        }

        foreach ( $urls_to_try as $url_base ) {
          $url = $url_base;
          if ( strpos( $url, '?' ) === false ) {
            $url .= '?';
          }
          else {
            $url .= '&';
          }

          $query_params = [];

          // Include edd_action=activate_license only for the fallback URL
          if ( $url_base === $this->getFallbackUrl() ) {
            $query_params['edd_action'] = 'activate_license';
          }

          if ( $this->item_id ) {
            $query_params['item_id'] = $this->item_id;
          }
          else {
            $query_params['item_name'] = urlencode( $this->item );
          }
          $query_params['license'] = $subscr_id;
          $query_params['url'] = strtolower( home_url() );
          $query_params['cache'] = bin2hex( openssl_random_pseudo_bytes( 4 ) );

          $url .= http_build_query( $query_params );

          $response = wp_remote_get(
            $url,
            [
              'user-agent' => 'MeowApps',
              'sslverify' => get_option( 'force_sslverify', false ),
              'timeout' => 45,
              'method' => 'GET'
            ]
          );
          $body = is_array( $response ) ? $response['body'] : null;
          $http_code = is_array( $response ) ? $response['response']['code'] : null;
          $post = @json_decode( $body );

          if ( $post && !property_exists( $post, 'code' ) && $post->license === 'valid' ) {
            $license = $post->license;
            $expires = $post->expires;
            delete_option( '_site_transient_update_plugins' );
            $license_valid = true;
            break; // Exit the loop
          }
          else {
            // Record the error or status for debugging
            $status = $post ? ( isset( $post->error ) ? $post->error : 'invalid_response' ) : 'no_response';
            // Collect debug info
            $debug = [
              'resolved_ip' => null,
              'server_addr' => null,
              'server_host' => null,
              'google_response_code' => null,
              'meowapps_response_code' => null,
              'license_response_code' => $http_code,
              'google_body' => null,
              'meowapps_body' => null,
              'license_body' => null,
              // New debug parameters
              'google_reason' => null,
              'meowapps_reason' => null,
              'license_reason' => null,
              'google_cf_ray' => null,
              'meowapps_cf_ray' => null,
              'license_cf_ray' => null,
            ];

            // Google response
            $google_response = wp_remote_get( 'https://google.com' );
            $debug['google_response_code'] = is_wp_error( $google_response ) ? print_r( $google_response, true ) : wp_remote_retrieve_response_code( $google_response );
            if ( $debug['google_response_code'] !== 200 ) {
              $debug['google_body'] = wp_remote_retrieve_body( $google_response );
              // Detect block in Google response
              $google_block_info = $this->detect_block( $debug['google_body'] );
              if ( $google_block_info ) {
                $debug['google_reason'] = $google_block_info['reason'];
                if ( isset( $google_block_info['cf_ray'] ) ) {
                  $debug['google_cf_ray'] = $google_block_info['cf_ray'];
                }
              }
            }

            // MeowApps response
            $meowapps_response = wp_remote_get( 'https://meowapps.com' );
            $debug['meowapps_response_code'] = is_wp_error( $meowapps_response ) ? print_r( $meowapps_response, true ) : wp_remote_retrieve_response_code( $meowapps_response );
            if ( $debug['meowapps_response_code'] !== 200 ) {
              $debug['meowapps_body'] = wp_remote_retrieve_body( $meowapps_response );
              // Detect block in MeowApps response
              $meowapps_block_info = $this->detect_block( $debug['meowapps_body'] );
              if ( $meowapps_block_info ) {
                $debug['meowapps_reason'] = $meowapps_block_info['reason'];
                if ( isset( $meowapps_block_info['cf_ray'] ) ) {
                  $debug['meowapps_cf_ray'] = $meowapps_block_info['cf_ray'];
                }
              }
            }

            // License response
            if ( $http_code !== 200 ) {
              $debug['license_body'] = $body;
              // Detect block in License response
              $license_block_info = $this->detect_block( $debug['license_body'] );
              if ( $license_block_info ) {
                $debug['license_reason'] = $license_block_info['reason'];
                if ( isset( $license_block_info['cf_ray'] ) ) {
                  $debug['license_cf_ray'] = $license_block_info['cf_ray'];
                }
              }
            }

            // Resolve IP
            $resIp = wp_remote_get( 'https://api.ipify.org/' );
            if ( !is_wp_error( $resIp ) ) {
              $debug['resolved_ip'] = wp_remote_retrieve_body( $resIp );
            }
            $debug['server_addr'] = $_SERVER['SERVER_ADDR'];
            $debug['server_host'] = gethostbyname( $_SERVER['SERVER_NAME'] );

            // Continue to next URL in the list
          }
        } // End of foreach

        if ( !$license_valid ) {
          // License validation failed with all URLs
          update_option(
            $prefix . '_license',
            [ 'key' => $subscr_id, 'issue' => $status,
              'debug' => $debug, 'expires' => null, 'license' => null ]
          );
        }
        else {
          // License validated successfully
          update_option(
            $prefix . '_license',
            [ 'key' => $subscr_id, 'issue' => null,
              'debug' => null, 'expires' => $expires, 'license' => $license ]
          );
        }
      }
      return $this->is_registered( true );
    }

    /**
    * Get the fallback URL for license validation.
    *
    * @return string|null The fallback URL, or null if not available.
    */
    private function getFallbackUrl() {
      // Base64 encoded URL to hide it from direct view
      $encoded_url = 'aHR0cHM6Ly9tZW93YXBwcy5jb20v';
      return base64_decode( $encoded_url );
    }

    /**
    * Detect if the response body indicates a security block.
    *
    * @param string $body The response body.
    * @return array|null An array with block information or null if not blocked.
    */
    private function detect_block( $body ) {
      $info = [];

      // Check for Cloudflare block
      if ( strpos( $body, 'Attention Required! | Cloudflare' ) !== false || strpos( $body, 'Cloudflare Ray ID' ) !== false ) {
        $info['reason'] = 'CLOUDFLARE_SECURITY_TRIGGER';
        // Extract Cloudflare Ray ID
        if ( preg_match( '/Cloudflare Ray ID:\s*<strong.*?>(.*?)<\/strong>/', $body, $matches ) ) {
          $info['cf_ray'] = trim( $matches[1] );
        }
      }
      // Check for Google block
      elseif ( strpos( $body, "We're sorry" ) !== false && strpos( $body, 'automated queries' ) !== false ) {
        $info['reason'] = 'GOOGLE_SECURITY_TRIGGER';
      }

      return !empty( $info ) ? $info : null;
    }

  }
}
