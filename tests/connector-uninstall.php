<?php
// Isolated WordPress uninstall fixture: no network, database, or real credentials.
$fixture_root = sys_get_temp_dir() . '/dhc-uninstall-fixture-' . getmypid() . '/';
if ( ! is_dir( $fixture_root ) ) mkdir( $fixture_root, 0700, true );
define( 'ABSPATH', $fixture_root );
define( 'WP_UNINSTALL_PLUGIN', true );

$expected_options = array(
    'dhc_api_key','dhc_telemetry_token','dhc_telemetry_binding','dhc_telemetry_provision_retry',
    'dhc_modules','dhc_subscription','dhc_activity_log','dhc_default_author','dhc_last_heartbeat',
    'dhc_installed_version','dhc_track_queue','dhc_hub_profile_synced','dhc_legacy_cleanup_version',
    'dhc_legacy_cleanup_result','dhc_disable_auto_update','dhc_dynamic_transient_keys',
    'dhc_generated_file_ownership','dhc_global_schemas','dhc_cwv_metrics','dhc_ai_business_profile',
    'dhc_business_profile','dhc_indexnow_key','dhc_ai_discovery_llms_settings',
    'dhc_ai_discovery_last_validation','dhc_ai_discovery_indexnow_queue',
    'dhc_ai_discovery_indexnow_last_submit','dhc_content_decay_results','dhc_lead_stats',
    'dhc_lead_monthly_count','dhc_form_inbox','dhc_tracking_scripts','dhc_dashboard_events',
    'dhc_indexing_live_validation','dhc_indexnow_last_status','dhc_event_tracker','dhc_llms_txt_raw',
    'dhc_404_log','dhc_broken_links','dhc_link_scan_last_run','dhc_redirects','dhc_ga4_measurement_id',
    'dhc_gtm_container_id','dhc_analytics_enabled','dhc_inventory_last_pushed','dhc_active_crawl',
    'dhc_crawler_diagnostics',
);
$fixture_options = array_fill_keys( $expected_options, 'fake-state' );
$dynamic_keys = array(
    'dhc_dashboard_cache_d42',
    'dhc_llms_link_v2_' . str_repeat( 'a', 32 ),
    'dhc_schema_suggestion_7_19',
    'dhc_rate_' . str_repeat( 'b', 32 ),
);
$fixture_options['dhc_dynamic_transient_keys'] = array_fill_keys( array_merge( $dynamic_keys, array( 'unrelated_cache' ) ), time() + 600 );
$fixture_options['dhc_generated_file_ownership'] = array( 'llms-full.txt' => hash( 'sha256', 'owned full' ) );

$static_transients = array(
    'dhc_subscription_cache','dhc_subscription_cache_site_bound_v1','dhc_update_cache',
    'dhc_fallback_profile_v1','dhc_show_sync_notice','dhc_crawler_lock','dhc_dashboard_cache',
    'dhc_dashboard_cache_d7','dhc_dashboard_cache_d14','dhc_dashboard_cache_d28',
    'dhc_dashboard_cache_d30','dhc_dashboard_cache_d60','dhc_dashboard_cache_d90',
    'dhc_dashboard_cache_d180','dhc_dashboard_cache_d365',
);
$fixture_transients = array_fill_keys( array_merge( $static_transients, $dynamic_keys ), 'fake-state' );
$fixture_transients['unrelated_cache'] = 'preserve';
$fixture_cron = array_fill_keys( array(
    'dhc_content_decay_scan','dhc_monthly_lead_reset','dhc_heartbeat_ping','dhc_auto_populate_profile',
    'dhc_provision_telemetry_token','dhc_inventory_cron','dhc_link_scan_cron','dhc_crawler_poll',
    'dhc_crawler_continue','dhc_ai_discovery_flush_indexnow','dhc_ai_discovery_daily_regenerate',
), 1 );
$fixture_remote_calls = 0;

$fixture_db_options = array_fill_keys( array(
    'dhc_leads_2026_10','_transient_dhc_dashboard_cache_d42','_transient_timeout_dhc_dashboard_cache_d42',
    '_transient_dhc_llms_link_v2_' . str_repeat( 'a', 32 ),
    '_transient_timeout_dhc_llms_link_v2_' . str_repeat( 'a', 32 ),
    '_transient_dhc_schema_suggestion_7_19','_transient_timeout_dhc_schema_suggestion_7_19',
    '_transient_dhc_rate_' . str_repeat( 'b', 32 ),'_transient_timeout_dhc_rate_' . str_repeat( 'b', 32 ),
), 'remove' );
$fixture_db_options['dhc_leadsX2026_10'] = 'preserve';
$fixture_db_options['_transient_dhc_llmsXlink_v2_' . str_repeat( 'a', 32 )] = 'preserve';
$fixture_db_options['other_plugin_state'] = 'preserve';

$fixture_postmeta = array_fill_keys( array(
    '_dhc_private','_d2_custom_schema','_d2_custom_schema_source','_d2_custom_schema_error',
    '_d2_custom_schema_guided','_d2_custom_schema_mode',
), 'remove' );
$fixture_postmeta['adhc_private'] = 'preserve';
$fixture_postmeta['_dhcXprivate'] = 'preserve';
$fixture_postmeta['_d2_custom_schema_extra'] = 'preserve';

