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

        // ── Loopback fetch-page (bypasses Cloudflare) ──────────
        // Hub crawler calls this to pull page HTML from the WP server
        // itself, avoiding any CDN/WAF in front of the site. Guarded
        // by X-DHC-API-Key + same-origin URL check.
        register_rest_route( self::NAMESPACE, '/fetch-page', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'handle_fetch_page' ),
            'permission_callback' => array( 'DHC_API_Key', 'authenticate_request' ),
            'args'                => array(
                'url' => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'esc_url_raw' ),
            ),
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

        // ── Post Content Update (v1.10) ─────────────────────────
        // Closes the AutoReason loop. The Hub ships a winning body
        // rewrite and this route replaces post_content via
        // wp_update_post() which auto-creates a WP revision for
        // rollback via the core revisions UI.
        register_rest_route( self::NAMESPACE, '/posts/content', array(
            'methods'             => 'POST',
            'callback'            => array( 'DHC_Posts', 'handle_content_update' ),
            'permission_callback' => array( 'DHC_API_Key', 'authenticate_request' ),
            'args'                => array(
                'post_id'      => array( 'required' => false, 'type' => 'integer' ),
                'url'          => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'esc_url_raw' ),
                'content_html' => array( 'required' => true,  'type' => 'string' ),
                'post_title'   => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
                'revision_note'=> array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
                'dry_run'      => array( 'required' => false, 'type' => 'boolean' ),
            ),
        ) );

        // ── Bulk SEO Meta (v1.10) ──────────────────────────────
        // Up to 100 meta_title + meta_description updates per call.
        // Mirrors the bulk alt-text pattern. One HTTP trip for a
        // whole site's meta rescue instead of N per-page calls.
        register_rest_route( self::NAMESPACE, '/seo-meta/bulk', array(
            'methods'             => 'POST',
            'callback'            => array( 'DHC_SEO_Meta', 'handle_bulk_request' ),
            'permission_callback' => array( 'DHC_API_Key', 'authenticate_request' ),
            'args'                => array(
                'updates' => array( 'required' => true, 'type' => 'array' ),
            ),
        ) );

        // ── Link Scanner — list 404s + broken links (v1.10) ────
        // Returns recent findings so the Hub's Today widget can
        // surface them. Writes happen inside the plugin's cron jobs.
        register_rest_route( self::NAMESPACE, '/link-scan', array(
            'methods'             => 'GET',
            'callback'            => array( 'DHC_Link_Scanner', 'handle_list_request' ),
            'permission_callback' => array( 'DHC_API_Key', 'authenticate_request' ),
        ) );
    }

    /**
     * Handle status / health check
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    /**
     * Fetch a page from this WordPress site and return its rendered HTML.
     *
     * Used by the Hub's audit crawler to bypass Cloudflare / Sucuri / other
     * bot-protection layers — the request originates from the WP server
     * itself (loopback), so it never hits the CF proxy that's blocking
     * external crawlers.
     *
     * SECURITY:
     *  - Requires a valid X-DHC-API-Key.
     *  - URL host MUST match the WordPress home_url() host. We reject any
     *    URL pointing elsewhere so this endpoint can't be weaponized as
     *    an open proxy to scrape arbitrary third-party sites.
     *  - Response is capped at 2 MB — plenty for a WP post, stops a
     *    misbehaving file from being relayed.
     */
    public static function handle_fetch_page( $request ) {
        $url = trim( (string) $request->get_param( 'url' ) );
        if ( empty( $url ) ) {
            return new WP_Error( 'missing_url', 'url parameter required', array( 'status' => 400 ) );
        }

        // Open-proxy guard: only allow URLs pointing at this WP install.
        $req_host  = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );
        $site_host = strtolower( (string) parse_url( home_url( '/' ), PHP_URL_HOST ) );
        if ( empty( $req_host ) ) {
            return new WP_Error( 'bad_url', 'Invalid URL', array( 'status' => 400 ) );
        }
        $norm = function( $h ) { return preg_replace( '/^www\./', '', $h ); };
        if ( $norm( $req_host ) !== $norm( $site_host ) ) {
            return new WP_Error(
                'external_url',
                'fetch-page only allows URLs on this site (' . $site_host . ')',
                array( 'status' => 403 )
            );
        }

        // Use wp_remote_get because it resolves to the loopback address
        // when WP_HOME matches — bypasses any edge CDN in front.
        $resp = wp_remote_get( $url, array(
            'timeout'     => 20,
            'redirection' => 5,
            'sslverify'   => apply_filters( 'dhc_fetch_page_sslverify', true ),
            'headers'     => array(
                'User-Agent' => 'DsquaredHubConnector/' . DHC_VERSION . ' (loopback-fetch)',
                'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            ),
        ) );

        if ( is_wp_error( $resp ) ) {
            return new WP_Error( 'fetch_failed', $resp->get_error_message(), array( 'status' => 502 ) );
        }

        $code = (int) wp_remote_retrieve_response_code( $resp );
        $body = (string) wp_remote_retrieve_body( $resp );

        if ( $code >= 400 ) {
            return new WP_Error( 'upstream_error', 'Upstream returned HTTP ' . $code, array( 'status' => 502 ) );
        }

        // Cap at 2 MB — a normal post is well under 250 KB.
        if ( strlen( $body ) > 2 * 1024 * 1024 ) {
            $body = substr( $body, 0, 2 * 1024 * 1024 );
        }

        return new WP_REST_Response( array(
            'url'         => $url,
            'status_code' => $code,
            'html'        => $body,
            'fetched_at'  => current_time( 'mysql' ),
        ), 200 );
    }

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
