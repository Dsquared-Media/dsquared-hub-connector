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

$dynamic_transient_keys   = get_option( 'dhc_dynamic_transient_keys', array() );
$generated_file_ownership = get_option( 'dhc_generated_file_ownership', array() );

// ── Remove plugin options ───────────────────────────────────
$options = array(
    // Core
    'dhc_api_key',
    'dhc_telemetry_token',
    'dhc_telemetry_binding',
    'dhc_telemetry_provision_retry',
    'dhc_modules',
    'dhc_subscription',
    'dhc_activity_log',
    'dhc_default_author',
    'dhc_last_heartbeat',
    'dhc_installed_version',
    'dhc_track_queue',
    'dhc_hub_profile_synced',
    'dhc_legacy_cleanup_version',
    'dhc_legacy_cleanup_result',
    'dhc_disable_auto_update',
    'dhc_dynamic_transient_keys',
    'dhc_generated_file_ownership',

    // v1.0 Modules
    'dhc_global_schemas',
    'dhc_cwv_metrics',

    // v1.5 Modules
    'dhc_ai_business_profile',
    'dhc_business_profile',
    'dhc_indexnow_key',
    'dhc_ai_discovery_llms_settings',
    'dhc_ai_discovery_last_validation',
    'dhc_ai_discovery_indexnow_queue',
    'dhc_ai_discovery_indexnow_last_submit',
    'dhc_content_decay_results',
    'dhc_lead_stats',
    'dhc_lead_monthly_count',
	'dhc_form_inbox',
    'dhc_tracking_scripts',
    'dhc_dashboard_events',
    'dhc_indexing_live_validation',
    'dhc_indexnow_last_status',
	'dhc_event_tracker',
    'dhc_llms_txt_raw',

    // Link scanner and redirects
    'dhc_404_log',
    'dhc_broken_links',
    'dhc_link_scan_last_run',
    'dhc_redirects',

    // Analytics, inventory and crawler
    'dhc_ga4_measurement_id',
    'dhc_gtm_container_id',
    'dhc_analytics_enabled',
    'dhc_inventory_last_pushed',
    'dhc_active_crawl',
    'dhc_crawler_diagnostics',
);

foreach ( $options as $option ) {
    delete_option( $option );
}

// ── Remove transients ───────────────────────────────────────
delete_transient( 'dhc_subscription_cache' );
delete_transient( 'dhc_subscription_cache_site_bound_v1' );
delete_transient( 'dhc_update_cache' );
delete_transient( 'dhc_fallback_profile_v1' );
delete_transient( 'dhc_show_sync_notice' );
delete_transient( 'dhc_crawler_lock' );
foreach ( array( 7, 14, 28, 30, 60, 90, 180, 365 ) as $days ) {
    delete_transient( 'dhc_dashboard_cache_d' . $days );
}
delete_transient( 'dhc_dashboard_cache' );
if ( is_array( $dynamic_transient_keys ) ) {
    foreach ( array_keys( $dynamic_transient_keys ) as $transient_key ) {
        if ( is_string( $transient_key ) && preg_match( '/^dhc_(?:dashboard_cache_d\d+|llms_link_v2_[a-f0-9]+|schema_suggestion_\d+_\d+|rate_[a-f0-9]+)$/', $transient_key ) ) {
            delete_transient( $transient_key );
        }
    }
}

// ── Remove dynamic options and transients ───────────────────
global $wpdb;
$dynamic_option_prefixes = array( 'dhc_leads_' );
foreach ( $dynamic_option_prefixes as $prefix ) {
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like( $prefix ) . '%'
        )
    );
}

$dynamic_transient_prefixes = array(
    'dhc_dashboard_cache_d',
    'dhc_llms_link_v2_',
    'dhc_schema_suggestion_',
    'dhc_rate_',
);
foreach ( $dynamic_transient_prefixes as $prefix ) {
    foreach ( array( '_transient_', '_transient_timeout_' ) as $storage_prefix ) {
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like( $storage_prefix . $prefix ) . '%'
            )
        );
    }
}

// ── Remove per-post meta ────────────────────────────────────
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
        $wpdb->esc_like( '_dhc_' ) . '%'
    )
);
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_d2_custom_schema','_d2_custom_schema_source','_d2_custom_schema_error','_d2_custom_schema_guided','_d2_custom_schema_mode')" );

// ── Remove scheduled cron events ────────────────────────────
$crons = array( 'dhc_content_decay_scan', 'dhc_monthly_lead_reset', 'dhc_heartbeat_ping', 'dhc_auto_populate_profile', 'dhc_provision_telemetry_token', 'dhc_inventory_cron', 'dhc_link_scan_cron', 'dhc_crawler_poll', 'dhc_crawler_continue', 'dhc_ai_discovery_flush_indexnow', 'dhc_ai_discovery_daily_regenerate' );
foreach ( $crons as $hook ) {
    $timestamp = wp_next_scheduled( $hook );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, $hook );
    }
    wp_clear_scheduled_hook( $hook );
}

// ── Remove generated files only when their current bytes still match the
// plugin's ownership receipt. User-created or subsequently edited files stay.
if ( is_array( $generated_file_ownership ) ) {
    foreach ( array( 'llms.txt', 'llms-full.txt' ) as $basename ) {
        $file          = ABSPATH . $basename;
        $expected_hash = $generated_file_ownership[ $basename ] ?? '';
        if ( is_string( $expected_hash ) && preg_match( '/^[a-f0-9]{64}$/', $expected_hash ) && is_file( $file ) && hash_file( 'sha256', $file ) === $expected_hash ) {
            @unlink( $file );
        }
    }
}

// Remove only the connector's exact marker-bounded Apache block.
$htaccess = ABSPATH . '.htaccess';
if ( is_file( $htaccess ) ) {
    $current = @file_get_contents( $htaccess );
    if ( false !== $current ) {
        $next = preg_replace( '/\n?# DHC: force UTF-8 charset on llms txt files.*?# \/DHC: force UTF-8 charset\n?/s', "\n", $current );
        if ( is_string( $next ) && $next !== $current ) @file_put_contents( $htaccess, $next );
    }
}
