<?php
/**
 * DHC_Link_Scanner — Module: 404 logger + weekly broken-link scan
 *
 * Two complementary parts:
 *   1. 404 logger — hooks template_redirect, captures path + referer
 *      + user-agent on every 404, buckets by path so repeat-404s count
 *      instead of spamming. Last 500 entries kept.
 *   2. Weekly broken-link scanner — wp-cron job iterates the last 200
 *      published posts/pages, parses links from post_content, HEAD-pings
 *      external URLs + verifies internal URLs resolve to 2xx. Writes
 *      findings to a dhc_broken_links option keyed by source_post_id.
 *
 * Admin sub-page under the main Dsquared Hub menu (added in
 * class-dhc-admin.php). REST endpoint /link-scan returns findings for
 * the Hub to surface in the Today widget.
 *
 * @package Dsquared_Hub_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DHC_Link_Scanner {

    const CRON_HOOK        = 'dhc_link_scan_cron';
    const OPT_404_LOG      = 'dhc_404_log';        // array of {path, referer, count, last_seen, ua}
    const OPT_BROKEN_LINKS = 'dhc_broken_links';   // array of {url, source_post_id, source_title, status, last_checked}
    const OPT_LAST_SCAN    = 'dhc_link_scan_last_run';

    const MAX_404_ENTRIES  = 500;
    const MAX_BROKEN       = 500;
    const SCAN_POSTS_LIMIT = 200;  // how many posts to crawl per weekly run
    const SCAN_TIMEOUT     = 10;   // seconds per URL HEAD request

    const OPT_REDIRECTS    = 'dhc_redirects';      // [{id, from, to, status, hits, created_at}]
    const MAX_REDIRECTS    = 1000;

    public static function init() {
        // Early priority — run BEFORE WP decides this is a 404 so we can
        // intercept matching paths and issue the redirect ourselves.
        add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect' ), 1 );
        add_action( 'template_redirect', array( __CLASS__, 'maybe_log_404' ), 99 );
        add_action( self::CRON_HOOK,     array( __CLASS__, 'run_weekly_scan' ) );
        // AJAX for the admin UI
        add_action( 'wp_ajax_dhc_redirect_add',    array( __CLASS__, 'ajax_add_redirect' ) );
        add_action( 'wp_ajax_dhc_redirect_delete', array( __CLASS__, 'ajax_delete_redirect' ) );
    }

    // ───── 301 REDIRECT MANAGER ───────────────────────────────

    /** Check the current URI against stored redirects; if a match, redirect. */
    public static function maybe_redirect() {
        if ( is_admin() ) return;
        $uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
        if ( $uri === '' ) return;
        $path = strtok( $uri, '?' );
        if ( $path === false || $path === '' ) return;
        // Normalize: strip trailing slash (except root), lowercase query-less path.
        $norm_request = rtrim( strtolower( $path ), '/' );
        if ( $norm_request === '' ) $norm_request = '/';

        $redirects = get_option( self::OPT_REDIRECTS, array() );
        if ( empty( $redirects ) ) return;

        foreach ( $redirects as &$r ) {
            if ( empty( $r['from'] ) || empty( $r['to'] ) ) continue;
            $norm_from = rtrim( strtolower( $r['from'] ), '/' );
            if ( $norm_from === '' ) $norm_from = '/';
            if ( $norm_request === $norm_from ) {
                $r['hits']      = (int) ( $r['hits'] ?? 0 ) + 1;
                $r['last_hit']  = current_time( 'mysql' );
                update_option( self::OPT_REDIRECTS, $redirects, false );
                $code = (int) ( $r['status'] ?? 301 );
                if ( ! in_array( $code, array( 301, 302, 307, 308 ), true ) ) $code = 301;
                wp_redirect( $r['to'], $code );
                exit;
            }
        }
    }

    public static function get_redirects() {
        $r = get_option( self::OPT_REDIRECTS, array() );
        return is_array( $r ) ? $r : array();
    }

    public static function ajax_add_redirect() {
        check_ajax_referer( 'dhc_link_scanner', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => 'Permission denied.' ) );

        $from   = isset( $_POST['from'] ) ? trim( (string) wp_unslash( $_POST['from'] ) ) : '';
        $to     = isset( $_POST['to'] )   ? esc_url_raw( (string) wp_unslash( $_POST['to'] ) ) : '';
        $status = isset( $_POST['status'] ) ? (int) $_POST['status'] : 301;

        if ( empty( $from ) || empty( $to ) ) {
            wp_send_json_error( array( 'message' => 'Both "from" and "to" are required.' ) );
        }
        // Normalize from: must start with / and not include query/fragment.
        if ( strpos( $from, '/' ) !== 0 ) $from = '/' . $from;
        $from = strtok( $from, '?#' );
        if ( ! in_array( $status, array( 301, 302, 307, 308 ), true ) ) $status = 301;

        $redirects = self::get_redirects();
        // Reject dup
        foreach ( $redirects as $r ) {
            if ( rtrim( strtolower( $r['from'] ?? '' ), '/' ) === rtrim( strtolower( $from ), '/' ) ) {
                wp_send_json_error( array( 'message' => 'A redirect for that path already exists.' ) );
            }
        }

        $redirects[] = array(
            'id'         => uniqid( 'r', true ),
            'from'       => $from,
            'to'         => $to,
            'status'     => $status,
            'hits'       => 0,
            'created_at' => current_time( 'mysql' ),
        );
        if ( count( $redirects ) > self::MAX_REDIRECTS ) $redirects = array_slice( $redirects, -self::MAX_REDIRECTS );
        update_option( self::OPT_REDIRECTS, $redirects, false );

        wp_send_json_success( array( 'message' => 'Redirect created.' ) );
    }

    public static function ajax_delete_redirect() {
        check_ajax_referer( 'dhc_link_scanner', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => 'Permission denied.' ) );
        $id = isset( $_POST['id'] ) ? (string) $_POST['id'] : '';
        if ( empty( $id ) ) wp_send_json_error( array( 'message' => 'Missing id' ) );
        $redirects = self::get_redirects();
        $filtered = array_values( array_filter( $redirects, function( $r ) use ( $id ) { return ( $r['id'] ?? '' ) !== $id; } ) );
        update_option( self::OPT_REDIRECTS, $filtered, false );
        wp_send_json_success();
    }

    public static function schedule() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + 86400, 'weekly', self::CRON_HOOK );
        }
    }

    public static function unschedule() {
        $ts = wp_next_scheduled( self::CRON_HOOK );
        if ( $ts ) wp_unschedule_event( $ts, self::CRON_HOOK );
    }

    // ───── 404 LOGGER ─────────────────────────────────────────

    /** Called on every front-end request; only logs when is_404() */
    public static function maybe_log_404() {
        if ( ! is_404() ) return;
        // Skip bot traffic heavy hitters — we care about real users hitting 404s
        if ( ! empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
            $ua = $_SERVER['HTTP_USER_AGENT'];
            if ( preg_match( '/bot|crawler|spider|scrape/i', $ua ) ) return;
        }
        // Build a normalized path key (strip query string, lowercase)
        $path = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
        $path = strtok( $path, '?' );
        $path = substr( $path, 0, 500 );
        if ( $path === false || $path === '' ) return;

        $log = get_option( self::OPT_404_LOG, array() );

        // Find existing entry by path — increment rather than append
        $found = false;
        foreach ( $log as &$entry ) {
            if ( ! is_array( $entry ) || empty( $entry['path'] ) ) continue;
            if ( $entry['path'] === $path ) {
                $entry['count']     = (int) ( $entry['count'] ?? 0 ) + 1;
                $entry['last_seen'] = current_time( 'mysql' );
                if ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
                    $entry['referer'] = substr( (string) $_SERVER['HTTP_REFERER'], 0, 500 );
                }
                $found = true;
                break;
            }
        }
        unset( $entry );

        if ( ! $found ) {
            $log[] = array(
                'path'       => $path,
                'referer'    => isset( $_SERVER['HTTP_REFERER'] ) ? substr( (string) $_SERVER['HTTP_REFERER'], 0, 500 ) : '',
                'count'      => 1,
                'first_seen' => current_time( 'mysql' ),
                'last_seen'  => current_time( 'mysql' ),
            );
        }

        // Cap the log + sort by last_seen desc
        if ( count( $log ) > self::MAX_404_ENTRIES ) {
            usort( $log, function( $a, $b ) {
                return strtotime( $b['last_seen'] ?? '0' ) - strtotime( $a['last_seen'] ?? '0' );
            } );
            $log = array_slice( $log, 0, self::MAX_404_ENTRIES );
        }
        update_option( self::OPT_404_LOG, $log, false );
    }

    public static function get_404s( $limit = 50 ) {
        $log = get_option( self::OPT_404_LOG, array() );
        usort( $log, function( $a, $b ) {
            // Primary sort: highest-count first; tiebreaker: most recent
            $cmp = (int) ( $b['count'] ?? 0 ) - (int) ( $a['count'] ?? 0 );
            if ( $cmp !== 0 ) return $cmp;
            return strtotime( $b['last_seen'] ?? '0' ) - strtotime( $a['last_seen'] ?? '0' );
        } );
        return array_slice( $log, 0, (int) $limit );
    }

    public static function clear_404s() {
        update_option( self::OPT_404_LOG, array(), false );
    }

    // ───── BROKEN LINK SCANNER ────────────────────────────────

    public static function run_weekly_scan() {
        // Cap runtime — large sites shouldn't chew unlimited PHP time
        @set_time_limit( 120 );

        $posts = get_posts( array(
            'post_type'      => array( 'post', 'page' ),
            'post_status'    => 'publish',
            'posts_per_page' => self::SCAN_POSTS_LIMIT,
            'orderby'        => 'modified',
            'order'          => 'DESC',
            'fields'         => 'ids',
        ) );

        $broken = array();
        $checked_urls = array(); // de-dupe: same URL across posts = one HEAD request

        foreach ( $posts as $pid ) {
            $post = get_post( $pid );
            if ( ! $post || empty( $post->post_content ) ) continue;
            $urls = self::extract_urls( $post->post_content );
            foreach ( $urls as $url ) {
                if ( isset( $checked_urls[ $url ] ) ) {
                    // Already checked this URL in this run — just attribute to another source
                    if ( $checked_urls[ $url ]['broken'] ) {
                        $broken[] = array_merge( $checked_urls[ $url ], array(
                            'source_post_id' => $pid,
                            'source_title'   => $post->post_title,
                            'source_url'     => get_permalink( $pid ),
                        ) );
                    }
                    continue;
                }
                $status = self::check_url( $url );
                $is_broken = $status >= 400 || $status === 0 /* connect failure */;
                $checked_urls[ $url ] = array(
                    'url'          => $url,
                    'status'       => $status,
                    'broken'       => $is_broken,
                    'last_checked' => current_time( 'mysql' ),
                );
                if ( $is_broken ) {
                    $broken[] = array_merge( $checked_urls[ $url ], array(
                        'source_post_id' => $pid,
                        'source_title'   => $post->post_title,
                        'source_url'     => get_permalink( $pid ),
                    ) );
                }
                if ( count( $broken ) >= self::MAX_BROKEN ) break 2;
            }
        }

        update_option( self::OPT_BROKEN_LINKS, $broken, false );
        update_option( self::OPT_LAST_SCAN, current_time( 'mysql' ), false );

        DHC_Event_Logger::log( 'link_scan', array(
            'posts_scanned' => count( $posts ),
            'urls_checked'  => count( $checked_urls ),
            'broken_found'  => count( $broken ),
        ) );
    }

    /** Extract all http(s) URLs from a post's content */
    private static function extract_urls( $html ) {
        $urls = array();
        if ( preg_match_all( '~<a\s[^>]*href=["\']([^"\']+)["\']~i', $html, $matches ) ) {
            foreach ( $matches[1] as $u ) {
                $u = trim( $u );
                if ( stripos( $u, 'http' ) !== 0 ) continue;
                if ( strlen( $u ) > 500 ) continue;
                // Skip common fragment-only / mailto / tel / javascript
                if ( preg_match( '~^(mailto:|tel:|javascript:)~i', $u ) ) continue;
                $urls[] = $u;
            }
        }
        return array_unique( $urls );
    }

    /** HEAD-ping a URL, fallback to GET if HEAD returns 405. Returns numeric status code or 0 on connect failure. */
    private static function check_url( $url ) {
        $res = wp_remote_head( $url, array(
            'timeout'     => self::SCAN_TIMEOUT,
            'redirection' => 5,
            'sslverify'   => false,
            'user-agent'  => 'DsquaredHubBot/1.0 (+https://hub.dsquaredmedia.net)',
        ) );
        if ( is_wp_error( $res ) ) return 0;
        $code = (int) wp_remote_retrieve_response_code( $res );
        // Some servers reject HEAD with 405 / 501 — retry with GET
        if ( $code === 405 || $code === 501 ) {
            $res = wp_remote_get( $url, array(
                'timeout'     => self::SCAN_TIMEOUT,
                'redirection' => 5,
                'sslverify'   => false,
                'user-agent'  => 'DsquaredHubBot/1.0 (+https://hub.dsquaredmedia.net)',
            ) );
            if ( is_wp_error( $res ) ) return 0;
            $code = (int) wp_remote_retrieve_response_code( $res );
        }
        return $code;
    }

    public static function get_broken_links( $limit = 100 ) {
        $rows = get_option( self::OPT_BROKEN_LINKS, array() );
        return array_slice( $rows, 0, (int) $limit );
    }

    public static function get_last_scan() {
        return get_option( self::OPT_LAST_SCAN, null );
    }

    /** Manual trigger — used by the admin page "Scan now" button */
    public static function run_now() {
        self::run_weekly_scan();
    }

    /** REST endpoint: /dsquared-hub/v1/link-scan (GET) */
    public static function handle_list_request( $request ) {
        if ( ! DHC_API_Key::is_module_available( 'site_health' ) ) {
            return new WP_Error(
                'dhc_module_unavailable',
                'Link Scanner is part of the Site Health module. Upgrade to Growth or Pro.',
                array( 'status' => 403 )
            );
        }
        return new WP_REST_Response( array(
            'last_scan'    => self::get_last_scan(),
            'broken_links' => self::get_broken_links( 200 ),
            'top_404s'     => self::get_404s( 50 ),
        ), 200 );
    }

    // ───── ADMIN SUB-PAGE RENDERER ────────────────────────────

    public static function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        // Handle form actions (nonce-protected)
        if ( isset( $_POST['dhc_link_action'] ) && check_admin_referer( 'dhc_link_scanner' ) ) {
            $act = sanitize_key( $_POST['dhc_link_action'] );
            if ( $act === 'scan_now' ) {
                self::run_now();
                echo '<div class="notice notice-success is-dismissible"><p>Scan complete. Results refreshed below.</p></div>';
            } elseif ( $act === 'clear_404s' ) {
                self::clear_404s();
                echo '<div class="notice notice-success is-dismissible"><p>404 log cleared.</p></div>';
            }
        }

        $broken    = self::get_broken_links( 100 );
        $four_oh_fours = self::get_404s( 50 );
        $last_scan = self::get_last_scan();
        ?>
        <div class="wrap dhc-wrap">
            <div class="dhc-header">
                <div class="dhc-header-left">
                    <div class="dhc-logo"><div class="dhc-logo-icon" style="display:flex;align-items:center;justify-content:center;"><span class="dashicons dashicons-admin-links" style="color:#4f46e5;font-size:22px;"></span></div></div>
                    <div>
                        <h1 class="dhc-title">Link Scanner</h1>
                        <div class="dhc-version">Broken links + 404s · updated <?php echo esc_html( $last_scan ?: 'never' ); ?></div>
                    </div>
                </div>
                <div class="dhc-header-right">
                    <form method="post" style="margin:0;">
                        <?php wp_nonce_field( 'dhc_link_scanner' ); ?>
                        <input type="hidden" name="dhc_link_action" value="scan_now">
                        <button type="submit" class="dhc-btn dhc-btn-primary">Scan now</button>
                    </form>
                </div>
            </div>

            <div class="dhc-card">
                <div class="dhc-card-header"><h2>Broken links found on your site</h2></div>
                <div class="dhc-card-body">
                    <?php if ( empty( $broken ) ) : ?>
                        <p style="color:#64748b;margin:0;">No broken links detected yet. Click <strong>Scan now</strong> to run the first crawl — it checks up to 200 recently-modified posts and follows every external link.</p>
                    <?php else : ?>
                        <table class="widefat striped" style="border:0;">
                            <thead>
                                <tr>
                                    <th>URL</th>
                                    <th style="width:90px;">Status</th>
                                    <th>Found on</th>
                                    <th style="width:130px;">Last checked</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $broken as $b ) : ?>
                                    <tr>
                                        <td style="word-break:break-all;"><a href="<?php echo esc_url( $b['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $b['url'] ); ?></a></td>
                                        <td><strong style="color:#dc2626;"><?php echo esc_html( $b['status'] ? $b['status'] : 'no response' ); ?></strong></td>
                                        <td><a href="<?php echo esc_url( get_edit_post_link( $b['source_post_id'] ) ); ?>"><?php echo esc_html( $b['source_title'] ?? '—' ); ?></a></td>
                                        <td style="color:#64748b;"><?php echo esc_html( $b['last_checked'] ?? '' ); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <div class="dhc-card">
                <div class="dhc-card-header">
                    <h2>404 errors from real visitors</h2>
                    <form method="post" style="margin:0;">
                        <?php wp_nonce_field( 'dhc_link_scanner' ); ?>
                        <input type="hidden" name="dhc_link_action" value="clear_404s">
                        <button type="submit" class="dhc-btn dhc-btn-icon" title="Clear the log">Clear</button>
                    </form>
                </div>
                <div class="dhc-card-body">
                    <?php if ( empty( $four_oh_fours ) ) : ?>
                        <p style="color:#64748b;margin:0;">No 404s logged yet. Real-user 404s will appear here — bots are filtered out. Requests with a referring URL point you at the source that's sending traffic to a dead page (usually an external site with a stale link you can reach out to).</p>
                    <?php else : ?>
                        <table class="widefat striped" style="border:0;">
                            <thead>
                                <tr>
                                    <th>Path</th>
                                    <th style="width:70px;">Hits</th>
                                    <th>Referer</th>
                                    <th style="width:130px;">Last seen</th>
                                    <th style="width:120px;">Fix</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $redirects = self::get_redirects();
                                $redirected_paths = array();
                                foreach ( $redirects as $r ) { if ( ! empty( $r['from'] ) ) $redirected_paths[ rtrim( strtolower( $r['from'] ), '/' ) ] = $r; }
                                foreach ( $four_oh_fours as $row ) :
                                    $norm_path = rtrim( strtolower( $row['path'] ), '/' );
                                    $has_redirect = isset( $redirected_paths[ $norm_path ] );
                                ?>
                                    <tr>
                                        <td style="word-break:break-all;"><code><?php echo esc_html( $row['path'] ); ?></code></td>
                                        <td><strong><?php echo esc_html( (int) ( $row['count'] ?? 1 ) ); ?></strong></td>
                                        <td style="word-break:break-all;max-width:300px;"><?php echo $row['referer'] ? '<a href="' . esc_url( $row['referer'] ) . '" target="_blank" rel="noopener">' . esc_html( $row['referer'] ) . '</a>' : '<em style="color:#9ca3af;">direct / typed</em>'; ?></td>
                                        <td style="color:#64748b;"><?php echo esc_html( $row['last_seen'] ?? '' ); ?></td>
                                        <td>
                                            <?php if ( $has_redirect ) : ?>
                                                <span style="color:#10b981;font-weight:600;font-size:12px;">✓ Redirected</span>
                                            <?php else : ?>
                                                <button type="button" class="dhc-btn dhc-btn-outline dhc-redirect-btn" data-path="<?php echo esc_attr( $row['path'] ); ?>" style="padding:4px 10px;font-size:12px;">+ Add 301</button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ── Active Redirects ── -->
            <div class="dhc-card">
                <div class="dhc-card-header">
                    <h2>Active Redirects</h2>
                    <p class="dhc-card-desc" style="margin:0;font-size:13px;">Any 301/302 redirects you add here fire on the very next visit — no server config or .htaccess edits required.</p>
                </div>
                <div class="dhc-card-body">
                    <div id="dhc-redirect-new" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-bottom:16px;padding-bottom:16px;border-bottom:1px solid #f1f5f9;">
                        <div style="flex:1;min-width:200px;">
                            <label style="display:block;font-size:12px;font-weight:600;color:#64748b;margin-bottom:4px;">From path</label>
                            <input type="text" id="dhc-redirect-from" class="dhc-input" placeholder="/old-url/" style="width:100%;">
                        </div>
                        <div style="flex:2;min-width:240px;">
                            <label style="display:block;font-size:12px;font-weight:600;color:#64748b;margin-bottom:4px;">To URL</label>
                            <input type="text" id="dhc-redirect-to" class="dhc-input" placeholder="https://example.com/new-url/ or /new-url/" style="width:100%;">
                        </div>
                        <div>
                            <label style="display:block;font-size:12px;font-weight:600;color:#64748b;margin-bottom:4px;">Code</label>
                            <select id="dhc-redirect-status" class="dhc-input" style="padding:8px 10px;">
                                <option value="301">301 Permanent</option>
                                <option value="302">302 Temporary</option>
                                <option value="307">307 Temporary (strict)</option>
                                <option value="308">308 Permanent (strict)</option>
                            </select>
                        </div>
                        <button type="button" id="dhc-redirect-add-btn" class="dhc-btn dhc-btn-primary">Add redirect</button>
                    </div>
                    <div id="dhc-redirect-msg" style="margin-bottom:10px;"></div>
                    <?php $redirects = self::get_redirects(); if ( empty( $redirects ) ) : ?>
                        <p style="color:#64748b;margin:0;">No redirects yet. Add one above, or click "+ Add 301" on any 404 row to auto-fill.</p>
                    <?php else : ?>
                        <table class="widefat striped" style="border:0;">
                            <thead>
                                <tr>
                                    <th>From</th>
                                    <th>To</th>
                                    <th style="width:70px;">Code</th>
                                    <th style="width:70px;">Hits</th>
                                    <th style="width:110px;">Added</th>
                                    <th style="width:90px;"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( array_reverse( $redirects ) as $r ) : ?>
                                    <tr>
                                        <td style="word-break:break-all;"><code><?php echo esc_html( $r['from'] ); ?></code></td>
                                        <td style="word-break:break-all;"><a href="<?php echo esc_url( $r['to'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $r['to'] ); ?></a></td>
                                        <td><strong><?php echo esc_html( (int) ( $r['status'] ?? 301 ) ); ?></strong></td>
                                        <td><?php echo esc_html( (int) ( $r['hits'] ?? 0 ) ); ?></td>
                                        <td style="color:#64748b;"><?php echo esc_html( substr( (string) ( $r['created_at'] ?? '' ), 0, 10 ) ); ?></td>
                                        <td>
                                            <button type="button" class="dhc-btn dhc-btn-outline dhc-redirect-delete" data-id="<?php echo esc_attr( $r['id'] ?? '' ); ?>" style="padding:4px 10px;font-size:12px;color:#dc2626;border-color:#fecaca;">Delete</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <script>
        (function(){
            var nonce = <?php echo wp_json_encode( wp_create_nonce( 'dhc_link_scanner' ) ); ?>;
            function post(action, data){
                var body = new URLSearchParams();
                body.set('action', action);
                body.set('nonce', nonce);
                Object.keys(data || {}).forEach(function(k){ body.set(k, data[k]); });
                return fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: body }).then(function(r){ return r.json(); });
            }
            function toast(msg, kind){
                var el = document.getElementById('dhc-redirect-msg');
                if (!el) return;
                el.innerHTML = '<div style="padding:10px 14px;border-radius:10px;font-size:13px;' + (kind === 'error' ? 'background:#fef2f2;border:1px solid #fecaca;color:#991b1b;' : 'background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;') + '">' + msg + '</div>';
                setTimeout(function(){ el.innerHTML = ''; }, 4000);
            }
            // Pre-fill from "+ Add 301" on a 404 row
            document.querySelectorAll('.dhc-redirect-btn').forEach(function(btn){
                btn.addEventListener('click', function(){
                    var p = btn.getAttribute('data-path') || '';
                    var from = document.getElementById('dhc-redirect-from');
                    if (from) from.value = p;
                    var to = document.getElementById('dhc-redirect-to');
                    if (to) to.focus();
                    if (from) from.scrollIntoView({ behavior: 'smooth', block: 'center' });
                });
            });
            var addBtn = document.getElementById('dhc-redirect-add-btn');
            if (addBtn) addBtn.addEventListener('click', function(){
                var from   = document.getElementById('dhc-redirect-from').value.trim();
                var to     = document.getElementById('dhc-redirect-to').value.trim();
                var status = document.getElementById('dhc-redirect-status').value;
                if (!from || !to) { toast('Enter both a From path and a To URL.', 'error'); return; }
                addBtn.disabled = true;
                post('dhc_redirect_add', { from: from, to: to, status: status }).then(function(res){
                    addBtn.disabled = false;
                    if (res && res.success) { toast('Redirect created. Refreshing…'); setTimeout(function(){ location.reload(); }, 900); }
                    else toast((res && res.data && res.data.message) || 'Could not add redirect.', 'error');
                }).catch(function(e){ addBtn.disabled = false; toast('Network error: ' + e.message, 'error'); });
            });
            document.querySelectorAll('.dhc-redirect-delete').forEach(function(btn){
                btn.addEventListener('click', function(){
                    if (!confirm('Delete this redirect?')) return;
                    var id = btn.getAttribute('data-id') || '';
                    post('dhc_redirect_delete', { id: id }).then(function(res){
                        if (res && res.success) { location.reload(); }
                        else toast((res && res.data && res.data.message) || 'Could not delete.', 'error');
                    });
                });
            });
        })();
        </script>
        <?php
    }
}
