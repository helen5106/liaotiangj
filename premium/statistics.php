<?php

/**
* Class MeowPro_MWAI_Statistics
*
* Main statistics manager. Handles database logic, logging, querying stats, shortcodes, etc.
*/
class MeowPro_MWAI_Statistics {
  #region Properties

  private $core = null;
  private $wpdb = null;
  private $db_check = false;
  private $table_logs = null;
  private $table_logmeta = null;

  #endregion

  #region Constructor

  public function __construct() {
    global $wpdb, $mwai_core;
    $this->core = $mwai_core;
    $this->wpdb = $wpdb;
    $this->table_logs = $wpdb->prefix . 'mwai_logs';
    $this->table_logmeta = $wpdb->prefix . 'mwai_logmeta';

    // Filters
    add_filter( 'mwai_stats_query', [ $this, 'stats_query' ], 10, 1 );
    add_filter( 'mwai_stats_logs_list', [ $this, 'stats_logs_list' ], 10, 5 );
    add_filter( 'mwai_stats_logs_delete', [ $this, 'stats_logs_delete' ], 10, 2 );
    add_filter( 'mwai_stats_logs_meta', [ $this, 'stats_logs_meta' ], 10, 5 );
    add_filter( 'mwai_stats_logs_activity', [ $this, 'stats_logs_activity' ], 10, 1 );
    add_filter( 'mwai_stats_logs_activity_daily', [ $this, 'stats_logs_activity_daily' ], 10, 3 );
    add_filter( 'mwai_stats_logs_activity_daily_by_model', [ $this, 'stats_logs_activity_daily_by_model' ], 10, 2 );

    // Cleanup task (Insights retention).
    add_filter( 'mwai_task_cleanup_statistics', [ $this, 'handle_cleanup_task' ], 10, 2 );

    // Shortcodes
    add_shortcode( 'mwai_stats_current', [ $this, 'shortcode_current' ] );
    add_shortcode( 'mwai_stats', [ $this, 'shortcode_current' ] );

    // For backward-compat: log every AI reply
    add_filter( 'mwai_ai_reply', function ( $reply, $query ) {
      global $mwai_stats;
      $mwai_stats->commit_stats_from_query( $query, $reply, [] );
      return $reply;
    }, 10, 2 );

    // Log every MCP tool call (Claude, ChatGPT, Claude Code, etc.).
    // Fires from labs/mcp.php execute_tool() regardless of success or error,
    // so admins can see attempted-but-blocked calls in MCP Logs too.
    add_action( 'mwai_mcp_tool_called', [ $this, 'commit_stats_from_mcp_tool_call' ], 10, 1 );

    // Session cookie is now set lazily when get_session_id() is called
    // This happens when the user first interacts with the chatbot

    // Limits
    $limits = $this->core->get_option( 'limits' );
    if ( isset( $limits['enabled'] ) && $limits['enabled'] ) {
      add_filter( 'mwai_ai_allowed', [ $this, 'check_limits' ], 10, 3 );
    }
  }

  #endregion

  #region Public API

  /**
  * Commits a stats object into the database (inserting or updating if refId is found).
  *
  * @param MeowPro_MWAI_Stats $statsObject
  * @return bool True if success, false if error.
  */
  public function commit_stats( MeowPro_MWAI_Stats $statsObject ): bool {
    $this->check_db();

    // 1. Check if refId is set and if an entry already exists for that refId.
    $existingId = null;
    if ( !empty( $statsObject->refId ) ) {
      $sql = $this->wpdb->prepare(
        "SELECT id FROM {$this->table_logs} WHERE refId = %s LIMIT 1",
        $statsObject->refId
      );
      $existingId = $this->wpdb->get_var( $sql );
    }

    // 2. Prepare data
    $data = [
      'refId' => $statsObject->refId,
      'session' => $statsObject->session,
      'feature' => $statsObject->feature,
      'model' => $statsObject->model,
      'envId' => $statsObject->envId,
      'units' => $statsObject->units,
      'type' => $statsObject->type,
      'price' => $statsObject->price,
      'scope' => $statsObject->scope,
      'accuracy' => $statsObject->accuracy,
      // Store the stats array as JSON if not empty
      'stats' => !empty( $statsObject->stats ) ? wp_json_encode( $statsObject->stats ) : null,
    ];
    $data = $this->validate_data( $data );

    // 3. If refId found, update; else insert new row.
    if ( $existingId ) {
      $res = $this->wpdb->update(
        $this->table_logs,
        $data,
        [ 'id' => $existingId ]
      );
      if ( $res === false ) {
        Meow_MWAI_Logging::error( 'Error while updating logs (' . $this->wpdb->last_error . ')' );
        return false;
      }
      $logId = $existingId;
    }
    else {
      $logId = $this->add_log( $data );
      if ( !$logId ) {
        return false;
      }
    }

    // 4. Handle metadata
    if ( !empty( $statsObject->metadata ) && $this->core->get_option( 'statistics_data' ) ) {
      foreach ( $statsObject->metadata as $metaKey => $metaValue ) {
        $this->add_metadata(
          $logId,
          $metaKey,
          is_array( $metaValue ) ? json_encode( $metaValue ) : $metaValue
        );
      }
    }

    return true;
  }

