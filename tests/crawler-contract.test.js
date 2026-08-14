'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

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

test('accepted page cap fits inside the one-hour Hub claim lease with retry headroom', () => {
  const number = (name) => Number(crawler.match(new RegExp(`const ${name} = (\\d+);`))?.[1]);
  const pagesPerTick = number('PAGES_PER_TICK');
  const maxPages = number('MAX_PAGES_HARD_CAP');
  const stateTtl = number('STATE_TTL_SEC');
  const completionAttempts = number('MAX_COMPLETE_ATTEMPTS');
  const cadenceSeconds = 300;
  const crawlTicks = Math.ceil(maxPages / pagesPerTick);
  const worstCaseSeconds = (crawlTicks + completionAttempts) * cadenceSeconds;

  assert.equal(maxPages, 100);
  assert.ok(stateTtl < 3600, 'local state must expire before the Hub lease/token');
  assert.ok(worstCaseSeconds <= stateTtl, 'crawl and all completion attempts must fit inside local TTL');
});

test('diagnostics distinguish idle, active, capped, pending, and terminal states', () => {
  for (const state of [
    'idle', 'claimed', 'claimed_with_page_cap', 'crawl_in_progress',
    'completion_pending', 'completed', 'completion_abandoned', 'state_expired',
  ]) {
    assert.match(crawler, new RegExp(`['\"]${state}['\"]`));
  }
});
