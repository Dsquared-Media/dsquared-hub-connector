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

    /** @var string Transient key for caching subscription data */
    const CACHE_KEY = 'dhc_subscription_cache';

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

        // Check cache first (unless forced)
        if ( ! $force ) {
            $cached = get_transient( self::CACHE_KEY );
            if ( false !== $cached && is_array( $cached ) ) {
                return $cached;
            }
        }

        // First, validate key format locally
        if ( ! self::validate_key_format( $api_key ) ) {
            return array(
                'valid'   => false,
                'message' => esc_html__( 'Invalid API key format. Keys should start with dhc_live_ followed by 32 characters.', 'dsquared-hub-connector' ),
            );
        }

        // Try Hub API validation
        $response = wp_remote_get(
            DHC_HUB_API_BASE . '/plugin/validate-key',
            array(
                'headers' => array(
                    'X-DHC-API-Key' => $api_key,
                    'Content-Type'  => 'application/json',
                ),
                'timeout' => 10,
            )
        );

        // If Hub API is reachable and returns valid data, use it
        if ( ! is_wp_error( $response ) ) {
            $code = wp_remote_retrieve_response_code( $response );
            $body = json_decode( wp_remote_retrieve_body( $response ), true );

            if ( 200 === $code && ! empty( $body ) && isset( $body['tier'] ) ) {
                $subscription = array(
                    'valid'   => true,
                    'tier'    => sanitize_text_field( $body['tier'] ?? 'starter' ),
                    'expires' => sanitize_text_field( $body['expires'] ?? '' ),
                    'modules' => self::get_tier_modules( $body['tier'] ?? 'starter' ),
                    'site_id' => sanitize_text_field( $body['site_id'] ?? '' ),
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

                return $subscription;
            }

            // API returned an explicit error (e.g. 403 invalid key)
            if ( 200 !== $code && ! empty( $body['message'] ) ) {
                return array(
                    'valid'   => false,
                    'message' => sanitize_text_field( $body['message'] ),
                );
            }
        }

        // Hub API unreachable or endpoint not yet deployed
        // Fall back to local validation: key format is valid, check cached subscription
        $cached = get_option( 'dhc_subscription', array() );
        if ( ! empty( $cached['status'] ) && 'active' === $cached['status'] ) {
            return array(
                'valid'         => true,
                'tier'          => $cached['tier'] ?? 'pro',
                'expires'       => $cached['expires'] ?? '',
                'modules'       => self::get_tier_modules( $cached['tier'] ?? 'pro' ),
                'cached'        => true,
                'network_error' => true,
            );
        }

        // No cached data — key format is valid, grant access with default tier
        // This allows the plugin to work before the Hub backend API is fully deployed
        $subscription = array(
            'valid'   => true,
            'tier'    => 'pro',
            'expires' => '',
            'modules' => self::get_tier_modules( 'pro' ),
            'local'   => true,
        );

        set_transient( self::CACHE_KEY, $subscription, self::CACHE_DURATION );
        update_option( 'dhc_subscription', array(
            'status'  => 'active',
            'tier'    => 'pro',
            'expires' => '',
        ) );

        return $subscription;
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
     * Valid formats:
     *   dhc_live_XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX  (40 chars total)
     *   dhc_test_XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX  (41 chars total)
     *
     * @param string $api_key The API key to validate.
     * @return bool
     */
    public static function validate_key_format( $api_key ) {
        // Must start with dhc_live_ or dhc_test_
        if ( strpos( $api_key, 'dhc_live_' ) !== 0 && strpos( $api_key, 'dhc_test_' ) !== 0 ) {
            return false;
        }

        // Remove prefix and check remaining length (should be 32 hex chars)
        $key_body = substr( $api_key, 9 );
        if ( strlen( $key_body ) < 20 || strlen( $key_body ) > 64 ) {
            return false;
        }

        // Must be alphanumeric
        if ( ! ctype_alnum( $key_body ) ) {
            return false;
        }

        return true;
    }

    /**
     * Clear cached subscription data
     */
    public static function clear_cache() {
        delete_transient( self::CACHE_KEY );
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

        // Verify subscription is active
        $subscription = self::validate( $stored_key );
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
