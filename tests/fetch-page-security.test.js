'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const { execFileSync } = require('node:child_process');

const restPath = path.join(__dirname, '..', 'includes', 'class-dhc-rest.php');

function runPhp(scenario) {
  const php = `
    define('ABSPATH', __DIR__);
    define('DHC_VERSION', 'test');
    class WP_Error {
      private $code; private $message; private $data;
      public function __construct($code, $message = '', $data = array()) { $this->code = $code; $this->message = $message; $this->data = $data; }
      public function get_error_code() { return $this->code; }
      public function get_error_message() { return $this->message; }
      public function get_error_data() { return $this->data; }
    }
    class WP_REST_Response {
      public $data; public $status;
      public function __construct($data, $status) { $this->data = $data; $this->status = $status; }
    }
    class DHC_API_Key { public static function is_module_available($module) { return true; } }
    class RequestStub { private $url; public function __construct($url) { $this->url = $url; } public function get_param($key) { return 'url' === $key ? $this->url : null; } }
    function home_url($path = '/') { return 'https://example.com' . $path; }
    function apply_filters($name, $value) { return $value; }
    function current_time($type) { return '2026-10-11 12:00:00'; }
    function is_wp_error($value) { return $value instanceof WP_Error; }
    function wp_remote_retrieve_response_code($response) { return $response['response']['code']; }
    function wp_remote_retrieve_body($response) { return $response['body'] ?? ''; }
    function wp_remote_retrieve_header($response, $name) { return $response['headers'][strtolower($name)] ?? ''; }
    $GLOBALS['calls'] = array();
    $GLOBALS['responses'] = array();
    function wp_remote_get($url, $args) {
      $GLOBALS['calls'][] = array('url' => $url, 'redirection' => $args['redirection'] ?? null);
      return array_shift($GLOBALS['responses']);
    }
    function result_json($result) {
      if ($result instanceof WP_Error) return array('kind' => 'error', 'code' => $result->get_error_code(), 'data' => $result->get_error_data());
      return array('kind' => 'response', 'status' => $result->status, 'data' => $result->data);
    }
    require ${JSON.stringify(restPath)};
    ${scenario}
  `;
  return JSON.parse(execFileSync('php', ['-r', php], { encoding: 'utf8' }));
}

test('fetch-page rejects scheme, port, www and credential origin changes before network access', () => {
  const value = runPhp(`
    $urls = array(
      'http://example.com/page',
      'https://example.com:444/page',
      'https://www.example.com/page',
      'https://user:pass@example.com/page'
    );
    $results = array();
    foreach ($urls as $url) $results[] = result_json(DHC_REST::handle_fetch_page(new RequestStub($url)));
    echo json_encode(array('results' => $results, 'calls' => $GLOBALS['calls']));
  `);
  assert.deepEqual(value.results.map((result) => result.code), ['external_url', 'external_url', 'external_url', 'external_url']);
  assert.equal(value.calls.length, 0);
});

test('fetch-page accepts hostname case and an explicit default HTTPS port', () => {
  const value = runPhp(`
    $GLOBALS['responses'][] = array('response' => array('code' => 200), 'headers' => array(), 'body' => 'ok');
    $result = DHC_REST::handle_fetch_page(new RequestStub('https://EXAMPLE.com:443/page'));
    echo json_encode(array('result' => result_json($result), 'calls' => $GLOBALS['calls']));
  `);
  assert.equal(value.result.kind, 'response');
  assert.equal(value.calls.length, 1);
  assert.equal(value.calls[0].redirection, 0);
});

test('fetch-page blocks an off-origin redirect without requesting its destination', () => {
  const value = runPhp(`
    $GLOBALS['responses'][] = array('response' => array('code' => 302), 'headers' => array('location' => 'https://attacker.test/private'));
    $result = DHC_REST::handle_fetch_page(new RequestStub('https://example.com/start'));
    echo json_encode(array('result' => result_json($result), 'calls' => $GLOBALS['calls']));
  `);
  assert.equal(value.result.code, 'external_redirect');
  assert.deepEqual(value.calls, [{ url: 'https://example.com/start', redirection: 0 }]);
});

test('fetch-page follows a relative same-origin redirect and preserves the final URL', () => {
  const value = runPhp(`
    $GLOBALS['responses'][] = array('response' => array('code' => 302), 'headers' => array('location' => '../final?view=1'));
    $GLOBALS['responses'][] = array('response' => array('code' => 200), 'headers' => array(), 'body' => '<html>ok</html>');
    $result = DHC_REST::handle_fetch_page(new RequestStub('https://example.com/section/start'));
    echo json_encode(array('result' => result_json($result), 'calls' => $GLOBALS['calls']));
  `);
  assert.equal(value.result.kind, 'response');
  assert.equal(value.result.data.url, 'https://example.com/section/start');
  assert.equal(value.result.data.final_url, 'https://example.com/final?view=1');
  assert.equal(value.result.data.html, '<html>ok</html>');
  assert.deepEqual(value.calls.map((call) => call.redirection), [0, 0]);
});

test('fetch-page rejects protocol-relative redirects that change origin', () => {
  const value = runPhp(`
    $GLOBALS['responses'][] = array('response' => array('code' => 301), 'headers' => array('location' => '//example.com:444/escape'));
    $result = DHC_REST::handle_fetch_page(new RequestStub('https://example.com/start'));
    echo json_encode(array('result' => result_json($result), 'calls' => $GLOBALS['calls']));
  `);
  assert.equal(value.result.code, 'external_redirect');
  assert.equal(value.calls.length, 1);
});

test('fetch-page blocks a safe hop followed by an unsafe hop', () => {
  const value = runPhp(`
    $GLOBALS['responses'][] = array('response' => array('code' => 302), 'headers' => array('location' => '/safe'));
    $GLOBALS['responses'][] = array('response' => array('code' => 302), 'headers' => array('location' => 'https://user@example.com/private'));
    $result = DHC_REST::handle_fetch_page(new RequestStub('https://example.com/start'));
    echo json_encode(array('result' => result_json($result), 'calls' => $GLOBALS['calls']));
  `);
  assert.equal(value.result.code, 'external_redirect');
  assert.deepEqual(value.calls.map((call) => call.url), ['https://example.com/start', 'https://example.com/safe']);
});

test('fetch-page stops after five same-origin redirect hops', () => {
  const value = runPhp(`
    for ($i = 1; $i <= 6; $i++) $GLOBALS['responses'][] = array('response' => array('code' => 302), 'headers' => array('location' => '/hop-' . $i));
    $result = DHC_REST::handle_fetch_page(new RequestStub('https://example.com/start'));
    echo json_encode(array('result' => result_json($result), 'calls' => $GLOBALS['calls']));
  `);
  assert.equal(value.result.code, 'too_many_redirects');
  assert.equal(value.calls.length, 6);
  assert.ok(value.calls.every((call) => call.redirection === 0));
});
