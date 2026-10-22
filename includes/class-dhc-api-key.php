<?php
/**
 * DHC_API_Key — Handles API key validation and subscription status
 *
 * @package Dsquared_Hub_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DHC_API_Key {

    /** @var array Per-request validation results, keyed by site and key hash. */
    private static $request_validation = array();

    /** @var string Transient key for caching subscription data */
    const CACHE_KEY = 'dhc_subscription_cache_site_bound_v1';

    /** @var int Cache duration in seconds (12 hours) */
    const CACHE_DURATION = 43200;

    /**
     * Module tier mapping — defines which modules are available at each tier.
     * Higher tiers inherit all modules from lower tiers.
     */
    const TIER_MODULES = array(
        'starter' => array( 'auto_post' ),
        'growth'  => array( 'auto_post', 'schema', 'seo_meta', 'content_decay' ),
        'pro'     => array( 'auto_post', 'schema', 'seo_meta', 'site_health', 'ai_discovery', 'content_decay', 'form_capture' ),
    );

    /**
     * Validate the API key against the Hub
     *
     * @param string $api_key The API key to validate.
     * @param bool   $force   Force a fresh validation (bypass cache).
     * @return array Subscription data or error.
     */
    public static function validate( $api_key = '', $force = false ) {
        if ( empty( $api_key ) ) {
            $api_key = get_option( 'dhc_api_key', '' );
        }

        if ( empty( $api_key ) ) {
            return array(
                'valid'   => false,
                'message' => esc_html__( 'No API key configured.', 'dsquared-hub-connector' ),
            );
        }

        // Keep forced and cached lookups separate so a privileged request never
        // reuses a passive cached decision. Within either mode, WordPress may
        // invoke the same REST permission callback more than once while
        // building the response (for example, for the Allow header), so reuse
        // the first result for the remainder of this PHP request.
        $request_base = home_url( '/' ) . '|' . hash( 'sha256', $api_key );
        $request_key  = $request_base . '|' . ( $force ? 'force' : 'cached' );

        // The core checks entitlement once per optional module. Remember the
        // result for this PHP request so a Hub failure can never multiply one
        // bounded refresh into several consecutive timeouts.
        if ( ! $force && isset( self::$request_validation[ $request_base . '|force' ] ) ) {
            return self::$request_validation[ $request_base . '|force' ];
        }

        if ( isset( self::$request_validation[ $request_key ] ) ) {
            return self::$request_validation[ $request_key ];
        }

        // Check cache first (unless forced)
        if ( ! $force ) {
            $cached = get_transient( self::CACHE_KEY );
            if ( false !== $cached && is_array( $cached )
                && ( $cached['site_url'] ?? '' ) === home_url( '/' )
                && ( $cached['key_hash'] ?? '' ) === hash( 'sha256', $api_key ) ) {
                self::$request_validation[ $request_key ] = $cached;
                return self::$request_validation[ $request_key ];
            }
        }

        // First, validate key format locally
        if ( ! self::validate_key_format( $api_key ) ) {
            self::$request_validation[ $request_key ] = array(
                'valid'   => false,
                'message' => esc_html__( 'Invalid API key format. Keys should start with dhc_live_ or dhc_test_ followed by a 30–80 character body (letters, digits, - or _).', 'dsquared-hub-connector' ),
            );
            return self::$request_validation[ $request_key ];
        }

        // Public page rendering must never wait for the Hub. Cached
        // entitlements initialize modules immediately. When the cache is
        // absent, a controlled context refreshes it while public rendering
        // fails closed for connector modules and keeps the client site fast.
        if ( ! $force && ! self::is_refresh_context() ) {
            self::$request_validation[ $request_key ] = array(
                'valid'   => false,
                'message' => esc_html__( 'Connector verification is pending a background refresh. The website remains available.', 'dsquared-hub-connector' ),
            );
            return self::$request_validation[ $request_key ];
        }

        // Try Hub API validation
        $response = wp_remote_get(
            DHC_HUB_API_BASE . '/plugin/validate-key',
            array(
                'headers' => array(
                    'X-DHC-API-Key' => $api_key,
                    'X-DHC-Site-Url' => home_url( '/' ),
                    'Content-Type'  => 'application/json',
                ),
                'timeout' => 3,
            )
        );

        // If Hub API is reachable and returns valid data, use it
        if ( ! is_wp_error( $response ) ) {
            $code = wp_remote_retrieve_response_code( $response );
            $body = json_decode( wp_remote_retrieve_body( $response ), true );

            if ( 200 === $code && ! empty( $body ) && isset( $body['tier'], $body['site_id'] ) ) {
                $subscription = array(
                    'valid'   => true,
                    'tier'    => sanitize_text_field( $body['tier'] ?? 'starter' ),
                    'expires' => sanitize_text_field( $body['expires'] ?? '' ),
                    'modules' => self::get_tier_modules( $body['tier'] ?? 'starter' ),
                    'site_id' => sanitize_text_field( $body['site_id'] ?? '' ),
                    'site_url' => home_url( '/' ),
                    'key_hash' => hash( 'sha256', $api_key ),
                );

                if ( ! empty( $subscription['expires'] ) ) {
                    $expiry = strtotime( $subscription['expires'] );
                    if ( $expiry && $expiry < time() ) {
                        $subscription['valid']   = false;
                        $subscription['message'] = esc_html__( 'Your Dsquared Hub subscription has expired. Features are currently disabled but your website is unaffected. Renew your subscription to restore full functionality.', 'dsquared-hub-connector' );
                        $subscription['expired'] = true;
                    }
                }

                set_transient( self::CACHE_KEY, $subscription, self::CACHE_DURATION );
                update_option( 'dhc_subscription', array(
                    'status'  => $subscription['valid'] ? 'active' : 'inactive',
                    'tier'    => $subscription['tier'],
                    'expires' => $subscription['expires'],
                ) );

                self::$request_validation[ $request_key ] = $subscription;
                return self::$request_validation[ $request_key ];
            }

            // API returned an explicit error (e.g. 403 invalid key)
            if ( 200 !== $code && ! empty( $body['message'] ) ) {
                self::$request_validation[ $request_key ] = array(
                    'valid'   => false,
                    'message' => sanitize_text_field( $body['message'] ),
                );
                return self::$request_validation[ $request_key ];
            }
        }

        // A key cannot be accepted on its shape alone. A network failure may
        // be temporary, but it must not make another site's key look valid.
        self::$request_validation[ $request_key ] = array(
            'valid'   => false,
            'message' => esc_html__( 'Could not verify this key for this WordPress URL. Check the Hub connection and try again; the saved key has not changed.', 'dsquared-hub-connector' ),
        );
        return self::$request_validation[ $request_key ];
    }

    /**
     * Whether this request may synchronously refresh Hub entitlement data.
     *
     * Privileged REST authentication passes $force and remains authoritative
     * and fail-closed. Ordinary public traffic deliberately returns false.
     *
     * @return bool
     */
    private static function is_refresh_context() {
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            return true;
        }

        if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
            return true;
        }

        if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
            return true;
        }

        return function_exists( 'is_admin' )
            && is_admin()
            && function_exists( 'current_user_can' )
            && current_user_can( 'manage_options' );
    }

    /**
     * Check if a specific module is available for the current subscription
     *
     * @param string $module Module slug.
     * @return bool
     */
    public static function is_module_available( $module ) {
        $subscription = self::validate();

        if ( ! $subscription['valid'] ) {
            return false;
        }

        // Check if module is enabled in plugin settings
        $enabled_modules = get_option( 'dhc_modules', array() );
        if ( empty( $enabled_modules[ $module ] ) ) {
            return false;
        }

        // Check if module is available in current tier
        return in_array( $module, $subscription['modules'] ?? array(), true );
    }

    /**
     * Get modules available for a given tier
     *
     * @param string $tier Subscription tier.
     * @return array
     */
    public static function get_tier_modules( $tier ) {
        $tier = strtolower( $tier );
        return self::TIER_MODULES[ $tier ] ?? array( 'auto_post' );
    }

    /**
     * Get human-readable tier name
     *
     * @param string $tier Tier slug.
     * @return string
     */
    public static function get_tier_label( $tier ) {
        $labels = array(
            'starter' => __( 'Starter', 'dsquared-hub-connector' ),
            'growth'  => __( 'Growth', 'dsquared-hub-connector' ),
            'pro'     => __( 'Professional', 'dsquared-hub-connector' ),
        );
        return $labels[ strtolower( $tier ) ] ?? ucfirst( $tier );
    }

    /**
     * Validate API key format locally
     *
     * Valid formats (Hub has used two generators historically):
     *   dhc_live_XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX  — 40 chars alphanumeric (legacy)
     *   dhc_live_XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX — 43 chars base64url (current)
     *   dhc_test_ variants of the above
     *
     * Allowed character class for the body is base64url:
     *   A-Z a-z 0-9 - _
     *
     * @param string $api_key The API key to validate.
     * @return bool
     */
    public static function validate_key_format( $api_key ) {
        // Must start with dhc_live_ or dhc_test_
        if ( strpos( $api_key, 'dhc_live_' ) !== 0 && strpos( $api_key, 'dhc_test_' ) !== 0 ) {
            return false;
        }

        // Length check: enough body to carry real entropy, but not so much
        // we accept a paste of the wrong thing. Covers both 40-char legacy
        // keys and 43-char base64url keys with headroom either side.
        $key_body = substr( $api_key, 9 );
        if ( strlen( $key_body ) < 20 || strlen( $key_body ) > 80 ) {
            return false;
        }

        // base64url character set — legacy keys (alphanumeric only) also
        // pass this filter, so both generations validate cleanly.
        if ( ! preg_match( '/^[A-Za-z0-9_\-]+$/', $key_body ) ) {
            return false;
        }

        return true;
    }

    /**
     * Clear cached subscription data
     */
    public static function clear_cache() {
        delete_transient( self::CACHE_KEY );
        delete_transient( 'dhc_subscription_cache' );
    }

    /**
     * Authenticate an incoming REST request from the Hub
     *
     * @param WP_REST_Request $request The incoming request.
     * @return bool|WP_Error
     */
    public static function authenticate_request( $request ) {
        $api_key = $request->get_header( 'X-DHC-API-Key' );

        if ( empty( $api_key ) ) {
            return new WP_Error(
                'dhc_missing_key',
                esc_html__( 'API key is required.', 'dsquared-hub-connector' ),
                array( 'status' => 401 )
            );
        }

        $stored_key = get_option( 'dhc_api_key', '' );

        if ( empty( $stored_key ) || ! hash_equals( $stored_key, $api_key ) ) {
            return new WP_Error(
                'dhc_invalid_key',
                esc_html__( 'Invalid API key.', 'dsquared-hub-connector' ),
                array( 'status' => 403 )
            );
        }

        // Privileged requests must observe Hub revocation immediately; the
        // subscription cache is only for passive module/UI availability.
        $subscription = self::validate( $stored_key, true );
        if ( ! $subscription['valid'] ) {
            return new WP_Error(
                'dhc_subscription_inactive',
                $subscription['message'] ?? esc_html__( 'Subscription is not active.', 'dsquared-hub-connector' ),
                array( 'status' => 403 )
            );
        }

        return true;
    }
}