  /**
  * For backward compatibility: commits stats from a query/reply pair.
  *
  * @param Meow_MWAI_Query_Base $query
  * @param Meow_MWAI_Reply      $reply
  * @param array                $overrides
  */
  public function commit_stats_from_query( $query, $reply, array $overrides ): void {
    $type = null;
    $units = 0;

    if (
      is_a( $query, 'Meow_MWAI_Query_Text' ) ||
        is_a( $query, 'Meow_MWAI_Query_Embed' ) ||
          is_a( $query, 'Meow_MWAI_Query_Assistant' ) ||
            is_a( $query, 'Meow_MWAI_Query_Feedback' )
    ) {
      $type = 'tokens';
      $units = $reply->get_units();
    }
    elseif ( is_a( $query, 'Meow_MWAI_Query_Image' ) ) {
      // Image models use different billing methods:
      // - Token-based models (e.g. gpt-image-*): Billed by tokens, reply contains total_tokens
      // - Per-image models (e.g. some Replicate/Google/OpenRouter models): Billed by image count
      if ( isset( $reply->usage['total_tokens'] ) && $reply->usage['total_tokens'] > 0 ) {
        $type = 'tokens';
        $units = $reply->usage['total_tokens'];
      }
      else {
        $type = 'images';
        $units = $reply->get_units();
      }
    }
    elseif ( is_a( $query, 'Meow_MWAI_QueryTranscribe' ) ) {
      $type = 'seconds';
      $units = $reply->get_units();
    }
    else {
      return;
    }

    $statsObject = new MeowPro_MWAI_Stats();
    $statsObject->session = $query->session;
    $statsObject->scope = $query->scope;
    $statsObject->feature = $query->feature;
    // Use the actual model returned by the API (stored in reply) if available
    $statsObject->model = !empty( $reply->model ) ? $reply->model : $query->model;
    $statsObject->envId = $query->envId;
    $statsObject->units = $units;
    $statsObject->type = $type;
    $statsObject->accuracy = $reply->get_usage_accuracy();

    // Overriding or setting a refId
    if ( isset( $overrides['refId'] ) ) {
      $statsObject->refId = $overrides['refId'];
    }

    // Price
    if ( empty( $overrides['price'] ) ) {
      $engine = Meow_MWAI_Engines_Factory::get( $this->core, $query->envId );
      $statsObject->price = $engine->get_price( $query, $reply );
    }
    else {
      $statsObject->price = $overrides['price'];
    }

    // Storing the raw query/reply for debugging (if enabled)
    if ( $this->core->get_option( 'statistics_data' ) ) {
      $statsObject->metadata['query'] = $query->toJson();
      $statsObject->metadata['reply'] = $reply->toJson();
    }

    // If it's a form, also store the form fields
    if ( $this->core->get_option( 'statistics_forms_data' ) ) {
      if ( $query->scope === 'form' && $query instanceof Meow_MWAI_Query_Text ) {
        $fields = $query->getExtraParam( 'fields' );
        if ( !empty( $fields ) ) {
          $statsObject->metadata['fields'] = $fields;
        }
      }
    }

    // Finally commit
    $this->commit_stats( $statsObject );
  }

  /**
   * Insert one row in wp_mwai_logs for an MCP tool call. Hooked to
   * mwai_mcp_tool_called fired by labs/mcp.php execute_tool(). Reuses the
   * existing logs table with a feature='mcp_tool' discriminator so Insights
   * UI, retention task, and Privacy First IP handling all work unchanged.
   *
   * @param array $event { tool, args, result, status, error_msg, duration_ms,
   *                       client_id, client_name, auth_method, request_id, user_id }
   */
  public function commit_stats_from_mcp_tool_call( $event ): void {
    if ( !is_array( $event ) || empty( $event['tool'] ) ) {
      return;
    }
    if ( !$this->core->get_option( 'mcp_log_calls', true ) ) {
      return;
    }

    $this->check_db();

    $stats = [
      'client_name' => $event['client_name'] ?? null,
      'auth_method' => $event['auth_method'] ?? null,
      'duration_ms' => isset( $event['duration_ms'] ) ? (int) $event['duration_ms'] : null,
      'status'      => $event['status'] ?? null,
    ];
    if ( !empty( $event['error_msg'] ) ) {
      $stats['error_msg'] = (string) $event['error_msg'];
    }

    $data = [
      'session'  => null,
      'feature'  => 'mcp_tool',
      'model'    => null,
      'envId'    => isset( $event['client_id'] ) ? (string) $event['client_id'] : null,
      'units'    => 0,
      'type'     => 'mcp_call',
      'price'    => 0,
      'scope'    => (string) $event['tool'],
      'accuracy' => 'none',
      'refId'    => isset( $event['request_id'] ) ? (string) $event['request_id'] : null,
      'stats'    => wp_json_encode( $stats ),
    ];

    $logId = $this->add_log( $data );
    if ( !$logId ) {
      return;
    }

    // Opt-in payload capture. Tool arguments can contain post content,
    // search queries, user-identifying data — off by default.
    if ( $this->core->get_option( 'mcp_log_payloads', false ) ) {
      if ( isset( $event['args'] ) ) {
        $this->add_metadata(
          $logId,
          'mcp_args',
          wp_json_encode( $event['args'] )
        );
      }
      if ( isset( $event['result'] ) ) {
        $this->add_metadata(
          $logId,
          'mcp_result',
          wp_json_encode( $event['result'] )
        );
      }
    }
  }

