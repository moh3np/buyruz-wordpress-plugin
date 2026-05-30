<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
// هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید و بی‌قید و شرط اجرا کنید و پس از اتمام کار تطابق را دوباره چک کنید.

/**
 * Static Controller module.
 *
 * Manages selected pages, page type detection, URLs Map generation,
 * and change-triggered regeneration for the hybrid static generator system.
 * Follows the static class pattern used by other buyruz-plugin modules.
 */
class BRZ_Static_Controller {

    const OPTION_KEY  = 'static_controller';
    const CRON_HOOK   = 'brz_static_regenerate_map';
    const BATCH_HOOK  = 'brz_static_batch_generate';

    /**
     * Bootstrap hooks.
     * Registers admin assets, AJAX handlers, and cron actions.
     * Ensures ZERO frontend hooks — all admin hooks are inside is_admin() guard.
     */
    public static function init(): void {
        // Activation guard: check PHP version requirement.
        if ( version_compare( PHP_VERSION, '8.3', '<' ) ) {
            add_action( 'admin_notices', function() {
                echo '<div class="notice notice-error"><p>';
                echo esc_html( 'ماژول کنترلر استاتیک نیاز به PHP نسخه ۸.۳ یا بالاتر دارد.' );
                echo '</p></div>';
            } );
            return;
        }

        // Activation guard: check WordPress version requirement.
        global $wp_version;
        if ( version_compare( $wp_version, '6.8.3', '<' ) ) {
            add_action( 'admin_notices', function() {
                echo '<div class="notice notice-error"><p>';
                echo esc_html( 'ماژول کنترلر استاتیک نیاز به وردپرس نسخه ۶.۸.۳ یا بالاتر دارد.' );
                echo '</p></div>';
            } );
            return;
        }

        // Admin-only hooks (no frontend impact).
        if ( is_admin() ) {
            add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );

            // Admin notices for sync/regeneration results.
            add_action( 'admin_notices', array( __CLASS__, 'display_admin_notices' ) );

            // AJAX handlers.
            add_action( 'wp_ajax_brz_static_search_pages', array( __CLASS__, 'ajax_search_pages' ) );
            add_action( 'wp_ajax_brz_static_save_pages', array( __CLASS__, 'ajax_save_selected_pages' ) );
            add_action( 'wp_ajax_brz_static_save_settings', array( __CLASS__, 'ajax_save_settings' ) );
            add_action( 'wp_ajax_brz_static_regenerate', array( __CLASS__, 'ajax_manual_regenerate' ) );
            add_action( 'wp_ajax_brz_static_get_settings', array( __CLASS__, 'ajax_get_settings' ) );

            // New AJAX handlers (UI upgrade).
            add_action( 'wp_ajax_brz_static_sitemap_sync', array( __CLASS__, 'ajax_sitemap_sync' ) );
            add_action( 'wp_ajax_brz_static_sitemap_confirm_import', array( __CLASS__, 'ajax_sitemap_confirm_import' ) );
            add_action( 'wp_ajax_brz_static_add_manual_page', array( __CLASS__, 'ajax_add_manual_page' ) );
            add_action( 'wp_ajax_brz_static_remove_manual_page', array( __CLASS__, 'ajax_remove_manual_page' ) );
            add_action( 'wp_ajax_brz_static_get_dashboard', array( __CLASS__, 'ajax_get_dashboard' ) );
            add_action( 'wp_ajax_brz_static_regenerate_pending', array( __CLASS__, 'ajax_regenerate_pending' ) );
            add_action( 'wp_ajax_brz_static_get_pages', array( __CLASS__, 'ajax_get_pages' ) );
            add_action( 'wp_ajax_brz_static_bulk_action', array( __CLASS__, 'ajax_bulk_action' ) );

            // AJAX handler for dismissing admin notices.
            add_action( 'wp_ajax_brz_static_dismiss_notice', array( __CLASS__, 'ajax_dismiss_notice' ) );

            // Change trigger hooks (admin context for save_post, etc.).
            BRZ_Static_Change_Trigger::init();
        }

        // Cron action hooks (always register so WP-Cron can fire them).
        add_action( BRZ_Static_Controller::CRON_HOOK, array( 'BRZ_Static_Map_Generator', 'generate' ) );
        add_action( BRZ_Static_Controller::BATCH_HOOK, array( 'BRZ_Static_Map_Generator', 'generate_batch' ), 10, 2 );

