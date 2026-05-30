<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
// هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید و بی‌قید و شرط اجرا کنید و پس از اتمام کار تطابق را دوباره چک کنید.

/**
 * Change Trigger for Static Controller module.
 *
 * Listens to WordPress/WooCommerce hooks for content changes (product price,
 * post status transitions, page saves, deletions) and schedules URLs Map
 * regeneration with a 60-second debounce window to prevent excessive rebuilds.
 *
 * Uses WordPress Transients API for debounce state and WP-Cron for
 * deferred execution. Handles errors gracefully without throwing exceptions.
 */
class BRZ_Static_Change_Trigger {

    /**
     * Transient key used for debounce tracking.
     */
    public const DEBOUNCE_TRANSIENT = 'brz_static_debounce';

    /**
     * Debounce window in seconds. Multiple change events within this
     * window are collapsed into a single scheduled regeneration.
     */
    public const DEBOUNCE_SECONDS = 60;

    /**
     * Retry delay in seconds. If a scheduled regeneration fails,
     * it is rescheduled once after this interval.
     */
    public const RETRY_SECONDS = 120;

    /**
     * Post meta key for storing content hash.
     *
     * Used to detect actual content changes by comparing MD5 hashes
     * of post_content + post_title + post_excerpt.
     */
    public const HASH_META_KEY = '_brz_static_content_hash';

    /**
     * Register all change detection hooks.
     *
     * Hooks into save_post, transition_post_status, before_delete_post,
     * and conditionally into WooCommerce price change hooks when WooCommerce
     * is active.
     */
    public static function init(): void {
        add_action( 'save_post', [ __CLASS__, 'on_save_post_enhanced' ], 10, 3 );
        add_action( 'transition_post_status', [ __CLASS__, 'on_post_transition' ], 10, 3 );
        add_action( 'before_delete_post', [ __CLASS__, 'on_delete_post' ], 10, 1 );

        // WooCommerce price change hooks — only if WooCommerce is active.
        if ( class_exists( 'WooCommerce' ) ) {
            add_action( 'woocommerce_product_set_regular_price', [ __CLASS__, 'on_price_change' ], 10, 1 );
            add_action( 'woocommerce_product_set_sale_price', [ __CLASS__, 'on_price_change' ], 10, 1 );
        }
    }

