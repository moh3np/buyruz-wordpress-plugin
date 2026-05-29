<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
// هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید و بی‌قید و شرط اجرا کنید و پس از اتمام کار تطابق را دوباره چک کنید.

/**
 * URLs Map Generator for Static Controller module.
 *
 * Builds the JSON structure mapping URLs to page types and modal data,
 * then writes the output file atomically (tmp + rename) to ensure the
 * Processing Engine never reads a partially written file.
 *
 * Supports batched generation for sites with >100 selected pages,
 * scheduling subsequent batches via wp_schedule_single_event with
 * 60-second intervals.
 */
class BRZ_Static_Map_Generator {

    /**
     * Option key used by the Static Controller module within brz_options.
     */
    private const OPTION_KEY = 'static_controller';

    /**
     * Maximum pages to process in a single generation run.
     */
    private const BATCH_LIMIT = 100;

    /**
     * Interval in seconds between scheduled batches.
     */
    private const BATCH_INTERVAL = 60;

    /**
     * Get the configured output file path.
     *
     * @return string Absolute path to the urls-map.json file.
     */
    public static function get_output_path(): string {
        $settings = BRZ_Static_Controller::get_settings();

        return $settings['output_path'];
    }

    /**
     * Ensure the directory for a given file path exists with correct permissions.
     *
     * Creates missing parent directories recursively using wp_mkdir_p()
     * and sets permissions to 0755.
     *
     * @param string $path The file path whose parent directory should exist.
     * @return bool|\WP_Error True on success, WP_Error on failure.
     */
    public static function ensure_directory( string $path ): bool|\WP_Error {
        $dir = dirname( $path );

        if ( is_dir( $dir ) ) {
            return true;
        }

        if ( ! wp_mkdir_p( $dir ) ) {
            return new \WP_Error(
                'dir_create_failed',
                sprintf( 'امکان ایجاد دایرکتوری وجود ندارد: %s', $dir )
            );
        }

        chmod( $dir, 0755 );

        return true;
    }

    /**
     * Atomic write: write content to a temporary file then rename to final path.
     *
     * Ensures the Processing Engine never reads a partially written file.
     * Uses LOCK_EX for exclusive file locking during write.
     *
     * @param string $path    The final destination file path.
     * @param string $content The content to write.
     * @return bool|\WP_Error True on success, WP_Error on failure.
     */
    private static function atomic_write( string $path, string $content ): bool|\WP_Error {
        $dir      = dirname( $path );
        $tmp_path = $dir . '/.urls-map-' . uniqid() . '.tmp';

        $bytes = file_put_contents( $tmp_path, $content, LOCK_EX );

        if ( $bytes === false ) {
            return new \WP_Error(
                'write_failed',
                sprintf( 'امکان نوشتن در فایل موقت وجود ندارد: %s', $tmp_path )
            );
        }

        chmod( $tmp_path, 0644 );

        if ( ! rename( $tmp_path, $path ) ) {
            @unlink( $tmp_path );

            return new \WP_Error(
                'rename_failed',
                sprintf( 'عملیات تغییر نام اتمیک ناموفق بود: %s → %s', $tmp_path, $path )
            );
        }

        return true;
    }

