'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');

const root = path.join(__dirname, '..');
const crawlerPath = path.join(root, 'includes/class-dhc-crawler.php');
const crawler = fs.readFileSync(crawlerPath, 'utf8');

test('crawler uses a conservative worker cap and bounded fetch wall clock', () => {
  const workers = Number(crawler.match(/const CONCURRENT_FETCH_WORKERS = (\d+);/)?.[1]);
  const seconds = Number(crawler.match(/const FETCH_WALL_CLOCK_BUDGET_SEC = (\d+);/)?.[1]);
  assert.ok(workers >= 2 && workers <= 4, `worker cap ${workers} must remain between 2 and 4`);
  assert.ok(seconds >= 25 && seconds <= 30, `fetch budget ${seconds}s must remain between 25 and 30s`);
  assert.match(crawler, /microtime\( true \) < \$deadline/);
  assert.match(crawler, /min\( self::PAGE_TIMEOUT, \$remaining \)/);
  assert.match(crawler, /function_exists\( 'curl_multi_init' \)/);
});

test('frontier batching is deterministic, bounded, and does not refetch visited URLs', () => {
  const queue = ['a', 'b', 'a', 'c', 'd', 'b'];
  const visited = [];
  const selected = [];
  const workers = 3;
  while (selected.length < workers && queue.length) {
    const url = queue.shift();
    if (visited.includes(url)) continue;
    visited.push(url);
    selected.push(url);
  }
  assert.deepEqual(selected, ['a', 'b', 'c']);
  assert.deepEqual(queue, ['d', 'b'], 'unselected frontier remains ordered for the next tick');

  assert.match(crawler, /foreach \( \$fetch_urls as \$result_index => \$url \)/);
  assert.match(crawler, /\$page_results\[ \$result_index \] \?\? null/);
  assert.match(crawler, /in_array\( \$url, \$visited, true \)/);
  assert.match(crawler, /\$batch_limit = min\( self::CONCURRENT_FETCH_WORKERS, \$max_pages - \$total_uploaded \)/);
});

test('partial ticks retain the unselected queue and failed uploads restore the exact frontier', () => {
  assert.match(crawler, /\$queue_before_tick\s*=\s*\$url_queue/);
  assert.match(crawler, /\$visited_before_tick\s*=\s*\$visited/);
  assert.match(crawler, /\$state\['url_queue'\]\s*=\s*\$url_queue/);
  assert.match(crawler, /\$state\['url_queue'\]\s*=\s*\$queue_before_tick/);
  assert.match(crawler, /\$state\['visited'\]\s*=\s*\$visited_before_tick/);
  assert.match(crawler, /\$this->schedule_continuation\(\)/);
});

test('concurrent fetches keep same-site and redirect security checks', () => {
  assert.match(crawler, /safe_fetch_batch\( array \$urls, \$allowed_domain, \$deadline \)/);
  assert.match(crawler, /! \$this->url_has_credentials\( \$url \)/);
  assert.match(crawler, /! \$this->url_has_credentials\( \$next_url \)/);
  assert.match(crawler, /\$this->is_same_domain\( \$next_url, \$allowed_domain \)/);
  assert.match(crawler, /\$next_hop <= self::MAX_REDIRECTS/);
  assert.match(crawler, /'follow_redirects' => false/);
  assert.match(crawler, /'redirects'\s*=> 0/);
  assert.match(crawler, /state_domain_mismatch/);
});

test('in-flight cancellation and exact job ownership prevent stale uploads', () => {
  const ownershipCheck = crawler.indexOf('crawl_state_is_current( $job_id, $claim_token )');
  const upload = crawler.indexOf('$this->upload_chunk(', ownershipCheck);
  assert.ok(ownershipCheck > 0 && upload > ownershipCheck, 'ownership must be rechecked before upload');
  assert.match(crawler, /hash_equals\( \(string\) \$current\['job_id'\], \(string\) \$job_id \)/);
  assert.match(crawler, /hash_equals\( \(string\) \$current\['claim_token'\], \(string\) \$claim_token \)/);
  assert.match(crawler, /crawl_cancelled/);
});