  /**
  * Commits stats from a real-time request, calculates the price from complex tokens data, etc.
  *
  * @param MeowPro_MWAI_Stats $statsObject
  * @return bool
  */
  public function commit_stats_from_realtime( MeowPro_MWAI_Stats $statsObject ): bool {
    $statsArray = is_array( $statsObject->stats ) ? $statsObject->stats : [];
    if ( empty( $statsObject->model ) ) {
      Meow_MWAI_Logging::warn( 'Realtime stats: no model specified.' );
      return false;
    }

    // Attempt to fetch the model definition from the engine
    $engine = Meow_MWAI_Engines_Factory::get( $this->core, $statsObject->envId );
    if ( !$engine ) {
      Meow_MWAI_Logging::warn( "Realtime stats: no engine found for envId ({$statsObject->envId})." );
      return false;
    }

    $modelDef = $engine->retrieve_model_info( $statsObject->model );
    if ( !$modelDef ) {
      Meow_MWAI_Logging::warn( "Realtime stats: model definition not found ({$statsObject->model})." );
      return false;
    }

    // Now we can calculate the price
    $textIn = isset( $statsArray['text_input_tokens'] ) ? intval( $statsArray['text_input_tokens'] ) : 0;
    $audioIn = isset( $statsArray['audio_input_tokens'] ) ? intval( $statsArray['audio_input_tokens'] ) : 0;
    $textOut = isset( $statsArray['text_output_tokens'] ) ? intval( $statsArray['text_output_tokens'] ) : 0;
    $audioOut = isset( $statsArray['audio_output_tokens'] ) ? intval( $statsArray['audio_output_tokens'] ) : 0;
    $textCache = isset( $statsArray['text_cached_tokens'] ) ? intval( $statsArray['text_cached_tokens'] ) : 0;
    $audioCache = isset( $statsArray['audio_cached_tokens'] ) ? intval( $statsArray['audio_cached_tokens'] ) : 0;

    $priceSum = 0.0;
    $unit = !empty( $modelDef['unit'] ) ? floatval( $modelDef['unit'] ) : 1.0;
    $price = isset( $modelDef['price'] ) ? $modelDef['price'] : [];

    // TEXT
    if ( !empty( $price['text'] ) ) {
      $textPrices = $price['text'];
      $priceSum += $textIn * floatval( $textPrices['in'] ?? 0 );
      $priceSum += $textOut * floatval( $textPrices['out'] ?? 0 );
      $priceSum += $textCache * floatval( $textPrices['cache'] ?? 0 );
    }

    // AUDIO
    if ( !empty( $price['audio'] ) ) {
      $audioPrices = $price['audio'];
      $priceSum += $audioIn * floatval( $audioPrices['in'] ?? 0 );
      $priceSum += $audioOut * floatval( $audioPrices['out'] ?? 0 );
      $priceSum += $audioCache * floatval( $audioPrices['cache'] ?? 0 );
    }

    // Multiply by unit factor
    $priceSum = $priceSum * $unit;

    // Let's add the total of tokens in total_tokens
    $statsArray['total_tokens'] = $textIn + $audioIn + $textOut + $audioOut + $textCache + $audioCache;

    // Now store it in the stats object
    $statsObject->price = round( $priceSum, 6 );
    $statsObject->type = 'tokens'; // or something more relevant
    $statsObject->units = isset( $statsArray['total_tokens'] )
        ? intval( $statsArray['total_tokens'] )
            : 0;
    $statsObject->accuracy = 'tokens'; // Token count from Realtime API, price calculated from model pricing

    // Then commit
    return $this->commit_stats( $statsObject );
  }

  /**
  * Retrieves usage stats.
  */
  public function stats_query( $timeFrame = null, $isAbsolute = null, $userId = null, $ipAddress = null, $system = false ) {
    return $this->query_stats( $timeFrame, $isAbsolute, $userId, $ipAddress, $system );
  }

  #endregion

  #region Shortcodes

