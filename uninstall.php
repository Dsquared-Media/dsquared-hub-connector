<?php
/**
 * Dsquared Hub Connector — Uninstall
 *
 * Cleans up all plugin data when the plugin is deleted (not just deactivated).
 * Deactivation does NOT remove data — only full deletion does.
 * This ensures the website is never affected by simply disabling the plugin.
 *
 * @package Dsquared_Hub_Connector
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// ── Remove plugin options ───────────────────────────────────
$options = array(
    // Core
    'dhc_api_key',
    'dhc_modules',
    'dhc_subscription',
    'dhc_activity_log',
    'dhc_default_author',

    // v1.0 Modules
    'dhc_global_schemas',
    'dhc_cwv_metrics',

    // v1.5 Modules
    'dhc_ai_business_profile',
    'dhc_indexnow_key',
    'dhc_content_decay_results',
    'dhc_lead_stats',
    'dhc_lead_monthly_count',
);

foreach ( $options as $option ) {
    delete_option( $option );
}

// ── Remove transients ───────────────────────────────────────
delete_transient( 'dhc_subscription_cache' );
delete_transient( 'dhc_update_cache' );

// ── Remove per-post meta ────────────────────────────────────
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_dhc\_%'" );

// ── Remove scheduled cron events ────────────────────────────
$crons = array( 'dhc_content_decay_scan', 'dhc_monthly_lead_reset' );
foreach ( $crons as $hook ) {
    $timestamp = wp_next_scheduled( $hook );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, $hook );
    }
}

// ── Remove generated files ──────────────────────────────────
$files_to_remove = array(
    ABSPATH . 'llms.txt',
    ABSPATH . 'llms-full.txt',
);

foreach ( $files_to_remove as $file ) {
    if ( file_exists( $file ) ) {
        @unlink( $file );
    }
}