file_put_contents( ABSPATH . 'llms.txt', 'user-owned summary' );
file_put_contents( ABSPATH . 'llms-full.txt', 'owned full' );
file_put_contents( ABSPATH . '.htaccess', "Before\n# DHC: force UTF-8 charset on llms txt files\n<FilesMatch \"^llms(?:-full)?\\.txt$\">\nAddCharset UTF-8 .txt\n</FilesMatch>\n# /DHC: force UTF-8 charset\nAfter\n" );

function get_option( $key, $default = false ) { return $GLOBALS['fixture_options'][ $key ] ?? $default; }
function delete_option( $key ) { unset( $GLOBALS['fixture_options'][ $key ] ); }
function delete_transient( $key ) { unset( $GLOBALS['fixture_transients'][ $key ] ); }
function wp_next_scheduled( $key ) { return $GLOBALS['fixture_cron'][ $key ] ?? false; }
function wp_unschedule_event( $time, $key ) { unset( $GLOBALS['fixture_cron'][ $key ] ); }
function wp_clear_scheduled_hook( $key ) { unset( $GLOBALS['fixture_cron'][ $key ] ); }
function wp_remote_post( ...$args ) { $GLOBALS['fixture_remote_calls']++; }

$wpdb = new class {
    public $postmeta = 'fixture_postmeta';
    public $options = 'fixture_options_table';
    public $queries = array();
    public function esc_like( $value ) { return addcslashes( $value, '_%\\' ); }
    public function prepare( $sql, $value ) { return str_replace( '%s', "'" . $value . "'", $sql ); }
    private function like_regex( $pattern ) {
        $regex = '';
        $escaped = false;
        for ( $i = 0, $len = strlen( $pattern ); $i < $len; $i++ ) {
            $char = $pattern[ $i ];
            if ( $escaped ) { $regex .= preg_quote( $char, '/' ); $escaped = false; continue; }
            if ( '\\' === $char ) { $escaped = true; continue; }
            if ( '%' === $char ) { $regex .= '.*'; continue; }
            if ( '_' === $char ) { $regex .= '.'; continue; }
            $regex .= preg_quote( $char, '/' );
        }
        return '/^' . $regex . '$/';
    }
    public function query( $sql ) {
        $this->queries[] = $sql;
        if ( false !== strpos( $sql, $this->options ) && preg_match( "/LIKE '([^']+)'/", $sql, $match ) ) {
            $regex = $this->like_regex( $match[1] );
            foreach ( array_keys( $GLOBALS['fixture_db_options'] ) as $key ) if ( preg_match( $regex, $key ) ) unset( $GLOBALS['fixture_db_options'][ $key ] );
        }
        if ( false !== strpos( $sql, $this->postmeta ) && preg_match( "/LIKE '([^']+)'/", $sql, $match ) ) {
            $regex = $this->like_regex( $match[1] );
            foreach ( array_keys( $GLOBALS['fixture_postmeta'] ) as $key ) if ( preg_match( $regex, $key ) ) unset( $GLOBALS['fixture_postmeta'][ $key ] );
        }
        if ( false !== strpos( $sql, $this->postmeta ) && false !== strpos( $sql, ' IN ' ) ) {
            preg_match_all( "/'([^']+)'/", $sql, $matches );
            foreach ( $matches[1] as $key ) unset( $GLOBALS['fixture_postmeta'][ $key ] );
        }
        return 0;
    }
};

require __DIR__ . '/../uninstall.php';

function fixture_fail( $message ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
if ( $fixture_options ) fixture_fail( 'not every static connector option was removed' );
if ( $fixture_transients !== array( 'unrelated_cache' => 'preserve' ) ) fixture_fail( 'external-cache transient cleanup was not exact' );
if ( $fixture_cron || 0 !== $fixture_remote_calls ) fixture_fail( 'cron cleanup or no-remote contract failed' );
if ( $fixture_db_options !== array( 'dhc_leadsX2026_10' => 'preserve', '_transient_dhc_llmsXlink_v2_' . str_repeat( 'a', 32 ) => 'preserve', 'other_plugin_state' => 'preserve' ) ) fixture_fail( 'bounded option SQL removed too much or too little' );
if ( $fixture_postmeta !== array( 'adhc_private' => 'preserve', '_dhcXprivate' => 'preserve', '_d2_custom_schema_extra' => 'preserve' ) ) fixture_fail( 'bounded postmeta SQL removed too much or too little' );
if ( ! is_file( ABSPATH . 'llms.txt' ) || 'user-owned summary' !== file_get_contents( ABSPATH . 'llms.txt' ) ) fixture_fail( 'unowned llms.txt was removed' );
if ( is_file( ABSPATH . 'llms-full.txt' ) ) fixture_fail( 'owned llms-full.txt was not removed' );
$htaccess = file_get_contents( ABSPATH . '.htaccess' );
if ( false !== strpos( $htaccess, '# DHC:' ) || false === strpos( $htaccess, 'Before' ) || false === strpos( $htaccess, 'After' ) ) fixture_fail( 'marker-bounded .htaccess cleanup failed' );

@unlink( ABSPATH . 'llms.txt' );
@unlink( ABSPATH . '.htaccess' );
@rmdir( ABSPATH );
echo "PASS: uninstall removes only owned connector state, files, metadata, caches and schedules\n";
