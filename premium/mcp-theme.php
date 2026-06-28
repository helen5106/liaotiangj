<?php

class MeowPro_MWAI_MCP_Theme {
  private $core = null;
  private $log_file = 'mwai.log';
  private $def_name = 'AI Engine Theme';

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

  #region Helpers

  private function theme_dir( string $slug ): string {
    return trailingslashit( get_theme_root() . '/' . sanitize_key( $slug ) );
  }

  /** Validate relative path against traversal & mwai.log; return absolute path or ''. */
  private function safe_path( string $slug, string $rel ): string {
    $rel = ltrim( str_replace( '\\', '/', $rel ), '/' );
    if ( $rel === '' ) {
      return $this->theme_dir( $slug );
    }
    if ( strpos( $rel, '..' ) !== false || $rel === $this->log_file ) {
      return '';
    }
    return $this->theme_dir( $slug ) . $rel;
  }

  private function empty_schema(): array {
    return [ 'type' => 'object', 'properties' => (object) [] ];
  }

  private function is_ai_theme( string $slug ): bool {
    return file_exists( $this->theme_dir( $slug ) . $this->log_file );
  }

  private function log_action( string $slug, string $msg ): void {
    $path = $this->theme_dir( $slug ) . $this->log_file;
    if ( file_exists( $path ) ) {
      file_put_contents( $path, date( 'c' ) . ' — ' . $msg . PHP_EOL, FILE_APPEND );
    }
  }

  /** Simple PCRE-pattern validator. */
  private function is_valid_regex( string $pattern ): bool {
    set_error_handler( fn () => false );
    $ok = preg_match( $pattern, '' ) !== false || preg_last_error() !== PREG_INTERNAL_ERROR;
    restore_error_handler();
    return $ok;
  }

  /** Ensure AI theme exists, creating boilerplate & log if needed. */
  private function ensure_theme_exists( string $slug, ?string $name = null ): bool {
    $dir = $this->theme_dir( $slug );
    if ( is_dir( $dir ) ) {
      return $this->is_ai_theme( $slug );
    }
    if ( !wp_mkdir_p( $dir ) ) {
      return false;
    }

    /* style.css */
    $css = implode( "\n", [
      '/*',
      'Theme Name: ' . ( $name ?: $this->def_name ),
      'Theme URI:  https://meowapps.com/',
      'Author:     AI Engine',
      'Version:    1.0',
      '*/',
      '',
    ] );
    file_put_contents( $dir . 'style.css', $css );

    /* index.php */
    $index = "<?php\n";
    $index .= "get_header(); ?>\n";
    $index .= "<main id=\"primary\">\n";
    $index .= "  <h1><?php the_title(); ?></h1>\n";
    $index .= "  <?php the_content(); ?>\n";
    $index .= "</main>\n";
    $index .= "<?php get_footer();\n";
    file_put_contents( $dir . 'index.php', $index );

    /* mwai.log */
    file_put_contents( $dir . $this->log_file, '' );
    $this->log_action( $slug, 'Theme created.' );

    return true;
  }

  /** Recursive copy dir → dir. */
  private function copy_dir( string $src, string $dst ): bool {
    $src = rtrim( $src, '/' );
    $dst = rtrim( $dst, '/' );
    if ( !is_dir( $src ) || !wp_mkdir_p( $dst ) ) {
      return false;
    }
    $it = new RecursiveIteratorIterator(
      new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS ),
      RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ( $it as $f ) {
      $dest = $dst . substr( $f->getPathname(), strlen( $src ) );
      $f->isDir() ? wp_mkdir_p( $dest ) : copy( $f->getPathname(), $dest );
    }
    return true;
  }

  /** Recursive delete. */
  private function delete_dir( string $dir ): bool {
    if ( !is_dir( $dir ) ) {
      return true;
    }
    $it = new RecursiveIteratorIterator(
      new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
      RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ( $it as $f ) {
      $f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() );
    }
    return rmdir( $dir );
  }
  #endregion

