<?php
/**
 * Uninstall script for Buyruz Plugin.
 *
 * This is executed when the plugin is uninstalled via the WordPress admin.
 * It checks user preferences to determine whether to delete Smart Linker data.
 *
 * @package Buyruz
 */

// Prevent direct access
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Get Smart Linker settings
$smart_linker_settings = get_option( 'brz_smart_linker', array() );

// Check if user wants to delete data on uninstall
if ( ! empty( $smart_linker_settings['delete_data_on_uninstall'] ) ) {
    global $wpdb;

    // Drop Smart Linker tables
    $tables = array(
        $wpdb->prefix . 'brz_content_index',
        $wpdb->prefix . 'brz_pending_links',
        // Legacy tables (in case they still exist)
        $wpdb->prefix . 'smart_links_log',
        $wpdb->prefix . 'buyruz_remote_cache',
    );

    foreach ( $tables as $table ) {
        $wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    // Delete plugin options
    delete_option( 'brz_smart_linker_options' );
}

// Remove firewall settings from brz_options.
$brz_options = get_option( 'brz_options', array() );
if ( is_array( $brz_options ) && isset( $brz_options['firewall'] ) ) {
    unset( $brz_options['firewall'] );
    update_option( 'brz_options', $brz_options );
}

// Note: Links that have been applied to post content are NOT removed
// because they are part of the post_content itself and do not depend on the plugin.
