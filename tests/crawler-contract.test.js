'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');

const root = path.join(__dirname, '..');
const crawler = fs.readFileSync(path.join(root, 'includes/class-dhc-crawler.php'), 'utf8');
const admin = fs.readFileSync(path.join(root, 'includes/class-dhc-admin.php'), 'utf8');
const plugin = fs.readFileSync(path.join(root, 'dsquared-hub-connector.php'), 'utf8');

test('crawl uploads and completion send the claim token issued for the job', () => {
  assert.match(crawler, /X-DHC-Claim-Token'\s*=>\s*\$claim_token/g);
  assert.equal((crawler.match(/X-DHC-Claim-Token'\s*=>\s*\$claim_token/g) || []).length, 2);
  assert.match(crawler, /upload_chunk\( \$api_key, \$hub_url, \$job_id, \$claim_token,/);
  assert.match(crawler, /attempt_complete\( \$api_key, \$hub_url, \$job_id, \$claim_token \)/);
});

test('a crawl offer is rejected before claim when its domain is not this WordPress site', () => {
  const validation = crawler.indexOf('A Hub job must be bound to this exact WordPress site');
  const claim = crawler.indexOf("'/claim'");
  assert.ok(validation > 0 && claim > validation, 'site binding must be checked before claim');
  assert.match(crawler, /norm_host\( \$allowed_domain \).*norm_host\( \$this->site_host\(\) \)/s);
  assert.match(crawler, /is_same_domain\( \$start_url, \$this->site_host\(\) \)/);
});

test('redirects are followed manually and remain on the authorized site', () => {
  assert.match(crawler, /'redirection'\s*=>\s*0/);
  assert.match(crawler, /is_same_domain\( \$abs_location, \$allowed_domain \)/);
  assert.match(crawler, /MAX_REDIRECTS/);
});

test('connection instructions point to the current Hub account route', () => {
  assert.match(admin, /https:\/\/hub\.dsquaredmedia\.net\/#account/);
  assert.match(admin, /WordPress Connector/);
  assert.doesNotMatch(admin, /dashboard\.html#account/);
});

test('crawler recurrence self-heals on activation, upgrade, init, and admin traffic', () => {
  assert.match(plugin, /DHC_Crawler::init\(\)->schedule_poll\(\)/);
  assert.ok((plugin.match(/DHC_Crawler::init\(\)->schedule_poll\(\)/g) || []).length >= 3);
  assert.match(crawler, /add_action\( 'init',\s+array\( \$this, 'schedule_poll' \) \)/);
  assert.match(crawler, /add_action\( 'admin_init',\s+array\( \$this, 'maybe_wake_overdue_poll' \)/);
  assert.match(crawler, /spawn_cron\( time\(\) \)/);
  assert.match(crawler, /wp_schedule_event\( time\(\), self::INTERVAL_NAME, self::CRON_HOOK, array\(\), true \)/);
});

test('the proven heartbeat cron also drives one crawler tick per cadence window', () => {
  assert.match(crawler, /add_action\( self::CRON_HOOK,\s+array\( \$this, 'run_poll_tick' \) \)/);
  assert.match(crawler, /add_action\( DHC_Heartbeat::CRON_HOOK,\s*array\( \$this, 'run_poll_tick' \), 20 \)/);
  assert.match(crawler, /const LOCK_TTL_SEC = 270/);
  const tick = crawler.match(/public function run_poll_tick\(\)[\s\S]+?\n\t}/)?.[0] || '';
  assert.match(tick, /get_transient\( self::LOCK_TRANSIENT \)/);
  assert.match(tick, /set_transient\( self::LOCK_TRANSIENT, 1, self::LOCK_TTL_SEC \)/);
  assert.doesNotMatch(tick, /delete_transient\( self::LOCK_TRANSIENT \)/);
});

test('active crawls chain bounded background batches without waiting five minutes', () => {
  assert.match(crawler, /const CONTINUE_HOOK = 'dhc_crawler_continue'/);
  assert.match(crawler, /add_action\( self::CONTINUE_HOOK, array\( \$this, 'run_poll_tick' \) \)/);
  assert.match(crawler, /wp_schedule_single_event\( time\(\), self::CONTINUE_HOOK, array\(\), true \)/);
  assert.match(crawler, /register_shutdown_function/);
  assert.match(crawler, /delete_transient\( self::LOCK_TRANSIENT \)/);
  assert.match(crawler, /spawn_cron\( time\(\) \)/);
  assert.match(crawler, /\$this->schedule_continuation\(\)/);
  assert.doesNotMatch(crawler, /register_rest_route[^\n]+crawler_continue/);
});

test('release metadata identifies the concurrent crawler version', () => {
  const readme = fs.readFileSync(path.join(root, 'readme.txt'), 'utf8');
  assert.match(plugin, /Version:\s+1\.17\.10/);
  assert.match(plugin, /define\( 'DHC_VERSION', '1\.17\.10' \)/);
  assert.match(readme, /Stable tag:\s+1\.17\.10/);
});

test('authenticated wake hints queue the bounded outbound worker and keep cron recovery', () => {
  const rest = fs.readFileSync(path.join(root, 'includes/class-dhc-rest.php'), 'utf8');
  assert.match(rest, /register_rest_route\( self::NAMESPACE, '\/crawler\/wake'/);
  assert.match(rest, /'permission_callback'\s*=>\s*array\( 'DHC_API_Key', 'authenticate_request' \)/);
  assert.match(rest, /DHC_Crawler::init\(\)->schedule_immediate_poll\(\)/);
  assert.match(crawler, /public function schedule_immediate_poll\(\)/);
  const wakeMethod = crawler.match(/public function schedule_immediate_poll\(\)[\s\S]+?\n\t}/)?.[0] || '';
  assert.doesNotMatch(wakeMethod, /\$this->schedule_continuation\(\)/);
  assert.match(wakeMethod, /wp_schedule_single_event\( \$run_at, self::CONTINUE_HOOK/);
  assert.match(wakeMethod, /get_transient\( self::LOCK_TRANSIENT \)/);
  assert.match(crawler, /wp_next_scheduled\( self::CONTINUE_HOOK \)/);
  assert.match(crawler, /wp_schedule_event\( time\(\), self::INTERVAL_NAME, self::CRON_HOOK/);
});

test('REST wake scheduling never releases a crawler lock owned by another request', () => {
  const php = `
    define('ABSPATH', __DIR__);
    define('DHC_VERSION', '1.17.9');
    class WP_Error { private $code; public function __construct($code) { $this->code = $code; } public function get_error_code() { return $this->code; } }
    class DHC_Heartbeat { const CRON_HOOK = 'dhc_heartbeat'; }
    function add_filter() {}
    function add_action() {}
    function esc_html__($value) { return $value; }
    function sanitize_key($value) { return $value; }
    function update_option() {}
    function get_option($key, $default = null) { return $default; }
    function get_transient($key) { return true; }
    function wp_next_scheduled() { return false; }
    function wp_schedule_single_event($timestamp, $hook, $args, $wp_error) { $GLOBALS['scheduled_at'] = $timestamp; return true; }
    function is_wp_error($value) { return $value instanceof WP_Error; }
    function spawn_cron() { $GLOBALS['spawned']++; }
    function delete_transient() { $GLOBALS['deleted']++; }
    $GLOBALS['spawned'] = 0;
    $GLOBALS['deleted'] = 0;
    $GLOBALS['scheduled_at'] = 0;
    require ${JSON.stringify(path.join(root, 'includes/class-dhc-crawler.php'))};
    $before = time();
    $accepted = DHC_Crawler::init()->schedule_immediate_poll();
    register_shutdown_function(function() use ($accepted, $before) {
      echo json_encode(array(
        'accepted' => $accepted,
        'delay' => $GLOBALS['scheduled_at'] - $before,
        'spawned' => $GLOBALS['spawned'],
        'deleted' => $GLOBALS['deleted'],
      ));
    });
  `;
  const result = JSON.parse(execFileSync('php', ['-r', php], { encoding: 'utf8' }));
  assert.equal(result.accepted, true);
  assert.ok(result.delay >= 271 && result.delay <= 272, 'locked wake must run after the lock TTL');
  assert.equal(result.spawned, 0, 'locked wake must not force a competing cron request');
  assert.equal(result.deleted, 0, 'REST wake must not delete the worker-owned lock');
});

test('an unlocked REST wake dispatches once and repeated pending wakes do no extra work', () => {
  const php = `
    define('ABSPATH', __DIR__);
    define('DHC_VERSION', '1.17.9');
    class WP_Error { public function get_error_code() { return 'error'; } }
    class DHC_Heartbeat { const CRON_HOOK = 'dhc_heartbeat'; }
    function add_filter() {}
    function add_action() {}
    function esc_html__($value) { return $value; }
    function sanitize_key($value) { return $value; }
    function update_option() {}
    function get_option($key, $default = null) { return $default; }
    function get_transient($key) { return false; }
    function wp_next_scheduled() { return false; }
    function wp_schedule_single_event($timestamp, $hook, $args, $wp_error) { $GLOBALS['scheduled']++; return true; }
    function is_wp_error($value) { return $value instanceof WP_Error; }
    function spawn_cron() { $GLOBALS['spawned']++; }
    function delete_transient() { $GLOBALS['deleted']++; }
    $GLOBALS['scheduled'] = 0;
    $GLOBALS['spawned'] = 0;
    $GLOBALS['deleted'] = 0;
    require ${JSON.stringify(path.join(root, 'includes/class-dhc-crawler.php'))};
    $crawler = DHC_Crawler::init();
    $first = $crawler->schedule_immediate_poll();
    $second = $crawler->schedule_immediate_poll();
    register_shutdown_function(function() use ($first, $second) {
      echo json_encode(array(
        'first' => $first,
        'second' => $second,
        'scheduled' => $GLOBALS['scheduled'],
        'spawned' => $GLOBALS['spawned'],
        'deleted' => $GLOBALS['deleted'],
      ));
    });
  `;
  const result = JSON.parse(execFileSync('php', ['-r', php], { encoding: 'utf8' }));
  assert.equal(result.first, true);
  assert.equal(result.second, true);
  assert.equal(result.scheduled, 1);
  assert.equal(result.spawned, 1);
  assert.equal(result.deleted, 0);
});

test('crawler stores bounded diagnostics without response bodies or credentials', () => {
  assert.match(crawler, /const DIAGNOSTICS_KEY = 'dhc_crawler_diagnostics'/);
  assert.match(crawler, /poll_transport_error/);
  assert.match(crawler, /offer_domain_mismatch/);
  assert.match(crawler, /claim_http_error/);
  assert.match(crawler, /'http_status', 'error_code', 'job_id', 'requested_pages', 'effective_pages'/);
  const diagnosticMethod = crawler.match(/private function record_diagnostic[\s\S]+?\n\t}/)?.[0] || '';
  assert.doesNotMatch(diagnosticMethod, /api_key|offer_token|claim_token|response_body|site_url/);
  assert.match(admin, /Hub scan worker/);
  assert.match(admin, /Last scan check/);
});

test('expected cadence lock preserves the prior crawler result', () => {
  const lockBranch = crawler.match(/if \( get_transient\( self::LOCK_TRANSIENT \) \) \{[\s\S]+?\n\t\t\}/)?.[0] || '';
  assert.match(lockBranch, /self::get_diagnostics\(\)/);
  assert.match(lockBranch, /\['last_locked_at'\]/);
  assert.doesNotMatch(lockBranch, /record_diagnostic\(\s*['"]locked['"]/);
  assert.doesNotMatch(lockBranch, /\['last_result'\]\s*=/);
});

test('large-site cap fits inside the renewable claim absolute duration', () => {
  const number = (name) => Number(crawler.match(new RegExp(`const ${name} = (\\d+);`))?.[1]);
  const pagesPerTick = number('PAGES_PER_TICK');
  const maxPages = number('MAX_PAGES_HARD_CAP');
  const idleTtl = number('STATE_IDLE_TTL_SEC');
  const absoluteTtl = number('STATE_ABSOLUTE_TTL_SEC');
  const completionAttempts = number('MAX_COMPLETE_ATTEMPTS');
  const cadenceSeconds = 300;
  const crawlTicks = Math.ceil(maxPages / pagesPerTick);
  const worstCaseSeconds = (crawlTicks + completionAttempts) * cadenceSeconds;

  assert.equal(maxPages, 500);
  assert.equal(idleTtl, 3600);
  assert.equal(absoluteTtl, 14400);
  assert.ok(worstCaseSeconds <= absoluteTtl, 'crawl and all completion attempts must fit inside absolute TTL');
  assert.match(crawler, /\$state\['last_progress_at'\]\s*=\s*time\(\)/);
  assert.match(crawler, /\$state\['claim_token'\]\s*=\s*\$claim_token/);
  assert.match(crawler, /empty\( \$body\['claim_token'\] \)/);
});

test('a failed chunk restores the pre-tick frontier instead of losing pages', () => {
  assert.match(crawler, /\$queue_before_tick\s*=\s*\$url_queue/);
  assert.match(crawler, /\$visited_before_tick\s*=\s*\$visited/);
  assert.match(crawler, /\$state\['url_queue'\]\s*=\s*\$queue_before_tick/);
  assert.match(crawler, /\$state\['visited'\]\s*=\s*\$visited_before_tick/);
});

test('diagnostics distinguish idle, active, capped, pending, and terminal states', () => {
  for (const state of [
    'idle', 'claimed', 'claimed_with_page_cap', 'crawl_in_progress',
    'completion_pending', 'completed', 'completion_abandoned', 'state_expired',
  ]) {
    assert.match(crawler, new RegExp(`['\"]${state}['\"]`));
  }
});

test('completion only clears state after an explicit Hub success response', () => {
  const completion = crawler.match(/private function attempt_complete[\s\S]+?\n\t}/)?.[0] || '';
  assert.match(completion, /200 === \$code/);
  assert.doesNotMatch(completion, /409 === \$code/);
});
