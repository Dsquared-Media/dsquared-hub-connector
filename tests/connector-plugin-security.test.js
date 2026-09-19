'use strict';

/**
 * connector-plugin-security.test.js
 *
 * Static security assertions for the two-token credential model (v1.17.4).
 * These tests read the PHP and JS source files directly and assert that
 * the private dhc_api_key never appears where only the narrow telemetry
 * token (dhc_telemetry_token) should be used.
 *
 * Run: node --test tests/connector-plugin-security.test.js
 */

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.join(__dirname, '..');

// Load files under test
const eventTracker  = fs.readFileSync(path.join(root, 'includes/modules/class-dhc-event-tracker.php'), 'utf8');
const siteHealth    = fs.readFileSync(path.join(root, 'includes/modules/class-dhc-site-health.php'), 'utf8');
const cwvJs         = fs.readFileSync(path.join(root, 'assets/dhc-site-health.js'), 'utf8');
const pluginMain    = fs.readFileSync(path.join(root, 'dsquared-hub-connector.php'), 'utf8');
const heartbeat     = fs.readFileSync(path.join(root, 'includes/class-dhc-heartbeat.php'), 'utf8');
const admin         = fs.readFileSync(path.join(root, 'includes/class-dhc-admin.php'), 'utf8');
const uninstall     = fs.readFileSync(path.join(root, 'uninstall.php'), 'utf8');
const readme        = fs.readFileSync(path.join(root, 'readme.txt'), 'utf8');
const changelog     = fs.readFileSync(path.join(root, 'CHANGELOG.md'), 'utf8');

// ---------------------------------------------------------------------------
// Issue 5: Event Tracker — dhc_api_key must not appear in the beacon block
// ---------------------------------------------------------------------------

test('event-tracker: dhc_api_key does not appear in the beacon localize block', () => {
    // Extract the inject_tracker method body — everything between inject_tracker() {
    // and the next method declaration / closing brace at the class level.
    const trackerStart = eventTracker.indexOf('public static function inject_tracker()');
    assert.ok(trackerStart >= 0, 'inject_tracker method must exist in class-dhc-event-tracker.php');

    const trackerBody = eventTracker.slice(trackerStart);

    // The beacon config assignment must use dhc_telemetry_token, not dhc_api_key.
    assert.ok(
        !trackerBody.includes("get_option( 'dhc_api_key'"),
        "inject_tracker must not call get_option('dhc_api_key') — use dhc_telemetry_token instead"
    );
    assert.ok(
        !trackerBody.includes('get_option("dhc_api_key"'),
        'inject_tracker must not call get_option("dhc_api_key") — use dhc_telemetry_token instead'
    );
});

test('event-tracker: inject_tracker sources beacon api_key from dhc_telemetry_token', () => {
    const trackerStart = eventTracker.indexOf('public static function inject_tracker()');
    const trackerBody  = eventTracker.slice(trackerStart);

    assert.ok(
        trackerBody.includes("get_option( 'dhc_telemetry_token'"),
        "inject_tracker must read dhc_telemetry_token via get_option('dhc_telemetry_token')"
    );
});

test('event-tracker: empty dhc_telemetry_token disables beacon (no api_key fallback)', () => {
    const trackerStart = eventTracker.indexOf('public static function inject_tracker()');
    const trackerBody  = eventTracker.slice(trackerStart);

    // The conditional must guard on telemetry_token being non-empty.
    // Look for the pattern: !empty($telemetry_token) or equivalent.
    assert.ok(
        trackerBody.includes('telemetry_token') && trackerBody.includes('null'),
        'inject_tracker must set beacon to null when dhc_telemetry_token is empty'
    );
});

// ---------------------------------------------------------------------------
// Issue 5: Site Health — dhc_api_key must not appear in enqueue_cwv_script
// ---------------------------------------------------------------------------

test('site-health: dhc_api_key does not appear in enqueue_cwv_script', () => {
    const enqueueStart = siteHealth.indexOf('public static function enqueue_cwv_script()');
    assert.ok(enqueueStart >= 0, 'enqueue_cwv_script method must exist in class-dhc-site-health.php');

    // Find the end of the method — next 'public static function' or closing class brace.
    const afterEnqueue = siteHealth.slice(enqueueStart);
    const nextMethod   = afterEnqueue.indexOf('public static function', 1);
    const methodBody   = nextMethod > 0 ? afterEnqueue.slice(0, nextMethod) : afterEnqueue;

    assert.ok(
        !methodBody.includes("get_option( 'dhc_api_key'"),
        "enqueue_cwv_script must not call get_option('dhc_api_key') — use dhc_telemetry_token"
    );
    assert.ok(
        !methodBody.includes('get_option("dhc_api_key"'),
        'enqueue_cwv_script must not call get_option("dhc_api_key") — use dhc_telemetry_token'
    );
});

