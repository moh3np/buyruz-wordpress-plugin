<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Buyruz Business Intelligence exporter.
 *
 * Generates a cached JSON snapshot of posts/products (SEO + BI) in background,
 * merges with remote peer, and exposes a protected REST/download endpoint.
 */
class BRZ_BI_Exporter {
    const OPTION_KEY    = 'brz_bi_exporter';
    const STATE_OPTION  = 'brz_bi_exporter_state';
    const CRON_HOOK     = 'brz_bi_exporter_process_batch';
    const BATCH_SIZE    = 50;
    const CACHE_DIR     = 'buyruz-bi';
    const LOCAL_FILE    = 'bi-local.json';
    const MERGED_FILE   = 'bi-full.json';

    /**
     * Bootstrap hooks.
     */
    public static function init() {
        register_activation_hook( BRZ_PATH . 'buyruz-settings.php', array( __CLASS__, 'on_activate' ) );
        register_deactivation_hook( BRZ_PATH . 'buyruz-settings.php', array( __CLASS__, 'on_deactivate' ) );

        add_action( self::CRON_HOOK, array( __CLASS__, 'process_batch' ), 10, 2 );
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );

        add_action( 'admin_post_brz_bi_exporter_regenerate', array( __CLASS__, 'handle_regenerate' ) );
        add_action( 'admin_post_brz_bi_exporter_download', array( __CLASS__, 'handle_download' ) );
        add_action( 'wp_ajax_brz_bi_exporter_regenerate', array( __CLASS__, 'ajax_regenerate' ) );
        add_action( 'wp_ajax_brz_bi_exporter_status', array( __CLASS__, 'ajax_status' ) );
        add_action( 'wp_ajax_brz_bi_exporter_save', array( __CLASS__, 'ajax_save_settings' ) );
    }

    /**
     * Ensure defaults on activation.
     */
    public static function on_activate() {
        $settings = self::get_settings();
        if ( empty( $settings['api_key'] ) ) {
            $settings['api_key'] = self::generate_api_key();
            update_option( self::OPTION_KEY, $settings, false );
        }
        self::ensure_cache_dir();
    }

    /**
     * Cleanup on deactivation.
     */
    public static function on_deactivate() {
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    /**
     * REST: /wp-json/buyruz/v1/full-dump
     */
    public static function register_routes() {
        register_rest_route(
            'buyruz/v1',
            '/full-dump',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( __CLASS__, 'rest_full_dump' ),
                    'permission_callback' => '__return_true',
                    'args'                => array(
                        'api_key' => array(
                            'description' => 'Shared secret key',
                            'type'        => 'string',
                        ),
                        'scope' => array(
                            'description' => 'Set to local to skip remote merge',
                            'type'        => 'string',
                        ),
                    ),
                ),
            )
        );
    }

    public static function rest_full_dump( WP_REST_Request $request ) {
        if ( ! self::authorized( $request ) ) {
            return new WP_Error( 'brz_bi_auth', 'API key required or invalid.', array( 'status' => 401 ) );
        }

        $scope = $request->get_param( 'scope' );
        $payload = ( 'local' === $scope ) ? self::get_local_node() : self::build_full_payload();

        if ( empty( $payload ) ) {
            return new WP_Error( 'brz_bi_empty', 'Snapshot unavailable. Regenerate first.', array( 'status' => 503 ) );
        }

        return rest_ensure_response( $payload );
    }

    private static function authorized( WP_REST_Request $request ) {
        $settings = self::get_settings();
        $key      = isset( $settings['api_key'] ) ? $settings['api_key'] : '';
        if ( empty( $key ) ) {
            return false;
        }

        $provided = $request->get_param( 'api_key' );
        if ( empty( $provided ) ) {
            $header = $request->get_header( 'x-buyruz-key' );
            $provided = $header ? $header : '';
        }

        return ! empty( $provided ) && hash_equals( (string) $key, (string) $provided );
    }

    /**
     * Admin-post handler to queue regeneration (non-AJAX fallback).
     */
    public static function handle_regenerate() {
        if ( ! current_user_can( BRZ_Settings::CAPABILITY ) ) {
            wp_die( esc_html__( 'Unauthorized', 'buyruz' ) );
        }
        check_admin_referer( 'brz_bi_exporter_regen' );
        self::start_new_run();
        wp_safe_redirect( admin_url( 'admin.php?page=buyruz-module-bi_exporter&brz-bix=queued' ) );
        exit;
    }

    /**
     * Download merged JSON.
     */
    public static function handle_download() {
        if ( ! current_user_can( BRZ_Settings::CAPABILITY ) ) {
            wp_die( esc_html__( 'Unauthorized', 'buyruz' ) );
        }
        check_admin_referer( 'brz_bi_exporter_download' );

        $payload = self::build_full_payload();
        if ( empty( $payload ) ) {
            wp_die( esc_html__( 'Snapshot not ready. Regenerate first.', 'buyruz' ) );
        }

        ignore_user_abort( true );
        @set_time_limit( 90 );

        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="buyruz-full-dump.json"' );

        echo wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        exit;
    }

    /**
     * AJAX: queue regeneration.
     */
    public static function ajax_regenerate() {
        check_ajax_referer( 'brz_bi_exporter_regen' );
        if ( ! current_user_can( BRZ_Settings::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
        }
        $state = self::start_new_run();
        wp_send_json_success( array( 'state' => $state ) );
    }

    /**
     * AJAX: status poll.
     */
    public static function ajax_status() {
        check_ajax_referer( 'brz_bi_exporter_status' );
        if ( ! current_user_can( BRZ_Settings::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
        }
        wp_send_json_success( array( 'state' => self::current_state() ) );
    }

    /**
     * AJAX: save settings.
     */
    public static function ajax_save_settings() {
        check_ajax_referer( 'brz_bi_exporter_save' );
        if ( ! current_user_can( BRZ_Settings::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
        }
        $raw = isset( $_POST[ self::OPTION_KEY ] ) ? (array) wp_unslash( $_POST[ self::OPTION_KEY ] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $settings = self::save_settings( $raw );
        wp_send_json_success( array( 'settings' => $settings ) );
    }

    /**
     * Create new run and enqueue first batch.
     */
    private static function start_new_run() {
        $run_id = 'bi_' . wp_generate_password( 12, false, false );
        $tmp    = self::tmp_path( $run_id );

        self::ensure_cache_dir();
        if ( file_exists( $tmp ) ) {
            @unlink( $tmp );
        }
        self::write_json_preamble( $tmp );

        $state = array(
            'run_id'     => $run_id,
            'status'     => 'running',
            'processed'  => 0,
            'total'      => self::total_targets(),
            'started_at' => current_time( 'mysql' ),
            'finished_at'=> '',
            'tmp_path'   => $tmp,
            'site_role'  => self::site_role(),
        );
        update_option( self::STATE_OPTION, $state, false );

        self::queue_batch( $run_id, 1 );

        return $state;
    }

    /**
     * Schedule next batch via Action Scheduler (fallback to WP-Cron).
     */
    private static function queue_batch( $run_id, $page ) {
        $args = array( $run_id, (int) $page );
        if ( function_exists( 'as_enqueue_async_action' ) ) {
            as_enqueue_async_action( self::CRON_HOOK, $args, 'brz-bi' );
        } elseif ( function_exists( 'as_schedule_single_action' ) ) {
            as_schedule_single_action( time() + 5, self::CRON_HOOK, $args, 'brz-bi' );
        } else {
            wp_schedule_single_event( time() + 5, self::CRON_HOOK, $args );
        }
    }

    /**
     * Process a batch of posts/products.
     */
    public static function process_batch( $run_id = '', $page = 1 ) {
        $state = self::current_state();
        if ( empty( $state['run_id'] ) || $state['run_id'] !== $run_id ) {
            return; // stale job
        }

        ignore_user_abort( true );
        @set_time_limit( 60 );

        $ids = self::fetch_targets( $page );
        if ( empty( $ids ) ) {
            self::finalize_file( $state );
            return;
        }

        $items = array();
        foreach ( $ids as $post_id ) {
            $item = self::build_item( $post_id );
            if ( ! empty( $item ) ) {
                $items[] = $item;
            }
        }

        self::append_items( $state['tmp_path'], $items, (int) $state['processed'] );
        $state['processed'] += count( $items );
        update_option( self::STATE_OPTION, $state, false );

        if ( count( $ids ) >= self::BATCH_SIZE ) {
            self::queue_batch( $run_id, (int) $page + 1 );
        } else {
            self::finalize_file( $state );
        }
    }

    /**
     * Finalize JSON and mark state done.
     */
    private static function finalize_file( array $state ) {
        if ( empty( $state['tmp_path'] ) || ! file_exists( $state['tmp_path'] ) ) {
            return;
        }
        file_put_contents( $state['tmp_path'], "\n]", FILE_APPEND );

        $final = self::local_cache_path();
        @rename( $state['tmp_path'], $final );

        $state['status']      = 'finished';
        $state['finished_at'] = current_time( 'mysql' );
        $state['file']        = $final;
        update_option( self::STATE_OPTION, $state, false );
    }

    /**
     * Query IDs for a batch.
     */
    private static function fetch_targets( $page ) {
        $args = array(
            'post_type'              => array( 'post', 'product' ),
            'post_status'            => array( 'publish', 'private', 'draft' ),
            'posts_per_page'         => self::BATCH_SIZE,
            'paged'                  => max( 1, (int) $page ),
            'orderby'                => 'ID',
            'order'                  => 'ASC',
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'ignore_sticky_posts'    => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        );

        return get_posts( $args );
    }

    /**
     * Build a compact item array for JSON.
     */
    private static function build_item( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post || 'revision' === $post->post_type ) {
            return array();
        }

        if ( 'post' === $post->post_type && 'publish' !== $post->post_status ) {
            return array();
        }

        $type = ( 'product' === $post->post_type ) ? 'product' : 'post';
        $seo  = self::extract_seo( $post_id );
        $analysis = self::analyze_content( $post->post_content );

        $item = array(
            'id'    => (int) $post_id,
            'type'  => $type,
            'title' => html_entity_decode( wp_strip_all_tags( $post->post_title ), ENT_QUOTES, 'UTF-8' ),
            'url'   => get_permalink( $post_id ),
            'pub'   => mysql2date( 'c', $post->post_date_gmt ? $post->post_date_gmt : $post->post_date ),
            'mod'   => mysql2date( 'c', $post->post_modified_gmt ? $post->post_modified_gmt : $post->post_modified ),
            'seo'   => $seo,
            'content' => array(
                'words'       => $analysis['words'],
                'imgs'        => $analysis['imgs'],
                'missing_alt' => $analysis['missing_alt'],
                'links'       => array(
                    'int' => $analysis['links_internal'],
                    'ext' => $analysis['links_external'],
                ),
            ),
            'tax' => self::get_term_paths( $post_id, ( 'product' === $type ) ? 'product_cat' : 'category' ),
        );

        if ( 'product' === $type && function_exists( 'wc_get_product' ) ) {
            $product = wc_get_product( $post_id );
            if ( $product ) {
                $item['biz'] = self::build_product_biz( $product, $post );
            }
        }

        return self::prune_nulls( $item );
    }

    /**
     * Extract RankMath SEO fields.
     */
    private static function extract_seo( $post_id ) {
        $kw     = get_post_meta( $post_id, 'rank_math_focus_keyword', true );
        if ( is_string( $kw ) && strpos( $kw, ',' ) !== false ) {
            $parts = array_map( 'trim', explode( ',', $kw ) );
            $kw    = reset( $parts );
        }
        $seo_score = (int) get_post_meta( $post_id, 'rank_math_seo_score', true );
        $robots    = get_post_meta( $post_id, 'rank_math_robots', true );
        $index     = true;
        if ( is_array( $robots ) ) {
            $index = ! in_array( 'noindex', $robots, true );
        } elseif ( is_string( $robots ) ) {
            $index = ( false === stripos( $robots, 'noindex' ) );
        }
        $schema = get_post_meta( $post_id, 'rank_math_rich_snippet', true );

        $seo = array(
            'kw'    => $kw,
            'score' => $seo_score,
            'index' => (bool) $index,
        );
        if ( ! empty( $schema ) ) {
            $seo['schema'] = $schema;
        }
        return self::prune_nulls( $seo );
    }

    /**
     * Analyze HTML content: words, imgs, alts, internal/external links.
     */
    private static function analyze_content( $html ) {
        $text = trim( wp_strip_all_tags( $html ) );
        $words = 0;
        if ( $text !== '' ) {
            $chunks = preg_split( '/[\s]+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
            $words  = is_array( $chunks ) ? count( $chunks ) : 0;
        }

        $imgs = 0;
        $missing_alt = 0;
        if ( preg_match_all( '/<img\b[^>]*>/i', $html, $img_matches ) ) {
            foreach ( $img_matches[0] as $tag ) {
                $imgs++;
                if ( ! preg_match( '/\balt\s*=\s*(\"[^\"]*\"|\'[^\']*\'|[^\"\'>\s]+)/i', $tag, $alt_match ) ) {
                    $missing_alt++;
                } else {
                    $alt_val = trim( trim( $alt_match[1], '"\'' ) );
                    if ( '' === $alt_val ) {
                        $missing_alt++;
                    }
                }
            }
        }

        $links_internal = 0;
        $links_external = 0;
        $home_host = parse_url( home_url(), PHP_URL_HOST );

        if ( preg_match_all( '/<a\b[^>]*href=[\"\']?([^\"\'\s>#]+)/i', $html, $hrefs ) ) {
            foreach ( $hrefs[1] as $href ) {
                $href = trim( $href );
                if ( '' === $href || strpos( $href, 'mailto:' ) === 0 || strpos( $href, 'tel:' ) === 0 || strpos( $href, '#' ) === 0 ) {
                    continue;
                }
                $host = parse_url( $href, PHP_URL_HOST );
                if ( empty( $host ) || ( $home_host && strtolower( $host ) === strtolower( $home_host ) ) ) {
                    $links_internal++;
                } else {
                    $links_external++;
                }
            }
        }

        return array(
            'words'          => $words,
            'imgs'           => $imgs,
            'missing_alt'    => $missing_alt,
            'links_internal' => $links_internal,
            'links_external' => $links_external,
        );
    }

    /**
     * Build WooCommerce-specific metrics.
     */
    private static function build_product_biz( WC_Product $product, WP_Post $post ) {
        $stock_status   = $product->get_stock_status();
        $stock_quantity = $product->get_stock_quantity();
        $backorders     = $product->get_backorders();

        $discontinued = in_array( $post->post_status, array( 'private', 'draft' ), true ) || has_term( 'discontinued', 'product_tag', $product->get_id() );

        if ( $discontinued ) {
            $stock_label = 'discontinued';
        } elseif ( 'instock' === $stock_status ) {
            $stock_label = 'instock';
        } elseif ( 'onbackorder' === $stock_status || ( $backorders && 'no' !== $backorders ) ) {
            $stock_label = 'oos_temp';
        } else {
            $stock_label = 'oos_temp';
        }

        list( $units, $revenue ) = self::get_sales_stats( $product );

        return self::prune_nulls( array(
            'price'   => (float) $product->get_price(),
            'sale'    => (float) $product->get_sale_price(),
            'stock'   => $stock_label,
            'qty'     => is_numeric( $stock_quantity ) ? (float) $stock_quantity : 0,
            'sales'   => array(
                'units' => $units,
                'rev'   => $revenue,
            ),
            'reviews' => self::get_reviews( $product->get_id() ),
        ) );
    }

    /**
     * Sales stats via wc_order_product_lookup (fallback to total_sales meta).
     */
    private static function get_sales_stats( WC_Product $product ) {
        global $wpdb;
        $product_id = $product->get_id();

        $table = $wpdb->prefix . 'wc_order_product_lookup';
        $has_table = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );

        if ( $has_table ) {
            $units = (int) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(product_qty) FROM {$table} WHERE product_id = %d", $product_id ) );
            $revenue = (float) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(product_net_revenue) FROM {$table} WHERE product_id = %d", $product_id ) );
            return array( $units, $revenue );
        }

        $units = (int) get_post_meta( $product_id, 'total_sales', true );
        $revenue = $units * (float) $product->get_price();
        return array( $units, $revenue );
    }

    /**
     * Last 5 approved reviews with rating.
     */
    private static function get_reviews( $product_id ) {
        $comments = get_comments( array(
            'post_id' => $product_id,
            'status'  => 'approve',
            'number'  => 5,
            'orderby' => 'comment_date_gmt',
            'order'   => 'DESC',
            'type'    => 'review',
            'fields'  => 'all',
        ) );

        $out = array();
        foreach ( $comments as $comment ) {
            $rating = get_comment_meta( $comment->comment_ID, 'rating', true );
            $out[] = array(
                'rating' => (int) $rating,
                'text'   => wp_strip_all_tags( $comment->comment_content ),
                'date'   => mysql2date( 'c', $comment->comment_date_gmt ? $comment->comment_date_gmt : $comment->comment_date ),
            );
        }
        return $out;
    }

    /**
     * Taxonomy breadcrumb paths.
     */
    private static function get_term_paths( $post_id, $taxonomy ) {
        $terms = get_the_terms( $post_id, $taxonomy );
        if ( empty( $terms ) || is_wp_error( $terms ) ) {
            return array();
        }
        $paths = array();
        foreach ( $terms as $term ) {
            $paths[] = self::get_single_term_path( $term );
        }
        return array_values( array_unique( $paths ) );
    }

    private static function get_single_term_path( WP_Term $term ) {
        $names  = array( $term->name );
        $parent = $term->parent;
        while ( $parent ) {
            $p = get_term( $parent, $term->taxonomy );
            if ( is_wp_error( $p ) || ! $p ) {
                break;
            }
            array_unshift( $names, $p->name );
            $parent = $p->parent;
        }
        return implode( ' > ', $names );
    }

    /**
     * Read local cache items.
     */
    private static function read_items_from_cache() {
        $path = self::local_cache_path();
        if ( ! file_exists( $path ) ) {
            return array();
        }
        $json = file_get_contents( $path );
        $data = json_decode( $json, true );
        return ( json_last_error() === JSON_ERROR_NONE && is_array( $data ) ) ? $data : array();
    }

    /**
     * Build local node payload.
     */
    private static function get_local_node() {
        $items = self::read_items_from_cache();
        if ( empty( $items ) ) {
            return array();
        }

        $state   = self::current_state();
        $mtime   = file_exists( self::local_cache_path() ) ? filemtime( self::local_cache_path() ) : time();
        $metrics = self::summarize_metrics( $items );

        return array(
            'role'        => self::site_role(),
            'generated_at'=> ! empty( $state['finished_at'] ) ? mysql2date( 'c', $state['finished_at'] ) : date( 'c', $mtime ),
            'items'       => $items,
            'metrics'     => $metrics,
        );
    }

    /**
     * Fetch remote snapshot (local scope) if configured.
     */
    private static function fetch_remote_node() {
        $settings = self::get_settings();
        if ( empty( $settings['remote_endpoint'] ) || empty( $settings['remote_api_key'] ) ) {
            return array();
        }

        $url = add_query_arg(
            array(
                'api_key' => rawurlencode( $settings['remote_api_key'] ),
                'scope'   => 'local',
            ),
            $settings['remote_endpoint']
        );

        $response = wp_remote_get( $url, array( 'timeout' => 30, 'redirection' => 3 ) );
        if ( is_wp_error( $response ) ) {
            return array();
        }
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );
        if ( json_last_error() !== JSON_ERROR_NONE || empty( $data ) ) {
            return array();
        }

        if ( isset( $data['items'] ) ) {
            return $data;
        }

        if ( isset( $data['shop_node']['items'] ) || isset( $data['blog_node']['items'] ) ) {
            if ( isset( $data['shop_node']['items'] ) ) {
                return $data['shop_node'];
            }
            if ( isset( $data['blog_node']['items'] ) ) {
                return $data['blog_node'];
            }
        }

        return is_array( $data ) ? $data : array();
    }

    /**
     * Merge local + remote into master payload.
     */
    private static function build_full_payload() {
        $local  = self::get_local_node();
        if ( empty( $local ) ) {
            return array();
        }
        $remote = self::fetch_remote_node();

        $shop_node = ( isset( $local['role'] ) && 'shop' === $local['role'] ) ? $local : $remote;
        $blog_node = ( isset( $local['role'] ) && 'blog' === $local['role'] ) ? $local : $remote;

        $ecosystem = array(
            'total_revenue' => (float) ( $shop_node['metrics']['rev'] ?? 0 ) + (float) ( $blog_node['metrics']['rev'] ?? 0 ),
            'total_traffic' => (int) ( $shop_node['metrics']['traffic'] ?? 0 ) + (int) ( $blog_node['metrics']['traffic'] ?? 0 ),
        );

        return array(
            'generated_at'     => date( 'c' ),
            'ecosystem_health' => $ecosystem,
            'shop_node'        => $shop_node ? $shop_node : array(),
            'blog_node'        => $blog_node ? $blog_node : array(),
        );
    }

    /**
     * Summaries for LLM token efficiency.
     */
    private static function summarize_metrics( array $items ) {
        $rev     = 0;
        $traffic = 0;
        foreach ( $items as $item ) {
            if ( isset( $item['biz']['sales']['rev'] ) ) {
                $rev += (float) $item['biz']['sales']['rev'];
            }
            $traffic++;
        }
        $traffic = apply_filters( 'brz/bi_exporter/traffic', $traffic, $items );
        return array(
            'rev'     => round( $rev, 2 ),
            'traffic' => (int) $traffic,
            'count'   => count( $items ),
        );
    }

    /**
     * Helpers & utilities.
     */
    private static function write_json_preamble( $path ) {
        file_put_contents( $path, '[' );
    }

    private static function append_items( $path, array $items, $processed_so_far ) {
        if ( empty( $items ) ) {
            return;
        }
        $fh = fopen( $path, 'ab' );
        if ( ! $fh ) {
            return;
        }
        foreach ( $items as $index => $item ) {
            $prefix = ( $processed_so_far > 0 || $index > 0 ) ? ",\n" : "\n";
            fwrite( $fh, $prefix . wp_json_encode( $item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
        }
        fclose( $fh );
    }

    private static function ensure_cache_dir() {
        $dir = self::cache_dir();
        if ( ! file_exists( $dir ) ) {
            wp_mkdir_p( $dir );
        }
        return $dir;
    }

    private static function cache_dir() {
        $uploads = wp_get_upload_dir();
        return trailingslashit( $uploads['basedir'] ) . self::CACHE_DIR . '/';
    }

    private static function tmp_path( $run_id ) {
        return trailingslashit( self::cache_dir() ) . $run_id . '.json';
    }

    private static function local_cache_path() {
        return trailingslashit( self::cache_dir() ) . self::LOCAL_FILE;
    }

    public static function merged_cache_path() {
        return trailingslashit( self::cache_dir() ) . self::MERGED_FILE;
    }

    private static function current_state() {
        $state = get_option( self::STATE_OPTION, array() );
        return is_array( $state ) ? $state : array();
    }

    /**
     * Public accessor for status (lightweight for UI).
     *
     * @return array
     */
    public static function get_state() {
        return self::current_state();
    }

    private static function total_targets() {
        $count = 0;
        $posts = wp_count_posts( 'post' );
        if ( $posts && isset( $posts->publish ) ) {
            $count += (int) $posts->publish;
        }
        if ( post_type_exists( 'product' ) ) {
            $products = wp_count_posts( 'product' );
            if ( $products && isset( $products->publish ) ) {
                $count += (int) $products->publish;
                $count += isset( $products->private ) ? (int) $products->private : 0;
                $count += isset( $products->draft ) ? (int) $products->draft : 0;
            }
        }
        return $count;
    }

    private static function site_role() {
        $settings = self::get_settings();
        return isset( $settings['site_role'] ) ? $settings['site_role'] : 'shop';
    }

    public static function get_settings() {
        $defaults = array(
            'api_key'         => '',
            'remote_endpoint' => '',
            'remote_api_key'  => '',
            'site_role'       => class_exists( 'WooCommerce' ) ? 'shop' : 'blog',
        );

        $saved = get_option( self::OPTION_KEY, array() );
        if ( ! is_array( $saved ) ) {
            $saved = array();
        }
        $settings = wp_parse_args( $saved, $defaults );

        if ( empty( $settings['remote_endpoint'] ) && class_exists( 'BRZ_Smart_Linker' ) ) {
            $sl = BRZ_Smart_Linker::get_settings();
            $settings['remote_endpoint'] = isset( $sl['remote_endpoint'] ) ? $sl['remote_endpoint'] : '';
            $settings['remote_api_key']  = isset( $sl['remote_api_key'] ) ? $sl['remote_api_key'] : '';
            $settings['site_role']       = isset( $sl['site_role'] ) ? $sl['site_role'] : $settings['site_role'];
        }

        if ( empty( $settings['api_key'] ) ) {
            $settings['api_key'] = self::generate_api_key();
            update_option( self::OPTION_KEY, $settings, false );
        }

        return $settings;
    }

    /**
     * Render module admin UI (called from BRZ_Settings).
     */
    public static function render_admin_page() {
        if ( ! current_user_can( BRZ_Settings::CAPABILITY ) ) {
            return;
        }

        $settings     = self::get_settings();
        $state        = self::current_state();
        $regen_nonce  = wp_create_nonce( 'brz_bi_exporter_regen' );
        $status_nonce = wp_create_nonce( 'brz_bi_exporter_status' );
        $download_url = wp_nonce_url( admin_url( 'admin-post.php?action=brz_bi_exporter_download' ), 'brz_bi_exporter_download' );

        $status_text  = 'منتظر اولین اجرا';
        if ( ! empty( $state['status'] ) ) {
            if ( 'finished' === $state['status'] ) {
                $status_text = 'آماده - آخرین خروجی در ' . mysql2date( 'Y-m-d H:i', $state['finished_at'] );
            } elseif ( 'running' === $state['status'] ) {
                $status_text = 'در حال پردازش (' . (int) $state['processed'] . ' / ' . (int) $state['total'] . ')';
            }
        }
        ?>


        <div class="brz-single-column">
            <div class="brz-card">
                <div class="brz-card__header">
                    <h3>اجرای پس‌زمینه</h3>
                    <p>پردازش در پس‌زمینه با Action Scheduler (50 آیتم در هر بچ).</p>
                </div>
                <div class="brz-card__body">
                    <p id="brz-bi-state-label" class="description"><?php echo esc_html( $status_text ); ?></p>
                    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                        <button type="button" class="button button-primary" id="brz-bi-regenerate" data-nonce="<?php echo esc_attr( $regen_nonce ); ?>">بازسازی گزارش</button>
                        <a class="button" href="<?php echo esc_url( $download_url ); ?>">دانلود JSON یکپارچه</a>
                        <button type="button" class="button" id="brz-bi-refresh-status" data-nonce="<?php echo esc_attr( $status_nonce ); ?>">به‌روزرسانی وضعیت</button>
                    </div>
                </div>
                <div class="brz-card__footer">
                    <p class="description">خروجی در مسیر آپلود ذخیره می‌شود: <code dir="ltr"><?php echo esc_html( self::local_cache_path() ); ?></code></p>
                </div>
            </div>
        </div>

        <script>
        (function(){
            const ajax = window.ajaxurl;
            const form = document.getElementById('brz-bi-settings-form');
            const saveStatus = document.getElementById('brz-bi-save-status');
            const regenBtn = document.getElementById('brz-bi-regenerate');
            const refreshBtn = document.getElementById('brz-bi-refresh-status');
            const stateLabel = document.getElementById('brz-bi-state-label');

            function setText(el, text, isError){
                if(!el) return;
                el.textContent = text || '';
                el.style.color = isError ? '#b91c1c' : '';
            }

            function formatState(state){
                if(!state || !state.status){ return 'منتظر اولین اجرا'; }
                if(state.status === 'running'){
                    return 'در حال پردازش (' + (state.processed || 0) + ' / ' + (state.total || 0) + ')';
                }
                if(state.status === 'finished'){
                    return 'آماده - ' + (state.finished_at || '');
                }
                return state.status;
            }

            if(form){
                form.addEventListener('submit', function(e){
                    e.preventDefault();
                    const fd = new FormData(form);
                    fd.append('action','brz_bi_exporter_save');
                    fetch(ajax, {method:'POST', credentials:'same-origin', body: fd})
                        .then(r=>r.json())
                        .then(json=>{
                            if(json && json.success){
                                setText(saveStatus, 'ذخیره شد');
                            } else {
                                setText(saveStatus, 'خطا در ذخیره', true);
                            }
                        })
                        .catch(()=>setText(saveStatus, 'خطا در ذخیره', true));
                });
            }

            function pollStatus(){
                if(!refreshBtn) return;
                const fd = new FormData();
                fd.append('action','brz_bi_exporter_status');
                fd.append('_wpnonce', refreshBtn.dataset.nonce || '');
                fetch(ajax, {method:'POST', credentials:'same-origin', body: fd})
                    .then(r=>r.json())
                    .then(json=>{
                        if(json && json.success && json.data && json.data.state){
                            setText(stateLabel, formatState(json.data.state));
                        }
                    })
                    .catch(()=>{});
            }

            if(refreshBtn){
                refreshBtn.addEventListener('click', function(){
                    pollStatus();
                });
            }

            if(regenBtn){
                regenBtn.addEventListener('click', function(){
                    const fd = new FormData();
                    fd.append('action','brz_bi_exporter_regenerate');
                    fd.append('_wpnonce', regenBtn.dataset.nonce || '');
                    setText(stateLabel, 'در حال صف‌بندی...');
                    fetch(ajax, {method:'POST', credentials:'same-origin', body: fd})
                        .then(r=>r.json())
                        .then(json=>{
                            if(json && json.success){
                                setText(stateLabel, 'در صف پردازش...');
                                pollStatus();
                            } else {
                                setText(stateLabel, 'خطا در صف‌بندی', true);
                            }
                        })
                        .catch(()=>setText(stateLabel, 'خطا در صف‌بندی', true));
                });
            }
        })();
        </script>
        <?php
    }

    public static function save_settings( array $input ) {
        $settings = self::get_settings();
        $settings['api_key']         = sanitize_text_field( isset( $input['api_key'] ) ? $input['api_key'] : $settings['api_key'] );
        $settings['remote_endpoint'] = esc_url_raw( isset( $input['remote_endpoint'] ) ? $input['remote_endpoint'] : $settings['remote_endpoint'] );
        $settings['remote_api_key']  = sanitize_text_field( isset( $input['remote_api_key'] ) ? $input['remote_api_key'] : $settings['remote_api_key'] );

        $role = isset( $input['site_role'] ) ? sanitize_key( $input['site_role'] ) : $settings['site_role'];
        $settings['site_role'] = in_array( $role, array( 'shop', 'blog' ), true ) ? $role : $settings['site_role'];

        if ( empty( $settings['api_key'] ) ) {
            $settings['api_key'] = self::generate_api_key();
        }

        update_option( self::OPTION_KEY, $settings, false );
        return $settings;
    }

    private static function generate_api_key() {
        return wp_generate_password( 24, false, false );
    }

    private static function prune_nulls( $data ) {
        if ( is_array( $data ) ) {
            foreach ( $data as $k => $v ) {
                if ( is_array( $v ) ) {
                    $data[ $k ] = self::prune_nulls( $v );
                } elseif ( null === $v ) {
                    unset( $data[ $k ] );
                }
            }
        }
        return $data;
    }
}

// Friendly alias for external calls.
class Buyruz_BI_Exporter extends BRZ_BI_Exporter {}
