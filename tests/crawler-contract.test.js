'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.join(__dirname, '..');
const crawler = fs.readFileSync(path.join(root, 'includes/class-dhc-crawler.php'), 'utf8');
const admin = fs.readFileSync(path.join(root, 'includes/class-dhc-admin.php'), 'utf8');

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