  /**
  * Handles [mwai_stats_current] and [mwai_stats] shortcodes.
  *
  * Usage examples:
  * [mwai_stats_current display="usage"]
  * [mwai_stats display="debug"]
  */
  public function shortcode_current( $atts ) {

    // Determine what to display (usage, debug, etc.)
    $display = isset( $atts['display'] ) ? $atts['display'] : 'debug';
    if ( $display === 'debug' ) {
      $display = 'stats'; // old fallback
    }
    elseif ( $display === 'usage' ) {
      $display = 'usagebar';
    }

    // Additional "display_*" attributes
    $showWho = filter_var( $atts['display_who'] ?? true, FILTER_VALIDATE_BOOLEAN );
    $showQueries = filter_var( $atts['display_queries'] ?? true, FILTER_VALIDATE_BOOLEAN );
    // 'display_units' is the legacy attribute; 'display_tokens' is the new
    // canonical one. Either turns the row on; if the caller explicitly used
    // the legacy spelling we keep the legacy "Units" label and footnote.
    $usedLegacyUnits = array_key_exists( 'display_units', $atts );
    $showTokens = filter_var( $atts['display_tokens'] ?? $atts['display_units'] ?? true, FILTER_VALIDATE_BOOLEAN );
    $showPrice = filter_var( $atts['display_price'] ?? true, FILTER_VALIDATE_BOOLEAN );
    $showUsage = filter_var( $atts['display_usage'] ?? true, FILTER_VALIDATE_BOOLEAN );
    $showCoins = filter_var( $atts['display_coins'] ?? true, FILTER_VALIDATE_BOOLEAN );

    // Fetch the stats using the new query_stats method
    $stats = $this->query_stats();

    // If there's no stats at all
    if ( empty( $stats ) ) {
      return 'No stats available.';
    }

    // ============== Display: usage bar ==============
    if ( $display === 'usagebar' ) {
      $percent = $stats['usagePercentage'] ?? 0;
      $cssPercent = min( 100, $percent ); // cap at 100
      $output = '<div class="mwai-statistics mwai-statistics-usage">';
      $output .= '  <div class="mwai-statistics-bar-container">';
      $output .= '    <div class="mwai-statistics-bar" style="width: ' . $cssPercent . '%;"></div>';
      $output .= '  </div>';
      $output .= '  <div class="mwai-statistics-bar-text">' . $percent . '%</div>';
      $output .= '</div>';

      // Optional: inline CSS from file
      $css = file_get_contents( MWAI_PATH . '/premium/styles/stats_ChatGPT.css' );
      $output .= '<style>' . $css . '</style>';

      return $output;
    }

    // ============== Display: stats / debug ==============
    elseif ( $display === 'stats' ) {
      $output = '<div class="mwai-statistics mwai-statistics-debug">';

      // Show user ID / IP
      if ( $showWho ) {
        if ( !empty( $stats['userId'] ) ) {
          $output .= "<div>User ID: {$stats['userId']}</div>";
        }
        if ( !empty( $stats['ipAddress'] ) ) {
          $output .= "<div>IP Address: {$stats['ipAddress']}</div>";
        }
      }

      // Show queries
      if ( $showQueries ) {
        $output .= "<div>Queries: {$stats['queries']}";
        if ( !empty( $stats['queriesLimit'] ) ) {
          $output .= " / {$stats['queriesLimit']}";
        }
        $output .= '</div>';
      }

      // Show tokens (was "units" before — kept for back-compat via display_units).
      if ( $showTokens ) {
        $label = $usedLegacyUnits ? 'Units' : 'Tokens';
        $output .= "<div>$label: {$stats['units']}";
        if ( !empty( $stats['unitsLimit'] ) ) {
          $output .= " / {$stats['unitsLimit']}";
        }
        $output .= '</div>';
        if ( $usedLegacyUnits ) {
          $output .= '<small>Note: Units can be tokens, images, etc.</small>';
        }
      }

      // Show price
      if ( $showPrice ) {
        $output .= "<div>Price: {$stats['price']}$";
        if ( !empty( $stats['priceLimit'] ) ) {
          $output .= " / {$stats['priceLimit']}$";
        }
        $output .= '</div>';
      }

      // Show 'coins' or custom currency, via a filter
      if ( $showCoins ) {
        $coins = apply_filters( 'mwai_stats_coins', $stats['price'], $stats, $atts );
        $coinsLimit = apply_filters( 'mwai_stats_coins_limit', $stats['priceLimit'], $stats, $atts );
        $output .= "<div>Coins: {$coins}";
        if ( !empty( $coinsLimit ) ) {
          $output .= " / {$coinsLimit}";
        }
        $output .= '</div>';
      }

      // Show usage % & whether user is over the limit
      if ( $showUsage && isset( $stats['usagePercentage'] ) ) {
        $output .= "<div>Usage: {$stats['usagePercentage']}% ";
        $output .= (
          $stats['overLimit']
            ? '<span class="mwai-over">(OVER LIMIT)</span>'
            : '<span class="mwai-ok">(OK)</span>'
        );
        $output .= '</div>';
      }

      $output .= '</div>';
      return $output;
    }

    // if no recognized display...
    return 'No valid display mode was provided.';
  }

  #endregion

  #region Internal API

  public function check_limits( $allowed, $query, $limits ) {
    global $mwai_stats;
    if ( empty( $mwai_stats ) ) {
      return $allowed;
    }

    $hasLimits = $limits && $limits['enabled'];
    if ( !$hasLimits ) {
      return $allowed;
    }

    // System
    if ( isset( $limits['system'] ) && $limits['system']['credits'] > 0 ) {
      $credits = $limits['system']['credits'];
      if ( $credits > 0 ) {
        $stats = $this->query_stats( null, null, null, null, true );
        if ( !empty( $stats ) && $stats['overLimit'] ) {
          return $limits['system']['overLimitMessage'];
        }
      }
    }

    // Identify target: user or guest
    $userId = $this->core->get_user_id();
    if ( $userId >= 0 ) {
      wp_set_current_user( $userId );
    }
    $target = $userId ? 'users' : 'guests';

    // Check ignored users
    if ( $target === 'users' ) {
      $ignoredUsers = $limits['users']['ignoredUsers'];
      $isAdministrator = current_user_can( 'administrator', $userId );
      if ( $isAdministrator && strpos( $ignoredUsers, 'administrator' ) !== false ) {
        return $allowed;
      }
      $isEditor = current_user_can( 'editor' );
      if ( $isEditor && strpos( $ignoredUsers, 'editor' ) !== false ) {
        return $allowed;
      }
    }

    // Check usage
    $credits = apply_filters( 'mwai_stats_credits', $limits[$target]['credits'], $userId, $query );
    if ( $credits === 0 ) {
      return $limits[ $target ]['overLimitMessage'];
    }
    $stats = $this->query_stats();
    if ( !empty( $stats ) && $stats['overLimit'] ) {
      return $limits[ $target ]['overLimitMessage'];
    }

    return $allowed;
  }

