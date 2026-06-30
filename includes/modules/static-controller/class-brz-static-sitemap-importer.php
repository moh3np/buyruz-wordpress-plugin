<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
// هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید و بی‌قید و شرط اجرا کنید و پس از اتمام کار تطابق را دوباره چک کنید.

/**
 * Static Sitemap Importer.
 *
 * Fetches, parses, and imports URLs from WordPress sitemap_index.xml and its
 * child sitemaps. Handles batch processing for large sitemaps (>5000 URLs),
 * daily auto-sync via WP-Cron, and retry logic for failed fetches.
 *
 * Follows the static class pattern used by other buyruz-plugin modules.
 */
class BRZ_Static_Sitemap_Importer {

    /**
     * Maximum number of URLs to process from sitemaps.
     */
    const MAX_URLS = 50000;

    /**
     * Number of URLs to process per batch during large imports.
     */
    const BATCH_SIZE = 500;

    /**
     * HTTP request timeout in seconds.
     */
    const HTTP_TIMEOUT = 30;

    /**
     * WP-Cron hook name for daily sitemap sync.
     */
    const CRON_HOOK = 'brz_static_daily_sitemap_sync';

    /**
     * WP-Cron hook name for retry after failed sync.
     */
    const RETRY_HOOK = 'brz_static_sitemap_retry';

    /**
     * WP-Cron hook name for batch import processing.
     */
    const BATCH_CRON_HOOK = 'brz_static_batch_import';

    /**
     * Interval between retry attempts in seconds (5 minutes).
     */
    const RETRY_INTERVAL = 300;

    /**
     * Maximum number of retry attempts before disabling auto-sync.
     */
    const MAX_RETRIES = 3;

    /**
     * Sitemap XML namespace URI.
     */
    const SITEMAP_NS = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    /**
     * Fetch and parse sitemap_index.xml and all child sitemaps.
     *
     * Fetches the sitemap index from the configured URL (or default site sitemap),
     * extracts child sitemap URLs, fetches each child sitemap, and collects all
     * URL entries with their lastmod timestamps. Enforces a maximum of 50,000 URLs.
     *
     * Unreachable child sitemaps are skipped with a warning logged, and parsing
     * continues with the remaining child sitemaps.
     *
     * @return array|\WP_Error Associative array of [url => lastmod] on success,
     *                         or WP_Error with specific error code on failure.
     *                         Error codes: 'timeout', 'http_error', 'invalid_xml'
     */
    public static function fetch_and_parse(): array|\WP_Error {
        $sitemap_url = self::get_sitemap_url();

        // Fetch the sitemap index.
        $response = wp_remote_get( $sitemap_url, array(
            'timeout'   => self::HTTP_TIMEOUT,
            'sslverify' => true,
        ) );

        // Handle HTTP request failure (timeout, network error).
        if ( is_wp_error( $response ) ) {
            $error_message = $response->get_error_message();

            // Detect timeout specifically.
            if ( str_contains( strtolower( $error_message ), 'timeout' )
                || str_contains( strtolower( $error_message ), 'timed out' )
                || $response->get_error_code() === 'http_request_failed'
            ) {
                return new \WP_Error(
                    'timeout',
                    'خطا: سایت‌مپ در مهلت ۳۰ ثانیه پاسخ نداد',
                    array( 'original_error' => $error_message )
                );
            }

            return new \WP_Error(
                'http_error',
                sprintf( 'خطا: دسترسی به سایت‌مپ ممکن نشد — %s', $error_message ),
                array( 'original_error' => $error_message )
            );
        }

        // Check HTTP status code.
        $status_code = wp_remote_retrieve_response_code( $response );
        if ( $status_code !== 200 ) {
            return new \WP_Error(
                'http_error',
                sprintf( 'خطا: سایت‌مپ با کد %d پاسخ داد', $status_code ),
                array( 'status_code' => $status_code )
            );
        }

        $body = wp_remote_retrieve_body( $response );

        if ( empty( $body ) ) {
            return new \WP_Error(
                'invalid_xml',
                'خطا: محتوای سایت‌مپ خالی است',
            );
        }

        // Try to parse as sitemap index first.
        $child_urls = self::parse_sitemap_index( $body );

        if ( is_wp_error( $child_urls ) ) {
            return $child_urls;
        }

        // If no child sitemaps found, try parsing as a single sitemap.
        if ( empty( $child_urls ) ) {
            $urls = self::parse_sitemap_xml( $body );

            if ( empty( $urls ) ) {
                return new \WP_Error(
                    'invalid_xml',
                    'خطا: محتوای سایت‌مپ XML معتبر نیست',
                );
            }

            // Enforce URL limit.
            if ( count( $urls ) > self::MAX_URLS ) {
                $urls = array_slice( $urls, 0, self::MAX_URLS, true );
            }

            return $urls;
        }

        // Fetch and parse each child sitemap.
        $all_urls = array();

        foreach ( $child_urls as $child_url ) {
            // Enforce URL limit.
            if ( count( $all_urls ) >= self::MAX_URLS ) {
                break;
            }

            $child_response = wp_remote_get( $child_url, array(
                'timeout'   => self::HTTP_TIMEOUT,
                'sslverify' => true,
            ) );

            // Skip unreachable child sitemaps with a warning.
            if ( is_wp_error( $child_response ) ) {
                error_log( sprintf(
                    '[BRZ Static Sitemap Importer] Child sitemap unreachable: %s — %s',
                    $child_url,
                    $child_response->get_error_message()
                ) );
                continue;
            }

            $child_status = wp_remote_retrieve_response_code( $child_response );
            if ( $child_status !== 200 ) {
                error_log( sprintf(
                    '[BRZ Static Sitemap Importer] Child sitemap returned HTTP %d: %s',
                    $child_status,
                    $child_url
                ) );
                continue;
            }

            $child_body = wp_remote_retrieve_body( $child_response );

            if ( empty( $child_body ) ) {
                error_log( sprintf(
                    '[BRZ Static Sitemap Importer] Child sitemap empty body: %s',
                    $child_url
                ) );
                continue;
            }

            $child_urls_parsed = self::parse_sitemap_xml( $child_body );

            // Merge child URLs, respecting the limit.
            foreach ( $child_urls_parsed as $url => $lastmod ) {
                if ( count( $all_urls ) >= self::MAX_URLS ) {
                    break;
                }
                $all_urls[ $url ] = $lastmod;
            }
        }

        return $all_urls;
    }

