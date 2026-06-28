<?php

class Meow_MWAI_Embeddings_ConversationContextBuilder {
  private $core;

  // Method names
  public const METHOD_SIMPLE = 'simple';
  public const METHOD_CONTEXT_AWARE = 'context_aware';
  public const METHOD_SMART_SEARCH = 'smart_search';

  public function __construct( $core ) {
    $this->core = $core;
  }

  /**
   * Build search query using the configured method
   */
  public function build_search_query( $messages, $settings = [], $originalQuery = null ) {
    // Get method from settings, default to simple for new installs
    $method = $settings['search_method'] ?? self::METHOD_SIMPLE;

    // Allow 'auto' selection (not implemented in v1, always falls back to simple)
    if ( $method === 'auto' ) {
      $method = self::METHOD_SIMPLE;
    }

    switch ( $method ) {
      case self::METHOD_SIMPLE:
        return $this->build_last_message_query( $messages, $originalQuery );

      case self::METHOD_CONTEXT_AWARE:
        return $this->build_user_messages_query( $messages, $settings, $originalQuery );

      case self::METHOD_SMART_SEARCH:
        return $this->build_ai_optimized_query( $messages, $settings, $originalQuery );

      default:
        return $this->build_last_message_query( $messages, $originalQuery );
    }
  }

  /**
   * Method 1: Use only the last user message (current default behavior)
   */
  private function build_last_message_query( $messages, $originalQuery = null ) {
    // First, try to get the current message from the query
    if ( $originalQuery && method_exists( $originalQuery, 'get_message' ) ) {
      $currentMessage = $originalQuery->get_message();
      if ( !empty( $currentMessage ) ) {
        return [
          'query' => $currentMessage,
          'method' => self::METHOD_SIMPLE,
          'tokens_estimate' => strlen( $currentMessage ) / 4
        ];
      }
    }

    // Fallback to finding the last user message in the messages array
    if ( empty( $messages ) ) {
      return [
        'query' => '',
        'method' => self::METHOD_SIMPLE,
        'tokens_estimate' => 0
      ];
    }

    // Find the last user message
    $lastUserMessage = null;
    $reversedMessages = array_reverse( $messages );
    foreach ( $reversedMessages as $message ) {
      if ( is_array( $message ) && ( $message['role'] ?? '' ) === 'user' ) {
        $lastUserMessage = $message;
        break;
      }
    }

    // If no user message found, return empty
    if ( !$lastUserMessage ) {
      return [
        'query' => '',
        'method' => self::METHOD_SIMPLE,
        'tokens_estimate' => 0
      ];
    }

    $content = $lastUserMessage['content'] ?? '';

    return [
      'query' => $content,
      'method' => self::METHOD_SIMPLE,
      'tokens_estimate' => strlen( $content ) / 4
    ];
  }

  /**
   * Method 2: Concatenate recent user messages
   */
  private function build_user_messages_query( $messages, $settings, $originalQuery = null ) {
    $messageCount = $settings['context_messages'] ?? 10;
    $userMessages = [];

    // First, add the current message if available
    if ( $originalQuery && method_exists( $originalQuery, 'get_message' ) ) {
      $currentMessage = $originalQuery->get_message();
      if ( !empty( $currentMessage ) ) {
        $userMessages[] = $currentMessage;
      }
    }

    // Extract previous user messages in reverse order
    $reversedMessages = array_reverse( $messages );
    foreach ( $reversedMessages as $message ) {
      if ( is_array( $message ) && ( $message['role'] ?? '' ) === 'user' ) {
        $userMessages[] = $message['content'] ?? '';
        if ( count( $userMessages ) >= $messageCount ) {
          break;
        }
      }
    }

    if ( empty( $userMessages ) ) {
      // Fallback to last message if no user messages found
      return $this->build_last_message_query( $messages, $originalQuery );
    }

    // Reverse to maintain chronological order (but keep current message last)
    $currentMsg = array_shift( $userMessages );
    $userMessages = array_reverse( $userMessages );
    if ( !empty( $currentMsg ) ) {
      $userMessages[] = $currentMsg;
    }

    // Join messages with space
    $query = implode( ' ', $userMessages );

    return [
      'query' => $query,
      'method' => self::METHOD_CONTEXT_AWARE,
      'message_count' => count( $userMessages ),
      'tokens_estimate' => strlen( $query ) / 4
    ];
  }

