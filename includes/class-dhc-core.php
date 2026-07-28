<?php
/**
 * DHC_Core — Main plugin controller (singleton)
 *
 * @package Dsquared_Hub_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DHC_Core {

    /** @var self|null Singleton instance */
    private static $instance = null;

    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor — wire up all hooks
     */
    private function __construct() {
        // Register REST API routes
        add_action( 'rest_api_init', array( 'DHC_REST', 'register_routes' ) );

        // Admin settings page
        if ( is_admin() ) {
            DHC_Admin::init();
        }

        // Self-hosted auto-updater
        DHC_Updater::init();

        // Privacy policy integration
        DHC_Privacy::init();

        // v1.6: Initialize heartbeat system (sends status pings to Hub)
        DHC_Heartbeat::init();

        // v1.6: Initialize Hub Sync (AJAX handlers for manual sync)
        DHC_Hub_Sync::init();

        // Initialize active modules (only if subscription is valid)
        $this->init_modules();

        // Admin notices for subscription status
        add_action( 'admin_notices', array( $this, 'subscription_notices' ) );

        // v1.6: Auto-populate notice
        add_action( 'admin_notices', array( $this, 'sync_notice' ) );
    }

    /**
     * Initialize modules based on subscription and settings
     */
    private function init_modules() {
        // v1.0 Modules ────────────────────────────────────────────

        // Schema Injector — hooks into wp_head if available
        if ( DHC_API_Key::is_module_available( 'schema' ) ) {
            DHC_Schema::init();
        }

        // SEO Meta Sync — hooks into wp_head for meta output
        if ( DHC_API_Key::is_module_available( 'seo_meta' ) ) {
            DHC_SEO_Meta::init();
        }

        // Site Health Monitor — enqueue frontend CWV script
        if ( DHC_API_Key::is_module_available( 'site_health' ) ) {
            DHC_Site_Health::init();
        }

        // Auto-Post doesn't need frontend init — it's REST-only

        // v1.5 Modules ────────────────────────────────────────────

        // AI Discovery — rewrite rules for llms.txt, schema injection, IndexNow
        if ( DHC_API_Key::is_module_available( 'ai_discovery' ) ) {
            DHC_AI_Discovery::init();
        }

        // Content Decay — cron-based post freshness scanning
        if ( DHC_API_Key::is_module_available( 'content_decay' ) ) {
            DHC_Content_Decay::init();
        }

        // Form Capture — hooks into form plugins for lead capture
        if ( DHC_API_Key::is_module_available( 'form_capture' ) ) {
            DHC_Form_Capture::init();
        }
    }

    /**
     * Resolve a WordPress post/page/product ID from a public URL.
     *
     * Core `url_to_postid()` only matches URLs that share the exact host
     * of `home_url()` and only reliably resolves posts/pages — it returns
     * 0 for WooCommerce shop archives and often for product URLs. Crawled
     * audit URLs also frequently differ by www/protocol from `home_url()`,
     * which made `url_to_postid()` return 0 for EVERY URL (the "Pushed
     * 0/50" symptom). This helper adds host-normalization, a WooCommerce
     * shop-page shortcut, and a slug/path fallback across every public
     * post type so bulk meta pushes resolve reliably.
     *
     * @param string $url Public URL.
     * @return int Post ID, or 0 if nothing matched.
     */
    public static function resolve_post_id_from_url( $url ) {
        $url = trim( (string) $url );
        if ( '' === $url ) {
            return 0;
        }

        // 1. Native attempt (fast path for same-host posts/pages).
        $id = url_to_postid( $url );
        if ( $id ) {
            return (int) $id;
        }

        $parts = wp_parse_url( $url );
        $path  = isset( $parts['path'] ) ? $parts['path'] : '';
        if ( '' === $path || '/' === $path ) {
            return 0;
        }
        $trimmed_path = trim( $path, '/' );

        // 2. Host-normalize: rebuild the URL against the real home_url()
        //    so a www/non-www or http/https mismatch stops blocking the
        //    match, then retry the native resolver.
        $rebuilt = untrailingslashit( home_url() ) . '/' . $trimmed_path . '/';
        $id      = url_to_postid( $rebuilt );
        if ( $id ) {
            return (int) $id;
        }

        // 3. WooCommerce shop archive → its configured page (never a
        //    singular post, so url_to_postid() can't reach it).
        if ( function_exists( 'wc_get_page_id' ) ) {
            $shop_id = (int) wc_get_page_id( 'shop' );
            if ( $shop_id > 0 ) {
                $shop_path = wp_parse_url( get_permalink( $shop_id ), PHP_URL_PATH );
                if ( $shop_path && trim( $shop_path, '/' ) === $trimmed_path ) {
                    return $shop_id;
                }
            }
        }

        // Public post types, minus attachments (never a meta target).
        $public_types = get_post_types( array( 'public' => true ), 'names' );
        unset( $public_types['attachment'] );

        // 4a. Hierarchical path match (pages nested under a parent).
        $page = get_page_by_path( $trimmed_path, OBJECT, $public_types );
        if ( $page instanceof WP_Post ) {
            return (int) $page->ID;
        }

        // 4b. Flat slug match on the last path segment — resolves
        //     WooCommerce products (/product/<slug>/) and posts whose
        //     permalink base url_to_postid() couldn't parse.
        $segments = array_values( array_filter( explode( '/', $trimmed_path ) ) );
        if ( ! empty( $segments ) ) {
            $slug = end( $segments );
            $hits = get_posts( array(
                'name'             => sanitize_title( $slug ),
                'post_type'        => array_values( $public_types ),
                'post_status'      => 'publish',
                'numberposts'      => 1,
                'fields'           => 'ids',
                'no_found_rows'    => true,
                'suppress_filters' => true,
            ) );
            if ( ! empty( $hits ) ) {
                return (int) $hits[0];
            }
        }

        return 0;
    }

    /**
     * Show admin notices for subscription status
     */
    public function subscription_notices() {
        $screen = get_current_screen();
        if ( ! $screen ) {
            return;
        }

        $allowed_screens = array( 'dashboard', 'toplevel_page_dsquared-hub', 'plugins' );
        if ( ! in_array( $screen->id, $allowed_screens, true ) && strpos( $screen->id, 'dsquared-hub' ) === false ) {
            return;
        }

        $api_key = get_option( 'dhc_api_key', '' );

        // No API key configured
        if ( empty( $api_key ) ) {
            if ( 'toplevel_page_dsquared-hub' !== $screen->id ) {
                echo '<div class="notice notice-info is-dismissible">';
                echo '<p><strong>' . esc_html__( 'Dsquared Hub Connector', 'dsquared-hub-connector' ) . '</strong> — ';
                echo esc_html__( 'Please', 'dsquared-hub-connector' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=dsquared-hub' ) ) . '">' . esc_html__( 'enter your API key', 'dsquared-hub-connector' ) . '</a> ' . esc_html__( 'to activate the plugin.', 'dsquared-hub-connector' ) . '</p>';
                echo '</div>';
            }
            return;
        }

        // Check subscription
        $subscription = DHC_API_Key::validate();

        if ( ! empty( $subscription['expired'] ) ) {
            echo '<div class="notice notice-warning">';
            echo '<p><strong>' . esc_html__( 'Dsquared Hub Connector', 'dsquared-hub-connector' ) . '</strong> — ';
            echo esc_html__( 'Your subscription has expired. All Hub features are currently disabled, but your website is completely unaffected. Keeping an active subscription is suggested to maintain full functionality.', 'dsquared-hub-connector' );
            echo ' <a href="https://hub.dsquaredmedia.net/dashboard.html#account" target="_blank">' . esc_html__( 'Renew your subscription', 'dsquared-hub-connector' ) . '</a></p>';
            echo '</div>';
        } elseif ( ! $subscription['valid'] && empty( $subscription['expired'] ) ) {
            echo '<div class="notice notice-error">';
            echo '<p><strong>' . esc_html__( 'Dsquared Hub Connector', 'dsquared-hub-connector' ) . '</strong> — ' . esc_html( $subscription['message'] ?? __( 'Unable to validate API key.', 'dsquared-hub-connector' ) ) . '</p>';
            echo '</div>';
        }
    }

    /**
     * Show notice when AI Discovery profile was auto-populated from Hub
     */
    public function sync_notice() {
        if ( ! get_transient( 'dhc_show_sync_notice' ) ) {
            return;
        }

        $screen = get_current_screen();
        if ( ! $screen ) {
            return;
        }

        echo '<div class="notice notice-success is-dismissible" id="dhc-sync-notice">';
        echo '<p><strong>' . esc_html__( 'Dsquared Hub Connector', 'dsquared-hub-connector' ) . '</strong> — ';
        echo esc_html__( 'Your AI Discovery business profile has been auto-populated from your Hub account data. ', 'dsquared-hub-connector' );
        echo '<a href="' . esc_url( admin_url( 'admin.php?page=dsquared-hub&tab=ai-discovery' ) ) . '">';
        echo esc_html__( 'Review and edit your profile', 'dsquared-hub-connector' );
        echo '</a></p>';
        echo '</div>';

        // Dismiss after showing once
        delete_transient( 'dhc_show_sync_notice' );
    }
}
