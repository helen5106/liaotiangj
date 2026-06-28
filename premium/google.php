<?php

class MeowPro_MWAI_Google extends Meow_MWAI_Engines_Google {
  private $streamFunctionCall = null;
  private $streamFunctionCalls = [];
  private $streamFunctionCallParts = []; // Store full parts including thought_signature
  private $streamRunId = 0;

  public function __construct( $core, $env ) {
    parent::__construct( $core, $env );
  }

  /**
   * Prepare query by uploading files to Gemini Files API.
   *
   * This method is called BEFORE build_body().
   * It uploads PDF files to Gemini's Files API and converts them from 'refId' type
   * to 'provider_file_id' type, which build_messages() will then use to construct the API request.
   *
   * Flow:
   * 1. prepare_query() uploads files to Gemini Files API → gets file URI
   * 2. Replaces DroppedFile from type 'refId' to type 'provider_file_id'
   * 3. build_messages() reads provider_file_id and includes it in message content as file_data
   *
   * @param Meow_MWAI_Query_Base $query The query with potential file attachments
   */
  protected function prepare_query( $query ) {
    // =====================================================================
    // MULTI-FILE UPLOAD: Process attachedFiles array
    // =====================================================================
    if ( !empty( $query->attachedFiles ) ) {
      foreach ( $query->attachedFiles as $index => $file ) {
        $mimeType = $file->get_mimeType() ?? '';
        $isPDF = strpos( $mimeType, 'application/pdf' ) === 0;

        // Skip files already uploaded (type = provider_file_id)
        if ( $file->get_type() === 'provider_file_id' ) {
          continue;
        }

        // Only PDFs need to be uploaded to Gemini Files API
        // Images are handled differently (base64 or URL in build_messages)
        if ( $isPDF ) {
          try {
            // Get file data from WordPress uploads directory
            $refId = $file->get_refId();
            $data = $this->core->files->get_data( $refId );
            $filename = $file->get_filename();

            // Upload to Gemini Files API
            $uploadedFile = $this->upload_file( $filename, $data, $mimeType );
            $fileUri = $uploadedFile['file']['uri'] ?? null;

            if ( $fileUri ) {
              // CRITICAL: Replace the file object with provider_file_id type
              // Store the full file URI as returned by Gemini Files API
              $query->attachedFiles[$index] = Meow_MWAI_Query_DroppedFile::from_provider_file_id(
                $fileUri,
                $file->get_purpose(),
                $mimeType
              );
            }
          }
          catch ( Exception $e ) {
            error_log( '[AI Engine] Failed to upload PDF to Gemini Files API: ' . $e->getMessage() );
            // Keep the original file - build_messages() will fall back to inline data
          }
        }
      }
    }
  }

  /**
   * Override build_body to use Gemini-specific function serialization.
   *
   * Gemini requires properties to be objects (stdClass), not arrays.
   * This is only implemented in Pro version.
   */
  protected function build_body( $query, $streamCallback = null ) {
    $body = [];

    // Gemini 3 models don't support multiple candidates
    $candidateCount = $query->maxResults;
    if ( preg_match( '/gemini-3/', $query->model ) && $candidateCount > 1 ) {
      $candidateCount = 1;
    }

    // Build generation config
    $body['generationConfig'] = [
      'candidateCount' => $candidateCount,
      'maxOutputTokens' => $query->maxTokens,
      'temperature' => $query->temperature,
      'stopSequences' => []
    ];

    // Add tools if available
    $hasTools = false;

    // Check for functions (use Gemini-specific serialization)
    if ( !empty( $query->functions ) ) {
      if ( !isset( $body['tools'] ) ) {
        $body['tools'] = [];
      }
      $body['tools'][] = [ 'function_declarations' => [] ];
      foreach ( $query->functions as $function ) {
        $body['tools'][0]['function_declarations'][] = $function->serializeForGemini();
      }
      $body['tool_config'] = [
        'function_calling_config' => [ 'mode' => 'AUTO' ]
      ];
      $hasTools = true;
    }

    // Check for web_search tool
    if ( !empty( $query->tools ) && in_array( 'web_search', $query->tools ) ) {
      if ( !isset( $body['tools'] ) ) {
        $body['tools'] = [];
      }
      $body['tools'][] = [ 'google_search' => (object) [] ];
      $hasTools = true;
    }

    // Check for thinking tool (Gemini 2.5+ models)
    if ( !empty( $query->tools ) && in_array( 'thinking', $query->tools ) ) {
      if ( !isset( $body['generationConfig']['thinkingConfig'] ) ) {
        $body['generationConfig']['thinkingConfig'] = [];
      }
      // Use dynamic thinking by default (-1 lets the model decide)
      $body['generationConfig']['thinkingConfig']['thinkingBudget'] = -1;

      // Always include thought summaries when thinking is enabled
      // This allows us to see thinking events in the UI
      $body['generationConfig']['thinkingConfig']['includeThoughts'] = true;

      // Log that thinking is enabled
      if ( $this->core->get_option( 'queries_debug_mode' ) ) {
        error_log( '[AI Engine] Thinking tool enabled for Gemini with dynamic budget' );
      }
    }

    // Build messages
    $body['contents'] = $this->build_messages( $query );

    return $body;
  }

  private function reset_stream() {
    $this->streamContent = '';
    $this->streamBuffer = '';
    $this->streamFunctionCall = null;
    $this->streamFunctionCalls = [];
    $this->streamFunctionCallParts = [];
    if ( !empty( $this->streamImages ) ) {
      foreach ( $this->streamImages as $image ) {
        if ( isset( $image['temp_file'] ) && file_exists( $image['temp_file'] ) ) {
          unlink( $image['temp_file'] );
        }
      }
    }
    $this->streamImages = [];
    $this->streamRunId++;
  }

  /**
   * Strip SSE (Server-Sent Events) prefixes from image data chunks.
   *
   * When streaming with ?alt=sse, Google wraps each chunk with "data:" prefix
   * and newline separators. This corrupts base64 data if not removed.
   * Example input: "data:{\"candidates\":[...]}\ndata:{more json}\n"
   * Example output: "{\"candidates\":[...]}{more json}"
   *
   * @param string $chunk Raw chunk from SSE stream
   * @return string Clean chunk without SSE formatting
   */
  private function strip_sse_prefixes_for_image( $chunk ) {
    if ( $chunk === '' ) {
      return '';
    }

    $lines = preg_split( "/\r?\n/", $chunk );
    $clean = '';

    foreach ( $lines as $line ) {
      if ( $line === '' ) {
        continue;
      }

      if ( strpos( $line, 'data:' ) === 0 ) {
        $line = ltrim( substr( $line, 5 ) );
      }

      $clean .= $line;
    }

    return $clean;
  }

