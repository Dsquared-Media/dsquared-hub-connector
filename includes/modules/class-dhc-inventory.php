<?php
/**
 * DHC_Inventory — Module: Daily site snapshot
 *
 * Once a day, pushes a snapshot of the WP site to the Hub so the
 * dashboard can surface "what changed on your site this week,"
 * diagnose plugin conflicts when things break, and alert on
 * caching-plugin-turned-off / theme-swapped / WP-version-stale.
 *
 * Snapshot contents:
 *   - Post / page / attachment / comment counts (published)
 *   - Last 10 posts + pages published in the last 14 days
 *   - Active theme name + version
 *   - Active + recently-deactivated plugins with versions
 *   - WP + PHP + MySQL versions
 *   - Memory limit + disk free (best-effort)
 *   - Cache plugin detected (WP Rocket / W3TC / LiteSpeed / etc.)
 *   - Multisite / HTTPS / debug mode flags
 *
 * Cron: wp_schedule_event daily at install, tied to wp_options cron
 * so WP reliably fires it once a day. Uses the hub's heartbeat
 * endpoint — no new Hub route needed, just a richer payload.
 *
 * @package Dsquared_Hub_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DHC_Inventory {

    const CRON_HOOK = 'dhc_inventory_cron';
    const OPTION_LAST = 'dhc_inventory_last_pushed';

    public static function init() {
        add_action( self::CRON_HOOK, array( __CLASS__, 'push_to_hub' ) );
    }

    /**
     * Schedule the daily cron — called from the plugin's activation hook
     * and on every admin_init as a self-heal (in case the cron was
     * cleared by another plugin or a WP migration).
     */
    public static function schedule() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + 3600, 'daily', self::CRON_HOOK );
        }
    }

    public static function unschedule() {
        $ts = wp_next_scheduled( self::CRON_HOOK );
        if ( $ts ) wp_unschedule_event( $ts, self::CRON_HOOK );
    }

    /**
     * Build the snapshot payload. Public so the admin page can render
     * a preview + manual-push button for debugging.
     */
    public static function build_snapshot() {
        global $wp_version;

        // Counts by post type
        $counts = array(
            'post'       => (int) wp_count_posts( 'post' )->publish,
            'page'       => (int) wp_count_posts( 'page' )->publish,
            'attachment' => (int) wp_count_posts( 'attachment' )->inherit,
        );
        $counts['comments'] = (int) ( wp_count_comments()->approved ?? 0 );

        // Recent publishes (last 14 days)
        $recent = get_posts( array(
            'post_type'      => array( 'post', 'page' ),
            'post_status'    => 'publish',
            'posts_per_page' => 10,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'date_query'     => array( array( 'after' => '14 days ago' ) ),
            'fields'         => 'ids',
        ) );
        $recent_items = array();
        foreach ( $recent as $rid ) {
            $p = get_post( $rid );
            if ( ! $p ) continue;
            $recent_items[] = array(
                'id'        => $p->ID,
                'type'      => $p->post_type,
                'title'     => $p->post_title,
                'url'       => get_permalink( $p ),
                'published' => $p->post_date_gmt,
                'modified'  => $p->post_modified_gmt,
            );
        }

        // Active theme
        $theme = wp_get_theme();
        $theme_info = array(
            'name'         => $theme->get( 'Name' ),
            'version'      => $theme->get( 'Version' ),
            'template'     => $theme->get_template(),
            'stylesheet'   => $theme->get_stylesheet(),
            'parent_theme' => $theme->parent() ? $theme->parent()->get( 'Name' ) : null,
        );

        // Active plugins (skip mu-plugins and dropins — too noisy)
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all_plugins    = get_plugins();
        $active_plugins = (array) get_option( 'active_plugins', array() );
        $plugins = array();
        foreach ( $active_plugins as $path ) {
            if ( empty( $all_plugins[ $path ] ) ) continue;
            $p = $all_plugins[ $path ];
            $plugins[] = array(
                'slug'        => $path,
                'name'        => $p['Name'] ?? $path,
                'version'     => $p['Version'] ?? '',
                'author'      => wp_strip_all_tags( $p['Author'] ?? '' ),
                'requires_wp' => $p['RequiresWP'] ?? '',
                'requires_php'=> $p['RequiresPHP'] ?? '',
            );
        }

        // Cache plugin detection — looks at common class/function signatures
        $cache_detected = null;
        if ( defined( 'WP_ROCKET_VERSION' ) )           $cache_detected = 'WP Rocket';
        elseif ( defined( 'W3TC' ) )                    $cache_detected = 'W3 Total Cache';
        elseif ( defined( 'WPFC_MAIN_PATH' ) )          $cache_detected = 'WP Fastest Cache';
        elseif ( defined( 'LSCWP_V' ) )                 $cache_detected = 'LiteSpeed Cache';
        elseif ( class_exists( 'WpeCommon' ) )          $cache_detected = 'WP Engine (built-in)';
        elseif ( class_exists( 'autoptimizeMain' ) )    $cache_detected = 'Autoptimize';
        elseif ( defined( 'WP_CACHE' ) && WP_CACHE )    $cache_detected = 'Generic (WP_CACHE on)';

        // Disk free — only reliable on *nix, returns null on hosted PaaS
        $disk_free_mb = null;
        try {
            if ( function_exists( 'disk_free_space' ) ) {
                $b = @disk_free_space( ABSPATH );
                if ( is_numeric( $b ) ) $disk_free_mb = (int) round( $b / 1048576 );
            }
        } catch ( \Throwable $e ) { /* ignore */ }

        return array(
            'site_url'         => get_site_url(),
            'site_name'        => get_bloginfo( 'name' ),
            'wp_version'       => $wp_version,
            'php_version'      => phpversion(),
            'mysql_version'    => function_exists( 'mysql_get_server_info' )
                                    ? @mysql_get_server_info()
                                    : ( $GLOBALS['wpdb'] ?? null ) ? $GLOBALS['wpdb']->db_version() : null,
            'is_multisite'     => is_multisite(),
            'is_ssl'           => is_ssl(),
            'is_debug'         => defined( 'WP_DEBUG' ) && WP_DEBUG,
            'memory_limit'     => ini_get( 'memory_limit' ),
            'max_execution'    => (int) ini_get( 'max_execution_time' ),
            'upload_max'       => ini_get( 'upload_max_filesize' ),
            'disk_free_mb'     => $disk_free_mb,
            'cache_plugin'     => $cache_detected,
            'counts'           => $counts,
            'active_theme'     => $theme_info,
            'active_plugins'   => $plugins,
            'recent_publishes' => $recent_items,
            'snapshot_at'      => gmdate( 'c' ),
            'plugin_version'   => defined( 'DHC_VERSION' ) ? DHC_VERSION : 'unknown',
        );
    }

    /**
     * POST the snapshot to the Hub's /api/plugin/inventory endpoint.
     * Uses the plugin API key as the bearer. Hub-side fallback: logs
     * and continues — a failed inventory push never blocks the cron.
     */
    public static function push_to_hub() {
        $api_key = get_option( 'dhc_api_key', '' );
        if ( empty( $api_key ) ) return;

        $payload = self::build_snapshot();
        $hub_url = defined( 'DHC_HUB_API_BASE' ) ? DHC_HUB_API_BASE : 'https://hub.dsquaredmedia.net/api';
        $url     = rtrim( $hub_url, '/' ) . '/plugin/inventory';

        $res = wp_remote_post( $url, array(
            'timeout'   => 15,
            'headers'   => array(
                'Content-Type'    => 'application/json',
                'X-DHC-API-Key'   => $api_key,
            ),
            'body'      => wp_json_encode( $payload ),
            'sslverify' => true,
        ) );

        if ( is_wp_error( $res ) ) {
            DHC_Event_Logger::log( 'inventory_push_failed', array( 'error' => $res->get_error_message() ) );
            return;
        }

        $code = wp_remote_retrieve_response_code( $res );
        update_option( self::OPTION_LAST, array(
            'at'            => current_time( 'mysql' ),
            'status_code'   => $code,
            'plugins_count' => count( $payload['active_plugins'] ),
            'posts_count'   => $payload['counts']['post'] ?? 0,
        ) );

        if ( $code >= 200 && $code < 300 ) {
            DHC_Event_Logger::log( 'inventory_push', array(
                'plugins'  => count( $payload['active_plugins'] ),
                'posts'    => $payload['counts']['post'] ?? 0,
                'wp'       => $payload['wp_version'],
                'php'      => $payload['php_version'],
            ) );
        } else {
            DHC_Event_Logger::log( 'inventory_push_bad_status', array( 'status_code' => $code ) );
        }
    }

    /** Manual trigger from the admin page */
    public static function push_now() { self::push_to_hub(); }
}
