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

        // Initialize active modules (only if subscription is valid)
        $this->init_modules();

        // Admin notices for subscription status
        add_action( 'admin_notices', array( $this, 'subscription_notices' ) );
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
}