test('site-health: enqueue_cwv_script passes dhc_telemetry_token as apiKey', () => {
    const enqueueStart = siteHealth.indexOf('public static function enqueue_cwv_script()');
    const afterEnqueue = siteHealth.slice(enqueueStart);
    const nextMethod   = afterEnqueue.indexOf('public static function', 1);
    const methodBody   = nextMethod > 0 ? afterEnqueue.slice(0, nextMethod) : afterEnqueue;

    assert.ok(
        methodBody.includes("get_option( 'dhc_telemetry_token'"),
        "enqueue_cwv_script must read dhc_telemetry_token via get_option('dhc_telemetry_token')"
    );
    assert.ok(
        methodBody.includes("'apiKey'"),
        "enqueue_cwv_script must pass 'apiKey' to wp_localize_script"
    );
});

// ---------------------------------------------------------------------------
// Issue 5: CWV browser JS — Hub send must use X-DHC-API-Key header, not ?key=
// ---------------------------------------------------------------------------

test('dhc-site-health.js: Hub CWV send does not use ?key= in the URL', () => {
    // Find the Hub reporting block — keyed on config.hubEndpoint
    const hubBlock = cwvJs.indexOf('config.hubEndpoint');
    assert.ok(hubBlock >= 0, 'dhc-site-health.js must contain a Hub reporting block using config.hubEndpoint');

    const hubSection = cwvJs.slice(hubBlock);

    assert.ok(
        !hubSection.includes('?key='),
        'Hub CWV send must NOT put the token in the URL as ?key= (use X-DHC-API-Key header instead)'
    );
    assert.ok(
        !hubSection.includes('?k='),
        'Hub CWV send must NOT put the token in the URL as ?k= (use X-DHC-API-Key header instead)'
    );
});

test('dhc-site-health.js: Hub CWV send uses X-DHC-API-Key header', () => {
    const hubBlock   = cwvJs.indexOf('config.hubEndpoint');
    const hubSection = cwvJs.slice(hubBlock);

    assert.ok(
        hubSection.includes("'X-DHC-API-Key'"),
        "Hub CWV send must use 'X-DHC-API-Key' header to transmit the telemetry token"
    );
});

test('dhc-site-health.js: Hub CWV send uses fetch (not sendBeacon)', () => {
    const hubBlock   = cwvJs.indexOf('config.hubEndpoint');
    const hubSection = cwvJs.slice(hubBlock);

    // fetch must appear before any sendBeacon in the Hub block
    const fetchIdx   = hubSection.indexOf('fetch(');
    assert.ok(fetchIdx >= 0, 'Hub CWV send must use fetch() to allow custom headers');

    // sendBeacon must not be used for the Hub endpoint (it cannot set custom headers)
    // The local WP REST endpoint uses sendBeacon (that's OK — it uses nonce, not api_key),
    // but the Hub section must use fetch.
    // We verify by checking no sendBeacon call follows config.hubEndpoint within the
    // same if-block (before the closing brace pair `});`).
    const blockEnd   = hubSection.indexOf('}).catch');
    const hubCore    = blockEnd > 0 ? hubSection.slice(0, blockEnd + 10) : hubSection.slice(0, 500);
    assert.ok(
        !hubCore.includes('sendBeacon'),
        'Hub CWV send must not use sendBeacon (cannot set X-DHC-API-Key header)'
    );
});

// ---------------------------------------------------------------------------
// Issue 5: Event Tracker JS — pagehide path must NOT use ?k= in URL
// ---------------------------------------------------------------------------

test('event-tracker: pagehide beacon path does not use ?k= in URL', () => {
    assert.ok(
        !eventTracker.includes("'?k=' +") && !eventTracker.includes('"?k=" +'),
        'event-tracker pagehide path must not append ?k= to the beacon URL'
    );
    assert.ok(
        !eventTracker.includes("+ 'k=' +") && !eventTracker.includes('+ "k=" +'),
        'event-tracker must not build a ?k= query param for the beacon URL'
    );
});

test('event-tracker: pagehide beacon path uses fetch with X-DHC-API-Key header', () => {
    // The flushBeacon(true) path (pagehide) must use fetch, not sendBeacon, for Hub send.
    // Verify X-DHC-API-Key header appears in the pagehide branch.
    const flushStart = eventTracker.indexOf('function flushBeacon(useSendBeacon)');
    assert.ok(flushStart >= 0, 'flushBeacon function must exist in event-tracker');

    const flushBody = eventTracker.slice(flushStart, flushStart + 1500);
    assert.ok(
        flushBody.includes("'X-DHC-API-Key'"),
        "flushBeacon must use 'X-DHC-API-Key' header in both the interval and pagehide paths"
    );
});

// ---------------------------------------------------------------------------
// Issue 2: Version-change block provisions dhc_telemetry_token
// ---------------------------------------------------------------------------