        // Sitemap importer cron hooks.
        add_action( 'brz_static_daily_sitemap_sync', array( 'BRZ_Static_Sitemap_Importer', 'auto_sync' ) );
        add_action( 'brz_static_sitemap_retry', array( 'BRZ_Static_Sitemap_Importer', 'handle_retry' ) );
        add_action( 'brz_static_batch_import', array( 'BRZ_Static_Sitemap_Importer', 'execute_batch_import' ), 10, 2 );
    }

    /**
     * Display admin notices for sync/regeneration results.
     *
     * Checks for:
     * 1. brz_static_sync_failed_notice transient — persistent error notice when auto-sync fails
     * 2. Pending pages count when auto_regenerate is disabled — info notice with pending count
     *
     * Notices are dismissible via AJAX (suppressed for 24h via transient).
     */
    public static function display_admin_notices(): void {
        // Only show on our module's page or the main admin dashboard.
        $screen = get_current_screen();
        if ( ! $screen ) {
            return;
        }

        // Check if the notice has been dismissed (suppressed for 24h).
        $dismissed = get_transient( 'brz_static_notice_dismissed' );

        // 1. Check for sync failure notice (persistent error).
        $sync_failed = get_transient( 'brz_static_sync_failed_notice' );
        if ( $sync_failed && is_array( $sync_failed ) && ! $dismissed ) {
            $message = $sync_failed['message'] ?? 'خطا در همگام‌سازی خودکار سایت‌مپ.';
            ?>
            <div class="notice notice-error is-dismissible brz-static-admin-notice" data-notice-type="sync_failed">
                <p>
                    <strong>کنترلر استاتیک:</strong>
                    <?php echo esc_html( $message ); ?>
                </p>
                <button type="button" class="notice-dismiss brz-static-notice-dismiss" data-notice="sync_failed">
                    <span class="screen-reader-text">بستن این اعلان</span>
                </button>
            </div>
            <script>
            jQuery(function($) {
                $('.brz-static-notice-dismiss[data-notice="sync_failed"]').on('click', function() {
                    $.post(ajaxurl, {
                        action: 'brz_static_dismiss_notice',
                        notice_type: 'sync_failed',
                        _ajax_nonce: '<?php echo esc_js( wp_create_nonce( 'brz_static_dismiss_notice' ) ); ?>'
                    });
                    $(this).closest('.notice').fadeOut();
                });
            });
            </script>
            <?php
        }

        // 2. Check for pending pages when auto_regenerate is disabled.
        if ( ! $dismissed ) {
            $settings      = self::get_settings();
            $auto_regen    = ! empty( $settings['auto_regenerate_enabled'] );
            $pending_count = BRZ_Static_Change_Trigger::get_pending_count();

            if ( ! $auto_regen && $pending_count > 0 ) {
                ?>
                <div class="notice notice-warning is-dismissible brz-static-admin-notice" data-notice-type="pending_pages">
                    <p>
                        <strong>کنترلر استاتیک:</strong>
                        <?php
                        echo esc_html( sprintf(
                            '%d صفحه در انتظار بازسازی هستند. بازسازی خودکار غیرفعال است.',
                            $pending_count
                        ) );
                        ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=buyruz-module-static_controller' ) ); ?>" class="button button-small" style="margin-right: 10px;">
                            بازسازی دستی
                        </a>
                    </p>
                    <button type="button" class="notice-dismiss brz-static-notice-dismiss" data-notice="pending_pages">
                        <span class="screen-reader-text">بستن این اعلان</span>
                    </button>
                </div>
                <script>
                jQuery(function($) {
                    $('.brz-static-notice-dismiss[data-notice="pending_pages"]').on('click', function() {
                        $.post(ajaxurl, {
                            action: 'brz_static_dismiss_notice',
                            notice_type: 'pending_pages',
                            _ajax_nonce: '<?php echo esc_js( wp_create_nonce( 'brz_static_dismiss_notice' ) ); ?>'
                        });
                        $(this).closest('.notice').fadeOut();
                    });
                });
                </script>
                <?php
            }
        }
    }

    /**
     * AJAX handler: Dismiss admin notice.
     *
     * Sets a transient to suppress notices for 24 hours.
     * Also clears the sync_failed transient if that notice is being dismissed.
     *
     * @return void Sends JSON response and terminates.
     */
    public static function ajax_dismiss_notice(): void {
        check_ajax_referer( 'brz_static_dismiss_notice' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز.' ), 403 );
        }

        $notice_type = isset( $_POST['notice_type'] ) ? sanitize_text_field( wp_unslash( $_POST['notice_type'] ) ) : '';

        // Suppress notices for 24 hours.
        set_transient( 'brz_static_notice_dismissed', 1, DAY_IN_SECONDS );

        // If dismissing the sync_failed notice, also clear the persistent transient.
        if ( $notice_type === 'sync_failed' ) {
            delete_transient( 'brz_static_sync_failed_notice' );
        }

        wp_send_json_success( array( 'dismissed' => true ) );
    }

    /**
     * Cleanup when module is deactivated.
     * Removes all scheduled events and transient data.
     */
    public static function deactivate_cleanup(): void {
        try {
            BRZ_Static_Change_Trigger::cleanup_scheduled_events();
        } catch ( \Throwable ) {
            error_log( '[BRZ Static Controller] Error during change trigger cleanup.' );
        }

        try {
            BRZ_Static_Sitemap_Importer::unschedule_daily_sync();
        } catch ( \Throwable ) {
            error_log( '[BRZ Static Controller] Error during sitemap sync cleanup.' );
        }
    }

    /**
     * Default settings for the module.
     *
     * @return array Default configuration values.
     */
    private static function default_settings(): array {
        return array(
            'selected_pages'         => array(),
            'output_path'            => '/static-data/urls-map.json',
            'modal_global'           => '',
            'modal_per_page'         => array(),
            'last_generated'         => null,
            'generation_status'      => 'idle',
            'sitemap_url'            => '',
            'auto_sync_enabled'      => false,
            'auto_regenerate_enabled'=> false,
            'notify_on_sync'         => false,
            'last_sync_timestamp'    => null,
            'sitemap_stored_state'   => array(
                'urls'       => array(),
                'updated_at' => null,
                'url_count'  => 0,
            ),
            'regeneration_history'   => array(),
            'sync_retry_count'       => 0,
        );
    }

    /**
     * Get module settings with defensive defaults.
     *
     * Merges stored settings with defaults for backward compatibility.
     * Also migrates existing page entries to include new fields.
     *
     * @return array Settings array merged with defaults.
     */
    public static function get_settings(): array {
        $options  = get_option( 'brz_options', array() );
        $settings = isset( $options[ self::OPTION_KEY ] ) && is_array( $options[ self::OPTION_KEY ] )
            ? $options[ self::OPTION_KEY ]
            : array();

        $parsed_settings = wp_parse_args( $settings, self::default_settings() );

        // If the bad default was saved in the database, override it.
        if ( isset( $parsed_settings['output_path'] ) && $parsed_settings['output_path'] === '/home/user/static-data/urls-map.json' ) {
            $parsed_settings['output_path'] = '/static-data/urls-map.json';
        }

        // Ensure sitemap_stored_state has the correct structure.
        if ( ! is_array( $parsed_settings['sitemap_stored_state'] ) ) {
            $parsed_settings['sitemap_stored_state'] = self::default_settings()['sitemap_stored_state'];
        }

        // Migrate existing page entries to include new fields.
        if ( ! empty( $parsed_settings['selected_pages'] ) && is_array( $parsed_settings['selected_pages'] ) ) {
            $parsed_settings['selected_pages'] = array_map(
                array( __CLASS__, 'migrate_page_entry' ),
                $parsed_settings['selected_pages']
            );
        }

        return $parsed_settings;
    }

    /**
     * Migrate a page entry to include all required fields.
     *
     * Adds missing fields (url, page_type, page_source, page_status, lastmod,
     * error_count, content_hash) to existing page entries for backward compatibility.
     *
     * @param array $entry The page entry to migrate.
     * @return array The migrated page entry with all required fields.
     */
    private static function migrate_page_entry( array $entry ): array {
        $defaults = array(
            'url'          => '',
            'page_type'    => 'unknown',
            'page_source'  => 'manual',
            'page_status'  => 'pending',
            'lastmod'      => null,
            'error_count'  => 0,
            'content_hash' => null,
        );

        // If the entry has an 'id' and 'type' but no 'url', attempt to derive the URL.
        if ( ! empty( $entry['id'] ) && empty( $entry['url'] ) ) {
            if ( isset( $entry['type'] ) && 'term' === $entry['type'] && ! empty( $entry['taxonomy'] ) ) {
                $term_link = get_term_link( (int) $entry['id'], $entry['taxonomy'] );
                if ( ! is_wp_error( $term_link ) ) {
                    $entry['url'] = $term_link;
                }
            } elseif ( isset( $entry['type'] ) && in_array( $entry['type'], array( 'post', 'page', 'product' ), true ) ) {
                $permalink = get_permalink( (int) $entry['id'] );
                if ( $permalink ) {
                    $entry['url'] = $permalink;
                }
            }
        }

        return wp_parse_args( $entry, $defaults );
    }

    /**
     * Enqueue admin CSS and JS on the static controller settings page only.
     *
     * @param string $hook_suffix The current admin page hook suffix.
     */
    public static function enqueue_admin_assets( string $hook_suffix ): void {
        // Only load on our module's page.
        if ( false === strpos( $hook_suffix, 'buyruz-module-static_controller' ) ) {
            return;
        }


        $assets_url = plugin_dir_url( __FILE__ ) . 'assets/';

        wp_enqueue_style(
            'brz-static-controller-admin-css',
            $assets_url . 'static-controller-admin.css',
            array(),
            BRZ_VERSION
        );

        wp_enqueue_script(
            'brz-static-controller-admin-js',
            $assets_url . 'static-controller-admin.js',
            array( 'jquery' ),
            BRZ_VERSION,
            true
        );

        wp_localize_script( 'brz-static-controller-admin-js', 'brz_static', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'brz_static_nonce' ),
            'strings'  => array(
                'save_success'         => 'تنظیمات ذخیره شد',
                'save_error'           => 'خطا در ذخیره تنظیمات',
                'search_empty'         => 'نتیجه‌ای یافت نشد',
                'regenerate_ok'        => 'بازسازی نقشه URL زمان‌بندی شد',
                'regenerate_err'       => 'خطا در زمان‌بندی بازسازی',
                'loading'              => 'در حال بارگذاری...',
                'network_error'        => 'خطای شبکه. لطفاً دوباره تلاش کنید.',
                'tab_dashboard'        => 'داشبورد',
                'tab_sitemap'          => 'صفحات سایت‌مپ',
                'tab_manual'           => 'صفحات دستی',
                'tab_settings'         => 'تنظیمات',
                'sync_btn'             => 'همگام‌سازی سایت‌مپ',
                'regenerate_pending_btn' => 'بازسازی صفحات تغییریافته',
                'add_url_placeholder'  => 'https://buyruz.com/your-page/',
                'add_url_btn'          => 'افزودن',
                'filter_all'           => 'همه',
                'filter_type_label'    => 'نوع صفحه',
                'filter_status_label'  => 'وضعیت',
                'bulk_mark_pending'    => 'علامت‌گذاری به عنوان در انتظار',
                'bulk_remove'          => 'حذف',
                'bulk_reset_error'     => 'بازنشانی خطا',
                'confirm_remove_title' => 'تأیید حذف',
                'confirm_remove_msg'   => 'آیا از حذف موارد انتخاب‌شده اطمینان دارید؟',
                'status_healthy'       => 'سالم',
                'status_attention'     => 'نیاز به توجه',
                'status_error'         => 'خطا',
            ),
        ) );
    }

    /**
     * Save module settings to brz_options.
     *
     * @param array $settings Settings array to persist.
     */
    private static function save_settings( array $settings ): void {
        $options = get_option( 'brz_options', array() );
        if ( ! is_array( $options ) ) {
            $options = array();
        }
        $options[ self::OPTION_KEY ] = $settings;
        update_option( 'brz_options', $options );
    }

    /**
     * AJAX handler: Search published pages, products, and taxonomy terms.
     *
     * Searches post titles (post types: post, page, product) and taxonomy terms
     * (product_cat, product_brand, product_tag, category) matching the search query.
     * Returns paginated results with detected page_type for each item.
     *
     * @return void Sends JSON response and terminates.
     */
    public static function ajax_search_pages(): void {
        check_ajax_referer( 'brz_static_nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز.' ), 403 );
        }

        $search   = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
        $page     = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;
        $per_page = isset( $_POST['per_page'] ) ? absint( $_POST['per_page'] ) : 50;

        if ( $page < 1 ) {
            $page = 1;
        }
        if ( $per_page < 1 || $per_page > 100 ) {
            $per_page = 50;
        }

        $items = array();

        // Query published posts (post, page, product).
        $post_types = array( 'post', 'page', 'product' );
        $post_args  = array(
            'post_type'      => $post_types,
            'post_status'    => 'publish',
            's'              => $search,
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        );

        $posts_query = new \WP_Query( $post_args );

        if ( $posts_query->have_posts() ) {
            foreach ( $posts_query->posts as $post ) {
                $items[] = array(
                    'id'        => $post->ID,
                    'title'     => $post->post_title,
                    'url'       => get_permalink( $post->ID ),
                    'type'      => 'post',
                    'page_type' => BRZ_Static_Page_Detector::detect( $post->ID ),
                );
            }
        }

        // Query taxonomy terms.
        $taxonomies = array( 'product_cat', 'product_brand', 'product_tag', 'category' );
        // Filter to only registered taxonomies.
        $taxonomies = array_filter( $taxonomies, 'taxonomy_exists' );

        if ( ! empty( $taxonomies ) ) {
            $term_args = array(
                'taxonomy'   => $taxonomies,
                'search'     => $search,
                'hide_empty' => false,
            );

            $terms = get_terms( $term_args );

            if ( ! is_wp_error( $terms ) && is_array( $terms ) ) {
                foreach ( $terms as $term ) {
                    $term_link = get_term_link( $term );
                    $items[]   = array(
                        'id'        => $term->term_id,
                        'title'     => $term->name,
                        'url'       => is_wp_error( $term_link ) ? '' : $term_link,
                        'type'      => 'term',
                        'taxonomy'  => $term->taxonomy,
                        'page_type' => BRZ_Static_Page_Detector::detect_term( $term->term_id, $term->taxonomy ),
                    );
                }
            }
        }

        // Paginate results.
        $total       = count( $items );
        $total_pages = (int) ceil( $total / $per_page );
        $offset      = ( $page - 1 ) * $per_page;
        $paged_items = array_slice( $items, $offset, $per_page );

        wp_send_json_success( array(
            'items' => $paged_items,
            'total' => $total,
            'pages' => $total_pages,
        ) );
    }

    /**
     * AJAX handler: Save selected pages.
     *
     * Receives a JSON-encoded array of selected pages, validates each entry
     * has 'id' and 'type' keys, and persists to settings.
     *
     * @return void Sends JSON response and terminates.
     */
    public static function ajax_save_selected_pages(): void {
        check_ajax_referer( 'brz_static_nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز.' ), 403 );
        }

        $selected_raw = isset( $_POST['selected'] ) ? wp_unslash( $_POST['selected'] ) : '[]';
        $selected     = json_decode( $selected_raw, true );

        if ( ! is_array( $selected ) ) {
            wp_send_json_error( array( 'message' => 'فرمت داده‌های ارسالی نامعتبر است.' ) );
        }

        // Validate each entry has 'id' and 'type' keys.
        $validated = array();
        foreach ( $selected as $entry ) {
            if ( ! is_array( $entry ) || ! isset( $entry['id'] ) || ! isset( $entry['type'] ) ) {
                continue;
            }

            $clean_entry = array(
                'id'   => absint( $entry['id'] ),
                'type' => sanitize_text_field( $entry['type'] ),
            );

            // Include taxonomy if present (for term type).
            if ( isset( $entry['taxonomy'] ) ) {
                $clean_entry['taxonomy'] = sanitize_text_field( $entry['taxonomy'] );
            }

            $validated[] = $clean_entry;
        }

        $settings                   = self::get_settings();
        $settings['selected_pages'] = $validated;
        self::save_settings( $settings );

        wp_send_json_success( array( 'count' => count( $validated ) ) );
    }

    /**
     * AJAX handler: Save module settings (output path and modal code).
     *
     * Validates output_path (absolute, valid chars, ≤255 chars) and modal code
     * (via BRZ_Static_Modal_Injector::validate()). Saves all valid settings.
     *
     * @return void Sends JSON response and terminates.
     */
    public static function ajax_save_settings(): void {
        check_ajax_referer( 'brz_static_nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز.' ), 403 );
        }

        $settings = self::get_settings();

        // Validate and save output_path.
        if ( isset( $_POST['output_path'] ) ) {
            $output_path     = sanitize_text_field( wp_unslash( $_POST['output_path'] ) );
            $path_validation = self::validate_output_path( $output_path );

            if ( is_wp_error( $path_validation ) ) {
                wp_send_json_error( array( 'message' => $path_validation->get_error_message() ) );
            }

            $settings['output_path'] = $output_path;
        }

        // Validate and save modal_global.
        if ( isset( $_POST['modal_global'] ) ) {
            $modal_global     = wp_unslash( $_POST['modal_global'] );
            $modal_validation = BRZ_Static_Modal_Injector::validate( $modal_global );

            if ( is_wp_error( $modal_validation ) ) {
                wp_send_json_error( array( 'message' => $modal_validation->get_error_message() ) );
            }

            $settings['modal_global'] = $modal_global;
        }

        // Validate and save sitemap_url.
        if ( isset( $_POST['sitemap_url'] ) ) {
            $sitemap_url = sanitize_text_field( wp_unslash( $_POST['sitemap_url'] ) );

            if ( ! empty( $sitemap_url ) ) {
                // Validate: must be HTTP or HTTPS.
                if ( ! preg_match( '#^https?://#i', $sitemap_url ) ) {
                    wp_send_json_error( array( 'message' => 'آدرس سایت‌مپ باید با http:// یا https:// شروع شود.' ) );
                }

                // Validate: max 2048 characters.
                if ( strlen( $sitemap_url ) > 2048 ) {
                    wp_send_json_error( array( 'message' => 'آدرس سایت‌مپ نمی‌تواند بیشتر از ۲۰۴۸ کاراکتر باشد.' ) );
                }

                // Validate: valid URL structure.
                if ( ! filter_var( $sitemap_url, FILTER_VALIDATE_URL ) ) {
                    wp_send_json_error( array( 'message' => 'فرمت آدرس سایت‌مپ نامعتبر است.' ) );
                }
            }

            $settings['sitemap_url'] = $sitemap_url;
        }

        // Handle auto_sync toggle.
        if ( isset( $_POST['auto_sync'] ) ) {
            $new_auto_sync = filter_var( wp_unslash( $_POST['auto_sync'] ), FILTER_VALIDATE_BOOLEAN );
            $old_auto_sync = ! empty( $settings['auto_sync_enabled'] );

            $settings['auto_sync_enabled'] = $new_auto_sync;

            // Schedule or unschedule daily sync based on toggle change.
            if ( $new_auto_sync && ! $old_auto_sync ) {
                BRZ_Static_Sitemap_Importer::schedule_daily_sync();
            } elseif ( ! $new_auto_sync && $old_auto_sync ) {
                BRZ_Static_Sitemap_Importer::unschedule_daily_sync();
            }
        }

        // Handle auto_regenerate toggle.
        if ( isset( $_POST['auto_regenerate'] ) ) {
            $settings['auto_regenerate_enabled'] = filter_var( wp_unslash( $_POST['auto_regenerate'] ), FILTER_VALIDATE_BOOLEAN );
        }

        // Handle notify_on_sync toggle.
        if ( isset( $_POST['notify_on_sync'] ) ) {
            $settings['notify_on_sync'] = filter_var( wp_unslash( $_POST['notify_on_sync'] ), FILTER_VALIDATE_BOOLEAN );
        }

        // Validate and save modal_per_page.
        if ( isset( $_POST['modal_per_page'] ) ) {
            $modal_per_page_raw = wp_unslash( $_POST['modal_per_page'] );
            $modal_per_page     = json_decode( $modal_per_page_raw, true );

            if ( ! is_array( $modal_per_page ) ) {
                wp_send_json_error( array( 'message' => 'فرمت داده‌های مودال اختصاصی نامعتبر است.' ) );
            }

            $validated_per_page = array();
            foreach ( $modal_per_page as $page_id => $code ) {
                $code_validation = BRZ_Static_Modal_Injector::validate( (string) $code );

                if ( is_wp_error( $code_validation ) ) {
                    wp_send_json_error( array(
                        'message' => sprintf(
                            'خطا در کد مودال صفحه %d: %s',
                            (int) $page_id,
                            $code_validation->get_error_message()
                        ),
                    ) );
                }

                $validated_per_page[ (int) $page_id ] = (string) $code;
            }

            $settings['modal_per_page'] = $validated_per_page;
        }

        self::save_settings( $settings );

        wp_send_json_success( array( 'message' => 'تنظیمات با موفقیت ذخیره شد.' ) );
    }

    /**
     * AJAX handler: Manually trigger URLs Map regeneration.
     *
     * Calls BRZ_Static_Change_Trigger::schedule_regeneration() to schedule
     * a regeneration event via WP-Cron.
     *
     * @return void Sends JSON response and terminates.
     */
    public static function ajax_manual_regenerate(): void {
        check_ajax_referer( 'brz_static_nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز.' ), 403 );
        }

        BRZ_Static_Change_Trigger::schedule_regeneration();

        wp_send_json_success( array( 'scheduled' => true ) );
    }

    /**
     * AJAX handler: Get module settings to populate the form on load.
     * Prevents WAF from blocking HTML responses containing paths or scripts.
     *
     * @return void Sends JSON response and terminates.
     */
    public static function ajax_get_settings(): void {
        check_ajax_referer( 'brz_static_nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز.' ), 403 );
        }

        $settings = self::get_settings();
        
        wp_send_json_success( array(
            'output_path'             => $settings['output_path'],
            'modal_global'            => $settings['modal_global'],
            'last_generated'          => $settings['last_generated'],
            'generation_status'       => $settings['generation_status'],
            'sitemap_url'             => $settings['sitemap_url'] ?? '',
            'auto_sync_enabled'       => ! empty( $settings['auto_sync_enabled'] ),
            'auto_regenerate_enabled' => ! empty( $settings['auto_regenerate_enabled'] ),
            'notify_on_sync'          => ! empty( $settings['notify_on_sync'] ),
        ) );
    }

    /**
     * AJAX handler: Trigger sitemap fetch and return grouped preview data.
     *
     * Calls BRZ_Static_Sitemap_Importer::fetch_and_parse() to fetch the sitemap,
     * then preview_import() to group URLs by prefix. Returns the grouped preview
     * data for admin confirmation before import.
     *
     * @return void Sends JSON response and terminates.
     */
    public static function ajax_sitemap_sync(): void {
        check_ajax_referer( 'brz_static_nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز.' ), 403 );
        }

        $urls = BRZ_Static_Sitemap_Importer::fetch_and_parse();

        if ( is_wp_error( $urls ) ) {
            wp_send_json_error( array( 'message' => $urls->get_error_message() ) );
        }

        $preview = BRZ_Static_Sitemap_Importer::preview_import( $urls );

        wp_send_json_success( $preview );
    }

    /**
     * AJAX handler: Execute confirmed sitemap import.
     *
     * Reads a JSON-encoded array of URLs from POST data. If the count exceeds
     * 5000, uses batch import via WP-Cron. Otherwise, executes a single-pass import.
     * Returns import statistics (imported, updated, skipped, total).
     *
     * @return void Sends JSON response and terminates.
     */
    public static function ajax_sitemap_confirm_import(): void {
        check_ajax_referer( 'brz_static_nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز.' ), 403 );
        }

        $urls_raw = isset( $_POST['urls'] ) ? wp_unslash( $_POST['urls'] ) : '';
        $urls     = json_decode( $urls_raw, true );

        if ( ! is_array( $urls ) ) {
            wp_send_json_error( array( 'message' => 'فرمت داده‌های ارسالی نامعتبر است.' ) );
        }

        if ( count( $urls ) > 5000 ) {
            $result = BRZ_Static_Sitemap_Importer::execute_batch_import( $urls );
        } else {
            $result = BRZ_Static_Sitemap_Importer::execute_import( $urls );
        }

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( $result );
    }

    /**
     * AJAX handler: Add a manual URL to selected pages.
     *
     * Reads the URL from POST data, validates and adds it via
     * BRZ_Static_Manual_Page_Manager::add_url(). On success, returns
     * the newly created page entry data.
     *
     * @return void Sends JSON response and terminates.
     */
    public static function ajax_add_manual_page(): void {
        check_ajax_referer( 'brz_static_nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز.' ), 403 );
        }

        $url = isset( $_POST['url'] ) ? sanitize_text_field( wp_unslash( $_POST['url'] ) ) : '';

        if ( empty( $url ) ) {
            wp_send_json_error( array( 'message' => 'URL نمی‌تواند خالی باشد.' ) );
        }

        $result = BRZ_Static_Manual_Page_Manager::add_url( $url );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        // Retrieve the newly added page entry from settings.
        $settings       = self::get_settings();
        $selected_pages = $settings['selected_pages'] ?? array();
        $page_entry     = null;

        // Find the entry we just added (last entry matching this URL).
        foreach ( array_reverse( $selected_pages ) as $entry ) {
            if ( isset( $entry['url'] ) && $entry['url'] === $url ) {
                $page_entry = $entry;
                break;
            }
        }

        wp_send_json_success( array( 'page' => $page_entry ) );
    }

    /**
     * AJAX handler: Remove a manual URL from selected pages.
     *
     * Reads the URL from POST data and removes it via
     * BRZ_Static_Manual_Page_Manager::remove_url(). Returns success or error.
     *
     * @return void Sends JSON response and terminates.
     */
    public static function ajax_remove_manual_page(): void {
        check_ajax_referer( 'brz_static_nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز.' ), 403 );
        }

        $url = isset( $_POST['url'] ) ? sanitize_text_field( wp_unslash( $_POST['url'] ) ) : '';

        if ( empty( $url ) ) {
            wp_send_json_error( array( 'message' => 'URL نمی‌تواند خالی باشد.' ) );
        }

        $result = BRZ_Static_Manual_Page_Manager::remove_url( $url );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array( 'removed' => true ) );
    }

    /**
     * AJAX handler: Return dashboard summary data.
     *
     * Computes and returns: total_pages, pending_count, error_count,
     * last_sync, last_generated, system_status, auto_sync_enabled,
     * auto_regenerate_enabled, and regeneration_history (last 5 entries).
     *
     * @return void Sends JSON response and terminates.
     */
    public static function ajax_get_dashboard(): void {
        check_ajax_referer( 'brz_static_nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز.' ), 403 );
        }

        $settings       = self::get_settings();
        $selected_pages = $settings['selected_pages'] ?? array();

        $total_pages   = count( $selected_pages );
        $pending_count = BRZ_Static_Change_Trigger::get_pending_count();

        // Count pages with status "error".
        $error_count = 0;
        foreach ( $selected_pages as $page ) {
            if ( ( $page['page_status'] ?? '' ) === 'error' ) {
                $error_count++;
            }
        }

        // Determine system_status.
        $generation_status = $settings['generation_status'] ?? 'idle';
        if ( $generation_status === 'error' ) {
            $system_status = 'error';
        } elseif ( $pending_count > 0 || $error_count > 0 ) {
            $system_status = 'attention';
        } else {
            $system_status = 'healthy';
        }

        // Get regeneration history (last 5 entries).
        $full_history = BRZ_Static_Map_Generator::get_regeneration_history();
        $history      = array_slice( $full_history, 0, 5 );

        wp_send_json_success( array(
            'total_pages'             => $total_pages,
            'pending_count'           => $pending_count,
            'error_count'             => $error_count,
            'last_sync'               => $settings['last_sync_timestamp'] ?? null,
            'last_generated'          => $settings['last_generated'] ?? null,
            'system_status'           => $system_status,
            'auto_sync_enabled'       => ! empty( $settings['auto_sync_enabled'] ),
            'auto_regenerate_enabled' => ! empty( $settings['auto_regenerate_enabled'] ),
            'regeneration_history'    => $history,
        ) );
    }

    /**
     * AJAX handler: Regenerate only pending pages.
     *
     * Calls BRZ_Static_Map_Generator::generate_pending_only() to regenerate
     * the URLs Map for pending/error pages, then records the regeneration event.
     * Returns the processed count and timestamp.
     *
     * @return void Sends JSON response and terminates.
     */
    public static function ajax_regenerate_pending(): void {
        check_ajax_referer( 'brz_static_nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز.' ), 403 );
        }

        // Get pending count before regeneration.
        $pending_count = BRZ_Static_Change_Trigger::get_pending_count();

        $result = BRZ_Static_Map_Generator::generate_pending_only();

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        // Record the regeneration event.
        BRZ_Static_Map_Generator::record_regeneration( 'manual', $pending_count );

        wp_send_json_success( array(
            'processed' => $pending_count,
            'timestamp' => gmdate( 'c' ),
        ) );
    }

    /**
     * AJAX handler: Return paginated, filtered page list.
     *
     * Supports filtering by tab (sitemap/manual/all), page_type, page_source,
     * page_status, and search (case-insensitive match against url and page_type).
     * Returns paginated results with total count and total pages.
     *
     * @return void Sends JSON response and terminates.
     */
    public static function ajax_get_pages(): void {
        check_ajax_referer( 'brz_static_nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز.' ), 403 );
        }

        // Read and sanitize parameters.
        $page          = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;
        $per_page      = isset( $_POST['per_page'] ) ? absint( $_POST['per_page'] ) : 50;
        $search        = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
        $filter_type   = isset( $_POST['filter_type'] ) ? sanitize_text_field( wp_unslash( $_POST['filter_type'] ) ) : '';
        $filter_source = isset( $_POST['filter_source'] ) ? sanitize_text_field( wp_unslash( $_POST['filter_source'] ) ) : '';
        $filter_status = isset( $_POST['filter_status'] ) ? sanitize_text_field( wp_unslash( $_POST['filter_status'] ) ) : '';
        $tab           = isset( $_POST['tab'] ) ? sanitize_text_field( wp_unslash( $_POST['tab'] ) ) : 'all';

        // Validate per_page (allowed: 25, 50, 100).
        if ( ! in_array( $per_page, array( 25, 50, 100 ), true ) ) {
            $per_page = 50;
        }

        if ( $page < 1 ) {
            $page = 1;
        }

        $settings       = self::get_settings();
        $selected_pages = $settings['selected_pages'] ?? array();

        // Apply tab filter.
        if ( $tab === 'sitemap' ) {
            $selected_pages = array_filter( $selected_pages, function( $entry ) {
                return ( $entry['page_source'] ?? '' ) === 'sitemap';
            } );
        } elseif ( $tab === 'manual' ) {
            $selected_pages = array_filter( $selected_pages, function( $entry ) {
                return ( $entry['page_source'] ?? '' ) === 'manual';
            } );
        }

        // Apply filter_type (AND logic).
        if ( ! empty( $filter_type ) ) {
            $selected_pages = array_filter( $selected_pages, function( $entry ) use ( $filter_type ) {
                return ( $entry['page_type'] ?? '' ) === $filter_type;
            } );
        }

        // Apply filter_source (AND logic).
        if ( ! empty( $filter_source ) ) {
            $selected_pages = array_filter( $selected_pages, function( $entry ) use ( $filter_source ) {
                return ( $entry['page_source'] ?? '' ) === $filter_source;
            } );
        }

        // Apply filter_status (AND logic).
        if ( ! empty( $filter_status ) ) {
            $selected_pages = array_filter( $selected_pages, function( $entry ) use ( $filter_status ) {
                return ( $entry['page_status'] ?? '' ) === $filter_status;
            } );
        }

        // Apply search (case-insensitive contains match against url and page_type).
        if ( ! empty( $search ) ) {
            $search_lower   = mb_strtolower( $search );
            $selected_pages = array_filter( $selected_pages, function( $entry ) use ( $search_lower ) {
                $url       = mb_strtolower( $entry['url'] ?? '' );
                $page_type = mb_strtolower( $entry['page_type'] ?? '' );

                return str_contains( $url, $search_lower ) || str_contains( $page_type, $search_lower );
            } );
        }

        // Re-index array after filtering.
        $selected_pages = array_values( $selected_pages );

        // Paginate.
        $total       = count( $selected_pages );
        $total_pages = (int) ceil( $total / $per_page );
        $offset      = ( $page - 1 ) * $per_page;
        $items       = array_slice( $selected_pages, $offset, $per_page );

        wp_send_json_success( array(
            'items'        => $items,
            'total'        => $total,
            'pages'        => $total_pages,
            'current_page' => $page,
        ) );
    }

    /**
     * AJAX handler: Handle bulk actions (mark_pending/remove/reset_error).
     *
     * Reads bulk_action (string) and urls (JSON array of URLs) from POST data.
     * Applies the action to each matching URL and persists changes.
     *
     * @return void Sends JSON response and terminates.
     */
    public static function ajax_bulk_action(): void {
        check_ajax_referer( 'brz_static_nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز.' ), 403 );
        }

        $bulk_action = isset( $_POST['bulk_action'] ) ? sanitize_text_field( wp_unslash( $_POST['bulk_action'] ) ) : '';
        $urls_raw    = isset( $_POST['urls'] ) ? wp_unslash( $_POST['urls'] ) : '';
        $urls        = json_decode( $urls_raw, true );

        if ( empty( $bulk_action ) ) {
            wp_send_json_error( array( 'message' => 'عملیات انتخاب نشده است.' ) );
        }

        if ( ! is_array( $urls ) || empty( $urls ) ) {
            wp_send_json_error( array( 'message' => 'لیست URLها نامعتبر یا خالی است.' ) );
        }

        $allowed_actions = array( 'mark_pending', 'remove', 'reset_error' );
        if ( ! in_array( $bulk_action, $allowed_actions, true ) ) {
            wp_send_json_error( array( 'message' => 'عملیات نامعتبر است.' ) );
        }

        $settings       = self::get_settings();
        $selected_pages = $settings['selected_pages'] ?? array();
        $url_set        = array_flip( $urls );
        $affected       = 0;

        if ( $bulk_action === 'remove' ) {
            // Remove matching URLs from selected_pages.
            $updated_pages = array();
            foreach ( $selected_pages as $page ) {
                $page_url = $page['url'] ?? '';
                if ( isset( $url_set[ $page_url ] ) ) {
                    $affected++;
                    continue; // Skip (remove) this entry.
                }
                $updated_pages[] = $page;
            }
            $settings['selected_pages'] = $updated_pages;
        } else {
            // mark_pending or reset_error: modify matching entries in place.
            foreach ( $selected_pages as &$page ) {
                $page_url = $page['url'] ?? '';
                if ( ! isset( $url_set[ $page_url ] ) ) {
                    continue;
                }

                if ( $bulk_action === 'mark_pending' ) {
                    $page['page_status'] = 'pending';
                    $affected++;
                } elseif ( $bulk_action === 'reset_error' ) {
                    $page['page_status'] = 'pending';
                    $page['error_count'] = 0;
                    $affected++;
                }
            }
            unset( $page );
            $settings['selected_pages'] = $selected_pages;
        }

        self::save_settings( $settings );

        wp_send_json_success( array( 'affected' => $affected ) );
    }

    /**
     * Validate the output file path.
     *
     * Checks that the path:
     * - Starts with '/' (absolute path)
     * - Does not contain '..' (directory traversal)
     * - Does not contain null bytes or control characters
     * - Does not exceed 255 characters
     *
     * @param string $path The file path to validate.
     * @return true|\WP_Error True if valid, WP_Error with Persian message if invalid.
     */
    private static function validate_output_path( string $path ): true|\WP_Error {
        // Must be absolute (start with /).
        if ( ! str_starts_with( $path, '/' ) ) {
            return new \WP_Error(
                'path_not_absolute',
                'مسیر فایل خروجی باید مطلق باشد (با / شروع شود).'
            );
        }

        // Must not contain directory traversal.
        if ( str_contains( $path, '..' ) ) {
            return new \WP_Error(
                'path_traversal',
                'مسیر فایل خروجی نمی‌تواند شامل «..» باشد.'
            );
        }

        // Must not contain null bytes.
        if ( str_contains( $path, "\0" ) ) {
            return new \WP_Error(
                'path_null_byte',
                'مسیر فایل خروجی شامل کاراکتر غیرمجاز است.'
            );
        }

        // Must not contain control characters (ASCII 0-31 except already checked \0).
        if ( preg_match( '/[\x01-\x1f]/', $path ) ) {
            return new \WP_Error(
                'path_control_chars',
                'مسیر فایل خروجی شامل کاراکترهای کنترلی غیرمجاز است.'
            );
        }

        // Must not exceed 255 characters.
        if ( strlen( $path ) > 255 ) {
            return new \WP_Error(
                'path_too_long',
                'مسیر فایل خروجی نمی‌تواند بیشتر از ۲۵۵ کاراکتر باشد.'
            );
        }

        return true;
    }

    /**
     * Render the admin settings page for the Static Controller module.
     * Called from BRZ_Settings when the static_controller module page is displayed.
     *
     * Renders a tabbed interface with: Dashboard, Sitemap Pages, Manual Pages, Settings.
     */
    public static function render_admin_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }


        $nonce          = wp_create_nonce( 'brz_static_nonce' );
        $settings       = self::get_settings();
        $pending_count  = BRZ_Static_Change_Trigger::get_pending_count();

        // Determine system status for the header badge.
        $generation_status = $settings['generation_status'] ?? 'idle';
        if ( $generation_status === 'error' ) {
            $system_status = 'error';
        } elseif ( $pending_count > 0 ) {
            $system_status = 'attention';
        } else {
            $system_status = 'healthy';
        }
        ?>
        <!-- Section Header -->
        <div class="brz-section-header">
            <div>
                <h2>کنترلر استاتیک</h2>
            </div>
            <div class="brz-section-actions">
                <span class="brz-static-system-status brz-static-system-status--<?php echo esc_attr( $system_status ); ?>" id="brz-static-system-badge">
                    <?php
                    if ( $system_status === 'healthy' ) {
                        echo esc_html( 'سالم' );
                    } elseif ( $system_status === 'attention' ) {
                        echo esc_html( 'نیاز به توجه' );
                    } else {
                        echo esc_html( 'خطا' );
                    }
                    ?>
                </span>
                <?php if ( $pending_count > 0 ) : ?>
                    <span class="brz-static-pending-badge" id="brz-static-pending-count">
                        <?php echo esc_html( $pending_count ); ?> در انتظار
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Tab Navigation -->
        <div class="brz-static-tabs" id="brz-static-tabs">
            <nav class="brz-static-tabs__nav" role="tablist">
                <button type="button" class="brz-static-tabs__btn is-active" role="tab"
                        aria-selected="true" aria-controls="brz-static-panel-dashboard"
                        data-tab="dashboard" id="brz-static-tab-dashboard">
                    داشبورد
                </button>
                <button type="button" class="brz-static-tabs__btn" role="tab"
                        aria-selected="false" aria-controls="brz-static-panel-sitemap"
                        data-tab="sitemap" id="brz-static-tab-sitemap">
                    صفحات سایت‌مپ
                </button>
                <button type="button" class="brz-static-tabs__btn" role="tab"
                        aria-selected="false" aria-controls="brz-static-panel-manual"
                        data-tab="manual" id="brz-static-tab-manual">
                    صفحات دستی
                </button>
                <button type="button" class="brz-static-tabs__btn" role="tab"
                        aria-selected="false" aria-controls="brz-static-panel-settings"
                        data-tab="settings" id="brz-static-tab-settings">
                    تنظیمات
                </button>
            </nav>

            <!-- Panel 1: Dashboard -->
            <div class="brz-static-tabs__panel is-visible" role="tabpanel"
                 data-panel="dashboard"
                 id="brz-static-panel-dashboard" aria-labelledby="brz-static-tab-dashboard">

                <!-- Summary Cards -->
                <div class="brz-static-dashboard">
                    <div class="brz-static-dashboard__card brz-card">
                        <div class="brz-card__body">
                            <span class="brz-static-dashboard__card-value" id="brz-static-dash-total">—</span>
                            <span class="brz-static-dashboard__card-label">کل صفحات</span>
                        </div>
                    </div>
                    <div class="brz-static-dashboard__card brz-card">
                        <div class="brz-card__body">
                            <span class="brz-static-dashboard__card-value" id="brz-static-dash-pending">—</span>
                            <span class="brz-static-dashboard__card-label">در انتظار بازسازی</span>
                        </div>
                    </div>
                    <div class="brz-static-dashboard__card brz-card">
                        <div class="brz-card__body">
                            <span class="brz-static-dashboard__card-value" id="brz-static-dash-last-sync">—</span>
                            <span class="brz-static-dashboard__card-label">آخرین همگام‌سازی</span>
                        </div>
                    </div>
                    <div class="brz-static-dashboard__card brz-card">
                        <div class="brz-card__body">
                            <span class="brz-static-dashboard__card-value brz-static-system-status" id="brz-static-dash-status">—</span>
                            <span class="brz-static-dashboard__card-label">وضعیت سیستم</span>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="brz-card">
                    <div class="brz-card__header">
                        <h3>عملیات سریع</h3>
                    </div>
                    <div class="brz-card__body">
                        <div class="brz-static-quick-actions">
                            <button type="button" class="button button-primary" id="brz-static-sync-sitemap-btn">
                                همگام‌سازی سایت‌مپ
                            </button>
                            <button type="button" class="button" id="brz-static-regenerate-pending-btn">
                                بازسازی صفحات تغییریافته
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Recent Activity -->
                <div class="brz-card">
                    <div class="brz-card__header">
                        <h3>فعالیت‌های اخیر</h3>
                    </div>
                    <div class="brz-card__body">
                        <ul class="brz-static-activity-list" id="brz-static-activity-list">
                            <!-- Populated via JS from regeneration_history -->
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Panel 2: Sitemap Pages -->
            <div class="brz-static-tabs__panel" role="tabpanel"
                 data-panel="sitemap"
                 id="brz-static-panel-sitemap" aria-labelledby="brz-static-tab-sitemap">

                <!-- Filters Bar -->
                <div class="brz-static-filters">
                    <div class="brz-static-filters__group">
                        <label for="brz-static-filter-type" class="brz-static-filters__label">نوع صفحه:</label>
                        <select id="brz-static-filter-type" class="brz-static-filters__select">
                            <option value="">همه</option>
                            <option value="product">محصول</option>
                            <option value="archive">آرشیو</option>
                            <option value="elementor_page">صفحه المنتور</option>
                            <option value="blog_post">نوشته</option>
                            <option value="blog_category">دسته‌بندی</option>
                            <option value="unknown">نامشخص</option>
                        </select>
                    </div>
                    <div class="brz-static-filters__group">
                        <label for="brz-static-filter-status" class="brz-static-filters__label">وضعیت:</label>
                        <select id="brz-static-filter-status" class="brz-static-filters__select">
                            <option value="">همه</option>
                            <option value="synced">همگام</option>
                            <option value="pending">در انتظار</option>
                            <option value="error">خطا</option>
                        </select>
                    </div>
                    <div class="brz-static-filters__group">
                        <input type="text"
                               id="brz-static-filter-search"
                               class="brz-static-filters__search"
                               placeholder="جستجو در URLها..."
                               dir="ltr">
                    </div>
                </div>

                <!-- Bulk Actions -->
                <div class="brz-static-bulk">
                    <label class="brz-static-bulk__checkbox">
                        <input type="checkbox" id="brz-static-bulk-select-all">
                        انتخاب همه
                    </label>
                    <select id="brz-static-bulk-action" class="brz-static-bulk__select">
                        <option value="">عملیات دسته‌جمعی...</option>
                        <option value="mark_pending">علامت‌گذاری به عنوان در انتظار</option>
                        <option value="remove">حذف</option>
                        <option value="reset_error">بازنشانی خطا</option>
                    </select>
                    <button type="button" class="button" id="brz-static-bulk-execute-btn">
                        اجرا
                    </button>
                </div>

                <!-- Page List Container -->
                <div class="brz-static-page-list" id="brz-static-sitemap-page-list">
                    <!-- Populated via JS/AJAX -->
                </div>

                <!-- Pagination Controls -->
                <div class="brz-static-pagination" id="brz-static-sitemap-pagination">
                    <!-- Populated via JS -->
                </div>
            </div>

            <!-- Panel 3: Manual Pages -->
            <div class="brz-static-tabs__panel" role="tabpanel"
                 data-panel="manual"
                 id="brz-static-panel-manual" aria-labelledby="brz-static-tab-manual">

                <!-- Add URL Input -->
                <div class="brz-card">
                    <div class="brz-card__header">
                        <h3>افزودن صفحه دستی</h3>
                    </div>
                    <div class="brz-card__body">
                        <div class="brz-static-manual-add">
                            <input type="text"
                                   id="brz-static-manual-url-input"
                                   class="regular-text"
                                   dir="ltr"
                                   placeholder="https://buyruz.com/your-page/"
                                   maxlength="2048">
                            <button type="button" class="button button-primary" id="brz-static-manual-add-btn">
                                افزودن
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Manual Pages List -->
                <div class="brz-static-page-list" id="brz-static-manual-page-list">
                    <!-- Populated via JS/AJAX -->
                </div>
            </div>

            <!-- Panel 4: Settings -->
            <div class="brz-static-tabs__panel" role="tabpanel"
                 data-panel="settings"
                 id="brz-static-panel-settings" aria-labelledby="brz-static-tab-settings">

                <div class="brz-card">
                    <div class="brz-card__header">
                        <h3>تنظیمات عمومی</h3>
                    </div>
                    <div class="brz-card__body">
                        <div class="brz-static-settings-form">
                            <!-- Output Path -->
                            <div class="brz-static-settings-field">
                                <label for="brz-static-output-path">مسیر فایل خروجی:</label>
                                <input type="text"
                                       id="brz-static-output-path"
                                       class="regular-text"
                                       dir="ltr"
                                       value=""
                                       placeholder="در حال بارگذاری...">
                            </div>

                            <!-- Sitemap URL -->
                            <div class="brz-static-settings-field">
                                <label for="brz-static-sitemap-url">آدرس سایت‌مپ:</label>
                                <input type="text"
                                       id="brz-static-sitemap-url"
                                       class="regular-text"
                                       dir="ltr"
                                       value=""
                                       placeholder="https://buyruz.com/sitemap_index.xml">
                            </div>

                            <!-- Auto-sync Toggle -->
                            <div class="brz-static-settings-field brz-static-settings-toggle">
                                <label for="brz-static-auto-sync">
                                    <input type="checkbox" id="brz-static-auto-sync">
                                    همگام‌سازی خودکار روزانه سایت‌مپ
                                </label>
                            </div>

                            <!-- Auto-regenerate Toggle -->
                            <div class="brz-static-settings-field brz-static-settings-toggle">
                                <label for="brz-static-auto-regenerate">
                                    <input type="checkbox" id="brz-static-auto-regenerate">
                                    بازسازی خودکار هنگام تشخیص تغییرات
                                </label>
                            </div>

                            <!-- Notify on Sync Toggle -->
                            <div class="brz-static-settings-field brz-static-settings-toggle">
                                <label for="brz-static-notify-sync">
                                    <input type="checkbox" id="brz-static-notify-sync">
                                    اطلاع‌رسانی پس از همگام‌سازی
                                </label>
                            </div>

                            <!-- Modal Code Section -->
                            <div class="brz-static-settings-field">
                                <label>کد مودال:</label>
                                <div class="brz-static-modal-scope">
                                    <label>
                                        <input type="radio" name="brz_static_modal_scope" value="global" checked>
                                        عمومی
                                    </label>
                                    <label>
                                        <input type="radio" name="brz_static_modal_scope" value="per-page">
                                        اختصاصی هر صفحه
                                    </label>
                                </div>
                                <textarea id="brz-static-modal-code"
                                          rows="10"
                                          dir="ltr"
                                          class="brz-static-code-editor"
                                          placeholder="کد HTML/JS مودال را اینجا وارد کنید..."></textarea>
                            </div>

                            <!-- Save Button -->
                            <div class="brz-static-settings-field">
                                <button type="button" class="button button-primary" id="brz-static-save-settings-btn">
                                    ذخیره تنظیمات
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Hidden nonce field for AJAX security -->
        <input type="hidden" id="brz-static-nonce" value="<?php echo esc_attr( $nonce ); ?>">
        <?php
    }
}
