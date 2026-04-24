<?php
/**
 * DHC_Dashboard — Module: Site Kit-style at-a-glance dashboard in WP admin
 *
 * Fetches a single aggregated payload from the Hub's /api/plugin/dashboard
 * endpoint (authed via X-DHC-API-Key) and renders it as KPI tiles +
 * tables inside WP admin. The whole point: a client logs into WordPress
 * and sees traffic numbers, top queries, recent wins, and AI activity
 * in ONE screen — no need to visit the Hub to feel the value.
 *
 * Cached plugin-side for 30 minutes via transient so we don't thrash
 * the Hub (which itself pings GSC + GA4 + DB). A "Refresh" button
 * busts the cache on demand.
 *
 * Admin sub-page: Dsquared Hub → Hub Dashboard (registered as the
 * FIRST sub-item, so it's the default landing after activation).
 *
 * @package Dsquared_Hub_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DHC_Dashboard {

    const CACHE_KEY = 'dhc_dashboard_cache';
    const CACHE_TTL = 30 * MINUTE_IN_SECONDS; // 30 min

    /**
     * Fetch the aggregated dashboard payload from the Hub.
     * Transient-cached so we don't pound the upstream every pageview.
     * Set $force_refresh = true to bypass cache (Refresh button).
     */
    public static function fetch( $force_refresh = false ) {
        // Respect a user-selected window. Each window gets its own
        // cache bucket so switching 7 ↔ 30 doesn't force a refetch of
        // the previously-shown window.
        $days = isset( $_GET['dhc_days'] ) ? max( 1, min( 365, (int) $_GET['dhc_days'] ) ) : 7;
        $cache_key = self::CACHE_KEY . '_d' . $days;

        if ( ! $force_refresh ) {
            $cached = get_transient( $cache_key );
            if ( is_array( $cached ) ) {
                $cached['_cache'] = 'hit';
                return $cached;
            }
        }

        $api_key = get_option( 'dhc_api_key', '' );
        if ( empty( $api_key ) ) {
            return array(
                'connected'  => false,
                '_error'     => 'No API key configured. Paste it on the Connection tab first.',
            );
        }

        $hub_url = defined( 'DHC_HUB_API_BASE' ) ? DHC_HUB_API_BASE : 'https://hub.dsquaredmedia.net/api';
        $url     = rtrim( $hub_url, '/' ) . '/plugin/dashboard?site_url=' . rawurlencode( home_url( '/' ) ) . '&days=' . $days;

        $res = wp_remote_get( $url, array(
            'timeout'   => 15,
            'headers'   => array(
                'X-DHC-API-Key'    => $api_key,
                'X-DHC-Site-Url'   => home_url( '/' ),
                'Accept'           => 'application/json',
            ),
            'sslverify' => true,
        ) );

        if ( is_wp_error( $res ) ) {
            return array(
                'connected' => false,
                '_error'    => 'Could not reach the Hub: ' . $res->get_error_message(),
            );
        }
        $code = wp_remote_retrieve_response_code( $res );
        $body = wp_remote_retrieve_body( $res );
        $data = json_decode( $body, true );

        if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
            return array(
                'connected' => false,
                '_error'    => 'Hub returned ' . $code . ': ' . substr( is_string( $body ) ? $body : '', 0, 200 ),
            );
        }

        // Only cache successful responses — error payloads should
        // retry on the next page load rather than wait 30 min.
        if ( ! empty( $data['connected'] ) ) {
            set_transient( $cache_key, $data, self::CACHE_TTL );
        }
        $data['_cache'] = 'miss';
        return $data;
    }

    public static function clear_cache() {
        // Clear every per-window bucket. Common windows enumerated.
        foreach ( array( 7, 14, 28, 30, 60, 90, 180, 365 ) as $d ) {
            delete_transient( self::CACHE_KEY . '_d' . $d );
        }
        delete_transient( self::CACHE_KEY ); // legacy key from v1.11
    }

    /** Admin sub-page renderer */
    public static function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $force = false;
        if ( isset( $_POST['dhc_dashboard_action'] ) && check_admin_referer( 'dhc_dashboard' ) ) {
            if ( $_POST['dhc_dashboard_action'] === 'refresh' ) {
                self::clear_cache();
                $force = true;
                echo '<div class="notice notice-success is-dismissible"><p>Refreshed from the Hub.</p></div>';
            }
        }

        $data = self::fetch( $force );
        ?>
        <?php
        $active_days = isset( $_GET['dhc_days'] ) ? max( 1, min( 365, (int) $_GET['dhc_days'] ) ) : 7;
        $period_label = ( $active_days === 7 ? 'Last 7 days' : 'Last ' . $active_days . ' days' );
        ?>
        <div class="wrap dhc-wrap">
            <div class="dhc-header">
                <div class="dhc-header-left">
                    <div class="dhc-logo">
                        <div class="dhc-logo-icon" style="display:flex;align-items:center;justify-content:center;">
                            <span class="dashicons dashicons-chart-area" style="color:#EC4899;font-size:22px;"></span>
                        </div>
                    </div>
                    <div>
                        <h1 class="dhc-title">Hub Dashboard</h1>
                        <div class="dhc-version">
                            <?php if ( ! empty( $data['connected'] ) && isset( $data['period'] ) ) : ?>
                                <?php echo esc_html( $period_label ); ?> · <?php echo esc_html( $data['period']['start'] ); ?> to <?php echo esc_html( $data['period']['end'] ); ?>
                            <?php else : ?>
                                Connect an API key to see your data
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="dhc-header-right" style="display:flex;align-items:center;gap:8px;">
                    <select id="dhc-period-select" style="padding:6px 10px;border-radius:6px;border:1px solid rgba(255,255,255,0.2);background:rgba(255,255,255,0.08);color:#fff;font-size:13px;cursor:pointer;"
                            onchange="(function(v){var u=new URL(location.href);u.searchParams.set('dhc_days',v);location.href=u.toString();})(this.value)">
                        <?php foreach ( array( 7, 14, 28, 30, 60, 90 ) as $d ) : ?>
                            <option value="<?php echo esc_attr( $d ); ?>" <?php selected( $active_days, $d ); ?>><?php echo $d === 7 ? 'Last 7 days' : 'Last ' . $d . ' days'; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <a class="dhc-btn dhc-btn-outline" href="https://hub.dsquaredmedia.net" target="_blank" rel="noopener">
                        Open Hub <span class="dashicons dashicons-external" style="font-size:14px;margin-left:2px;"></span>
                    </a>
                    <form method="post" style="margin:0;display:inline-flex;">
                        <?php wp_nonce_field( 'dhc_dashboard' ); ?>
                        <input type="hidden" name="dhc_dashboard_action" value="refresh">
                        <button type="submit" class="dhc-btn dhc-btn-primary">Refresh</button>
                    </form>
                </div>
            </div>

            <?php if ( empty( $data['connected'] ) ) : ?>
                <div class="dhc-card">
                    <div class="dhc-card-body">
                        <p style="margin:0;color:#64748b;"><?php echo esc_html( $data['_error'] ?? ( $data['message'] ?? 'Not connected to the Hub.' ) ); ?></p>
                        <p style="margin-top:14px;">
                            <a class="dhc-btn dhc-btn-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=dsquared-hub' ) ); ?>">Open Connection settings</a>
                        </p>
                    </div>
                </div>
            <?php else : ?>
                <?php self::render_blocks( $data ); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /** Render all data blocks (only non-empty ones) */
    private static function render_blocks( $data ) {
        // ── Block: KPI tiles ────────────────────────────
        if ( ! empty( $data['summary'] ) ) :
            $s = $data['summary'];
            $period_days = (int) ( $data['period']['days'] ?? 7 );
            $period_sub  = 'Last ' . $period_days . ' days';

            // Derived KPIs — computed from data the Hub already returned.
            $top_queries = is_array( $data['top_queries'] ?? null ) ? $data['top_queries'] : array();
            $top_pages   = is_array( $data['top_pages']   ?? null ) ? $data['top_pages']   : array();
            $traffic     = is_array( $data['traffic']     ?? null ) ? $data['traffic']     : array();

            $top3_kw  = count( array_filter( $top_queries, function( $q ) { return ( (float) ( $q['position'] ?? 99 ) ) <= 3.5; } ) );
            $top10_kw = count( array_filter( $top_queries, function( $q ) { return ( (float) ( $q['position'] ?? 99 ) ) <= 10.5; } ) );
            $pages_with_traffic = count( $top_pages );
            $sessions_total = array_sum( array_map( function( $p ) { return (int) ( $p['value'] ?? $p[1] ?? 0 ); }, $traffic ) );

            $ctr_gaps_count  = is_array( $data['ctr_gaps']   ?? null ) ? count( $data['ctr_gaps']   ) : 0;
            $drafts_count    = is_array( $data['drafts']     ?? null ) ? count( $data['drafts']     ) : 0;
            $watches_count   = is_array( $data['seo_watches']?? null ) ? count( $data['seo_watches']) : 0;

            // Best / worst pickers for "Best keyword" + "Fastest riser"
            $best_kw = null;
            $best_clicks = 0;
            foreach ( $top_queries as $q ) {
                $c = (int) ( $q['clicks'] ?? 0 );
                if ( $c > $best_clicks ) { $best_clicks = $c; $best_kw = $q; }
            }
            $best_page = null;
            $best_page_clicks = 0;
            foreach ( $top_pages as $p ) {
                $c = (int) ( $p['clicks'] ?? 0 );
                if ( $c > $best_page_clicks ) { $best_page_clicks = $c; $best_page = $p; }
            }

            // Device + source breakdown from the Hub payload (v1.12.3+).
            $device_top = is_array( $data['device_top'] ?? null ) ? $data['device_top'] : null;
            $source_top = is_array( $data['source_top'] ?? null ) ? $data['source_top'] : null;
            $country_top = is_array( $data['country_top'] ?? null ) ? $data['country_top'] : null;
        ?>
            <div class="dhc-dash-kpi-grid">
                <?php echo self::kpi_tile( 'Clicks',        self::num( $s['clicks_7d']      ?? 0 ), $period_sub ); ?>
                <?php echo self::kpi_tile( 'Impressions',   self::num( $s['impressions_7d'] ?? 0 ), $period_sub ); ?>
                <?php echo self::kpi_tile( 'Avg position',  number_format( (float) ( $s['avg_position'] ?? 0 ), 1 ), 'across all queries' ); ?>
                <?php echo self::kpi_tile( 'CTR',           ( $s['ctr_pct'] ?? 0 ) . '%', 'across all queries' ); ?>
                <?php if ( $top3_kw > 0 ) echo self::kpi_tile( 'Keywords in top 3',  self::num( $top3_kw ),  'of your top ' . count( $top_queries ) . ' by clicks' ); ?>
                <?php if ( $top10_kw > 0 ) echo self::kpi_tile( 'Keywords in top 10', self::num( $top10_kw ), 'of your top ' . count( $top_queries ) . ' by clicks' ); ?>
                <?php if ( $pages_with_traffic > 0 ) echo self::kpi_tile( 'Pages getting traffic', self::num( $pages_with_traffic ), $period_sub ); ?>
                <?php if ( $sessions_total > 0 ) echo self::kpi_tile( 'Sessions', self::num( $sessions_total ), $period_sub . ' (GA4)' ); ?>
                <?php if ( $best_kw ) echo self::kpi_tile( 'Top keyword', self::short( $best_kw['keyword'] ?? '', 22 ), self::num( $best_clicks ) . ' clicks · pos ' . number_format( (float) ( $best_kw['position'] ?? 0 ), 1 ) ); ?>
                <?php if ( $device_top && ! empty( $device_top['label'] ) ) echo self::kpi_tile( 'Top device', esc_html( $device_top['label'] ), ( $device_top['pct'] ?? 0 ) . '% of sessions' ); ?>
                <?php if ( $source_top && ! empty( $source_top['label'] ) ) echo self::kpi_tile( 'Top source', esc_html( $source_top['label'] ), ( $source_top['pct'] ?? 0 ) . '% of traffic' ); ?>
                <?php if ( $country_top && ! empty( $country_top['label'] ) ) echo self::kpi_tile( 'Top country', esc_html( $country_top['label'] ), ( $country_top['pct'] ?? 0 ) . '% of visitors' ); ?>
                <?php if ( $ctr_gaps_count > 0 ) echo self::kpi_tile( 'CTR quick wins', self::num( $ctr_gaps_count ), 'pages underperforming' ); ?>
                <?php if ( $watches_count > 0 ) echo self::kpi_tile( 'Tracked changes', self::num( $watches_count ), 'SEO Watch entries' ); ?>
                <?php if ( $drafts_count > 0 ) echo self::kpi_tile( 'Drafts ready', self::num( $drafts_count ), 'to review + publish' ); ?>
            </div>
        <?php endif; ?>

        <!-- ── Block: traffic trend ── -->
        <?php if ( ! empty( $data['traffic'] ) ) : ?>
            <div class="dhc-card">
                <div class="dhc-card-header"><h2>Traffic — last <?php echo (int) ( $data['period']['days'] ?? 14 ); ?> days</h2></div>
                <div class="dhc-card-body">
                    <?php echo self::render_sparkline( $data['traffic'] ); ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- ── Block: device + source donut charts (side-by-side) ── -->
        <?php
        $has_dev  = ! empty( $data['device_top']['list'] );
        $has_src  = ! empty( $data['source_top']['list'] );
        $has_cnt  = ! empty( $data['country_top']['list'] );
        if ( $has_dev || $has_src || $has_cnt ) :
        ?>
            <div class="dhc-dash-two-col">
                <?php if ( $has_dev ) : ?>
                    <div class="dhc-card">
                        <div class="dhc-card-header"><h2>Devices</h2></div>
                        <div class="dhc-card-body">
                            <?php echo self::render_donut( $data['device_top']['list'], array( '#6366F1', '#EC4899', '#F59E0B', '#10B981', '#8B5CF6' ) ); ?>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ( $has_src ) : ?>
                    <div class="dhc-card">
                        <div class="dhc-card-header"><h2>Traffic sources</h2></div>
                        <div class="dhc-card-body">
                            <?php echo self::render_donut( $data['source_top']['list'], array( '#4A6CF7', '#EC4899', '#8B5CF6', '#06B6D4', '#94A3B8' ) ); ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            <?php if ( $has_cnt ) : ?>
                <div class="dhc-card">
                    <div class="dhc-card-header"><h2>Top countries</h2></div>
                    <div class="dhc-card-body">
                        <?php echo self::render_hbar( $data['country_top']['list'], '#6366F1' ); ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- ── Block: CTR gaps (quick wins) ── -->
        <?php if ( ! empty( $data['ctr_gaps'] ) ) : ?>
            <div class="dhc-card">
                <div class="dhc-card-header">
                    <h2>Quick wins — underperforming CTR</h2>
                    <span style="font-size:12px;color:#9ca3af;">Title/meta rewrites likely fix these</span>
                </div>
                <div class="dhc-card-body" style="padding:0 28px 18px;">
                    <table class="widefat" style="border:0;">
                        <thead>
                            <tr>
                                <th>Keyword</th>
                                <th style="width:80px;">Position</th>
                                <th style="width:110px;">CTR now / expected</th>
                                <th style="width:130px;text-align:right;">Est. recovery</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $data['ctr_gaps'] as $g ) : ?>
                                <tr>
                                    <td style="font-weight:600;"><?php echo esc_html( $g['keyword'] ); ?></td>
                                    <td>#<?php echo esc_html( number_format( (float) $g['position'], 1 ) ); ?></td>
                                    <td style="color:#64748b;">
                                        <?php echo esc_html( $g['actual_ctr_pct'] ); ?>% /
                                        <strong style="color:#4f46e5;"><?php echo esc_html( $g['expected_ctr_pct'] ); ?>%</strong>
                                    </td>
                                    <td style="text-align:right;"><strong style="color:#4f46e5;">+<?php echo esc_html( number_format( (int) $g['lost_clicks_est'] ) ); ?> clicks/mo</strong></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- ── Block: Top queries + Top pages side-by-side ── -->
        <?php if ( ! empty( $data['top_queries'] ) || ! empty( $data['top_pages'] ) ) : ?>
            <div class="dhc-dash-two-col">
                <?php if ( ! empty( $data['top_queries'] ) ) : ?>
                    <div class="dhc-card">
                        <div class="dhc-card-header"><h2>Top search queries</h2></div>
                        <div class="dhc-card-body" style="padding:0 28px 18px;">
                            <table class="widefat" style="border:0;">
                                <thead><tr><th>Query</th><th style="width:60px;text-align:right;">Clicks</th><th style="width:60px;text-align:right;">Pos</th></tr></thead>
                                <tbody>
                                    <?php foreach ( $data['top_queries'] as $q ) : ?>
                                        <tr>
                                            <td><?php echo esc_html( $q['keyword'] ); ?></td>
                                            <td style="text-align:right;font-weight:700;"><?php echo esc_html( self::num( $q['clicks'] ) ); ?></td>
                                            <td style="text-align:right;color:#64748b;"><?php echo esc_html( number_format( (float) $q['position'], 1 ) ); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ( ! empty( $data['top_pages'] ) ) : ?>
                    <div class="dhc-card">
                        <div class="dhc-card-header"><h2>Top landing pages</h2></div>
                        <div class="dhc-card-body" style="padding:0 28px 18px;">
                            <table class="widefat" style="border:0;">
                                <thead><tr><th>Page</th><th style="width:60px;text-align:right;">Clicks</th><th style="width:60px;text-align:right;">Pos</th></tr></thead>
                                <tbody>
                                    <?php foreach ( $data['top_pages'] as $p ) : ?>
                                        <tr>
                                            <td style="word-break:break-all;"><a href="<?php echo esc_url( $p['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $p['path'] ); ?></a></td>
                                            <td style="text-align:right;font-weight:700;"><?php echo esc_html( self::num( $p['clicks'] ) ); ?></td>
                                            <td style="text-align:right;color:#64748b;"><?php echo esc_html( number_format( (float) $p['position'], 1 ) ); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- ── Block: SEO Watch (recent shipped changes) ── -->
        <?php if ( ! empty( $data['seo_watches'] ) ) : ?>
            <div class="dhc-card">
                <div class="dhc-card-header">
                    <h2>Recent SEO changes tracked</h2>
                    <span style="font-size:12px;color:#9ca3af;">From AutoReason and Deep Page Analysis</span>
                </div>
                <div class="dhc-card-body" style="padding:0 28px 18px;">
                    <table class="widefat" style="border:0;">
                        <thead>
                            <tr>
                                <th>Page</th>
                                <th>Keyword</th>
                                <th style="width:100px;">Δ Position</th>
                                <th style="width:110px;">Δ Clicks</th>
                                <th style="width:90px;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $data['seo_watches'] as $w ) : ?>
                                <tr>
                                    <td style="word-break:break-all;"><a href="<?php echo esc_url( $w['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $w['path'] ); ?></a></td>
                                    <td><?php echo esc_html( $w['target_keyword'] ); ?></td>
                                    <td><?php echo self::delta_cell( $w['delta_position'] ?? null, true /* lower is better */ ); ?></td>
                                    <td><?php echo self::delta_cell( $w['delta_clicks']   ?? null, false ); ?></td>
                                    <td style="color:#64748b;"><?php echo esc_html( $w['status'] ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- ── Block: Recent drafts + activity side-by-side ── -->
        <?php if ( ! empty( $data['drafts'] ) || ! empty( $data['activity'] ) ) : ?>
            <div class="dhc-dash-two-col">
                <?php if ( ! empty( $data['drafts'] ) ) : ?>
                    <div class="dhc-card">
                        <div class="dhc-card-header"><h2>Fresh content drafts</h2></div>
                        <div class="dhc-card-body">
                            <ul style="margin:0;padding-left:20px;font-size:14px;line-height:1.7;color:#334155;">
                                <?php foreach ( $data['drafts'] as $d ) : ?>
                                    <li style="margin-bottom:4px;">
                                        <?php echo esc_html( self::short( $d['topic'], 90 ) ); ?>
                                        <span style="color:#94a3b8;font-size:12px;">· <?php echo esc_html( $d['campaign'] ); ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                            <p style="margin:14px 0 0;font-size:12px;color:#9ca3af;">Review + publish from the Hub's Content Studio.</p>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ( ! empty( $data['activity'] ) ) : ?>
                    <div class="dhc-card">
                        <div class="dhc-card-header"><h2>Recent Hub activity</h2></div>
                        <div class="dhc-card-body">
                            <ul style="margin:0;padding-left:20px;font-size:14px;line-height:1.7;color:#334155;">
                                <?php foreach ( $data['activity'] as $a ) : ?>
                                    <li style="margin-bottom:4px;">
                                        <strong><?php echo esc_html( $a['label'] ); ?></strong>
                                        <?php if ( ! empty( $a['title'] ) ) : ?>:
                                            <?php echo esc_html( self::short( $a['title'], 80 ) ); ?>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif;
    }

    // ── Small helpers ────────────────────────────────────────

    private static function kpi_tile( $label, $value, $sub ) {
        return '<div class="dhc-dash-kpi">'
             . '<div class="dhc-dash-kpi-label">' . esc_html( $label ) . '</div>'
             . '<div class="dhc-dash-kpi-value">' . esc_html( (string) $value ) . '</div>'
             . '<div class="dhc-dash-kpi-sub">' . esc_html( $sub ) . '</div>'
             . '</div>';
    }

    private static function delta_cell( $v, $lower_is_better ) {
        if ( $v === null || $v === '' ) return '<span style="color:#9ca3af;">—</span>';
        $v = (float) $v;
        $improved = $lower_is_better ? ( $v < -0.3 ) : ( $v > 0.3 );
        $flat     = abs( $v ) <= ( $lower_is_better ? 0.3 : 1.0 );
        $color    = $flat ? '#9ca3af' : ( $improved ? '#4f46e5' : '#dc2626' );
        $arrow    = $flat ? '→' : ( $improved ? '↑' : '↓' );
        $display  = ( $v >= 0 ? '+' : '' ) . ( $lower_is_better ? number_format( $v, 1 ) : (string) (int) $v );
        return '<span style="color:' . $color . ';font-weight:700;">' . $arrow . ' ' . $display . '</span>';
    }

    private static function render_sparkline( $rows ) {
        if ( ! is_array( $rows ) || empty( $rows ) ) return '';
        $max = 1;
        foreach ( $rows as $r ) { if ( ! empty( $r['sessions'] ) && $r['sessions'] > $max ) $max = $r['sessions']; }
        $w = 800; $h = 90; $pad = 2;
        $n = count( $rows );
        $stepX = $n > 1 ? ( $w - ( $pad * 2 ) ) / ( $n - 1 ) : 0;
        $points = array();
        foreach ( $rows as $i => $r ) {
            $x = $pad + $i * $stepX;
            $y = $h - $pad - ( ( $r['sessions'] ?? 0 ) / $max ) * ( $h - ( $pad * 2 ) );
            $points[] = number_format( $x, 1, '.', '' ) . ',' . number_format( $y, 1, '.', '' );
        }
        $poly_points = implode( ' ', $points );
        $area_points = $poly_points . ' ' . number_format( $pad + ( $n - 1 ) * $stepX, 1, '.', '' ) . ',' . ( $h - $pad ) . ' ' . $pad . ',' . ( $h - $pad );
        $total = array_sum( array_map( fn( $r ) => (int) ( $r['sessions'] ?? 0 ), $rows ) );
        $out  = '<div style="display:flex;align-items:center;gap:20px;flex-wrap:wrap;">';
        $out .= '<div><div style="font-size:12px;color:#9ca3af;text-transform:uppercase;letter-spacing:0.08em;font-weight:700;">Sessions (14d)</div>';
        $out .= '<div style="font-size:28px;font-weight:800;color:#111827;letter-spacing:-0.02em;">' . number_format( $total ) . '</div></div>';
        $out .= '<svg viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" style="flex:1;min-width:320px;height:90px;">';
        $out .= '<polygon points="' . esc_attr( $area_points ) . '" fill="rgba(79,70,229,0.12)"/>';
        $out .= '<polyline points="' . esc_attr( $poly_points ) . '" fill="none" stroke="#4f46e5" stroke-width="2"/>';
        $out .= '</svg></div>';
        return $out;
    }

    /** SVG donut — colorful, no JS library. Expects rows of { label, value }. */
    private static function render_donut( $rows, $palette ) {
        if ( ! is_array( $rows ) || empty( $rows ) ) return '';
        $total = 0;
        foreach ( $rows as $r ) { $total += (int) ( $r['value'] ?? 0 ); }
        if ( $total <= 0 ) return '';

        $cx = 70; $cy = 70; $r_outer = 60; $r_inner = 42;
        $start_angle = -90;
        $segments = '';
        $legend = '';
        $i = 0;
        foreach ( $rows as $row ) {
            $v = (int) ( $row['value'] ?? 0 );
            if ( $v <= 0 ) continue;
            $frac = $v / $total;
            $sweep = $frac * 360;
            $end_angle = $start_angle + $sweep;
            $color = $palette[ $i % count( $palette ) ];
            // Large arc flag — 1 when sweep > 180°.
            $large = $sweep > 180 ? 1 : 0;
            $sx = $cx + $r_outer * cos( deg2rad( $start_angle ) );
            $sy = $cy + $r_outer * sin( deg2rad( $start_angle ) );
            $ex = $cx + $r_outer * cos( deg2rad( $end_angle ) );
            $ey = $cy + $r_outer * sin( deg2rad( $end_angle ) );
            $sxi = $cx + $r_inner * cos( deg2rad( $end_angle ) );
            $syi = $cy + $r_inner * sin( deg2rad( $end_angle ) );
            $exi = $cx + $r_inner * cos( deg2rad( $start_angle ) );
            $eyi = $cy + $r_inner * sin( deg2rad( $start_angle ) );
            $d  = 'M ' . number_format( $sx, 2, '.', '' ) . ' ' . number_format( $sy, 2, '.', '' );
            $d .= ' A ' . $r_outer . ' ' . $r_outer . ' 0 ' . $large . ' 1 ' . number_format( $ex, 2, '.', '' ) . ' ' . number_format( $ey, 2, '.', '' );
            $d .= ' L ' . number_format( $sxi, 2, '.', '' ) . ' ' . number_format( $syi, 2, '.', '' );
            $d .= ' A ' . $r_inner . ' ' . $r_inner . ' 0 ' . $large . ' 0 ' . number_format( $exi, 2, '.', '' ) . ' ' . number_format( $eyi, 2, '.', '' );
            $d .= ' Z';
            $segments .= '<path d="' . $d . '" fill="' . esc_attr( $color ) . '"/>';

            $pct = round( $frac * 100 );
            $legend .= '<div style="display:flex;align-items:center;gap:8px;font-size:13px;padding:5px 0;">'
                   . '<span style="width:10px;height:10px;border-radius:2px;background:' . esc_attr( $color ) . ';flex-shrink:0;"></span>'
                   . '<span style="flex:1;color:#1e293b;">' . esc_html( $row['label'] ?? '' ) . '</span>'
                   . '<span style="color:#64748b;font-weight:600;font-variant-numeric:tabular-nums;">' . $pct . '%</span>'
                   . '</div>';
            $start_angle = $end_angle;
            $i++;
        }

        $out  = '<div style="display:flex;align-items:center;gap:24px;flex-wrap:wrap;">';
        $out .= '<svg viewBox="0 0 140 140" style="width:140px;height:140px;flex-shrink:0;">' . $segments . '</svg>';
        $out .= '<div style="flex:1;min-width:180px;">' . $legend . '</div>';
        $out .= '</div>';
        return $out;
    }

    /** Horizontal bar chart — used for countries + any ranked list. */
    private static function render_hbar( $rows, $color = '#6366F1' ) {
        if ( ! is_array( $rows ) || empty( $rows ) ) return '';
        $max = 0;
        foreach ( $rows as $r ) { if ( (int) ( $r['value'] ?? 0 ) > $max ) $max = (int) $r['value']; }
        if ( $max <= 0 ) return '';
        $out = '<div style="display:flex;flex-direction:column;gap:8px;">';
        foreach ( $rows as $r ) {
            $v = (int) ( $r['value'] ?? 0 );
            $pct = $max > 0 ? round( ( $v / $max ) * 100 ) : 0;
            $label = esc_html( $r['label'] ?? '' );
            $out .= '<div style="display:flex;align-items:center;gap:12px;font-size:13px;">'
                 . '<span style="width:100px;color:#1e293b;">' . $label . '</span>'
                 . '<div style="flex:1;height:10px;background:#f1f5f9;border-radius:999px;overflow:hidden;">'
                 .   '<div style="width:' . $pct . '%;height:100%;background:' . esc_attr( $color ) . ';border-radius:999px;"></div>'
                 . '</div>'
                 . '<span style="width:60px;text-align:right;color:#64748b;font-weight:600;font-variant-numeric:tabular-nums;">' . number_format( $v ) . '</span>'
                 . '</div>';
        }
        $out .= '</div>';
        return $out;
    }

    private static function num( $n ) {
        $n = (int) $n;
        if ( $n >= 1000000 ) return number_format( $n / 1000000, 1 ) . 'M';
        if ( $n >= 1000 )    return number_format( $n / 1000, 1 ) . 'k';
        return number_format( $n );
    }

    private static function short( $s, $len ) {
        $s = (string) $s;
        return mb_strlen( $s ) > $len ? ( mb_substr( $s, 0, $len ) . '…' ) : $s;
    }

    /** CSS injected inline — scoped to the dashboard sub-page only */
    public static function print_inline_styles() {
        ?>
        <style>
            .dhc-dash-kpi-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
                gap: 14px;
                margin-bottom: 24px;
            }
            .dhc-dash-kpi {
                background: #fff;
                border: 1px solid #eaeaea;
                border-radius: 12px;
                padding: 18px 20px;
            }
            .dhc-dash-kpi-label {
                font-size: 11px;
                font-weight: 700;
                color: #9ca3af;
                text-transform: uppercase;
                letter-spacing: 0.1em;
                margin-bottom: 10px;
            }
            .dhc-dash-kpi-value {
                font-size: 28px;
                font-weight: 800;
                color: #111827;
                letter-spacing: -0.02em;
                font-variant-numeric: tabular-nums;
                line-height: 1;
            }
            .dhc-dash-kpi-sub {
                font-size: 12px;
                color: #9ca3af;
                margin-top: 6px;
            }
            .dhc-dash-two-col {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(380px, 1fr));
                gap: 16px;
                margin-bottom: 24px;
            }
            .dhc-dash-two-col .dhc-card { margin-bottom: 0; }
            .dhc-wrap table.widefat { background: transparent; box-shadow: none; }
            .dhc-wrap table.widefat thead th { background: transparent; font-size: 11px; text-transform: uppercase; letter-spacing: 0.08em; color: #9ca3af; font-weight: 700; border-bottom: 1px solid #eaeaea; padding: 8px 12px; }
            .dhc-wrap table.widefat td { border-bottom: 1px solid #f4f4f5; padding: 10px 12px; color: #374151; }
            .dhc-wrap table.widefat tr:last-child td { border-bottom: none; }
        </style>
        <?php
    }
}