  /**
   * Method 3: AI-optimized query using a fast model
   */
  private function build_ai_optimized_query( $messages, $settings, $originalQuery = null ) {
    // Get the current user message from the query
    $currentUserMessage = '';
    if ( $originalQuery && method_exists( $originalQuery, 'get_message' ) ) {
      $currentUserMessage = $originalQuery->get_message();
    }
    
    if ( empty( $currentUserMessage ) ) {
      // Fallback to user messages method if no current message
      return $this->build_user_messages_query( $messages, $settings );
    }

    // Extract conversation context (previous messages)
    $messageLimit = $settings['context_messages'] ?? 10;
    $contextMessages = [];
    $conversationContext = [];

    // Build conversation context from previous messages
    $reversedMessages = array_reverse( $messages );
    foreach ( $reversedMessages as $message ) {
      if ( !is_array( $message ) ) {
        continue;
      }

      $role = $message['role'] ?? '';
      $content = $message['content'] ?? '';

      if ( $role === 'user' && count( $contextMessages ) < $messageLimit ) {
        $contextMessages[] = $content;
        $conversationContext[] = 'User: ' . $content;
      }
      elseif ( $role === 'assistant' && count( $conversationContext ) < $messageLimit * 2 ) {
        // Include brief assistant context
        $briefContent = substr( $content, 0, 150 );
        if ( strlen( $content ) > 150 ) {
          $briefContent .= '...';
        }
        $conversationContext[] = 'Assistant: ' . $briefContent;
      }
    }

    // Get include instructions setting
    $includeInstructions = $settings['include_instructions'] ?? false;

    // Build prompt sections with filters
    $conversationSection = $this->get_conversation_context_section( $conversationContext, $includeInstructions, $originalQuery );
    $taskSection = $this->get_task_section( $currentUserMessage );
    $rulesSection = $this->get_rules_section();
    $examplesSection = $this->get_examples_section();
    $outputSection = $this->get_output_section();
    
    // Combine all sections
    $prompt = $conversationSection . $taskSection . $rulesSection . $examplesSection . $outputSection;

    try {
      // Prepare parameters
      $params = [
        'max_tokens' => 200,
        'temperature' => 0.3
      ];
      
      // Copy scope from original query if available
      if ( $originalQuery && !empty( $originalQuery->scope ) ) {
        $params['scope'] = $originalQuery->scope;
      }
      
      // Use the global mwai instance to run the fast query
      global $mwai;
      $optimizedQuery = $mwai->simpleFastTextQuery( $prompt, $params );
      $optimizedQuery = trim( $optimizedQuery );

      // Debug logging
      Meow_MWAI_Logging::log( '🔍 Embeddings Smart Search' );
      Meow_MWAI_Logging::log( 'Prompt:' );
      Meow_MWAI_Logging::log( $prompt );
      Meow_MWAI_Logging::log( 'Optimized Query: ' . $optimizedQuery );

      // Validate the result
      if ( empty( $optimizedQuery ) || strlen( $optimizedQuery ) > 500 ) {
        throw new Exception( 'Invalid optimization result' );
      }

      return [
        'query' => $optimizedQuery,
        'method' => self::METHOD_SMART_SEARCH,
        'original_messages' => count( $contextMessages ),
        'tokens_estimate' => strlen( $optimizedQuery ) / 4
      ];
    }
    catch ( Exception $e ) {
      // Log error and fallback
      error_log( 'AI Engine - Embeddings optimization failed: ' . $e->getMessage() );

      // Fallback to user messages method
      return $this->build_user_messages_query( $messages, $settings );
    }
  }

  /**
   * Get conversation context section
   */
  private function get_conversation_context_section( $conversationContext, $includeInstructions, $originalQuery ) {
    $section = apply_filters( 'mwai_embeddings_conversation_context_section', null, $conversationContext, $includeInstructions, $originalQuery );
    
    if ( $section !== null ) {
      return $section;
    }
    
    $section = "";
    
    // Add chatbot instructions if enabled and available
    if ( $includeInstructions && $originalQuery && !empty( $originalQuery->instructions ) ) {
      $section .= "CHATBOT INSTRUCTIONS:\n" . $originalQuery->instructions . "\n\n";
    }
    
    // Add conversation context if available
    if ( !empty( $conversationContext ) ) {
      $section .= "CONVERSATION CONTEXT:\n";
      $section .= implode( "\n", array_reverse( array_slice( $conversationContext, 0, 5 ) ) ) . "\n\n";
    }
    
    return $section;
  }

  /**
   * Get task section
   */
  private function get_task_section( $currentUserMessage ) {
    $section = apply_filters( 'mwai_embeddings_task_section', null, $currentUserMessage );
    
    if ( $section !== null ) {
      return $section;
    }
    
    $section = "USER'S FOLLOW-UP QUESTION:\n" . $currentUserMessage . "\n\n";
    $section .= "YOUR TASK:\n";
    $section .= "Rephrase the follow-up question as a standalone, clear, and specific natural-language query suitable for vector search. ";
    $section .= "The output must be understandable without prior chat history.\n\n";
    
    return $section;
  }

  /**
   * Get rules section
   */
  private function get_rules_section() {
    $section = apply_filters( 'mwai_embeddings_rules_section', null );
    
    if ( $section !== null ) {
      return $section;
    }
    
    $section = "CRITICAL RULES:\n";
    $section .= "- 5–50 words\n";
    $section .= "- Use natural, full sentences\n";
    $section .= "- No markdown, ALL CAPS, or excessive punctuation\n";
    $section .= "- Include relevant context (names, locations, relationships, etc.)\n";
    $section .= "- Don't reduce to keywords\n";
    $section .= "- Keep stop words (\"is\", \"the\", \"and\", etc.)\n";
    $section .= "- DO NOT use exclusions like \"besides\", \"other than\", etc.\n";
    $section .= "- Transform exclusions into general category searches if needed\n\n";
    
    return $section;
  }

  /**
   * Get examples section
   */
  private function get_examples_section() {
    $section = apply_filters( 'mwai_embeddings_examples_section', null );
    
    if ( $section !== null ) {
      return $section;
    }
    
    $section = "EXAMPLES:\n";
    $section .= "✘ \"Who has pets besides Jordy?\"\n";
    $section .= "→ ✔ \"Who are the people that have pets?\"\n\n";
    $section .= "✘ \"Other activities than hiking?\"\n";
    $section .= "→ ✔ \"What activities can people do in the area?\"\n\n";
    $section .= "✘ \"Does anyone else have cats in Nakai besides Stéphanie?\"\n";
    $section .= "→ ✔ \"Who has cats in Nakai?\"\n\n";
    
    return $section;
  }

  /**
   * Get output section
   */
  private function get_output_section() {
    $section = apply_filters( 'mwai_embeddings_output_section', null );
    
    if ( $section !== null ) {
      return $section;
    }
    
    $section = "OUTPUT:\n";
    $section .= "Only return the optimized search query, nothing else.\n\n";
    $section .= "INPUT QUERY TO REWRITE:\n";
    
    return $section;
  }
}
