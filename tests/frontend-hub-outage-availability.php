<?php
// Isolated availability fixture. No WordPress install, database, network, or
// real credentials are used.
define( 'ABSPATH', '/nonexistent-wordpress-fixture/' );

$options      = array();
$transients   = array();
$remote_calls = 0;
$admin        = false;
$can_manage   = false;
$cron         = false;
$remote       = null;

function check( $condition, $label ) {
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$label}\n" );
        exit( 1 );
    }
}
function get_option( $name, $default = false ) { return $GLOBALS['options'][ $name ] ?? $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['options'][ $name ] = $value; return true; }
function get_transient( $name ) { return $GLOBALS['transients'][ $name ] ?? false; }
function set_transient( $name, $value, $ttl ) { $GLOBALS['transients'][ $name ] = $value; return true; }
function home_url( $path = '' ) { return 'https://client.example/'; }
function is_admin() { return $GLOBALS['admin']; }
function current_user_can( $capability ) { return $GLOBALS['can_manage'] && 'manage_options' === $capability; }
function wp_doing_cron() { return $GLOBALS['cron']; }
function esc_html__( $value ) { return $value; }
function sanitize_text_field( $value ) { return $value; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_remote_get( $url, $args ) {
    $GLOBALS['remote_calls']++;
    return call_user_func( $GLOBALS['remote'], $url, $args );
}
function wp_remote_retrieve_response_code( $response ) { return $response['code']; }
function wp_remote_retrieve_body( $response ) { return json_encode( $response['body'] ); }
class WP_Error {}

define( 'DHC_HUB_API_BASE', 'https://hub.invalid/api' );
require __DIR__ . '/../includes/class-dhc-api-key.php';

$modules = array( 'schema', 'seo_meta', 'site_health', 'ai_discovery', 'content_decay', 'form_capture' );
$options['dhc_modules'] = array_fill_keys( $modules, true );
$remote = function () { return new WP_Error(); };

// Public rendering has no entitlement cache and the Hub is unavailable. It
// must make zero synchronous requests even though core checks six modules.
$options['dhc_api_key'] = 'dhc_live_' . str_repeat( 'a', 40 );
foreach ( $modules as $module ) {
    check( ! DHC_API_Key::is_module_available( $module ), "public {$module} fails closed" );
}
check( 0 === $remote_calls, 'public page performs no Hub entitlement request' );

// Explicit privileged validation remains authoritative and can warm the cache.
$remote = function () {
    return array( 'code' => 200, 'body' => array( 'tier' => 'pro', 'site_id' => 'site-fixture' ) );
};
check( DHC_API_Key::validate( $options['dhc_api_key'], true )['valid'], 'forced validation succeeds' );
check( 1 === $remote_calls, 'forced validation performs exactly one Hub request' );
foreach ( $modules as $module ) {
    check( DHC_API_Key::is_module_available( $module ), "cached {$module} remains available" );
}
check( 1 === $remote_calls, 'cached module checks perform no extra requests' );

// An unauthenticated admin-ajax/admin-post style request reports is_admin(),
// but must remain Hub-independent just like a normal public request.
$admin = true;
$options['dhc_api_key'] = 'dhc_live_' . str_repeat( 'b', 40 );
$transients = array();
$remote = function () { return new WP_Error(); };
foreach ( $modules as $module ) {
    check( ! DHC_API_Key::is_module_available( $module ), "unauthenticated admin surface {$module} fails closed" );
}
check( 1 === $remote_calls, 'unauthenticated admin surface performs no Hub request' );

// A real administrator cache miss may refresh, but repeated module checks
// still share one result and the timeout remains bounded by production code.
$can_manage = true;
$options['dhc_api_key'] = 'dhc_live_' . str_repeat( 'c', 40 );
$transients = array();
foreach ( $modules as $module ) {
    check( ! DHC_API_Key::is_module_available( $module ), "administrator {$module} fails closed during outage" );
}
check( 2 === $remote_calls, 'administrator outage performs one request, not one per module' );

echo "PASS: public rendering is Hub-independent; controlled refresh is bounded and memoized\n";
