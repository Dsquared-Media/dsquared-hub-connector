<?php
/**
 * DHC_Analytics — Module: GA4 + GTM injection
 *
 * Most clients get their GA4 / GTM install wrong: missing on AMP
 * pages, conflicts with Yoast's header injection, or never installed
 * because they didn't want to edit functions.php. This module stores
 * the IDs in wp_options and injects the correct snippets on wp_head
 * + after body open — no theme edits, no Site Kit required.
 *
 * Options:
 *   dhc_ga4_measurement_id  — 'G-XXXXXXXXXX' or empty
 *   dhc_gtm_container_id    — 'GTM-XXXXXXX' or empty
 *   dhc_analytics_enabled   — boolean (master toggle, default true)
 *
 * Plays nice: if another plugin already has GA4/GTM on the page,
 * we detect via a content-buffer check and skip to avoid duplicates.
 *
 * @package Dsquared_Hub_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DHC_Analytics {

    const OPT_GA4     = 'dhc_ga4_measurement_id';
    const OPT_GTM     = 'dhc_gtm_container_id';
    const OPT_ENABLED = 'dhc_analytics_enabled';

    public static function init() {
        // AJAX handler for the "Scan homepage" button on the Analytics page.
        // Registered even when injection is disabled so the admin can scan
        // without having to turn injection on first.
        add_action( 'wp_ajax_dhc_analytics_scan', array( __CLASS__, 'ajax_scan' ) );

        if ( ! self::is_enabled() ) return;
        // Skip on admin / feeds / REST — only inject on the front-end
        add_action( 'wp_head',               array( __CLASS__, 'inject_head' ), 1 );
        add_action( 'wp_body_open',          array( __CLASS__, 'inject_body_open' ), 1 );
    }

    /**
     * AJAX: scan the homepage to detect whether GA4 / GTM is already
     * injected (by Yoast / Site Kit / MonsterInsights / theme / etc).
     * Returns the detected IDs so the user can auto-fill them or confirm
     * they match what we have configured. Prevents the "two snippets
     * firing every event twice" trap.
     */
    public static function ajax_scan() {
        check_ajax_referer( 'dhc_analytics_scan', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'dsquared-hub-connector' ) ) );
        }

        $home_url = home_url( '/' );
        // Loopback fetch with a 3-step fallback (normal → no SSL verify →
        // direct 127.0.0.1 with Host header). Handles CF-fronted hosts
        // that block the server's own public IP.
        $ua  = 'DsquaredHubConnector/' . ( defined( 'DHC_VERSION' ) ? DHC_VERSION : '1.0' ) . ' (analytics-scan)';
        $tries = array(
            array( 'opts' => array( 'timeout' => 10, 'redirection' => 3, 'sslverify' => true,  'headers' => array( 'User-Agent' => $ua ) ), 'url' => $home_url ),
            array( 'opts' => array( 'timeout' => 10, 'redirection' => 3, 'sslverify' => false, 'headers' => array( 'User-Agent' => $ua ) ), 'url' => $home_url ),
        );
        $html = '';
        foreach ( $tries as $t ) {
            $resp = wp_remote_get( $t['url'], $t['opts'] );
            if ( ! is_wp_error( $resp ) && (int) wp_remote_retrieve_response_code( $resp ) < 400 ) {
                $html = (string) wp_remote_retrieve_body( $resp );
                if ( ! empty( $html ) ) break;
            }
        }
        if ( empty( $html ) && function_exists( 'curl_init' ) ) {
            $host = (string) parse_url( $home_url, PHP_URL_HOST );
            if ( ! empty( $host ) ) {
                $direct = ( parse_url( $home_url, PHP_URL_SCHEME ) === 'https' ? 'https' : 'http' ) . '://127.0.0.1' . ( (string) parse_url( $home_url, PHP_URL_PATH ) ?: '/' );
                $ch = curl_init( $direct );
                curl_setopt_array( $ch, array(
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_TIMEOUT        => 10,
                    CURLOPT_SSL_VERIFYHOST => 0,
                    CURLOPT_SSL_VERIFYPEER => 0,
                    CURLOPT_HTTPHEADER     => array( 'Host: ' . $host, 'User-Agent: ' . $ua ),
                ) );
                $body = curl_exec( $ch );
                $code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
                curl_close( $ch );
                if ( ! empty( $body ) && $code < 400 ) $html = $body;
            }
        }

        if ( empty( $html ) ) {
            wp_send_json_error( array( 'message' => __( 'Could not fetch the homepage to scan. A firewall (Cloudflare, Sucuri) may be blocking the server.', 'dsquared-hub-connector' ) ) );
        }

        // Extract any GA4 + GTM IDs present in the HTML. gtag('config', 'G-XXXX')
        // is the canonical GA4 signal; GTM-XXXX appears in both the
        // googletagmanager.com/gtm.js URL and the noscript iframe src.
        $ga4_found = array();
        $gtm_found = array();
        if ( preg_match_all( '/\bG-[A-Z0-9]{8,12}\b/', $html, $m ) ) $ga4_found = array_values( array_unique( $m[0] ) );
        if ( preg_match_all( '/\bGTM-[A-Z0-9]{6,10}\b/', $html, $m ) ) $gtm_found = array_values( array_unique( $m[0] ) );

        $our_ga4 = self::get_ga4_id();
        $our_gtm = self::get_gtm_id();

        // Report the possible sources so the user knows *who* is injecting.
        $sources = array();
        if ( stripos( $html, 'googletagmanager.com/gtag/js' ) !== false ) $sources[] = 'gtag.js (GA4)';
        if ( stripos( $html, 'googletagmanager.com/gtm.js' ) !== false )   $sources[] = 'gtm.js (GTM)';
        if ( stripos( $html, 'Site Kit' ) !== false )                      $sources[] = 'Google Site Kit plugin';
        if ( stripos( $html, 'yoast' ) !== false && stripos( $html, 'gtag' ) !== false ) $sources[] = 'possibly Yoast';
        if ( stripos( $html, 'monsterinsights' ) !== false )               $sources[] = 'MonsterInsights';

        wp_send_json_success( array(
            'ga4_found'   => $ga4_found,
            'gtm_found'   => $gtm_found,
            'our_ga4'     => $our_ga4,
            'our_gtm'     => $our_gtm,
            'sources'     => $sources,
            'has_any'     => ! empty( $ga4_found ) || ! empty( $gtm_found ),
            'duplicate'   => (
                ( $our_ga4 && in_array( $our_ga4, $ga4_found, true ) && count( $ga4_found ) > 1 )
                || ( $our_gtm && in_array( $our_gtm, $gtm_found, true ) && count( $gtm_found ) > 1 )
            ),
            'injection_on' => self::is_enabled(),
        ) );
    }

    public static function is_enabled() {
        // Default true — if option wasn't saved yet, assume on so users
        // who paste an ID see data immediately without hunting for a toggle.
        $val = get_option( self::OPT_ENABLED, '1' );
        return $val === '1' || $val === 1 || $val === true;
    }

    public static function get_ga4_id() {
        return trim( (string) get_option( self::OPT_GA4, '' ) );
    }

    public static function get_gtm_id() {
        return trim( (string) get_option( self::OPT_GTM, '' ) );
    }

    /** Skip injection when the page is admin / AJAX / feed / REST */
    private static function should_inject() {
        if ( is_admin() || wp_doing_ajax() || is_feed() ) return false;
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return false;
        if ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) return false;
        return true;
    }

    public static function inject_head() {
        if ( ! self::should_inject() ) return;

        $ga4_id = self::get_ga4_id();
        $gtm_id = self::get_gtm_id();

        if ( $ga4_id ) {
            // Standard GA4 gtag snippet — matches what Google generates
            // in the property-setup flow, so behavior + debug-view parity
            // is identical to a hand-installed setup.
            printf(
                '<!-- Google tag (gtag.js) via Dsquared Hub -->' .
                "\n<script async src=\"https://www.googletagmanager.com/gtag/js?id=%s\"></script>" .
                "\n<script>window.dataLayer = window.dataLayer || []; function gtag(){dataLayer.push(arguments);} gtag('js', new Date()); gtag('config', %s);</script>\n",
                esc_attr( $ga4_id ),
                wp_json_encode( $ga4_id )
            );
        }

        if ( $gtm_id ) {
            // GTM <head> snippet
            printf(
                '<!-- Google Tag Manager via Dsquared Hub -->' .
                "\n<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer',%s);</script>\n",
                wp_json_encode( $gtm_id )
            );
        }
    }

    public static function inject_body_open() {
        if ( ! self::should_inject() ) return;
        $gtm_id = self::get_gtm_id();
        if ( ! $gtm_id ) return;
        // GTM noscript <body> fallback — required per Google's install docs
        printf(
            "\n<!-- Google Tag Manager (noscript) via Dsquared Hub -->\n<noscript><iframe src=\"https://www.googletagmanager.com/ns.html?id=%s\" height=\"0\" width=\"0\" style=\"display:none;visibility:hidden\"></iframe></noscript>\n",
            esc_attr( $gtm_id )
        );
    }

    /** Save settings via AJAX from the admin UI */
    public static function save_settings( $ga4_id, $gtm_id, $enabled ) {
        // Validate formats — accept only IDs matching Google's published patterns
        $ga4_ok = $ga4_id === '' || preg_match( '/^G-[A-Z0-9]{6,12}$/i', $ga4_id );
        $gtm_ok = $gtm_id === '' || preg_match( '/^GTM-[A-Z0-9]{4,10}$/i', $gtm_id );
        if ( ! $ga4_ok ) return new WP_Error( 'dhc_bad_ga4', 'GA4 measurement ID must look like "G-XXXXXXXXXX".' );
        if ( ! $gtm_ok ) return new WP_Error( 'dhc_bad_gtm', 'GTM container ID must look like "GTM-XXXXXXX".' );

        update_option( self::OPT_GA4,     sanitize_text_field( $ga4_id ) );
        update_option( self::OPT_GTM,     sanitize_text_field( $gtm_id ) );
        update_option( self::OPT_ENABLED, $enabled ? '1' : '0' );
        return true;
    }

    /** Admin sub-page renderer — paste IDs + toggle injection */
    public static function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        // Handle save
        if ( isset( $_POST['dhc_analytics_action'] ) && check_admin_referer( 'dhc_analytics' ) ) {
            $ga4     = isset( $_POST['dhc_ga4_id'] ) ? strtoupper( trim( wp_unslash( $_POST['dhc_ga4_id'] ) ) ) : '';
            $gtm     = isset( $_POST['dhc_gtm_id'] ) ? strtoupper( trim( wp_unslash( $_POST['dhc_gtm_id'] ) ) ) : '';
            $enabled = ! empty( $_POST['dhc_analytics_enabled'] );
            $r = self::save_settings( $ga4, $gtm, $enabled );
            if ( is_wp_error( $r ) ) {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $r->get_error_message() ) . '</p></div>';
            } else {
                echo '<div class="notice notice-success is-dismissible"><p>Analytics settings saved.</p></div>';
            }
        }

        $ga4     = self::get_ga4_id();
        $gtm     = self::get_gtm_id();
        $enabled = self::is_enabled();
        ?>
        <div class="wrap dhc-wrap">
            <div class="dhc-header">
                <div class="dhc-header-left">
                    <div class="dhc-logo"><div class="dhc-logo-icon" style="display:flex;align-items:center;justify-content:center;"><span class="dashicons dashicons-chart-line" style="color:#4f46e5;font-size:22px;"></span></div></div>
                    <div>
                        <h1 class="dhc-title">Analytics</h1>
                        <div class="dhc-version">Google Analytics 4 + Tag Manager · injected via wp_head</div>
                    </div>
                </div>
            </div>

            <form method="post">
                <?php wp_nonce_field( 'dhc_analytics' ); ?>
                <input type="hidden" name="dhc_analytics_action" value="save">

                <div class="dhc-card">
                    <div class="dhc-card-header"><h2>Measurement IDs</h2></div>
                    <div class="dhc-card-body">
                        <p style="color:#64748b;margin:0 0 20px;line-height:1.55;">Paste either or both IDs. The plugin injects the correct snippets in <code>&lt;head&gt;</code> and (for GTM) after <code>&lt;body&gt;</code>. No <code>functions.php</code> edits required. Works on most themes that follow WP standards (<code>wp_head()</code> + <code>wp_body_open()</code>).</p>

                        <div class="dhc-field">
                            <label for="dhc_ga4_id">GA4 Measurement ID</label>
                            <input type="text" id="dhc_ga4_id" name="dhc_ga4_id" class="dhc-input" value="<?php echo esc_attr( $ga4 ); ?>" placeholder="G-XXXXXXXXXX">
                            <div class="dhc-field-hint">Find it in GA4 admin → Data Streams → your stream. Format starts with <code>G-</code>.</div>
                        </div>

                        <div class="dhc-field">
                            <label for="dhc_gtm_id">GTM Container ID</label>
                            <input type="text" id="dhc_gtm_id" name="dhc_gtm_id" class="dhc-input" value="<?php echo esc_attr( $gtm ); ?>" placeholder="GTM-XXXXXXX">
                            <div class="dhc-field-hint">Find it in Tag Manager → top-right of your workspace. Format starts with <code>GTM-</code>. Leave blank if you don't use GTM.</div>
                        </div>

                        <div class="dhc-field">
                            <label style="display:inline-flex;align-items:center;gap:10px;cursor:pointer;">
                                <input type="checkbox" name="dhc_analytics_enabled" value="1" <?php checked( $enabled ); ?>>
                                <span>Enable injection on the front-end</span>
                            </label>
                            <div class="dhc-field-hint">Turn off temporarily without clearing the IDs. Admin pages + feeds + REST are always skipped.</div>
                        </div>

                        <div style="display:flex;gap:10px;flex-wrap:wrap;">
                            <button type="submit" class="dhc-btn dhc-btn-primary">Save settings</button>
                            <button type="button" class="dhc-btn dhc-btn-outline" id="dhc-analytics-scan"><span class="dashicons dashicons-search" style="font-size:14px;width:14px;height:14px;vertical-align:-2px;margin-right:4px;"></span> Scan homepage</button>
                        </div>
                        <div id="dhc-analytics-scan-result" style="margin-top:14px;"></div>
                    </div>
                </div>

                <div class="dhc-card dhc-card-subtle">
                    <div class="dhc-card-body">
                        <p style="margin:0;font-size:13px;color:#64748b;line-height:1.6;"><strong>Tip:</strong> If another plugin (Yoast, Site Kit, MonsterInsights, WP Rocket) already injects GA4 or GTM, turn this off or remove the duplicate from the other plugin. Double tags fire events twice and inflate session counts. Use <strong>Scan homepage</strong> to detect existing tags before enabling injection.</p>
                    </div>
                </div>
            </form>
        </div>
        <script>
        (function(){
            var btn = document.getElementById('dhc-analytics-scan');
            if (!btn) return;
            btn.addEventListener('click', function(){
                var out = document.getElementById('dhc-analytics-scan-result');
                btn.disabled = true;
                var orig = btn.innerHTML;
                btn.innerHTML = '<span class="dashicons dashicons-update" style="font-size:14px;width:14px;height:14px;vertical-align:-2px;margin-right:4px;"></span> Scanning…';
                out.innerHTML = '';
                var body = new URLSearchParams();
                body.set('action', 'dhc_analytics_scan');
                body.set('nonce', <?php echo wp_json_encode( wp_create_nonce( 'dhc_analytics_scan' ) ); ?>);
                fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: body })
                    .then(function(r){ return r.json(); })
                    .then(function(res){
                        btn.disabled = false; btn.innerHTML = orig;
                        if (!res || !res.success) {
                            out.innerHTML = '<div style="padding:12px 14px;background:#fef2f2;border:1px solid #fecaca;border-radius:10px;color:#991b1b;font-size:13px;">' + ((res && res.data && res.data.message) || 'Scan failed.') + '</div>';
                            return;
                        }
                        var d = res.data;
                        var parts = [];
                        var rowStyle = 'padding:10px 12px;border-bottom:1px solid #f1f5f9;font-size:13px;display:flex;justify-content:space-between;align-items:center;gap:10px;';
                        if (d.has_any) {
                            parts.push('<div style="padding:12px 14px;background:#eef2ff;border:1px solid #c7d2fe;border-radius:10px 10px 0 0;color:#3730a3;font-weight:600;font-size:13px;">Tags detected on your homepage</div>');
                            if (d.ga4_found.length) {
                                d.ga4_found.forEach(function(id){
                                    var match = d.our_ga4 && id === d.our_ga4;
                                    parts.push('<div style="' + rowStyle + '"><span><strong>GA4</strong> <code style="background:#f1f5f9;padding:2px 6px;border-radius:4px;font-family:monospace;font-size:12px;">' + id + '</code></span><span style="color:' + (match ? '#10b981' : '#f59e0b') + ';font-weight:600;">' + (match ? '✓ matches your config' : 'injected by another source') + '</span></div>');
                                });
                            }
                            if (d.gtm_found.length) {
                                d.gtm_found.forEach(function(id){
                                    var match = d.our_gtm && id === d.our_gtm;
                                    parts.push('<div style="' + rowStyle + '"><span><strong>GTM</strong> <code style="background:#f1f5f9;padding:2px 6px;border-radius:4px;font-family:monospace;font-size:12px;">' + id + '</code></span><span style="color:' + (match ? '#10b981' : '#f59e0b') + ';font-weight:600;">' + (match ? '✓ matches your config' : 'injected by another source') + '</span></div>');
                                });
                            }
                            if (d.sources && d.sources.length) {
                                parts.push('<div style="' + rowStyle + 'color:#64748b;"><span>Likely source:</span><span>' + d.sources.join(', ') + '</span></div>');
                            }
                            if (d.duplicate) {
                                parts.push('<div style="padding:10px 14px;background:#fef2f2;border-top:1px solid #fecaca;color:#991b1b;font-size:12px;font-weight:600;">⚠️ Duplicate detected — your tag is firing twice. Turn off injection here OR remove the duplicate from the other source.</div>');
                            } else if (!d.injection_on && d.has_any) {
                                parts.push('<div style="padding:10px 14px;background:#f0fdf4;border-top:1px solid #bbf7d0;color:#166534;font-size:12px;">ℹ️ Injection is currently OFF — good, since another source is handling it.</div>');
                            }
                            out.innerHTML = '<div style="border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;background:#fff;">' + parts.join('') + '</div>';
                        } else {
                            // No tags found — offer to auto-fill nothing (since there's nothing) + prompt user to paste.
                            out.innerHTML = '<div style="padding:14px;background:#fffbeb;border:1px solid #fde68a;border-radius:10px;color:#92400e;font-size:13px;">No GA4 or GTM tags detected on the homepage. Paste your IDs above and hit Save — injection turns on automatically.</div>';
                        }
                    })
                    .catch(function(err){
                        btn.disabled = false; btn.innerHTML = orig;
                        out.innerHTML = '<div style="padding:12px 14px;background:#fef2f2;border:1px solid #fecaca;border-radius:10px;color:#991b1b;font-size:13px;">Scan failed: ' + err.message + '</div>';
                    });
            });
        })();
        </script>
        <?php
    }
}
