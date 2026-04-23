<?php
/**
 * DHC_Posts — Module: Post Content Updates
 *
 * Accepts body rewrites from the Hub (AutoReason winners, Deep Page
 * Analysis "Apply Body" actions, etc.) and applies them via
 * wp_update_post(). Core WP auto-creates a revision on every
 * wp_update_post(), so rollback is available via Posts → Edit →
 * Revisions without us doing anything special.
 *
 * This route exists because core /wp/v2/posts/:id POST requires a
 * WP user session — our X-DHC-API-Key header isn't recognized by
 * the core endpoint. Same pattern as /media/alt and /seo-meta.
 *
 * @package Dsquared_Hub_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DHC_Posts {

    /**
     * Handle incoming body-content update from the Hub.
     *
     * Body: { post_id: int|null, url: string|null, content_html, post_title?,
     *         revision_note?, dry_run? }
     *
     * At least one of post_id or url must be provided.
     */
    public static function handle_content_update( $request ) {
        // Gate on the seo_meta subscription module — body rewrites are a
        // natural extension of SEO Meta Sync and shouldn't require a new
        // tier. Clients with SEO Meta have body rewrites for free.
        if ( ! DHC_API_Key::is_module_available( 'seo_meta' ) ) {
            return new WP_Error(
                'dhc_module_unavailable',
                'Post content updates require the SEO Meta Sync module. Upgrade to Growth or Pro.',
                array( 'status' => 403 )
            );
        }

        $post_id       = $request->get_param( 'post_id' );
        $url           = $request->get_param( 'url' );
        $content_html  = $request->get_param( 'content_html' );
        $post_title    = $request->get_param( 'post_title' );
        $revision_note = $request->get_param( 'revision_note' );
        $dry_run       = (bool) $request->get_param( 'dry_run' );

        if ( empty( $content_html ) ) {
            return new WP_Error( 'dhc_missing_content', 'content_html is required.', array( 'status' => 400 ) );
        }

        // Resolve post by URL if post_id not supplied
        if ( ! $post_id && ! empty( $url ) ) {
            $post_id = url_to_postid( $url );
            if ( ! $post_id ) {
                return new WP_Error(
                    'dhc_post_not_found',
                    'Could not resolve a post from URL: ' . esc_url( $url ),
                    array( 'status' => 404 )
                );
            }
        }

        if ( ! $post_id ) {
            return new WP_Error( 'dhc_missing_target', 'Provide either post_id or url.', array( 'status' => 400 ) );
        }

        $post = get_post( (int) $post_id );
        if ( ! $post || $post->post_status === 'trash' ) {
            return new WP_Error( 'dhc_post_not_found', 'Post not found or in trash.', array( 'status' => 404 ) );
        }

        // Only allow edits to actual post types — never users, attachments,
        // menu items, revisions, etc. If a client has a custom post type
        // they want to edit, they can add its slug to the dhc_editable_post_types filter.
        $editable_types = apply_filters( 'dhc_editable_post_types', array( 'post', 'page' ) );
        if ( ! in_array( $post->post_type, $editable_types, true ) ) {
            return new WP_Error(
                'dhc_post_type_not_editable',
                'Post type "' . $post->post_type . '" is not editable via the Hub by default. Add it via the dhc_editable_post_types filter if needed.',
                array( 'status' => 403 )
            );
        }

        // wp_kses_post allows the full set of HTML tags standard editors
        // accept. Strips scripts, event handlers, and other dangerous stuff.
        $safe_content = wp_kses_post( $content_html );

        // Before/after preview for the dry_run response
        $before = array(
            'post_title'  => $post->post_title,
            'content_len' => strlen( $post->post_content ),
        );
        $after = array(
            'post_title'  => $post_title ? $post_title : $post->post_title,
            'content_len' => strlen( $safe_content ),
        );

        if ( $dry_run ) {
            return new WP_REST_Response( array(
                'success' => true,
                'dry_run' => true,
                'post_id' => $post_id,
                'post_type' => $post->post_type,
                'before'  => $before,
                'after'   => $after,
            ), 200 );
        }

        // Core auto-creates a revision on every wp_update_post() when
        // revisions are enabled (they are by default). Rollback via
        // Posts → Edit → Revisions in wp-admin.
        $payload = array(
            'ID'           => $post_id,
            'post_content' => $safe_content,
        );
        if ( ! empty( $post_title ) ) {
            $payload['post_title'] = $post_title;
        }

        $result = wp_update_post( $payload, true );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        // Grab the freshly-created revision so the Hub can deep-link to it
        $revision_id = null;
        $revisions = wp_get_post_revisions( $post_id, array( 'posts_per_page' => 1 ) );
        if ( ! empty( $revisions ) ) {
            $first = reset( $revisions );
            $revision_id = $first ? (int) $first->ID : null;
        }

        // Log the edit so admins can see what the Hub did and when.
        // Revision note defaults to "via Dsquared Hub" but callers can
        // override (e.g. "AutoReason winner for keyword 'X'").
        DHC_Event_Logger::log( 'post_content_updated', array(
            'post_id'       => $post_id,
            'post_type'     => $post->post_type,
            'revision_id'   => $revision_id,
            'title_changed' => ! empty( $post_title ) && $post_title !== $post->post_title,
            'note'          => $revision_note ? $revision_note : 'via Dsquared Hub',
        ) );

        return new WP_REST_Response( array(
            'success'      => true,
            'post_id'      => $post_id,
            'post_type'    => $post->post_type,
            'revision_id'  => $revision_id,
            'edit_url'     => get_edit_post_link( $post_id, 'raw' ),
            'view_url'     => get_permalink( $post_id ),
            'before'       => $before,
            'after'        => $after,
        ), 200 );
    }
}
