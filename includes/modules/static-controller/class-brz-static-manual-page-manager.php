<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
// هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید و بی‌قید و شرط اجرا کنید و پس از اتمام کار تطابق را دوباره چک کنید.

/**
 * Manual Page Manager for Static Controller module.
 *
 * Handles validation, addition, and removal of manually-added URLs
 * that are not present in the WordPress sitemap. Provides URL validation
 * with specific error codes for each failure type, page type detection
 * via HTTP HEAD requests, and CRUD operations on the selected pages list.
 *
 * All methods are static following the BRZ_ class pattern.
 * Error messages are in Persian for admin-facing display.
 */
class BRZ_Static_Manual_Page_Manager {

    /**
     * Maximum allowed URL length in characters.
     */
    public const MAX_URL_LENGTH = 2048;

    /**
     * Timeout in seconds for HTTP HEAD page type detection requests.
     */
    public const DETECT_TIMEOUT = 10;

    /**
     * Validate and add a manual URL to the selected pages list.
     *
     * Performs full validation, attempts page type detection via HTTP HEAD,
     * and stores the URL with page_source="manual" and page_status="pending".
     * Falls back to page_type="unknown" if detection fails.
     *
     * @param string $url The URL to add.
     * @return true|\WP_Error True on success, WP_Error with specific code on failure.
     */
    public static function add_url( string $url ): true|\WP_Error {
        // Normalize: trim whitespace.
        $url = trim( $url );

        // Validate the URL.
        $validation = self::validate_url( $url );

        if ( is_wp_error( $validation ) ) {
            return $validation;
        }

        // Detect page type via HTTP HEAD request.
        $page_type = self::detect_page_type_remote( $url );

        // Build the page entry.
        $page_entry = array(
            'id'           => 0,
            'type'         => 'url',
            'url'          => $url,
            'page_type'    => $page_type,
            'page_source'  => 'manual',
            'page_status'  => 'pending',
            'lastmod'      => null,
            'error_count'  => 0,
            'content_hash' => null,
        );

        // Get current settings and add the page.
        $settings = BRZ_Static_Controller::get_settings();
        $settings['selected_pages'][] = $page_entry;

        // Save settings.
        self::save_settings( $settings );

        return true;
    }

    /**
     * Remove a manual URL from selected pages and the URLs_Map.
     *
     * Removes the URL from the in-memory selected_pages array and persists
     * the change. Also triggers a regeneration to update the URLs_Map file.
     *
     * @param string $url The URL to remove.
     * @return true|\WP_Error True on success, WP_Error if URL not found.
     */
    public static function remove_url( string $url ): true|\WP_Error {
        $url = trim( $url );

        $settings       = BRZ_Static_Controller::get_settings();
        $selected_pages = $settings['selected_pages'] ?? array();
        $found          = false;

        // Filter out the matching URL.
        $updated_pages = array();
        foreach ( $selected_pages as $page ) {
            if ( isset( $page['url'] ) && $page['url'] === $url ) {
                $found = true;
                continue; // Skip this entry (remove it).
            }
            $updated_pages[] = $page;
        }

        if ( ! $found ) {
            return new \WP_Error(
                'url_not_found',
                'URL مورد نظر در لیست صفحات یافت نشد.'
            );
        }

        $settings['selected_pages'] = $updated_pages;
        self::save_settings( $settings );

        // Trigger URLs_Map regeneration to reflect the removal.
        if ( class_exists( 'BRZ_Static_Change_Trigger' ) ) {
            BRZ_Static_Change_Trigger::schedule_regeneration();
        }

        return true;
    }