  /**
   * Locate where base64 data starts in a buffer.
   *
   * Google's inlineData structure can vary slightly in formatting.
   * This method checks multiple patterns to reliably find the start
   * of the base64 string, avoiding JSON parsing of large data.
   *
   * Patterns cover variations like:
   * - {"inlineData":{"data":"base64here"}}
   * - {"data": "base64here"}
   * - {"bytes":"base64here"}
   *
   * @param string $buffer Buffer containing JSON with base64
   * @return int|false Position after opening quote of base64 data, or false if not found
   */
  private function locate_base64_start_in_buffer( $buffer ) {
    if ( $buffer === '' ) {
      return false;
    }

    $patterns = [
      '"data":"',
      '"data": "',
      '"data" :"',
      '"data" : "',
      '"bytes":"',
      '"inlineData":{"data":"'
    ];

    foreach ( $patterns as $pattern ) {
      $pos = strpos( $buffer, $pattern );
      if ( $pos !== false ) {
        return $pos + strlen( $pattern );
      }
    }

    return false;
  }

  /**
   * Extract MIME type from a chunk containing inlineData.
   *
   * Looks for the mimeType field in the JSON structure without
   * parsing the entire JSON (which would include the large base64).
   * Example: {"inlineData":{"mimeType":"image/png","data":"..."}}
   *
   * @param string $chunk Chunk that may contain mimeType field
   * @return string|null MIME type or null if not found
   */
  private function detect_inline_mime_type_from_chunk( $chunk ) {
    if ( $chunk === '' ) {
      return null;
    }

    if ( preg_match( '/"mimeType"\s*:\s*"([^"]+)"/', $chunk, $matches ) ) {
      return $matches[1];
    }

    return null;
  }

