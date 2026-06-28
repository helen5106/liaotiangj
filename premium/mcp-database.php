<?php

class MeowPro_MWAI_MCP_Database {
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

  #region Tools
  private function tools(): array {
    global $wpdb;
    return [
      'wp_db_query' => [
        'name' => 'wp_db_query',
        'description' => "Run a SQL query against the WordPress database and return the rows. The table prefix is '{$wpdb->prefix}'. Read-only by default: SELECT / SHOW / DESCRIBE / EXPLAIN / WITH. Anything else is a write and is blocked unless you pass confirm_write: true. The gate covers ALL non-read SQL — DML (INSERT, UPDATE, DELETE, REPLACE) and DDL (ALTER, DROP, CREATE, TRUNCATE, RENAME, GRANT, REVOKE). For post / meta mutations prefer the dedicated tools (wp_create_post, wp_update_post, wp_alter_post, wp_delete_post, wp_update_post_meta) which validate inputs and bust caches.",
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'query' => [
              'type' => 'string',
              'description' => 'The SQL query to execute.',
            ],
            'confirm_write' => [
              'type' => 'boolean',
              'description' => 'Required to be true for non-read queries. Without it, write queries are rejected.',
            ],
          ],
          'required' => [ 'query' ],
        ],
        'category' => 'AI Engine (Database)',
        'annotations' => [
          'readOnlyHint' => false,
          'destructiveHint' => true,
          'openWorldHint' => false,
        ],
        'accessLevel' => 'admin',
      ],
    ];
  }
  #endregion

  #region Register
  public function register_rest_tools( array $prev ): array {
    $tools = $this->tools();
    $merged = array_merge( $prev, array_values( $tools ) );
    return $merged;
  }
  #endregion

  #region Callback
  public function handle_call( $prev, string $tool, array $args, ?int $id ) {
    if ( !empty( $prev ) || !isset( $this->tools()[ $tool ] ) ) {
      return $prev;
    }
    if ( !current_user_can( 'administrator' ) ) {
      wp_set_current_user( 1 );
    }
    return $this->dispatch( $tool, $args );
  }
  #endregion

  #region Dispatcher
  private function dispatch( string $tool, array $a ) {
    global $wpdb;

    switch ( $tool ) {
      case 'wp_db_query':
        $query = $a['query'] ?? '';
        if ( empty( $query ) ) {
          throw new Exception( 'Query is required.' );
        }
        $trimmed = ltrim( $query );
        // Strip a leading SQL comment so "/* foo */ UPDATE ..." is still classified as a write.
        $trimmed = preg_replace( '#^(?:--[^\n]*\n|/\*.*?\*/)\s*#s', '', $trimmed );
        $is_read = (bool) preg_match( '/^\s*(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN|WITH)\b/i', $trimmed );
        if ( $is_read ) {
          $results = $wpdb->get_results( $query, ARRAY_A );
          if ( $wpdb->last_error ) {
            throw new Exception( $wpdb->last_error );
          }
          $count = count( $results );
          return [
            'rows' => $count,
            'data' => $results,
          ];
        }

        // Write path: require explicit confirmation. Silently no-op'ing writes is the worst
        // outcome (the caller assumes success and the data is unchanged), so be loud.
        if ( empty( $a['confirm_write'] ) ) {
          throw new Exception(
            'wp_db_query: write queries are blocked unless confirm_write is true. ' .
            'Prefer the dedicated tools (wp_create_post, wp_update_post, wp_alter_post, ' .
            'wp_delete_post, wp_update_post_meta) which validate inputs and bust caches. ' .
            'If raw SQL is genuinely necessary, retry with confirm_write: true.'
          );
        }
        $affected = $wpdb->query( $query );
        if ( $wpdb->last_error ) {
          throw new Exception( $wpdb->last_error );
        }
        if ( $affected === false ) {
          throw new Exception( 'wp_db_query: query failed (no MySQL error reported).' );
        }
        return [
          'affected_rows' => (int) $affected,
        ];

        // no break
      default:
        throw new Exception( 'Unknown tool' );
    }
  }
  #endregion
}