    /**
     * Validate a URL for manual addition.
     *
     * Checks the following in order, returning a specific WP_Error code
     * for the first failure encountered:
     * 1. Valid URL structure (parseable with scheme and host)
     * 2. HTTPS protocol required
     * 3. Domain must match the site domain
     * 4. Length must not exceed MAX_URL_LENGTH (2048) characters
     * 5. No whitespace or control characters
     * 6. No duplicate in existing selected pages
     *
     * @param string $url The URL to validate.
     * @return true|\WP_Error True if valid, WP_Error with specific error code on failure.
     */
    public static function validate_url( string $url ): true|\WP_Error {
        // Check for whitespace or control characters first (before parsing).
        if ( preg_match( '/[\s\x00-\x1f\x7f]/', $url ) ) {
            return new \WP_Error(
                'invalid_format',
                'فرمت URL وارد شده معتبر نیست'
            );
        }

        // Check valid URL structure (must have scheme and host).
        $parsed = wp_parse_url( $url );

        if ( $parsed === false || empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) {
            return new \WP_Error(
                'invalid_format',
                'فرمت URL وارد شده معتبر نیست'
            );
        }

        // Check HTTPS protocol.
        if ( strtolower( $parsed['scheme'] ) !== 'https' ) {
            return new \WP_Error(
                'insecure_protocol',
                'URL باید با https:// شروع شود'
            );
        }

        // Check domain match.
        $site_domain = self::get_site_domain();
        $url_host    = strtolower( $parsed['host'] );

        if ( $url_host !== $site_domain && $url_host !== 'www.' . $site_domain ) {
            return new \WP_Error(
                'wrong_domain',
                'URL باید متعلق به دامنه سایت باشد'
            );
        }

        // Check length.
        if ( strlen( $url ) > self::MAX_URL_LENGTH ) {
            return new \WP_Error(
                'url_too_long',
                'URL نمی‌تواند بیشتر از ۲۰۴۸ کاراکتر باشد'
            );
        }

        // Check for duplicates in existing selected pages.
        $settings       = BRZ_Static_Controller::get_settings();
        $selected_pages = $settings['selected_pages'] ?? array();

        foreach ( $selected_pages as $page ) {
            if ( isset( $page['url'] ) && $page['url'] === $url ) {
                return new \WP_Error(
                    'duplicate_url',
                    'این URL قبلاً در لیست وجود دارد'
                );
            }
        }

        return true;
    }

    /**
     * Attempt to detect the page type of a URL via HTTP HEAD request.
     *
     * Sends an HTTP HEAD request with a timeout of DETECT_TIMEOUT seconds.
     * On success (2xx response), attempts to infer page type from response
     * headers and URL path patterns. Returns "unknown" on any failure.
     *
     * @param string $url The URL to detect page type for.
     * @return string One of the BRZ_Static_Page_Detector::PROFILE_* constants.
     */
    public static function detect_page_type_remote( string $url ): string {
        $response = wp_remote_head( $url, array(
            'timeout'   => self::DETECT_TIMEOUT,
            'sslverify' => true,
        ) );

        // On any failure, return unknown.
        if ( is_wp_error( $response ) ) {
            return BRZ_Static_Page_Detector::PROFILE_UNKNOWN;
        }

        $status_code = wp_remote_retrieve_response_code( $response );

        // Non-2xx response means we can't reliably detect.
        if ( $status_code < 200 || $status_code >= 300 ) {
            return BRZ_Static_Page_Detector::PROFILE_UNKNOWN;
        }

        // Attempt to infer page type from URL path patterns.
        $parsed = wp_parse_url( $url );
        $path   = $parsed['path'] ?? '/';

        // Product URLs typically contain /product/ in the path.
        if ( str_contains( $path, '/product/' ) ) {
            return BRZ_Static_Page_Detector::PROFILE_PRODUCT;
        }

        // Archive/category URLs.
        if ( str_contains( $path, '/product-category/' ) ||
             str_contains( $path, '/product-tag/' ) ||
             str_contains( $path, '/product-brand/' ) ) {
            return BRZ_Static_Page_Detector::PROFILE_ARCHIVE;
        }

        // Blog category.
        if ( str_contains( $path, '/category/' ) ) {
            return BRZ_Static_Page_Detector::PROFILE_BLOG_CATEGORY;
        }

        // Blog post pattern: /mag/ or /blog/ prefix.
        if ( str_contains( $path, '/mag/' ) || str_contains( $path, '/blog/' ) ) {
            return BRZ_Static_Page_Detector::PROFILE_BLOG_POST;
        }

        // Cannot determine — return unknown.
        return BRZ_Static_Page_Detector::PROFILE_UNKNOWN;
    }

    /**
     * Extract the site domain from WordPress site_url().
     *
     * Parses the WordPress site URL and returns the host component
     * in lowercase, without protocol or trailing slash.
     * Strips "www." prefix if present for consistent comparison.
     *
     * @return string The site domain (e.g., "buyruz.com").
     */
    private static function get_site_domain(): string {
        $site_url = site_url();
        $parsed   = wp_parse_url( $site_url );
        $host     = strtolower( $parsed['host'] ?? '' );

        // Strip www. prefix for consistent domain comparison.
        if ( str_starts_with( $host, 'www.' ) ) {
            $host = substr( $host, 4 );
        }

        return $host;
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

        $options[ BRZ_Static_Controller::OPTION_KEY ] = $settings;
        update_option( 'brz_options', $options );
    }
}
