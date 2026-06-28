<?php

if ( !class_exists( 'MeowKitPro_MWAI_Integrity' ) ) {

  class MeowKitPro_MWAI_Integrity {

    private $prefix;
    private $base_path;

    public function __construct( $prefix, $base_path ) {
      $this->prefix = $prefix;
      $this->base_path = $base_path;
    }

    /**
     * Get build reference (integrity checksum of critical files)
     * Cached in transient for performance (24 hours)
     */
    public function get_build_ref( $version ) {
      $cache_key = $this->prefix . '_build_ref_' . $version;
      $cached = get_transient( $cache_key );

      if ( $cached !== false ) {
        return $cached;
      }

      // Calculate checksum
      $files = [
        'common/premium/licenser.php',
        'common/premium/rest_license.php',
        'common/premium/updater.php'
      ];

      $combined = '';

      foreach ( $files as $file ) {
        $file_path = $this->base_path . '/' . $file;
        if ( file_exists( $file_path ) ) {
          $combined .= md5_file( $file_path );
        }
      }

      // SHA256 of combined MD5s (same as webpack)
      $build_ref = hash( 'sha256', $combined );

      // Cache for 24 hours
      set_transient( $cache_key, $build_ref, DAY_IN_SECONDS );

      return $build_ref;
    }

    /**
     * Track MEOW_OVERRIDE usage
     */
    public function check_override() {
      $constant_name = 'MEOWAPPS_' . strtoupper( $this->prefix ) . '_LICENSE';

      if ( defined( $constant_name ) ) {
        $license = constant( $constant_name );
        if ( !empty( $license ) && $license === 'MEOW_OVERRIDE' ) {
          $this->report_override_usage();
          return true;
        }
      }

      return false;
    }

    /**
     * Report MEOW_OVERRIDE usage (non-blocking)
     */
    private function report_override_usage() {
      $last_report = get_transient( $this->prefix . '_override_reported' );
      if ( $last_report ) {
        return;
      }

      set_transient( $this->prefix . '_override_reported', time(), DAY_IN_SECONDS );

      $data = [
        'plugin' => $this->prefix,
        'site' => home_url(),
        'php' => PHP_VERSION,
        'wp' => get_bloginfo( 'version' ),
        'timestamp' => time()
      ];

      wp_remote_post(
        'https://track.meowapps.com/override',
        [
          'body' => $data,
          'timeout' => 1,
          'blocking' => false,
          'sslverify' => false
        ]
      );
    }
  }
}
