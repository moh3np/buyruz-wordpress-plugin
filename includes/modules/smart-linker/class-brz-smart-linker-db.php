<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Persistence layer for Smart Internal Linking v3.1.
 *
 * Responsibility:
 * - Table name resolution
 * - Schema creation / migrations
 * - CRUD helpers with strict sanitization
 */
class BRZ_Smart_Linker_DB {
    const CONTENT_INDEX_SUFFIX = 'brz_content_index';
    const PENDING_LINKS_SUFFIX = 'brz_pending_links';

    /**
     * Content index table name.
     */
    public static function content_index_table() {
        global $wpdb;
        return $wpdb->prefix . self::CONTENT_INDEX_SUFFIX;
    }

    /**
     * Pending links table name.
     */
    public static function pending_links_table() {
        global $wpdb;
        return $wpdb->prefix . self::PENDING_LINKS_SUFFIX;
    }

    /**
     * Alias for pending links table name for backward-compatibility.
     */
    public static function table() {
        return self::pending_links_table();
    }

    /**
     * Create or upgrade all tables.
     * Uses dbDelta to stay compatible across environments.
     * v3.1: Only creates v3.0 tables (content_index, pending_links)
     */
    public static function migrate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();

        // v3.0: Content Index table (unified knowledge base)
        $content_index = self::content_index_table();
        $sql_content = "CREATE TABLE {$content_index} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            site_id varchar(32) NOT NULL DEFAULT 'local',
            post_id bigint(20) unsigned NOT NULL,
            post_type varchar(50) NOT NULL,
            title varchar(255) NOT NULL,
            url text NOT NULL,
            category_ids text NULL,
            category_names text NULL,
            focus_keyword varchar(255) NULL,
            secondary_keywords text NULL,
            content_excerpt MEDIUMTEXT NULL,
            word_count int(11) unsigned NOT NULL DEFAULT 0,
            is_linkable tinyint(1) NOT NULL DEFAULT 1,
            stock_status varchar(40) NULL,
            price varchar(50) NULL,
            last_synced datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY site_post (site_id, post_id),
            KEY post_type (post_type),
            KEY is_linkable (is_linkable),
            UNIQUE KEY unique_content (site_id, post_id, post_type)
        ) {$charset_collate};";
        dbDelta( $sql_content );

        // v3.0: Pending Links table (AI suggestions for review)
        $pending = self::pending_links_table();
        $sql_pending = "CREATE TABLE {$pending} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source_site varchar(32) NOT NULL,
            source_id bigint(20) unsigned NOT NULL,
            source_type varchar(50) NOT NULL,
            keyword varchar(255) NOT NULL,
            target_site varchar(32) NOT NULL,
            target_id bigint(20) unsigned NOT NULL,
            target_url text NOT NULL,
            priority varchar(20) NOT NULL DEFAULT 'medium',
            reason text NULL,
            status ENUM('pending','approved','rejected','applied') NOT NULL DEFAULT 'pending',
            batch_id varchar(64) NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            reviewed_at datetime NULL,
            applied_at datetime NULL,
            PRIMARY KEY (id),
            KEY source_lookup (source_site, source_id),
            KEY status (status),
            KEY batch_id (batch_id)
        ) {$charset_collate};";
        dbDelta( $sql_pending );

        // Drop legacy tables if they exist (cleanup)
        self::drop_legacy_tables();
    }

    /**
     * Drop legacy tables from older versions.
     */
    public static function drop_legacy_tables() {
        global $wpdb;
        
        // Legacy tables to remove
        $legacy_tables = array(
            $wpdb->prefix . 'smart_links_log',
            $wpdb->prefix . 'buyruz_remote_cache',
        );

        foreach ( $legacy_tables as $table ) {
            $wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }
    }

    /**
     * Drop ALL Smart Linker tables (for uninstall).
     */
    public static function drop_all_tables() {
        global $wpdb;
        
        $tables = array(
            self::content_index_table(),
            self::pending_links_table(),
        );

        foreach ( $tables as $table ) {
            $wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }

        // Also drop legacy tables
        self::drop_legacy_tables();
    }

    // ============================
    // Content Index CRUD Methods
    // ============================

    /**
     * Upsert content into the index.
     *
     * @param array $data Content data
     * @return int|false
     */
    public static function upsert_content( array $data ) {
        global $wpdb;
        $table = self::content_index_table();

        $defaults = array(
            'site_id'            => 'local',
            'post_id'            => 0,
            'post_type'          => 'post',
            'title'              => '',
            'url'                => '',
            'category_ids'       => '',
            'category_names'     => '',
            'focus_keyword'      => '',
            'secondary_keywords' => '',
            'content_excerpt'    => '',
            'word_count'         => 0,
            'is_linkable'        => 1,
            'stock_status'       => '',
            'price'              => '',
        );
        $data = wp_parse_args( $data, $defaults );

        // Check if exists
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE site_id = %s AND post_id = %d AND post_type = %s",
            $data['site_id'],
            $data['post_id'],
            $data['post_type']
        ) );

        $row = array(
            'site_id'            => sanitize_key( $data['site_id'] ),
            'post_id'            => absint( $data['post_id'] ),
            'post_type'          => sanitize_key( $data['post_type'] ),
            'title'              => sanitize_text_field( $data['title'] ),
            'url'                => esc_url_raw( $data['url'] ),
            'category_ids'       => is_array( $data['category_ids'] ) ? wp_json_encode( $data['category_ids'] ) : $data['category_ids'],
            'category_names'     => is_array( $data['category_names'] ) ? wp_json_encode( $data['category_names'] ) : $data['category_names'],
            'focus_keyword'      => sanitize_text_field( $data['focus_keyword'] ),
            'secondary_keywords' => is_array( $data['secondary_keywords'] ) ? wp_json_encode( $data['secondary_keywords'] ) : $data['secondary_keywords'],
            'content_excerpt'    => wp_kses( $data['content_excerpt'], array( 'a' => array( 'href' => array() ) ) ),
            'word_count'         => absint( $data['word_count'] ),
            'is_linkable'        => absint( $data['is_linkable'] ),
            'stock_status'       => sanitize_text_field( $data['stock_status'] ),
            'price'              => sanitize_text_field( $data['price'] ),
            'last_synced'        => current_time( 'mysql' ),
        );

        if ( $existing ) {
            $wpdb->update( $table, $row, array( 'id' => $existing ) );
            return (int) $existing;
        }

        $wpdb->insert( $table, $row );
        return $wpdb->insert_id ? (int) $wpdb->insert_id : false;
    }

    /**
     * Get all content from index.
     *
     * @param string $site_id Optional site filter (null = all sites)
     * @param bool   $only_linkable If true, only return items with is_linkable = 1
     * @param string $post_type Optional type filter
     * @return array
     */
    public static function get_content_index( $site_id = null, $only_linkable = false, $post_type = null ) {
        global $wpdb;
        $table = self::content_index_table();

        $where = array( '1=1' );
        $params = array();

        if ( $site_id ) {
            $where[] = 'site_id = %s';
            $params[] = $site_id;
        }
        if ( $only_linkable ) {
            $where[] = 'is_linkable = 1';
        }
        if ( $post_type ) {
            $where[] = 'post_type = %s';
            $params[] = $post_type;
        }

        $where_sql = implode( ' AND ', $where );
        $sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY post_type, title";

        if ( ! empty( $params ) ) {
            $sql = $wpdb->prepare( $sql, $params );
        }

        return $wpdb->get_results( $sql, ARRAY_A );
    }

    /**
     * Clear content index for a site.
     *
     * @param string $site_id
     */
    public static function clear_content_index( $site_id = 'local' ) {
        global $wpdb;
        $table = self::content_index_table();
        $wpdb->delete( $table, array( 'site_id' => $site_id ) );
    }

    // ============================
    // Pending Links CRUD Methods
    // ============================

    /**
     * Insert multiple pending links from AI response.
     *
     * @param array  $links Array of link suggestions
     * @param string $batch_id Unique batch identifier
     * @return int Number of inserted rows
     */
    public static function insert_pending_links( array $links, $batch_id ) {
        global $wpdb;
        $table = self::pending_links_table();
        $count = 0;

        foreach ( $links as $link ) {
            $inserted = $wpdb->insert( $table, array(
                'source_site'  => sanitize_key( isset( $link['source_site'] ) ? $link['source_site'] : 'local' ),
                'source_id'    => absint( isset( $link['source_id'] ) ? $link['source_id'] : 0 ),
                'source_type'  => sanitize_key( isset( $link['source_type'] ) ? $link['source_type'] : 'post' ),
                'keyword'      => sanitize_text_field( isset( $link['keyword'] ) ? $link['keyword'] : '' ),
                'target_site'  => sanitize_key( isset( $link['target_site'] ) ? $link['target_site'] : 'local' ),
                'target_id'    => absint( isset( $link['target_id'] ) ? $link['target_id'] : 0 ),
                'target_url'   => esc_url_raw( isset( $link['target_url'] ) ? $link['target_url'] : '' ),
                'priority'     => sanitize_key( isset( $link['priority'] ) ? $link['priority'] : 'medium' ),
                'reason'       => sanitize_text_field( isset( $link['reason'] ) ? $link['reason'] : '' ),
                'status'       => 'pending',
                'batch_id'     => sanitize_text_field( $batch_id ),
                'created_at'   => current_time( 'mysql' ),
            ) );

            if ( $inserted ) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Get pending links by status.
     *
     * @param string $status
     * @param int    $limit
     * @return array
     */
    public static function get_pending_links( $status = 'pending', $limit = 100 ) {
        global $wpdb;
        $table = self::pending_links_table();

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE status = %s ORDER BY priority DESC, created_at ASC LIMIT %d",
            $status,
            $limit
        ), ARRAY_A );
    }

    /**
     * Update pending link status.
     *
     * @param array  $ids
     * @param string $status
     * @return int
     */
    public static function update_pending_status( array $ids, $status ) {
        global $wpdb;
        $table = self::pending_links_table();

        if ( empty( $ids ) ) {
            return 0;
        }

        $ids_clean = array_map( 'absint', $ids );
        $placeholders = implode( ',', array_fill( 0, count( $ids_clean ), '%d' ) );
        $reviewed_at = 'applied' === $status ? ', applied_at = %s' : ', reviewed_at = %s';
        
        $sql = "UPDATE {$table} SET status = %s {$reviewed_at} WHERE id IN ({$placeholders})";
        $params = array_merge( array( $status, current_time( 'mysql' ) ), $ids_clean );

        return $wpdb->query( $wpdb->prepare( $sql, $params ) );
    }

    /**
     * Get pending links count by status.
     *
     * @return array
     */
    public static function get_pending_counts() {
        global $wpdb;
        $table = self::pending_links_table();

        $results = $wpdb->get_results(
            "SELECT status, COUNT(*) as count FROM {$table} GROUP BY status",
            ARRAY_A
        );

        $counts = array(
            'pending'  => 0,
            'approved' => 0,
            'rejected' => 0,
            'applied'  => 0,
        );

        foreach ( $results as $row ) {
            if ( isset( $counts[ $row['status'] ] ) ) {
                $counts[ $row['status'] ] = (int) $row['count'];
            }
        }

        return $counts;
    }

    // ============================
    // Legacy Methods (kept for compatibility)
    // ============================

    /**
     * Insert or update (by fingerprint) a suggestion row.
     *
     * @param array $data associative array: post_id, keyword, target_url, fingerprint, status.
     * @return int|false Inserted row ID or existing row ID.
     */
    public static function upsert( array $data ) {
        global $wpdb;
        $defaults = array(
            'post_id'     => 0,
            'keyword'     => '',
            'target_url'  => '',
            'fingerprint' => '',
            'status'      => 'pending',
        );

        $data = wp_parse_args( $data, $defaults );

        if ( empty( $data['fingerprint'] ) ) {
            return false;
        }

        $table = self::pending_links_table();

        $existing_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE fingerprint = %s",
                $data['fingerprint']
            )
        );

        if ( $existing_id ) {
            $wpdb->update(
                $table,
                array(
                    'post_id'    => (int) $data['post_id'],
                    'keyword'    => sanitize_text_field( $data['keyword'] ),
                    'target_url' => esc_url_raw( $data['target_url'] ),
                    'status'     => self::normalize_status( $data['status'] ),
                ),
                array( 'id' => (int) $existing_id ),
                array( '%d', '%s', '%s', '%s' ),
                array( '%d' )
            );
            return (int) $existing_id;
        }

        $inserted = $wpdb->insert(
            $table,
            array(
                'post_id'     => (int) $data['post_id'],
                'keyword'     => sanitize_text_field( $data['keyword'] ),
                'target_url'  => esc_url_raw( $data['target_url'] ),
                'fingerprint' => sanitize_text_field( $data['fingerprint'] ),
                'status'      => self::normalize_status( $data['status'] ),
                'created_at'  => current_time( 'mysql' ),
            ),
            array( '%d', '%s', '%s', '%s', '%s', '%s' )
        );

        return $inserted ? (int) $wpdb->insert_id : false;
    }

    /**
     * Fetch rows by status.
     *
     * @param array $statuses
     * @return array
     */
    public static function get_by_status( array $statuses ) {
        global $wpdb;
        $table = self::pending_links_table();
        $where = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

        $sql = "SELECT * FROM {$table} WHERE status IN ({$where}) ORDER BY created_at ASC";
        $prepared = $wpdb->prepare( $sql, $statuses );

        return $wpdb->get_results( $prepared, ARRAY_A );
    }

    /**
     * Fetch rows for a specific post by status.
     *
     * @param int   $post_id
     * @param array $statuses
     * @return array
     */
    public static function get_for_post( $post_id, array $statuses ) {
        global $wpdb;
        $table = self::pending_links_table();
        $where = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

        $sql = "SELECT * FROM {$table} WHERE post_id = %d AND status IN ({$where})";
        $prepared = $wpdb->prepare(
            $sql,
            array_merge( array( (int) $post_id ), $statuses )
        );

        return $wpdb->get_results( $prepared, ARRAY_A );
    }

    /**
     * Update status for provided IDs.
     *
     * @param array  $ids
     * @param string $status
     * @return int rows updated
     */
    public static function set_status( array $ids, $status ) {
        if ( empty( $ids ) ) {
            return 0;
        }

        global $wpdb;
        $table   = self::pending_links_table();
        $status  = self::normalize_status( $status );

        $ids_clean = array_map( 'intval', $ids );
        $placeholders = implode( ',', array_fill( 0, count( $ids_clean ), '%d' ) );
        $sql = "UPDATE {$table} SET status = %s WHERE id IN ({$placeholders})";
        $params = array_merge( array( $status ), $ids_clean );

        return $wpdb->query( $wpdb->prepare( $sql, $params ) );
    }

    /**
     * Update status for provided fingerprints.
     *
     * @param array  $fingerprints
     * @param string $status
     */
    public static function set_status_by_fingerprint( array $fingerprints, $status ) {
        global $wpdb;
        if ( empty( $fingerprints ) ) {
            return;
        }

        $table  = self::pending_links_table();
        $status = self::normalize_status( $status );

        $placeholders = implode( ',', array_fill( 0, count( $fingerprints ), '%s' ) );
        $sql          = "UPDATE {$table} SET status = %s WHERE fingerprint IN ({$placeholders})";
        $params       = array_merge( array( $status ), $fingerprints );

        $wpdb->query( $wpdb->prepare( $sql, $params ) );
    }

    /**
     * Return active links for the given post ID.
     *
     * @param int $post_id
     * @return array
     */
    public static function active_for_post( $post_id ) {
        return self::get_for_post( $post_id, array( 'active' ) );
    }

    /**
     * Restrict status to allowed ENUM values.
     *
     * @param string $status
     * @return string
     */
    private static function normalize_status( $status ) {
        $status = sanitize_key( $status );
        $allowed = class_exists( 'BRZ_Smart_Linker' ) ? BRZ_Smart_Linker::statuses() : array( 'pending', 'approved', 'active', 'user_deleted', 'manual_override' );
        if ( in_array( $status, $allowed, true ) ) {
            return $status;
        }
        return 'pending';
    }
}

