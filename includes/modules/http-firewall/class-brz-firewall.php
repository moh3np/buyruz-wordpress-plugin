<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * HTTP Request Firewall module.
 *
 * Intercepts outgoing WordPress HTTP requests via `pre_http_request` filter
 * and provides blacklist/whitelist domain filtering.
 * Follows the static class pattern used by other buyruz-plugin modules.
 */
class BRZ_Firewall {

    const OPTION_KEY = 'firewall';

    /**
     * Bootstrap hooks.
     * Registers the pre_http_request filter at priority 5 and AJAX handlers.
     */
    public static function init(): void {
        add_filter( 'pre_http_request', array( __CLASS__, 'filter_request' ), 5, 3 );

        add_action( 'wp_ajax_brz_firewall_switch_mode', array( __CLASS__, 'ajax_switch_mode' ) );
        add_action( 'wp_ajax_brz_firewall_add_domain', array( __CLASS__, 'ajax_add_domain' ) );
        add_action( 'wp_ajax_brz_firewall_remove_domain', array( __CLASS__, 'ajax_remove_domain' ) );
        add_action( 'wp_ajax_brz_firewall_add_batch', array( __CLASS__, 'ajax_add_batch' ) );

        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
    }

    /**
     * Enqueue admin CSS and JS on the firewall settings page only.
     *
     * @param string $hook The current admin page hook suffix.
     */
    public static function enqueue_admin_assets( string $hook ): void {
        if ( strpos( $hook, 'buyruz-module-outbound_guard' ) === false ) {
            return;
        }

        $assets_url = plugin_dir_url( __FILE__ ) . 'assets/';

        wp_enqueue_style(
            'brz-firewall-admin-css',
            $assets_url . 'firewall-admin.css',
            array(),
            BRZ_VERSION
        );

        wp_enqueue_script(
            'brz-firewall-admin-js',
            $assets_url . 'firewall-admin.js',
            array( 'jquery' ),
            BRZ_VERSION,
            true
        );

        wp_localize_script( 'brz-firewall-admin-js', 'brz_firewall', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'brz_firewall_nonce' ),
            'strings'  => array(
                'empty'        => 'لیست خالی است',
                'empty_domain' => 'دامنه نمی‌تواند خالی باشد',
                'add_error'    => 'خطا در افزودن دامنه',
                'remove'       => 'حذف',
            ),
        ) );
    }

    /**
     * Render the firewall admin settings page.
     * Called from BRZ_Settings when the http_firewall module page is displayed.
     */
    public static function render_admin_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $settings    = self::get_settings();
        $active_mode = $settings['active_mode'];
        $domains     = $settings[ $active_mode ];
        $nonce       = wp_create_nonce( 'brz_firewall_nonce' );
        ?>


        <div class="brz-single-column">
            <!-- Mode Selector Card -->
            <div class="brz-card">
                <div class="brz-card__header">
                    <h3>حالت فایروال</h3>
                </div>
                <div class="brz-card__body">
                    <div class="brz-firewall-mode-selector">
                        <label class="brz-firewall-mode-option <?php echo 'blacklist' === $active_mode ? 'is-active' : ''; ?>">
                            <input type="radio" name="brz_firewall_mode" value="blacklist" <?php checked( $active_mode, 'blacklist' ); ?>>
                            <span class="brz-firewall-mode-option__label">لیست سیاه (Blacklist)</span>
                            <span class="brz-firewall-mode-option__desc">مسدود کردن دامنه‌های خاص</span>
                        </label>
                        <label class="brz-firewall-mode-option <?php echo 'whitelist' === $active_mode ? 'is-active' : ''; ?>">
                            <input type="radio" name="brz_firewall_mode" value="whitelist" <?php checked( $active_mode, 'whitelist' ); ?>>
                            <span class="brz-firewall-mode-option__label">لیست سفید (Whitelist)</span>
                            <span class="brz-firewall-mode-option__desc">فقط اجازه به دامنه‌های خاص</span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Domain List Card -->
            <div class="brz-card">
                <div class="brz-card__header">
                    <h3 class="brz-firewall-list-title">
                        <?php echo 'blacklist' === $active_mode ? 'لیست دامنه‌های مسدود' : 'لیست دامنه‌های مجاز'; ?>
                    </h3>
                </div>
                <div class="brz-card__body">
                    <!-- Add Domain Input -->
                    <div class="brz-firewall-domain-input">
                        <input type="text" id="brz-firewall-new-domain" placeholder="example.com" dir="ltr">
                        <button type="button" class="button button-primary" id="brz-firewall-add-btn">افزودن</button>
                    </div>

                    <!-- Batch Add -->
                    <details style="margin-bottom: 12px;">
                        <summary style="cursor:pointer; color:#2563eb; font-size:13px; margin-bottom:8px;">افزودن دسته‌ای (چند دامنه همزمان)</summary>
                        <div style="margin-top:8px;">
                            <textarea id="brz-firewall-batch" rows="4" dir="ltr" style="width:100%; font-family:monospace; font-size:13px; padding:8px; border:1px solid #e2e8f0; border-radius:8px;" placeholder="هر خط یک دامنه&#10;example.com&#10;*.example.org"></textarea>
                            <button type="button" class="button" id="brz-firewall-batch-btn" style="margin-top:6px;">افزودن همه</button>
                            <span id="brz-firewall-batch-status" style="margin-right:8px; font-size:13px;"></span>
                        </div>
                    </details>

                    <!-- Inline Error Container -->
                    <div class="brz-firewall-error" id="brz-firewall-error" style="display:none;"></div>

                    <!-- Domain List -->
                    <div class="brz-firewall-domain-list" id="brz-firewall-domain-list">
                        <?php if ( empty( $domains ) ) : ?>
                            <p class="brz-firewall-empty"><?php echo esc_html( 'لیست خالی است' ); ?></p>
                        <?php else : ?>
                            <?php foreach ( $domains as $domain ) : ?>
                                <div class="brz-firewall-domain-item" data-domain="<?php echo esc_attr( $domain ); ?>">
                                    <span class="brz-firewall-domain-item__name" dir="ltr"><?php echo esc_html( $domain ); ?></span>
                                    <button type="button" class="brz-firewall-domain-item__delete" title="حذف">&times;</button>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Nonce field for AJAX security -->
        <input type="hidden" id="brz-firewall-nonce" value="<?php echo esc_attr( $nonce ); ?>">
        <?php
    }

    /**
     * Get firewall settings with defensive defaults.
     *
     * @return array Settings array with 'active_mode', 'blacklist', and 'whitelist' keys.
     */
    public static function get_settings(): array {
        $options  = get_option( 'brz_options', array() );
        $settings = isset( $options[ self::OPTION_KEY ] ) && is_array( $options[ self::OPTION_KEY ] )
            ? $options[ self::OPTION_KEY ]
            : array();

        $active_mode = isset( $settings['active_mode'] ) && in_array( $settings['active_mode'], array( 'blacklist', 'whitelist' ), true )
            ? $settings['active_mode']
            : 'blacklist';

        $blacklist = isset( $settings['blacklist'] ) && is_array( $settings['blacklist'] )
            ? $settings['blacklist']
            : array();

        $whitelist = isset( $settings['whitelist'] ) && is_array( $settings['whitelist'] )
            ? $settings['whitelist']
            : array();

        return array(
            'active_mode' => $active_mode,
            'blacklist'   => $blacklist,
            'whitelist'   => $whitelist,
        );
    }

    /**
     * Save firewall settings to brz_options.
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
     * Check if a host matches any entry in the domain list.
     *
     * @param string $host        The host to check.
     * @param array  $domain_list Array of domain entries.
     * @return bool True if host matches any entry.
     */
    private static function domain_matches( string $host, array $domain_list ): bool {
        foreach ( $domain_list as $entry ) {
            if ( BRZ_Firewall_Validator::matches( $host, $entry ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Filter outgoing HTTP requests based on active mode and domain list.
     *
     * @param mixed  $pre  Pre-filter value (false to continue, truthy to short-circuit).
     * @param array  $args HTTP request arguments.
     * @param string $url  The request URL.
     * @return mixed WP_Error if blocked, false to allow.
     */
    public static function filter_request( $pre, array $args, string $url ): mixed {
        // If already short-circuited by another filter, pass through.
        if ( false !== $pre ) {
            return $pre;
        }

        $parsed = wp_parse_url( $url );
        if ( empty( $parsed['host'] ) ) {
            // Cannot determine host — fail open.
            return false;
        }

        $host     = strtolower( $parsed['host'] );
        $settings = self::get_settings();
        $mode     = $settings['active_mode'];
        $domains  = $settings[ $mode ];

        if ( 'blacklist' === $mode ) {
            if ( self::domain_matches( $host, $domains ) ) {
                return new WP_Error(
                    'brz_firewall_blocked',
                    sprintf( 'درخواست به %s توسط فایروال مسدود شد.', $host )
                );
            }
        } else {
            // Whitelist mode: block if NOT in list.
            if ( ! self::domain_matches( $host, $domains ) ) {
                return new WP_Error(
                    'brz_firewall_blocked',
                    sprintf( 'دامنه %s در لیست مجاز نیست.', $host )
                );
            }
        }

        return false;
    }

    /**
     * AJAX handler: Switch firewall mode.
     */
    public static function ajax_switch_mode(): void {
        check_ajax_referer( 'brz_firewall_nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
        }

        $mode = isset( $_POST['mode'] ) ? sanitize_text_field( wp_unslash( $_POST['mode'] ) ) : '';

        if ( ! in_array( $mode, array( 'blacklist', 'whitelist' ), true ) ) {
            wp_send_json_error( array( 'message' => 'حالت نامعتبر' ) );
        }

        $settings               = self::get_settings();
        $settings['active_mode'] = $mode;
        self::save_settings( $settings );

        wp_send_json_success( array(
            'mode'    => $mode,
            'domains' => $settings[ $mode ],
        ) );
    }

    /**
     * AJAX handler: Add a domain to the active list.
     */
    public static function ajax_add_domain(): void {
        check_ajax_referer( 'brz_firewall_nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
        }

        $raw_domain = isset( $_POST['domain'] ) ? sanitize_text_field( wp_unslash( $_POST['domain'] ) ) : '';

        if ( '' === $raw_domain ) {
            wp_send_json_error( array( 'message' => 'دامنه نمی‌تواند خالی باشد' ) );
        }

        $domain = BRZ_Firewall_Validator::normalize( $raw_domain );

        $validation = BRZ_Firewall_Validator::validate( $domain );
        if ( is_wp_error( $validation ) ) {
            wp_send_json_error( array( 'message' => sprintf( 'فرمت دامنه نامعتبر است: %s', $validation->get_error_message() ) ) );
        }

        $settings = self::get_settings();
        $mode     = $settings['active_mode'];

        if ( in_array( $domain, $settings[ $mode ], true ) ) {
            wp_send_json_error( array( 'message' => 'این دامنه قبلاً در لیست وجود دارد' ) );
        }

        $settings[ $mode ][] = sanitize_text_field( $domain );
        self::save_settings( $settings );

        wp_send_json_success( array(
            'domain'  => $domain,
            'domains' => $settings[ $mode ],
        ) );
    }

    /**
     * AJAX handler: Remove a domain from the active list.
     */
    public static function ajax_remove_domain(): void {
        check_ajax_referer( 'brz_firewall_nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
        }

        $domain = isset( $_POST['domain'] ) ? sanitize_text_field( wp_unslash( $_POST['domain'] ) ) : '';

        $settings = self::get_settings();
        $mode     = $settings['active_mode'];

        $settings[ $mode ] = array_values( array_filter(
            $settings[ $mode ],
            function ( $entry ) use ( $domain ) {
                return $entry !== $domain;
            }
        ) );

        self::save_settings( $settings );

        wp_send_json_success( array(
            'domains' => $settings[ $mode ],
        ) );
    }

    /**
     * AJAX handler: Add multiple domains at once (batch).
     * Accepts newline-separated or comma-separated domains.
     */
    public static function ajax_add_batch(): void {
        check_ajax_referer( 'brz_firewall_nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
        }

        $raw = isset( $_POST['domains'] ) ? sanitize_textarea_field( wp_unslash( $_POST['domains'] ) ) : '';

        if ( '' === trim( $raw ) ) {
            wp_send_json_error( array( 'message' => 'لیست دامنه‌ها خالی است' ) );
        }

        // Split by newline, comma, or space
        $lines = preg_split( '/[\r\n,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY );

        $settings = self::get_settings();
        $mode     = $settings['active_mode'];
        $added    = 0;
        $skipped  = 0;
        $errors   = array();

        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( '' === $line ) {
                continue;
            }

            $domain = BRZ_Firewall_Validator::normalize( $line );
            if ( '' === $domain ) {
                $skipped++;
                continue;
            }

            $validation = BRZ_Firewall_Validator::validate( $domain );
            if ( is_wp_error( $validation ) ) {
                $errors[] = $line . ': ' . $validation->get_error_message();
                continue;
            }

            if ( in_array( $domain, $settings[ $mode ], true ) ) {
                $skipped++;
                continue;
            }

            $settings[ $mode ][] = $domain;
            $added++;
        }

        self::save_settings( $settings );

        $message = sprintf( '%d دامنه اضافه شد', $added );
        if ( $skipped > 0 ) {
            $message .= sprintf( '، %d تکراری رد شد', $skipped );
        }
        if ( ! empty( $errors ) ) {
            $message .= sprintf( '، %d خطا', count( $errors ) );
        }

        wp_send_json_success( array(
            'message' => $message,
            'added'   => $added,
            'skipped' => $skipped,
            'errors'  => $errors,
            'domains' => $settings[ $mode ],
        ) );
    }
}