  public function query_stats( $timeFrame = null, $isAbsolute = null, $userId = null, $ipAddress = null, $system = false ) {
    if ( $system ) {
      $userId = null;
      $ipAddress = null;
      $target = 'system';
    }
    else {
      $target = 'guests';
      if ( $userId === null && $ipAddress === null ) {
        $userId = $this->core->get_user_id();
        if ( $userId ) {
          $target = 'users';
        }
        else {
          $ipAddress = $this->core->get_ip_address();
          if ( $ipAddress === null ) {
            Meow_MWAI_Logging::warn( 'There should be an userId or an ipAddress.' );
            return null;
          }
        }
      }
    }

    $limitsOption = $this->core->get_option( 'limits' );
    $hasLimits = $limitsOption && isset( $limitsOption['enabled'] ) && $limitsOption['enabled'];
    $limits = $hasLimits ? $limitsOption[ $target ] : [];
    if ( $timeFrame === null && !empty( $limits['timeFrame'] ) ) {
      $timeFrame = $limits['timeFrame'];
    }
    if ( $isAbsolute === null && isset( $limits['isAbsolute'] ) ) {
      $isAbsolute = $limits['isAbsolute'];
    }

    $this->check_db();
    $prefix = esc_sql( $this->wpdb->prefix );
    $sql = "SELECT COUNT(*) AS queries, SUM(units) AS units, SUM(price) AS price FROM {$prefix}mwai_logs WHERE ";

    // Condition: userId or ipAddress or system
    if ( $target === 'users' ) {
      $sql .= "userId = '" . esc_sql( $userId ) . "'";
    }
    elseif ( $target === 'guests' ) {
      $sql .= "ip = '" . esc_sql( $ipAddress ) . "'";
    }
    else {
      $sql .= '1 = 1';
    }

    // Time frame
    $timeUnits = [ 'second', 'minute', 'hour', 'day', 'week', 'month', 'year' ];
    if ( in_array( $timeFrame, $timeUnits ) ) {
      $now = date( 'Y-m-d H:i:s' );
      if ( $isAbsolute ) {
        $sql .= ' AND ' . strtoupper( $timeFrame ) . '(time) = ' . strtoupper( $timeFrame ) . "(\"$now\")";
      }
      else {
        $timeAgo = date( 'Y-m-d H:i:s', strtotime( "-1 $timeFrame" ) );
        $sql .= " AND time >= \"$timeAgo\"";
      }
    }
    else {
      Meow_MWAI_Logging::warn( 'TimeFrame should be hour, day, week, month, or year.' );
      return null;
    }

    $results = $this->wpdb->get_results( $sql );
    if ( count( $results ) === 0 ) {
      Meow_MWAI_Logging::warn( 'No results found for the statistics query.' );
      return null;
    }

    $result = $results[0];
    $stats = [];
    $stats['userId'] = $userId;
    $stats['ipAddress'] = $ipAddress;
    $stats['queries'] = intval( $result->queries );
    $stats['units'] = intval( $result->units );
    $stats['tokens'] = $stats['units']; // canonical alias; same value, new name
    $stats['price'] = round( floatval( $result->price ), 4 );

    $credits = 0;
    if ( $hasLimits ) {
      $credits = apply_filters( 'mwai_stats_credits', $limits['credits'], $userId, null );
    }

    // Limit type reads accept both 'units' (legacy) and 'tokens' (canonical).
    $isTokenLimit = $hasLimits && in_array( $limits['creditType'], [ 'units', 'tokens' ], true );
    $stats['queriesLimit'] = intval( $hasLimits && $limits['creditType'] === 'queries' ? $credits : 0 );
    $stats['unitsLimit'] = intval( $isTokenLimit ? $credits : 0 );
    $stats['tokensLimit'] = $stats['unitsLimit']; // canonical alias
    $stats['priceLimit'] = floatval( $hasLimits && $limits['creditType'] === 'price' ? $credits : 0 );

    $stats['overLimit'] = false;
    $stats['usagePercentage'] = 0;

    if ( $hasLimits ) {
      if ( $limits['creditType'] === 'queries' ) {
        $stats['overLimit'] = $stats['queries'] >= $credits;
        $stats['usagePercentage'] = $stats['queriesLimit'] > 0
            ? round( $stats['queries'] / $stats['queriesLimit'] * 100, 2 )
                  : 0;
      }
      elseif ( $isTokenLimit ) {
        $stats['overLimit'] = $stats['units'] >= $credits;
        $stats['usagePercentage'] = $stats['unitsLimit'] > 0
            ? round( $stats['units'] / $stats['unitsLimit'] * 100, 2 )
                  : 0;
      }
      elseif ( $limits['creditType'] === 'price' ) {
        $stats['overLimit'] = $stats['price'] >= $credits;
        $stats['usagePercentage'] = $stats['priceLimit'] > 0
            ? round( $stats['price'] / $stats['priceLimit'] * 100, 2 )
                  : 0;
      }
    }
    return $stats;
  }

  #endregion

  #region Helpers

  public function validate_data( $data ): array {
    $data['time'] = date( 'Y-m-d H:i:s' );
    $data['userId'] = $this->core->get_user_id( $data );
    $data['session'] = isset( $data['session'] ) ? (string) $data['session'] : null;
    $data['ip'] = $this->core->get_ip_address();
    $data['model'] = isset( $data['model'] ) ? (string) $data['model'] : null;
    $data['feature'] = isset( $data['feature'] ) ? (string) $data['feature'] : null;
    $data['units'] = isset( $data['units'] ) ? intval( $data['units'] ) : 0;
    $data['type'] = isset( $data['type'] ) ? (string) $data['type'] : null;
    $data['price'] = isset( $data['price'] ) && $data['price'] !== null ? floatval( $data['price'] ) : null;
    $data['scope'] = isset( $data['scope'] ) ? (string) $data['scope'] : null;
    $data['envId'] = isset( $data['envId'] ) ? (string) $data['envId'] : null;

    if ( isset( $data['refId'] ) ) {
      $data['refId'] = (string) $data['refId'];
    }
    else {
      $data['refId'] = null;
    }

    // stats is a LONGTEXT column, so we store the JSON.
    $data['stats'] = isset( $data['stats'] ) ? $data['stats'] : null;

    return $data;
  }