    /**
     * Parse a single sitemap XML string into URL entries.
     *
     * Extracts `<loc>` and optional `<lastmod>` elements from a sitemap XML
     * document. Handles the sitemap namespace correctly.
     *
     * @param string $xml_content Raw XML content of a sitemap.
     * @return array Associative array of [url => lastmod], where lastmod is
     *              a string (ISO 8601) or null if not present.
     */
    public static function parse_sitemap_xml( string $xml_content ): array {
        if ( empty( $xml_content ) ) {
            return array();
        }

        // Suppress XML parsing errors and handle them gracefully.
        $use_errors = libxml_use_internal_errors( true );

        $xml = simplexml_load_string( $xml_content );

        if ( false === $xml ) {
            libxml_clear_errors();
            libxml_use_internal_errors( $use_errors );
            return array();
        }

        $urls = array();

        // Register the sitemap namespace for XPath queries.
        $xml->registerXPathNamespace( 'sm', self::SITEMAP_NS );

        // Try namespace-aware parsing first.
        $url_elements = $xml->xpath( '//sm:url' );

        if ( ! empty( $url_elements ) ) {
            foreach ( $url_elements as $url_element ) {
                $url_element->registerXPathNamespace( 'sm', self::SITEMAP_NS );

                $loc_nodes = $url_element->xpath( 'sm:loc' );
                if ( empty( $loc_nodes ) ) {
                    continue;
                }

                $loc = trim( (string) $loc_nodes[0] );
                if ( empty( $loc ) ) {
                    continue;
                }

                $lastmod = null;
                $lastmod_nodes = $url_element->xpath( 'sm:lastmod' );
                if ( ! empty( $lastmod_nodes ) ) {
                    $lastmod_value = trim( (string) $lastmod_nodes[0] );
                    if ( ! empty( $lastmod_value ) ) {
                        $lastmod = $lastmod_value;
                    }
                }

                $urls[ $loc ] = $lastmod;
            }
        } else {
            // Fallback: try without namespace (some sitemaps omit the namespace).
            foreach ( $xml->url as $url_element ) {
                $loc = trim( (string) ( $url_element->loc ?? '' ) );
                if ( empty( $loc ) ) {
                    continue;
                }

                $lastmod = null;
                if ( isset( $url_element->lastmod ) ) {
                    $lastmod_value = trim( (string) $url_element->lastmod );
                    if ( ! empty( $lastmod_value ) ) {
                        $lastmod = $lastmod_value;
                    }
                }

                $urls[ $loc ] = $lastmod;
            }
        }

        libxml_clear_errors();
        libxml_use_internal_errors( $use_errors );

        return $urls;
    }

    /**
     * Parse a sitemap index XML to extract child sitemap URLs.
     *
     * @param string $xml_content Raw XML content of a sitemap index.
     * @return array|\WP_Error Array of child sitemap URLs, or WP_Error if XML is invalid.
     */
    private static function parse_sitemap_index( string $xml_content ): array|\WP_Error {
        // Suppress XML parsing errors.
        $use_errors = libxml_use_internal_errors( true );

        $xml = simplexml_load_string( $xml_content );

        if ( false === $xml ) {
            libxml_clear_errors();
            libxml_use_internal_errors( $use_errors );
            return new \WP_Error(
                'invalid_xml',
                'خطا: محتوای سایت‌مپ XML معتبر نیست',
            );
        }

        $child_urls = array();

        // Register namespace for XPath.
        $xml->registerXPathNamespace( 'sm', self::SITEMAP_NS );

        // Try namespace-aware parsing for <sitemap><loc> elements.
        $sitemap_elements = $xml->xpath( '//sm:sitemap/sm:loc' );

        if ( ! empty( $sitemap_elements ) ) {
            foreach ( $sitemap_elements as $loc ) {
                $url = trim( (string) $loc );
                if ( ! empty( $url ) ) {
                    $child_urls[] = $url;
                }
            }
        } else {
            // Fallback: try without namespace.
            if ( isset( $xml->sitemap ) ) {
                foreach ( $xml->sitemap as $sitemap_element ) {
                    $loc = trim( (string) ( $sitemap_element->loc ?? '' ) );
                    if ( ! empty( $loc ) ) {
                        $child_urls[] = $loc;
                    }
                }
            }
        }

        libxml_clear_errors();
        libxml_use_internal_errors( $use_errors );

        return $child_urls;
    }