    /**
     * Build the map data structure for selected pages.
     *
     * Constructs metadata (generation_timestamp, total_count, plugin_version)
     * and pages array (url, page_type, modal) for each selected page with
     * 'publish' status.
     *
     * @param array $selected_pages Array of selected page entries with 'id', 'type', and optionally 'taxonomy'.
     * @return array{metadata: array, pages: array} The complete map data structure.
     */
    public static function build_map_data( array $selected_pages ): array {
        $pages = [];

        foreach ( $selected_pages as $page_entry ) {
            $id   = (int) ( $page_entry['id'] ?? 0 );
            $type = $page_entry['type'] ?? 'post';

            if ( $id <= 0 ) {
                continue;
            }

            if ( $type === 'term' ) {
                $taxonomy = $page_entry['taxonomy'] ?? '';
                $url      = get_term_link( $id, $taxonomy );

                if ( is_wp_error( $url ) ) {
                    continue;
                }

                $page_type = BRZ_Static_Page_Detector::detect_term( $id, $taxonomy );
                $modal     = BRZ_Static_Modal_Injector::get_modal_for_page( $id );

                $pages[] = [
                    'url'       => $url,
                    'page_type' => $page_type,
                    'modal'     => $modal,
                ];
            } else {
                // type === 'post'
                $post = get_post( $id );

                if ( ! $post || $post->post_status !== 'publish' ) {
                    continue;
                }

                $url       = get_permalink( $id );
                $page_type = BRZ_Static_Page_Detector::detect( $id );
                $modal     = BRZ_Static_Modal_Injector::get_modal_for_page( $id );

                $pages[] = [
                    'url'       => $url,
                    'page_type' => $page_type,
                    'modal'     => $modal,
                ];
            }
        }

        $metadata = [
            'generation_timestamp' => gmdate( 'c' ),
            'total_count'          => count( $pages ),
            'plugin_version'       => defined( 'BRZ_VERSION' ) ? BRZ_VERSION : '1.0.0',
        ];

        return [
            'metadata' => $metadata,
            'pages'    => $pages,
        ];
    }