  #region Tools
  private function tools(): array {
    return [

      /* ───────── Themes overview ───────── */
      'wp_list_themes' => [
        'name' => 'wp_list_themes',
        'description' => 'List installed themes (slug, name, version, active?, editable?).',
        'inputSchema' => $this->empty_schema(),
        'accessLevel' => 'admin',
      ],

      'wp_switch_theme' => [
        'name' => 'wp_switch_theme',
        'description' => 'Activate any installed theme by slug.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [ 'slug' => [ 'type' => 'string' ] ],
          'required' => [ 'slug' ],
        ],
        'accessLevel' => 'admin',
      ],

      /* ───────── Theme CRUD ───────── */
      'wp_create_theme' => [
        'name' => 'wp_create_theme',
        'description' => 'Create a new AI Engine theme (slug required, optional name).',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'slug' => [ 'type' => 'string' ],
            'name' => [ 'type' => 'string' ],
          ],
          'required' => [ 'slug' ],
        ],
        'accessLevel' => 'admin',
      ],

      'wp_copy_theme' => [
        'name' => 'wp_copy_theme',
        'description' => 'Duplicate an existing theme into a new AI Engine theme.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'source_slug' => [ 'type' => 'string' ],
            'new_slug' => [ 'type' => 'string' ],
            'new_name' => [ 'type' => 'string' ],
          ],
          'required' => [ 'source_slug', 'new_slug' ],
        ],
        'accessLevel' => 'admin',
      ],

      'wp_rename_theme' => [
        'name' => 'wp_rename_theme',
        'description' => 'Rename an AI Engine theme. If the renamed theme was active, it is re-activated under its new slug.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'old_slug' => [ 'type' => 'string' ],
            'new_slug' => [ 'type' => 'string' ],
          ],
          'required' => [ 'old_slug', 'new_slug' ],
        ],
        'accessLevel' => 'admin',
      ],

      'wp_delete_theme' => [
        'name' => 'wp_delete_theme',
        'description' => 'Delete an AI Engine theme; if active, WordPress switches away first.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [ 'slug' => [ 'type' => 'string' ] ],
          'required' => [ 'slug' ],
        ],
        'accessLevel' => 'admin',
      ],

      /* ───────── Directory ops ───────── */
      'wp_theme_mkdir' => [
        'name' => 'wp_theme_mkdir',
        'description' => 'Create a directory (and parents) inside an AI Engine theme.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'slug' => [ 'type' => 'string' ],
            'dir' => [ 'type' => 'string' ],
          ],
          'required' => [ 'slug', 'dir' ],
        ],
        'accessLevel' => 'admin',
      ],

      'wp_theme_list_dir' => [
        'name' => 'wp_theme_list_dir',
        'description' => 'List dirs/files inside a directory of an AI Engine theme (omit dir for root).',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'slug' => [ 'type' => 'string' ],
            'dir' => [ 'type' => 'string' ],
          ],
          'required' => [ 'slug' ],
        ],
        'accessLevel' => 'admin',
      ],

      'wp_theme_delete_path' => [
        'name' => 'wp_theme_delete_path',
        'description' => 'Delete a file or directory (recursively) inside an AI Engine theme. IMPORTANT: irreversible.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'slug' => [ 'type' => 'string' ],
            'path' => [ 'type' => 'string' ],
          ],
          'required' => [ 'slug', 'path' ],
        ],
        'accessLevel' => 'admin',
      ],

      /* ───────── File ops ───────── */
      'wp_theme_get_file' => [
        'name' => 'wp_theme_get_file',
        'description' => 'Get raw contents of a file in an AI Engine theme.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'slug' => [ 'type' => 'string' ],
            'file' => [ 'type' => 'string' ],
          ],
          'required' => [ 'slug', 'file' ],
        ],
        'accessLevel' => 'admin',
      ],

      'wp_theme_put_file' => [
        'name' => 'wp_theme_put_file',
        'description' => 'Create or overwrite any file inside an AI Engine theme’s directory, but keep style.css minimal (header + version only) and place substantive CSS in separate files; always prefix PHP functions with the theme slug, avoid including or requiring PHP files that don’t yet exist (to prevent WordPress crashes), limit the total number of files so the AI can manage the project more easily, and remember to bump the version number in style.css (and in functions.php if referenced there) whenever major changes—especially to CSS—are made so that caching servers serve the latest assets.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'slug' => [ 'type' => 'string' ],
            'file' => [ 'type' => 'string' ],
            'content' => [ 'type' => 'string' ],
          ],
          'required' => [ 'slug', 'file', 'content' ],
        ],
        'accessLevel' => 'admin',
      ],

      /* ───────── NEW: Alter file ───────── */
      'wp_theme_alter_file' => [
        'name' => 'wp_theme_alter_file',
        'description' => 'Search-and-replace inside a single file of an AI Engine theme (recommended for fast, efficient updates compared to replacing the whole file; still, be careful with function order). Args: slug (string) – theme slug; file (string) – relative path in theme; search (string) – literal text or a PCRE pattern with delimiters (e.g., /foo/i); replace (string) – replacement text; regex (bool, default false) – set true to treat search as regex. Regex rules: pattern must be valid PHP-PCRE; delimiters mandatory; allowed flags i m s u x A D U; forbidden deprecated e modifier. Returns the number of replacements applied; if zero, the file is left untouched.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'slug' => [ 'type' => 'string' ],
            'file' => [ 'type' => 'string' ],
            'search' => [ 'type' => 'string' ],
            'replace' => [ 'type' => 'string' ],
            'regex' => [ 'type' => 'boolean' ],
          ],
          'required' => [ 'slug', 'file', 'search', 'replace' ],
        ],
        'accessLevel' => 'admin',
      ],

      'wp_theme_set_screenshot' => [
        'name' => 'wp_theme_set_screenshot',
        'description' => 'Add or replace the theme screenshot (PNG/JPG) from a URL or local path.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'slug' => [ 'type' => 'string' ],
            'source' => [ 'type' => 'string' ],
          ],
          'required' => [ 'slug', 'source' ],
        ],
        'accessLevel' => 'admin',
      ],
    ];
  }
  #endregion

  #region Register
  public function register_rest_tools( array $prev ): array {
    $tools = $this->tools();

    // Add category and annotations to each tool
    foreach ( $tools as &$tool ) {
      if ( !isset( $tool['category'] ) ) {
        $tool['category'] = 'AI Engine (Themes)';
      }

      // Add MCP tool annotations
      if ( !isset( $tool['annotations'] ) ) {
        $name = $tool['name'];

        // Read-only tools
        $is_readonly = (
          $name === 'wp_list_themes' ||
          strpos( $name, '_get_' ) !== false ||
          strpos( $name, '_list_' ) !== false
        );

        // Destructive tools
        $is_destructive = (
          strpos( $name, '_delete_' ) !== false ||
          $name === 'wp_theme_alter_file'
        );

        $tool['annotations'] = [
          'readOnlyHint' => $is_readonly,
          'destructiveHint' => !$is_readonly && $is_destructive,
          'openWorldHint' => false,
        ];
      }
    }

    $merged = array_merge( $prev, array_values( $tools ) );
    return $merged;
  }
  #endregion

  #region Callback
  public function handle_call( $prev, string $tool, array $args, ?int $id ) {
    if ( !empty( $prev ) || !isset( $this->tools()[ $tool ] ) ) {
      return $prev; // handled elsewhere or unknown
    }
    if ( !current_user_can( 'administrator' ) ) {
      wp_set_current_user( 1 );
    }
    return $this->dispatch( $tool, $args, $id );
  }
  #endregion

  #region Dispatcher
  private function dispatch( string $tool, array $a, ?int $id ) {

    switch ( $tool ) {

      /* ───────── Themes overview ───────── */
      case 'wp_list_themes':
        $active = wp_get_theme()->get_stylesheet();
        $list = [];
        foreach ( wp_get_themes() as $slug => $t ) {
          $list[] = [
            'slug' => $slug,
            'name' => $t->get( 'Name' ),
            'version' => $t->get( 'Version' ),
            'active' => $slug === $active,
            'editable' => $this->is_ai_theme( $slug ),
          ];
        }
        return $list;
        break;

      case 'wp_switch_theme':
        $slug = sanitize_key( $a['slug'] ?? '' );
        if ( !$slug || !wp_get_theme( $slug )->exists() ) {
          throw new Exception( 'Unknown theme slug' );
        }
        switch_theme( $slug );
        return 'Theme switched to ' . $slug . '.';
        break;

        /* ───────── Create / copy / rename / delete theme ───────── */
      case 'wp_create_theme':
        $slug = sanitize_key( $a['slug'] ?? '' );
        if ( !$slug || is_dir( $this->theme_dir( $slug ) ) ) {
          throw new Exception( 'Invalid or existing slug' );
        }
        if ( !$this->ensure_theme_exists( $slug, $a['name'] ?? null ) ) {
          throw new Exception( 'Creation failed' );
        }
        return 'Theme "' . $slug . '" created.';
        break;

      case 'wp_copy_theme':
        $src = sanitize_key( $a['source_slug'] ?? '' );
        $dst = sanitize_key( $a['new_slug'] ?? '' );
        if ( !$src || !$dst ) {
          throw new Exception( 'source_slug & new_slug required' );
        }
        if ( !wp_get_theme( $src )->exists() || is_dir( $this->theme_dir( $dst ) ) ) {
          throw new Exception( 'Invalid source or destination slug' );
        }
        if ( !$this->copy_dir( $this->theme_dir( $src ), $this->theme_dir( $dst ) ) ) {
          throw new Exception( 'Copy failed' );
        }
        file_put_contents( $this->theme_dir( $dst ) . $this->log_file, '' );
        $this->log_action( $dst, 'Theme forked from ' . $src . '.' );
        if ( !empty( $a['new_name'] ) && file_exists( $this->theme_dir( $dst ) . 'style.css' ) ) {
          $css = file_get_contents( $this->theme_dir( $dst ) . 'style.css' );
          $css = preg_replace( '/Theme Name:\\s*.+/i', 'Theme Name: ' . $a['new_name'], $css, 1 );
          file_put_contents( $this->theme_dir( $dst ) . 'style.css', $css );
        }
        return 'Theme "' . $src . '" copied to "' . $dst . '".';
        break;

      case 'wp_rename_theme':
        $old = sanitize_key( $a['old_slug'] ?? '' );
        $new = sanitize_key( $a['new_slug'] ?? '' );
        if ( !$old || !$new || !$this->is_ai_theme( $old ) || is_dir( $this->theme_dir( $new ) ) ) {
          throw new Exception( 'Invalid slugs or target exists' );
        }
        if ( !rename( $this->theme_dir( $old ), $this->theme_dir( $new ) ) ) {
          throw new Exception( 'Rename failed' );
        }
        $this->log_action( $new, 'Theme renamed from ' . $old . '.' );

        // Switch only if the renamed theme was active.
        $was_active = ( $old === wp_get_theme()->get_stylesheet() );
        if ( $was_active ) {
          switch_theme( $new );
        }

        return 'Theme renamed to ' . $new . ( $was_active ? ' and activated.' : '.' );
        break;

      case 'wp_delete_theme':
        $slug = sanitize_key( $a['slug'] ?? '' );
        if ( !$slug || !$this->is_ai_theme( $slug ) ) {
          throw new Exception( 'Unknown AI Engine theme' );
        }
        if ( $slug === wp_get_theme()->get_stylesheet() ) {
          foreach ( wp_get_themes() as $alt => $t ) {
            if ( $alt !== $slug ) {
              switch_theme( $alt );
              break;
            }
          }
        }
        $ok = function_exists( 'delete_theme' )
          ? !is_wp_error( delete_theme( $slug ) )
              : $this->delete_dir( $this->theme_dir( $slug ) );
        if ( !$ok ) {
          throw new Exception( 'Delete failed' );
        }
        return 'Theme "' . $slug . '" deleted.';
        break;

        /* ───────── Directory operations ───────── */
      case 'wp_theme_mkdir':
        $slug = sanitize_key( $a['slug'] ?? '' );
        $dir = $a['dir'] ?? '';
        $dest = $this->safe_path( $slug, $dir );
        if ( !$slug || !$dir || !$this->is_ai_theme( $slug ) || !$dest ) {
          throw new Exception( 'Invalid slug or dir' );
        }
        if ( !wp_mkdir_p( $dest ) ) {
          throw new Exception( 'mkdir failed' );
        }
        $this->log_action( $slug, 'Created dir ' . $dir . '.' );
        return 'Directory created.';
        break;

      case 'wp_theme_list_dir':
        $slug = sanitize_key( $a['slug'] ?? '' );
        $dir = $a['dir'] ?? '';
        $path = $this->safe_path( $slug, $dir );
        if ( !$slug || !$this->is_ai_theme( $slug ) || !$path || !is_dir( $path ) ) {
          throw new Exception( 'Dir not found' );
        }
        $dirs = [];
        $files = [];
        foreach ( scandir( $path ) as $item ) {
          if ( $item === '.' || $item === '..' || $item === $this->log_file ) {
            continue;
          }
          $rel = ltrim( ( $dir ? $dir . '/' : '' ) . $item, '/' );
          is_dir( $path . '/' . $item ) ? $dirs[] = $rel : $files[] = $rel;
        }
        return [ 'dirs' => $dirs, 'files' => $files ];
        break;

      case 'wp_theme_delete_path':
        $slug = sanitize_key( $a['slug'] ?? '' );
        $rel = $a['path'] ?? '';
        $abs = $this->safe_path( $slug, $rel );
        if ( !$slug || !$rel || !$this->is_ai_theme( $slug ) || !$abs || !file_exists( $abs ) ) {
          throw new Exception( 'Path not found' );
        }
        $ok = is_dir( $abs ) ? $this->delete_dir( $abs ) : unlink( $abs );
        if ( !$ok ) {
          throw new Exception( 'Delete failed' );
        }
        $this->log_action( $slug, 'Deleted ' . $rel . '.' );
        return $rel . ' deleted.';
        break;

        /* ───────── File operations ───────── */
      case 'wp_theme_get_file':
        $slug = sanitize_key( $a['slug'] ?? '' );
        $file = $a['file'] ?? '';
        $abs = $this->safe_path( $slug, $file );
        if ( !$slug || !$file || !$this->is_ai_theme( $slug ) || !$abs || !is_file( $abs ) ) {
          throw new Exception( 'File not found' );
        }
        return file_get_contents( $abs );
        break;

      case 'wp_theme_put_file':
        $slug = sanitize_key( $a['slug'] ?? '' );
        $file = $a['file'] ?? '';
        $cnt = $a['content'] ?? null;
        $abs = $this->safe_path( $slug, $file );
        if ( !$slug || !$file || !isset( $cnt ) || !$this->is_ai_theme( $slug ) || !$abs ) {
          throw new Exception( 'Invalid input' );
        }
        wp_mkdir_p( dirname( $abs ) );
        if ( file_put_contents( $abs, $cnt ) === false ) {
          throw new Exception( 'Write failed' );
        }
        $this->log_action( $slug, 'Saved ' . $file . '.' );
        return $file . ' saved.';
        break;

        /* ───────── Alter file ───────── */
      case 'wp_theme_alter_file':
        $slug = sanitize_key( $a['slug'] ?? '' );
        $file = $a['file'] ?? '';
        $search = $a['search'] ?? null;
        $replace = $a['replace'] ?? '';
        $regex = (bool) ( $a['regex'] ?? false );
        $abs = $this->safe_path( $slug, $file );

        if ( !$slug || !$file || !isset( $search ) || !$this->is_ai_theme( $slug ) || !$abs || !is_file( $abs ) ) {
          throw new Exception( 'Invalid input or file not found' );
        }
        if ( $regex && !$this->is_valid_regex( $search ) ) {
          throw new Exception( 'Invalid regex pattern' );
        }

        $contents = file_get_contents( $abs );
        $count = 0;
        if ( $regex ) {
          $new = preg_replace( $search, $replace, $contents, -1, $count );
          if ( $new === null ) {
            throw new Exception( 'Regex error' );
          }
        }
        else {
          $new = str_replace( $search, $replace, $contents, $count );
        }

        if ( $count === 0 ) {
          return 'No occurrences found; file unchanged.';
        }
        if ( file_put_contents( $abs, $new ) === false ) {
          throw new Exception( 'Write failed' );
        }
        $this->log_action( $slug, "Altered $file ($count replacement" . ( $count === 1 ? '' : 's' ) . ').' );
        return "$count replacement" . ( $count === 1 ? '' : 's' ) . ' applied.';
        break;

        /* ───────── Screenshot ───────── */
      case 'wp_theme_set_screenshot':
        $slug = sanitize_key( $a['slug'] ?? '' );
        $src = $a['source'] ?? '';
        if ( !$slug || !$src || !$this->is_ai_theme( $slug ) ) {
          throw new Exception( 'Invalid slug or source' );
        }
        $data = null;
        if ( filter_var( $src, FILTER_VALIDATE_URL ) ) {
          $resp = wp_remote_get( $src, [ 'timeout' => 15 ] );
          if ( is_wp_error( $resp ) || wp_remote_retrieve_response_code( $resp ) !== 200 ) {
            throw new Exception( 'Download failed' );
          }
          $data = wp_remote_retrieve_body( $resp );
        }
        elseif ( file_exists( $src ) ) {
          $data = file_get_contents( $src );
        }
        else {
          throw new Exception( 'Source not found' );
        }
        $ext = strtolower( pathinfo( $src, PATHINFO_EXTENSION ) );
        $ext = in_array( $ext, [ 'jpg', 'jpeg' ], true ) ? 'jpg' : ( $ext === 'png' ? 'png' : '' );
        if ( !$ext ) {
          throw new Exception( 'Unsupported format' );
        }
        foreach ( glob( $this->theme_dir( $slug ) . 'screenshot.*' ) as $old ) {
          @unlink( $old );
        }
        $dest = $this->theme_dir( $slug ) . 'screenshot.' . $ext;
        if ( file_put_contents( $dest, $data ) === false ) {
          throw new Exception( 'Write failed' );
        }
        $this->log_action( $slug, 'Updated screenshot.' );
        return 'Screenshot saved.';
        break;

      default:
        throw new Exception( 'Unknown tool' );
    }
  }
  #endregion
}