  /**
   * Handle streaming responses from Google Gemini API with optimized image handling.
   *
   * PERFORMANCE CRITICAL - LESSONS LEARNED:
   *
   * THE PROBLEM:
   * Google Gemini Flash Image models return generated images as base64 strings
   * embedded in JSON ("inlineData" field). These can be 2.5MB+ causing:
   * - 2+ minutes to stream (vs 6 seconds for the actual generation)
   * - 100% CPU usage from character-by-character JSON parsing
   * - Memory exhaustion from exponentially growing buffers
   * - Browser timeouts and poor user experience
   *
   * THE SOLUTION:
   * 1. Use SSE format (?alt=sse) for proper chunk framing
   * 2. Detect "inlineData" and switch to raw streaming mode
   * 3. Strip SSE prefixes ("data:") that corrupt base64
   * 4. Stream base64 directly to temp file without JSON parsing
   * 5. Find base64 boundaries using pattern matching on buffer
   * 6. Read complete image from temp file after streaming
   *
   * KEY INSIGHTS:
   * - Never parse large base64 as JSON - it's exponentially slow
   * - SSE format requires prefix stripping for clean data
   * - Temp files avoid memory issues with large images
   * - Buffer-based pattern matching is fast and reliable
   *
   * @param resource $handle CURL handle
   * @param array $args Arguments
   * @param string $url API endpoint URL with ?alt=sse for SSE format
   */
  public function stream_handler( $handle, $args, $url ) {
    curl_setopt( $handle, CURLOPT_SSL_VERIFYPEER, false );
    curl_setopt( $handle, CURLOPT_SSL_VERIFYHOST, false );

    curl_setopt( $handle, CURLOPT_WRITEFUNCTION, function ( $curl, $data ) {

      $length = strlen( $data );

      // Static variables persist across callback invocations
      // This is crucial for maintaining state during streaming
      static $chunkCount = 0;          // Track chunks for debugging
      static $lastLogTime = 0;          // Throttle debug logging
      static $imageMode = false;        // Whether we're streaming image data
      static $imageFile = null;         // Path to temp file for image
      static $imageFileHandle = null;   // File handle for writing base64
      static $imageBuffer = '';         // Buffer for finding base64 boundaries
      static $base64Started = false;    // Whether we've found base64 start
      static $imageMimeType = 'image/png'; // Detected MIME type
      static $imageIndex = null;        // Index in streamImages array
      static $lastRunId = null;         // Detect new streaming sessions
      $currentTime = microtime( true );

      // Reset static variables when starting a new stream
      // This prevents data leakage between different streaming sessions
      if ( $lastRunId !== $this->streamRunId ) {
        if ( is_resource( $imageFileHandle ) ) {
          fclose( $imageFileHandle );
        }
        if ( $imageFile && file_exists( $imageFile ) ) {
          unlink( $imageFile );
        }

        $chunkCount = 0;
        $lastLogTime = 0;
        $imageMode = false;
        $imageFile = null;
        $imageFileHandle = null;
        $imageBuffer = '';
        $base64Started = false;
        $imageMimeType = 'image/png';
        $imageIndex = null;
        $lastRunId = $this->streamRunId;
      }

      if ( strpos( $this->streamBuffer, 'flash-image' ) !== false || strpos( $this->streamBuffer, 'image-preview' ) !== false ) {
        $chunkCount++;
        // Log every 10 chunks or every 5 seconds
        if ( $chunkCount % 10 === 0 || ( $currentTime - $lastLogTime ) > 5 ) {
          $lastLogTime = $currentTime;
        }
      }

      // PERFORMANCE OPTIMIZATION: Detect image data and switch to raw streaming
      // This is the key to avoiding the 2+ minute delays with large images
      if ( !$imageMode && strpos( $data, '"inlineData"' ) !== false ) {
        $imageMode = true;
        $imageBuffer = '';
        $base64Started = false;
        $imageMimeType = $this->detect_inline_mime_type_from_chunk( $data ) ?? 'image/png';

        $imageFile = tempnam( sys_get_temp_dir(), 'mwai_image_' );
        $imageFileHandle = fopen( $imageFile, 'w' );

        if ( !$imageFileHandle ) {
          if ( $imageFile && file_exists( $imageFile ) ) {
            unlink( $imageFile );
          }
          $imageFile = null;
          $imageMode = false;
          $imageBuffer = '';
          $base64Started = false;
          $imageMimeType = 'image/png';
          $imageIndex = null;
          return $length;
        }

        if ( $this->streamCallback && $this->currentDebugMode ) {
          $event = new Meow_MWAI_Event( 'live', MWAI_STREAM_TYPES['IMAGE_GEN'] );
          $event->set_content( 'Generating image...' );
          call_user_func( $this->streamCallback, $event );
        }

        $this->streamImages[] = [
          'temp_file' => $imageFile,
          'mimeType' => $imageMimeType
        ];
        $imageIndex = count( $this->streamImages ) - 1;
      }

      // RAW STREAMING MODE: Write base64 directly to disk without JSON parsing
      // This is 20-30x faster than parsing JSON with large base64 strings
      if ( $imageMode ) {
        if ( !$imageFileHandle ) {
          if ( $imageIndex !== null && isset( $this->streamImages[ $imageIndex ] ) ) {
            unset( $this->streamImages[ $imageIndex ] );
          }
          if ( $imageFile && file_exists( $imageFile ) ) {
            unlink( $imageFile );
          }
          $imageFile = null;
          $imageIndex = null;
          $imageMode = false;
          $imageBuffer = '';
          $base64Started = false;
          $imageMimeType = 'image/png';
          return $length;
        }

        // CRITICAL: Remove SSE prefixes that would corrupt the base64 data
        // Without this, the base64 contains "data:" markers making it invalid
        $cleanChunk = $this->strip_sse_prefixes_for_image( $data );

        if ( $cleanChunk === '' ) {
          return $length;
        }

        // Buffer cleaned data to find base64 boundaries
        $imageBuffer .= $cleanChunk;

        $detectedMime = $this->detect_inline_mime_type_from_chunk( $imageBuffer );
        if ( $detectedMime && $detectedMime !== $imageMimeType ) {
          $imageMimeType = $detectedMime;
          if ( $imageIndex !== null && isset( $this->streamImages[ $imageIndex ] ) ) {
            $this->streamImages[ $imageIndex ]['mimeType'] = $imageMimeType;
          }
        }

        // Find where the actual base64 data starts (after JSON fields)
        if ( !$base64Started ) {
          $base64StartPos = $this->locate_base64_start_in_buffer( $imageBuffer );

          if ( $base64StartPos === false ) {
            // Keep only last 2KB to avoid memory growth while searching
            if ( strlen( $imageBuffer ) > 2048 ) {
              $imageBuffer = substr( $imageBuffer, -2048 );
            }

            return $length;
          }

          // Found base64 start - trim buffer to actual data
          $base64Started = true;
          $imageBuffer = substr( $imageBuffer, $base64StartPos );
        }

        // Stream base64 to file until we find the closing quote
        while ( $base64Started ) {
          $closingQuotePos = strpos( $imageBuffer, '"' );

          if ( $closingQuotePos === false ) {
            // No closing quote yet - write current buffer and wait for more
            if ( $imageBuffer !== '' ) {
              fwrite( $imageFileHandle, $imageBuffer );
              $imageBuffer = '';
            }

            return $length;
          }

          $base64Chunk = substr( $imageBuffer, 0, $closingQuotePos );

          if ( $base64Chunk !== '' ) {
            fwrite( $imageFileHandle, $base64Chunk );
          }

          $currentFile = $imageFile;
          fclose( $imageFileHandle );
          $imageFileHandle = null;
          $imageMode = false;
          $base64Started = false;
          $imageBuffer = '';
          $imageIndex = null;
          $imageMimeType = 'image/png';

          $fileSize = ( $currentFile && file_exists( $currentFile ) ) ? filesize( $currentFile ) : 0;

          if ( $this->streamCallback && $this->currentDebugMode ) {
            $event = new Meow_MWAI_Event( 'live', MWAI_STREAM_TYPES['IMAGE_GEN'] );
            $event->set_content( 'Image generated' );
            call_user_func( $this->streamCallback, $event );
          }

          $imageFile = null;
        }

        return $length;
      }

      // Parse SSE format if present
      if ( strpos( $data, 'data: ' ) !== false ) {
        // This is Server-Sent Events format
        // Each event starts with "data: " followed by JSON
        $lines = explode( "\n", $data );
        $cleanData = '';

        foreach ( $lines as $line ) {
          if ( strpos( $line, 'data: ' ) === 0 ) {
            // Extract JSON after "data: "
            $jsonPart = substr( $line, 6 );
            $cleanData .= $jsonPart;
          }
          else if ( strpos( $line, 'data:' ) === 0 ) {
            // Sometimes no space after colon
            $jsonPart = substr( $line, 5 );
            $cleanData .= $jsonPart;
          }
          else if ( trim( $line ) !== '' && $line !== '[DONE]' ) {
            // Continue previous data
            $cleanData .= $line;
          }
        }

        $data = $cleanData;
      }

      // REMOVED CODE: Initial "skip mode" approach failed because:
      // 1. Didn't handle SSE format properly (data: prefixes)
      // 2. Complex regex patterns couldn't reliably find boundaries
      // 3. Mixed concerns between SSE parsing and base64 extraction
      // The current solution cleanly separates these concerns.
      /* DISABLED - Skip mode doesn't work with SSE format
      if ( false ) {
          // Still collecting the header to find where base64 starts
          $imageHeader .= $data;

          // Debug: Log first part of header to see structure
          if ( strlen($imageHeader) > 100 && strlen($imageHeader) < 500 ) {
          }

          // Look for the start of base64 data - try multiple patterns
          // Google might use different field names or formats
          $patterns = [
            '"data":"',         // Standard format
            '"data": "',        // With space
            '"image":"',        // Alternative field name
            '"bytes":"',        // Another possible field name
            '"mimeType":"image' // Look for mimeType followed by data
          ];

          $dataPos = false;
          $usedPattern = null;
          foreach ($patterns as $pattern) {
            $pos = strpos( $imageHeader, $pattern );
            if ( $pos !== false ) {
              $dataPos = $pos;
              $usedPattern = $pattern;
              break;
            }
          }

          // If we found mimeType, look for the actual data field after it
          if ( $usedPattern === '"mimeType":"image' ) {
            // Search for data field after mimeType
            $searchFrom = $dataPos + strlen($usedPattern);
            $dataFieldPos = strpos( $imageHeader, '"data"', $searchFrom );
            if ( $dataFieldPos !== false ) {
              // Now find the actual start of the base64 data (after the colon and quote)
              $colonPos = strpos( $imageHeader, ':', $dataFieldPos );
              if ( $colonPos !== false ) {
                // Skip any whitespace and find the opening quote
                $quotePos = strpos( $imageHeader, '"', $colonPos );
                if ( $quotePos !== false ) {
                  $dataPos = $quotePos;
                  $usedPattern = '"'; // Adjust pattern since we're at the quote
                }
              }
            }
          }

          if ( $dataPos === false ) {
            // No pattern found yet, log once when buffer is large enough
            if ( strlen($imageHeader) > 1000 && !$patternWarningLogged ) {
              $patternWarningLogged = true;
            }
          }

          if ( $dataPos !== false ) {
            $collectingImageHeader = false;

            // Position right after the pattern
            $base64Start = $dataPos + strlen($usedPattern);

            // Open file for writing
            $imageFileHandle = fopen( $imageTempFile, 'w' );

            if (!$imageFileHandle) {
              return $length;
            }

            // Extract the base64 part from the header
            // We start from the position after the pattern
            $remainingData = substr( $imageHeader, $base64Start );

            // Debug: Check what we're about to write
            $firstChars = substr($remainingData, 0, 50);

            // Write the initial base64 data to the temp file
            // Don't close the file yet - we'll continue writing in subsequent chunks
            $bytesWritten = fwrite( $imageFileHandle, $remainingData );

            // Mark that we're now inside the base64 data
            $insideBase64 = true;

            // IMPORTANT: Keep the file handle open for subsequent chunks
            // The static variable will preserve it across calls
          } else {
            // Pattern not found yet
            if ( strlen($imageHeader) > 5000 ) {
            }
          }
          return $length;
        }

        // In skip mode, we're collecting the raw base64 data
        // We need to look for the end of the inline data object, not just a quote

        // Debug: Log the state of the file handle
        if (!$imageFileHandle) {
        }

        // Only continue if we have an open file handle
        if ( !$imageFileHandle ) {
          // File was already closed, skip this data
          return $length;
        }

        // Look for the closing quote of the base64 data
        // The base64 string ends with a quote, followed by JSON structure
        $quotePos = strpos( $data, '"' );
        if ( $quotePos !== false ) {
          // Found a quote - this should be the end of the base64 data

          // Write only the base64 data before the quote
          if ($quotePos > 0) {
            $finalData = substr( $data, 0, $quotePos );
            $finalBytes = fwrite( $imageFileHandle, $finalData );
          }

          // Close the file
          fclose( $imageFileHandle );
          $imageFileHandle = null;

          // Get final file size
          $finalSize = filesize( $imageTempFile );

          // Verify this is really the end by checking what comes after
          $afterQuote = substr($data, $quotePos, 50);

          $skipImageData = false;
          $insideBase64 = false;
        } else {
          // Still in the middle of base64 data
          if ( $imageFileHandle ) {
            // The raw HTTP stream includes JSON separators between objects
            // We need to write ONLY the base64 data, not any JSON markers

            // Check for common streaming separators and truncate before them
            $separators = [
              ',{"candidates"',  // New JSON object
              ',\n{"candidates"', // New JSON object with newline
              '\n{"candidates"',  // New JSON object with just newline
              ']},',             // End of object, start of next
              ']}]',             // End of array
            ];

            $truncateAt = strlen($data);
            foreach ($separators as $sep) {
              $pos = strpos($data, $sep);
              if ($pos !== false && $pos < $truncateAt) {
                $truncateAt = $pos;
              }
            }

            if ($truncateAt < strlen($data)) {
              $data = substr($data, 0, $truncateAt);
            }

            $bytesWritten = fwrite( $imageFileHandle, $data );
            // Log periodically to track progress
            static $totalBytesWritten = 0;
            if (!$imageDetected) {
              $totalBytesWritten = 0; // Reset on first detection
            }
            $totalBytesWritten += $bytesWritten;
            if ($totalBytesWritten % 100000 == 0) {
            }
          }
        }

        return $length;
      }
      END OF DISABLED SKIP MODE */

      // TEXT STREAMING: Process normally through JSON parsing
      // This path handles regular text responses, function calls, etc.
      // Only small JSON objects go through here - images are handled above

      // Check if this looks like SSE format
      if ( strpos( $data, 'data: ' ) !== false || strpos( $this->streamTemporaryBuffer, 'data: ' ) !== false ) {
        // Parse SSE format
        $this->streamTemporaryBuffer .= $data;

        // Split by "data: " to get individual events
        $parts = explode( "\ndata: ", $this->streamTemporaryBuffer );

        // Keep the incomplete part for next iteration
        $this->streamTemporaryBuffer = array_pop( $parts );

        // Process complete events
        foreach ( $parts as $part ) {
          // Remove "data: " prefix if present at the start
          if ( strpos( $part, 'data: ' ) === 0 ) {
            $part = substr( $part, 6 );
          }

          // Remove trailing newlines
          $part = trim( $part );

          // Skip empty events
          if ( empty( $part ) ) {
            continue;
          }

          // Add to buffer for processing
          $this->streamBuffer .= $part;
        }
      }
      else {
        // Not SSE format, use as-is
        $this->streamTemporaryBuffer .= $data;
        $this->streamBuffer .= $data;
      }

      $this->stream_error_check( $this->streamBuffer );

      // Parse complete JSON objects from the buffer
      // This character-by-character parsing is fine for small text responses
      // but would be catastrophic for 2.5MB base64 strings (hence image mode)
      static $lastProcessedPos = 0;
      $buf = $this->streamTemporaryBuffer;
      $bufLen = strlen( $buf );
      $objects = [];

      // Skip if buffer has placeholder from image streaming
      if ( strpos( $buf, 'SKIPPED_DURING_STREAMING' ) !== false ) {
        return $length;
      }

      {
        // For text streaming: Use character-by-character JSON parsing
        // This works well for small JSON objects (text responses)
        $pos = 0;
        $depth = 0;
        $inStr = false;
        $escape = false;
        $start = null;

        // PERFORMANCE: Cache buffer length to avoid repeated strlen() calls
        while ( $pos < $bufLen ) {
          $ch = $buf[$pos];

          // Handle string state
          if ( $inStr ) {
            if ( $escape ) {
              $escape = false;
            }
            elseif ( $ch === '\\' ) {
              $escape = true;
            }
            elseif ( $ch === '"' ) {
              $inStr = false;
            }
            $pos++;
            continue;
          }

          if ( $ch === '"' ) {
            $inStr = true;
            $pos++;
            continue;
          }

          // Handle brace counting for object detection
          if ( $ch === '{' ) {
            if ( $depth === 0 ) {
              $start = $pos;
            }
            $depth++;
          }
          elseif ( $ch === '}' ) {
            $depth--;
            if ( $depth === 0 && $start !== null ) {
              $jsonChunk = substr( $buf, $start, $pos - $start + 1 );

              $json = json_decode( $jsonChunk, true );

              if ( json_last_error() === JSON_ERROR_NONE ) {
                $objects[] = $json;

                // Skip trailing spaces / commas / newlines
                $commaPos = $pos + 1;
                while ( $commaPos < $bufLen && in_array( $buf[ $commaPos ], [ ' ', "\n", "\r", ',' ] ) ) {
                  $commaPos++;
                }
                // Trim processed part from buffer and restart scanning
                $buf = substr( $buf, $commaPos );
                $bufLen = strlen( $buf );
                $pos = -1;      // will be ++ to 0 at loop bottom
                $start = null;
              }
              else {
                // JSON still incomplete – wait for more bytes
                break;
              }
            }
          }
          $pos++;
        }

        // Keep the unprocessed tail for next callback
        $this->streamTemporaryBuffer = $buf;
      }

      // --------------- Forward each decoded object --------------
      foreach ( $objects as $objIdx => $obj ) {
        // Handle all parts, not just the first one
        if ( isset( $obj['candidates'][0]['content']['parts'] ) ) {
          $parts = $obj['candidates'][0]['content']['parts'];

          foreach ( $parts as $partIdx => $part ) {
            $delta = [ 'role' => 'assistant' ];

            if ( isset( $part['functionCall'] ) ) {
              $delta['function_call'] = $part['functionCall'];
              // Store the full part including thought_signature for Gemini 3 models
              $this->streamFunctionCallParts[] = $part;
            }
            if ( isset( $part['text'] ) ) {
              // Check if this is a thought part (for thinking mode)
              if ( isset( $part['thought'] ) && $part['thought'] === true ) {
                // Emit thinking event
                if ( $this->streamCallback ) {
                  $event = new Meow_MWAI_Event( 'live', MWAI_STREAM_TYPES['THINKING'] );
                  $event->set_content( $part['text'] );
                  call_user_func( $this->streamCallback, $event );
                }
                // Don't add thoughts to the main content
                continue;
              }
              else {
                $delta['content'] = $part['text'];
              }
            }
            // Handle inline images properly by extracting the base64
            if ( isset( $part['inlineData'] ) ) {
              if ( isset( $part['inlineData']['data'] ) ) {
                // Extract the base64 data
                $base64Data = $part['inlineData']['data'];
                $mimeType = $part['inlineData']['mimeType'] ?? 'image/png';

                // Store the base64 for later use
                $this->streamImages[] = [
                  'b64_json' => $base64Data,
                  'mimeType' => $mimeType
                ];

                // Emit image generation event if in debug mode
                if ( $this->streamCallback && $this->currentDebugMode ) {
                  $event = new Meow_MWAI_Event( 'live', MWAI_STREAM_TYPES['IMAGE_GEN'] );
                  $event->set_content( 'Image generated' );
                  call_user_func( $this->streamCallback, $event );
                }
              }
              continue;
            }

            $mapped = [
              'choices' => [ [ 'delta' => $delta ] ],
            ];

            $content = $this->stream_data_handler( $mapped );
            if ( !is_null( $content ) ) {
              if ( $content === "\n" ) {
                $content = "  \n";
              }
              $this->streamContent .= $content;
              call_user_func( $this->streamCallback, $content );
            }
          }
        }
      }

      return $length;
    } );
  }

