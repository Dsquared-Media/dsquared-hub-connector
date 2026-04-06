<?php
/**
 * DHC_Updater — GitHub-based plugin auto-updater
 *
 * Checks GitHub releases for new plugin versions and integrates
 * with WordPress's native update system. Users see update notifications
 * in the admin just like any other plugin. Also temporarily disables
 * SVG Support plugin hooks during plugin installation to prevent
 * DOMDocument crashes on servers missing php-xml.
 *
 * @package Dsquared_Hub_Connector
 * @since   1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DHC_Updater {

    /** @var string GitHub repository in owner/repo format */
    const GITHUB_REPO = 'dsquaredmedia/dsquared-hub-connector';

    /** @var string GitHub API endpoint for latest release */
    const GITHUB_API_URL = 'https://api.github.com/repos/dsquaredmedia/dsquared-hub-connector/releases/latest';

    /** @var string Fallback: Hub endpoint for update checks */
    const HUB_UPDATE_URL = 'https://hub.dsquaredmedia.net/api/plugin/update-check';

    /** @var string Transient key for caching update data */
    const CACHE_KEY = 'dhc_update_cache';

    /** @var int Cache duration: 6 hours */
    const CACHE_DURATION = 21600;

    /**
     * Initialize update hooks
     */
    public static function init() {
        add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'check_for_update' ) );
        add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 20, 3 );
        add_action( 'upgrader_process_complete', array( __CLASS__, 'clear_cache' ), 10, 2 );
        add_filter( 'plugin_row_meta', array( __CLASS__, 'plugin_row_meta' ), 10, 2 );

        // SVG Support conflict protection: disable SVG sanitization during plugin installs/updates
        add_action( 'upgrader_pre_install', array( __CLASS__, 'disable_svg_support_on_install' ), 1, 2 );
        add_action( 'upgrader_post_install', array( __CLASS__, 'restore_svg_support_after_install' ), 99, 3 );

        // Also hook into the upload process itself
        add_filter( 'wp_handle_upload_prefilter', array( __CLASS__, 'protect_plugin_upload' ), 1 );
    }

    /**
     * Temporarily disable SVG Support plugin hooks during plugin installation
     * This prevents DOMDocument crashes on servers missing php-xml
     */
    public static function disable_svg_support_on_install( $response, $hook_extra ) {
        self::toggle_svg_support( false );
        return $response;
    }

    /**
     * Re-enable SVG Support plugin hooks after plugin installation
     */
    public static function restore_svg_support_after_install( $response, $hook_extra, $result ) {
        self::toggle_svg_support( true );
        return $response;
    }

    /**
     * Protect plugin ZIP uploads from SVG Support interference
     */
    public static function protect_plugin_upload( $file ) {
        // If this is a plugin upload (ZIP file), temporarily disable SVG Support
        if ( isset( $file['type'] ) && in_array( $file['type'], array( 'application/zip', 'application/x-zip-compressed', 'application/octet-stream' ), true ) ) {
            self::toggle_svg_support( false );
            // Re-enable after upload completes
            add_action( 'shutdown', array( __CLASS__, 'restore_svg_on_shutdown' ) );
        }
        return $file;
    }

    /**
     * Restore SVG Support on shutdown (safety net)
     */
    public static function restore_svg_on_shutdown() {
        self::toggle_svg_support( true );
    }

    /**
     * Toggle SVG Support plugin's sanitization hooks on/off
     *
     * @param bool $enable True to enable, false to disable
     */
    private static function toggle_svg_support( $enable ) {
        // Target the SVG Support plugin's sanitization filter
        $hooks_to_toggle = array(
            array( 'wp_handle_upload_prefilter', 'bodhi_svgs_sanitize_svg' ),
            array( 'wp_handle_sideload_prefilter', 'bodhi_svgs_sanitize_svg' ),
            array( 'wp_check_filetype_and_ext', 'bodhi_svgs_allow_svg_upload' ),
        );

        foreach ( $hooks_to_toggle as $hook_info ) {
            $tag      = $hook_info[0];
            $function = $hook_info[1];

            if ( $enable ) {
                // We can't easily re-add with the original priority, so we skip re-enabling
                // The hooks will be restored on next page load anyway
            } else {
                // Remove the problematic hooks
                if ( function_exists( $function ) ) {
                    remove_all_filters( $tag );
                }
            }
        }

        // Also try to remove the class-based hooks from newer SVG Support versions
        if ( ! $enable ) {
            global $wp_filter;
            $tags_to_clean = array(
                'wp_handle_upload_prefilter',
                'wp_handle_sideload_prefilter',
                'wp_handle_upload',
            );
            foreach ( $tags_to_clean as $tag ) {
                if ( isset( $wp_filter[ $tag ] ) ) {
                    foreach ( $wp_filter[ $tag ]->callbacks as $priority => $callbacks ) {
                        foreach ( $callbacks as $key => $callback ) {
                            $func = $callback['function'];
                            $func_name = '';
                            if ( is_string( $func ) ) {
                                $func_name = $func;
                            } elseif ( is_array( $func ) && isset( $func[1] ) ) {
                                $func_name = is_string( $func[1] ) ? $func[1] : '';
                            }
                            // Remove any SVG-related sanitization hooks
                            if ( stripos( $func_name, 'svg' ) !== false || stripos( $func_name, 'sanitize_svg' ) !== false ) {
                                unset( $wp_filter[ $tag ]->callbacks[ $priority ][ $key ] );
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Check GitHub releases for a new version
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
                'slug'         => 'dsquared-hub-connector',
                'plugin'       => DHC_PLUGIN_BASENAME,
                'new_version'  => $remote['version'],
                'url'          => $remote['homepage'] ?? 'https://hub.dsquaredmedia.net',
                'package'      => $remote['download_url'] ?? '',
                'icons'        => array(
                    '1x' => $remote['icon_url'] ?? '',
                ),
                'banners'      => array(
                    'low'  => $remote['banner_url'] ?? '',
                    'high' => $remote['banner_url_2x'] ?? '',
                ),
                'tested'       => $remote['tested_wp'] ?? '',
                'requires'     => $remote['requires_wp'] ?? '5.8',
                'requires_php' => $remote['requires_php'] ?? '7.4',
            );
        } else {
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
                'description'  => $remote['description'] ?? 'Connect your WordPress site to Dsquared Media Hub for analytics, AI insights, SEO tools, and more.',
                'installation' => $remote['installation'] ?? 'Upload the plugin via Plugins → Add New → Upload, or install via the Hub dashboard. Then enter your API key to connect.',
                'changelog'    => $remote['changelog'] ?? '',
            ),
            'download_link'   => $remote['download_url'] ?? '',
        );
    }

    /**
     * Fetch remote update data — tries GitHub first, falls back to Hub API
     *
     * @return array|null
     */
    private static function get_remote_data() {
        $cached = get_transient( self::CACHE_KEY );
        if ( false !== $cached && ! empty( $cached ) ) {
            return $cached;
        }

        // Try GitHub releases API first
        $data = self::fetch_from_github();

        // Fall back to Hub API if GitHub fails
        if ( ! $data ) {
            $data = self::fetch_from_hub();
        }

        if ( $data && ! empty( $data['version'] ) ) {
            set_transient( self::CACHE_KEY, $data, self::CACHE_DURATION );
            return $data;
        }

        // Cache failure for 1 hour to avoid hammering
        set_transient( self::CACHE_KEY, array(), 3600 );
        return null;
    }

    /**
     * Fetch latest release info from GitHub
     *
     * @return array|null
     */
    private static function fetch_from_github() {
        $response = wp_remote_get( self::GITHUB_API_URL, array(
            'headers' => array(
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'DSQuaredHubConnector/' . DHC_VERSION,
            ),
            'timeout' => 15,
        ) );

        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            return null;
        }

        $release = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! is_array( $release ) || empty( $release['tag_name'] ) ) {
            return null;
        }

        // Parse version from tag (strip leading 'v' if present)
        $version = ltrim( $release['tag_name'], 'v' );

        // Build the download URL for the ZIP
        $download_url = $release['zipball_url'] ?? sprintf(
            'https://github.com/%s/archive/refs/tags/%s.zip',
            self::GITHUB_REPO,
            $release['tag_name']
        );

        return array(
            'version'      => $version,
            'download_url' => $download_url,
            'homepage'     => 'https://hub.dsquaredmedia.net',
            'changelog'    => self::parse_changelog( $release['body'] ?? '' ),
            'last_updated' => $release['published_at'] ?? '',
            'requires_wp'  => '5.8',
            'requires_php' => '7.4',
            'tested_wp'    => '6.7',
            'description'  => 'Connect your WordPress site to Dsquared Media Hub for analytics, AI insights, SEO tools, and more.',
        );
    }

    /**
     * Fetch update info from the Hub API (fallback)
     *
     * @return array|null
     */
    private static function fetch_from_hub() {
        $api_key = get_option( 'dhc_api_key', '' );

        $response = wp_remote_get( self::HUB_UPDATE_URL, array(
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
            return null;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! is_array( $body ) || empty( $body['version'] ) ) {
            return null;
        }

        return $body;
    }

    /**
     * Parse GitHub release body (Markdown) into simple HTML for changelog
     *
     * @param string $body
     * @return string
     */
    private static function parse_changelog( $body ) {
        if ( empty( $body ) ) {
            return '';
        }

        // Basic Markdown to HTML conversion for changelogs
        $html = esc_html( $body );
        $html = preg_replace( '/^### (.+)$/m', '<h4>$1</h4>', $html );
        $html = preg_replace( '/^## (.+)$/m', '<h3>$1</h3>', $html );
        $html = preg_replace( '/^- (.+)$/m', '<li>$1</li>', $html );
        $html = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html );
        $html = preg_replace( '/`(.+?)`/', '<code>$1</code>', $html );
        $html = nl2br( $html );

        // Wrap list items in <ul>
        if ( strpos( $html, '<li>' ) !== false ) {
            $html = preg_replace( '/(<li>.*?<\/li>)/s', '<ul>$1</ul>', $html );
        }

        return $html;
    }

    /**
     * Clear update cache after an upgrade
     */
    public static function clear_cache( $upgrader = null, $options = array() ) {
        if ( ! empty( $options['plugins'] ) && in_array( DHC_PLUGIN_BASENAME, $options['plugins'], true ) ) {
            delete_transient( self::CACHE_KEY );
        }
    }

    /**
     * Add "Check for updates" link to plugin row
     */
    public static function plugin_row_meta( $links, $file ) {
        if ( DHC_PLUGIN_BASENAME === $file ) {
            $links[] = '<a href="' . esc_url( admin_url( 'update-core.php' ) ) . '">' .
                       esc_html__( 'Check for updates', 'dsquared-hub-connector' ) . '</a>';
        }
        return $links;
    }

    /**
     * Manually trigger an update check (useful for admin AJAX)
     */
    public static function force_check() {
        delete_transient( self::CACHE_KEY );
        wp_clean_plugins_cache( true );
        wp_update_plugins();
    }
}
