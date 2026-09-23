'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const path = require('node:path');

test('schema endpoint rejects an unresolved interior page before any global write', () => {
  const modulePath = path.resolve(__dirname, '../includes/modules/class-dhc-schema.php');
  const script = `
    define('ABSPATH', '/tmp/');
    class DHC_API_Key { static function is_module_available($name) { return true; } }
    class DHC_Core { static function resolve_post_id_from_url($url) { return 0; } }
    class WP_Error {
      public $code; public $data;
      function __construct($code, $message, $data) { $this->code = $code; $this->data = $data; }
    }
    class Fake_Request {
      function get_param($key) {
        $values = array('schema' => array('@context' => 'https://schema.org', '@type' => 'SportsClub'),
          'url' => 'https://theclubnj.com/marlboro/', 'schema_type' => 'SportsClub');
        return isset($values[$key]) ? $values[$key] : null;
      }
    }
    function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
    function home_url($path = '/') { return 'https://theclubnj.com' . $path; }
    function get_option() { throw new Exception('Global storage was read'); }
    function update_option() { throw new Exception('Global storage was written'); }
    require ${JSON.stringify(modulePath)};
    $result = DHC_Schema::handle_request(new Fake_Request());
    echo json_encode(array('code' => $result->code, 'status' => $result->data['status']));
  `;
  const result = spawnSync('php', ['-r', script], { encoding: 'utf8' });
  assert.equal(result.status, 0, result.stderr);
  assert.deepEqual(JSON.parse(result.stdout), { code: 'dhc_schema_target_not_found', status: 404 });
});

test('schema endpoint rejects a post ID that does not match the target URL', () => {
  const modulePath = path.resolve(__dirname, '../includes/modules/class-dhc-schema.php');
  const script = `
    define('ABSPATH', '/tmp/');
    class DHC_API_Key { static function is_module_available($name) { return true; } }
    class DHC_Core { static function resolve_post_id_from_url($url) { return 42; } }
    class WP_Error {
      public $code; public $data;
      function __construct($code, $message, $data) { $this->code = $code; $this->data = $data; }
    }
    class Fake_Request {
      function get_param($key) {
        $values = array('schema' => array('@context' => 'https://schema.org', '@type' => 'SportsClub'),
          'url' => 'https://theclubnj.com/marlboro/', 'post_id' => 99, 'schema_type' => 'SportsClub');
        return isset($values[$key]) ? $values[$key] : null;
      }
    }
    function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
    function home_url($path = '/') { return 'https://theclubnj.com' . $path; }
    function get_option() { throw new Exception('Global storage was read'); }
    function update_option() { throw new Exception('Global storage was written'); }
    require ${JSON.stringify(modulePath)};
    $result = DHC_Schema::handle_request(new Fake_Request());
    echo json_encode(array('code' => $result->code, 'status' => $result->data['status']));
  `;
  const result = spawnSync('php', ['-r', script], { encoding: 'utf8' });
  assert.equal(result.status, 0, result.stderr);
  assert.deepEqual(JSON.parse(result.stdout), { code: 'dhc_schema_target_mismatch', status: 409 });
});

test('URL-scoped homepage schema does not render on an interior location page', () => {
  const modulePath = path.resolve(__dirname, '../includes/modules/class-dhc-schema.php');
  const script = `
    define('ABSPATH', '/tmp/');
    function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
    function home_url($path = '/') { return 'https://theclubnj.com' . $path; }
    function is_front_page() { return false; }
    function is_singular() { return true; }
    function get_the_ID() { return 42; }
    function get_permalink($id) { return 'https://theclubnj.com/marlboro/'; }
    require ${JSON.stringify(modulePath)};
    $method = new ReflectionMethod('DHC_Schema', 'global_entry_matches_page');
    $home = $method->invoke(null, array('url' => 'https://theclubnj.com/'));
    $marlboro = $method->invoke(null, array('url' => 'https://theclubnj.com/marlboro/'));
    $monroe = $method->invoke(null, array('url' => 'https://theclubnj.com/monroe/'));
    echo json_encode(array($home, $marlboro, $monroe));
  `;
  const result = spawnSync('php', ['-r', script], { encoding: 'utf8' });
  assert.equal(result.status, 0, result.stderr);
  assert.deepEqual(JSON.parse(result.stdout), [false, true, false]);
});