    /**
     * Group URLs by path prefix for admin preview.
     *
     * Groups the given URLs by their path prefix (e.g., /product/, /shop/, /mag/)
     * and computes counts per group. Also compares against stored state to determine
     * how many URLs are new, changed (different lastmod), or removed.
     *
     * Known prefixes and their Persian labels:
     * - /product/          → "محصولات"
     * - /product-category/ → "دسته‌بندی محصولات"
     * - /product-tag/      → "برچسب محصولات"
     * - /product-brand/    → "برند محصولات"
     * - /mag/              → "مجله"
     * - /blog/             → "بلاگ"
     * - /shop/             → "فروشگاه"
     * - /category/         → "دسته‌بندی مطالب"
     * - Everything else    → "سایر"
     *
     * @param array $urls Associative array of [url => lastmod] (output from fetch_and_parse()).
     * @return array {
     *     @type int   $total_urls    Total number of URLs in the input.
     *     @type array $groups        Array of groups, each with 'prefix', 'count', 'label'.
     *     @type int   $new_urls      Count of URLs not present in stored state.
     *     @type int   $changed_urls  Count of URLs with a different lastmod than stored state.
     *     @type int   $removed_urls  Count of URLs in stored state but not in current input.
     * }
     */
    public static function preview_import( array $urls ): array {
        // Define known prefixes with their Persian labels.
        // Order matters: longer/more-specific prefixes should be checked first.
        $known_prefixes = array(
            '/product-category/' => 'دسته‌بندی محصولات',
            '/product-tag/'      => 'برچسب محصولات',
            '/product-brand/'    => 'برند محصولات',
            '/product/'          => 'محصولات',
            '/mag/'              => 'مجله',
            '/blog/'             => 'بلاگ',
            '/shop/'             => 'فروشگاه',
            '/category/'         => 'دسته‌بندی مطالب',
        );

        // Initialize group counts.
        $group_counts = array();
        foreach ( $known_prefixes as $prefix => $label ) {
            $group_counts[ $prefix ] = 0;
        }
        $group_counts['سایر'] = 0;

        // Group each URL by its path prefix.
        foreach ( $urls as $url => $lastmod ) {
            $path = wp_parse_url( $url, PHP_URL_PATH );

            if ( empty( $path ) ) {
                $group_counts['سایر']++;
                continue;
            }

            $matched = false;
            foreach ( $known_prefixes as $prefix => $label ) {
                if ( str_starts_with( $path, $prefix ) ) {
                    $group_counts[ $prefix ]++;
                    $matched = true;
                    break;
                }
            }

            if ( ! $matched ) {
                $group_counts['سایر']++;
            }
        }

        // Build groups array (only include groups with count > 0).
        $groups = array();
        foreach ( $known_prefixes as $prefix => $label ) {
            if ( $group_counts[ $prefix ] > 0 ) {
                $groups[] = array(
                    'prefix' => $prefix,
                    'count'  => $group_counts[ $prefix ],
                    'label'  => $label,
                );
            }
        }

        // Add "سایر" group if it has any URLs.
        if ( $group_counts['سایر'] > 0 ) {
            $groups[] = array(
                'prefix' => 'سایر',
                'count'  => $group_counts['سایر'],
                'label'  => 'سایر',
            );
        }

        // Compare against stored state to compute new/changed/removed counts.
        $stored_state = self::get_stored_state();

        $new_urls     = 0;
        $changed_urls = 0;

        foreach ( $urls as $url => $lastmod ) {
            if ( ! array_key_exists( $url, $stored_state ) ) {
                $new_urls++;
            } elseif ( $stored_state[ $url ] !== $lastmod ) {
                $changed_urls++;
            }
        }

        // Removed URLs: present in stored state but not in current input.
        $removed_urls = 0;
        foreach ( $stored_state as $url => $lastmod ) {
            if ( ! array_key_exists( $url, $urls ) ) {
                $removed_urls++;
            }
        }

        return array(
            'total_urls'   => count( $urls ),
            'groups'       => $groups,
            'new_urls'     => $new_urls,
            'changed_urls' => $changed_urls,
            'removed_urls' => $removed_urls,
        );
    }