    /**
     * Generate the complete URLs map and write atomically.
     *
     * If the number of selected pages exceeds 100, schedules batched
     * generation instead of processing all at once.
     *
     * @return bool|\WP_Error True on success, WP_Error on failure.
     */
    public static function generate(): bool|\WP_Error {
        $settings       = BRZ_Static_Controller::get_settings();
        $selected_pages = $settings['selected_pages'] ?? [];
        $output_path    = $settings['output_path'];

        // Update status to generating.
        self::update_generation_status( 'generating' );

        // If more than 100 pages, schedule batched generation.
        if ( count( $selected_pages ) > self::BATCH_LIMIT ) {
            wp_schedule_single_event(
                time() + self::BATCH_INTERVAL,
                BRZ_Static_Controller::BATCH_HOOK,
                [ 0, self::BATCH_LIMIT ]
            );

            return true;
        }

        // Build map data for all selected pages.
        $map_data = self::build_map_data( $selected_pages );

        $json = json_encode(
            $map_data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ( $json === false ) {
            self::update_generation_status( 'error' );

            return new \WP_Error(
                'json_encode_failed',
                'خطا در تبدیل داده‌ها به JSON.'
            );
        }

        // Ensure output directory exists.
        $dir_result = self::ensure_directory( $output_path );

        if ( is_wp_error( $dir_result ) ) {
            self::update_generation_status( 'error' );

            return $dir_result;
        }

        // Atomic write to output path.
        $write_result = self::atomic_write( $output_path, $json );

        if ( is_wp_error( $write_result ) ) {
            self::update_generation_status( 'error' );

            return $write_result;
        }

        // Update settings with success status.
        self::update_generation_status( 'success', gmdate( 'c' ) );

        return true;
    }

    /**
     * Generate a batch of URLs for large sites (>100 pages).
     *
     * Processes a slice of selected pages from the given offset.
     * If this is the last batch, merges all batch data and performs
     * the final atomic write. Otherwise, schedules the next batch.
     *
     * @param int $offset Starting index in the selected_pages array.
     * @param int $limit  Number of pages to process in this batch (default 100).
     * @return bool|\WP_Error True on success, WP_Error on failure.
     */
    public static function generate_batch( int $offset, int $limit = 100 ): bool|\WP_Error {
        $settings       = BRZ_Static_Controller::get_settings();
        $selected_pages = $settings['selected_pages'] ?? [];
        $output_path    = $settings['output_path'];
        $total_pages    = count( $selected_pages );

        // Get the slice for this batch.
        $batch_pages = array_slice( $selected_pages, $offset, $limit );

        if ( empty( $batch_pages ) ) {
            self::update_generation_status( 'success', gmdate( 'c' ) );

            return true;
        }

        // Build partial map data for this batch.
        $batch_data = self::build_map_data( $batch_pages );

        // Store batch results in a transient.
        $batch_key     = 'brz_static_batch_' . $offset;
        $batch_results = $batch_data['pages'];
        set_transient( $batch_key, $batch_results, HOUR_IN_SECONDS );

        $next_offset = $offset + $limit;

        // Check if this is the last batch.
        if ( $next_offset >= $total_pages ) {
            // Merge all batches and write final output.
            $all_pages = self::merge_batch_results( $total_pages, $limit );

            $final_data = [
                'metadata' => [
                    'generation_timestamp' => gmdate( 'c' ),
                    'total_count'          => count( $all_pages ),
                    'plugin_version'       => defined( 'BRZ_VERSION' ) ? BRZ_VERSION : '1.0.0',
                ],
                'pages' => $all_pages,
            ];

            $json = json_encode(
                $final_data,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );

            if ( $json === false ) {
                self::update_generation_status( 'error' );
                self::cleanup_batch_transients( $total_pages, $limit );

                return new \WP_Error(
                    'json_encode_failed',
                    'خطا در تبدیل داده‌ها به JSON.'
                );
            }

            // Ensure output directory exists.
            $dir_result = self::ensure_directory( $output_path );

            if ( is_wp_error( $dir_result ) ) {
                self::update_generation_status( 'error' );
                self::cleanup_batch_transients( $total_pages, $limit );

                return $dir_result;
            }

            // Atomic write.
            $write_result = self::atomic_write( $output_path, $json );

            if ( is_wp_error( $write_result ) ) {
                self::update_generation_status( 'error' );
                self::cleanup_batch_transients( $total_pages, $limit );

                return $write_result;
            }

            // Cleanup batch transients and update status.
            self::cleanup_batch_transients( $total_pages, $limit );
            self::update_generation_status( 'success', gmdate( 'c' ) );

            return true;
        }

        // Not the last batch — schedule next batch with 60s delay.
        wp_schedule_single_event(
            time() + self::BATCH_INTERVAL,
            BRZ_Static_Controller::BATCH_HOOK,
            [ $next_offset, $limit ]
        );

        return true;
    }

    /**
     * Merge all batch results from transients into a single pages array.
     *
     * @param int $total_pages Total number of selected pages.
     * @param int $limit       Batch size used during generation.
     * @return array Merged array of all page entries.
     */
    private static function merge_batch_results( int $total_pages, int $limit ): array {
        $all_pages = [];

        for ( $offset = 0; $offset < $total_pages; $offset += $limit ) {
            $batch_key  = 'brz_static_batch_' . $offset;
            $batch_data = get_transient( $batch_key );

            if ( is_array( $batch_data ) ) {
                $all_pages = array_merge( $all_pages, $batch_data );
            }
        }

        return $all_pages;
    }

    /**
     * Clean up batch transients after generation completes or fails.
     *
     * @param int $total_pages Total number of selected pages.
     * @param int $limit       Batch size used during generation.
     */
    private static function cleanup_batch_transients( int $total_pages, int $limit ): void {
        for ( $offset = 0; $offset < $total_pages; $offset += $limit ) {
            delete_transient( 'brz_static_batch_' . $offset );
        }
    }

    /**
     * Update generation status and optionally the last_generated timestamp.
     *
     * @param string      $status         The new generation status (idle, generating, success, error).
     * @param string|null $last_generated ISO 8601 timestamp of last successful generation, or null to keep existing.
     */
    private static function update_generation_status( string $status, ?string $last_generated = null ): void {
        $options  = get_option( 'brz_options', [] );
        $settings = isset( $options[ self::OPTION_KEY ] ) && is_array( $options[ self::OPTION_KEY ] )
            ? $options[ self::OPTION_KEY ]
            : [];

        $settings['generation_status'] = $status;

        if ( $last_generated !== null ) {
            $settings['last_generated'] = $last_generated;
        }

        if ( ! is_array( $options ) ) {
            $options = [];
        }

        $options[ self::OPTION_KEY ] = $settings;
        update_option( 'brz_options', $options );
    }
}
