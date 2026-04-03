<?php
/**
 * DHC_Updater — Self-hosted plugin update checker
 *
 * Checks hub.dsquaredmedia.net for new plugin versions and integrates
 * with WordPress's native update system. Users see update notifications
 * in the admin just like any other plugin.
 *
 * @package Dsquared_Hub_Connector
 * @since   1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DHC_Updater {

    /** @var string Remote endpoint for update checks */
    const UPDATE_URL = 'https://hub.dsquaredmedia.net/api/plugin/update-check';

    /** @var string Transient key for caching update data */
    const CACHE_KEY = 'dhc_update_cache';

    /** @var int Cache duration: 12 hours */
    const CACHE_DURATION = 43200;

    /**
     * Initialize update hooks
     */
    public static function init() {
        add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'check_for_update' ) );
        add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 20, 3 );
        add_action( 'upgrader_process_complete', array( __CLASS__, 'clear_cache' ), 10, 2 );
        add_filter( 'plugin_row_meta', array( __CLASS__, 'plugin_row_meta' ), 10, 2 );
    }

    /**
     * Check the remote server for a new version
     *
     * @param object $transient WordPress update transient.
     * @return object Modified transient.
     */
    public static function check_for_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $remote = self::get_remote_data();

        if ( ! $remote || empty( $remote['version'] ) ) {
            return $transient;
        }

        $current_version = DHC_VERSION;

        if ( version_compare( $remote['version'], $current_version, '>' ) ) {
            $transient->response[ DHC_PLUGIN_BASENAME ] = (object) array(
                'slug'        => 'dsquared-hub-connector',
                'plugin'      => DHC_PLUGIN_BASENAME,
                'new_version' => $remote['version'],
                'url'         => $remote['homepage'] ?? 'https://hub.dsquaredmedia.net',
                'package'     => $remote['download_url'] ?? '',
                'icons'       => array(
                    '1x' => $remote['icon_url'] ?? '',
                ),
                'banners'     => array(
                    'low'  => $remote['banner_url'] ?? '',
                    'high' => $remote['banner_url_2x'] ?? '',
                ),
                'tested'      => $remote['tested_wp'] ?? '',
                'requires'    => $remote['requires_wp'] ?? '5.8',
                'requires_php' => $remote['requires_php'] ?? '7.4',
            );
        } else {
            // No update available — still report to WordPress
            $transient->no_update[ DHC_PLUGIN_BASENAME ] = (object) array(
                'slug'        => 'dsquared-hub-connector',
                'plugin'      => DHC_PLUGIN_BASENAME,
                'new_version' => $current_version,
                'url'         => 'https://hub.dsquaredmedia.net',
            );
        }

        return $transient;
    }

    /**
     * Provide plugin information for the "View Details" modal
     *
     * @param false|object|array $result
     * @param string             $action
     * @param object             $args
     * @return false|object
     */
    public static function plugin_info( $result, $action, $args ) {
        if ( 'plugin_information' !== $action ) {
            return $result;
        }

        if ( ! isset( $args->slug ) || 'dsquared-hub-connector' !== $args->slug ) {
            return $result;
        }

        $remote = self::get_remote_data();

        if ( ! $remote ) {
            return $result;
        }

        return (object) array(
            'name'            => 'Dsquared Hub Connector',
            'slug'            => 'dsquared-hub-connector',
            'version'         => $remote['version'] ?? DHC_VERSION,
            'author'          => '<a href="https://dsquaredmedia.net">Dsquared Media</a>',
            'author_profile'  => 'https://dsquaredmedia.net',
            'homepage'        => 'https://hub.dsquaredmedia.net',
            'requires'        => $remote['requires_wp'] ?? '5.8',
            'tested'          => $remote['tested_wp'] ?? '',
            'requires_php'    => $remote['requires_php'] ?? '7.4',
            'downloaded'      => $remote['download_count'] ?? 0,
            'last_updated'    => $remote['last_updated'] ?? '',
            'sections'        => array(
                'description'  => $remote['description'] ?? 'Connect your WordPress site to Dsquared Media Hub.',
                'installation' => $remote['installation'] ?? 'Upload the plugin ZIP via Plugins → Add New → Upload, then enter your API key.',
                'changelog'    => $remote['changelog'] ?? '',
                'faq'          => $remote['faq'] ?? '',
            ),
            'download_link'   => $remote['download_url'] ?? '',
            'banners'         => array(
                'low'  => $remote['banner_url'] ?? '',
                'high' => $remote['banner_url_2x'] ?? '',
            ),
        );
    }

    /**
     * Fetch remote update data (cached)
     *
     * @return array|null
     */
    private static function get_remote_data() {
        $cached = get_transient( self::CACHE_KEY );
        if ( false !== $cached ) {
            return $cached;
        }

        $api_key = get_option( 'dhc_api_key', '' );

        $response = wp_remote_get( self::UPDATE_URL, array(
            'headers' => array(
                'X-DHC-API-Key'     => $api_key,
                'X-DHC-Version'     => DHC_VERSION,
                'X-DHC-Site'        => home_url( '/' ),
                'X-DHC-WP-Version'  => get_bloginfo( 'version' ),
                'X-DHC-PHP-Version' => phpversion(),
            ),
            'timeout' => 15,
        ) );

        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            // Cache a failure for 1 hour to avoid hammering
            set_transient( self::CACHE_KEY, array(), 3600 );
            return null;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! is_array( $body ) || empty( $body['version'] ) ) {
            set_transient( self::CACHE_KEY, array(), 3600 );
            return null;
        }

        set_transient( self::CACHE_KEY, $body, self::CACHE_DURATION );

        return $body;
    }

    /**
     * Clear update cache after an upgrade
     *
     * @param WP_Upgrader $upgrader
     * @param array       $options
     */
    public static function clear_cache( $upgrader = null, $options = array() ) {
        if ( ! empty( $options['plugins'] ) && in_array( DHC_PLUGIN_BASENAME, $options['plugins'], true ) ) {
            delete_transient( self::CACHE_KEY );
        }
    }

    /**
     * Add "Check for updates" link to plugin row
     *
     * @param array  $links
     * @param string $file
     * @return array
     */
    public static function plugin_row_meta( $links, $file ) {
        if ( DHC_PLUGIN_BASENAME === $file ) {
            $links[] = '<a href="' . esc_url( admin_url( 'update-core.php' ) ) . '">' .
                       esc_html__( 'Check for updates', 'dsquared-hub-connector' ) . '</a>';
        }
        return $links;
    }
}
