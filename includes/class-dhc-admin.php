<?php
/**
 * DHC_Admin — Admin settings page styled to match the Hub backend
 *
 * @package Dsquared_Hub_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DHC_Admin {

    /**
     * Initialize admin hooks
     */
    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'wp_ajax_dhc_save_settings', array( __CLASS__, 'ajax_save_settings' ) );
        add_action( 'wp_ajax_dhc_validate_key', array( __CLASS__, 'ajax_validate_key' ) );
        add_action( 'wp_ajax_dhc_clear_activity_log', array( __CLASS__, 'ajax_clear_log' ) );
        add_action( 'wp_ajax_dhc_save_ai_discovery', array( __CLASS__, 'ajax_save_ai_discovery' ) );
    }

    /**
     * Add the admin menu page
     */
    public static function add_menu_page() {
        add_menu_page(
            esc_html__( 'Dsquared Hub', 'dsquared-hub-connector' ),
            esc_html__( 'Dsquared Hub', 'dsquared-hub-connector' ),
            'manage_options',
            'dsquared-hub',
            array( __CLASS__, 'render_page' ),
            'data:image/svg+xml;base64,' . base64_encode( self::get_menu_icon() ),
            30
        );
    }

    /**
     * Enqueue admin CSS and JS
     */
    public static function enqueue_assets( $hook ) {
        if ( 'toplevel_page_dsquared-hub' !== $hook ) {
            return;
        }

        wp_enqueue_style( 'dhc-admin', DHC_PLUGIN_URL . 'admin/css/dhc-admin.css', array(), DHC_VERSION );
        wp_enqueue_style( 'dhc-google-fonts', 'https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap', array(), null );

        wp_enqueue_script( 'dhc-admin', DHC_PLUGIN_URL . 'admin/js/dhc-admin.js', array( 'jquery' ), DHC_VERSION, true );

        wp_localize_script( 'dhc-admin', 'dhcAdmin', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'dhc_admin_nonce' ),
            'restUrl' => rest_url( 'dsquared-hub/v1/' ),
        ) );
    }

    /**
     * Render the admin settings page
     */
    public static function render_page() {
        $api_key      = get_option( 'dhc_api_key', '' );
        $modules      = get_option( 'dhc_modules', array() );
        $subscription = DHC_API_Key::validate();
        $activity_log = array_reverse( get_option( 'dhc_activity_log', array() ) );
        $cwv_metrics  = DHC_Site_Health::get_aggregated_metrics( 30 );
        $seo_plugin   = DHC_SEO_Meta::detect_seo_plugin();
        $ai_profile   = get_option( 'dhc_ai_business_profile', array() );
        ?>
        <div class="dhc-wrap">
            <!-- Header -->
            <div class="dhc-header">
                <div class="dhc-header-left">
                    <div class="dhc-logo">
                        <svg width="28" height="28" viewBox="0 0 40 40" fill="none">
                            <rect width="40" height="40" rx="8" fill="#5661FF"/>
                            <path d="M10 12h8c5.5 0 10 4.5 10 10s-4.5 10-10 10h-8V12z" fill="none" stroke="#fff" stroke-width="2.5"/>
                            <path d="M22 12h8v8" fill="none" stroke="#E8466D" stroke-width="2.5" stroke-linecap="round"/>
                        </svg>
                    </div>
                    <div>
                        <h1 class="dhc-title"><?php esc_html_e( 'Dsquared Hub Connector', 'dsquared-hub-connector' ); ?></h1>
                        <span class="dhc-version">v<?php echo esc_html( DHC_VERSION ); ?></span>
                    </div>
                </div>
                <div class="dhc-header-right">
                    <?php if ( ! empty( $subscription['valid'] ) ) : ?>
                        <span class="dhc-badge dhc-badge-success">
                            <span class="dhc-badge-dot"></span>
                            <?php echo esc_html__( 'Connected', 'dsquared-hub-connector' ) . ' — ' . esc_html( DHC_API_Key::get_tier_label( $subscription['tier'] ?? '' ) ); ?>
                        </span>
                    <?php elseif ( ! empty( $subscription['expired'] ) ) : ?>
                        <span class="dhc-badge dhc-badge-warning">
                            <span class="dhc-badge-dot"></span>
                            <?php esc_html_e( 'Subscription Expired', 'dsquared-hub-connector' ); ?>
                        </span>
                    <?php else : ?>
                        <span class="dhc-badge dhc-badge-inactive">
                            <span class="dhc-badge-dot"></span>
                            <?php esc_html_e( 'Not Connected', 'dsquared-hub-connector' ); ?>
                        </span>
                    <?php endif; ?>
                    <a href="https://hub.dsquaredmedia.net" target="_blank" class="dhc-btn dhc-btn-outline"><?php esc_html_e( 'Open Hub', 'dsquared-hub-connector' ); ?></a>
                </div>
            </div>

            <?php if ( ! empty( $subscription['expired'] ) ) : ?>
            <div class="dhc-notice dhc-notice-warning">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                <div>
                    <strong><?php esc_html_e( 'Your subscription has expired.', 'dsquared-hub-connector' ); ?></strong>
                    <?php esc_html_e( 'All Hub features are currently disabled, but your website is completely unaffected. Keeping an active subscription is suggested to maintain full functionality.', 'dsquared-hub-connector' ); ?>
                    <a href="https://hub.dsquaredmedia.net/dashboard.html#account" target="_blank"><?php esc_html_e( 'Renew your subscription', 'dsquared-hub-connector' ); ?> &rarr;</a>
                </div>
            </div>
            <?php endif; ?>

            <!-- Tab Navigation -->
            <div class="dhc-tabs">
                <button class="dhc-tab active" data-tab="connection"><?php esc_html_e( 'Connection', 'dsquared-hub-connector' ); ?></button>
                <button class="dhc-tab" data-tab="modules"><?php esc_html_e( 'Modules', 'dsquared-hub-connector' ); ?></button>
                <button class="dhc-tab" data-tab="ai-discovery"><?php esc_html_e( 'AI Discovery', 'dsquared-hub-connector' ); ?></button>
                <button class="dhc-tab" data-tab="health"><?php esc_html_e( 'Site Health', 'dsquared-hub-connector' ); ?></button>
                <button class="dhc-tab" data-tab="activity"><?php esc_html_e( 'Activity Log', 'dsquared-hub-connector' ); ?></button>
            </div>

            <!-- ═══ Connection Tab ═══ -->
            <div class="dhc-tab-content active" id="tab-connection">
                <div class="dhc-card">
                    <div class="dhc-card-header">
                        <h2><?php esc_html_e( 'API Connection', 'dsquared-hub-connector' ); ?></h2>
                        <p class="dhc-card-desc"><?php esc_html_e( 'Connect this WordPress site to your Dsquared Media Hub account.', 'dsquared-hub-connector' ); ?></p>
                    </div>
                    <div class="dhc-card-body">
                        <div class="dhc-field">
                            <label for="dhc-api-key"><?php esc_html_e( 'API Key', 'dsquared-hub-connector' ); ?></label>
                            <div class="dhc-input-group">
                                <input type="password" id="dhc-api-key" class="dhc-input"
                                       value="<?php echo esc_attr( $api_key ); ?>"
                                       placeholder="<?php esc_attr_e( 'Enter your Hub API key', 'dsquared-hub-connector' ); ?>" autocomplete="off">
                                <button type="button" class="dhc-btn dhc-btn-icon" id="dhc-toggle-key" title="<?php esc_attr_e( 'Show/hide key', 'dsquared-hub-connector' ); ?>">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                </button>
                            </div>
                            <p class="dhc-field-hint"><?php printf( esc_html__( 'Find your API key in %sHub &rarr; Account &rarr; API Keys%s', 'dsquared-hub-connector' ), '<a href="https://hub.dsquaredmedia.net/dashboard.html#account" target="_blank">', '</a>' ); ?></p>
                        </div>
                        <div class="dhc-actions">
                            <button type="button" class="dhc-btn dhc-btn-primary" id="dhc-save-key"><?php esc_html_e( 'Save & Validate', 'dsquared-hub-connector' ); ?></button>
                            <span id="dhc-key-status" class="dhc-status-msg"></span>
                        </div>
                    </div>
                </div>

                <div class="dhc-card">
                    <div class="dhc-card-header">
                        <h2><?php esc_html_e( 'Subscription Details', 'dsquared-hub-connector' ); ?></h2>
                    </div>
                    <div class="dhc-card-body">
                        <div class="dhc-info-grid">
                            <div class="dhc-info-item">
                                <span class="dhc-info-label"><?php esc_html_e( 'Status', 'dsquared-hub-connector' ); ?></span>
                                <span class="dhc-info-value <?php echo ! empty( $subscription['valid'] ) ? 'dhc-text-success' : 'dhc-text-muted'; ?>">
                                    <?php echo ! empty( $subscription['valid'] ) ? esc_html__( 'Active', 'dsquared-hub-connector' ) : ( ! empty( $subscription['expired'] ) ? esc_html__( 'Expired', 'dsquared-hub-connector' ) : esc_html__( 'Inactive', 'dsquared-hub-connector' ) ); ?>
                                </span>
                            </div>
                            <div class="dhc-info-item">
                                <span class="dhc-info-label"><?php esc_html_e( 'Tier', 'dsquared-hub-connector' ); ?></span>
                                <span class="dhc-info-value"><?php echo esc_html( DHC_API_Key::get_tier_label( $subscription['tier'] ?? '' ) ?: '—' ); ?></span>
                            </div>
                            <div class="dhc-info-item">
                                <span class="dhc-info-label"><?php esc_html_e( 'Expires', 'dsquared-hub-connector' ); ?></span>
                                <span class="dhc-info-value"><?php echo ! empty( $subscription['expires'] ) ? esc_html( date( 'M j, Y', strtotime( $subscription['expires'] ) ) ) : '—'; ?></span>
                            </div>
                            <div class="dhc-info-item">
                                <span class="dhc-info-label"><?php esc_html_e( 'WordPress', 'dsquared-hub-connector' ); ?></span>
                                <span class="dhc-info-value"><?php echo esc_html( get_bloginfo( 'version' ) ); ?></span>
                            </div>
                            <div class="dhc-info-item">
                                <span class="dhc-info-label"><?php esc_html_e( 'SEO Plugin', 'dsquared-hub-connector' ); ?></span>
                                <span class="dhc-info-value"><?php echo $seo_plugin ? esc_html( ucfirst( $seo_plugin ) ) : esc_html__( 'None detected', 'dsquared-hub-connector' ); ?></span>
                            </div>
                            <div class="dhc-info-item">
                                <span class="dhc-info-label"><?php esc_html_e( 'REST Endpoint', 'dsquared-hub-connector' ); ?></span>
                                <span class="dhc-info-value dhc-mono"><?php echo esc_html( rest_url( 'dsquared-hub/v1/' ) ); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="dhc-card dhc-card-subtle">
                    <div class="dhc-card-body">
                        <div class="dhc-notice-inline">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                            <p><?php esc_html_e( 'If the plugin is disabled or your subscription lapses, it will not interrupt your website. Hub features will simply become unavailable until reactivated. Your content, schema markup, and SEO settings will be preserved. Keeping an active subscription is suggested for continued access to all features.', 'dsquared-hub-connector' ); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ═══ Modules Tab ═══ -->
            <div class="dhc-tab-content" id="tab-modules">
                <div class="dhc-modules-grid">
                    <?php
                    $module_list = array(
                        'auto_post' => array(
                            'name' => __( 'Auto-Post to Draft', 'dsquared-hub-connector' ),
                            'desc' => __( 'Receive blog content from the Hub and create WordPress draft posts automatically. Supports title, body, categories, tags, and featured images.', 'dsquared-hub-connector' ),
                            'icon' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>',
                            'tier' => 'Starter+',
                        ),
                        'schema' => array(
                            'name' => __( 'Schema Injector', 'dsquared-hub-connector' ),
                            'desc' => __( "Push JSON-LD structured data from the Hub's Schema Generator directly into your pages. Supports per-post and site-wide schemas.", 'dsquared-hub-connector' ),
                            'icon' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>',
                            'tier' => 'Growth+',
                        ),
                        'seo_meta' => array(
                            'name' => __( 'SEO Meta Sync', 'dsquared-hub-connector' ),
                            'desc' => __( "Sync optimized meta titles, descriptions, and OG data from the Hub's Page Optimizer. Compatible with Yoast, Rank Math, AIOSEO, and SEOPress.", 'dsquared-hub-connector' ),
                            'icon' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>',
                            'tier' => 'Growth+',
                        ),
                        'site_health' => array(
                            'name' => __( 'Site Health Monitor', 'dsquared-hub-connector' ),
                            'desc' => __( 'Collect real-user Core Web Vitals (LCP, CLS, INP, TTFB, FCP) and report them to the Hub for monitoring. Lightweight ~2KB script.', 'dsquared-hub-connector' ),
                            'icon' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>',
                            'tier' => 'Pro',
                        ),
                        'ai_discovery' => array(
                            'name' => __( 'AI Discovery', 'dsquared-hub-connector' ),
                            'desc' => __( 'Generate an AI-readable business profile (llms.txt), inject LocalBusiness schema, and ping IndexNow when content changes so AI search engines know you exist.', 'dsquared-hub-connector' ),
                            'icon' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2a10 10 0 1010 10A10 10 0 0012 2z"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 014 10 15.3 15.3 0 01-4 10 15.3 15.3 0 01-4-10 15.3 15.3 0 014-10z"/></svg>',
                            'tier' => 'Pro',
                        ),
                        'content_decay' => array(
                            'name' => __( 'Content Decay Alerts', 'dsquared-hub-connector' ),
                            'desc' => __( 'Monitor published posts for freshness and report stale content back to the Hub. Posts not updated in 6+ months get flagged for review.', 'dsquared-hub-connector' ),
                            'icon' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
                            'tier' => 'Growth+',
                        ),
                        'form_capture' => array(
                            'name' => __( 'Form Submission Capture', 'dsquared-hub-connector' ),
                            'desc' => __( 'Hook into popular form plugins to capture leads, filter spam in real-time, and send clean lead data to your Hub pipeline. No personal data stored locally.', 'dsquared-hub-connector' ),
                            'icon' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>',
                            'tier' => 'Pro',
                        ),
                    );

                    foreach ( $module_list as $slug => $module ) :
                        $is_enabled   = ! empty( $modules[ $slug ] );
                        $is_available = DHC_API_Key::is_module_available( $slug );
                        $tier_ok      = in_array( $slug, $subscription['modules'] ?? array(), true );
                    ?>
                    <div class="dhc-module-card <?php echo $is_available ? 'dhc-module-active' : ''; ?> <?php echo ! $tier_ok ? 'dhc-module-locked' : ''; ?>">
                        <div class="dhc-module-header">
                            <div class="dhc-module-icon"><?php echo $module['icon']; ?></div>
                            <div class="dhc-module-meta">
                                <h3><?php echo esc_html( $module['name'] ); ?></h3>
                                <span class="dhc-tier-badge"><?php echo esc_html( $module['tier'] ); ?></span>
                            </div>
                            <label class="dhc-toggle">
                                <input type="checkbox" class="dhc-module-toggle" data-module="<?php echo esc_attr( $slug ); ?>"
                                       <?php checked( $is_enabled ); ?>
                                       <?php disabled( ! $tier_ok ); ?>>
                                <span class="dhc-toggle-slider"></span>
                            </label>
                        </div>
                        <p class="dhc-module-desc"><?php echo esc_html( $module['desc'] ); ?></p>
                        <?php if ( ! $tier_ok ) : ?>
                            <div class="dhc-module-upgrade">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                                <?php esc_html_e( 'Upgrade your plan to unlock this module', 'dsquared-hub-connector' ); ?>
                            </div>
                        <?php endif; ?>
                        <?php if ( $is_available ) : ?>
                            <div class="dhc-module-status">
                                <span class="dhc-status-dot dhc-status-active"></span> <?php esc_html_e( 'Active', 'dsquared-hub-connector' ); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="dhc-actions" style="margin-top: 20px;">
                    <button type="button" class="dhc-btn dhc-btn-primary" id="dhc-save-modules"><?php esc_html_e( 'Save Module Settings', 'dsquared-hub-connector' ); ?></button>
                    <span id="dhc-modules-status" class="dhc-status-msg"></span>
                </div>
            </div>

            <!-- ═══ AI Discovery Tab ═══ -->
            <div class="dhc-tab-content" id="tab-ai-discovery">
                <div class="dhc-card">
                    <div class="dhc-card-header">
                        <h2><?php esc_html_e( 'Business Profile for AI Discovery', 'dsquared-hub-connector' ); ?></h2>
                        <p class="dhc-card-desc"><?php esc_html_e( 'This information is used to generate your llms.txt, llms-full.txt, and LocalBusiness schema so AI platforms know your business exists and what services you offer.', 'dsquared-hub-connector' ); ?></p>
                    </div>
                    <div class="dhc-card-body">
                        <div class="dhc-field">
                            <label for="dhc-biz-name"><?php esc_html_e( 'Business Name', 'dsquared-hub-connector' ); ?></label>
                            <input type="text" id="dhc-biz-name" class="dhc-input" value="<?php echo esc_attr( $ai_profile['business_name'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'e.g., Acme Garage Door Repair', 'dsquared-hub-connector' ); ?>">
                        </div>
                        <div class="dhc-field">
                            <label for="dhc-biz-desc"><?php esc_html_e( 'Business Description', 'dsquared-hub-connector' ); ?></label>
                            <textarea id="dhc-biz-desc" class="dhc-input dhc-textarea" rows="3" placeholder="<?php esc_attr_e( 'Describe what your business does, who you serve, and what makes you different.', 'dsquared-hub-connector' ); ?>"><?php echo esc_textarea( $ai_profile['description'] ?? '' ); ?></textarea>
                        </div>
                        <div class="dhc-field">
                            <label for="dhc-biz-services"><?php esc_html_e( 'Services Offered', 'dsquared-hub-connector' ); ?></label>
                            <textarea id="dhc-biz-services" class="dhc-input dhc-textarea" rows="4" placeholder="<?php esc_attr_e( "One service per line, e.g.:\nBroken Spring Repair\nGarage Door Installation\nOpener Replacement", 'dsquared-hub-connector' ); ?>"><?php echo esc_textarea( $ai_profile['services_text'] ?? '' ); ?></textarea>
                            <p class="dhc-field-hint"><?php esc_html_e( 'Enter one service per line. Be specific — these are what AI platforms will associate with your business.', 'dsquared-hub-connector' ); ?></p>
                        </div>
                        <div class="dhc-field-row">
                            <div class="dhc-field">
                                <label for="dhc-biz-phone"><?php esc_html_e( 'Phone', 'dsquared-hub-connector' ); ?></label>
                                <input type="text" id="dhc-biz-phone" class="dhc-input" value="<?php echo esc_attr( $ai_profile['phone'] ?? '' ); ?>" placeholder="(555) 123-4567">
                            </div>
                            <div class="dhc-field">
                                <label for="dhc-biz-email"><?php esc_html_e( 'Email', 'dsquared-hub-connector' ); ?></label>
                                <input type="email" id="dhc-biz-email" class="dhc-input" value="<?php echo esc_attr( $ai_profile['email'] ?? '' ); ?>" placeholder="info@example.com">
                            </div>
                        </div>
                        <div class="dhc-field">
                            <label for="dhc-biz-address"><?php esc_html_e( 'Address', 'dsquared-hub-connector' ); ?></label>
                            <input type="text" id="dhc-biz-address" class="dhc-input" value="<?php echo esc_attr( $ai_profile['address'] ?? '' ); ?>" placeholder="<?php esc_attr_e( '123 Main St, Dallas, TX 75201', 'dsquared-hub-connector' ); ?>">
                        </div>
                        <div class="dhc-field">
                            <label for="dhc-biz-areas"><?php esc_html_e( 'Service Areas', 'dsquared-hub-connector' ); ?></label>
                            <textarea id="dhc-biz-areas" class="dhc-input dhc-textarea" rows="2" placeholder="<?php esc_attr_e( "One area per line, e.g.:\nDallas, TX\nFort Worth, TX\nPlano, TX", 'dsquared-hub-connector' ); ?>"><?php echo esc_textarea( $ai_profile['service_areas_text'] ?? '' ); ?></textarea>
                        </div>
                        <div class="dhc-field">
                            <label for="dhc-biz-hours"><?php esc_html_e( 'Business Hours', 'dsquared-hub-connector' ); ?></label>
                            <input type="text" id="dhc-biz-hours" class="dhc-input" value="<?php echo esc_attr( $ai_profile['hours'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Mon-Fri 8am-6pm, Sat 9am-2pm', 'dsquared-hub-connector' ); ?>">
                        </div>
                        <div class="dhc-field">
                            <label for="dhc-biz-extra"><?php esc_html_e( 'Additional Info', 'dsquared-hub-connector' ); ?></label>
                            <textarea id="dhc-biz-extra" class="dhc-input dhc-textarea" rows="3" placeholder="<?php esc_attr_e( 'Certifications, brands carried, years in business, unique selling points, etc.', 'dsquared-hub-connector' ); ?>"><?php echo esc_textarea( $ai_profile['extra_info'] ?? '' ); ?></textarea>
                        </div>
                        <div class="dhc-actions">
                            <button type="button" class="dhc-btn dhc-btn-primary" id="dhc-save-ai-discovery"><?php esc_html_e( 'Save & Generate Files', 'dsquared-hub-connector' ); ?></button>
                            <span id="dhc-ai-discovery-status" class="dhc-status-msg"></span>
                        </div>
                    </div>
                </div>

                <div class="dhc-card">
                    <div class="dhc-card-header">
                        <h2><?php esc_html_e( 'Generated AI Discovery Files', 'dsquared-hub-connector' ); ?></h2>
                    </div>
                    <div class="dhc-card-body">
                        <div class="dhc-info-grid">
                            <div class="dhc-info-item">
                                <span class="dhc-info-label">llms.txt</span>
                                <span class="dhc-info-value dhc-mono"><a href="<?php echo esc_url( home_url( '/llms.txt' ) ); ?>" target="_blank"><?php echo esc_html( home_url( '/llms.txt' ) ); ?></a></span>
                            </div>
                            <div class="dhc-info-item">
                                <span class="dhc-info-label">llms-full.txt</span>
                                <span class="dhc-info-value dhc-mono"><a href="<?php echo esc_url( home_url( '/llms-full.txt' ) ); ?>" target="_blank"><?php echo esc_html( home_url( '/llms-full.txt' ) ); ?></a></span>
                            </div>
                            <div class="dhc-info-item">
                                <span class="dhc-info-label"><?php esc_html_e( 'IndexNow Key', 'dsquared-hub-connector' ); ?></span>
                                <span class="dhc-info-value dhc-mono"><?php echo esc_html( get_option( 'dhc_indexnow_key', 'Not generated yet' ) ); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ═══ Site Health Tab ═══ -->
            <div class="dhc-tab-content" id="tab-health">
                <div class="dhc-card">
                    <div class="dhc-card-header">
                        <h2><?php esc_html_e( 'Core Web Vitals — Last 30 Days', 'dsquared-hub-connector' ); ?></h2>
                        <p class="dhc-card-desc"><?php esc_html_e( 'Real-user metrics collected from your site visitors (p75 values).', 'dsquared-hub-connector' ); ?></p>
                    </div>
                    <div class="dhc-card-body">
                        <?php if ( $cwv_metrics['count'] > 0 ) : ?>
                        <div class="dhc-cwv-grid">
                            <?php
                            $cwv_items = array(
                                'lcp'  => array( 'label' => 'LCP', 'full' => __( 'Largest Contentful Paint', 'dsquared-hub-connector' ), 'unit' => 'ms', 'good' => '< 2500ms' ),
                                'cls'  => array( 'label' => 'CLS', 'full' => __( 'Cumulative Layout Shift', 'dsquared-hub-connector' ), 'unit' => '', 'good' => '< 0.1' ),
                                'inp'  => array( 'label' => 'INP', 'full' => __( 'Interaction to Next Paint', 'dsquared-hub-connector' ), 'unit' => 'ms', 'good' => '< 200ms' ),
                                'ttfb' => array( 'label' => 'TTFB', 'full' => __( 'Time to First Byte', 'dsquared-hub-connector' ), 'unit' => 'ms', 'good' => '< 800ms' ),
                                'fid'  => array( 'label' => 'FID', 'full' => __( 'First Input Delay', 'dsquared-hub-connector' ), 'unit' => 'ms', 'good' => '< 100ms' ),
                            );
                            foreach ( $cwv_items as $key => $item ) :
                                $value  = $cwv_metrics[ $key . '_p75' ];
                                $rating = DHC_Site_Health::get_rating( $key, $value );
                            ?>
                            <div class="dhc-cwv-card dhc-cwv-<?php echo esc_attr( $rating ); ?>">
                                <div class="dhc-cwv-label"><?php echo esc_html( $item['label'] ); ?></div>
                                <div class="dhc-cwv-value"><?php echo null !== $value ? esc_html( $value . $item['unit'] ) : '—'; ?></div>
                                <div class="dhc-cwv-full"><?php echo esc_html( $item['full'] ); ?></div>
                                <div class="dhc-cwv-threshold"><?php echo esc_html__( 'Good:', 'dsquared-hub-connector' ) . ' ' . esc_html( $item['good'] ); ?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <p class="dhc-cwv-count"><?php echo esc_html( number_format( $cwv_metrics['count'] ) ); ?> <?php esc_html_e( 'page loads measured', 'dsquared-hub-connector' ); ?></p>
                        <?php else : ?>
                        <div class="dhc-empty-state">
                            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" opacity="0.4"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
                            <p><?php esc_html_e( 'No Core Web Vitals data collected yet.', 'dsquared-hub-connector' ); ?></p>
                            <p class="dhc-text-muted"><?php esc_html_e( 'Data will appear once real users visit your site with the Site Health module enabled.', 'dsquared-hub-connector' ); ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ═══ Activity Log Tab ═══ -->
            <div class="dhc-tab-content" id="tab-activity">
                <div class="dhc-card">
                    <div class="dhc-card-header">
                        <h2><?php esc_html_e( 'Recent Activity', 'dsquared-hub-connector' ); ?></h2>
                        <?php if ( ! empty( $activity_log ) ) : ?>
                        <button type="button" class="dhc-btn dhc-btn-outline dhc-btn-sm" id="dhc-clear-log"><?php esc_html_e( 'Clear Log', 'dsquared-hub-connector' ); ?></button>
                        <?php endif; ?>
                    </div>
                    <div class="dhc-card-body">
                        <?php if ( ! empty( $activity_log ) ) : ?>
                        <div class="dhc-activity-list">
                            <?php foreach ( array_slice( $activity_log, 0, 25 ) as $entry ) : ?>
                            <div class="dhc-activity-item">
                                <div class="dhc-activity-icon"><?php echo self::get_activity_icon( $entry['action'] ); ?></div>
                                <div class="dhc-activity-content">
                                    <span class="dhc-activity-text"><?php echo esc_html( self::format_activity( $entry ) ); ?></span>
                                    <span class="dhc-activity-time"><?php echo esc_html( $entry['time'] ?? '' ); ?></span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else : ?>
                        <div class="dhc-empty-state">
                            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" opacity="0.4"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                            <p><?php esc_html_e( 'No activity recorded yet.', 'dsquared-hub-connector' ); ?></p>
                            <p class="dhc-text-muted"><?php esc_html_e( 'Actions from the Hub will appear here as they happen.', 'dsquared-hub-connector' ); ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <div class="dhc-footer">
                <span><?php printf( esc_html__( 'Dsquared Hub Connector v%s — by %sDsquared Media%s', 'dsquared-hub-connector' ), DHC_VERSION, '<a href="https://dsquaredmedia.net" target="_blank">', '</a>' ); ?></span>
                <span><a href="https://hub.dsquaredmedia.net/dashboard.html#help-center" target="_blank"><?php esc_html_e( 'Support', 'dsquared-hub-connector' ); ?></a></span>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX: Save settings
     */
    public static function ajax_save_settings() {
        check_ajax_referer( 'dhc_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( esc_html__( 'Unauthorized', 'dsquared-hub-connector' ) );
        }

        $api_key = sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) );
        $modules = isset( $_POST['modules'] ) ? (array) wp_unslash( $_POST['modules'] ) : array();

        $all_module_keys = array( 'auto_post', 'schema', 'seo_meta', 'site_health', 'ai_discovery', 'content_decay', 'form_capture' );
        $clean_modules = array();
        foreach ( $all_module_keys as $mod ) {
            $clean_modules[ $mod ] = ! empty( $modules[ $mod ] );
        }

        update_option( 'dhc_api_key', $api_key );
        update_option( 'dhc_modules', $clean_modules );
        DHC_API_Key::clear_cache();

        wp_send_json_success( esc_html__( 'Settings saved.', 'dsquared-hub-connector' ) );
    }

    /**
     * AJAX: Validate API key
     */
    public static function ajax_validate_key() {
        check_ajax_referer( 'dhc_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( esc_html__( 'Unauthorized', 'dsquared-hub-connector' ) );
        }

        $api_key = sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) );
        if ( empty( $api_key ) ) {
            wp_send_json_error( esc_html__( 'Please enter an API key.', 'dsquared-hub-connector' ) );
        }

        update_option( 'dhc_api_key', $api_key );
        DHC_API_Key::clear_cache();

        $result = DHC_API_Key::validate( $api_key, true );

        if ( ! empty( $result['valid'] ) ) {
            wp_send_json_success( array(
                'message' => sprintf(
                    /* translators: %s: tier label */
                    esc_html__( 'API key validated successfully! Connected as %s.', 'dsquared-hub-connector' ),
                    DHC_API_Key::get_tier_label( $result['tier'] ?? '' )
                ),
                'tier'    => $result['tier'] ?? '',
                'expires' => $result['expires'] ?? '',
            ) );
        } else {
            wp_send_json_error( $result['message'] ?? esc_html__( 'Invalid API key.', 'dsquared-hub-connector' ) );
        }
    }

    /**
     * AJAX: Clear activity log
     */
    public static function ajax_clear_log() {
        check_ajax_referer( 'dhc_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( esc_html__( 'Unauthorized', 'dsquared-hub-connector' ) );
        }
        update_option( 'dhc_activity_log', array() );
        wp_send_json_success( esc_html__( 'Activity log cleared.', 'dsquared-hub-connector' ) );
    }

    /**
     * AJAX: Save AI Discovery business profile
     */
    public static function ajax_save_ai_discovery() {
        check_ajax_referer( 'dhc_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( esc_html__( 'Unauthorized', 'dsquared-hub-connector' ) );
        }

        $profile = array(
            'business_name'      => sanitize_text_field( wp_unslash( $_POST['business_name'] ?? '' ) ),
            'description'        => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ),
            'services_text'      => sanitize_textarea_field( wp_unslash( $_POST['services_text'] ?? '' ) ),
            'phone'              => sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ),
            'email'              => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ),
            'address'            => sanitize_text_field( wp_unslash( $_POST['address'] ?? '' ) ),
            'service_areas_text' => sanitize_textarea_field( wp_unslash( $_POST['service_areas_text'] ?? '' ) ),
            'hours'              => sanitize_text_field( wp_unslash( $_POST['hours'] ?? '' ) ),
            'extra_info'         => sanitize_textarea_field( wp_unslash( $_POST['extra_info'] ?? '' ) ),
        );

        // Parse services and areas from text
        $profile['services'] = array_filter( array_map( 'trim', explode( "\n", $profile['services_text'] ) ) );
        $profile['service_areas'] = array_filter( array_map( 'trim', explode( "\n", $profile['service_areas_text'] ) ) );

        update_option( 'dhc_ai_business_profile', $profile );

        // Regenerate llms.txt files if the AI Discovery class has the method
        if ( class_exists( 'DHC_AI_Discovery' ) && method_exists( 'DHC_AI_Discovery', 'regenerate_files' ) ) {
            DHC_AI_Discovery::regenerate_files( $profile );
        }

        wp_send_json_success( esc_html__( 'Business profile saved and AI discovery files regenerated.', 'dsquared-hub-connector' ) );
    }

    /**
     * Get activity icon SVG
     */
    private static function get_activity_icon( $action ) {
        $icons = array(
            'auto_post'            => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#5661FF" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>',
            'schema_updated'       => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#22C55E" stroke-width="2"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>',
            'global_schema_updated' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#22C55E" stroke-width="2"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>',
            'seo_meta_sync'        => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#F59E0B" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>',
            'ai_discovery'         => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#8B5CF6" stroke-width="2"><path d="M12 2a10 10 0 1010 10A10 10 0 0012 2z"/><path d="M2 12h20"/></svg>',
            'content_decay'        => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#EF4444" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
            'form_capture'         => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#06B6D4" stroke-width="2"><path d="M16 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="8.5" cy="7" r="4"/></svg>',
        );
        return $icons[ $action ] ?? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#8892A8" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg>';
    }

    /**
     * Format activity log entry for display
     */
    private static function format_activity( $entry ) {
        switch ( $entry['action'] ) {
            case 'auto_post':
                return sprintf( __( 'Draft post created: "%s" (#%s)', 'dsquared-hub-connector' ), $entry['title'] ?? 'Untitled', $entry['post_id'] ?? '?' );
            case 'schema_updated':
                return sprintf( __( 'Schema markup updated for post #%s (%s)', 'dsquared-hub-connector' ), $entry['post_id'] ?? '?', $entry['schema_type'] ?? 'custom' );
            case 'global_schema_updated':
                return sprintf( __( 'Global schema markup updated (%s)', 'dsquared-hub-connector' ), $entry['schema_type'] ?? 'custom' );
            case 'seo_meta_sync':
                return sprintf( __( 'SEO meta synced for post #%s', 'dsquared-hub-connector' ), $entry['post_id'] ?? '?' );
            case 'ai_discovery':
                return __( 'AI discovery files regenerated', 'dsquared-hub-connector' );
            case 'content_decay':
                return sprintf( __( 'Content decay scan completed — %d stale posts found', 'dsquared-hub-connector' ), $entry['stale_count'] ?? 0 );
            case 'form_capture':
                return sprintf( __( 'Lead captured from %s', 'dsquared-hub-connector' ), $entry['form_plugin'] ?? 'form' );
            default:
                return ucfirst( str_replace( '_', ' ', $entry['action'] ?? 'Unknown action' ) );
        }
    }

    /**
     * Menu icon SVG
     */
    private static function get_menu_icon() {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><rect x="2" y="3" width="16" height="14" rx="2" fill="none" stroke="currentColor" stroke-width="1.5"/><path d="M5 7h4c2 0 3.5 1.5 3.5 3.5S11 14 9 14H5V7z" fill="none" stroke="currentColor" stroke-width="1.2"/><path d="M13 7h3v3" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>';
    }
}