    /**
     * Get stored sitemap state from brz_options.
     *
     * Reads the previously stored sitemap state (URL → lastmod mapping) from
     * brz_options['static_controller']['sitemap_stored_state']['urls'].
     *
     * @return array Associative array of [url => lastmod]. Returns empty array
     *              if no stored state exists.
     */
    public static function get_stored_state(): array {
        $settings = BRZ_Static_Controller::get_settings();

        if ( ! empty( $settings['sitemap_stored_state']['urls'] )
            && is_array( $settings['sitemap_stored_state']['urls'] )
        ) {
            return $settings['sitemap_stored_state']['urls'];
        }

        return array();
    }

    /**
     * Execute import: merge new URLs into selected_pages.
     *
     * For each URL in the input array:
     * - If the URL already exists in selected_pages: update lastmod and set
     *   page_status to "pending" only if lastmod has changed. If the existing
     *   page has page_source "manual", promote it to "sitemap" (Requirement 2.8).
     * - If the URL is new: add it with page_source="sitemap", page_status="pending",
     *   and detect page_type using BRZ_Static_Page_Detector (via url_to_postid())
     *   or URL path pattern matching as fallback.
     *
     * All existing manual pages not in the import set are preserved unchanged.
     * After import, saves the updated selected_pages and sitemap stored state.
     *
     * @param array $urls Associative array of [url => lastmod] to import.
     * @return array|\WP_Error Import stats on success: {imported, updated, skipped, total},
     *                         or WP_Error on failure.
     */
    public static function execute_import( array $urls ): array|\WP_Error {
        if ( empty( $urls ) ) {
            return array(
                'imported' => 0,
                'updated'  => 0,
                'skipped'  => 0,
                'total'    => 0,
            );
        }

        $settings       = BRZ_Static_Controller::get_settings();
        $selected_pages = $settings['selected_pages'] ?? array();

        // Build a URL index for quick lookup: url => array index.
        $url_index = array();
        foreach ( $selected_pages as $index => $page ) {
            if ( ! empty( $page['url'] ) ) {
                $url_index[ $page['url'] ] = $index;
            }
        }

        $imported = 0;
        $updated  = 0;
        $skipped  = 0;

        foreach ( $urls as $url => $lastmod ) {
            if ( isset( $url_index[ $url ] ) ) {
                // URL already exists in selected_pages.
                $existing_index = $url_index[ $url ];
                $existing_page  = $selected_pages[ $existing_index ];

                // Promote manual pages to sitemap source (Requirement 2.8).
                if ( isset( $existing_page['page_source'] ) && $existing_page['page_source'] === 'manual' ) {
                    $selected_pages[ $existing_index ]['page_source'] = 'sitemap';
                }

                // Update lastmod and set pending only if lastmod changed.
                if ( $existing_page['lastmod'] !== $lastmod ) {
                    $selected_pages[ $existing_index ]['lastmod']     = $lastmod;
                    $selected_pages[ $existing_index ]['page_status'] = 'pending';
                    $updated++;
                } else {
                    $skipped++;
                }
            } else {
                // New URL — detect page_type and add.
                $page_type    = self::detect_page_type_for_url( $url );
                $needs_review = self::needs_manual_review( $url );

                $new_entry = array(
                    'id'           => 0,
                    'type'         => 'url',
                    'url'          => $url,
                    'page_type'    => $page_type,
                    'page_source'  => 'sitemap',
                    'page_status'  => 'pending',
                    'lastmod'      => $lastmod,
                    'error_count'  => 0,
                    'content_hash' => null,
                    'needs_review' => $needs_review,
                );

                // Try to resolve WordPress post ID from URL.
                $post_id = url_to_postid( $url );
                if ( $post_id > 0 ) {
                    $new_entry['id']   = $post_id;
                    $new_entry['type'] = 'post';
                }

                $selected_pages[] = $new_entry;
                $imported++;
            }
        }

        // Save updated selected_pages.
        $settings['selected_pages'] = $selected_pages;

        // Save sitemap stored state.
        self::save_state( $urls );

        // Update last sync timestamp.
        $settings['last_sync_timestamp'] = gmdate( 'c' );

        // Persist settings.
        self::save_settings( $settings );

        // Auto-regenerate urls-map.json after successful import.
        if ( $imported > 0 || $updated > 0 ) {
            if ( class_exists( 'BRZ_Static_Change_Trigger' ) && method_exists( 'BRZ_Static_Change_Trigger', 'schedule_regeneration' ) ) {
                BRZ_Static_Change_Trigger::schedule_regeneration();
            }
        }

        return array(
            'imported' => $imported,
            'updated'  => $updated,
            'skipped'  => $skipped,
            'total'    => $imported + $updated + $skipped,
        );
    }

