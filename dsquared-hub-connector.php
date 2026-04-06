<?php
/**
 * Plugin Name:       Dsquared Hub Connector
 * Plugin URI:        https://hub.dsquaredmedia.net
 * Description:       Connect your WordPress site to Dsquared Media Hub — auto-post drafts, inject schema markup, sync SEO meta, monitor site health, AI discovery, content decay alerts, and lead capture. All features are subscription-gated and will gracefully disable if your subscription lapses without affecting your website.
 * Version:           1.5.1
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Dsquared Media
 * Author URI:        https://dsquaredmedia.net
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       dsquared-hub-connector
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ── Plugin constants ────────────────────────────────────────────────
define( 'DHC_VERSION', '1.5.1' );
define( 'DHC_PLUGIN_FILE', __FILE__ );
define( 'DHC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DHC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'DHC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'DHC_HUB_API_BASE', 'https://hub.dsquaredmedia.net/api' );

// ── Compatibility checks ────────────────────────────────────────────
if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
    add_action( 'admin_notices', function () {
        echo '<div class="notice notice-error"><p><strong>Dsquared Hub Connector</strong> requires PHP 7.4 or higher. You are running PHP ' . esc_html( PHP_VERSION ) . '.</p></div>';
    } );
    return;
}

// ── Autoload includes ───────────────────────────────────────────────
// Core classes
require_once DHC_PLUGIN_DIR . 'includes/class-dhc-api-key.php';
require_once DHC_PLUGIN_DIR . 'includes/class-dhc-rest.php';
require_once DHC_PLUGIN_DIR . 'includes/class-dhc-admin.php';
require_once DHC_PLUGIN_DIR . 'includes/class-dhc-updater.php';
require_once DHC_PLUGIN_DIR . 'includes/class-dhc-privacy.php';
require_once DHC_PLUGIN_DIR . 'includes/class-dhc-core.php';

// v1.0 Modules
require_once DHC_PLUGIN_DIR . 'includes/modules/class-dhc-auto-post.php';
require_once DHC_PLUGIN_DIR . 'includes/modules/class-dhc-schema.php';
require_once DHC_PLUGIN_DIR . 'includes/modules/class-dhc-seo-meta.php';
require_once DHC_PLUGIN_DIR . 'includes/modules/class-dhc-site-health.php';

// v1.5 Modules
require_once DHC_PLUGIN_DIR . 'includes/modules/class-dhc-ai-discovery.php';
require_once DHC_PLUGIN_DIR . 'includes/modules/class-dhc-content-decay.php';
require_once DHC_PLUGIN_DIR . 'includes/modules/class-dhc-form-capture.php';

// ── Activation hook ─────────────────────────────────────────────────
function dhc_activate() {
    // WordPress version check
    if ( version_compare( get_bloginfo( 'version' ), '5.8', '<' ) ) {
        deactivate_plugins( DHC_PLUGIN_BASENAME );
        wp_die(
            esc_html__( 'Dsquared Hub Connector requires WordPress 5.8 or higher.', 'dsquared-hub-connector' ),
            'Plugin Activation Error',
            array( 'back_link' => true )
        );
    }

    // Set default options
    if ( false === get_option( 'dhc_api_key' ) ) {
        add_option( 'dhc_api_key', '' );
    }
    if ( false === get_option( 'dhc_modules' ) ) {
        add_option( 'dhc_modules', array(
            'auto_post'     => true,
            'schema'        => true,
            'seo_meta'      => true,
            'site_health'   => true,
            'ai_discovery'  => true,
            'content_decay' => true,
            'form_capture'  => true,
        ) );
    } else {
        // Ensure new modules are added to existing installs
        $modules = get_option( 'dhc_modules', array() );
        $defaults = array(
            'ai_discovery'  => true,
            'content_decay' => true,
            'form_capture'  => true,
        );
        foreach ( $defaults as $key => $val ) {
            if ( ! isset( $modules[ $key ] ) ) {
                $modules[ $key ] = $val;
            }
        }
        update_option( 'dhc_modules', $modules );
    }

    if ( false === get_option( 'dhc_subscription' ) ) {
        add_option( 'dhc_subscription', array(
            'status'  => 'inactive',
            'tier'    => '',
            'expires' => '',
        ) );
    }

    // Flush rewrite rules for REST endpoints
    flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'dhc_activate' );

// ── Deactivation hook ───────────────────────────────────────────────
function dhc_deactivate() {
    // Clean up transients
    delete_transient( 'dhc_subscription_cache' );
    delete_transient( 'dhc_update_cache' );

    // Remove scheduled cron events
    $crons = array( 'dhc_content_decay_scan', 'dhc_monthly_lead_reset' );
    foreach ( $crons as $hook ) {
        $timestamp = wp_next_scheduled( $hook );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, $hook );
        }
    }

    flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'dhc_deactivate' );

// ── Initialize the plugin ───────────────────────────────────────────
function dhc_init() {
    DHC_Core::get_instance();
}
add_action( 'plugins_loaded', 'dhc_init' );

// ── Add settings link on plugin page ────────────────────────────────
function dhc_plugin_action_links( $links ) {
    $settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=dsquared-hub' ) ) . '">' .
                     esc_html__( 'Settings', 'dsquared-hub-connector' ) . '</a>';
    array_unshift( $links, $settings_link );
    return $links;
}
add_filter( 'plugin_action_links_' . DHC_PLUGIN_BASENAME, 'dhc_plugin_action_links' );