  public function add_log( $data ) {
    $this->check_db();
    $data = $this->validate_data( $data );
    if ( empty( $data ) ) {
      return false;
    }
    $res = $this->wpdb->insert( $this->table_logs, $data );
    if ( $res === false ) {
      Meow_MWAI_Logging::error( 'Error while writing logs (' . $this->wpdb->last_error . ')' );
      return false;
    }
    return $this->wpdb->insert_id;
  }

  public function add_metadata( int $logId, string $metaKey, string $metaValue ) {
    $data = [
      'log_id' => $logId,
      'meta_key' => $metaKey,
      'meta_value' => $metaValue,
    ];
    $res = $this->wpdb->insert( $this->table_logmeta, $data );
    if ( $res === false ) {
      Meow_MWAI_Logging::error( 'Error while writing logs metadata (' . $this->wpdb->last_error . ')' );
      return false;
    }
    return $this->wpdb->insert_id;
  }

  public function check_db(): bool {
    if ( $this->db_check ) {
      return true;
    }

    // Per-module version check: skip SHOW TABLES if already verified for this version.
    if ( get_option( 'mwai_db_version_statistics' ) === MWAI_VERSION ) {
      $this->db_check = true;
      return true;
    }

    $this->db_check = !(
      strtolower( $this->wpdb->get_var( "SHOW TABLES LIKE '$this->table_logs'" ) )
          != strtolower( $this->table_logs )
    );

    if ( !$this->db_check ) {
      $this->create_db();
      $this->db_check = !(
        strtolower( $this->wpdb->get_var( "SHOW TABLES LIKE '$this->table_logs'" ) )
            != strtolower( $this->table_logs )
      );
    }

    if ( $this->db_check ) {
      update_option( 'mwai_db_version_statistics', MWAI_VERSION, true );
    }

    return $this->db_check;
  }

  public function create_db(): void {
    $charset_collate = $this->wpdb->get_charset_collate();

    $sqlLogs = "CREATE TABLE $this->table_logs (
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        id BIGINT(20) NOT NULL AUTO_INCREMENT,
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          userId BIGINT(20) NULL,
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            ip VARCHAR(64) NULL,
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              session VARCHAR(64) NULL,
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                model VARCHAR(64) NULL,
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  feature VARCHAR(64) NULL,
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    units INT(11) NOT NULL DEFAULT 0,
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      type VARCHAR(64) NULL,
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        price FLOAT NULL DEFAULT NULL,
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        scope VARCHAR(64) NULL,
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          envId VARCHAR(128) NULL,
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            time DATETIME NOT NULL,
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            refId VARCHAR(64) NULL,
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              stats LONGTEXT NULL,
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              accuracy VARCHAR(20) NULL DEFAULT 'none',
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              PRIMARY KEY (id)
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              ) $charset_collate;";

