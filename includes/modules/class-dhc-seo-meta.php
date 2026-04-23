<?php
/**
 * DHC_SEO_Meta — Module 3: SEO Meta Sync
 *
 * Pushes optimized meta titles, descriptions, and OG data from the Hub's
 * Page Optimizer directly into WordPress. Compatible with Yoast SEO,
 * Rank Math, All in One SEO, and falls back to native meta output.
 *
 * @package Dsquared_Hub_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DHC_SEO_Meta {

    const META_KEY = '_dhc_seo_meta';

    /**
     * Initialize — hook into wp_head for fallback meta output
     */
    public static function init() {
        // Only output our own meta if no SEO plugin is detected
        if ( ! self::has_seo_plugin() ) {
            add_action( 'wp_head', array( __CLASS__, 'output_meta' ), 1 );
        }
    }

    /**
     * Handle incoming SEO meta push from the Hub
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public static function handle_request( $request ) {
        if ( ! DHC_API_Key::is_module_available( 'seo_meta' ) ) {
            return new WP_Error(
                'dhc_module_unavailable',
                'SEO Meta Sync module is not available on your current subscription tier. Upgrade to Growth or Pro to access this feature.',
                array( 'status' => 403 )
            );
        }

        $post_id          = $request->get_param( 'post_id' );
        $url              = $request->get_param( 'url' );
        $meta_title       = $request->get_param( 'meta_title' );
        $meta_description = $request->get_param( 'meta_description' );
        $focus_keyword    = $request->get_param( 'focus_keyword' );
        $og_title         = $request->get_param( 'og_title' );
        $og_description   = $request->get_param( 'og_description' );

        // At least one meta field must be provided
        if ( empty( $meta_title ) && empty( $meta_description ) && empty( $focus_keyword ) ) {
            return new WP_Error(
                'dhc_missing_meta',
                'At least one SEO meta field (meta_title, meta_description, or focus_keyword) is required.',
                array( 'status' => 400 )
            );
        }

        // Resolve post ID from URL if not provided
        if ( empty( $post_id ) && ! empty( $url ) ) {
            $post_id = url_to_postid( $url );
        }

        if ( empty( $post_id ) || ! get_post( $post_id ) ) {
            return new WP_Error(
                'dhc_post_not_found',
                'Could not find a WordPress post for the given post_id or URL.',
                array( 'status' => 404 )
            );
        }

        // Build meta data
        $seo_data = array(
            'meta_title'       => $meta_title ?? '',
            'meta_description' => $meta_description ?? '',
            'focus_keyword'    => $focus_keyword ?? '',
            'og_title'         => $og_title ?? $meta_title ?? '',
            'og_description'   => $og_description ?? $meta_description ?? '',
            'updated_at'       => current_time( 'mysql' ),
            'source'           => 'dsquared-hub',
        );

        // Store in our own meta
        update_post_meta( $post_id, self::META_KEY, $seo_data );

        // Sync to detected SEO plugin
        $synced_to = self::sync_to_seo_plugin( $post_id, $seo_data );

        // Log the action
        self::log_action( $post_id, $synced_to );

        return new WP_REST_Response( array(
            'success'   => true,
            'post_id'   => $post_id,
            'synced_to' => $synced_to,
            'message'   => 'SEO meta updated for post #' . $post_id . '.' .
                          ( $synced_to ? ' Also synced to ' . $synced_to . '.' : '' ),
        ), 200 );
    }

    /**
     * Detect which SEO plugin is active
     *
     * @return string|false Plugin identifier or false.
     */
    public static function detect_seo_plugin() {
        // Yoast SEO
        if ( defined( 'WPSEO_VERSION' ) || is_plugin_active( 'wordpress-seo/wp-seo.php' ) || is_plugin_active( 'wordpress-seo-premium/wp-seo-premium.php' ) ) {
            return 'yoast';
        }

        // Rank Math
        if ( defined( 'RANK_MATH_VERSION' ) || is_plugin_active( 'seo-by-rank-math/rank-math.php' ) ) {
            return 'rankmath';
        }

        // All in One SEO
        if ( defined( 'AIOSEO_VERSION' ) || is_plugin_active( 'all-in-one-seo-pack/all_in_one_seo_pack.php' ) || is_plugin_active( 'all-in-one-seo-pack-pro/all_in_one_seo_pack.php' ) ) {
            return 'aioseo';
        }

        // SEOPress
        if ( defined( 'SEOPRESS_VERSION' ) || is_plugin_active( 'wp-seopress/seopress.php' ) ) {
            return 'seopress';
        }

        return false;
    }

    /**
     * Check if any SEO plugin is active
     *
     * @return bool
     */
    public static function has_seo_plugin() {
        return false !== self::detect_seo_plugin();
    }

    /**
     * Sync meta data to the detected SEO plugin
     *
     * @param int   $post_id  Post ID.
     * @param array $seo_data SEO meta data.
     * @return string|false Plugin synced to, or false.
     */
    private static function sync_to_seo_plugin( $post_id, $seo_data ) {
        $plugin = self::detect_seo_plugin();

        if ( ! $plugin ) {
            return false;
        }

        switch ( $plugin ) {
            case 'yoast':
                return self::sync_yoast( $post_id, $seo_data );

            case 'rankmath':
                return self::sync_rankmath( $post_id, $seo_data );

            case 'aioseo':
                return self::sync_aioseo( $post_id, $seo_data );

            case 'seopress':
                return self::sync_seopress( $post_id, $seo_data );
        }

        return false;
    }

    /**
     * Sync to Yoast SEO
     */
    private static function sync_yoast( $post_id, $data ) {
        if ( ! empty( $data['meta_title'] ) ) {
            update_post_meta( $post_id, '_yoast_wpseo_title', $data['meta_title'] );
        }
        if ( ! empty( $data['meta_description'] ) ) {
            update_post_meta( $post_id, '_yoast_wpseo_metadesc', $data['meta_description'] );
        }
        if ( ! empty( $data['focus_keyword'] ) ) {
            update_post_meta( $post_id, '_yoast_wpseo_focuskw', $data['focus_keyword'] );
        }
        if ( ! empty( $data['og_title'] ) ) {
            update_post_meta( $post_id, '_yoast_wpseo_opengraph-title', $data['og_title'] );
        }
        if ( ! empty( $data['og_description'] ) ) {
            update_post_meta( $post_id, '_yoast_wpseo_opengraph-description', $data['og_description'] );
        }
        return 'Yoast SEO';
    }

    /**
     * Sync to Rank Math
     */
    private static function sync_rankmath( $post_id, $data ) {
        if ( ! empty( $data['meta_title'] ) ) {
            update_post_meta( $post_id, 'rank_math_title', $data['meta_title'] );
        }
        if ( ! empty( $data['meta_description'] ) ) {
            update_post_meta( $post_id, 'rank_math_description', $data['meta_description'] );
        }
        if ( ! empty( $data['focus_keyword'] ) ) {
            update_post_meta( $post_id, 'rank_math_focus_keyword', $data['focus_keyword'] );
        }
        if ( ! empty( $data['og_title'] ) ) {
            update_post_meta( $post_id, 'rank_math_facebook_title', $data['og_title'] );
        }
        if ( ! empty( $data['og_description'] ) ) {
            update_post_meta( $post_id, 'rank_math_facebook_description', $data['og_description'] );
        }
        return 'Rank Math';
    }

    /**
     * Sync to All in One SEO
     */
    private static function sync_aioseo( $post_id, $data ) {
        if ( ! empty( $data['meta_title'] ) ) {
            update_post_meta( $post_id, '_aioseo_title', $data['meta_title'] );
        }
        if ( ! empty( $data['meta_description'] ) ) {
            update_post_meta( $post_id, '_aioseo_description', $data['meta_description'] );
        }
        if ( ! empty( $data['focus_keyword'] ) ) {
            update_post_meta( $post_id, '_aioseo_keyphrases', wp_json_encode( array(
                'focus' => array( 'keyphrase' => $data['focus_keyword'] ),
            ) ) );
        }
        if ( ! empty( $data['og_title'] ) ) {
            update_post_meta( $post_id, '_aioseo_og_title', $data['og_title'] );
        }
        if ( ! empty( $data['og_description'] ) ) {
            update_post_meta( $post_id, '_aioseo_og_description', $data['og_description'] );
        }
        return 'All in One SEO';
    }

    /**
     * Sync to SEOPress
     */
    private static function sync_seopress( $post_id, $data ) {
        if ( ! empty( $data['meta_title'] ) ) {
            update_post_meta( $post_id, '_seopress_titles_title', $data['meta_title'] );
        }
        if ( ! empty( $data['meta_description'] ) ) {
            update_post_meta( $post_id, '_seopress_titles_desc', $data['meta_description'] );
        }
        if ( ! empty( $data['focus_keyword'] ) ) {
            update_post_meta( $post_id, '_seopress_analysis_target_kw', $data['focus_keyword'] );
        }
        if ( ! empty( $data['og_title'] ) ) {
            update_post_meta( $post_id, '_seopress_social_fb_title', $data['og_title'] );
        }
        if ( ! empty( $data['og_description'] ) ) {
            update_post_meta( $post_id, '_seopress_social_fb_desc', $data['og_description'] );
        }
        return 'SEOPress';
    }

    /**
     * Output meta tags in wp_head (fallback when no SEO plugin is active)
     */
    public static function output_meta() {
        if ( ! is_singular() ) {
            return;
        }

        $post_id  = get_the_ID();
        $seo_data = get_post_meta( $post_id, self::META_KEY, true );

        if ( empty( $seo_data ) || ! is_array( $seo_data ) ) {
            return;
        }

        // Meta title (document title filter)
        if ( ! empty( $seo_data['meta_title'] ) ) {
            add_filter( 'pre_get_document_title', function() use ( $seo_data ) {
                return $seo_data['meta_title'];
            }, 99 );
        }

        // Meta description
        if ( ! empty( $seo_data['meta_description'] ) ) {
            echo '<meta name="description" content="' . esc_attr( $seo_data['meta_description'] ) . '" />' . "\n";
        }

        // Open Graph
        if ( ! empty( $seo_data['og_title'] ) ) {
            echo '<meta property="og:title" content="' . esc_attr( $seo_data['og_title'] ) . '" />' . "\n";
        }
        if ( ! empty( $seo_data['og_description'] ) ) {
            echo '<meta property="og:description" content="' . esc_attr( $seo_data['og_description'] ) . '" />' . "\n";
        }
    }

    /**
     * Log SEO meta actions
     */
    private static function log_action( $post_id, $synced_to ) {
        $log = get_option( 'dhc_activity_log', array() );
        if ( count( $log ) >= 50 ) {
            $log = array_slice( $log, -49 );
        }
        $log[] = array(
            'action'    => 'seo_meta_sync',
            'post_id'   => $post_id,
            'synced_to' => $synced_to ?: 'native',
            'time'      => current_time( 'mysql' ),
        );
        update_option( 'dhc_activity_log', $log );
    }

    /**
     * v1.10: Bulk meta rescue. Accepts up to 100 updates per call
     * and re-dispatches each through handle_request() so SEO-plugin
     * detection + fallback logic stays consistent. Per-item errors
     * are returned inline — the batch only 500s if auth/module gate
     * fails.
     */
    const BULK_MAX = 100;

    public static function handle_bulk_request( $request ) {
        if ( ! DHC_API_Key::is_module_available( 'seo_meta' ) ) {
            return new WP_Error(
                'dhc_module_unavailable',
                'SEO Meta Sync module is not available on your current subscription tier.',
                array( 'status' => 403 )
            );
        }
        $updates = $request->get_param( 'updates' );
        if ( ! is_array( $updates ) || empty( $updates ) ) {
            return new WP_Error( 'dhc_missing_updates', 'updates[] is required.', array( 'status' => 400 ) );
        }
        if ( count( $updates ) > self::BULK_MAX ) {
            return new WP_Error(
                'dhc_too_many_updates',
                'Max ' . self::BULK_MAX . ' updates per call. Split into smaller batches.',
                array( 'status' => 400 )
            );
        }

        $results    = array();
        $successful = 0;
        $failed     = 0;

        foreach ( $updates as $u ) {
            $post_id = isset( $u['post_id'] ) ? (int) $u['post_id'] : 0;
            $url     = isset( $u['url'] ) ? (string) $u['url'] : '';
            if ( ! $post_id && $url ) {
                $post_id = url_to_postid( $url );
            }
            if ( ! $post_id ) {
                $results[] = array( 'success' => false, 'error' => 'Missing post_id / url', 'post_id' => null );
                $failed++; continue;
            }
            // Fabricate a sub-request so handle_request() can reuse its
            // normal param-read + SEO-plugin-detect flow.
            $sub_req = new WP_REST_Request( 'POST' );
            $sub_req->set_param( 'post_id', $post_id );
            if ( isset( $u['meta_title'] ) )       $sub_req->set_param( 'meta_title',       sanitize_text_field( $u['meta_title'] ) );
            if ( isset( $u['meta_description'] ) ) $sub_req->set_param( 'meta_description', sanitize_textarea_field( $u['meta_description'] ) );
            if ( isset( $u['focus_keyword'] ) )    $sub_req->set_param( 'focus_keyword',    sanitize_text_field( $u['focus_keyword'] ) );
            if ( isset( $u['og_title'] ) )         $sub_req->set_param( 'og_title',         sanitize_text_field( $u['og_title'] ) );
            if ( isset( $u['og_description'] ) )   $sub_req->set_param( 'og_description',   sanitize_textarea_field( $u['og_description'] ) );

            $r = self::handle_request( $sub_req );
            if ( is_wp_error( $r ) ) {
                $results[] = array(
                    'success' => false,
                    'post_id' => $post_id,
                    'error'   => $r->get_error_message(),
                );
                $failed++;
            } else {
                $data = is_a( $r, 'WP_REST_Response' ) ? $r->get_data() : (array) $r;
                $results[] = array_merge(
                    array( 'success' => true, 'post_id' => $post_id ),
                    (array) $data
                );
                $successful++;
            }
        }

        DHC_Event_Logger::log( 'seo_meta_bulk', array(
            'total'      => count( $updates ),
            'successful' => $successful,
            'failed'     => $failed,
        ) );

        return new WP_REST_Response( array(
            'success' => true,
            'results' => $results,
            'summary' => array(
                'total'   => count( $updates ),
                'updated' => $successful,
                'failed'  => $failed,
            ),
        ), 200 );
    }
}