  private $streamImages = [];

  protected function stream_data_handler( $json ) {
    if ( !isset( $json['choices'][0]['delta'] ) ) {
      return null;
    }
    $choice = $json['choices'][0];
    $delta = $choice['delta'];

    // Capture a function-call if the model sends one.
    if ( isset( $delta['function_call'] ) ) {
      $this->streamFunctionCall = $delta['function_call'];
      $this->streamFunctionCalls[] = $delta['function_call'];

      // Emit function_calling event
      if ( $this->currentDebugMode && $this->streamCallback ) {
        $functionName = $delta['function_call']['name'] ?? 'unknown';
        $functionArgs = isset( $delta['function_call']['args'] ) ?
          json_encode( $delta['function_call']['args'] ) : '';

        $event = Meow_MWAI_Event::function_calling( $functionName, $functionArgs );
        call_user_func( $this->streamCallback, $event );
      }
    }

    // Note: Images are handled directly in the stream handler, not here

    if ( isset( $delta['content'] ) && $delta['content'] !== '' ) {
      return $delta['content'];
    }
    return null;
  }

  public function try_decode_error( $data ) {
    $json = json_decode( $data, true );
    if ( isset( $json['error']['message'] ) ) {
      return $json['error']['message'];
    }
    return null;
  }

  public function run_completion_query( $query, $streamCallback = null ): Meow_MWAI_Reply {
    $isStreaming = !is_null( $streamCallback );

    // Initialize debug mode
    $this->init_debug_mode( $query );

    // IMPORTANT: Prepare query BEFORE setting up streaming hooks
    // The streaming hook intercepts ALL wp_remote_* calls, so preparation must happen first
    $this->prepare_query( $query );

    if ( $isStreaming ) {
      $this->streamCallback = $streamCallback;
      $this->reset_stream();

    }

    // Build body using the parent's build_body method which handles event emission
    $body = $this->build_body( $query, $streamCallback );

    $base = $this->endpoint . '/models/' . $query->model;
    if ( $isStreaming ) {
      $url = $base . ':streamGenerateContent?alt=sse&key=' . $this->apiKey;
    }
    else {
      $url = $base . ':generateContent?key=' . $this->apiKey;
    }

    if ( $isStreaming ) {
      // Emit "Request sent" event for feedback queries
      if ( $this->currentDebugMode && !empty( $streamCallback ) &&
           ( $query instanceof Meow_MWAI_Query_Feedback || $query instanceof Meow_MWAI_Query_AssistFeedback ) ) {
        $event = Meow_MWAI_Event::request_sent()
          ->set_metadata( 'is_feedback', true )
          ->set_metadata( 'feedback_count', count( $query->blocks ) );
        call_user_func( $streamCallback, $event );
      }

      $ch = curl_init();
      curl_setopt_array( $ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [ 'Content-Type: application/json', 'Accept: text/event-stream' ],
        CURLOPT_POSTFIELDS => json_encode( $body ),
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0
      ] );
      $this->stream_handler( $ch, [], $url );
      curl_exec( $ch );
      $httpCode = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
      curl_close( $ch );

      if ( empty( $this->streamContent ) ) {
        $error = $this->try_decode_error( $this->streamBuffer );
        if ( !is_null( $error ) ) {
          throw new Exception( $error );
        }
      }

      $reply = new Meow_MWAI_Reply( $query );

      // If we have multiple function calls, return them in Google's format
      $returned_choices = [];

      // Add each function call as a separate choice
      if ( !empty( $this->streamFunctionCalls ) ) {
        foreach ( $this->streamFunctionCalls as $function_call ) {
          $returned_choices[] = [
            'message' => [
              'content' => null,
              'function_call' => $function_call
            ]
          ];
        }
      }

      // Add text content if present
      if ( !empty( $this->streamContent ) ) {
        if ( empty( $returned_choices ) ) {
          // No function calls, just text
          $returned_choices[] = [ 'message' => [ 'content' => $this->streamContent ] ];
        }
        else {
          // Add text as a separate choice
          $returned_choices[] = [ 'role' => 'assistant', 'text' => $this->streamContent ];
        }
      }

      // FINAL STEP: Retrieve images from temp files
      // The base64 was streamed directly to disk during the response
      // Now we read it back and add it to the reply
      if ( !empty( $this->streamImages ) ) {
        foreach ( $this->streamImages as $image ) {
          if ( isset( $image['temp_file'] ) ) {
            $tempFile = $image['temp_file'];

            if ( file_exists( $tempFile ) ) {
              $fileSize = filesize( $tempFile );

              if ( $fileSize > 0 ) {
                $base64Data = file_get_contents( $tempFile );

                if ( $base64Data !== false ) {
                  $base64Data = preg_replace( '/\s+/', '', $base64Data );
                  $base64Data = trim( $base64Data );

                  if ( $base64Data !== '' ) {
                    $decoded = base64_decode( $base64Data, true );

                    if ( $decoded !== false ) {

                      $choice = [
                        'b64_json' => $base64Data,
                        'mime_type' => $image['mimeType'] ?? 'image/png'
                      ];

                      $returned_choices[] = $choice;
                    }
                    else {
                    }
                  }
                  else {
                  }
                }
                else {
                }
              }
              else {
              }

              unlink( $tempFile );
            }
            else {
            }
          }
          elseif ( isset( $image['b64_json'] ) ) {
            $returned_choices[] = [ 'b64_json' => $image['b64_json'] ];
          }
        }
      }

      // If we still have no choices, add a single function call if available
      if ( empty( $returned_choices ) && $this->streamFunctionCall ) {
        $returned_choices[] = [ 'message' => [ 'content' => null, 'function_call' => $this->streamFunctionCall ] ];
      }

      // Build Google-format rawMessage from stored parts (includes thought_signature for Gemini 3)
      $googleRawMessage = null;
      if ( !empty( $this->streamFunctionCallParts ) ) {
        $googleRawMessage = [
          'role' => 'model',
          'parts' => $this->streamFunctionCallParts
        ];
      }

      $reply->set_choices( $returned_choices, $googleRawMessage );
      $this->handle_tokens_usage( $reply, $query, $query->model, null, null );
      return $reply;
    }

