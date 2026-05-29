<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
// هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید و بی‌قید و شرط اجرا کنید و پس از اتمام کار تطابق را دوباره چک کنید.

/**
 * Modal Injector for Static Controller module.
 *
 * Validates, stores, and retrieves modal code (global and per-page).
 * The Processing Engine uses this data to inject modal code into static pages.
 */
class BRZ_Static_Modal_Injector {

    /**
     * Maximum allowed length for modal code (characters).
     */
    const MAX_CODE_LENGTH = 50000;

    /**
     * Option key used by the Static Controller module within brz_options.
     */
    private const OPTION_KEY = 'static_controller';

    /**
     * Validate modal code.
     *
     * Checks that the code does not exceed the maximum length and does not
     * contain PHP execution tags (<?php, <?=, or short open tag <?).
     *
     * @param string $code The modal HTML/JS code to validate.
     * @return true|\WP_Error True if valid, WP_Error with Persian message if invalid.
     */
    public static function validate( string $code ): true|\WP_Error {
        // Allow empty string (used to clear per-page overrides).
        if ( $code === '' ) {
            return true;
        }

        // Check maximum length.
        if ( mb_strlen( $code, 'UTF-8' ) > self::MAX_CODE_LENGTH ) {
            return new \WP_Error(
                'modal_code_too_long',
                sprintf(
                    'کد مودال نمی‌تواند بیشتر از %s کاراکتر باشد.',
                    number_format_i18n( self::MAX_CODE_LENGTH )
                )
            );
        }

        // Check for PHP execution tags in priority order (most specific first).
        $php_tags = [
            '<?php' => '<?php',
            '<?='   => '<?=',
            '<?'    => '<?',
        ];

        foreach ( $php_tags as $tag => $display ) {
            if ( str_contains( $code, $tag ) ) {
                return new \WP_Error(
                    'modal_code_php_tag',
                    sprintf(
                        'کد مودال نمی‌تواند شامل تگ اجرایی PHP باشد. تگ یافت‌شده: %s',
                        $display
                    )
                );
            }
        }

        return true;
    }

    /**
     * Save global modal code.
     *
     * Validates the code and persists it to brz_options['static_controller']['modal_global'].
     *
     * @param string $code The modal HTML/JS code to save globally.
     * @return true|\WP_Error True on success, WP_Error if validation fails.
     */
    public static function save_global( string $code ): true|\WP_Error {
        $validation = self::validate( $code );

        if ( is_wp_error( $validation ) ) {
            return $validation;
        }

        $settings = self::get_settings();
        $settings['modal_global'] = $code;
        self::save_settings( $settings );

        return true;
    }

    /**
     * Save per-page modal code.
     *
     * Validates the code and persists it to
     * brz_options['static_controller']['modal_per_page'][$post_id].
     * An empty string clears the per-page override for that page.
     *
     * @param int    $post_id The post ID to associate the modal code with.
     * @param string $code    The modal HTML/JS code for this specific page.
     * @return true|\WP_Error True on success, WP_Error if validation fails.
     */
    public static function save_per_page( int $post_id, string $code ): true|\WP_Error {
        $validation = self::validate( $code );

        if ( is_wp_error( $validation ) ) {
            return $validation;
        }

        $settings = self::get_settings();

        if ( ! is_array( $settings['modal_per_page'] ) ) {
            $settings['modal_per_page'] = [];
        }

        $settings['modal_per_page'][ $post_id ] = $code;
        self::save_settings( $settings );

        return true;
    }

    /**
     * Get the effective modal code for a specific page.
     *
     * Per-page configuration takes priority over global configuration.
     * Returns the applicable code and its scope indicator.
     *
     * @param int $post_id The post ID to retrieve modal code for.
     * @return array{code: string, scope: string} Modal code and scope ('per-page' or 'global').
     */
    public static function get_modal_for_page( int $post_id ): array {
        $settings = self::get_settings();

        // Check for per-page override.
        $per_page = $settings['modal_per_page'] ?? [];

        if ( is_array( $per_page ) && isset( $per_page[ $post_id ] ) && $per_page[ $post_id ] !== '' ) {
            return [
                'code'  => $per_page[ $post_id ],
                'scope' => 'per-page',
            ];
        }

        // Fall back to global modal code.
        $global_code = $settings['modal_global'] ?? '';

        return [
            'code'  => $global_code,
            'scope' => 'global',
        ];
    }

    /**
     * Get module settings with defensive defaults.
     *
     * @return array Settings array merged with defaults.
     */
    private static function get_settings(): array {
        $options  = get_option( 'brz_options', [] );
        $settings = isset( $options[ self::OPTION_KEY ] ) && is_array( $options[ self::OPTION_KEY ] )
            ? $options[ self::OPTION_KEY ]
            : [];

        return wp_parse_args( $settings, [
            'modal_global'   => '',
            'modal_per_page' => [],
        ] );
    }

    /**
     * Save module settings to brz_options.
     *
     * @param array $settings Settings array to persist.
     */
    private static function save_settings( array $settings ): void {
        $options = get_option( 'brz_options', [] );

        if ( ! is_array( $options ) ) {
            $options = [];
        }

        // Merge with existing static_controller settings to preserve other keys.
        $existing = isset( $options[ self::OPTION_KEY ] ) && is_array( $options[ self::OPTION_KEY ] )
            ? $options[ self::OPTION_KEY ]
            : [];

        $options[ self::OPTION_KEY ] = array_merge( $existing, $settings );
        update_option( 'brz_options', $options );
    }
}
