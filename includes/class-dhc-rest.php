<?php
/**
 * DHC_REST — Registers all REST API endpoints for the Hub Connector
 *
 * @package Dsquared_Hub_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DHC_REST {

    const NAMESPACE = 'dsquared-hub/v1';

    /**
     * Register all REST routes
     */
    public static function register_routes() {

        // ── Status / health check (no auth) ─────────────────────
        register_rest_route( self::NAMESPACE, '/status', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'handle_status' ),
            'permission_callback' => '__return_true',
        ) );

        // ── Auto-Post endpoint ──────────────────────────────────
        register_rest_route( self::NAMESPACE, '/post', array(
            'methods'             => 'POST',
            'callback'            => array( 'DHC_Auto_Post', 'handle_request' ),
            'permission_callback' => array( 'DHC_API_Key', 'authenticate_request' ),
            'args'                => array(
                'title'   => array( 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
                'content' => array( 'required' => true,  'type' => 'string', 'sanitize_callback' => 'wp_kses_post' ),
                'excerpt' => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ),
                'categories'         => array( 'required' => false, 'type' => 'array' ),
                'tags'               => array( 'required' => false, 'type' => 'array' ),
                'featured_image_url' => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'esc_url_raw' ),
                'meta'               => array( 'required' => false, 'type' => 'object' ),
            ),
        ) );

        // ── Schema Injector endpoint ────────────────────────────
        register_rest_route( self::NAMESPACE, '/schema', array(
            'methods'             => 'POST',
            'callback'            => array( 'DHC_Schema', 'handle_request' ),
            'permission_callback' => array( 'DHC_API_Key', 'authenticate_request' ),
            'args'                => array(
                'post_id'     => array( 'required' => false, 'type' => 'integer' ),
                'url'         => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'esc_url_raw' ),
                'schema'      => array( 'required' => true,  'type' => array( 'object', 'array', 'string' ) ),
                'schema_type' => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
            ),
        ) );

        // ── SEO Meta Sync endpoint ──────────────────────────────
        register_rest_route( self::NAMESPACE, '/seo-meta', array(
            'methods'             => 'POST',
            'callback'            => array( 'DHC_SEO_Meta', 'handle_request' ),
            'permission_callback' => array( 'DHC_API_Key', 'authenticate_request' ),
            'args'                => array(
                'post_id'          => array( 'required' => false, 'type' => 'integer' ),
                'url'              => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'esc_url_raw' ),
                'meta_title'       => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
                'meta_description' => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ),
                'focus_keyword'    => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
                'og_title'         => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
                'og_description'   => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ),
            ),
        ) );

        // ── Site Health data receiver (no auth — browser beacon) ─
        register_rest_route( self::NAMESPACE, '/health', array(
            'methods'             => 'POST',
            'callback'            => array( 'DHC_Site_Health', 'handle_request' ),
            'permission_callback' => '__return_true',
            'args'                => array(
                'metrics'    => array( 'required' => true,  'type' => 'object' ),
                'url'        => array( 'required' => true,  'type' => 'string', 'sanitize_callback' => 'esc_url_raw' ),
                'user_agent' => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
            ),
        ) );

        // ── AI Discovery — business profile push ────────────────
        register_rest_route( self::NAMESPACE, '/ai-discovery', array(
            'methods'             => 'POST',
            'callback'            => array( 'DHC_AI_Discovery', 'handle_request' ),
            'permission_callback' => array( 'DHC_API_Key', 'authenticate_request' ),
            'args'                => array(
                'business_name' => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
                'description'   => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ),
                'services'      => array( 'required' => false, 'type' => 'array' ),
                'service_areas' => array( 'required' => false, 'type' => 'array' ),
                'phone'         => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
                'email'         => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_email' ),
                'address'       => array( 'required' => false, 'type' => 'object' ),
                'hours'         => array( 'required' => false, 'type' => 'object' ),
            ),
        ) );

        // ── Content Decay — trigger scan ────────────────────────
        register_rest_route( self::NAMESPACE, '/content-decay', array(
            'methods'             => 'GET',
            'callback'            => array( 'DHC_Content_Decay', 'handle_request' ),
            'permission_callback' => array( 'DHC_API_Key', 'authenticate_request' ),
        ) );

        // ── Form Capture — lead stats ───────────────────────────
        register_rest_route( self::NAMESPACE, '/leads', array(
            'methods'             => 'GET',
            'callback'            => array( 'DHC_Form_Capture', 'handle_stats_request' ),
            'permission_callback' => array( 'DHC_API_Key', 'authenticate_request' ),
        ) );

        // ── Media / Alt Text Updates (v1.9) ─────────────────────
        // Core /wp-json/wp/v2/media/:id POST requires standard WP
        // user auth, which the Hub doesn't have — only our custom
        // API key. This route accepts the API-key auth and writes
        // alt_text via update_post_meta. Supports single + bulk.
        register_rest_route( self::NAMESPACE, '/media/alt', array(
            'methods'             => 'POST',
            'callback'            => array( 'DHC_Media', 'handle_alt_request' ),
            'permission_callback' => array( 'DHC_API_Key', 'authenticate_request' ),
            'args'                => array(
                'media_id' => array( 'required' => false, 'type' => 'integer' ),
                'alt_text' => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
                'updates'  => array( 'required' => false, 'type' => 'array' ),
            ),
        ) );
    }

    /**
     * Handle status / health check
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function handle_status( $request ) {
        $subscription = DHC_API_Key::validate();
        $modules      = get_option( 'dhc_modules', array() );

        $all_modules = array(
            'auto_post', 'schema', 'seo_meta', 'site_health',
            'ai_discovery', 'content_decay', 'form_capture',
        );

        $module_status = array();
        foreach ( $all_modules as $mod ) {
            $module_status[ $mod ] = array(
                'enabled'   => ! empty( $modules[ $mod ] ),
                'available' => DHC_API_Key::is_module_available( $mod ),
            );
        }

        return new WP_REST_Response( array(
            'plugin'       => 'Dsquared Hub Connector',
            'version'      => DHC_VERSION,
            'wordpress'    => get_bloginfo( 'version' ),
            'php'          => phpversion(),
            'site_url'     => get_site_url(),
            'site_name'    => get_bloginfo( 'name' ),
            'connected'    => ! empty( get_option( 'dhc_api_key', '' ) ),
            'subscription' => array(
                'active'  => $subscription['valid'] ?? false,
                'tier'    => $subscription['tier'] ?? '',
                'expires' => $subscription['expires'] ?? '',
            ),
            'modules'      => $module_status,
        ), 200 );
    }
}