    /**
     * Execute import in batches for large URL sets (>5000).
     *
     * Processes BATCH_SIZE (500) URLs starting from the given offset. If more
     * URLs remain after processing the current batch, schedules the next batch
     * via wp_schedule_single_event with a 60-second delay. Stores progress in
     * a transient (brz_static_import_progress) with total, processed, and
     * percentage values.
     *
     * On final batch completion, saves the full sitemap state and clears the
     * progress transient.
     *
     * @param array $urls Full URL array of [url => lastmod] to import.
     * @param int   $offset Current batch offset (default 0).
     * @return bool|\WP_Error True on successful batch processing, WP_Error on failure.
     */
    public static function execute_batch_import( array $urls, int $offset = 0 ): bool|\WP_Error {
        if ( empty( $urls ) ) {
            return new \WP_Error(
                'empty_urls',
                'خطا: لیست URLها خالی است',
            );
        }

        $total     = count( $urls );
        $url_keys  = array_keys( $urls );
        $batch_end = min( $offset + self::BATCH_SIZE, $total );

        // Extract the current batch slice.
        $batch_urls = array();
        for ( $i = $offset; $i < $batch_end; $i++ ) {
            $url               = $url_keys[ $i ];
            $batch_urls[ $url ] = $urls[ $url ];
        }

        // Process the current batch using execute_import().
        $result = self::execute_import( $batch_urls );

        if ( is_wp_error( $result ) ) {
            // Clear progress transient on failure.
            delete_transient( 'brz_static_import_progress' );
            return $result;
        }

        $processed = $batch_end;
        $percentage = (int) round( ( $processed / $total ) * 100 );

        if ( $processed < $total ) {
            // More URLs remain — store progress and schedule next batch.
            set_transient( 'brz_static_import_progress', array(
                'total'      => $total,
                'processed'  => $processed,
                'percentage' => $percentage,
            ), HOUR_IN_SECONDS );

            // Schedule the next batch with a 60-second delay.
            wp_schedule_single_event(
                time() + 60,
                self::BATCH_CRON_HOOK,
                array( $urls, $processed )
            );
        } else {
            // Final batch completed — save full state and clear progress.
            self::save_state( $urls );
            delete_transient( 'brz_static_import_progress' );
        }

        return true;
    }

    /**
     * Get the current batch import progress.
     *
     * Returns the progress data stored in the brz_static_import_progress
     * transient, or null if no import is currently running.
     *
     * @return array|null Progress array {total, processed, percentage} or null.
     */
    public static function get_import_progress(): ?array {
        $progress = get_transient( 'brz_static_import_progress' );

        if ( false === $progress || ! is_array( $progress ) ) {
            return null;
        }

        return $progress;
    }

    /**
     * Compare current sitemap state against a previous state.
     *
     * Identifies additions (URLs in current but not in previous),
     * modifications (URLs in both but with different lastmod), and
     * deletions (URLs in previous but not in current).
     *
     * @param array $current  Current sitemap state: [url => lastmod].
     * @param array $previous Previous sitemap state: [url => lastmod].
     * @return array {
     *     @type array $additions     URLs present in current but not in previous.
     *     @type array $modifications URLs present in both but with different lastmod.
     *     @type array $deletions     URLs present in previous but not in current.
     * }
     */
    public static function compute_diff( array $current, array $previous ): array {
        $additions     = array();
        $modifications = array();
        $deletions     = array();

        // Find additions and modifications.
        foreach ( $current as $url => $lastmod ) {
            if ( ! array_key_exists( $url, $previous ) ) {
                $additions[] = $url;
            } elseif ( $previous[ $url ] !== $lastmod ) {
                $modifications[] = $url;
            }
        }

        // Find deletions.
        foreach ( $previous as $url => $lastmod ) {
            if ( ! array_key_exists( $url, $current ) ) {
                $deletions[] = $url;
            }
        }

        return array(
            'additions'     => $additions,
            'modifications' => $modifications,
            'deletions'     => $deletions,
        );
    }

    /**
     * Save sitemap state to brz_options.
     *
     * Persists the sitemap URL → lastmod mapping along with metadata
     * (updated_at timestamp and url_count) to the settings.
     *
     * @param array $state Associative array of [url => lastmod] to persist.
     */
    private static function save_state( array $state ): void {
        $options = get_option( 'brz_options', array() );

        if ( ! is_array( $options ) ) {
            $options = array();
        }

        if ( ! isset( $options[ BRZ_Static_Controller::OPTION_KEY ] ) || ! is_array( $options[ BRZ_Static_Controller::OPTION_KEY ] ) ) {
            $options[ BRZ_Static_Controller::OPTION_KEY ] = array();
        }

        $options[ BRZ_Static_Controller::OPTION_KEY ]['sitemap_stored_state'] = array(
            'urls'       => $state,
            'updated_at' => gmdate( 'c' ),
            'url_count'  => count( $state ),
        );

        update_option( 'brz_options', $options );
    }

    /**
     * Detect page type for a URL using URL path prefix matching.
     *
     * Uses URL path prefix matching to determine page_type:
     * - /product/          → "product"
     * - /product-category/ → "category"
     * - /brand/            → "brand"
     * - /product-tag/      → "tag"
     * - /category/         → "category"
     * - /tag/              → "tag"
     * - Everything else    → "page" (flagged for manual review)
     *
     * Order matters: longer/more-specific prefixes are checked first to avoid
     * false matches (e.g., /product-category/ before /product/).
     *
     * @param string $url The URL to detect page type for.
     * @return string The detected page_type string.
     */
    public static function detect_page_type_for_url( string $url ): string {
        $parsed = wp_parse_url( $url );
        $path   = $parsed['path'] ?? '/';

        // Define prefix → page_type mapping.
        // Order: longer/more-specific prefixes first to prevent false matches.
        $prefix_map = array(
            '/product-category/' => 'category',
            '/product-tag/'      => 'tag',
            '/product-brand/'    => 'brand',
            '/brand/'            => 'brand',
            '/product/'          => 'product',
            '/category/'         => 'category',
            '/tag/'              => 'tag',
        );

        foreach ( $prefix_map as $prefix => $type ) {
            if ( str_starts_with( $path, $prefix ) ) {
                return $type;
            }
        }

        // Unknown prefix — default to "page" (flagged for manual review).
        return 'page';
    }

