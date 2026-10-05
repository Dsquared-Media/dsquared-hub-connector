<?php
// No WordPress database, filesystem removal, network, or real credentials.
define('ABSPATH', '/nonexistent-wordpress-fixture/');
define('WP_UNINSTALL_PLUGIN', true);
$fixture_options = array_fill_keys(array('dhc_api_key','dhc_telemetry_token','dhc_telemetry_binding','dhc_telemetry_provision_retry'), 'fake-state');
$fixture_transients = array_fill_keys(array('dhc_subscription_cache','dhc_subscription_cache_site_bound_v1','dhc_update_cache'), 'fake-state');
$fixture_cron = array('dhc_provision_telemetry_token' => 1);
$fixture_remote_calls = 0;
function delete_option($key) { unset($GLOBALS['fixture_options'][$key]); }
function delete_transient($key) { unset($GLOBALS['fixture_transients'][$key]); }
function wp_next_scheduled($key) { return $GLOBALS['fixture_cron'][$key] ?? false; }
function wp_unschedule_event($time,$key) { unset($GLOBALS['fixture_cron'][$key]); }
function wp_clear_scheduled_hook($key) { unset($GLOBALS['fixture_cron'][$key]); }
function wp_remote_post(...$args) { $GLOBALS['fixture_remote_calls']++; }
$wpdb = new class { public $postmeta='fixture_postmeta'; public function query($sql) { return 0; } };
require __DIR__.'/../uninstall.php';
if ($fixture_options || $fixture_transients || $fixture_cron || $fixture_remote_calls !== 0) {
    fwrite(STDERR, "FAIL: uninstall local cleanup / no remote revocation contract\n"); exit(1);
}
echo "PASS: uninstall removes local private/telemetry credentials, caches and retry; does not revoke remote Hub credentials\n";
