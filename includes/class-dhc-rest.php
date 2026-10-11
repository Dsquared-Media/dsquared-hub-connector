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
     * Return the routes this exact installed connector can serve.
     *
     * The Hub stores this list with each heartbeat so it can distinguish a
     * healthy connection from a connector that is missing a required
     * capability. Keep the inventory tied to real registered routes. Optional
     * module routes are advertised only when that module is available.
     *
     * @return array<int,string>
     */
    public static function advertised_routes() {
        $prefix = '/wp-json/' . self::NAMESPACE;
        $routes = array(
            '/status',
            '/fetch-page',
            '/crawler/wake',
            '/health',
        );

        if ( DHC_API_Key::is_module_available( 'auto_post' ) ) {
            $routes[] = '/post';
        }
        if ( DHC_API_Key::is_module_available( 'schema' ) ) {
            $routes[] = '/schema';
        }
        if ( DHC_API_Key::is_module_available( 'seo_meta' ) ) {
            $routes = array_merge( $routes, array(
                '/seo-meta',
                '/media/alt',
                '/posts/content',
                '/posts/heading',
                '/seo-meta/bulk',
            ) );
        }
        if ( DHC_API_Key::is_module_available( 'site_health' ) ) {
            $routes[] = '/link-scan';
        }
        if ( DHC_API_Key::is_module_available( 'ai_discovery' ) ) {
            $routes = array_merge( $routes, array(
                '/ai-discovery',
                '/ai-discovery/profile',
                '/ai-discovery/ping',
                '/ai-discovery/raw',
                '/ai-discovery/llms',
                '/ai-discovery/llms/regenerate',
                '/ai-discovery/indexnow/submit',
            ) );
        }
        if ( DHC_API_Key::is_module_available( 'content_decay' ) ) {
            $routes = array_merge( $routes, array(
                '/content-decay',
                '/content-decay/scan',
            ) );
        }
        if ( DHC_API_Key::is_module_available( 'form_capture' ) ) {
            $routes[] = '/leads/stats';
        }

        return array_values( array_unique( array_map(
            function( $route ) use ( $prefix ) {
                return $prefix . $route;
            },
            $routes
        ) ) );
    }

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

        // ── Outbound crawler wake hint ──────────────────────────
        // The Hub calls this immediately after it durably queues a crawl job.
        // It only schedules the existing bounded outbound poll worker; the
        // five-minute cron remains the recovery path if loopback cron is
        // unavailable. Exact-site API-key auth prevents public wake abuse.
        register_rest_route( self::NAMESPACE, '/crawler/wake', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'handle_crawler_wake' ),
            'permission_callback' => array( 'DHC_API_Key', 'authenticate_request' ),
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
                'page_url'    => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'esc_url_raw' ),
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
            'permission_callback' => array( __CLASS__, 'authorize_ai_discovery' ),
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

        // ── Reviewed Page Heading Update (v1.18) ───────────────
        // Updates the WordPress page title and, when present in stored block
        // content, the first explicit H1. Core creates a revision so the
        // change can be rolled back in wp-admin.
        register_rest_route( self::NAMESPACE, '/posts/heading', array(
            'methods'             => 'POST',
            'callback'            => array( 'DHC_Posts', 'handle_heading_update' ),
            'permission_callback' => array( 'DHC_API_Key', 'authenticate_request' ),
            'args'                => array(
                'post_id'      => array( 'required' => false, 'type' => 'integer' ),
                'url'          => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'esc_url_raw' ),
                'h1'           => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
                'revision_note'=> array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
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
     * Schedule one immediate connector poll without doing crawl work in the
     * inbound REST request. Repeated wake hints are safe and idempotent.
     *
     * @return WP_REST_Response|WP_Error
     */
    public static function handle_crawler_wake() {
        if ( ! class_exists( 'DHC_Crawler' ) ) {
            return new WP_Error( 'crawler_unavailable', 'Crawler worker is unavailable.', array( 'status' => 503 ) );
        }

        $queued = DHC_Crawler::init()->schedule_immediate_poll();
        if ( ! $queued ) {
            return new WP_Error( 'crawler_wake_failed', 'Crawler wake could not be scheduled.', array( 'status' => 503 ) );
        }

        return new WP_REST_Response( array(
            'accepted' => true,
            'mode'     => 'outbound_poll',
        ), 202 );
    }

    /**
     * Handle status / health check
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    /** Require both connector authentication and AI Discovery entitlement. */
    public static function authorize_ai_discovery( $request ) {
        $authenticated = DHC_API_Key::authenticate_request( $request );
        if ( true !== $authenticated ) {
            return $authenticated;
        }
        if ( ! DHC_API_Key::is_module_available( 'ai_discovery' ) ) {
            return new WP_Error(
                'dhc_ai_discovery_unavailable',
                'AI Discovery is not available on the current subscription.',
                array( 'status' => 403 )
            );
        }
        return true;
    }

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
     *  - The initial URL and every redirect MUST match the exact WordPress
     *    home_url() origin (scheme, host, and effective port).
     *  - Response is capped at 2 MB — plenty for a WP post, stops a
     *    misbehaving file from being relayed.
     */
    public static function handle_fetch_page( $request ) {
        $url = trim( (string) $request->get_param( 'url' ) );
        if ( empty( $url ) ) {
            return new WP_Error( 'missing_url', 'url parameter required', array( 'status' => 400 ) );
        }

        $site_url  = home_url( '/' );
        $site_host = strtolower( (string) parse_url( $site_url, PHP_URL_HOST ) );
        if ( ! self::is_same_origin( $url, $site_url ) ) {
            if ( empty( parse_url( $url, PHP_URL_HOST ) ) ) {
                return new WP_Error( 'bad_url', 'Invalid URL', array( 'status' => 400 ) );
            }
            return new WP_Error(
                'external_url',
                'fetch-page only allows URLs on this site (' . $site_host . ')',
                array( 'status' => 403 )
            );
        }

        $current_url = $url;
        $redirects   = 0;
        while ( true ) {
            // Redirects are handled here so every hop is checked before fetch.
            $resp = wp_remote_get( $current_url, array(
                'timeout'     => 20,
                'redirection' => 0,
                'sslverify'   => apply_filters( 'dhc_fetch_page_sslverify', true ),
                'limit_response_size' => 2 * 1024 * 1024 + 1,
                'headers'     => array(
                    'User-Agent' => 'DsquaredHubConnector/' . DHC_VERSION . ' (loopback-fetch)',
                    'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                ),
            ) );

            if ( is_wp_error( $resp ) ) {
                return new WP_Error( 'fetch_failed', $resp->get_error_message(), array( 'status' => 502 ) );
            }

            $code = (int) wp_remote_retrieve_response_code( $resp );
            if ( $code < 300 || $code >= 400 ) {
                break;
            }

            $location = trim( (string) wp_remote_retrieve_header( $resp, 'location' ) );
            if ( '' === $location ) {
                return new WP_Error( 'redirect_missing_location', 'Upstream redirect did not include a Location header', array( 'status' => 502 ) );
            }
            if ( $redirects >= 5 ) {
                return new WP_Error( 'too_many_redirects', 'Upstream returned too many redirects', array( 'status' => 502 ) );
            }

            $next_url = self::resolve_redirect_url( $current_url, $location );
            if ( ! $next_url ) {
                return new WP_Error( 'bad_redirect', 'Upstream returned an invalid redirect URL', array( 'status' => 502 ) );
            }
            if ( ! self::is_same_origin( $next_url, $site_url ) ) {
                return new WP_Error( 'external_redirect', 'Upstream redirect left this site', array( 'status' => 403 ) );
            }

            $current_url = $next_url;
            $redirects++;
        }

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
            'final_url'   => $current_url,
            'status_code' => $code,
            'html'        => $body,
            'fetched_at'  => current_time( 'mysql' ),
        ), 200 );
    }

    /** Whether two absolute HTTP(S) URLs share an exact origin. */
    private static function is_same_origin( $url, $site_url ) {
        $candidate = parse_url( $url );
        $site      = parse_url( $site_url );
        if ( ! is_array( $candidate ) || ! is_array( $site ) ||
            isset( $candidate['user'] ) || isset( $candidate['pass'] ) ) {
            return false;
        }

        $candidate_scheme = strtolower( (string) ( $candidate['scheme'] ?? '' ) );
        $site_scheme      = strtolower( (string) ( $site['scheme'] ?? '' ) );
        $candidate_host   = strtolower( (string) ( $candidate['host'] ?? '' ) );
        $site_host        = strtolower( (string) ( $site['host'] ?? '' ) );
        if ( ! in_array( $candidate_scheme, array( 'http', 'https' ), true ) ||
            ! in_array( $site_scheme, array( 'http', 'https' ), true ) ||
            '' === $candidate_host || '' === $site_host ) {
            return false;
        }

        $candidate_port = isset( $candidate['port'] ) ? (int) $candidate['port'] : ( 'https' === $candidate_scheme ? 443 : 80 );
        $site_port      = isset( $site['port'] ) ? (int) $site['port'] : ( 'https' === $site_scheme ? 443 : 80 );

        return $candidate_scheme === $site_scheme &&
            $candidate_host === $site_host &&
            $candidate_port === $site_port;
    }

    /** Resolve an HTTP redirect Location header against the current URL. */
    private static function resolve_redirect_url( $base_url, $location ) {
        $location = trim( $location );
        if ( '' === $location ) {
            return null;
        }
        if ( preg_match( '#^https?://#i', $location ) ) {
            return $location;
        }

        $base = parse_url( $base_url );
        if ( ! is_array( $base ) || empty( $base['scheme'] ) || empty( $base['host'] ) ) {
            return null;
        }
        if ( 0 === strpos( $location, '//' ) ) {
            return $base['scheme'] . ':' . $location;
        }
        if ( preg_match( '#^[a-z][a-z0-9+.-]*:#i', $location ) ) {
            return null;
        }

        $origin = $base['scheme'] . '://' . $base['host'] . ( isset( $base['port'] ) ? ':' . $base['port'] : '' );
        if ( '?' === substr( $location, 0, 1 ) ) {
            return $origin . ( $base['path'] ?? '/' ) . $location;
        }
        if ( '#' === substr( $location, 0, 1 ) ) {
            return $origin . ( $base['path'] ?? '/' ) . ( isset( $base['query'] ) ? '?' . $base['query'] : '' );
        }

        $location_parts = explode( '?', $location, 2 );
        $path = $location_parts[0];
        if ( '/' !== substr( $path, 0, 1 ) ) {
            $base_path = $base['path'] ?? '/';
            $path = rtrim( str_replace( '\\', '/', dirname( $base_path ) ), '/' ) . '/' . $path;
        }

        $segments = array();
        foreach ( explode( '/', $path ) as $segment ) {
            if ( '' === $segment || '.' === $segment ) {
                continue;
            }
            if ( '..' === $segment ) {
                array_pop( $segments );
                continue;
            }
            $segments[] = $segment;
        }
        $resolved = $origin . '/' . implode( '/', $segments );
        if ( '/' === substr( $path, -1 ) && '/' !== substr( $resolved, -1 ) ) {
            $resolved .= '/';
        }
        if ( isset( $location_parts[1] ) ) {
            $resolved .= '?' . $location_parts[1];
        }
        return $resolved;
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
            'advertised_routes' => self::advertised_routes(),
            'subscription' => array(
                'active'  => $subscription['valid'] ?? false,
                'tier'    => $subscription['tier'] ?? '',
                'expires' => $subscription['expires'] ?? '',
            ),
            'modules'      => $module_status,
        ), 200 );
    }
}
