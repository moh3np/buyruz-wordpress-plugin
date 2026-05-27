<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Smart Linker Link Health Analyzer.
 *
 * Scans content for broken links, noindex targets, and external link issues.
 * Supports both manual and scheduled scanning with async processing.
 *
 * @since 2.10.0
 */
class BRZ_Smart_Linker_Health {

    /** @var string DB table suffix */
    const TABLE_SUFFIX = 'brz_link_health';
    
    /** @var int Max links to check per batch */
    const BATCH_SIZE = 50;
    
    /** @var int Rate limit for external links (requests per second) */
    const RATE_LIMIT = 5;
    
    /** @var int Cache duration in seconds (24 hours) */
    const CACHE_DURATION = 86400;

    /**
     * Get table name.
     *
     * @return string
     */
    public static function table() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    /**
     * Create or upgrade the link health table.
     */
    public static function migrate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source_id bigint(20) unsigned NOT NULL,
            source_type varchar(50) NOT NULL DEFAULT 'post',
            source_url text NOT NULL,
            link_url text NOT NULL,
            link_text varchar(255) NULL,
            link_type enum('internal','external') NOT NULL DEFAULT 'internal',
            status_code smallint(3) NULL,
            status_message varchar(100) NULL,
            is_nofollow tinyint(1) NOT NULL DEFAULT 0,
            is_sponsored tinyint(1) NOT NULL DEFAULT 0,
            is_ugc tinyint(1) NOT NULL DEFAULT 0,
            target_is_noindex tinyint(1) NOT NULL DEFAULT 0,
            target_post_id bigint(20) unsigned NULL,
            redirect_count tinyint(2) NOT NULL DEFAULT 0,
            redirect_chain text NULL,
            final_url text NULL,
            response_time int(11) NULL,
            error_message text NULL,
            last_checked datetime NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY source (source_id, source_type),
            KEY link_type (link_type),
            KEY status_code (status_code),
            KEY target_is_noindex (target_is_noindex),
            KEY last_checked (last_checked)
        ) {$charset_collate};";

        dbDelta( $sql );
    }

    /**
     * Extract all links from HTML content.
     *
     * @param string $content HTML content
     * @return array Array of link data
     */
    public static function extract_links( $content ) {
        $links = array();
        
        if ( empty( $content ) ) {
            return $links;
        }

        // Use DOMDocument for reliable HTML parsing
        $dom = new DOMDocument();
        libxml_use_internal_errors( true );
        $dom->loadHTML( '<?xml encoding="UTF-8">' . $content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
        libxml_clear_errors();

        $anchors = $dom->getElementsByTagName( 'a' );
        
        foreach ( $anchors as $anchor ) {
            $href = $anchor->getAttribute( 'href' ) ?? '';
            
            // Skip empty, anchor-only, javascript, and mail links
            if ( empty( $href ) || 
                 str_starts_with( $href, '#' ) || 
                 str_starts_with( $href, 'javascript:' ) ||
                 str_starts_with( $href, 'mailto:' ) ||
                 str_starts_with( $href, 'tel:' ) ) {
                continue;
            }
            
            $rel = strtolower( $anchor->getAttribute( 'rel' ) ?? '' );
            
            $links[] = array(
                'url'          => $href,
                'text'         => mb_substr( trim( $anchor->textContent ?? '' ), 0, 255, 'UTF-8' ),
                'is_nofollow'  => str_contains( $rel, 'nofollow' ) ? 1 : 0,
                'is_sponsored' => str_contains( $rel, 'sponsored' ) ? 1 : 0,
                'is_ugc'       => str_contains( $rel, 'ugc' ) ? 1 : 0,
            );
        }
        
        return $links;
    }

    /**
     * Determine if a URL is internal or external.
     *
     * @param string $url The URL to check
     * @return string 'internal' or 'external'
     */
    public static function get_link_type( $url ) {
        $site_host = wp_parse_url( home_url(), PHP_URL_HOST );
        $link_host = wp_parse_url( $url, PHP_URL_HOST );
        
        // Relative URLs are internal
        if ( empty( $link_host ) ) {
            return 'internal';
        }
        
        // Compare hosts (handle www prefix)
        $site_host = preg_replace( '/^www\./', '', $site_host );
        $link_host = preg_replace( '/^www\./', '', $link_host );
        
        return ( $site_host === $link_host ) ? 'internal' : 'external';
    }

    /**
     * Resolve a relative URL to absolute.
     *
     * @param string $url Possibly relative URL
     * @param string $base_url Base URL for resolution
     * @return string Absolute URL
     */
    public static function resolve_url( $url, $base_url = null ) {
        if ( empty( $url ) ) {
            return '';
        }
        
        // Already absolute
        if ( preg_match( '#^https?://#i', $url ) ) {
            return $url;
        }
        
        $base = $base_url ?: home_url();
        
        // Protocol-relative
        if ( str_starts_with( $url, '//' ) ) {
            return 'https:' . $url;
        }
        
        // Root-relative
        if ( str_starts_with( $url, '/' ) ) {
            return trailingslashit( $base ) . ltrim( $url, '/' );
        }
        
        // Relative
        return trailingslashit( $base ) . $url;
    }

    /**
     * Check a single link and return status data.
     *
     * @param string $url URL to check
     * @param bool   $follow_redirects Whether to follow redirects
     * @return array Status data
     */
    public static function check_link( $url, $follow_redirects = true ) {
        $start_time = microtime( true );
        
        $result = array(
            'status_code'    => 0,
            'status_message' => '',
            'redirect_count' => 0,
            'redirect_chain' => array(),
            'final_url'      => $url,
            'response_time'  => 0,
            'error_message'  => null,
        );
        
        // Use HEAD request first (faster)
        $args = array(
            'timeout'     => 10,
            'redirection' => $follow_redirects ? 5 : 0,
            'sslverify'   => false,
            'user-agent'  => 'Mozilla/5.0 (compatible; BuyruzLinkChecker/1.0)',
        );
        
        $response = wp_remote_head( $url, $args );
        
        // Some servers don't support HEAD, fallback to GET
        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) >= 400 ) {
            $response = wp_remote_get( $url, $args );
        }
        
        $result['response_time'] = round( ( microtime( true ) - $start_time ) * 1000 );
        
        if ( is_wp_error( $response ) ) {
            $result['error_message'] = $response->get_error_message();
            $result['status_message'] = 'Connection Error';
            return $result;
        }
        
        $result['status_code'] = wp_remote_retrieve_response_code( $response );
        $result['status_message'] = self::get_status_message( $result['status_code'] );
        
        // Track redirects if available
        $response_url = wp_remote_retrieve_header( $response, 'location' );
        if ( ! empty( $response_url ) && $response_url !== $url ) {
            $result['redirect_count'] = 1;
            $result['redirect_chain'] = array( $response_url );
            $result['final_url'] = $response_url;
        }
        
        return $result;
    }

    /**
     * Get human-readable status message.
     *
     * @param int $code HTTP status code
     * @return string
     */
    public static function get_status_message( $code ) {
        $messages = array(
            200 => 'OK',
            201 => 'Created',
            301 => 'Moved Permanently',
            302 => 'Found (Redirect)',
            303 => 'See Other',
            304 => 'Not Modified',
            307 => 'Temporary Redirect',
            308 => 'Permanent Redirect',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            408 => 'Request Timeout',
            410 => 'Gone',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            504 => 'Gateway Timeout',
        );
        
        return isset( $messages[ $code ] ) ? $messages[ $code ] : "HTTP {$code}";
    }

    /**
     * Check if an internal URL target is noindex.
     *
     * @param string $url Internal URL to check
     * @return array [ 'is_noindex' => bool, 'post_id' => int|null ]
     */
    public static function check_noindex_target( $url ) {
        $result = array(
            'is_noindex' => false,
            'post_id'    => null,
        );
        
        // Try to get post ID from URL
        $post_id = url_to_postid( $url );
        
        if ( ! $post_id ) {
            // Try taxonomy terms
            $term = self::url_to_term( $url );
            if ( $term ) {
                $result['post_id'] = $term->term_id;
                
                // Check RankMath noindex for term
                if ( class_exists( 'RankMath' ) ) {
                    $robots = get_term_meta( $term->term_id, 'rank_math_robots', true );
                    if ( is_array( $robots ) && in_array( 'noindex', $robots, true ) ) {
                        $result['is_noindex'] = true;
                    }
                }
            }
            return $result;
        }
        
        $result['post_id'] = $post_id;
        
        // Check post status
        $post = get_post( $post_id );
        if ( ! $post || $post->post_status !== 'publish' ) {
            $result['is_noindex'] = true;
            return $result;
        }
        
        // Check RankMath noindex
        if ( class_exists( 'RankMath' ) ) {
            $robots = get_post_meta( $post_id, 'rank_math_robots', true );
            if ( is_array( $robots ) && in_array( 'noindex', $robots, true ) ) {
                $result['is_noindex'] = true;
            }
        }
        
        return $result;
    }

    /**
     * Try to find term from URL.
     *
     * @param string $url URL to check
     * @return WP_Term|null
     */
    private static function url_to_term( $url ) {
        $path = wp_parse_url( $url, PHP_URL_PATH );
        if ( empty( $path ) ) {
            return null;
        }
        
        $slug = basename( untrailingslashit( $path ) );
        
        // Check common taxonomies
        $taxonomies = array( 'category', 'post_tag', 'product_cat', 'product_tag' );
        foreach ( $taxonomies as $taxonomy ) {
            $term = get_term_by( 'slug', $slug, $taxonomy );
            if ( $term ) {
                return $term;
            }
        }
        
        return null;
    }

    /**
     * Scan a single post/page/product for links.
     *
     * @param int    $post_id Post ID to scan
     * @param string $post_type Post type
     * @return int Number of links found
     */
    public static function scan_post( $post_id, $post_type = 'post' ) {
        global $wpdb;
        
        $post = get_post( $post_id );
        if ( ! $post || $post->post_status !== 'publish' ) {
            return 0;
        }
        
        $content = $post->post_content;
        $links = self::extract_links( $content );
        
        if ( empty( $links ) ) {
            return 0;
        }
        
        $table = self::table();
        $source_url = get_permalink( $post_id );
        
        // Clear old entries for this post
        $wpdb->delete( $table, array(
            'source_id'   => $post_id,
            'source_type' => $post_type,
        ) );
        
        $count = 0;
        foreach ( $links as $link ) {
            $url = self::resolve_url( $link['url'], $source_url );
            $type = self::get_link_type( $url );
            
            // Insert link record (status will be checked later)
            $wpdb->insert( $table, array(
                'source_id'    => $post_id,
                'source_type'  => $post_type,
                'source_url'   => $source_url,
                'link_url'     => $url,
                'link_text'    => $link['text'],
                'link_type'    => $type,
                'is_nofollow'  => $link['is_nofollow'],
                'is_sponsored' => $link['is_sponsored'],
                'is_ugc'       => $link['is_ugc'],
                'created_at'   => current_time( 'mysql' ),
            ) );
            
            $count++;
        }
        
        return $count;
    }

    /**
     * Scan all published content.
     *
     * @param array $post_types Post types to scan
     * @return array Stats about the scan
     */
    public static function scan_all_content( $post_types = array( 'post', 'page', 'product' ) ) {
        $stats = array(
            'posts_scanned' => 0,
            'links_found'   => 0,
            'started_at'    => current_time( 'mysql' ),
        );
        
        $posts = get_posts( array(
            'post_type'      => $post_types,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ) );
        
        foreach ( $posts as $post_id ) {
            $post = get_post( $post_id );
            $count = self::scan_post( $post_id, $post->post_type );
            $stats['links_found'] += $count;
            $stats['posts_scanned']++;
        }
        
        $stats['completed_at'] = current_time( 'mysql' );
        
        // Store scan timestamp
        update_option( 'brz_link_health_last_scan', $stats );
        
        return $stats;
    }

    /**
     * Check unchecked links in batches.
     *
     * @param int $batch_size Number of links to check
     * @return array Stats about the check
     */
    public static function check_pending_links( $batch_size = null ) {
        global $wpdb;
        
        if ( null === $batch_size ) {
            $batch_size = self::BATCH_SIZE;
        }
        
        $table = self::table();
        
        // Get unchecked links
        $links = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE last_checked IS NULL LIMIT %d",
            $batch_size
        ) );
        
        $stats = array(
            'checked'  => 0,
            'ok'       => 0,
            'broken'   => 0,
            'noindex'  => 0,
            'redirect' => 0,
        );
        
        foreach ( $links as $link ) {
            $check = self::check_link( $link->link_url );
            
            $update_data = array(
                'status_code'    => $check['status_code'],
                'status_message' => $check['status_message'],
                'redirect_count' => $check['redirect_count'],
                'redirect_chain' => ! empty( $check['redirect_chain'] ) ? wp_json_encode( $check['redirect_chain'] ) : null,
                'final_url'      => $check['final_url'],
                'response_time'  => $check['response_time'],
                'error_message'  => $check['error_message'],
                'last_checked'   => current_time( 'mysql' ),
            );
            
            // Check noindex for internal links
            if ( $link->link_type === 'internal' && $check['status_code'] === 200 ) {
                $noindex = self::check_noindex_target( $link->link_url );
                $update_data['target_is_noindex'] = $noindex['is_noindex'] ? 1 : 0;
                $update_data['target_post_id'] = $noindex['post_id'];
                
                if ( $noindex['is_noindex'] ) {
                    $stats['noindex']++;
                }
            }
            
            $wpdb->update( $table, $update_data, array( 'id' => $link->id ) );
            
            $stats['checked']++;
            
            if ( $check['status_code'] >= 200 && $check['status_code'] < 400 ) {
                $stats['ok']++;
            } else {
                $stats['broken']++;
            }
            
            if ( $check['redirect_count'] > 0 ) {
                $stats['redirect']++;
            }
            
            // Rate limiting for external links
            if ( $link->link_type === 'external' ) {
                usleep( 1000000 / self::RATE_LIMIT ); // Sleep to respect rate limit
            }
        }
        
        return $stats;
    }

    /**
     * Get health statistics.
     *
     * @return array
     */
    public static function get_stats() {
        global $wpdb;
        $table = self::table();
        
        // Check if table exists
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
            return array(
                'total'           => 0,
                'checked'         => 0,
                'pending'         => 0,
                'ok'              => 0,
                'broken'          => 0,
                'noindex'         => 0,
                'redirect'        => 0,
                'internal'        => 0,
                'external'        => 0,
                'nofollow'        => 0,
                'last_scan'       => null,
            );
        }
        
        return array(
            'total'           => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ),
            'checked'         => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE last_checked IS NOT NULL" ),
            'pending'         => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE last_checked IS NULL" ),
            'ok'              => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status_code >= 200 AND status_code < 400" ),
            'broken'          => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE (status_code >= 400 OR status_code = 0) AND last_checked IS NOT NULL" ),
            'noindex'         => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE target_is_noindex = 1" ),
            'redirect'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE redirect_count > 0" ),
            'internal'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE link_type = 'internal'" ),
            'external'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE link_type = 'external'" ),
            'nofollow'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE is_nofollow = 1" ),
            'last_scan'       => get_option( 'brz_link_health_last_scan', null ),
        );
    }

    /**
     * Get issues (broken, noindex, redirect) for display.
     *
     * @param string $filter Filter type: 'all', 'broken', 'noindex', 'redirect', 'external'
     * @param int    $limit  Max results
     * @param int    $offset Offset for pagination
     * @return array
     */
    public static function get_issues( $filter = 'all', $limit = 50, $offset = 0 ) {
        global $wpdb;
        $table = self::table();
        
        // Check if table exists
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
            return array();
        }
        
        $where = 'WHERE last_checked IS NOT NULL';
        
        switch ( $filter ) {
            case 'broken':
                $where .= ' AND (status_code >= 400 OR status_code = 0)';
                break;
            case 'noindex':
                $where .= ' AND target_is_noindex = 1';
                break;
            case 'redirect':
                $where .= ' AND redirect_count > 0';
                break;
            case 'external':
                $where .= " AND link_type = 'external'";
                break;
            case 'nofollow':
                $where .= ' AND is_nofollow = 1';
                break;
        }
        
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} {$where} ORDER BY 
                CASE WHEN status_code >= 400 OR status_code = 0 THEN 0 ELSE 1 END,
                CASE WHEN target_is_noindex = 1 THEN 0 ELSE 1 END,
                last_checked DESC
            LIMIT %d OFFSET %d",
            $limit,
            $offset
        ) );
    }

    /**
     * Clear all health data.
     */
    public static function clear_all() {
        global $wpdb;
        $table = self::table();
        $wpdb->query( "TRUNCATE TABLE {$table}" );
        delete_option( 'brz_link_health_last_scan' );
    }
}