test('plugin: version-change block provisions dhc_telemetry_token for existing installs', () => {
    // The dhc_init() version-change block must schedule dhc_provision_telemetry_token
    // when dhc_telemetry_token is empty. This ensures 1.17.3 installs auto-upgrade.
    const initStart = pluginMain.indexOf('function dhc_init()');
    assert.ok(initStart >= 0, 'dhc_init() must exist in dsquared-hub-connector.php');

    const versionBlock = pluginMain.indexOf("if ( \$installed !== DHC_VERSION ) {", initStart);
    assert.ok(versionBlock >= 0, 'version-change block must exist inside dhc_init()');

    // Use a 1500-char window to capture the full version-change block including
    // the nested telemetry-token provisioning call (which is ~400 chars in).
    const versionBody = pluginMain.slice(versionBlock, versionBlock + 1500);
    assert.ok(
        versionBody.includes('dhc_telemetry_token'),
        'version-change block must reference dhc_telemetry_token provisioning'
    );
    assert.ok(
        versionBody.includes('DHC_Heartbeat::ensure_telemetry_token_scheduled()'),
        'version-change block must invoke the self-healing telemetry provision scheduler'
    );
});

// ---------------------------------------------------------------------------
// Package readiness: DHC_VERSION is 1.17.8
// ---------------------------------------------------------------------------

test('plugin: DHC_VERSION constant is 1.17.8', () => {
    assert.ok(
        pluginMain.includes("define( 'DHC_VERSION', '1.17.8' )"),
        "DHC_VERSION must be '1.17.8'"
    );
});

test('plugin: file header Version comment is 1.17.8', () => {
    assert.ok(
        pluginMain.includes('* Version:           1.17.8'),
        'Plugin file header comment must declare Version: 1.17.8'
    );
});

test('plugin: DHC_VERSION constant and header comment agree', () => {
    const headerMatch  = pluginMain.match(/\* Version:\s+([\d.]+)/);
    const defineMatch  = pluginMain.match(/define\(\s*'DHC_VERSION',\s*'([\d.]+)'\s*\)/);

    assert.ok(headerMatch,  'Header Version comment must be present');
    assert.ok(defineMatch,  'DHC_VERSION define must be present');
    assert.equal(
        headerMatch[1],
        defineMatch[1],
        `Header version (${headerMatch?.[1]}) must match DHC_VERSION constant (${defineMatch?.[1]})`
    );
});

test('plugin: WordPress stable tag and changelog agree with 1.17.8', () => {
    assert.match(readme, /^Stable tag:\s*1\.17\.8$/m);
    assert.match(readme, /^= 1\.17\.8 =$/m);
    assert.match(changelog, /^## 1\.17\.8$/m);
});

// ---------------------------------------------------------------------------
// Upgrade reliability: provisioning must recover from a missed/failed event
// ---------------------------------------------------------------------------

test('telemetry provisioning: heartbeat and admin traffic self-heal a missing token', () => {
    const sendHeartbeat = heartbeat.slice(
        heartbeat.indexOf('public function send_heartbeat()'),
        heartbeat.indexOf('public static function get_hub_url()')
    );
    assert.match(sendHeartbeat, /self::ensure_telemetry_token_scheduled\(\)/);

    const adminInit = pluginMain.slice(
        pluginMain.indexOf("add_action( 'admin_init'"),
        pluginMain.indexOf('// Auto-flush rewrite rules')
    );
    assert.match(adminInit, /DHC_Heartbeat::ensure_telemetry_token_scheduled\(\)/);
});

test('telemetry provisioning: failed requests use capped persistent backoff', () => {
    assert.match(heartbeat, /const TELEMETRY_RETRY_BASE_SECONDS = 300/);
    assert.match(heartbeat, /const TELEMETRY_RETRY_MAX_SECONDS = 86400/);
    assert.match(heartbeat, /\$failures = min\( 9, \$failures \+ 1 \)/);
    assert.match(heartbeat, /self::TELEMETRY_RETRY_MAX_SECONDS,[\s\S]*self::TELEMETRY_RETRY_BASE_SECONDS/);

    const provision = heartbeat.slice(heartbeat.indexOf('public static function maybe_provision_telemetry_token()'));
    const failureSchedules = provision.match(/self::schedule_telemetry_retry\(\)/g) || [];
    assert.equal(failureSchedules.length, 2, 'transport and non-success responses must both schedule a retry');
});

test('telemetry provisioning: key rotation clears stale token, retry state, and pending event', () => {
    const rotationBlocks = admin.match(/if \( \$api_key !== \$old_key \) \{[\s\S]*?\n\s*\}/g) || [];
    assert.equal(rotationBlocks.length, 2, 'both settings-save paths must handle key rotation');
    for (const block of rotationBlocks) {
        assert.match(block, /delete_option\( 'dhc_telemetry_token' \)/);
        assert.match(block, /delete_option\( DHC_Heartbeat::TELEMETRY_RETRY_OPTION \)/);
        assert.match(block, /wp_clear_scheduled_hook\( DHC_Heartbeat::TELEMETRY_PROVISION_HOOK \)/);
        assert.match(block, /DHC_Heartbeat::ensure_telemetry_token_scheduled\(\)/);
    }
});

test('plugin uninstall removes telemetry credential, retry state, and all pending retries', () => {
    assert.match(uninstall, /'dhc_telemetry_token'/);
    assert.match(uninstall, /'dhc_telemetry_provision_retry'/);
    assert.match(uninstall, /'dhc_provision_telemetry_token'/);
    assert.match(uninstall, /wp_clear_scheduled_hook\( \$hook \)/);
});
