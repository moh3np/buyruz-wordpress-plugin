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

            // AJAX handlers.
            add_action( 'wp_ajax_brz_static_search_pages', array( __CLASS__, 'ajax_search_pages' ) );
            add_action( 'wp_ajax_brz_static_save_pages', array( __CLASS__, 'ajax_save_selected_pages' ) );
            add_action( 'wp_ajax_brz_static_save_settings', array( __CLASS__, 'ajax_save_settings' ) );
            add_action( 'wp_ajax_brz_static_regenerate', array( __CLASS__, 'ajax_manual_regenerate' ) );

            // Change trigger hooks (admin context for save_post, etc.).
            BRZ_Static_Change_Trigger::init();
        }

        // Cron action hooks (always register so WP-Cron can fire them).
        add_action( BRZ_Static_Controller::CRON_HOOK, array( 'BRZ_Static_Map_Generator', 'generate' ) );
        add_action( BRZ_Static_Controller::BATCH_HOOK, array( 'BRZ_Static_Map_Generator', 'generate_batch' ), 10, 2 );
    }

    /**
     * Cleanup when module is deactivated.
     * Removes all scheduled events and transient data.
     */
    public static function deactivate_cleanup(): void {
        try {
            BRZ_Static_Change_Trigger::cleanup_scheduled_events();
        } catch ( \Throwable ) {
            error_log( '[BRZ Static Controller] Error during deactivation cleanup.' );
        }
    }

    /**
     * Default settings for the module.
     *
     * @return array Default configuration values.
     */
    private static function default_settings(): array {
        return array(
            'selected_pages'    => array(),
            'output_path'       => '/home/user/static-data/urls-map.json',
            'modal_global'      => '',
            'modal_per_page'    => array(),
            'last_generated'    => null,
            'generation_status' => 'idle',
        );
    }

    /**
     * Get module settings with defensive defaults.
     *
     * @return array Settings array merged with defaults.
     */
    public static function get_settings(): array {
        $options  = get_option( 'brz_options', array() );
        $settings = isset( $options[ self::OPTION_KEY ] ) && is_array( $options[ self::OPTION_KEY ] )
            ? $options[ self::OPTION_KEY ]
            : array();

        return wp_parse_args( $settings, self::default_settings() );
    }

    /**
     * Enqueue admin CSS and JS on the static controller settings page only.
     *
     * @param string $hook The current admin page hook suffix.
     */
    public static function enqueue_admin_assets( string $hook ): void {
        if ( strpos( $hook, 'buyruz-module-urlgen' ) === false ) {
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
                'save_success'    => 'تنظیمات ذخیره شد',
                'save_error'      => 'خطا در ذخیره تنظیمات',
                'search_empty'    => 'نتیجه‌ای یافت نشد',
                'regenerate_ok'   => 'بازسازی نقشه URL زمان‌بندی شد',
                'regenerate_err'  => 'خطا در زمان‌بندی بازسازی',
                'loading'         => 'در حال بارگذاری...',
                'network_error'   => 'خطای شبکه. لطفاً دوباره تلاش کنید.',
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
     */
    public static function render_admin_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $settings          = self::get_settings();
        $output_path       = $settings['output_path'];
        $last_generated    = $settings['last_generated'];
        $generation_status = $settings['generation_status'];
        $modal_global      = $settings['modal_global'];
        $nonce             = wp_create_nonce( 'brz_static_nonce' );
        ?>
        <!-- Section Header -->
        <div class="brz-section-header">
            <div>
                <h2>کنترلر استاتیک</h2>
                <p>مدیریت صفحات و تولید نقشه URL برای ژنراتور استاتیک</p>
            </div>
            <div class="brz-section-actions">
                <span class="brz-status is-on">فعال</span>
            </div>
        </div>

        <div class="brz-single-column">
            <!-- Card: تنظیمات خروجی (Output Settings) -->
            <div class="brz-card">
                <div class="brz-card__header">
                    <h3>تنظیمات خروجی</h3>
                </div>
                <div class="brz-card__body">
                    <div class="brz-static-output-settings">
                        <label for="brz-static-output-path">مسیر فایل خروجی:</label>
                        <input type="text"
                               id="brz-static-output-path"
                               class="regular-text"
                               dir="ltr"
                               value="<?php echo esc_attr( $output_path ); ?>"
                               placeholder="/home/user/static-data/urls-map.json">

                        <div class="brz-static-generation-info" style="margin-top: 12px;">
                            <p>
                                <strong>آخرین تولید:</strong>
                                <span id="brz-static-last-generated">
                                    <?php echo $last_generated ? esc_html( $last_generated ) : 'هنوز تولید نشده'; ?>
                                </span>
                            </p>
                            <p>
                                <strong>وضعیت:</strong>
                                <span id="brz-static-generation-status" class="brz-static-status brz-static-status--<?php echo esc_attr( $generation_status ); ?>">
                                    <?php
                                    $status_labels = array(
                                        'idle'    => 'بدون فعالیت',
                                        'success' => 'موفق',
                                        'error'   => 'خطا',
                                        'running' => 'در حال اجرا',
                                    );
                                    echo esc_html( $status_labels[ $generation_status ] ?? $generation_status );
                                    ?>
                                </span>
                            </p>
                        </div>

                        <button type="button" class="button button-primary" id="brz-static-regenerate-btn" style="margin-top: 10px;">
                            بازسازی دستی
                        </button>
                    </div>
                </div>
            </div>

            <!-- Card: انتخاب صفحات (Page Selection) -->
            <div class="brz-card">
                <div class="brz-card__header">
                    <h3>انتخاب صفحات</h3>
                </div>
                <div class="brz-card__body">
                    <div class="brz-static-search">
                        <input type="text"
                               id="brz-static-search-input"
                               class="regular-text"
                               placeholder="جستجوی صفحه یا محصول..."
                               dir="rtl">
                    </div>

                    <div class="brz-static-page-list" id="brz-static-page-list">
                        <!-- Populated via JS/AJAX -->
                    </div>

                    <div class="brz-static-pagination" id="brz-static-pagination">
                        <!-- Pagination controls populated via JS -->
                    </div>
                </div>
            </div>

            <!-- Card: کد مودال (Modal Code) -->
            <div class="brz-card">
                <div class="brz-card__header">
                    <h3>کد مودال</h3>
                </div>
                <div class="brz-card__body">
                    <div class="brz-static-modal-settings">
                        <div class="brz-static-modal-scope" style="margin-bottom: 12px;">
                            <label>
                                <input type="radio" name="brz_static_modal_scope" value="global" checked>
                                عمومی
                            </label>
                            <label style="margin-right: 16px;">
                                <input type="radio" name="brz_static_modal_scope" value="per-page">
                                اختصاصی هر صفحه
                            </label>
                        </div>

                        <div class="brz-static-code-editor">
                            <textarea id="brz-static-modal-code"
                                      rows="10"
                                      dir="ltr"
                                      style="width: 100%; font-family: monospace;"
                                      placeholder="کد HTML/JS مودال را اینجا وارد کنید..."><?php echo esc_textarea( $modal_global ); ?></textarea>
                        </div>

                        <button type="button" class="button button-primary" id="brz-static-save-modal-btn" style="margin-top: 10px;">
                            ذخیره کد مودال
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Hidden nonce field for AJAX security -->
        <input type="hidden" id="brz-static-nonce" value="<?php echo esc_attr( $nonce ); ?>">
        <?php
    }
}
