<?php
/**
 * Plugin Name:       Dsquared Hub Connector
 * Plugin URI:        https://hub.dsquaredmedia.net
 * Description:       Connect your WordPress site to Dsquared Media Hub — auto-post drafts, inject schema markup, sync SEO meta, monitor site health, AI discovery, content decay alerts, and lead capture. All features are subscription-gated and will gracefully disable if your subscription lapses without affecting your website.
 * Version:           1.13.1
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
define( 'DHC_VERSION', '1.13.1' );
define( 'DHC_PLUGIN_FILE', __FILE__ );
define( 'DHC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DHC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'DHC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'DHC_HUB_API_BASE', 'https://hub.dsquaredmedia.net/api' );

// ── SVG Support conflict protection ─────────────────────────────────
// Some servers are missing the php-xml extension (DOMDocument class).
// The SVG Support plugin crashes when it tries to sanitize SVGs without it.
// We proactively disable its upload hooks during our plugin's lifecycle.
if ( ! class_exists( 'DOMDocument' ) ) {
    add_action( 'plugins_loaded', function() {
        // Remove SVG Support's upload sanitization hooks that crash without DOMDocument
        if ( function_exists( 'bodhi_svgs_sanitize_svg' ) ) {
            remove_filter( 'wp_handle_upload_prefilter', 'bodhi_svgs_sanitize_svg' );
            remove_filter( 'wp_handle_sideload_prefilter', 'bodhi_svgs_sanitize_svg' );
        }
    }, 0 ); // Priority 0 = run before everything else
}

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

// v1.6 Core: Heartbeat, Event Logger, Hub Sync
require_once DHC_PLUGIN_DIR . 'includes/class-dhc-heartbeat.php';
require_once DHC_PLUGIN_DIR . 'includes/class-dhc-event-logger.php';
require_once DHC_PLUGIN_DIR . 'includes/class-dhc-hub-sync.php';

// v1.0 Modules
require_once DHC_PLUGIN_DIR . 'includes/modules/class-dhc-auto-post.php';
require_once DHC_PLUGIN_DIR . 'includes/modules/class-dhc-schema.php';
require_once DHC_PLUGIN_DIR . 'includes/modules/class-dhc-seo-meta.php';
require_once DHC_PLUGIN_DIR . 'includes/modules/class-dhc-site-health.php';

// v1.5 Modules
require_once DHC_PLUGIN_DIR . 'includes/modules/class-dhc-ai-discovery.php';
require_once DHC_PLUGIN_DIR . 'includes/modules/class-dhc-content-decay.php';
require_once DHC_PLUGIN_DIR . 'includes/modules/class-dhc-form-capture.php';

// v1.9 Modules
require_once DHC_PLUGIN_DIR . 'includes/modules/class-dhc-media.php';

// v1.10 Modules
require_once DHC_PLUGIN_DIR . 'includes/modules/class-dhc-posts.php';
require_once DHC_PLUGIN_DIR . 'includes/modules/class-dhc-inventory.php';
require_once DHC_PLUGIN_DIR . 'includes/modules/class-dhc-analytics.php';
require_once DHC_PLUGIN_DIR . 'includes/modules/class-dhc-link-scanner.php';

// v1.11 Modules
require_once DHC_PLUGIN_DIR . 'includes/modules/class-dhc-dashboard.php';

// v1.13 Modules
require_once DHC_PLUGIN_DIR . 'includes/modules/class-dhc-event-tracker.php';

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

    // Schedule heartbeat cron
    if ( ! wp_next_scheduled( DHC_Heartbeat::CRON_HOOK ) ) {
        wp_schedule_event( time(), DHC_Heartbeat::INTERVAL_NAME, DHC_Heartbeat::CRON_HOOK );
    }

    // v1.10: schedule daily inventory push + weekly link scan
    if ( class_exists( 'DHC_Inventory' ) )    DHC_Inventory::schedule();
    if ( class_exists( 'DHC_Link_Scanner' ) ) DHC_Link_Scanner::schedule();

    // Attempt AI Discovery auto-populate from Hub (deferred to avoid blocking activation)
    wp_schedule_single_event( time() + 10, 'dhc_auto_populate_profile' );

    // Flush rewrite rules for REST endpoints
    flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'dhc_activate' );

// Handle deferred auto-populate
add_action( 'dhc_auto_populate_profile', function() {
    if ( class_exists( 'DHC_Hub_Sync' ) ) {
        DHC_Hub_Sync::auto_populate_on_enable();
    }
} );

// ── Deactivation hook ───────────────────────────────────────────────
function dhc_deactivate() {
    // Clean up transients
    delete_transient( 'dhc_subscription_cache' );
    delete_transient( 'dhc_update_cache' );
    delete_transient( 'dhc_show_sync_notice' );

    // Remove scheduled cron events
    $crons = array(
        'dhc_content_decay_scan',
        'dhc_monthly_lead_reset',
        DHC_Heartbeat::CRON_HOOK,
        'dhc_auto_populate_profile',
        // v1.10 cron hooks
        DHC_Inventory::CRON_HOOK,
        DHC_Link_Scanner::CRON_HOOK,
    );
    foreach ( $crons as $hook ) {
        $timestamp = wp_next_scheduled( $hook );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, $hook );
        }
        wp_clear_scheduled_hook( $hook );
    }

    // Send a final "disconnected" event to the Hub
    $api_key = get_option( 'dhc_api_key', '' );
    if ( ! empty( $api_key ) ) {
        $hub_url = DHC_Heartbeat::get_hub_url();
        wp_remote_post( $hub_url . '/api/plugin/event', array(
            'body'    => wp_json_encode( array(
                'event'  => 'plugin_deactivated',
                'site'   => home_url( '/' ),
                'module' => 'core',
                'data'   => array(
                    'plugin_version' => DHC_VERSION,
                    'time'           => current_time( 'mysql' ),
                ),
            ) ),
            'headers' => array(
                'Content-Type'  => 'application/json',
                'X-DHC-API-Key' => $api_key,
            ),
            'timeout'  => 5,
            'blocking' => false,
        ) );
    }

    flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'dhc_deactivate' );

// ── Initialize the plugin ───────────────────────────────────────────
function dhc_init() {
    DHC_Core::get_instance();

    // v1.10 module hooks — register cron actions + analytics injection
    if ( class_exists( 'DHC_Inventory' ) )      DHC_Inventory::init();
    if ( class_exists( 'DHC_Analytics' ) )      DHC_Analytics::init();
    if ( class_exists( 'DHC_Event_Tracker' ) )  DHC_Event_Tracker::init();
    if ( class_exists( 'DHC_Link_Scanner' ) ) DHC_Link_Scanner::init();

    // Self-heal crons on every admin load — if another plugin or a
    // migration cleared them, they'll come back next time an admin
    // loads any page. Cheap: wp_next_scheduled is a single option read.
    add_action( 'admin_init', function() {
        if ( class_exists( 'DHC_Inventory' ) )    DHC_Inventory::schedule();
        if ( class_exists( 'DHC_Link_Scanner' ) ) DHC_Link_Scanner::schedule();

        // Self-heal rewrite rules. AI Discovery registers /llms.txt and
        // /.well-known/ai-plugin.json rewrites, which stop working when
        // another plugin flushes rules after ours (e.g. a permalink-
        // changing plugin). If the rule is missing, re-flush once.
        if ( class_exists( 'DHC_API_Key' ) && DHC_API_Key::is_module_available( 'ai_discovery' ) ) {
            $rules = get_option( 'rewrite_rules' );
            $has_llms = is_array( $rules ) && isset( $rules['^llms\.txt$'] );
            if ( ! $has_llms ) {
                flush_rewrite_rules( false );
            }
        }
    } );

    // Auto-flush rewrite rules when the plugin version changes.
    // Without this, new rewrite rules (like /llms.txt) don't take effect
    // after an auto-update because the activation hook isn't re-run.
    $installed = get_option( 'dhc_installed_version' );
    if ( $installed !== DHC_VERSION ) {
        flush_rewrite_rules( false );
        update_option( 'dhc_installed_version', DHC_VERSION );
        // First-load after upgrade: fire the new crons so users see
        // data in the Link Scanner sub-page immediately after update.
        if ( class_exists( 'DHC_Inventory' ) )    DHC_Inventory::schedule();
        if ( class_exists( 'DHC_Link_Scanner' ) ) DHC_Link_Scanner::schedule();
    }
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
