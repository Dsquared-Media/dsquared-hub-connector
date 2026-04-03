<?php
/**
 * Module 6: Content Decay Alerts
 *
 * Scans published posts for staleness based on last modified date.
 * Reports content health data back to the Hub for the Content Health dashboard.
 * Runs on a daily WP-Cron schedule.
 *
 * @package Dsquared_Hub_Connector
 * @since   1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DHC_Content_Decay {

    private static $instance = null;

    /** Thresholds in days */
    const YELLOW_THRESHOLD = 180; // 6 months
    const RED_THRESHOLD    = 365; // 12 months

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        // Schedule daily scan
        add_action( 'init', array( $this, 'schedule_scan' ) );
        add_action( 'dhc_content_decay_scan', array( $this, 'run_scan' ) );

        // REST endpoints
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );

        // Admin column for post freshness
        add_filter( 'manage_posts_columns', array( $this, 'add_freshness_column' ) );
        add_action( 'manage_posts_custom_column', array( $this, 'render_freshness_column' ), 10, 2 );

        // Dashboard widget
        add_action( 'wp_dashboard_setup', array( $this, 'add_dashboard_widget' ) );
    }

    /* ─── Cron Schedule ─── */

    public function schedule_scan() {
        if ( ! wp_next_scheduled( 'dhc_content_decay_scan' ) ) {
            wp_schedule_event( time(), 'daily', 'dhc_content_decay_scan' );
        }
    }

    /* ─── Core Scan ─── */

    public function run_scan() {
        $results = $this->scan_all_posts();

        // Store results locally
        update_option( 'dhc_content_decay_results', array(
            'scanned_at' => current_time( 'mysql' ),
            'summary'    => $results['summary'],
            'stale'      => $results['stale'],
        ) );

        // Report to Hub
        $this->report_to_hub( $results );

        // Log activity
        $this->log_activity(
            sprintf(
                'Content decay scan: %d posts scanned, %d stale (6mo+), %d critical (12mo+)',
                $results['summary']['total'],
                $results['summary']['yellow'],
                $results['summary']['red']
            )
        );

        return $results;
    }

    private function scan_all_posts() {
        $now = time();
        $stale_posts = array();
        $summary = array(
            'total'   => 0,
            'fresh'   => 0,
            'yellow'  => 0,
            'red'     => 0,
        );

        $args = array(
            'post_type'      => array( 'post', 'page' ),
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        );

        $post_ids = get_posts( $args );
        $summary['total'] = count( $post_ids );

        foreach ( $post_ids as $post_id ) {
            $modified = get_post_modified_time( 'U', false, $post_id );
            $days_since = floor( ( $now - $modified ) / DAY_IN_SECONDS );

            if ( $days_since >= self::RED_THRESHOLD ) {
                $status = 'red';
                $summary['red']++;
            } elseif ( $days_since >= self::YELLOW_THRESHOLD ) {
                $status = 'yellow';
                $summary['yellow']++;
            } else {
                $summary['fresh']++;
                continue; // Don't include fresh posts in stale list
            }

            $post = get_post( $post_id );
            $stale_posts[] = array(
                'post_id'       => $post_id,
                'title'         => $post->post_title,
                'url'           => get_permalink( $post_id ),
                'post_type'     => $post->post_type,
                'last_modified' => date( 'm/d/Y', $modified ),
                'days_stale'    => $days_since,
                'status'        => $status,
                'word_count'    => str_word_count( wp_strip_all_tags( $post->post_content ) ),
            );
        }

        // Sort by staleness (most stale first)
        usort( $stale_posts, function( $a, $b ) {
            return $b['days_stale'] - $a['days_stale'];
        } );

        return array(
            'summary' => $summary,
            'stale'   => $stale_posts,
        );
    }

    /* ─── REST Routes ─── */

    public function register_routes() {
        register_rest_route( 'dsquared-hub/v1', '/content-decay', array(
            'methods'  => 'GET',
            'callback' => array( $this, 'get_decay_report' ),
            'permission_callback' => array( $this, 'check_api_key' ),
        ) );

        register_rest_route( 'dsquared-hub/v1', '/content-decay/scan', array(
            'methods'  => 'POST',
            'callback' => array( $this, 'trigger_scan' ),
            'permission_callback' => array( $this, 'check_api_key' ),
        ) );
    }

    public function check_api_key( $request ) {
        $key = $request->get_header( 'X-DHC-API-Key' );
        if ( ! $key ) return false;
        return DHC_API_Key::instance()->validate_local( $key );
    }

    public function get_decay_report( $request ) {
        $results = get_option( 'dhc_content_decay_results', null );

        if ( ! $results ) {
            return new WP_REST_Response( array(
                'success' => true,
                'message' => 'No scan results yet. Trigger a scan first.',
                'data'    => null,
            ), 200 );
        }

        return new WP_REST_Response( array(
            'success' => true,
            'data'    => $results,
        ), 200 );
    }

    public function trigger_scan( $request ) {
        $results = $this->run_scan();

        return new WP_REST_Response( array(
            'success' => true,
            'message' => 'Content decay scan completed.',
            'data'    => array(
                'scanned_at' => current_time( 'mysql' ),
                'summary'    => $results['summary'],
                'stale_count' => count( $results['stale'] ),
            ),
        ), 200 );
    }

    /* ─── Admin Column ─── */

    public function add_freshness_column( $columns ) {
        $columns['dhc_freshness'] = 'Freshness';
        return $columns;
    }

    public function render_freshness_column( $column, $post_id ) {
        if ( $column !== 'dhc_freshness' ) return;

        $modified = get_post_modified_time( 'U', false, $post_id );
        $days = floor( ( time() - $modified ) / DAY_IN_SECONDS );

        if ( $days >= self::RED_THRESHOLD ) {
            echo '<span style="color:#dc2626;font-weight:600;">&#9679; ' . $days . 'd ago</span>';
        } elseif ( $days >= self::YELLOW_THRESHOLD ) {
            echo '<span style="color:#d97706;font-weight:600;">&#9679; ' . $days . 'd ago</span>';
        } else {
            echo '<span style="color:#16a34a;">&#9679; ' . $days . 'd ago</span>';
        }
    }

    /* ─── Dashboard Widget ─── */

    public function add_dashboard_widget() {
        wp_add_dashboard_widget(
            'dhc_content_decay_widget',
            'Content Freshness — Dsquared Hub',
            array( $this, 'render_dashboard_widget' )
        );
    }

    public function render_dashboard_widget() {
        $results = get_option( 'dhc_content_decay_results', null );

        if ( ! $results ) {
            echo '<p style="color:#64748b;">No scan data yet. The first scan will run automatically within 24 hours.</p>';
            return;
        }

        $s = $results['summary'];
        $scanned = $results['scanned_at'] ?? 'Unknown';

        echo '<div style="font-family:\'Plus Jakarta Sans\',sans-serif;">';
        echo '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:16px;">';

        // Fresh
        echo '<div style="text-align:center;padding:12px;background:#f0fdf4;border-radius:8px;border:1px solid #bbf7d0;">';
        echo '<div style="font-size:24px;font-weight:800;color:#16a34a;">' . $s['fresh'] . '</div>';
        echo '<div style="font-size:11px;color:#64748b;">Fresh</div>';
        echo '</div>';

        // Yellow
        echo '<div style="text-align:center;padding:12px;background:#fffbeb;border-radius:8px;border:1px solid #fde68a;">';
        echo '<div style="font-size:24px;font-weight:800;color:#d97706;">' . $s['yellow'] . '</div>';
        echo '<div style="font-size:11px;color:#64748b;">6+ Months</div>';
        echo '</div>';

        // Red
        echo '<div style="text-align:center;padding:12px;background:#fef2f2;border-radius:8px;border:1px solid #fecaca;">';
        echo '<div style="font-size:24px;font-weight:800;color:#dc2626;">' . $s['red'] . '</div>';
        echo '<div style="font-size:11px;color:#64748b;">12+ Months</div>';
        echo '</div>';

        echo '</div>';

        // Top 5 stalest
        if ( ! empty( $results['stale'] ) ) {
            echo '<div style="font-size:12px;font-weight:700;color:#1a1f36;margin-bottom:8px;">Most Stale Content:</div>';
            $top = array_slice( $results['stale'], 0, 5 );
            foreach ( $top as $post ) {
                $color = $post['status'] === 'red' ? '#dc2626' : '#d97706';
                echo '<div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #f1f3f9;font-size:12px;">';
                echo '<a href="' . esc_url( get_edit_post_link( $post['post_id'] ) ) . '" style="color:#1a1f36;text-decoration:none;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' . esc_html( $post['title'] ) . '</a>';
                echo '<span style="color:' . $color . ';font-weight:600;margin-left:8px;white-space:nowrap;">' . $post['days_stale'] . 'd</span>';
                echo '</div>';
            }
        }

        echo '<div style="font-size:11px;color:#94a3b8;margin-top:12px;">Last scan: ' . esc_html( $scanned ) . '</div>';
        echo '</div>';
    }

    /* ─── Hub Reporting ─── */

    private function report_to_hub( $results ) {
        $api_key = get_option( 'dhc_api_key' );
        $sub     = get_option( 'dhc_subscription', array() );
        $hub_url = $sub['hub_url'] ?? 'https://hub.dsquaredmedia.net';

        if ( ! $api_key ) return;

        wp_remote_post( $hub_url . '/api/plugin/event', array(
            'body'    => wp_json_encode( array(
                'event' => 'content_decay_scan',
                'site'  => home_url( '/' ),
                'data'  => array(
                    'summary' => $results['summary'],
                    'stale'   => array_slice( $results['stale'], 0, 50 ), // Top 50
                    'scanned_at' => current_time( 'mysql' ),
                ),
            ) ),
            'headers' => array(
                'Content-Type'  => 'application/json',
                'X-DHC-API-Key' => $api_key,
            ),
            'timeout'  => 15,
            'blocking' => false,
        ) );
    }

    /* ─── Activity Logging ─── */

    private function log_activity( $message ) {
        $log = get_option( 'dhc_activity_log', array() );
        array_unshift( $log, array(
            'message' => $message,
            'module'  => 'content-decay',
            'time'    => current_time( 'mysql' ),
        ) );
        $log = array_slice( $log, 0, 200 );
        update_option( 'dhc_activity_log', $log );
    }

    /* ─── Cleanup on deactivation ─── */

    public static function deactivate() {
        wp_clear_scheduled_hook( 'dhc_content_decay_scan' );
    }
}
