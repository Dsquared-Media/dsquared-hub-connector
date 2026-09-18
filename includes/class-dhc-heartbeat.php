<?php
/**
 * DHC_Heartbeat — Sends periodic status pings to the Hub
 *
 * Every 5 minutes (via WP-Cron), the plugin sends a heartbeat to the Hub
 * containing plugin version, WordPress/PHP versions, active modules, and
 * their last activity timestamps. This powers the WordPress Connector
 * Status page in the Hub dashboard.
 *
 * @package Dsquared_Hub_Connector
 * @since   1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DHC_Heartbeat {

    /** @var self|null Singleton instance */
    private static $instance = null;

    /** Cron hook name */
    const CRON_HOOK = 'dhc_heartbeat_ping';

    /** Custom cron interval name */
    const INTERVAL_NAME = 'dhc_five_minutes';

    /**
     * Initialize the heartbeat system
     */
    public static function init() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor — wire up cron hooks
     */
    public function __construct() {
        // Register custom cron interval
        add_filter( 'cron_schedules', array( $this, 'add_cron_interval' ) );

        // Schedule heartbeat
        add_action( 'init', array( $this, 'schedule_heartbeat' ) );

        // Handle the heartbeat cron event
        add_action( self::CRON_HOOK, array( $this, 'send_heartbeat' ) );
    }

    /**
     * Add a 5-minute cron interval
     *
     * @param array $schedules Existing cron schedules.
     * @return array Modified schedules.
     */
    public function add_cron_interval( $schedules ) {
        $schedules[ self::INTERVAL_NAME ] = array(
            'interval' => 300, // 5 minutes
            'display'  => esc_html__( 'Every 5 Minutes', 'dsquared-hub-connector' ),
        );
        return $schedules;
    }

    /**
     * Schedule the heartbeat cron if not already scheduled
     */
    public function schedule_heartbeat() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), self::INTERVAL_NAME, self::CRON_HOOK );
        }
    }

    /**
     * Send heartbeat to the Hub
     *
     * POSTs to /api/plugin/heartbeat with:
     * - site_url
     * - plugin_version
     * - wp_version
     * - php_version
     * - active_modules (array of module keys with last activity timestamps)
     */
    public function send_heartbeat() {
        $api_key = get_option( 'dhc_api_key', '' );
        if ( empty( $api_key ) ) {
            return;
        }

        // Don't send heartbeat if subscription is invalid
        $subscription = DHC_API_Key::validate();
        if ( empty( $subscription['valid'] ) ) {
            return;
        }

        $modules_setting = get_option( 'dhc_modules', array() );
        $active_modules  = array();

        // Build active modules list with last activity timestamps
        $module_keys = array(
            'auto_post'     => 'auto_post',
            'schema'        => 'schema_injector',
            'seo_meta'      => 'seo_meta_sync',
            'site_health'   => 'site_health',
            'ai_discovery'  => 'ai_discovery',
            'content_decay' => 'content_decay',
            'form_capture'  => 'form_capture',
        );

        foreach ( $module_keys as $setting_key => $module_id ) {
            if ( ! empty( $modules_setting[ $setting_key ] ) ) {
                $active_modules[] = $module_id;
            }
        }

        $payload = array(
            'site_url'        => home_url( '/' ),
            'plugin_version'  => DHC_VERSION,
            'wp_version'      => get_bloginfo( 'version' ),
            'php_version'     => phpversion(),
            'active_modules'  => $active_modules,
        );

        $hub_url = self::get_hub_url();

        $response = wp_remote_post( $hub_url . '/api/plugin/heartbeat', array(
            'body'    => wp_json_encode( $payload ),
            'headers' => array(
                'Content-Type'  => 'application/json',
                'X-DHC-API-Key' => $api_key,
            ),
            'timeout'  => 15,
            'blocking' => false,
        ) );

        // Store last heartbeat time locally
        update_option( 'dhc_last_heartbeat', array(
            'time'     => current_time( 'mysql' ),
            'status'   => is_wp_error( $response ) ? 'error' : 'sent',
            'modules'  => count( $active_modules ),
        ) );
    }

    /**
     * Get the Hub URL from subscription data or fallback to constant
     *
     * @return string Hub base URL
     */
    public static function get_hub_url() {
        $sub = get_option( 'dhc_subscription', array() );
        return ! empty( $sub['hub_url'] ) ? $sub['hub_url'] : 'https://hub.dsquaredmedia.net';
    }

    /**
     * Unschedule the heartbeat cron on deactivation
     */
    public static function deactivate() {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
        }
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    /**
     * Provision a narrow telemetry token from the Hub.
     *
     * The full connector key (dhc_api_key) must NEVER appear in public HTML
     * because it also authenticates privileged Hub and WordPress REST write
     * routes (post, schema, seo-meta, posts/content, seo-meta/bulk, media/alt).
     * The telemetry token (dhc_telemetry_token) is a Hub-issued credential
     * scoped to heartbeat, event/events, and cwv-report only — safe to embed
     * in public JavaScript for beacon/CWV reporting.
     *
     * Called on activation and when the API key is saved, so the public-safe
     * token is available immediately for the event tracker and site health modules.
     *
     * @return bool True if a valid token is already stored or was just provisioned.
     */
    public static function maybe_provision_telemetry_token() {
        $api_key = get_option( 'dhc_api_key', '' );
        if ( empty( $api_key ) ) {
            return false;
        }

        // Skip if a token is already stored — no need to re-provision.
        $existing = get_option( 'dhc_telemetry_token', '' );
        if ( ! empty( $existing ) ) {
            return true;
        }

        $hub_url = self::get_hub_url();

        $response = wp_remote_post( $hub_url . '/api/plugin/telemetry-token', array(
            'body'    => wp_json_encode( array( 'site_url' => home_url( '/' ) ) ),
            'headers' => array(
                'Content-Type'  => 'application/json',
                'X-DHC-API-Key' => $api_key,
                'X-DHC-Site-Url' => home_url( '/' ),
            ),
            'timeout'  => 15,
            'blocking' => true,
        ) );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( 200 === $code && ! empty( $body['telemetry_token'] ) ) {
            update_option( 'dhc_telemetry_token', sanitize_text_field( $body['telemetry_token'] ) );
            return true;
        }

        return false;
    }
}
