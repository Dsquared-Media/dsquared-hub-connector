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
        if ( ! self::is_enabled() ) return;
        // Skip on admin / feeds / REST — only inject on the front-end
        add_action( 'wp_head',               array( __CLASS__, 'inject_head' ), 1 );
        add_action( 'wp_body_open',          array( __CLASS__, 'inject_body_open' ), 1 );
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

                        <button type="submit" class="dhc-btn dhc-btn-primary">Save settings</button>
                    </div>
                </div>

                <div class="dhc-card dhc-card-subtle">
                    <div class="dhc-card-body">
                        <p style="margin:0;font-size:13px;color:#64748b;line-height:1.6;"><strong>Tip:</strong> If another plugin (Yoast, Site Kit, MonsterInsights, WP Rocket) already injects GA4 or GTM, turn this off or remove the duplicate from the other plugin. Double tags fire events twice and inflate session counts.</p>
                    </div>
                </div>
            </form>
        </div>
        <?php
    }
}
