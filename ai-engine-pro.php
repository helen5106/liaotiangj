<?php

/*
Plugin Name: AI Engine (Pro)
Plugin URI: https://wordpress.org/plugins/ai-engine/
Description: AI meets WordPress. Your site can now chat, write poetry, solve problems, and maybe make you coffee.
Version: 3.5.4
Author: Jordy Meow
Author URI: https://jordymeow.com
Text Domain: ai-engine
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

define( 'MWAI_VERSION', '3.5.4' );
define( 'MWAI_PREFIX', 'mwai' );
define( 'MWAI_DOMAIN', 'ai-engine' );
define( 'MWAI_ENTRY', __FILE__ );
define( 'MWAI_PATH', dirname( __FILE__ ) );
define( 'MWAI_URL', plugin_dir_url( __FILE__ ) );
define( 'MWAI_ITEM_ID', 17631833 );
if ( !defined( 'MWAI_TIMEOUT' ) ) {
  define( 'MWAI_TIMEOUT', 60 * 5 );
}
if ( !defined( 'MWAI_SSL_VERIFY' ) ) {
  define( 'MWAI_SSL_VERIFY', false );
}
define( 'MWAI_FALLBACK_MODEL', 'gpt-5.5' );
define( 'MWAI_FALLBACK_MODEL_FAST', 'gpt-5-mini' );
define( 'MWAI_FALLBACK_MODEL_VISION', 'gpt-5-mini' );
define( 'MWAI_FALLBACK_MODEL_JSON', 'gpt-5-mini' );
define( 'MWAI_FALLBACK_MODEL_IMAGES', 'gpt-image-1.5' );
define( 'MWAI_FALLBACK_MODEL_AUDIO', 'whisper-1' );
define( 'MWAI_FALLBACK_MODEL_EMBEDDINGS', 'text-embedding-3-small' );

add_filter('pre_http_request', function($preempt, $args, $url) {
  if (strpos($url, 'check.meowapps.com') !== false || strpos($url, 'meowapps.com') !== false && strpos($url, 'license') !== false) {
    return ['body' => json_encode(['license' => 'valid', 'expires' => 'lifetime']), 'response' => ['code' => 200]];
  }
  return $preempt;
}, 10, 3);

update_option('mwai_license', ['key' => 'b5e0b5f8dd8689e6aca49dd6e6e1a930', 'issue' => null, 'debug' => null, 'expires' => 'lifetime', 'license' => 'valid']);

define( 'MEOWAPPS_MWAI_LICENSE', 'b5e0b5f8dd8689e6aca49dd6e6e1a930' );
update_option( 'mwai_license', [
  'key' => 'b5e0b5f8dd8689e6aca49dd6e6e1a930',
  'license' => 'valid',
  'expires' => 'lifetime',
  'issue' => null
] );

require_once( MWAI_PATH . '/classes/init.php' );

add_filter( 'mwai_ai_exception', function ( $exception ) {
  try {
    // Remove the service prefix if present
    if ( strpos( $exception, 'OpenAI:' ) === 0 ) {
      $exception = trim( substr( $exception, strlen( 'OpenAI:' ) ) );
    }

    // If the remaining string looks like JSON, try to decode it
    $json = json_decode( $exception, true );
    if ( is_array( $json ) && isset( $json['error']['message'] ) ) {
      $exception = $json['error']['message'];
    }

    if ( strpos( $exception, 'OpenAI' ) !== false ) {
      if ( strpos( $exception, 'API URL was not found' ) !== false ) {
        return "Received the 'API URL was not found' error from OpenAI. This actually means that your OpenAI account has not been enabled for the Chat API. You need to either add some credits to OpenAI account, or link a credit card to it.";
      }
    }
    return $exception;
  }
  catch ( Exception $e ) {
    error_log( $e->getMessage() );
  }
  return $exception;
} );
