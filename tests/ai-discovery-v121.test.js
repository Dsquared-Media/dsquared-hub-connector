'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');

const root = path.join(__dirname, '..');
const modulePath = path.join(root, 'includes/modules/class-dhc-ai-discovery.php');
const discovery = fs.readFileSync(modulePath, 'utf8');
const plugin = fs.readFileSync(path.join(root, 'dsquared-hub-connector.php'), 'utf8');
const admin = fs.readFileSync(path.join(root, 'includes/class-dhc-admin.php'), 'utf8');

test('robots output contains only complete user-agent groups and replaces the legacy block safely', () => {
  const script = `
    define('ABSPATH', '/tmp/');
    function add_action() {}
    function add_filter() {}
    require ${JSON.stringify(modulePath)};
    $d = new DHC_AI_Discovery();
    $legacy = "Allow: /existing-public-file.txt\\n\\n# Dsquared Hub Connector — AI Discovery\\nAllow: /llms.txt\\nAllow: /llms-full.txt\\nAllow: /.well-known/ai-plugin.json\\n# START YOAST BLOCK\\nUser-agent: *\\nDisallow: /private/\\n\\nUser-agent: GPTBot\\nDisallow: /private-ai/\\n";
    echo $d->add_robots_entries($legacy, true);
  `;
  const result = spawnSync('php', ['-r', script], { encoding: 'utf8' });
  assert.equal(result.status, 0, result.stderr);
  const output = result.stdout;
  assert.match(output, /^Allow: \/existing-public-file\.txt/m);
  assert.doesNotMatch(output, /AI Discovery\nAllow:/);
  assert.match(output, /# Dsquared Hub Connector - AI Discovery\nUser-agent: \*\nAllow: \/llms\.txt/);
  assert.equal((output.match(/^User-agent: GPTBot$/gm) || []).length, 1);
  assert.match(output, /User-agent: Google-Extended\nAllow: \/\n\nUser-agent: PerplexityBot/);
});

test('manual Markdown keeps legitimate backslashes and editor modes share the versioned setting', () => {
  const script = `
    define('ABSPATH', '/tmp/');
    function add_action() {}
    function add_filter() {}
    function sanitize_key($v) { return preg_replace('/[^a-z0-9_-]/', '', strtolower($v)); }
    function esc_url_raw($v) { return $v; }
    function sanitize_text_field($v) { return trim((string) $v); }
    function current_time() { return '2026-09-30 10:00:00'; }
    function wp_check_invalid_utf8($v) { return $v; }
    require ${JSON.stringify(modulePath)};
    $d = new DHC_AI_Discovery();
    echo json_encode($d->sanitize_editor_settings(array('mode' => 'manual', 'custom_content' => '# Site\\n\\\\path\\\\name')));
  `;
  const result = spawnSync('php', ['-r', script], { encoding: 'utf8' });
  assert.equal(result.status, 0, result.stderr);
  const settings = JSON.parse(result.stdout);
  assert.equal(settings.mode, 'manual');
  assert.equal(settings.custom_content, '# Site\\n\\path\\name');
  assert.match(discovery, /const SETTINGS_OPTION\s+=\s+'dhc_ai_discovery_llms_settings'/);
  assert.match(discovery, /save_editor_settings\( \$data, 'hub' \)/);
  assert.match(discovery, /save_editor_settings\( array\([\s\S]*?'mode' => 'manual'/);
});

test('legacy raw content migrates without initializing gated runtime hooks', () => {
  const script = `
    define('ABSPATH', '/tmp/');
    $options = array('dhc_llms_txt_raw' => "# Legacy\\n\\nKeep this text.");
    function get_option($key, $default = null) { global $options; return array_key_exists($key, $options) ? $options[$key] : $default; }
    function update_option($key, $value) { global $options; $options[$key] = $value; }
    function delete_option($key) { global $options; unset($options[$key]); }
    function current_time() { return '2026-09-30 10:00:00'; }
    require ${JSON.stringify(modulePath)};
    $settings = DHC_AI_Discovery::migrate_editor_settings_option();
    echo json_encode(array('settings' => $settings, 'legacy_exists' => array_key_exists('dhc_llms_txt_raw', $options)));
  `;
  const result = spawnSync('php', ['-r', script], { encoding: 'utf8' });
  assert.equal(result.status, 0, result.stderr);
  const value = JSON.parse(result.stdout);
  assert.equal(value.settings.mode, 'append');
  assert.equal(value.settings.custom_content, '# Legacy\n\nKeep this text.');
  assert.equal(value.legacy_exists, false);
});

test('auto discovery excludes thank-you slugs and common SEO noindex metadata', () => {
  const script = `
    define('ABSPATH', '/tmp/');
    function add_action() {}
    function add_filter() {}
    function get_post_type_object() { return (object) array('public' => true); }
    function get_post_meta($id, $key) {
      if ($id === 3 && $key === '_aioseo_robots_noindex') return '1';
      return '';
    }
    require ${JSON.stringify(modulePath)};
    $d = new DHC_AI_Discovery();
    $m = new ReflectionMethod('DHC_AI_Discovery', 'post_is_indexable');
    $post = function($id, $slug) { return (object) array('ID' => $id, 'post_status' => 'publish', 'post_type' => 'page', 'post_name' => $slug); };
    echo json_encode(array(
      $m->invoke($d, $post(1, 'thank-you')),
      $m->invoke($d, $post(2, 'contact-thank-you')),
      $m->invoke($d, $post(3, 'services')),
      $m->invoke($d, $post(4, 'services'))
    ));
  `;
  const result = spawnSync('php', ['-r', script], { encoding: 'utf8' });
  assert.equal(result.status, 0, result.stderr);
  assert.deepEqual(JSON.parse(result.stdout), [false, false, false, true]);
});

test('new Hub REST routes all use the existing authenticated subscription gate and reject invalid keys with 401', () => {
  const script = `
    define('ABSPATH', '/tmp/');
    $routes = array();
    $allow = false;
    $entitled = true;
    function add_action() {}
    function add_filter() {}
    function register_rest_route($ns, $route, $args) { global $routes; $routes[$route] = $args; }
    class WP_Error { public $code; public $message; public $data; public function __construct($c,$m,$d=array()){ $this->code=$c; $this->message=$m; $this->data=$d; } }
    class DHC_API_Key {
      public static function authenticate_request($request) { global $allow; return $allow; }
      public static function is_module_available($module) { global $entitled; return $entitled; }
    }
    require ${JSON.stringify(modulePath)};
    $d = new DHC_AI_Discovery();
    $d->register_routes();
    $denied = $d->check_api_key(null);
    $allow = true;
    $accepted = $d->check_api_key(null);
    $entitled = false;
    $unavailable = $d->check_api_key(null);
    echo json_encode(array('routes' => array_keys($routes), 'denied' => $denied, 'accepted' => $accepted, 'unavailable' => $unavailable));
  `;
  const result = spawnSync('php', ['-r', script], { encoding: 'utf8' });
  assert.equal(result.status, 0, result.stderr);
  const value = JSON.parse(result.stdout);
  assert.ok(value.routes.includes('/ai-discovery/llms'));
  assert.ok(value.routes.includes('/ai-discovery/llms/regenerate'));
  assert.ok(value.routes.includes('/ai-discovery/indexnow/submit'));
  assert.equal(value.denied.data.status, 401);
  assert.equal(value.accepted, true);
  assert.equal(value.unavailable.data.status, 403);
  assert.match(discovery, /'permission_callback'\s*=>\s*array\( \$this, 'check_api_key' \)/);
});

test('forced link validation checks the live HTTP response even for a published internal post', () => {
  const script = `
    define('ABSPATH', '/tmp/'); define('DHC_VERSION', '1.21.0'); define('HOUR_IN_SECONDS', 3600);
    function add_action() {} function add_filter() {}
    function esc_url_raw($v) { return $v; }
    function wp_parse_url($u, $c = -1) { return parse_url($u, $c); }
    function home_url($p = '/') { return 'https://example.com' . $p; }
    function get_transient() { return false; } function set_transient() {}
    function url_to_postid() { return 7; }
    function get_post() { return (object) array('ID'=>7,'post_status'=>'publish','post_type'=>'page','post_name'=>'services'); }
    function get_post_type_object() { return (object) array('public'=>true); }
    function get_post_meta() { return ''; } function get_post_status() { return 'publish'; }
    function untrailingslashit($v) { return rtrim($v, '/'); }
    function wp_remote_head() { return array('response'=>array('code'=>404),'headers'=>array()); }
    function is_wp_error() { return false; }
    function wp_remote_retrieve_response_code($r) { return $r['response']['code']; }
    function wp_remote_retrieve_header() { return ''; }
    require ${JSON.stringify(modulePath)};
    echo json_encode((new DHC_AI_Discovery())->validate_discovery_link('https://example.com/services/', true));
  `;
  const result = spawnSync('php', ['-r', script], { encoding: 'utf8' });
  assert.equal(result.status, 0, result.stderr);
  const value = JSON.parse(result.stdout);
  assert.equal(value.ok, false);
  assert.equal(value.status, 404);
  assert.match(discovery, /foreach \( \$this->auto_discovery_links\(\) as \$link \) \{\s*\$validation = \$this->validate_discovery_link/);
  assert.match(discovery, /wp_html_excerpt\( \$description, 120/);
  assert.match(discovery, /'post_type' => 'post'[\s\S]*?'posts_per_page' => 10/);
});

test('upgrade regenerates discovery files and the AI Discovery tab contains the editor, preview, validation, and IndexNow control', () => {
  const upgrade = plugin.slice(plugin.indexOf("$installed = get_option( 'dhc_installed_version' )"));
  assert.match(upgrade, /migrate_editor_settings_option/);
  assert.match(upgrade, /regenerate_static_files/);
  assert.match(upgrade, /DHC_API_Key::is_module_available\( 'ai_discovery' \)/);
  assert.ok(upgrade.indexOf('migrate_editor_settings_option') < upgrade.indexOf("DHC_API_Key::is_module_available( 'ai_discovery' )"));
  assert.match(discovery, /migrate_editor_settings[\s\S]*?\$settings\['mode'\]\s*=\s*'append'/);

  const aiTabStart = admin.indexOf('id="tab-ai-discovery"');
  const healthTabStart = admin.indexOf('id="tab-health"', aiTabStart);
  assert.ok(aiTabStart >= 0 && healthTabStart > aiTabStart);
  const aiTab = admin.slice(aiTabStart, healthTabStart);
  assert.match(aiTab, /id="dhc-llms-links"/);
  assert.match(aiTab, /name="dhc-llms-mode"/);
  assert.match(aiTab, /id="dhc-llms-preview"/);
  assert.match(aiTab, /id="dhc-llms-validation"/);
  assert.match(aiTab, /id="dhc-submit-indexnow-all"/);
  assert.match(aiTab, /Submit all public URLs now/);
});

test('IndexNow key and dynamic discovery responses explicitly return 200 text/plain UTF-8', () => {
  const keyStart = discovery.indexOf('public function serve_indexnow_key');
  const keyMethod = discovery.slice(keyStart, discovery.indexOf('/* ─── .well-known', keyStart));
  assert.match(keyMethod, /status_header\( 200 \)/);
  assert.match(keyMethod, /Content-Type: text\/plain; charset=utf-8/);
  assert.match(discovery, /maybe_serve_llms_txt_early[\s\S]*?status_header\( 200 \)[\s\S]*?Content-Type: text\/plain; charset=utf-8/);
});
