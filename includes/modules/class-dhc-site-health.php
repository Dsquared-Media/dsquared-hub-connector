<?php
/**
 * DHC_Site_Health — Module 4: Site Health Monitor
 *
 * Injects a lightweight Core Web Vitals reporting script on the frontend
 * that collects LCP, FID, CLS, TTFB, and INP metrics from real users
 * and reports them back to both the local WP REST endpoint and the Hub.
 *
 * @package Dsquared_Hub_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DHC_Site_Health {

    const METRICS_OPTION = 'dhc_cwv_metrics';
    const MAX_ENTRIES    = 500;

    /**
     * Initialize — enqueue frontend CWV script
     */
    public static function init() {
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_cwv_script' ) );
    }

    /**
     * Enqueue the lightweight CWV reporting script
     */
    public static function enqueue_cwv_script() {
        // Don't track admin users or logged-in editors
        if ( current_user_can( 'edit_posts' ) ) {
            return;
        }

        // Don't track in customizer preview
        if ( is_customize_preview() ) {
            return;
        }

        wp_enqueue_script(
            'dhc-site-health',
            DHC_PLUGIN_URL . 'assets/dhc-site-health.js',
            array(),
            DHC_VERSION,
            array(
                'strategy' => 'defer',
                'in_footer' => true,
            )
        );

        // Pass config to the script.
        // SECURITY: The API key is intentionally excluded here. The script
        // reports metrics to the local WP REST endpoint (/health), which is
        // unauthenticated and safe for browser beacons. That endpoint then
        // forwards to the Hub server-side using the stored key — the key
        // never appears in public page HTML.
        wp_localize_script( 'dhc-site-health', 'dhcHealthConfig', array(
            'endpoint'   => rest_url( 'dsquared-hub/v1/health' ),
            'siteUrl'    => get_site_url(),
            'nonce'      => wp_create_nonce( 'wp_rest' ),
            'sampleRate' => 100, // Report 100% of page loads (adjust for high-traffic sites)
        ) );
    }

    /**
     * Handle incoming CWV data from the frontend script
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function handle_request( $request ) {
        $metrics    = $request->get_param( 'metrics' );
        $url        = $request->get_param( 'url' );
        $user_agent = $request->get_param( 'user_agent' ) ?? '';

        if ( empty( $metrics ) || empty( $url ) ) {
            return new WP_REST_Response( array( 'success' => false ), 400 );
        }

        // Sanitize metrics
        $clean_metrics = array(
            'lcp'   => isset( $metrics['lcp'] )  ? floatval( $metrics['lcp'] )  : null,
            'fid'   => isset( $metrics['fid'] )  ? floatval( $metrics['fid'] )  : null,
            'cls'   => isset( $metrics['cls'] )  ? floatval( $metrics['cls'] )  : null,
            'ttfb'  => isset( $metrics['ttfb'] ) ? floatval( $metrics['ttfb'] ) : null,
            'inp'   => isset( $metrics['inp'] )  ? floatval( $metrics['inp'] )  : null,
            'fcp'   => isset( $metrics['fcp'] )  ? floatval( $metrics['fcp'] )  : null,
        );

        // Determine device type from user agent
        $device = 'desktop';
        if ( preg_match( '/Mobile|Android|iPhone|iPad/i', $user_agent ) ) {
            $device = 'mobile';
        } elseif ( preg_match( '/Tablet|iPad/i', $user_agent ) ) {
            $device = 'tablet';
        }

        // Store locally
        $entry = array(
            'url'        => esc_url_raw( $url ),
            'metrics'    => $clean_metrics,
            'device'     => $device,
            'timestamp'  => current_time( 'mysql' ),
            'date'       => current_time( 'Y-m-d' ),
        );

        self::store_metric( $entry );

        // Forward to Hub API (async — don't block the response)
        $api_key = get_option( 'dhc_api_key', '' );
        if ( ! empty( $api_key ) ) {
            wp_remote_post(
                DHC_HUB_API_BASE . '/plugin/cwv-report',
                array(
                    'headers'  => array(
                        'X-DHC-API-Key' => $api_key,
                        'Content-Type'  => 'application/json',
                    ),
                    'body'     => wp_json_encode( array(
                        'site_url' => get_site_url(),
                        'url'      => $url,
                        'metrics'  => $clean_metrics,
                        'device'   => $device,
                    ) ),
                    'timeout'  => 5,
                    'blocking' => false,
                )
            );
        }

        return new WP_REST_Response( array( 'success' => true ), 200 );
    }

    /**
     * Store a CWV metric entry
     *
     * @param array $entry Metric data.
     */
    private static function store_metric( $entry ) {
        $metrics = get_option( self::METRICS_OPTION, array() );

        // Trim to max entries
        if ( count( $metrics ) >= self::MAX_ENTRIES ) {
            $metrics = array_slice( $metrics, -( self::MAX_ENTRIES - 1 ) );
        }

        $metrics[] = $entry;
        update_option( self::METRICS_OPTION, $metrics, false ); // Don't autoload
    }

    /**
     * Get aggregated metrics for the admin dashboard
     *
     * @param int    $days   Number of days to look back.
     * @param string $device Filter by device type (all, mobile, desktop).
     * @return array Aggregated metrics.
     */
    public static function get_aggregated_metrics( $days = 30, $device = 'all' ) {
        $metrics = get_option( self::METRICS_OPTION, array() );
        $cutoff  = date( 'Y-m-d', strtotime( "-{$days} days" ) );

        $filtered = array_filter( $metrics, function( $m ) use ( $cutoff, $device ) {
            if ( $m['date'] < $cutoff ) return false;
            if ( 'all' !== $device && $m['device'] !== $device ) return false;
            return true;
        } );

        if ( empty( $filtered ) ) {
            return array(
                'count'   => 0,
                'lcp_p75' => null,
                'fid_p75' => null,
                'cls_p75' => null,
                'ttfb_p75' => null,
                'inp_p75' => null,
            );
        }

        return array(
            'count'    => count( $filtered ),
            'lcp_p75'  => self::percentile( array_column( array_column( $filtered, 'metrics' ), 'lcp' ), 75 ),
            'fid_p75'  => self::percentile( array_column( array_column( $filtered, 'metrics' ), 'fid' ), 75 ),
            'cls_p75'  => self::percentile( array_column( array_column( $filtered, 'metrics' ), 'cls' ), 75 ),
            'ttfb_p75' => self::percentile( array_column( array_column( $filtered, 'metrics' ), 'ttfb' ), 75 ),
            'inp_p75'  => self::percentile( array_column( array_column( $filtered, 'metrics' ), 'inp' ), 75 ),
        );
    }

    /**
     * Calculate percentile from an array of values
     *
     * @param array $values    Numeric values.
     * @param int   $percentile Percentile to calculate (e.g., 75).
     * @return float|null
     */
    private static function percentile( $values, $percentile ) {
        $values = array_filter( $values, function( $v ) { return $v !== null; } );
        if ( empty( $values ) ) return null;

        sort( $values );
        $count = count( $values );
        $index = ( $percentile / 100 ) * ( $count - 1 );
        $lower = floor( $index );
        $upper = ceil( $index );
        $frac  = $index - $lower;

        if ( $lower === $upper ) {
            return round( $values[ $lower ], 2 );
        }

        return round( $values[ $lower ] * ( 1 - $frac ) + $values[ $upper ] * $frac, 2 );
    }

    /**
     * Get CWV rating for a metric value
     *
     * @param string $metric Metric name (lcp, fid, cls, ttfb, inp).
     * @param float  $value  Metric value.
     * @return string good, needs-improvement, or poor.
     */
    public static function get_rating( $metric, $value ) {
        if ( null === $value ) return 'unknown';

        $thresholds = array(
            'lcp'  => array( 2500, 4000 ),
            'fid'  => array( 100, 300 ),
            'cls'  => array( 0.1, 0.25 ),
            'ttfb' => array( 800, 1800 ),
            'inp'  => array( 200, 500 ),
            'fcp'  => array( 1800, 3000 ),
        );

        if ( ! isset( $thresholds[ $metric ] ) ) return 'unknown';

        if ( $value <= $thresholds[ $metric ][0] ) return 'good';
        if ( $value <= $thresholds[ $metric ][1] ) return 'needs-improvement';
        return 'poor';
    }
}
