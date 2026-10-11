<?php
// Isolated WordPress fixture: no network, database, or real credentials.
define('ABSPATH', '/nonexistent-wordpress-fixture/');
$options = []; $transients = []; $hooks = []; $scheduled = []; $public = '';
$site = 'https://example.test/'; $remote = null; $remote_calls = 0;
function check($condition, $label) { if (!$condition) { fwrite(STDERR, "FAIL: $label\n"); exit(1); } }
function get_option($n, $default=false) { return $GLOBALS['options'][$n] ?? $default; }
function update_option($n, $v, $autoload=null) { $old=get_option($n); $GLOBALS['options'][$n]=$v; if($old !== $v) do_action('update_option_'.$n, $old, $v); return true; }
function add_option($n,$v) { $GLOBALS['options'][$n]=$v; do_action('add_option_'.$n,$n,$v); }
function delete_option($n) { unset($GLOBALS['options'][$n]); do_action('delete_option_'.$n,$n); }
function add_action($n,$cb,...$args) { $GLOBALS['hooks'][$n][]=$cb; }
function add_filter(...$args) {}
function do_action($n,...$args) { foreach($GLOBALS['hooks'][$n] ?? [] as $cb) call_user_func_array($cb,$args); }
function get_transient($n) { return $GLOBALS['transients'][$n] ?? false; }
function set_transient($n,$v,$ttl) { $GLOBALS['transients'][$n]=$v; }
function delete_transient($n) { unset($GLOBALS['transients'][$n]); }
function home_url($p='') { return $GLOBALS['site']; }
function get_site_url() { return home_url(); }
function plugin_dir_path($f) { return dirname($f).'/'; }
function plugin_dir_url($f) { return 'https://example.test/plugin/'; }
function plugin_basename($f) { return basename($f); }
function register_activation_hook(...$args) {}
function register_deactivation_hook(...$args) {}
function wp_next_scheduled($n) { return $GLOBALS['scheduled'][$n] ?? false; }
function wp_schedule_single_event($t,$n) { $GLOBALS['scheduled'][$n]=$t; return true; }
function wp_clear_scheduled_hook($n) { unset($GLOBALS['scheduled'][$n]); }
function wp_unschedule_event($t,$n) { wp_clear_scheduled_hook($n); }
function wp_remote_post(...$args) { return ($GLOBALS['remote'])(...$args); }
function wp_remote_get(...$args) { $GLOBALS['remote_calls']++; return ($GLOBALS['remote'])(...$args); }
function wp_remote_retrieve_response_code($r) { return $r['code']; }
function wp_remote_retrieve_body($r) { return json_encode($r['body']); }
function wp_json_encode($v) { return json_encode($v); }
function is_wp_error($v) { return $v instanceof WP_Error; }
class WP_Error { function __construct(...$args) {} }
function esc_attr($v) { return htmlspecialchars($v); }
function esc_html__($v,...$args) { return $v; }
function sanitize_text_field($v) { return $v; }
function current_user_can(...$args) { return false; }
function is_customize_preview() { return false; }
function rest_url($p) { return home_url().'wp-json/'.$p; }
function wp_create_nonce($p) { return 'fixture-nonce'; }
function wp_enqueue_script(...$args) { $GLOBALS['public'].=file_get_contents(__DIR__.'/../assets/dhc-site-health.js'); }
function wp_localize_script($handle,$name,$cfg) { $GLOBALS['public'].=json_encode($cfg); }
function current_time($v) { return '2026-10-05'; }
function flush_rewrite_rules(...$args) {}
require __DIR__.'/../dsquared-hub-connector.php';
$keyA='dhc_live_'.str_repeat('a',40); $keyB='dhc_live_'.str_repeat('b',40);
$tokenA='dhct_'.str_repeat('a',40); $tokenB='dhct_'.str_repeat('b',40);
function binding($token,$key) { return ['token'=>$token,'parent_hash'=>hash('sha256',$key),'site_url'=>home_url()]; }
function render_public($expected) {
    global $public,$keyA,$keyB;
    $public=''; ob_start(); DHC_Event_Tracker::inject_tracker(); $public.=ob_get_clean();
    DHC_Site_Health::enqueue_cwv_script();
    check(strpos($public,$keyA)===false && strpos($public,$keyB)===false, 'private key absent in actual rendered HTML, localized config and loaded JS');
    check(DHC_Heartbeat::public_telemetry_token()===$expected, 'public token fail-closed binding');
    if ($expected) check(substr_count($public,$expected)===2, 'both real render sinks use bound narrow token');
    else check(!preg_match('/dhct_[a-f0-9]{40}/',$public), 'invalid token never rendered');
}
add_option('dhc_api_key',$keyA);
check(isset($scheduled[DHC_Heartbeat::TELEMETRY_PROVISION_HOOK]), 'direct key add schedules provisioning');
foreach ([$keyA,'invalid',$tokenA] as $bad) {
    $options['dhc_telemetry_token']=$bad; unset($options['dhc_telemetry_binding']); render_public('');
}
$options['dhc_telemetry_binding']=binding($keyA,$keyA); render_public('');
$options['dhc_telemetry_binding']=binding($tokenA,$keyB); render_public('');
$options['dhc_telemetry_binding']=binding($tokenA,$keyA); render_public($tokenA);
$site='https://other.test/'; render_public(''); $site='https://example.test/';
foreach ([$keyA,'malformed'] as $bad) {
    DHC_Heartbeat::clear_telemetry_state(); $remote=fn()=>['code'=>200,'body'=>['telemetry_token'=>$bad]];
    check(!DHC_Heartbeat::maybe_provision_telemetry_token(),'malformed/private provision response rejected'); render_public('');
}
DHC_Heartbeat::clear_telemetry_state();
$remote=function() use($keyB,$tokenA) { update_option('dhc_api_key',$keyB); return ['code'=>200,'body'=>['telemetry_token'=>$tokenA]]; };
check(!DHC_Heartbeat::maybe_provision_telemetry_token(),'late response for A rejected after switch B'); render_public('');
$remote=fn()=>['code'=>200,'body'=>['telemetry_token'=>$tokenB]];
check(DHC_Heartbeat::maybe_provision_telemetry_token(),'new B telemetry provisions'); render_public($tokenB);
$remote=fn()=>['code'=>200,'body'=>['tier'=>'pro','site_id'=>'site-fixture']];
check(DHC_API_Key::validate($keyB)['valid'],'warm subscription cache');
$before=$remote_calls; $remote=fn()=>['code'=>403,'body'=>['message'=>'revoked']];
$request=new class($keyB) { private $key; function __construct($k){$this->key=$k;} function get_header($n){return $this->key;} };
check(is_wp_error(DHC_API_Key::authenticate_request($request)),'revoked key denied despite warm cache');
check($remote_calls===$before+1,'privileged auth actually called authoritative Hub');
$remote=fn()=>new WP_Error(); check(is_wp_error(DHC_API_Key::authenticate_request($request)),'Hub outage fails closed');
$remote=fn()=>['code'=>200,'body'=>['tier'=>'pro','site_id'=>'site-fixture']];
check(DHC_API_Key::authenticate_request($request)===true,'valid B privileged auth remains usable');
update_option('dhc_api_key',$keyA); render_public('');
check(get_transient(DHC_API_Key::CACHE_KEY)===false,'direct change invalidates cached validation');
delete_option('dhc_api_key'); check(!isset($scheduled[DHC_Heartbeat::TELEMETRY_PROVISION_HOOK]),'delete clears pending provisioning'); render_public('');
$options['dhc_api_key']=$keyB; $options['dhc_telemetry_binding']=binding($tokenB,$keyB);
$remote=fn()=>['code'=>200,'body'=>[]]; dhc_deactivate(); render_public('');
check(get_transient(DHC_API_Key::CACHE_KEY)===false,'deactivation clears current auth cache');
check(get_option('dhc_api_key')===$keyB,'deactivation retains private key for intentional reactivation, not remote revocation');
echo "PASS: public rendering, token provenance, key option hooks, late responses, revoked-cache authentication, outage, valid control, deactivation\n";
