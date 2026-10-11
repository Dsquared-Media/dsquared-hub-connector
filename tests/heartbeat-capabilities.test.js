'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');

const root = path.join(__dirname, '..');
const rest = fs.readFileSync(path.join(root, 'includes/class-dhc-rest.php'), 'utf8');
const heartbeat = fs.readFileSync(path.join(root, 'includes/class-dhc-heartbeat.php'), 'utf8');

test('heartbeat sends the connector capability inventory to the Hub', () => {
  assert.match(heartbeat, /'advertised_routes'\s*=>\s*DHC_REST::advertised_routes\(\)/);
});

test('status exposes the same capability inventory', () => {
  assert.match(rest, /'advertised_routes'\s*=>\s*self::advertised_routes\(\)/);
});

test('capability inventory includes the authenticated same-site fetch route', () => {
  const inventoryStart = rest.indexOf('public static function advertised_routes()');
  assert.ok(inventoryStart >= 0, 'advertised_routes() must exist');
  const inventory = rest.slice(inventoryStart, rest.indexOf('public static function register_routes()', inventoryStart));
  assert.match(inventory, /'\/fetch-page'/);
  assert.match(inventory, /'\/posts\/heading'/);
  assert.match(inventory, /'\/media\/alt'/);
  assert.match(inventory, /'\/ai-discovery\/raw'/);
  assert.match(inventory, /DHC_API_Key::is_module_available\( 'ai_discovery' \)/);
  assert.match(inventory, /'\/ai-discovery\/llms'/);
  assert.match(inventory, /'\/ai-discovery\/llms\/regenerate'/);
  assert.match(inventory, /'\/ai-discovery\/indexnow\/submit'/);
  assert.match(inventory, /'\/leads\/stats'/);
  assert.doesNotMatch(inventory, /'\/leads'/);
});

test('top-level REST registry does not register routes with obsolete callbacks', () => {
  const registryStart = rest.indexOf('public static function register_routes()');
  const registryEnd = rest.indexOf('public static function handle_fetch_page', registryStart);
  const registry = rest.slice(registryStart, registryEnd);
  assert.doesNotMatch(registry, /DHC_Content_Decay', 'handle_request/);
  assert.doesNotMatch(registry, /DHC_Form_Capture', 'handle_stats_request/);
  assert.match(registry, /'\/ai-discovery'[\s\S]+?'permission_callback'\s*=>\s*array\( __CLASS__, 'authorize_ai_discovery' \)/);
});

test('runtime capability inventory exposes only entitled optional module routes', () => {
  const php = `
    define('ABSPATH', __DIR__);
    class WP_Error {}
    class DHC_API_Key {
      public static function is_module_available($module) { return in_array($module, $GLOBALS['available'], true); }
      public static function authenticate_request($request) { return true; }
    }
    require ${JSON.stringify(path.join(root, 'includes/class-dhc-rest.php'))};
    $GLOBALS['available'] = array();
    $none = DHC_REST::advertised_routes();
    $GLOBALS['available'] = array('auto_post', 'schema', 'seo_meta', 'site_health', 'ai_discovery', 'content_decay', 'form_capture');
    $all = DHC_REST::advertised_routes();
    echo json_encode(array('none' => $none, 'all' => $all));
  `;
  const value = JSON.parse(execFileSync('php', ['-r', php], { encoding: 'utf8' }));
  const prefix = '/wp-json/dsquared-hub/v1';
  for (const route of [
    '/post', '/schema', '/seo-meta', '/media/alt', '/posts/content',
    '/posts/heading', '/seo-meta/bulk', '/link-scan', '/ai-discovery',
    '/ai-discovery/profile', '/ai-discovery/ping', '/ai-discovery/raw',
    '/ai-discovery/llms', '/ai-discovery/llms/regenerate', '/ai-discovery/indexnow/submit',
    '/content-decay', '/content-decay/scan', '/leads/stats',
  ]) {
    assert.ok(!value.none.includes(prefix + route), `${route} must be absent when its module is unavailable`);
    assert.ok(value.all.includes(prefix + route), `${route} must be advertised when its module is available`);
  }
  assert.ok(!value.all.includes(prefix + '/leads'));
});

test('legacy AI Discovery route requires both authentication and module entitlement', () => {
  const php = `
    define('ABSPATH', __DIR__);
    class WP_Error { public $code; public function __construct($code) { $this->code = $code; } }
    class DHC_API_Key {
      public static function authenticate_request($request) { return $GLOBALS['authenticated'] ? true : new WP_Error('unauthorized'); }
      public static function is_module_available($module) { return $GLOBALS['available']; }
    }
    require ${JSON.stringify(path.join(root, 'includes/class-dhc-rest.php'))};
    $GLOBALS['authenticated'] = false; $GLOBALS['available'] = true;
    $unauthenticated = DHC_REST::authorize_ai_discovery(null);
    $GLOBALS['authenticated'] = true; $GLOBALS['available'] = false;
    $unentitled = DHC_REST::authorize_ai_discovery(null);
    $GLOBALS['available'] = true;
    $accepted = DHC_REST::authorize_ai_discovery(null);
    echo json_encode(array(
      'unauthenticated' => $unauthenticated->code,
      'unentitled' => $unentitled->code,
      'accepted' => $accepted,
    ));
  `;
  const value = JSON.parse(execFileSync('php', ['-r', php], { encoding: 'utf8' }));
  assert.equal(value.unauthenticated, 'unauthorized');
  assert.equal(value.unentitled, 'dhc_ai_discovery_unavailable');
  assert.equal(value.accepted, true);
});
