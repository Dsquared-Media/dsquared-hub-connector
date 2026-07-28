<?php
/**
 * DHC_Schema — Module 2: Schema Markup Injector
 *
 * Receives structured data (JSON-LD) from the Hub's Schema Generator
 * and injects it into the appropriate WordPress pages/posts.
 * Stores schema per-post in post meta, or site-wide in options.
 *
 * @package Dsquared_Hub_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DHC_Schema {

    const META_KEY       = '_dhc_schema_markup';
    const GLOBAL_OPTION  = 'dhc_global_schemas';

    /**
     * Initialize — hook into wp_head to output schema
     */
    public static function init() {
        add_action( 'wp_head', array( __CLASS__, 'output_schema' ), 99 );
    }

    /**
     * Handle incoming schema push from the Hub
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public static function handle_request( $request ) {
        if ( ! DHC_API_Key::is_module_available( 'schema' ) ) {
            return new WP_Error(
                'dhc_module_unavailable',
                'Schema Injector module is not available on your current subscription tier. Upgrade to Growth or Pro to access this feature.',
                array( 'status' => 403 )
            );
        }

        $schema      = $request->get_param( 'schema' );
        $post_id     = $request->get_param( 'post_id' );
        $url         = $request->get_param( 'url' );
        $schema_type = $request->get_param( 'schema_type' ) ?? 'custom';

        if ( empty( $schema ) ) {
            return new WP_Error(
                'dhc_missing_schema',
                'Schema markup data is required.',
                array( 'status' => 400 )
            );
        }

        // If schema is a string, try to parse it as JSON
        if ( is_string( $schema ) ) {
            $parsed = json_decode( $schema, true );
            if ( json_last_error() === JSON_ERROR_NONE ) {
                $schema = $parsed;
            } else {
                return new WP_Error(
                    'dhc_invalid_schema',
                    'Schema markup must be valid JSON.',
                    array( 'status' => 400 )
                );
            }
        }

        // Resolve post ID from URL if not provided (WooCommerce/host-aware).
        if ( empty( $post_id ) && ! empty( $url ) ) {
            $post_id = DHC_Core::resolve_post_id_from_url( $url );
        }

        // Store schema
        if ( $post_id && $post_id > 0 ) {
            // Per-post schema
            $existing = get_post_meta( $post_id, self::META_KEY, true );
            if ( ! is_array( $existing ) ) {
                $existing = array();
            }

            // Replace or add schema by type
            $existing[ $schema_type ] = array(
                'markup'     => $schema,
                'updated_at' => current_time( 'mysql' ),
                'source'     => 'dsquared-hub',
            );

            update_post_meta( $post_id, self::META_KEY, $existing );

            // Log the action
            self::log_action( 'schema_updated', $post_id, $schema_type );

            return new WP_REST_Response( array(
                'success'     => true,
                'post_id'     => $post_id,
                'schema_type' => $schema_type,
                'message'     => 'Schema markup saved for post #' . $post_id . '.',
            ), 200 );
        } else {
            // Global/site-wide schema (e.g., Organization, LocalBusiness)
            $global = get_option( self::GLOBAL_OPTION, array() );

            $global[ $schema_type ] = array(
                'markup'     => $schema,
                'url'        => $url ?? '',
                'updated_at' => current_time( 'mysql' ),
                'source'     => 'dsquared-hub',
            );

            update_option( self::GLOBAL_OPTION, $global );

            self::log_action( 'global_schema_updated', 0, $schema_type );

            return new WP_REST_Response( array(
                'success'     => true,
                'scope'       => 'global',
                'schema_type' => $schema_type,
                'message'     => 'Global schema markup saved.',
            ), 200 );
        }
    }

    /**
     * Output schema markup in wp_head
     */
    public static function output_schema() {
        // Output global schemas on every page
        $global_schemas = get_option( self::GLOBAL_OPTION, array() );
        if ( ! empty( $global_schemas ) ) {
            foreach ( $global_schemas as $type => $data ) {
                if ( ! empty( $data['markup'] ) ) {
                    self::render_json_ld( $data['markup'], 'global-' . $type );
                }
            }
        }

        // Output per-post schemas on singular pages
        if ( is_singular() ) {
            $post_id = get_the_ID();
            $schemas = get_post_meta( $post_id, self::META_KEY, true );

            if ( ! empty( $schemas ) && is_array( $schemas ) ) {
                foreach ( $schemas as $type => $data ) {
                    if ( ! empty( $data['markup'] ) ) {
                        self::render_json_ld( $data['markup'], 'post-' . $type );
                    }
                }
            }
        }
    }

    /**
     * Render a JSON-LD script tag
     *
     * @param array|object $schema Schema data.
     * @param string       $id     Identifier for the script tag.
     */
    private static function render_json_ld( $schema, $id = '' ) {
        $json = wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
        if ( ! $json ) {
            return;
        }

        echo "\n<!-- Dsquared Hub Schema: " . esc_attr( $id ) . " -->\n";
        echo '<script type="application/ld+json">' . "\n";
        echo $json . "\n";
        echo '</script>' . "\n";
    }

    /**
     * Get all schemas for a post (used in admin UI)
     *
     * @param int $post_id Post ID.
     * @return array
     */
    public static function get_post_schemas( $post_id ) {
        $schemas = get_post_meta( $post_id, self::META_KEY, true );
        return is_array( $schemas ) ? $schemas : array();
    }

    /**
     * Delete a specific schema type from a post
     *
     * @param int    $post_id     Post ID.
     * @param string $schema_type Schema type to remove.
     * @return bool
     */
    public static function delete_post_schema( $post_id, $schema_type ) {
        $schemas = self::get_post_schemas( $post_id );
        if ( isset( $schemas[ $schema_type ] ) ) {
            unset( $schemas[ $schema_type ] );
            update_post_meta( $post_id, self::META_KEY, $schemas );
            return true;
        }
        return false;
    }

    /**
     * Log schema actions
     */
    private static function log_action( $action, $post_id, $schema_type ) {
        $log = get_option( 'dhc_activity_log', array() );
        if ( count( $log ) >= 50 ) {
            $log = array_slice( $log, -49 );
        }
        $log[] = array(
            'action'      => $action,
            'post_id'     => $post_id,
            'schema_type' => $schema_type,
            'time'        => current_time( 'mysql' ),
        );
        update_option( 'dhc_activity_log', $log );
    }
}
