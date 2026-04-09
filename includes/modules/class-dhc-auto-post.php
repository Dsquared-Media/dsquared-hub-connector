<?php
/**
 * DHC_Auto_Post — Module 1: Auto-Post content from Hub as WordPress drafts
 *
 * Receives title + body content from the Hub's Blog Writer / Content AI
 * and creates a WordPress draft post. Supports categories, tags, excerpts,
 * featured images, and custom meta.
 *
 * @package Dsquared_Hub_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DHC_Auto_Post {

    /**
     * Handle incoming auto-post request from the Hub
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public static function handle_request( $request ) {
        // Check if module is available
        if ( ! DHC_API_Key::is_module_available( 'auto_post' ) ) {
            return new WP_Error(
                'dhc_module_unavailable',
                'Auto-Post module is not available on your current subscription tier.',
                array( 'status' => 403 )
            );
        }

        $title   = $request->get_param( 'title' );
        $content = $request->get_param( 'content' );
        $excerpt = $request->get_param( 'excerpt' ) ?? '';

        if ( empty( $title ) || empty( $content ) ) {
            return new WP_Error(
                'dhc_missing_fields',
                'Title and content are required.',
                array( 'status' => 400 )
            );
        }

        // Prepare post data — support draft or publish from Hub
        $meta = $request->get_param( 'meta' );
        $requested_status = 'draft';
        if ( ! empty( $meta ) && is_array( $meta ) && ! empty( $meta['post_status'] ) ) {
            $requested_status = in_array( $meta['post_status'], array( 'draft', 'publish', 'pending' ), true )
                ? $meta['post_status'] : 'draft';
        }
        $post_data = array(
            'post_title'   => $title,
            'post_content' => $content,
            'post_excerpt' => $excerpt,
            'post_status'  => $requested_status,
            'post_type'    => 'post',
            'post_author'  => self::get_default_author(),
            'meta_input'   => array(
                '_dhc_source'     => 'dsquared-hub',
                '_dhc_created_at' => current_time( 'mysql' ),
            ),
        );

        // Handle categories
        $categories = $request->get_param( 'categories' );
        if ( ! empty( $categories ) && is_array( $categories ) ) {
            $cat_ids = array();
            foreach ( $categories as $cat_name ) {
                $cat_name = sanitize_text_field( $cat_name );
                $term     = term_exists( $cat_name, 'category' );
                if ( $term ) {
                    $cat_ids[] = (int) $term['term_id'];
                } else {
                    // Create the category if it doesn't exist
                    $new_term = wp_insert_term( $cat_name, 'category' );
                    if ( ! is_wp_error( $new_term ) ) {
                        $cat_ids[] = (int) $new_term['term_id'];
                    }
                }
            }
            if ( ! empty( $cat_ids ) ) {
                $post_data['post_category'] = $cat_ids;
            }
        }

        // Insert the post
        $post_id = wp_insert_post( $post_data, true );

        if ( is_wp_error( $post_id ) ) {
            return new WP_Error(
                'dhc_post_failed',
                'Failed to create draft post: ' . $post_id->get_error_message(),
                array( 'status' => 500 )
            );
        }

        // Handle tags
        $tags = $request->get_param( 'tags' );
        if ( ! empty( $tags ) && is_array( $tags ) ) {
            $tag_names = array_map( 'sanitize_text_field', $tags );
            wp_set_post_tags( $post_id, $tag_names, false );
        }

        // Handle featured image from URL
        $featured_image_url = $request->get_param( 'featured_image_url' );
        if ( ! empty( $featured_image_url ) ) {
            $image_id = self::sideload_image( $featured_image_url, $post_id, $title );
            if ( $image_id && ! is_wp_error( $image_id ) ) {
                set_post_thumbnail( $post_id, $image_id );
            }
        }

        // Handle custom meta from Hub
        $meta = $request->get_param( 'meta' );
        if ( ! empty( $meta ) && is_array( $meta ) ) {
            foreach ( $meta as $key => $value ) {
                // Prefix all Hub meta keys for safety
                $safe_key = '_dhc_' . sanitize_key( $key );
                update_post_meta( $post_id, $safe_key, sanitize_text_field( $value ) );
            }
        }

        // Log the action
        self::log_post_creation( $post_id, $title );

        // Set slug if provided
        if ( ! empty( $meta ) && is_array( $meta ) && ! empty( $meta['url_slug'] ) ) {
            wp_update_post( array(
                'ID'        => $post_id,
                'post_name' => sanitize_title( $meta['url_slug'] ),
            ) );
        }

        // Set Yoast/RankMath meta if available
        if ( ! empty( $meta ) && is_array( $meta ) ) {
            if ( ! empty( $meta['meta_description'] ) ) {
                update_post_meta( $post_id, '_yoast_wpseo_metadesc', sanitize_text_field( $meta['meta_description'] ) );
                update_post_meta( $post_id, 'rank_math_description', sanitize_text_field( $meta['meta_description'] ) );
            }
            if ( ! empty( $meta['primary_keyword'] ) ) {
                update_post_meta( $post_id, '_yoast_wpseo_focuskw', sanitize_text_field( $meta['primary_keyword'] ) );
                update_post_meta( $post_id, 'rank_math_focus_keyword', sanitize_text_field( $meta['primary_keyword'] ) );
            }
        }

        // Log event to Hub
        if ( class_exists( 'DHC_Event_Logger' ) ) {
            DHC_Event_Logger::log( 'auto_post_created', array(
                'post_id' => $post_id,
                'title'   => $title,
                'status'  => $requested_status,
            ) );
        }

        return new WP_REST_Response( array(
            'success'  => true,
            'post_id'  => $post_id,
            'status'   => $requested_status,
            'edit_url' => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
            'message'  => ucfirst( $requested_status ) . ' post created successfully.',
        ), 201 );
    }

    /**
     * Get the default author for auto-posted content
     *
     * @return int User ID
     */
    private static function get_default_author() {
        // Check if a default author is set in plugin options
        $default_author = get_option( 'dhc_default_author', 0 );
        if ( $default_author && get_user_by( 'id', $default_author ) ) {
            return (int) $default_author;
        }

        // Fall back to the first administrator
        $admins = get_users( array(
            'role'   => 'administrator',
            'number' => 1,
            'fields' => 'ID',
        ) );

        return ! empty( $admins ) ? (int) $admins[0] : 1;
    }

    /**
     * Sideload a remote image into the WordPress media library
     *
     * @param string $url     Image URL.
     * @param int    $post_id Post to attach image to.
     * @param string $title   Image title/alt text.
     * @return int|WP_Error Attachment ID or error.
     */
    private static function sideload_image( $url, $post_id, $title = '' ) {
        if ( ! function_exists( 'media_sideload_image' ) ) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        // Download the image to a temp file
        $tmp = download_url( $url, 30 );
        if ( is_wp_error( $tmp ) ) {
            return $tmp;
        }

        // Determine filename from URL
        $url_path = wp_parse_url( $url, PHP_URL_PATH );
        $filename = basename( $url_path );

        // Ensure it has an extension
        if ( ! preg_match( '/\.(jpe?g|png|gif|webp|svg)$/i', $filename ) ) {
            $filename .= '.jpg';
        }

        $file_array = array(
            'name'     => sanitize_file_name( $filename ),
            'tmp_name' => $tmp,
        );

        // Sideload into media library
        $attachment_id = media_handle_sideload( $file_array, $post_id, $title );

        // Clean up temp file on error
        if ( is_wp_error( $attachment_id ) ) {
            @unlink( $tmp );
        }

        return $attachment_id;
    }

    /**
     * Log post creation for activity tracking
     *
     * @param int    $post_id Post ID.
     * @param string $title   Post title.
     */
    private static function log_post_creation( $post_id, $title ) {
        $log = get_option( 'dhc_activity_log', array() );

        // Keep only last 50 entries
        if ( count( $log ) >= 50 ) {
            $log = array_slice( $log, -49 );
        }

        $log[] = array(
            'action'  => 'auto_post',
            'post_id' => $post_id,
            'title'   => $title,
            'time'    => current_time( 'mysql' ),
        );

        update_option( 'dhc_activity_log', $log );
    }
}
