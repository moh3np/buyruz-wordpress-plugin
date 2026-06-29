<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Communication adapter for the Static Controller module.
 *
 * Provides a WordPress-compatible interface to the shared data directory,
 * mirroring the Communication_Interface used by Processing_Engine and Dashboard.
 * Uses file-based transport with atomic writes (temp + rename) to ensure
 * no reader observes a partially-written file.
 *
 * The shared_data_dir is read from the `static_controller` settings in `brz_options`.
 *
 * @since 4.11.0
 */
class BRZ_Static_Communication {

    /**
     * Option key for the Static Controller module within brz_options.
     */
    private const OPTION_KEY = 'static_controller';

    /**
     * Default shared data directory when no setting is configured.
     */
    private const DEFAULT_SHARED_DATA_DIR = '/static-data';

    /**
     * Get the shared data directory path from module settings.
     *
     * Reads from brz_options → static_controller → shared_data_dir.
     * Falls back to the default path if not configured.
     *
     * @return string Absolute path to the shared data directory (no trailing slash).
     */
    public static function get_shared_data_dir(): string {
        $options  = get_option( 'brz_options', array() );
        $settings = isset( $options[ self::OPTION_KEY ] ) && is_array( $options[ self::OPTION_KEY ] )
            ? $options[ self::OPTION_KEY ]
            : array();

        $dir = isset( $settings['shared_data_dir'] ) && is_string( $settings['shared_data_dir'] ) && $settings['shared_data_dir'] !== ''
            ? $settings['shared_data_dir']
            : self::DEFAULT_SHARED_DATA_DIR;

        return rtrim( $dir, '/' );
    }

    /**
     * Read a resource by key from the shared data directory.
     *
     * The key follows the format "category/name" which maps to
     * shared_data_dir/category/name.json on disk.
     *
     * @param string $key Resource identifier (max 255 chars, alphanumeric with slashes).
     * @return array{success: bool, data: ?string, error: ?string}
     */
    public static function read( string $key ): array {
        $path = self::key_to_path( $key );

        if ( ! file_exists( $path ) ) {
            return array(
                'success' => false,
                'data'    => null,
                'error'   => 'not_found',
            );
        }

        $content = @file_get_contents( $path );

        if ( $content === false ) {
            return array(
                'success' => false,
                'data'    => null,
                'error'   => sprintf( 'Failed to read: %s', $key ),
            );
        }

        return array(
            'success' => true,
            'data'    => $content,
            'error'   => null,
        );
    }

    /**
     * Write data atomically to a key in the shared data directory.
     *
     * Uses a temporary file + rename strategy to ensure no reader can
     * observe a partially-written value. Creates parent directories
     * if they don't exist.
     *
     * @param string $key  Resource identifier (max 255 chars).
     * @param string $data Content to write (typically JSON-encoded).
     * @return array{success: bool, error: ?string}
     */
    public static function write( string $key, string $data ): array {
        $path = self::key_to_path( $key );
        $dir  = dirname( $path );

        // Ensure directory exists.
        if ( ! is_dir( $dir ) ) {
            if ( ! wp_mkdir_p( $dir ) ) {
                return array(
                    'success' => false,
                    'error'   => sprintf( 'Failed to create directory for: %s', $key ),
                );
            }
            @chmod( $dir, 0755 );
        }

        // Atomic write: temp file + rename.
        $tmp = $path . '.tmp.' . getmypid() . '.' . wp_generate_password( 8, false );

        $bytes = @file_put_contents( $tmp, $data, LOCK_EX );

        if ( $bytes === false ) {
            @unlink( $tmp );
            return array(
                'success' => false,
                'error'   => sprintf( 'Failed to write temp file for: %s', $key ),
            );
        }

        @chmod( $tmp, 0644 );

        if ( ! @rename( $tmp, $path ) ) {
            @unlink( $tmp );
            return array(
                'success' => false,
                'error'   => sprintf( 'Atomic rename failed for: %s', $key ),
            );
        }

        return array(
            'success' => true,
            'error'   => null,
        );
    }

    /**
     * Check if a key exists in the shared data directory.
     *
     * @param string $key Resource identifier.
     * @return bool True if the resource file exists.
     */
    public static function exists( string $key ): bool {
        return file_exists( self::key_to_path( $key ) );
    }

    /**
     * Delete a resource by key from the shared data directory.
     *
     * @param string $key Resource identifier.
     * @return array{success: bool, error: ?string}
     */
    public static function delete( string $key ): array {
        $path = self::key_to_path( $key );

        if ( ! file_exists( $path ) ) {
            return array(
                'success' => true,
                'error'   => null,
            );
        }

        if ( ! @unlink( $path ) ) {
            return array(
                'success' => false,
                'error'   => sprintf( 'Failed to delete: %s', $key ),
            );
        }

        return array(
            'success' => true,
            'error'   => null,
        );
    }

    /**
     * List keys matching a prefix in the shared data directory.
     *
     * Scans the directory corresponding to the prefix and returns
     * basenames of .json files (without the .json extension).
     *
     * @param string $prefix Key prefix (e.g., "queue/pending").
     * @return array<string> Array of matching key basenames.
     */
    public static function list_keys( string $prefix ): array {
        $dir = self::get_shared_data_dir() . '/' . trim( $prefix, '/' );

        if ( ! is_dir( $dir ) ) {
            return array();
        }

        $files = glob( $dir . '/*.json' );

        if ( $files === false || empty( $files ) ) {
            return array();
        }

        return array_map(
            fn( string $f ): string => basename( $f, '.json' ),
            $files
        );
    }

    /**
     * Convert a key to a filesystem path.
     *
     * Key format: "category/name" → shared_data_dir/category/name.json
     * Characters are sanitized to prevent directory traversal.
     *
     * @param string $key Resource identifier.
     * @return string Absolute file path.
     */
    private static function key_to_path( string $key ): string {
        // Sanitize: only allow alphanumeric, underscores, hyphens, slashes, and dots.
        $sanitized = preg_replace( '/[^a-zA-Z0-9_\-\/.]/', '', $key );

        // Prevent directory traversal.
        $sanitized = str_replace( '..', '', $sanitized ?? '' );

        $path = self::get_shared_data_dir() . '/' . ltrim( $sanitized, '/' );

        // Append .json extension if not already present.
        if ( ! str_ends_with( $path, '.json' ) ) {
            $path .= '.json';
        }

        return $path;
    }
}
