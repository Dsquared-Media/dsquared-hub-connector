<?php
/**
 * DHC_Alt_Text_Generator — Review-first Media Library alt text workflow.
 *
 * Image inventory and final writes stay inside WordPress. AI generation is
 * proxied server-to-server through the connected Hub account so the private
 * connector key never reaches the browser and Hub billing remains canonical.
 *
 * @package Dsquared_Hub_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DHC_Alt_Text_Generator {

    const PAGE_SLUG  = 'dsquared-hub-alt-text';
    const PAGE_SIZE  = 24;
    const MAX_BATCH  = 20;
    const MAX_LENGTH = 125;

    /** Register authenticated admin actions. */
    public static function init() {
        add_action( 'wp_ajax_dhc_alt_inventory', array( __CLASS__, 'ajax_inventory' ) );
        add_action( 'wp_ajax_dhc_alt_quote', array( __CLASS__, 'ajax_quote' ) );
        add_action( 'wp_ajax_dhc_alt_generate', array( __CLASS__, 'ajax_generate' ) );
        add_action( 'wp_ajax_dhc_alt_save', array( __CLASS__, 'ajax_save' ) );
    }

    /** Render the dedicated D2 Hub admin screen. */
    public static function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to manage image alt text.', 'dsquared-hub-connector' ) );
        }
        $subscription = DHC_API_Key::validate();
        $connected    = ! empty( $subscription['valid'] );
        ?>
        <div class="dhc-wrap dhc-alt-wrap" id="dhc-alt-app">
            <div class="dhc-header dhc-alt-header">
                <div class="dhc-header-left">
                    <div class="dhc-logo">
                        <img src="<?php echo esc_url( DHC_PLUGIN_URL . 'admin/images/d2-logo.jpg' ); ?>" alt="" class="dhc-logo-icon">
                    </div>
                    <div>
                        <h1 class="dhc-title"><?php esc_html_e( 'Alt Text Generator', 'dsquared-hub-connector' ); ?></h1>
                        <p class="dhc-alt-subtitle"><?php esc_html_e( 'Find missing image descriptions, generate concise drafts, and review every change before saving.', 'dsquared-hub-connector' ); ?></p>
                    </div>
                </div>
                <div class="dhc-header-right">
                    <span class="dhc-badge <?php echo $connected ? 'dhc-badge-success' : 'dhc-badge-inactive'; ?>">
                        <span class="dhc-badge-dot"></span>
                        <?php echo $connected ? esc_html__( 'Connected to Hub', 'dsquared-hub-connector' ) : esc_html__( 'Connection required', 'dsquared-hub-connector' ); ?>
                    </span>
                    <a class="dhc-btn dhc-btn-outline" href="https://hub.dsquaredmedia.net/#alt-text-generator" target="_blank" rel="noopener noreferrer">
                        <?php esc_html_e( 'Open in Hub', 'dsquared-hub-connector' ); ?>
                    </a>
                </div>
            </div>

            <?php if ( ! $connected ) : ?>
                <div class="dhc-notice dhc-notice-warning" role="alert">
                    <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                    <div>
                        <strong><?php esc_html_e( 'Connect this site before generating alt text.', 'dsquared-hub-connector' ); ?></strong>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=dsquared-hub-connection' ) ); ?>"><?php esc_html_e( 'Open Connection settings', 'dsquared-hub-connector' ); ?> &rarr;</a>
                    </div>
                </div>
            <?php endif; ?>

            <div class="dhc-alt-summary" aria-label="<?php esc_attr_e( 'Alt text summary', 'dsquared-hub-connector' ); ?>">
                <div class="dhc-alt-stat"><span id="dhc-alt-missing">—</span><small><?php esc_html_e( 'Missing alt text', 'dsquared-hub-connector' ); ?></small></div>
                <div class="dhc-alt-stat"><span id="dhc-alt-selected">0</span><small><?php esc_html_e( 'Selected', 'dsquared-hub-connector' ); ?></small></div>
                <div class="dhc-alt-stat"><span id="dhc-alt-cost">0</span><small><?php esc_html_e( 'Maximum credits', 'dsquared-hub-connector' ); ?></small></div>
            </div>

            <div class="dhc-card dhc-alt-toolbar-card">
                <div class="dhc-alt-toolbar">
                    <div class="dhc-alt-segmented" role="group" aria-label="<?php esc_attr_e( 'Media filter', 'dsquared-hub-connector' ); ?>">
                        <button type="button" class="dhc-alt-filter active" data-filter="missing" aria-pressed="true"><?php esc_html_e( 'Missing alt text', 'dsquared-hub-connector' ); ?></button>
                        <button type="button" class="dhc-alt-filter" data-filter="all" aria-pressed="false"><?php esc_html_e( 'All images', 'dsquared-hub-connector' ); ?></button>
                    </div>
                    <label class="dhc-alt-search">
                        <span class="screen-reader-text"><?php esc_html_e( 'Search Media Library', 'dsquared-hub-connector' ); ?></span>
                        <span class="dashicons dashicons-search" aria-hidden="true"></span>
                        <input type="search" id="dhc-alt-search" placeholder="<?php esc_attr_e( 'Search filenames or titles', 'dsquared-hub-connector' ); ?>">
                    </label>
                    <button type="button" class="dhc-btn dhc-btn-outline" id="dhc-alt-select-visible"><?php esc_html_e( 'Select visible', 'dsquared-hub-connector' ); ?></button>
                </div>
            </div>

            <div id="dhc-alt-notice" class="dhc-alt-inline-notice" role="status" aria-live="polite" tabindex="-1" hidden></div>
            <div id="dhc-alt-grid" class="dhc-alt-grid" aria-busy="true"></div>
            <div id="dhc-alt-empty" class="dhc-card dhc-alt-empty" hidden></div>
            <div class="dhc-alt-load-row"><button type="button" class="dhc-btn dhc-btn-outline" id="dhc-alt-load-more" hidden><?php esc_html_e( 'Load more images', 'dsquared-hub-connector' ); ?></button></div>

            <div class="dhc-alt-actionbar" aria-label="<?php esc_attr_e( 'Alt text actions', 'dsquared-hub-connector' ); ?>">
                <div>
                    <strong id="dhc-alt-action-summary"><?php esc_html_e( 'Select images to begin', 'dsquared-hub-connector' ); ?></strong>
                    <span id="dhc-alt-action-detail"><?php esc_html_e( 'Nothing is generated or saved without your approval.', 'dsquared-hub-connector' ); ?></span>
                </div>
                <div class="dhc-alt-action-buttons">
                    <button type="button" class="dhc-btn dhc-btn-outline" id="dhc-alt-clear" disabled><?php esc_html_e( 'Clear', 'dsquared-hub-connector' ); ?></button>
                    <button type="button" class="dhc-btn dhc-btn-primary" id="dhc-alt-generate" disabled><?php esc_html_e( 'Generate drafts', 'dsquared-hub-connector' ); ?></button>
                    <button type="button" class="dhc-btn dhc-btn-primary" id="dhc-alt-save" disabled><?php esc_html_e( 'Save reviewed changes', 'dsquared-hub-connector' ); ?></button>
                </div>
            </div>
        </div>
        <?php
    }

    /** Enforce the same permission and nonce on every browser action. */
    private static function authorize_ajax() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'You do not have permission to manage image alt text.' ), 403 );
        }
        check_ajax_referer( 'dhc_alt_text_nonce', 'nonce' );
    }

    /** Return a paginated, local Media Library inventory. */
    public static function ajax_inventory() {
        self::authorize_ajax();

        $page   = max( 1, absint( isset( $_POST['page'] ) ? $_POST['page'] : 1 ) );
        $filter = isset( $_POST['filter'] ) && 'all' === sanitize_key( wp_unslash( $_POST['filter'] ) ) ? 'all' : 'missing';
        $search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
        $args   = array(
            'post_type'              => 'attachment',
            'post_status'            => 'inherit',
            'post_mime_type'         => 'image',
            'posts_per_page'         => self::PAGE_SIZE,
            'paged'                  => $page,
            'orderby'                => 'date',
            'order'                  => 'DESC',
            's'                      => $search,
            'no_found_rows'          => false,
            'update_post_term_cache' => false,
        );
        if ( 'missing' === $filter ) {
            $args['meta_query'] = array(
                'relation' => 'OR',
                array( 'key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS' ),
                array( 'key' => '_wp_attachment_image_alt', 'value' => '', 'compare' => '=' ),
            );
        }
        $query  = new WP_Query( $args );
        $images = array();
        foreach ( $query->posts as $attachment ) {
            $url = wp_get_attachment_url( $attachment->ID );
            if ( ! $url ) {
                continue;
            }
            $images[] = array(
                'id'        => (int) $attachment->ID,
                'url'       => esc_url_raw( $url ),
                'thumbnail' => esc_url_raw( wp_get_attachment_image_url( $attachment->ID, 'medium' ) ?: $url ),
                'filename'  => sanitize_file_name( wp_basename( wp_parse_url( $url, PHP_URL_PATH ) ) ),
                'title'     => sanitize_text_field( get_the_title( $attachment ) ),
                'alt_text'  => sanitize_text_field( get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ) ),
            );
        }

        wp_send_json_success( array(
            'images'        => $images,
            'page'          => $page,
            'total_pages'   => (int) $query->max_num_pages,
            'filtered_total'=> (int) $query->found_posts,
            'missing_total' => self::missing_image_count(),
            'quote'         => array( 'available' => false, 'max_batch' => self::MAX_BATCH ),
        ) );
    }

    /** Count image attachments whose alt meta is absent or empty. */
    private static function missing_image_count() {
        global $wpdb;
        $sql = "SELECT COUNT(DISTINCT p.ID)
                  FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm
                    ON pm.post_id = p.ID AND pm.meta_key = '_wp_attachment_image_alt'
                 WHERE p.post_type = 'attachment'
                   AND p.post_status = 'inherit'
                   AND p.post_mime_type LIKE 'image/%'
                   AND (pm.meta_id IS NULL OR pm.meta_value = '')";
        return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed table names and literals only.
    }

    /** Resolve Media Library IDs to canonical attachment URLs. */
    private static function selected_images() {
        $raw = isset( $_POST['images'] ) ? json_decode( wp_unslash( $_POST['images'] ), true ) : array();
        if ( ! is_array( $raw ) || empty( $raw ) ) {
            return new WP_Error( 'empty_selection', 'Select at least one image.' );
        }
        if ( count( $raw ) > self::MAX_BATCH ) {
            return new WP_Error( 'batch_too_large', 'Generate at most ' . self::MAX_BATCH . ' images at a time.' );
        }
        $images = array();
        $seen   = array();
        foreach ( $raw as $item ) {
            $id = isset( $item['media_id'] ) ? absint( $item['media_id'] ) : 0;
            if ( ! $id || isset( $seen[ $id ] ) || 'attachment' !== get_post_type( $id ) || 0 !== strpos( (string) get_post_mime_type( $id ), 'image/' ) ) {
                return new WP_Error( 'invalid_media', 'One selected Media Library item is invalid.' );
            }
            $url = wp_get_attachment_url( $id );
            if ( ! $url ) {
                return new WP_Error( 'missing_media_url', 'One selected image no longer has a source URL.' );
            }
            $seen[ $id ] = true;
            $images[] = array( 'media_id' => $id, 'url' => esc_url_raw( $url ) );
        }
        usort( $images, function( $a, $b ) { return $a['media_id'] - $b['media_id']; } );
        return $images;
    }

    /** Persistent request key survives cache eviction and delayed retries. */
    private static function request_meta_key( $images ) {
        return '_dhc_alt_req_' . hash( 'sha256', wp_json_encode( $images ) );
    }

    private static function request_id( $images ) {
        $user_id = get_current_user_id();
        $key     = self::request_meta_key( $images );
        $id      = get_user_meta( $user_id, $key, true );
        if ( ! is_string( $id ) || ! wp_is_uuid( $id ) ) {
            $id = wp_generate_uuid4();
            update_user_meta( $user_id, $key, $id );
        }
        return $id;
    }

    /** Obtain one signed, selection-bound quote from the authoritative site wallet. */
    public static function ajax_quote() {
        self::authorize_ajax();
        $subscription = DHC_API_Key::validate();
        if ( empty( $subscription['valid'] ) ) {
            wp_send_json_error( array( 'message' => 'Alt text generation requires an active Hub connection.' ), 403 );
        }
        $images = self::selected_images();
        if ( is_wp_error( $images ) ) {
            wp_send_json_error( array( 'message' => $images->get_error_message() ), 400 );
        }
        $request_id = self::request_id( $images );
        $api_key    = get_option( 'dhc_api_key', '' );
        $response   = wp_remote_post( DHC_HUB_API_BASE . '/plugin/alt-text/quote', array(
            'headers' => array(
                'X-DHC-API-Key'  => $api_key,
                'X-DHC-Site-Url' => home_url( '/' ),
                'Content-Type'   => 'application/json',
                'Accept'         => 'application/json',
            ),
            'body'    => wp_json_encode( array( 'site_url' => home_url( '/' ), 'request_id' => $request_id, 'images' => $images ) ),
            'timeout' => 15,
        ) );
        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            $body = is_wp_error( $response ) ? array() : json_decode( wp_remote_retrieve_body( $response ), true );
            wp_send_json_error( array( 'message' => ! empty( $body['error'] ) ? sanitize_text_field( $body['error'] ) : 'The Hub could not prepare an exact credit quote.' ), 502 );
        }
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) || empty( $body['quote_token'] ) || empty( $body['request_id'] ) ) {
            wp_send_json_error( array( 'message' => 'The Hub returned an incomplete credit quote.' ), 502 );
        }
        $body['available'] = true;
        wp_send_json_success( $body );
    }

    /** Proxy a reviewed selection to the Hub's site-bound paid route. */
    public static function ajax_generate() {
        self::authorize_ajax();
        $subscription = DHC_API_Key::validate();
        if ( empty( $subscription['valid'] ) ) {
            wp_send_json_error( array( 'message' => 'Alt text generation requires an active Hub connection.' ), 403 );
        }
        $images = self::selected_images();
        if ( is_wp_error( $images ) ) {
            wp_send_json_error( array( 'message' => $images->get_error_message() ), 400 );
        }
        $quote_token = isset( $_POST['quote_token'] ) ? sanitize_text_field( wp_unslash( $_POST['quote_token'] ) ) : '';
        $request_id  = isset( $_POST['request_id'] ) ? sanitize_text_field( wp_unslash( $_POST['request_id'] ) ) : '';
        if ( '' === $quote_token || ! wp_is_uuid( $request_id ) || $request_id !== self::request_id( $images ) ) {
            wp_send_json_error( array( 'message' => 'The exact credit quote is missing or no longer matches this selection.' ), 409 );
        }

        $api_key = get_option( 'dhc_api_key', '' );
        $response = wp_remote_post( DHC_HUB_API_BASE . '/plugin/alt-text/generate', array(
            'headers' => array(
                'X-DHC-API-Key'  => $api_key,
                'X-DHC-Site-Url' => home_url( '/' ),
                'Content-Type'   => 'application/json',
                'Accept'         => 'application/json',
            ),
            'body'    => wp_json_encode( array( 'site_url' => home_url( '/' ), 'images' => $images, 'quote_token' => $quote_token, 'request_id' => $request_id ) ),
            'timeout' => 120,
        ) );
        if ( is_wp_error( $response ) ) {
            wp_send_json_error( array( 'message' => 'The Hub could not be reached: ' . $response->get_error_message() ), 502 );
        }
        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $code < 200 || $code >= 300 || ! is_array( $body ) ) {
            $message = is_array( $body ) && ! empty( $body['error'] ) ? sanitize_text_field( $body['error'] ) : 'The Hub could not generate alt text.';
            wp_send_json_error( array( 'message' => $message, 'code' => $body['code'] ?? '' ), $code ?: 502 );
        }
        $refund_pending = isset( $body['summary']['refund_pending_credits'] ) ? absint( $body['summary']['refund_pending_credits'] ) : 0;
        if ( 202 !== $code && 0 === $refund_pending && in_array( $body['status'] ?? '', array( 'succeeded', 'failed', 'refunded' ), true ) ) {
            delete_user_meta( get_current_user_id(), self::request_meta_key( $images ) );
        }
        wp_send_json_success( $body );
    }

    /** Save only explicitly reviewed drafts into WordPress attachment meta. */
    public static function ajax_save() {
        self::authorize_ajax();
        $raw = isset( $_POST['updates'] ) ? json_decode( wp_unslash( $_POST['updates'] ), true ) : array();
        if ( ! is_array( $raw ) || empty( $raw ) ) {
            wp_send_json_error( array( 'message' => 'Review at least one generated draft before saving.' ), 400 );
        }
        if ( count( $raw ) > DHC_Media::MAX_BULK ) {
            wp_send_json_error( array( 'message' => 'Save at most ' . DHC_Media::MAX_BULK . ' images at a time.' ), 400 );
        }
        $results = array();
        foreach ( $raw as $item ) {
            $id             = isset( $item['media_id'] ) ? absint( $item['media_id'] ) : 0;
            $classification = isset( $item['classification'] ) ? sanitize_key( $item['classification'] ) : '';
            $alt            = isset( $item['alt_text'] ) ? sanitize_text_field( $item['alt_text'] ) : '';
            if ( ! $id || 'attachment' !== get_post_type( $id ) || 0 !== strpos( (string) get_post_mime_type( $id ), 'image/' ) ) {
                $results[] = array( 'media_id' => $id, 'success' => false, 'error' => 'Media attachment not found.' );
                continue;
            }
            if ( ! in_array( $classification, array( 'usable', 'decorative' ), true ) ) {
                $results[] = array( 'media_id' => $id, 'success' => false, 'error' => 'Choose descriptive or decorative before saving.' );
                continue;
            }
            if ( 'decorative' === $classification ) {
                $alt = '';
            } elseif ( '' === trim( $alt ) ) {
                $results[] = array( 'media_id' => $id, 'success' => false, 'error' => 'Descriptive alt text cannot be blank.' );
                continue;
            }
            if ( function_exists( 'mb_strlen' ) ? mb_strlen( $alt ) > self::MAX_LENGTH : strlen( $alt ) > self::MAX_LENGTH ) {
                $results[] = array( 'media_id' => $id, 'success' => false, 'error' => 'Alt text must be 125 characters or fewer.' );
                continue;
            }
            $existing = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
            $ok       = $existing === $alt || false !== update_post_meta( $id, '_wp_attachment_image_alt', $alt );
            $results[] = array(
                'media_id' => $id,
                'success'  => (bool) $ok,
                'noop'     => $existing === $alt,
                'alt_text' => $ok ? $alt : null,
                'error'    => $ok ? null : 'WordPress could not save this alt text.',
            );
        }
        $saved = count( array_filter( $results, function( $row ) { return ! empty( $row['success'] ); } ) );
        DHC_Event_Logger::log( 'media_alt_review_save', array(
            'total'  => count( $results ),
            'saved'  => $saved,
            'failed' => count( $results ) - $saved,
            'source' => 'wordpress_admin',
        ) );
        wp_send_json_success( array(
            'results' => $results,
            'summary' => array( 'total' => count( $results ), 'saved' => $saved, 'failed' => count( $results ) - $saved ),
        ) );
    }
}
