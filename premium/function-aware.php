<?php

class MeowPro_MWAI_FunctionAware {
  private $core = null;

  /**
  * Constructor
  *
  * @param mixed $core
  */
  public function __construct( $core ) {
    $this->core = $core;

    // Register the functions from Code Engine.
    add_filter( 'mwai_functions_list', [ $this, 'functions_list' ], 10, 1 );

    // Handle the feedbacks for the functions created via Code Engine.
    add_filter( 'mwai_ai_feedback', [ $this, 'ai_feedbacks' ], 10, 3 );

    // Add the functions to the chatbot query.
    add_filter( 'mwai_chatbot_query', [ $this, 'chatbot_query' ], 10, 2 );
  }

  /**
  * Create a Meow_MWAI_Query_Function object based on type and id
  *
  * @param string $funcType
  * @param string $funcId
  * @return Meow_MWAI_Query_Function|null
  */
  public static function get_function( $funcType, $funcId ) {
    $functions = apply_filters( 'mwai_functions_list', [] );

    foreach ( $functions as $function ) {
      if ( $function->type === $funcType && $function->id === $funcId ) {
        return $function;
      }
    }

    Meow_MWAI_Logging::warn( "The function '{$funcId}' was not found." );
    return null;
  }

  /**
  * Add the functions from Code Engine.
  *
  * @param array $functions
  * @return array
  */
  public function functions_list( $functions ) {
    global $mwcode;
    if ( isset( $mwcode ) ) {
      $svFuncs = null;

      // Check for new method name first
      if ( method_exists( $mwcode, 'getSnippets' ) ) {
        $svFuncs = $mwcode->getSnippets( true, 'function' );
      }
      // Fall back to old method name for backward compatibility
      elseif ( method_exists( $mwcode, 'get_functions' ) ) {
        $svFuncs = $mwcode->get_functions();
      }

      if ( $svFuncs ) {
        foreach ( $svFuncs as $function ) {
          $function['type'] = 'code-engine';

          // Use functionName if available, otherwise fall back to name
          if ( isset( $function['functionName'] ) && !isset( $function['name'] ) ) {
            $function['name'] = $function['functionName'];
          }
          // If both exist and they're different, use functionName as it's the actual callable
          elseif ( isset( $function['functionName'] ) && $function['functionName'] !== $function['name'] ) {
            $function['name'] = $function['functionName'];
          }

          // Map functionTarget to target if it exists
          if ( isset( $function['functionTarget'] ) && !isset( $function['target'] ) ) {
            // Map 'js' to 'js' and 'php' to 'server' for compatibility
            $function['target'] = $function['functionTarget'] === 'php' ? 'server' : $function['functionTarget'];
          }

          // Map functionBehavior ('dynamic' | 'static') to behavior. Code Engine
          // stores it as functionBehavior; the AI Engine query class expects 'behavior'.
          // Static = execute the function but skip the AI feedback round-trip.
          if ( isset( $function['functionBehavior'] ) && !isset( $function['behavior'] ) ) {
            $function['behavior'] = $function['functionBehavior'];
          }

          // Convert Code Engine's functionArgsDict to AI Engine's expected args format
          if ( isset( $function['functionArgsDict'] ) && !isset( $function['args'] ) ) {
            $function['args'] = [];
            foreach ( $function['functionArgsDict'] as $argName => $argDetails ) {
              $function['args'][] = [
                'name' => $argName,
                'description' => $argDetails['desc'] ?? "The $argName parameter",
                'type' => $argDetails['type'] ?? 'string',
                'required' => $argDetails['required'] ?? true
              ];
            }
          }

          $func = Meow_MWAI_Query_Function::fromJson( $function );
          $functions[] = $func;
        }
      }
    }
    return $functions;
  }