    /**
     * Check if a URL's page_type requires manual review.
     *
     * URLs that don't match any known prefix are assigned page_type "page"
     * and should be flagged for operator manual review.
     *
     * @param string $url The URL to check.
     * @return bool True if the URL's page_type needs manual review.
     */
    public static function needs_manual_review( string $url ): bool {
        return self::detect_page_type_for_url( $url ) === 'page';
    }

    /**
     * Get the list of URLs previously removed by the operator.
     *
     * These URLs are excluded from auto-sync re-import. Only a manual
     * "re-add" by the operator can bring them back.
     *
     * @return array List of removed URL strings.
     */
    public static function get_removed_urls(): array {
        $settings = BRZ_Static_Controller::get_settings();

        if ( ! empty( $settings['removed_urls'] ) && is_array( $settings['removed_urls'] ) ) {
            return $settings['removed_urls'];
        }

        return array();
    }

    /**
     * Track a URL as removed by the operator.
     *
     * Adds the URL to the removed_urls list so auto-sync skips it in future imports.
     *
     * @param string $url The URL to mark as removed.
     */
    public static function track_removed_url( string $url ): void {
        $settings     = BRZ_Static_Controller::get_settings();
        $removed_urls = $settings['removed_urls'] ?? array();

        if ( ! in_array( $url, $removed_urls, true ) ) {
            $removed_urls[] = $url;
            $settings['removed_urls'] = $removed_urls;
            self::save_settings( $settings );
        }
    }

    /**
     * Track multiple URLs as removed by the operator.
     *
     * Adds each URL to the removed_urls list so auto-sync skips them in future imports.
     *
     * @param array $urls Array of URL strings to mark as removed.
     */
    public static function track_removed_urls_bulk( array $urls ): void {
        $settings     = BRZ_Static_Controller::get_settings();
        $removed_urls = $settings['removed_urls'] ?? array();

        $changed = false;
        foreach ( $urls as $url ) {
            if ( ! in_array( $url, $removed_urls, true ) ) {
                $removed_urls[] = $url;
                $changed = true;
            }
        }

        if ( $changed ) {
            $settings['removed_urls'] = $removed_urls;
            self::save_settings( $settings );
        }
    }