    /**
     * Handle product price change.
     *
     * Extracts the product ID from either an integer or a WC_Product object
     * and schedules regeneration.
     *
     * @param mixed $product Product ID (int) or WC_Product object.
     */
    public static function on_price_change( mixed $product ): void {
        try {
            if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
                // WC_Product object — extract ID (unused but validates the object).
                $product->get_id();
            }

            self::schedule_regeneration();
        } catch ( \Throwable ) {
            // Graceful degradation — do not throw exceptions to WordPress.
        }
    }

    /**
     * Handle post status transition.
     *
     * Only acts on 'product' post_type when transitioning to/from
     * 'publish' or 'trash' status.
     *
     * @param string   $new_status New post status.
     * @param string   $old_status Old post status.
     * @param \WP_Post $post       Post object.
     */
    public static function on_post_transition( string $new_status, string $old_status, \WP_Post $post ): void {
        try {
            // Only act on 'product' post_type.
            if ( $post->post_type !== 'product' ) {
                return;
            }

            // Only act when transitioning to/from 'publish' or 'trash'.
            $relevant_statuses = [ 'publish', 'trash' ];

            if (
                ! in_array( $new_status, $relevant_statuses, true ) &&
                ! in_array( $old_status, $relevant_statuses, true )
            ) {
                return;
            }

            self::schedule_regeneration();
        } catch ( \Throwable ) {
            // Graceful degradation — do not throw exceptions to WordPress.
        }
    }

    /**
     * Handle save_post for selected pages (legacy).
     *
     * Skips autosaves, revisions, and non-publish posts. Only triggers
     * regeneration if the saved post is in the selected_pages list.
     *
     * @deprecated Use on_save_post_enhanced() which includes content hash comparison.
     *
     * @param int      $post_id Post ID.
     * @param \WP_Post $post    Post object.
     * @param bool     $update  Whether this is an update (vs new post).
     */
    public static function on_save_post( int $post_id, \WP_Post $post, bool $update ): void {
        try {
            // Skip autosaves.
            if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
                return;
            }

            // Skip revisions.
            if ( wp_is_post_revision( $post_id ) ) {
                return;
            }

            // Skip non-publish posts.
            if ( $post->post_status !== 'publish' ) {
                return;
            }

            // Check if this post_id is in the selected_pages list.
            $settings       = BRZ_Static_Controller::get_settings();
            $selected_pages = $settings['selected_pages'] ?? [];

            $is_selected = false;
            foreach ( $selected_pages as $page_entry ) {
                $entry_id = (int) ( $page_entry['id'] ?? 0 );
                if ( $entry_id === $post_id ) {
                    $is_selected = true;
                    break;
                }
            }

            if ( $is_selected ) {
                self::schedule_regeneration();
            }
        } catch ( \Throwable ) {
            // Graceful degradation — do not throw exceptions to WordPress.
        }
    }

    /**
     * Enhanced save_post handler with content hash comparison.
     *
     * Compares MD5(post_content + post_title + post_excerpt) against the
     * previously stored hash. Only marks the page as "pending" if the content
     * has actually changed, preventing unnecessary regeneration cycles.
     *
     * Skips autosaves, revisions, and non-publish posts. Only acts on posts
     * that are in the selected_pages list.
     *
     * @param int      $post_id Post ID.
     * @param \WP_Post $post    Post object.
     * @param bool     $update  Whether this is an update (vs new post).
     */
    public static function on_save_post_enhanced( int $post_id, \WP_Post $post, bool $update ): void {
        try {
            // Skip autosaves.
            if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
                return;
            }

            // Skip revisions.
            if ( wp_is_post_revision( $post_id ) ) {
                return;
            }

            // Skip non-publish posts.
            if ( $post->post_status !== 'publish' ) {
                return;
            }

            // Check if this post_id is in the selected_pages list.
            $settings       = BRZ_Static_Controller::get_settings();
            $selected_pages = $settings['selected_pages'] ?? [];

            $is_selected = false;
            foreach ( $selected_pages as $page_entry ) {
                $entry_id = (int) ( $page_entry['id'] ?? 0 );
                if ( $entry_id === $post_id ) {
                    $is_selected = true;
                    break;
                }
            }

            if ( ! $is_selected ) {
                return;
            }

            // Compute current content hash.
            $current_hash = self::compute_content_hash( $post );

            // Get previously stored hash from post meta.
            $stored_hash = get_post_meta( $post_id, self::HASH_META_KEY, true );

            // Always store the current hash.
            update_post_meta( $post_id, self::HASH_META_KEY, $current_hash );

            // If hash is identical, content hasn't changed — skip.
            if ( $stored_hash === $current_hash ) {
                return;
            }

            // Content changed — mark page as pending and schedule regeneration.
            self::mark_page_pending_by_id( $post_id );
            self::schedule_regeneration_if_auto();
        } catch ( \Throwable ) {
            // Graceful degradation — do not throw exceptions to WordPress.
        }
    }

    /**
     * Compute content hash for change detection.
     *
     * Generates an MD5 hash of the concatenation of post_content,
     * post_title, and post_excerpt. Used to detect actual content
     * changes vs. metadata-only saves.
     *
     * @param \WP_Post $post Post object to hash.
     * @return string MD5 hash string (32 hex characters).
     */
    public static function compute_content_hash( \WP_Post $post ): string {
        $content = $post->post_content . $post->post_title . $post->post_excerpt;
        return md5( $content );
    }

    /**
     * Mark a page as "pending" by its URL.
     *
     * Finds the page in selected_pages by URL and sets its page_status
     * to "pending". Persists the change to brz_options.
     *
     * @param string $url The absolute URL of the page to mark.
     */
    public static function mark_page_pending( string $url ): void {
        try {
            $settings       = BRZ_Static_Controller::get_settings();
            $selected_pages = $settings['selected_pages'] ?? [];
            $changed        = false;

            foreach ( $selected_pages as &$page_entry ) {
                $entry_url = $page_entry['url'] ?? '';
                if ( $entry_url === $url && ( $page_entry['page_status'] ?? '' ) !== 'pending' ) {
                    $page_entry['page_status'] = 'pending';
                    $changed = true;
                    break;
                }
            }
            unset( $page_entry );

            if ( $changed ) {
                $settings['selected_pages'] = $selected_pages;
                self::persist_settings( $settings );
            }
        } catch ( \Throwable ) {
            // Graceful degradation — do not throw exceptions to WordPress.
        }
    }

    /**
     * Mark a page as "pending" by its post ID.
     *
     * Finds the page in selected_pages by post ID and sets its page_status
     * to "pending". Persists the change to brz_options.
     *
     * @param int $post_id The WordPress post ID of the page to mark.
     */
    public static function mark_page_pending_by_id( int $post_id ): void {
        try {
            $settings       = BRZ_Static_Controller::get_settings();
            $selected_pages = $settings['selected_pages'] ?? [];
            $changed        = false;

            foreach ( $selected_pages as &$page_entry ) {
                $entry_id = (int) ( $page_entry['id'] ?? 0 );
                if ( $entry_id === $post_id && ( $page_entry['page_status'] ?? '' ) !== 'pending' ) {
                    $page_entry['page_status'] = 'pending';
                    $changed = true;
                    break;
                }
            }
            unset( $page_entry );

            if ( $changed ) {
                $settings['selected_pages'] = $selected_pages;
                self::persist_settings( $settings );
            }
        } catch ( \Throwable ) {
            // Graceful degradation — do not throw exceptions to WordPress.
        }
    }

    /**
     * Get the count of pages with "pending" status.
     *
     * @return int Number of pages with page_status="pending".
     */
    public static function get_pending_count(): int {
        try {
            $settings       = BRZ_Static_Controller::get_settings();
            $selected_pages = $settings['selected_pages'] ?? [];

            $count = 0;
            foreach ( $selected_pages as $page_entry ) {
                if ( ( $page_entry['page_status'] ?? '' ) === 'pending' ) {
                    $count++;
                }
            }

            return $count;
        } catch ( \Throwable ) {
            return 0;
        }
    }

    /**
     * Schedule regeneration only if automatic regeneration is enabled.
     *
     * Checks the `auto_regenerate_enabled` setting before scheduling.
     * This respects the user's preference for manual vs. automatic regeneration.
     */
    public static function schedule_regeneration_if_auto(): void {
        try {
            $settings = BRZ_Static_Controller::get_settings();

            if ( ! empty( $settings['auto_regenerate_enabled'] ) ) {
                self::schedule_regeneration();
            }
        } catch ( \Throwable ) {
            // Graceful degradation — do not throw exceptions to WordPress.
        }
    }

    /**
     * Persist settings to brz_options.
     *
     * Helper method to save the static_controller settings array
     * back to the WordPress options table.
     *
     * @param array $settings The full settings array to persist.
     */
    private static function persist_settings( array $settings ): void {
        $options = get_option( 'brz_options', array() );
        if ( ! is_array( $options ) ) {
            $options = array();
        }
        $options[ BRZ_Static_Controller::OPTION_KEY ] = $settings;
        update_option( 'brz_options', $options );
    }

    /**
     * Handle post deletion.
     *
     * Only triggers regeneration for 'product' post_type deletions.
     *
     * @param int $post_id Post ID being deleted.
     */
    public static function on_delete_post( int $post_id ): void {
        try {
            $post = get_post( $post_id );

            if ( ! $post || $post->post_type !== 'product' ) {
                return;
            }

            self::schedule_regeneration();
        } catch ( \Throwable ) {
            // Graceful degradation — do not throw exceptions to WordPress.
        }
    }

    /**
     * Schedule regeneration with debounce logic.
     *
     * Uses a transient to implement a 60-second debounce window.
     * If the transient exists, a regeneration is already pending.
     * If no event is scheduled, creates a new single scheduled event.
     */
    public static function schedule_regeneration(): void {
        try {
            // Check debounce transient — if exists, already debouncing.
            if ( get_transient( self::DEBOUNCE_TRANSIENT ) ) {
                return;
            }

            // Set debounce transient with TTL.
            set_transient( self::DEBOUNCE_TRANSIENT, 1, self::DEBOUNCE_SECONDS );

            // Check if regeneration is already scheduled.
            if ( self::is_scheduled() ) {
                return;
            }

            // Schedule regeneration after debounce window.
            wp_schedule_single_event(
                time() + self::DEBOUNCE_SECONDS,
                BRZ_Static_Controller::CRON_HOOK
            );
        } catch ( \Throwable ) {
            // Graceful degradation — do not throw exceptions to WordPress.
        }
    }

    /**
     * Check if regeneration is already scheduled.
     *
     * @return bool True if a regeneration event is pending.
     */
    private static function is_scheduled(): bool {
        return (bool) wp_next_scheduled( BRZ_Static_Controller::CRON_HOOK );
    }

    /**
     * Cleanup: remove all scheduled events for this module.
     *
     * Unschedules all instances of CRON_HOOK and BATCH_HOOK,
     * deletes the debounce transient, and removes all batch transients.
     */
    public static function cleanup_scheduled_events(): void {
        try {
            // Unschedule all instances of CRON_HOOK.
            $timestamp = wp_next_scheduled( BRZ_Static_Controller::CRON_HOOK );
            while ( $timestamp ) {
                wp_unschedule_event( $timestamp, BRZ_Static_Controller::CRON_HOOK );
                $timestamp = wp_next_scheduled( BRZ_Static_Controller::CRON_HOOK );
            }

            // Unschedule all instances of BATCH_HOOK.
            $timestamp = wp_next_scheduled( BRZ_Static_Controller::BATCH_HOOK );
            while ( $timestamp ) {
                wp_unschedule_event( $timestamp, BRZ_Static_Controller::BATCH_HOOK );
                $timestamp = wp_next_scheduled( BRZ_Static_Controller::BATCH_HOOK );
            }

            // Delete debounce transient.
            delete_transient( self::DEBOUNCE_TRANSIENT );

            // Delete all batch transients matching 'brz_static_batch_*' pattern.
            global $wpdb;
            $prefix       = '_transient_brz_static_batch_';
            $timeout_prefix = '_transient_timeout_brz_static_batch_';

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                    $wpdb->esc_like( $prefix ) . '%',
                    $wpdb->esc_like( $timeout_prefix ) . '%'
                )
            );
        } catch ( \Throwable ) {
            // Graceful degradation — log and continue without throwing.
            error_log( '[BRZ Static Controller] Error during scheduled events cleanup.' );
        }
    }
}