  /**
  * Handle feedback for functions created via Code Engine.
  *
  * @param mixed $value
  * @param array $functionCall
  * @return mixed
  */
  public function ai_feedbacks( $value, $functionCall, $reply ) {
    $function = $functionCall['function'];
    if ( empty( $function ) || empty( $function->id ) ) {
      return $value;
    }
    if ( $function->type !== 'code-engine' ) {
      return $value;
    }

    // Not sure why Anthropic is sending an object with a type of 'object'
    // when there is nothing in the object. This is a workaround for that.
    $arguments = $functionCall['arguments'] ?? [];
    if ( is_array( $arguments ) && count( $arguments ) === 1 && isset( $arguments['type'] ) && $arguments['type'] === 'object' ) {
      $arguments = [];
    }

    // Check if we have a function_call in rawMessage but no tool_calls.
    if ( isset( $functionCall['rawMessage'] )
      && isset( $functionCall['rawMessage']['function_call'] )
            && ( !isset( $functionCall['rawMessage']['tool_calls'] ) || empty( $functionCall['rawMessage']['tool_calls'] ) )
    ) {
      $function_name = $functionCall['rawMessage']['function_call']['name'];
      $function_args = $functionCall['rawMessage']['function_call']['args'] ?? [];

      // Create a tool_call entry.
      $tool_id = 'tool_0_' . $function_name;
      $functionCall['rawMessage']['tool_calls'] = [
        [
          'id' => $tool_id,
          'type' => 'function',
          'function' => [
            'name' => $function_name,
            'arguments' => json_encode( $function_args ),
          ],
        ],
      ];

      // Update the functionCall with the tool ID.
      $functionCall['toolId'] = $tool_id;
      $functionCall['type'] = 'tool_call';

      // Make sure arguments are properly set in functionCall.
      if ( empty( $functionCall['arguments'] ) ) {
        $functionCall['arguments'] = $function_args;
      }
      elseif ( !empty( $function_args ) ) {
        // Merge existing arguments with function_args.
        $functionCall['arguments'] = array_merge( $functionCall['arguments'], $function_args );
      }
    }

    // Execute the function with Code Engine.
    global $mwcode;
    if ( empty( $mwcode ) ) {
      Meow_MWAI_Logging::warn( 'Code Engine is not available.' );
      return $value;
    }

    // Log the execution for debugging
    $executionId = uniqid( 'exec_' );
    Meow_MWAI_Logging::log( "[$executionId] Executing function '{$function->name}' (ID: {$function->id}, Type: {$function->type}, Target: " . ( $function->target ?? 'unknown' ) . ') with arguments: ' . json_encode( $functionCall['arguments'] ) );

    // Execute the function with Code Engine
    // Code Engine should handle both PHP and JS functions appropriately
    $value = $mwcode->executeSnippet( $function->id, $functionCall['arguments'], $reply );

    Meow_MWAI_Logging::log( "[$executionId] Function '{$function->name}' execution completed with result: " . ( is_string( $value ) ? substr( $value, 0, 100 ) : json_encode( $value ) ) );

    return $value;
  }

  /**
  * Add Code Engine functions to the chatbot query.
  *
  * @param Meow_MWAI_Query $query
  * @param array           $params
  * @return Meow_MWAI_Query
  */
  public function chatbot_query( $query, $params ) {
    if ( !is_array( $params ) ) {
      return $query;
    }

    // Handle functions
    $functions = $params['functions'] ?? [];
    if ( is_array( $functions ) ) {
      foreach ( $functions as $function ) {
        if ( !is_array( $function ) ) {
          continue;
        }
        $type = $function['type'] ?? null;
        $id = $function['id'] ?? null;

        $query_function = self::get_function( $type, $id );
        if ( $query_function ) {
          $query->add_function( $query_function );
        }
      }
    }

    // Handle MCP servers (only if Orchestration module is enabled)
    $mcpServers = $params['mcpServers'] ?? [];
    if ( is_array( $mcpServers ) && !empty( $mcpServers ) ) {
      // Check if Orchestration module is enabled
      if ( $this->core->get_option( 'module_orchestration' ) ) {
        $query->set_mcp_servers( $mcpServers );
      }
      else {
        // Log that MCP servers were ignored because Orchestration is disabled
        Meow_MWAI_Logging::log( 'MCP servers ignored: Orchestration module is disabled.' );
      }
    }

    return $query;
  }
}