    // Emit "Request sent" event for feedback queries (non-streaming)
    if ( !$isStreaming && $this->currentDebugMode && !empty( $streamCallback ) &&
         ( $query instanceof Meow_MWAI_Query_Feedback || $query instanceof Meow_MWAI_Query_AssistFeedback ) ) {
      $event = Meow_MWAI_Event::request_sent()
        ->set_metadata( 'is_feedback', true )
        ->set_metadata( 'feedback_count', count( $query->blocks ) );
      call_user_func( $streamCallback, $event );
    }

    $headers = $this->build_headers( $query );
    $options = $this->build_options( $headers, $body );
    $res = $this->run_query( $url, $options );
    $reply = new Meow_MWAI_Reply( $query );
    $data = $res['data'];
    if ( empty( $data ) ) {
      throw new Exception( 'No content received (res is null).' );
    }
    $returned_choices = [];
    if ( isset( $data['candidates'] ) ) {
      foreach ( $data['candidates'] as $candidate ) {
        $content = $candidate['content'];

        // Check if there are any parts with function calls
        $functionCalls = [];
        $textContent = '';

        if ( isset( $content['parts'] ) ) {
          foreach ( $content['parts'] as $part ) {
            if ( isset( $part['functionCall'] ) ) {
              $functionCalls[] = $part['functionCall'];

              // Emit function calling event if debug mode is enabled
              if ( $this->currentDebugMode && !empty( $streamCallback ) ) {
                $functionName = $part['functionCall']['name'] ?? 'unknown';
                $functionArgs = isset( $part['functionCall']['args'] ) ? json_encode( $part['functionCall']['args'] ) : '';

                $event = Meow_MWAI_Event::function_calling( $functionName, $functionArgs );
                call_user_func( $streamCallback, $event );
              }
            }
            elseif ( isset( $part['text'] ) ) {
              $textContent .= $part['text'];
            }
          }
        }

        // If we have function calls, return them in Google's expected format
        if ( !empty( $functionCalls ) ) {
          // Process each function call separately to ensure all are handled
          foreach ( $functionCalls as $function_call ) {
            $returned_choices[] = [
              'message' => [
                'content' => null,
                'function_call' => $function_call
              ]
            ];
          }
        }

        // Add text content if present (separate from function calls)
        if ( !empty( $textContent ) ) {
          $returned_choices[] = [ 'role' => 'assistant', 'text' => $textContent ];
        }
      }
    }
    // Create a proper Google-formatted rawMessage
    $googleRawMessage = null;
    if ( isset( $data['candidates'][0]['content'] ) ) {
      $googleRawMessage = $data['candidates'][0]['content'];
    }