    $sqlLogMeta = "CREATE TABLE $this->table_logmeta (
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              meta_id BIGINT(20) NOT NULL AUTO_INCREMENT,
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                log_id BIGINT(20) NOT NULL,
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  meta_key varchar(255) NULL,
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    meta_value longtext NULL,
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    PRIMARY KEY (meta_id)
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    ) $charset_collate;";

    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sqlLogs );
    dbDelta( $sqlLogMeta );
  }

  public function remove_db(): void {
    $sql = "DROP TABLE IF EXISTS $this->table_logs, $this->table_logmeta;";
    $this->wpdb->query( $sql );
  }

  #endregion

  #region Logs CRUD

  public function stats_logs_meta( array $meta, int $logId, array $metaKeys ) {
    $query = "SELECT * FROM $this->table_logmeta";
    $where = [];
    $where[] = 'log_id = ' . intval( $logId );
    if ( !empty( $metaKeys ) ) {
      // Sanitize each requested key (alphanumerics + underscore) before
      // splicing into the IN list. Caller is admin-only via permission_callback.
      $clean = array_filter( array_map( function ( $k ) {
        return preg_replace( '/[^a-zA-Z0-9_]/', '', (string) $k );
      }, $metaKeys ) );
      if ( !empty( $clean ) ) {
        $where[] = "meta_key IN ('" . implode( "','", $clean ) . "')";
      }
    }
    if ( !empty( $where ) ) {
      $query .= ' WHERE ' . implode( ' AND ', $where );
    }
    $query .= ' ORDER BY meta_key ASC';
    $res = $this->wpdb->get_results( $query, ARRAY_A );

    foreach ( $res as $value ) {
      // Generic: any requested meta_key is returned, JSON-decoded when it
      // looks like JSON. Keeps support for arbitrary keys (mcp_args,
      // mcp_result, …) without enumerating them here.
      $decoded = json_decode( $value['meta_value'], true );
      $meta[ $value['meta_key'] ] = ( $decoded === null && $value['meta_value'] !== 'null' )
        ? $value['meta_value']
        : $decoded;
    }
    return $meta;
  }

  public function stats_logs_delete( $success, $logIds ) {
    if ( !$success ) {
      return false;
    }
    $logIds = !empty( $logIds ) ? $logIds : [];
    if ( empty( $logIds ) ) {
      $query = "DELETE FROM $this->table_logs";
      $this->wpdb->query( $query );
      $query = "DELETE FROM $this->table_logmeta";
      $this->wpdb->query( $query );
      return true;
    }
    $logIds = array_map( 'intval', $logIds );
    $logIds = implode( ',', $logIds );

    $query = "DELETE FROM $this->table_logs WHERE id IN ($logIds)";
    $this->wpdb->query( $query );

    // Clean up meta
    $query = "DELETE FROM $this->table_logmeta WHERE log_id NOT IN (SELECT id FROM $this->table_logs)";
    $this->wpdb->query( $query );

    return true;
  }

  public function stats_logs_list( $logs = [], $offset = 0, $limit = null, $filters = null, $sort = null ) {
    $this->check_db();
    $offset = !empty( $offset ) ? intval( $offset ) : 0;
    $limit = !empty( $limit ) ? intval( $limit ) : 100;
    $filters = !empty( $filters ) ? $filters : [];
    $this->core->sanitize_sort( $sort, 'time', 'DESC' );

    $query = "SELECT * FROM $this->table_logs";

    // Filters
    $where = [];
    if ( !empty( $filters ) ) {
      foreach ( $filters as $filter ) {
        $accessor = $filter['accessor'];
        $value = $filter['value'];
        if ( empty( $value ) ) {
          continue;
        }
        if ( $accessor === 'user' ) {
          $isIP = filter_var( $value, FILTER_VALIDATE_IP );
          if ( $isIP ) {
            $where[] = "ip = '" . esc_sql( $value ) . "'";
          }
          else {
            $where[] = 'userId = ' . intval( $value );
          }
        }
        elseif ( $accessor === 'session' ) {
          $where[] = "session = '" . esc_sql( $value ) . "'";
        }
        elseif ( $accessor === 'model' ) {
          $where[] = "model = '" . esc_sql( $value ) . "'";
        }
        elseif ( $accessor === 'feature' ) {
          $where[] = "feature = '" . esc_sql( $value ) . "'";
        }
        elseif ( $accessor === 'feature_not' ) {
          // Inverse filter, used by the Insights "Query Logs" view to exclude
          // MCP tool-call rows so the table stays focused on AI completions.
          $where[] = "(feature IS NULL OR feature != '" . esc_sql( $value ) . "')";
        }
        elseif ( $accessor === 'units' ) {
          $where[] = 'units = ' . intval( $value );
        }
        elseif ( $accessor === 'type' ) {
          $where[] = "type = '" . esc_sql( $value ) . "'";
        }
        elseif ( $accessor === 'price' ) {
          $where[] = 'price = ' . floatval( $value );
        }
        elseif ( $accessor === 'scope' ) {
          $where[] = "scope IN ('" . implode( "','", $value ) . "')";
        }
        elseif ( $accessor === 'envId' ) {
          $where[] = "envId = '" . esc_sql( $value ) . "'";
        }
        elseif ( $accessor === 'time' ) {
          $where[] = "time = '" . esc_sql( $value ) . "'";
        }
        elseif ( $accessor === 'refId' ) {
          $where[] = "refId = '" . esc_sql( $value ) . "'";
        }
      }
    }

    if ( count( $where ) > 0 ) {
      $query .= ' WHERE ' . implode( ' AND ', $where );
    }

    $logs['total'] = $this->wpdb->get_var( "SELECT COUNT(*) FROM ($query) AS t" );

    $query .= ' ORDER BY ' . esc_sql( $sort['accessor'] ) . ' ' . esc_sql( $sort['by'] );
    if ( $limit > 0 ) {
      $query .= " LIMIT $offset, $limit";
    }

    $logs['rows'] = $this->wpdb->get_results( $query, ARRAY_A );
    // Dual-emit: alias the legacy 'units' field as 'tokens' on every row so
    // REST consumers can speak in either name. Same underlying value.
    foreach ( $logs['rows'] as &$row ) {
      if ( array_key_exists( 'units', $row ) ) {
        $row['tokens'] = $row['units'];
      }
    }
    unset( $row );
    return $logs;
  }

  public function stats_logs_activity( $hours = 24 ) {
    $this->check_db();
    $hours = intval( $hours );
    $query = $this->wpdb->prepare(
      "SELECT DATE_FORMAT(time, '%%Y-%%m-%%d %%H:00:00') AS h, COUNT(*) AS c FROM {$this->table_logs} WHERE time >= DATE_SUB(NOW(), INTERVAL %d HOUR) GROUP BY h ORDER BY h",
      $hours
    );
    $results = $this->wpdb->get_results( $query, ARRAY_A );
    $data = array_fill( 0, $hours, 0 );
    foreach ( $results as $row ) {
      $index = $hours - 1 - intval( ( strtotime( 'now' ) - strtotime( $row['h'] ) ) / 3600 );
      if ( $index >= 0 && $index < $hours ) {
        $data[$index] = intval( $row['c'] );
      }
    }
    return $data;
  }

  public function stats_logs_activity_daily( $data = [], $days = 31, $feature = null ) {
    $this->check_db();
    $days = intval( $days );

    // Ensure we have a valid days value
    if ( $days <= 0 ) {
      $days = 31;
    }

    $where = 'time >= DATE_SUB(NOW(), INTERVAL ' . intval( $days ) . ' DAY)';
    if ( is_string( $feature ) && $feature !== '' ) {
      $where .= " AND feature = '" . esc_sql( $feature ) . "'";
    }

    // Build query without wpdb->prepare for the DATE_FORMAT to avoid % escaping issues
    $query = "SELECT DATE_FORMAT(time, '%Y-%m-%d') AS d, COUNT(*) AS c FROM {$this->table_logs} WHERE $where GROUP BY d ORDER BY d";

    $results = $this->wpdb->get_results( $query, ARRAY_A );
    $result_data = array_fill( 0, $days, 0 );

    foreach ( $results as $row ) {
      $daysDiff = intval( ( strtotime( 'now' ) - strtotime( $row['d'] . ' 00:00:00' ) ) / 86400 );
      $index = $days - 1 - $daysDiff;
      if ( $index >= 0 && $index < $days ) {
        $result_data[$index] = intval( $row['c'] );
      }
    }

    return $result_data;
  }

  public function stats_logs_activity_daily_by_model( $data = [], $days = 31 ) {
    try {
      $this->check_db();
      $days = intval( $days );

      // Ensure we have a valid days value
      if ( $days <= 0 ) {
        $days = 31;
      }

      // Build query to get data grouped by date and model
      $query = "SELECT DATE_FORMAT(time, '%Y-%m-%d') AS d, model, COUNT(*) AS c 
                FROM {$this->table_logs} 
                WHERE time >= DATE_SUB(NOW(), INTERVAL " . intval( $days ) . ' DAY) 
                GROUP BY d, model 
                ORDER BY d, model';

      $results = $this->wpdb->get_results( $query, ARRAY_A );

      // Initialize result structure: array of days, each containing model counts
      $result_data = [];
      for ( $i = 0; $i < $days; $i++ ) {
        $result_data[$i] = [];
      }

      // Process results
      foreach ( $results as $row ) {
        if ( empty( $row['model'] ) ) {
          continue; // Skip entries without model
        }

        $daysDiff = intval( ( strtotime( 'now' ) - strtotime( $row['d'] . ' 00:00:00' ) ) / 86400 );
        $index = $days - 1 - $daysDiff;

        if ( $index >= 0 && $index < $days ) {
          $model = $row['model'];
          $result_data[$index][$model] = intval( $row['c'] );
        }
      }

      return $result_data;
    }
    catch ( Exception $e ) {
      error_log( 'AI Engine - Error in stats_logs_activity_daily_by_model: ' . $e->getMessage() );
      return array_fill( 0, $days, [] );
    }
  }

  /**
   * Handle cleanup task for the Insights logs (and their metadata).
   * Mirrors the discussions cleanup pattern: batched, resumable, time-budgeted.
   */
  public function handle_cleanup_task( $result, $job ) {
    $start = microtime( true );
    $retention_option = $this->core->get_option( 'statistics_retention_days' );
    // "Never" (or 0 / negative) disables the cleanup entirely.
    if ( $retention_option === 'Never' || (int) $retention_option <= 0 ) {
      return [
        'ok' => true,
        'done' => true,
        'message' => 'Insights cleanup disabled (retention set to Never)',
      ];
    }
    $retention_days = (int) apply_filters( 'mwai_statistics_retention_days', (int) $retention_option );
    if ( $retention_days <= 0 ) {
      return [
        'ok' => true,
        'done' => true,
        'message' => 'Insights cleanup disabled by filter',
      ];
    }
    $cutoff = date( 'Y-m-d H:i:s', strtotime( "-{$retention_days} days" ) );

    // Bail if logs table doesn't exist yet (fresh install, statistics never enabled).
    $table_exists = $this->wpdb->get_var( "SHOW TABLES LIKE '{$this->table_logs}'" );
    if ( !$table_exists ) {
      return [
        'ok' => true,
        'done' => true,
        'message' => 'Insights logs table does not exist yet',
      ];
    }

    $deleted_total = isset( $job['meta']['deleted_total'] ) ? (int) $job['meta']['deleted_total'] : 0;
    $last_id = isset( $job['meta']['last_id'] ) ? (int) $job['meta']['last_id'] : 0;

    $batch_size = 100;
    $deleted_batch = 0;

    $old_logs = $this->wpdb->get_results( $this->wpdb->prepare(
      "SELECT id FROM {$this->table_logs}
       WHERE time < %s AND id > %d
       ORDER BY id ASC
       LIMIT %d",
      $cutoff,
      $last_id,
      $batch_size
    ) );

    if ( !empty( $old_logs ) ) {
      $ids = wp_list_pluck( $old_logs, 'id' );
      $ids_string = implode( ',', array_map( 'intval', $ids ) );

      // Cascade: drop metadata first so we never leave orphans, even if
      // the second query is interrupted.
      $this->wpdb->query(
        "DELETE FROM {$this->table_logmeta} WHERE log_id IN ($ids_string)"
      );
      $deleted_batch = $this->wpdb->query(
        "DELETE FROM {$this->table_logs} WHERE id IN ($ids_string)"
      );

      $deleted_total += $deleted_batch;
      $last_id = end( $ids );
    }

    $has_more = count( $old_logs ) === $batch_size;
    $time_elapsed = microtime( true ) - $start;

    if ( $has_more && $time_elapsed < 8 ) {
      return [
        'ok' => true,
        'done' => false,
        'message' => sprintf( 'Deleted %d logs (total: %d)', $deleted_batch, $deleted_total ),
        'meta' => [
          'deleted_total' => $deleted_total,
          'last_id' => $last_id,
        ],
        'step' => $job['step'] + 1,
        'step_name' => 'batch_' . ( $job['step'] + 1 ),
      ];
    }

    return [
      'ok' => true,
      'done' => true,
      'message' => sprintf( 'Cleanup complete. Deleted %d logs older than %d days', $deleted_total, $retention_days ),
      'meta' => [
        'deleted_total' => 0,
        'last_id' => 0,
      ],
    ];
  }

  #endregion

}
