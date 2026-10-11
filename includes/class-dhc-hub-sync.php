<?php
/**
 * DHC_Hub_Sync — Syncs data from the Hub to the WordPress plugin
 *
 * Handles auto-populating the AI Discovery business profile from Hub data
 * when the module is first enabled or when the user clicks "Sync from Hub".
 *
 * @package Dsquared_Hub_Connector
 * @since   1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DHC_Hub_Sync {

    public static function profile_request_url( $hub_url, $site_url ) {
        return add_query_arg( 'site_url', $site_url, rtrim( $hub_url, '/' ) . '/api/plugin/ai-discovery-profile' );
    }

    /** Pure canonical merge used by automatic sync, manual sync, and tests. */
    public static function merge_profile_data( $hub_profile, $local_profile = array(), $force = false ) {
        if ( class_exists( 'DHC_AI_Discovery' ) ) {
            $local_profile = DHC_AI_Discovery::normalize_business_profile( $local_profile );
            $hub_profile = DHC_AI_Discovery::normalize_business_profile( $hub_profile, $force ? array() : $local_profile );
        }
        if ( $force || empty( $local_profile ) ) return $hub_profile;
        $merged = $local_profile;
        foreach ( $hub_profile as $key => $value ) if ( ! isset( $merged[ $key ] ) || self::is_empty_value( $merged[ $key ] ) ) $merged[ $key ] = $value;
        return class_exists( 'DHC_AI_Discovery' ) ? DHC_AI_Discovery::normalize_business_profile( $merged ) : $merged;
    }

    /**
     * Initialize hooks
     */
    public static function init() {
        // AJAX handler for manual sync from Hub
        add_action( 'wp_ajax_dhc_sync_from_hub', array( __CLASS__, 'ajax_sync_from_hub' ) );

        // AJAX handler for dismissing the auto-populate notice
        add_action( 'wp_ajax_dhc_dismiss_sync_notice', array( __CLASS__, 'ajax_dismiss_sync_notice' ) );
    }

    /**
     * Fetch AI Discovery profile from the Hub
     *
     * Calls GET /api/plugin/ai-discovery-profile with the site URL
     * and returns the compiled business profile data.
     *
     * @return array|WP_Error Profile data or error.
     */
    public static function fetch_profile_from_hub() {
        $api_key = get_option( 'dhc_api_key', '' );
        if ( empty( $api_key ) ) {
            return new WP_Error( 'no_api_key', __( 'No API key configured.', 'dsquared-hub-connector' ) );
        }

        $hub_url  = DHC_Heartbeat::get_hub_url();
        $site_url = home_url( '/' );

        $response = wp_remote_get(
            self::profile_request_url( $hub_url, $site_url ),
            array(
                'headers' => array(
                    'Content-Type'  => 'application/json',
                    'X-DHC-API-Key' => $api_key,
                ),
                'timeout' => 20,
            )
        );

        if ( is_wp_error( $response ) ) {
            return new WP_Error(
                'hub_unreachable',
                __( 'Could not reach the Hub. Please try again later.', 'dsquared-hub-connector' )
            );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( 200 !== $code || empty( $body['success'] ) ) {
            return new WP_Error(
                'hub_error',
                $body['error'] ?? __( 'Hub returned an error.', 'dsquared-hub-connector' )
            );
        }

        return $body['profile'] ?? array();
    }

    /**
     * Apply Hub profile data to the local AI Discovery business profile
     *
     * Merges Hub data with existing local data (local values take priority
     * if they are non-empty, unless $force is true).
     *
     * @param array $hub_profile  Profile data from the Hub.
     * @param bool  $force        If true, overwrite existing local data.
     * @return array The merged profile.
     */
    public static function apply_profile( $hub_profile, $force = false ) {
        // Get existing local profile (check both option names for compatibility)
        $local_profile = get_option( 'dhc_business_profile', array() );
        if ( empty( $local_profile ) ) {
            $local_profile = get_option( 'dhc_ai_business_profile', array() );
        }

        $merged = self::merge_profile_data( $hub_profile, $local_profile, $force );

        // One canonical normalizer owns both option names and all legacy mirrors.
        if ( class_exists( 'DHC_AI_Discovery' ) ) $merged = DHC_AI_Discovery::save_business_profile_data( $merged, $force );
        else {
            update_option( 'dhc_business_profile', $merged );
            update_option( 'dhc_ai_business_profile', $merged );
        }

        // Rewrite the physical llms.txt / llms-full.txt right away so a sync
        // takes effect immediately, without a separate "Save & Generate
        // Files" step. Previously the option updated but the live files
        // stayed stale until the user clicked save.
        if ( class_exists( 'DHC_AI_Discovery' ) && method_exists( 'DHC_AI_Discovery', 'regenerate_files' ) ) {
            DHC_AI_Discovery::regenerate_files( $merged );
        }

        // Log the sync event
        DHC_Event_Logger::ai_discovery(
            'profile_synced_from_hub',
            array(
                'fields_populated' => count( array_filter( $merged, function( $v ) {
                    return ! self::is_empty_value( $v );
                } ) ),
                'source' => 'hub',
                'time'   => current_time( 'mysql' ),
            ),
            'AI Discovery profile synced from Hub'
        );

        return $merged;
    }

    /**
     * Auto-populate on first activation or module enable
     *
     * Called when the AI Discovery module is first enabled.
     * Fetches profile from Hub and pre-fills local options.
     *
     * @return bool True if profile was populated, false otherwise.
     */
    public static function auto_populate_on_enable() {
        // Check if we've already auto-populated
        $already_synced = get_option( 'dhc_hub_profile_synced', false );
        if ( $already_synced ) {
            return false;
        }

        // Check if there's already a populated profile
        $existing = get_option( 'dhc_business_profile', array() );
        if ( ! empty( $existing ) && ! empty( $existing['business_name'] ) ) {
            return false;
        }

        $hub_profile = self::fetch_profile_from_hub();
        if ( is_wp_error( $hub_profile ) || empty( $hub_profile ) ) {
            return false;
        }

        self::apply_profile( $hub_profile, false );

        // Mark as synced and set a transient for the admin notice
        update_option( 'dhc_hub_profile_synced', true );
        set_transient( 'dhc_show_sync_notice', true, 3600 ); // Show notice for 1 hour

        return true;
    }

    /**
     * AJAX: Sync profile from Hub (manual trigger)
     */
    public static function ajax_sync_from_hub() {
        check_ajax_referer( 'dhc_admin_nonce', 'nonce' );
        $permission = self::manual_sync_permission();
        if ( is_wp_error( $permission ) ) wp_send_json_error( array( 'message' => $permission->get_error_message() ), intval( $permission->get_error_data()['status'] ?? 403 ) );

        // A manual "Sync from Hub" means "pull the current Hub profile" — the
        // Hub is the source of truth, so default to overwrite. Without this it
        // merged and kept stale local values, so a resync couldn't fix a
        // profile that was already wrong. A manual sync is always authoritative.
        $force = true;

        $hub_profile = self::fetch_profile_from_hub();
        if ( is_wp_error( $hub_profile ) ) {
            wp_send_json_error( $hub_profile->get_error_message() );
        }

        if ( empty( $hub_profile ) ) {
            wp_send_json_error( __( 'No profile data available in the Hub. Complete your Content AI profile in the Hub first.', 'dsquared-hub-connector' ) );
        }

        $merged = self::apply_profile( $hub_profile, $force );

        $populated_count = count( array_filter( $merged, function( $v ) {
            return ! self::is_empty_value( $v );
        } ) );

        wp_send_json_success( array(
            'message' => sprintf(
                /* translators: %d: number of fields populated */
                __( 'Profile synced from Hub. %d fields were saved and the discovery files were regenerated.', 'dsquared-hub-connector' ),
                $populated_count
            ),
            'profile' => $merged,
        ) );
    }

    /** Manual sync is a gated AI Discovery write, not a generic admin action. */
    public static function manual_sync_permission() {
        if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'dhc_forbidden', __( 'Unauthorized', 'dsquared-hub-connector' ), array( 'status' => 403 ) );
        if ( ! DHC_API_Key::is_module_available( 'ai_discovery' ) ) return new WP_Error( 'dhc_ai_discovery_unavailable', __( 'AI Discovery is not available on the current subscription.', 'dsquared-hub-connector' ), array( 'status' => 403 ) );
        return true;
    }

    /**
     * AJAX: Dismiss the auto-populate notice
     */
    public static function ajax_dismiss_sync_notice() {
        check_ajax_referer( 'dhc_admin_nonce', 'nonce' );
        delete_transient( 'dhc_show_sync_notice' );
        wp_send_json_success();
    }

    /**
     * Check if a value is considered "empty" for merge purposes
     *
     * @param mixed $value The value to check.
     * @return bool True if empty.
     */
    private static function is_empty_value( $value ) {
        if ( is_null( $value ) ) return true;
        if ( is_string( $value ) && trim( $value ) === '' ) return true;
        if ( is_array( $value ) && empty( $value ) ) return true;
        return false;
    }
}