    /**
     * Remove a URL from the removed_urls tracking list.
     *
     * Used when an operator explicitly re-adds a previously removed URL,
     * so it won't be skipped by future auto-sync.
     *
     * @param string $url The URL to untrack.
     */
    public static function untrack_removed_url( string $url ): void {
        $settings     = BRZ_Static_Controller::get_settings();
        $removed_urls = $settings['removed_urls'] ?? array();

        $key = array_search( $url, $removed_urls, true );
        if ( $key !== false ) {
            unset( $removed_urls[ $key ] );
            $settings['removed_urls'] = array_values( $removed_urls );
            self::save_settings( $settings );
        }
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

    /**
     * Daily auto-sync cron handler.
     *
     * Fetches the sitemap, compares against stored state, flags changed pages
     * as pending, removes deleted sitemap URLs (only page_source="sitemap"),
     * and optionally triggers regeneration.
     *
     * On fetch failure: increments sync_retry_count and schedules a retry
     * (or disables auto_sync if max retries reached).
     */
    public static function auto_sync(): void {
        $settings = BRZ_Static_Controller::get_settings();

        // Fetch and parse the sitemap.
        $current_urls = self::fetch_and_parse();

        // Handle fetch failure: schedule retry or disable auto_sync.
        if ( is_wp_error( $current_urls ) ) {
            $retry_count = (int) ( $settings['sync_retry_count'] ?? 0 );
            $retry_count++;

            $settings['sync_retry_count'] = $retry_count;
            self::save_settings( $settings );

            if ( $retry_count < self::MAX_RETRIES ) {
                // Schedule a retry in RETRY_INTERVAL seconds.
                if ( ! wp_next_scheduled( self::RETRY_HOOK ) ) {
                    wp_schedule_single_event( time() + self::RETRY_INTERVAL, self::RETRY_HOOK );
                }
            } else {
                // Max retries reached — disable auto_sync.
                $settings['auto_sync_enabled'] = false;
                self::save_settings( $settings );

                error_log( sprintf(
                    '[BRZ Static Sitemap Importer] Auto-sync disabled after %d failed attempts. Last error: %s',
                    $retry_count,
                    $current_urls->get_error_message()
                ) );

                // Set a transient for persistent admin notice.
                set_transient( 'brz_static_sync_failed_notice', array(
                    'message' => sprintf(
                        'همگام‌سازی خودکار سایت‌مپ پس از %d تلاش ناموفق غیرفعال شد. دلیل: %s',
                        $retry_count,
                        $current_urls->get_error_message()
                    ),
                    'attempts' => $retry_count,
                ), 0 ); // No expiration — persistent until dismissed.
            }

            return;
        }

        // Fetch succeeded — get stored state and compute diff.
        $previous_state = self::get_stored_state();
        $diff           = self::compute_diff( $current_urls, $previous_state );

        $selected_pages = $settings['selected_pages'] ?? array();

        // Build URL index for quick lookup.
        $url_index = array();
        foreach ( $selected_pages as $index => $page ) {
            if ( ! empty( $page['url'] ) ) {
                $url_index[ $page['url'] ] = $index;
            }
        }

        // Handle additions: add new URLs with page_source="sitemap", page_status="pending".
        // Skip URLs that were previously manually removed by the operator.
        $removed_urls = self::get_removed_urls();
        $removed_set  = array_flip( $removed_urls );

        foreach ( $diff['additions'] as $url ) {
            if ( isset( $url_index[ $url ] ) ) {
                // URL already exists (shouldn't happen in additions, but be safe).
                continue;
            }

            // Skip previously removed URLs — operator must re-add manually.
            if ( isset( $removed_set[ $url ] ) ) {
                continue;
            }

            $page_type    = self::detect_page_type_for_url( $url );
            $needs_review = self::needs_manual_review( $url );
            $lastmod      = $current_urls[ $url ] ?? null;

            $new_entry = array(
                'id'           => 0,
                'type'         => 'url',
                'url'          => $url,
                'page_type'    => $page_type,
                'page_source'  => 'sitemap',
                'page_status'  => 'pending',
                'lastmod'      => $lastmod,
                'error_count'  => 0,
                'content_hash' => null,
                'needs_review' => $needs_review,
            );

            // Try to resolve WordPress post ID.
            $post_id = url_to_postid( $url );
            if ( $post_id > 0 ) {
                $new_entry['id']   = $post_id;
                $new_entry['type'] = 'post';
            }

            $selected_pages[] = $new_entry;
        }

        // Handle modifications: mark existing pages as "pending".
        foreach ( $diff['modifications'] as $url ) {
            if ( isset( $url_index[ $url ] ) ) {
                $idx = $url_index[ $url ];
                $selected_pages[ $idx ]['page_status'] = 'pending';
                $selected_pages[ $idx ]['lastmod']     = $current_urls[ $url ] ?? null;
            }
        }

        // Handle deletions: remove ONLY pages with page_source="sitemap".
        foreach ( $diff['deletions'] as $url ) {
            if ( isset( $url_index[ $url ] ) ) {
                $idx  = $url_index[ $url ];
                $page = $selected_pages[ $idx ];

                // Only remove sitemap-sourced pages; preserve manual pages.
                if ( isset( $page['page_source'] ) && $page['page_source'] === 'sitemap' ) {
                    unset( $selected_pages[ $idx ] );
                }
            }
        }

        // Re-index the array after potential unset operations.
        $selected_pages = array_values( $selected_pages );

        // Save updated state.
        $settings['selected_pages']      = $selected_pages;
        $settings['last_sync_timestamp'] = gmdate( 'c' );
        $settings['sync_retry_count']    = 0;
        self::save_settings( $settings );

        // Save new sitemap stored state.
        self::save_state( $current_urls );

        // If auto_regenerate_enabled, trigger regeneration.
        if ( ! empty( $settings['auto_regenerate_enabled'] ) ) {
            if ( class_exists( 'BRZ_Static_Change_Trigger' )
                && method_exists( 'BRZ_Static_Change_Trigger', 'schedule_regeneration_if_auto' )
            ) {
                BRZ_Static_Change_Trigger::schedule_regeneration_if_auto();
            }
        }
    }

    /**
     * Schedule daily sitemap sync via WP-Cron.
     *
     * Registers CRON_HOOK as a daily recurring event if not already scheduled.
     */
    public static function schedule_daily_sync(): void {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'daily', self::CRON_HOOK );
        }
    }

    /**
     * Unschedule daily sitemap sync and retry events.
     *
     * Removes all scheduled instances of CRON_HOOK and RETRY_HOOK.
     */
    public static function unschedule_daily_sync(): void {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
        }

        $retry_timestamp = wp_next_scheduled( self::RETRY_HOOK );
        if ( $retry_timestamp ) {
            wp_unschedule_event( $retry_timestamp, self::RETRY_HOOK );
        }

        // Clear all instances (in case multiple are scheduled).
        wp_clear_scheduled_hook( self::CRON_HOOK );
        wp_clear_scheduled_hook( self::RETRY_HOOK );
    }

    /**
     * Handle retry after a failed auto-sync attempt.
     *
     * Increments sync_retry_count. If below MAX_RETRIES, schedules another
     * retry in RETRY_INTERVAL seconds. If at or above MAX_RETRIES, disables
     * auto_sync_enabled, displays an admin notice, and logs the error.
     */
    public static function handle_retry(): void {
        $settings    = BRZ_Static_Controller::get_settings();
        $retry_count = (int) ( $settings['sync_retry_count'] ?? 0 );

        // Attempt the sync again.
        $current_urls = self::fetch_and_parse();

        if ( ! is_wp_error( $current_urls ) ) {
            // Retry succeeded — perform the full sync logic.
            $previous_state = self::get_stored_state();
            $diff           = self::compute_diff( $current_urls, $previous_state );

            $selected_pages = $settings['selected_pages'] ?? array();

            // Build URL index.
            $url_index = array();
            foreach ( $selected_pages as $index => $page ) {
                if ( ! empty( $page['url'] ) ) {
                    $url_index[ $page['url'] ] = $index;
                }
            }

            // Handle additions.
            // Skip URLs that were previously manually removed by the operator.
            $removed_urls = self::get_removed_urls();
            $removed_set  = array_flip( $removed_urls );

            foreach ( $diff['additions'] as $url ) {
                if ( isset( $url_index[ $url ] ) ) {
                    continue;
                }

                // Skip previously removed URLs — operator must re-add manually.
                if ( isset( $removed_set[ $url ] ) ) {
                    continue;
                }

                $page_type    = self::detect_page_type_for_url( $url );
                $needs_review = self::needs_manual_review( $url );
                $lastmod      = $current_urls[ $url ] ?? null;

                $new_entry = array(
                    'id'           => 0,
                    'type'         => 'url',
                    'url'          => $url,
                    'page_type'    => $page_type,
                    'page_source'  => 'sitemap',
                    'page_status'  => 'pending',
                    'lastmod'      => $lastmod,
                    'error_count'  => 0,
                    'content_hash' => null,
                    'needs_review' => $needs_review,
                );

                $post_id = url_to_postid( $url );
                if ( $post_id > 0 ) {
                    $new_entry['id']   = $post_id;
                    $new_entry['type'] = 'post';
                }

                $selected_pages[] = $new_entry;
            }

            // Handle modifications.
            foreach ( $diff['modifications'] as $url ) {
                if ( isset( $url_index[ $url ] ) ) {
                    $idx = $url_index[ $url ];
                    $selected_pages[ $idx ]['page_status'] = 'pending';
                    $selected_pages[ $idx ]['lastmod']     = $current_urls[ $url ] ?? null;
                }
            }

            // Handle deletions (only sitemap-sourced pages).
            foreach ( $diff['deletions'] as $url ) {
                if ( isset( $url_index[ $url ] ) ) {
                    $idx  = $url_index[ $url ];
                    $page = $selected_pages[ $idx ];

                    if ( isset( $page['page_source'] ) && $page['page_source'] === 'sitemap' ) {
                        unset( $selected_pages[ $idx ] );
                    }
                }
            }

            $selected_pages = array_values( $selected_pages );

            // Save updated state.
            $settings['selected_pages']      = $selected_pages;
            $settings['last_sync_timestamp'] = gmdate( 'c' );
            $settings['sync_retry_count']    = 0;
            self::save_settings( $settings );
            self::save_state( $current_urls );

            // Trigger regeneration if enabled.
            if ( ! empty( $settings['auto_regenerate_enabled'] ) ) {
                if ( class_exists( 'BRZ_Static_Change_Trigger' )
                    && method_exists( 'BRZ_Static_Change_Trigger', 'schedule_regeneration_if_auto' )
                ) {
                    BRZ_Static_Change_Trigger::schedule_regeneration_if_auto();
                }
            }

            return;
        }

        // Retry failed — increment count.
        $retry_count++;
        $settings['sync_retry_count'] = $retry_count;

        if ( $retry_count < self::MAX_RETRIES ) {
            // Schedule another retry.
            self::save_settings( $settings );

            if ( ! wp_next_scheduled( self::RETRY_HOOK ) ) {
                wp_schedule_single_event( time() + self::RETRY_INTERVAL, self::RETRY_HOOK );
            }
        } else {
            // Max retries reached — disable auto_sync.
            $settings['auto_sync_enabled'] = false;
            self::save_settings( $settings );

            error_log( sprintf(
                '[BRZ Static Sitemap Importer] Auto-sync disabled after %d failed attempts. Last error: %s',
                $retry_count,
                $current_urls->get_error_message()
            ) );

            // Set persistent admin notice.
            set_transient( 'brz_static_sync_failed_notice', array(
                'message' => sprintf(
                    'همگام‌سازی خودکار سایت‌مپ پس از %d تلاش ناموفق غیرفعال شد. دلیل: %s',
                    $retry_count,
                    $current_urls->get_error_message()
                ),
                'attempts' => $retry_count,
            ), 0 );
        }
    }

    /**
     * Get the configured sitemap URL or default to site's sitemap_index.xml.
     *
     * @return string The sitemap URL to fetch.
     */
    private static function get_sitemap_url(): string {
        $settings = BRZ_Static_Controller::get_settings();

        if ( ! empty( $settings['sitemap_url'] ) ) {
            return $settings['sitemap_url'];
        }

        return trailingslashit( get_site_url() ) . 'sitemap_index.xml';
    }
}
