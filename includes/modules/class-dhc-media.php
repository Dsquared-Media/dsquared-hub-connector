<?php
/**
 * DHC_Media — Module: Media / Alt Text Updates
 *
 * Lets the Dsquared Hub push alt_text updates directly to WordPress media
 * attachments. Exists because core /wp-json/wp/v2/media/:id requires
 * WordPress user auth (Application Password or cookie+nonce), which the
 * Hub doesn't have — only our plugin's X-DHC-API-Key. This endpoint
 * accepts the API-key auth and writes the alt text via
 * update_post_meta( $id, '_wp_attachment_image_alt', $alt ).
 *
 * Supports two modes:
 *   POST /dsquared-hub/v1/media/alt
 *     Single: { media_id: 123, alt_text: "..." }
 *     Bulk:   { updates: [{ media_id, alt_text }, ...] }  (up to 50 per call)
 *
 * @package Dsquared_Hub_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DHC_Media {

    const MAX_BULK = 50;

    /**
     * Handle incoming alt-text push from the Hub.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public static function handle_alt_request( $request ) {
        // Subscription gate — alt-text is a growth-tier convenience but
        // ride the SEO Meta module flag since that's where it belongs
        // conceptually and users who have SEO Meta already expect this.
        if ( ! DHC_API_Key::is_module_available( 'seo_meta' ) ) {
            return new WP_Error(
                'dhc_module_unavailable',
                'Alt-text push is part of the SEO Meta Sync module. Upgrade to Growth or Pro to use it.',
                array( 'status' => 403 )
            );
        }

        $updates_param = $request->get_param( 'updates' );
        $single_id     = $request->get_param( 'media_id' );
        $single_alt    = $request->get_param( 'alt_text' );

        // Normalize to a single updates[] shape.
        $updates = array();
        if ( is_array( $updates_param ) && ! empty( $updates_param ) ) {
            $updates = $updates_param;
        } elseif ( $single_id !== null ) {
            $updates[] = array(
                'media_id'  => $single_id,
                'alt_text'  => $single_alt,
            );
        } else {
            return new WP_Error(
                'dhc_missing_payload',
                'Provide either { media_id, alt_text } or { updates: [{ media_id, alt_text }, ...] }.',
                array( 'status' => 400 )
            );
        }

        if ( count( $updates ) > self::MAX_BULK ) {
            return new WP_Error(
                'dhc_too_many_updates',
                'Maximum ' . self::MAX_BULK . ' updates per request. Split into smaller batches.',
                array( 'status' => 400 )
            );
        }

        $results    = array();
        $successful = 0;
        $failed     = 0;

        foreach ( $updates as $u ) {
            $id  = isset( $u['media_id'] ) ? (int) $u['media_id'] : 0;
            $alt = isset( $u['alt_text'] ) ? (string) $u['alt_text'] : '';

            // Sanitize + cap length. WP has no hard ceiling but screen
            // readers truncate around 125 chars; we cap generously at 250
            // so we're tolerant of intentional long-form descriptions
            // without letting the Hub shove a whole paragraph in.
            $alt = sanitize_text_field( $alt );
            if ( strlen( $alt ) > 250 ) {
                $alt = substr( $alt, 0, 250 );
            }

            if ( $id <= 0 ) {
                $results[] = array(
                    'media_id' => $id,
                    'success'  => false,
                    'error'    => 'Invalid media_id',
                );
                $failed++;
                continue;
            }

            $post = get_post( $id );
            if ( ! $post || $post->post_type !== 'attachment' ) {
                $results[] = array(
                    'media_id' => $id,
                    'success'  => false,
                    'error'    => 'Media not found',
                );
                $failed++;
                continue;
            }

            // update_post_meta returns:
            //   true on insert/update, false on DB error, AND false when
            //   the new value is identical to the existing value. We
            //   distinguish with get_post_meta so "no-op" isn't a failure.
            $existing = get_post_meta( $id, '_wp_attachment_image_alt', true );
            $same     = ( $existing === $alt );
            $ok       = $same || (bool) update_post_meta( $id, '_wp_attachment_image_alt', $alt );

            // Mirror into post_excerpt (WP uses this for attachment "caption")
            // only if the caller wants it — default off. Alt text and caption
            // are different fields; don't conflate them.

            if ( $ok ) {
                $results[] = array(
                    'media_id' => $id,
                    'success'  => true,
                    'alt_text' => $alt,
                    'noop'     => $same,
                );
                $successful++;
            } else {
                $results[] = array(
                    'media_id' => $id,
                    'success'  => false,
                    'error'    => 'update_post_meta returned false',
                );
                $failed++;
            }
        }

        // Log the event so admins can see push activity
        DHC_Event_Logger::log( 'media_alt_update', array(
            'total'      => count( $updates ),
            'successful' => $successful,
            'failed'     => $failed,
        ) );

        return new WP_REST_Response( array(
            'success'    => true,
            'results'    => $results,
            'summary'    => array(
                'total'      => count( $updates ),
                'successful' => $successful,
                'failed'     => $failed,
            ),
        ), 200 );
    }
}