    $reply->set_choices( $returned_choices, $googleRawMessage );
    $this->handle_tokens_usage( $reply, $query, $query->model, null, null );
    return $reply;
  }

  public function run_embedding_query( Meow_MWAI_Query_Base $query ) {
    // For experimental models, we might need to use a different approach
    // For now, let's use the model as specified
    $modelName = $query->model;

    // Build the URL for embeddings
    $url = $this->endpoint . '/models/' . $modelName . ':embedContent';
    if ( strpos( $url, '?' ) === false ) {
      $url .= '?key=' . $this->apiKey;
    }
    else {
      $url .= '&key=' . $this->apiKey;
    }

    // Build the request body
    $parts = [];
    if ( $query->has_image() ) {
      $parts[] = [ 'inline_data' => [
        'mime_type' => $query->imageMimeType,
        'data' => $query->imageData,
      ]];
    }
    $message = $query->get_message();
    if ( !empty( $message ) ) {
      $parts[] = [ 'text' => $message ];
    }
    $body = [ 'content' => [ 'parts' => $parts ] ];

    $headers = $this->build_headers( $query );
    $options = $this->build_options( $headers, $body );

    try {
      // Debug logging
      if ( $this->core->get_option( 'queries_debug_mode' ) ) {
        error_log( '[AI Engine] Google Embedding Request URL: ' . $url );
        error_log( '[AI Engine] Google Embedding Request Body: ' . json_encode( $body ) );
      }

      $res = $this->run_query( $url, $options );
      $data = $res['data'];

      // Debug logging
      if ( $this->core->get_option( 'queries_debug_mode' ) ) {
        // Don't log the full embedding response, just the structure
        if ( isset( $data['embedding']['values'] ) && is_array( $data['embedding']['values'] ) ) {
          error_log( '[AI Engine] Google Embedding Response: Received ' . count( $data['embedding']['values'] ) . ' dimensions' );
        }
        else {
          error_log( '[AI Engine] Google Embedding Response: ' . json_encode( $data ) );
        }
      }

      // Check if we have an error response
      if ( isset( $data['error'] ) ) {
        $errorMsg = isset( $data['error']['message'] ) ? $data['error']['message'] : 'Unknown error';
        $errorCode = isset( $data['error']['code'] ) ? $data['error']['code'] : 'N/A';
        throw new Exception( "Google API Error (Code: {$errorCode}): {$errorMsg}" );
      }

      if ( !isset( $data['embedding']['values'] ) ) {
        throw new Exception( 'No embedding values in the response. Response: ' . json_encode( $data ) );
      }

      $embedding = $data['embedding']['values'];

      // Handle matryoshka truncation if dimensions are specified
      if ( $query->dimensions && $query->dimensions < count( $embedding ) ) {
        // Google Gemini embedding models support matryoshka (dimension truncation)
        // We can safely truncate to the requested dimensions
        $embedding = array_slice( $embedding, 0, $query->dimensions );

        if ( $this->core->get_option( 'queries_debug_mode' ) ) {
          error_log( '[AI Engine] Truncated embedding from ' . count( $data['embedding']['values'] ) . " to {$query->dimensions} dimensions (matryoshka)" );
        }
      }

      $reply = new Meow_MWAI_Reply( $query );
      $reply->type = 'embedding';
      $reply->result = $embedding;
      $reply->results = [ $embedding ];

      // Newer Gemini embedding models (gemini-embedding-001/002+) return usageMetadata.
      // Older ones (text-embedding-004, embedding-001) don't, in which case we estimate
      // from the input message. We always pass 0 for out_tokens — never null — so the
      // fallback in handle_tokens_usage doesn't try to "estimate" tokens from the
      // embedding vector itself (which would produce huge bogus counts like 13k for
      // a 50-character input).
      $promptTokens = isset( $data['usageMetadata']['promptTokenCount'] )
        ? (int) $data['usageMetadata']['promptTokenCount']
        : null;
      $this->handle_tokens_usage( $reply, $query, $query->model, $promptTokens, 0 );

      return $reply;
    }
    catch ( Exception $e ) {
      Meow_MWAI_Logging::error( '(Google) Embedding error: ' . $e->getMessage() );
      throw new Exception( 'Google Embedding Error: ' . $e->getMessage() );
    }
  }

  /**
   * Upload a file to Gemini Files API using multipart/form-data.
   * PRO VERSION ONLY - Uses cURL for proper header handling.
   *
   * @param string $filename The original filename
   * @param string $data The file binary data
   * @param string $mimeType The MIME type of the file
   * @return array The uploaded file response with 'file' key containing 'uri', 'name', and 'mimeType'
   * @throws Exception If upload fails
   */
  public function upload_file( $filename, $data, $mimeType ) {
    global $wp_filter;

    // Temporarily remove ALL http_api_curl hooks to prevent streaming hook interference
    $saved_hooks = null;
    if ( isset( $wp_filter['http_api_curl'] ) ) {
      $saved_hooks = $wp_filter['http_api_curl'];
      unset( $wp_filter['http_api_curl'] );
    }

    try {
      // Build multipart form data
      $boundary = '----WebKitFormBoundary' . uniqid();
      $bodyContent = '';

      // Add metadata field
      $bodyContent .= "--{$boundary}\r\n";
      $bodyContent .= "Content-Disposition: form-data; name=\"metadata\"\r\n";
      $bodyContent .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
      $bodyContent .= json_encode( [ 'file' => [ 'display_name' => $filename ] ] );
      $bodyContent .= "\r\n";

      // Add file field
      $bodyContent .= "--{$boundary}\r\n";
      $bodyContent .= "Content-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\n";
      $bodyContent .= "Content-Type: {$mimeType}\r\n\r\n";
      $bodyContent .= $data;
      $bodyContent .= "\r\n";
      $bodyContent .= "--{$boundary}--\r\n";

      $uploadUrl = 'https://generativelanguage.googleapis.com/upload/v1beta/files?key=' . $this->apiKey;

      // Upload using cURL
      $ch = curl_init( $uploadUrl );
      curl_setopt_array( $ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
          'Content-Type: multipart/form-data; boundary=' . $boundary,
          'Content-Length: ' . strlen( $bodyContent )
        ],
        CURLOPT_POSTFIELDS => $bodyContent,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
      ] );

      $uploadResponse = curl_exec( $ch );
      $uploadHttpCode = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
      curl_close( $ch );

      // Restore hooks
      if ( $saved_hooks !== null ) {
        $wp_filter['http_api_curl'] = $saved_hooks;
      }

      if ( $uploadResponse === false ) {
        throw new Exception( 'cURL error during file upload' );
      }

      $result = json_decode( $uploadResponse, true );

      if ( isset( $result['error'] ) ) {
        throw new Exception( 'Gemini Files API error: ' . ( $result['error']['message'] ?? 'Unknown error' ) );
      }

      if ( empty( $result ) || !isset( $result['file'] ) ) {
        throw new Exception( 'Invalid response from Gemini Files API' );
      }

      return $result;
    }
    catch ( Exception $e ) {
      if ( $saved_hooks !== null ) {
        $wp_filter['http_api_curl'] = $saved_hooks;
      }
      throw $e;
    }
  }

  /**
   * Override build_messages to support full file handling with file_data format.
   * PRO VERSION ONLY - Supports PDFs uploaded to Gemini Files API.
   *
   * @param Meow_MWAI_Query_Completion|Meow_MWAI_Query_Feedback $query
   * @return array
   */
  protected function build_messages( $query ) {
    $messages = [];

    // 1. Instructions (if any).
    if ( !empty( $query->instructions ) ) {
      $messages[] = [
        'role' => 'model',
        'parts' => [
          [ 'text' => $query->instructions ]
        ]
      ];
    }

    // 2. Existing messages (already partially formatted).
    foreach ( $query->messages as $message ) {

      // Convert roles: 'assistant' => 'model', 'user' => 'user'.
      $newMessage = [ 'role' => $message['role'], 'parts' => [] ];
      if ( isset( $message['content'] ) ) {
        $newMessage['parts'][] = [ 'text' => $message['content'] ];
      }
      if ( $newMessage['role'] === 'assistant' ) {
        $newMessage['role'] = 'model';
      }
      $messages[] = $newMessage;
    }

    // 3. Context (if any).
    if ( !empty( $query->context ) ) {
      $messages[] = [
        'role' => 'model',
        'parts' => [
          [ 'text' => $query->context ]
        ]
      ];
    }

    // 4. The final user message with full file support (PRO).
    $userMessageParts = [];
    $hasVisionOrFiles = false;

    // Handle file attachments
    $attachments = method_exists( $query, 'getAttachments' ) ? $query->getAttachments() : [];
    if ( !empty( $attachments ) ) {
      foreach ( $attachments as $file ) {
        if ( $file->get_type() === 'provider_file_id' ) {
          // PDF uploaded to Gemini Files API - use file_data format
          $userMessageParts[] = [
            'fileData' => [
              'fileUri' => $file->get_refId(),
              'mimeType' => $file->get_mimeType()
            ]
          ];
          $hasVisionOrFiles = true;
        }
        else if ( $file->is_image() ) {
          // Image - use inlineData format
          $data = $file->get_base64();
          $userMessageParts[] = [
            'inlineData' => [
              'mimeType' => $file->get_mimeType(),
              'data' => $data
            ]
          ];
          $hasVisionOrFiles = true;
        }
      }
    }

    // Add the user's text message
    $userMessageParts[] = [ 'text' => $query->get_message() ];

    $messages[] = [
      'role' => 'user',
      'parts' => $userMessageParts
    ];

    // Gemini doesn't support multi-turn chat with Vision or Files.
    if ( $hasVisionOrFiles ) {
      $messages = array_slice( $messages, -1 );
    }

    // 5. Streamline messages.
    $messages = $this->streamline_messages( $messages, 'model', 'parts' );

    // Debug: Log message count before feedback
    if ( $this->core->get_option( 'queries_debug_mode' ) ) {
      error_log( '[AI Engine Queries] Messages before feedback: ' . count( $messages ) );
    }

    // 6. Feedback data for Meow_MWAI_Query_Feedback.
    if ( $query instanceof Meow_MWAI_Query_Feedback && !empty( $query->blocks ) ) {
      foreach ( $query->blocks as $feedback_block ) {
        // Debug logging of raw message
        if ( $this->core->get_option( 'queries_debug_mode' ) ) {
          error_log( '[AI Engine Queries] Raw message before formatting: ' . json_encode( $feedback_block['rawMessage'] ) );
        }

        $formattedMessage = $this->format_function_call( $feedback_block['rawMessage'] );

        // Debug logging of formatted message
        if ( $this->core->get_option( 'queries_debug_mode' ) ) {
          error_log( '[AI Engine Queries] Formatted function call message: ' . json_encode( $formattedMessage ) );
        }

        // Check if Google returned multiple function calls but we only have one response
        $functionCallCount = 0;
        if ( isset( $formattedMessage['parts'] ) ) {
          foreach ( $formattedMessage['parts'] as $part ) {
            if ( isset( $part['functionCall'] ) ) {
              $functionCallCount++;
            }
          }
        }

        if ( $functionCallCount > 1 && count( $feedback_block['feedbacks'] ) != $functionCallCount ) {
          // Mismatch between function calls and responses
          // Google requires exact matching of function calls to responses
          $errorMsg = sprintf(
            'Function call/response mismatch: Google returned %d function calls but we have %d response(s). ' .
            'Google requires all function responses to be provided together.',
            $functionCallCount,
            count( $feedback_block['feedbacks'] )
          );

          // Log the error for debugging
          if ( $this->core->get_option( 'queries_debug_mode' ) ) {
            error_log( '[AI Engine Queries] ERROR: ' . $errorMsg );

            // Log which functions were called vs which were responded to
            $calledFunctions = [];
            foreach ( $formattedMessage['parts'] as $part ) {
              if ( isset( $part['functionCall'] ) ) {
                $calledFunctions[] = $part['functionCall']['name'] ?? 'unknown';
              }
            }
            $respondedFunctions = array_map( function ( $fb ) {
              return $fb['request']['name'] ?? 'unknown';
            }, $feedback_block['feedbacks'] );

            error_log( '[AI Engine Queries] Called functions: ' . implode( ', ', $calledFunctions ) );
            error_log( '[AI Engine Queries] Responded functions: ' . implode( ', ', $respondedFunctions ) );
          }

          throw new Exception( $errorMsg );
        }

        $messages[] = $formattedMessage;
        foreach ( $feedback_block['feedbacks'] as $feedback ) {
          $functionResponseMessage = [
            'role' => 'function',
            'parts' => [
              [
                'functionResponse' => [
                  'name' => $feedback['request']['name'],
                  'response' => $this->format_function_response( $feedback['reply']['value'] )
                ]
              ]
            ]
          ];

          // Debug logging of function response
          if ( $this->core->get_option( 'queries_debug_mode' ) ) {
            error_log( '[AI Engine Queries] Function response: ' . json_encode( $functionResponseMessage ) );
          }

          $messages[] = $functionResponseMessage;
        }
      }
    }

    // Debug logging of all messages
    if ( $this->core->get_option( 'queries_debug_mode' ) ) {
      error_log( '[AI Engine Queries] Total messages to Google: ' . count( $messages ) );
      foreach ( $messages as $index => $message ) {
        $role = $message['role'] ?? 'unknown';
        $preview = $role;
        if ( isset( $message['parts'][0] ) ) {
          if ( isset( $message['parts'][0]['text'] ) ) {
            $text = substr( $message['parts'][0]['text'], 0, 50 );
            $preview .= ' (text: "' . $text . '...")';
          }
          elseif ( isset( $message['parts'][0]['functionCall'] ) ) {
            $preview .= ' (functionCall: ' . $message['parts'][0]['functionCall']['name'] . ')';
          }
          elseif ( isset( $message['parts'][0]['functionResponse'] ) ) {
            $preview .= ' (functionResponse: ' . $message['parts'][0]['functionResponse']['name'] . ')';
          }
          elseif ( isset( $message['parts'][0]['fileData'] ) ) {
            $preview .= ' (fileData: ' . ( $message['parts'][0]['fileData']['fileUri'] ?? 'unknown' ) . ')';
          }
        }
        error_log( '[AI Engine Queries] Message[' . $index . ']: ' . $preview );
      }
    }

    return $messages;
  }
}