test('Requests multi results remain ordered and reject off-site and credential redirects', () => {
  const php = String.raw`
    define('ABSPATH', __DIR__);
    define('DHC_VERSION', '1.17.9');
    class DHC_Heartbeat { const CRON_HOOK = 'dhc_heartbeat'; }
    class FakeResponse {
      public $status_code; public $headers; public $body;
      public function __construct($code, $headers, $body) {
        $this->status_code = $code; $this->headers = $headers; $this->body = $body;
      }
    }
    class Requests {
      public static $calls = array();
      public static $options = array();
      public static function request_multiple($requests) {
        self::$calls[] = array_map(function($r) { return $r['url']; }, $requests);
        self::$options[] = array_map(function($r) { return $r['options']; }, $requests);
        $responses = array();
        foreach (array_reverse($requests, true) as $key => $request) {
          $url = $request['url'];
          if (strpos($url, '/external') !== false) {
            $responses[$key] = new FakeResponse(302, array('location' => 'https://evil.example/secret'), '');
          } elseif (strpos($url, '/credential') !== false) {
            $responses[$key] = new FakeResponse(302, array('location' => 'https://user:pass@example.test/private'), '');
          } elseif (strpos($url, '/redirect') !== false) {
            $responses[$key] = new FakeResponse(302, array('location' => '/final'), '');
          } else {
            $responses[$key] = new FakeResponse(200, array('content-type' => 'text/html'), '<html><body>' . $url . str_repeat('x', 250) . '</body></html>');
          }
        }
        return $responses;
      }
    }
    function add_filter() {} function add_action() {}
    function esc_html__($v) { return $v; }
    function apply_filters($name, $value) { return $value; }
    function wp_remote_get() { throw new Exception('serial fallback must not run'); }
    function is_wp_error() { return false; }
    function wp_remote_retrieve_response_code($r) { return $r['response']['code']; }
    function wp_remote_retrieve_header($r, $n) { return $r['headers'][$n] ?? ''; }
    function wp_remote_retrieve_body($r) { return $r['body'] ?? ''; }
    require ${JSON.stringify(crawlerPath)};
    $crawler = DHC_Crawler::init();
    $method = new ReflectionMethod(DHC_Crawler::class, 'safe_fetch_batch');
    $urls = array(
      'https://example.test/slow-a',
      'https://example.test/redirect',
      'https://example.test/external',
      'https://example.test/credential'
    );
    $results = $method->invoke($crawler, $urls, 'example.test', microtime(true) + 5);
    echo json_encode(array(
      'codes' => array_map(function($r) { return $r === null ? null : $r['dhc_code']; }, $results),
      'calls' => Requests::$calls,
      'options' => Requests::$options,
      'bodies' => array_map(function($r) { return $r === null ? null : $r['dhc_body']; }, $results),
    ));
  `;
  const result = JSON.parse(execFileSync('php', ['-r', php], { encoding: 'utf8' }));
  assert.deepEqual(result.codes, [200, 200, null, null]);
  assert.match(result.bodies[0], /slow-a/);
  assert.match(result.bodies[1], /final/);
  assert.deepEqual(result.calls[0], [
    'https://example.test/slow-a',
    'https://example.test/redirect',
    'https://example.test/external',
    'https://example.test/credential',
  ]);
  assert.deepEqual(result.calls[1], { 1: 'https://example.test/final' });
  assert.equal(result.options[0][0].max_bytes, 409601);
  assert.equal(result.options[0][1].max_bytes, 409601);
  assert.equal(JSON.stringify(result.calls).includes('evil.example'), false);
  assert.equal(JSON.stringify(result.calls).includes('user:pass'), false);
});

test('serial fallback and legacy single fetch cap transfer bodies before parsing', () => {
  const php = String.raw`
    define('ABSPATH', __DIR__);
    define('DHC_VERSION', '1.17.10');
    class DHC_Heartbeat { const CRON_HOOK = 'dhc_heartbeat'; }
    class WP_Error {}
    function add_filter() {} function add_action() {}
    function esc_html__($v) { return $v; }
    function apply_filters($name, $value) { return $value; }
    function wp_remote_get($url, $args) {
      $GLOBALS['gets'][] = array('url' => $url, 'args' => $args);
      return new WP_Error();
    }
    function is_wp_error($value) { return $value instanceof WP_Error; }
    function wp_remote_retrieve_response_code($r) { return 0; }
    function wp_remote_retrieve_header($r, $n) { return ''; }
    function wp_remote_retrieve_body($r) { return ''; }
    $GLOBALS['gets'] = array();
    require ${JSON.stringify(crawlerPath)};
    $crawler = DHC_Crawler::init();
    $multi = new ReflectionMethod(DHC_Crawler::class, 'request_multiple');
    $multi->invoke($crawler, array(
      0 => 'https://example.test/a',
      1 => 'https://example.test/b'
    ), 2, microtime(true) + 5);
    $single = new ReflectionMethod(DHC_Crawler::class, 'safe_fetch');
    $single->invoke($crawler, 'https://example.test/legacy', 'example.test');
    echo json_encode(array(
      'gets' => $GLOBALS['gets'],
    ));
  `;
  const result = JSON.parse(execFileSync('php', ['-n', '-r', php], { encoding: 'utf8' }));
  assert.equal(result.gets.length, 3);
  for (const call of result.gets) {
    assert.equal(call.args.limit_response_size, 409601);
    assert.equal(call.args.redirection, 0);
  }
});

test('redirect rounds stop at the wall-clock deadline and return a partial result', () => {
  const php = String.raw`
    define('ABSPATH', __DIR__);
    define('DHC_VERSION', '1.17.10');
    class DHC_Heartbeat { const CRON_HOOK = 'dhc_heartbeat'; }
    class FakeResponse {
      public $status_code = 302; public $headers = array('location' => '/next'); public $body = '';
    }
    class Requests {
      public static $calls = 0;
      public static function request_multiple($requests) {
        self::$calls++; usleep(30000);
        return array(0 => new FakeResponse());
      }
    }
    function add_filter() {} function add_action() {}
    function esc_html__($v) { return $v; }
    function apply_filters($name, $value) { return $value; }
    function wp_remote_get() { throw new Exception('serial fallback must not run'); }
    function is_wp_error() { return false; }
    function wp_remote_retrieve_response_code($r) { return 0; }
    function wp_remote_retrieve_header($r, $n) { return ''; }
    function wp_remote_retrieve_body($r) { return ''; }
    require ${JSON.stringify(crawlerPath)};
    $crawler = DHC_Crawler::init();
    $method = new ReflectionMethod(DHC_Crawler::class, 'safe_fetch_batch');
    $started = microtime(true);
    $results = $method->invoke(
      $crawler,
      array('https://example.test/start'),
      'example.test',
      $started + 0.01
    );
    echo json_encode(array(
      'calls' => Requests::$calls,
      'is_partial' => $results[0] === null,
      'elapsed_ms' => (microtime(true) - $started) * 1000,
    ));
  `;
  const result = JSON.parse(execFileSync('php', ['-r', php], { encoding: 'utf8' }));
  assert.equal(result.calls, 1, 'deadline must prevent the second redirect round');
  assert.equal(result.is_partial, true);
  assert.ok(result.elapsed_ms < 150, `bounded fixture took ${result.elapsed_ms}ms`);
});
