'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const path = require('node:path');

function extract(html) {
  const crawler = path.join(__dirname, '..', 'includes', 'class-dhc-crawler.php');
  const bootstrap = `<?php
error_reporting(E_ERROR | E_PARSE);
define('ABSPATH', __DIR__);
function add_filter(){} function add_action(){}
function sanitize_text_field($v){ return trim(strip_tags((string)$v)); }
require ${JSON.stringify(crawler)};
$o=(new ReflectionClass('DHC_Crawler'))->newInstanceWithoutConstructor();
$m=new ReflectionMethod('DHC_Crawler','extract_schema_evidence'); $m->setAccessible(true);
echo json_encode($m->invoke($o, base64_decode($argv[1])));`;
  const result = spawnSync('php', ['-r', bootstrap.replace(/^<\?php\n/, ''), Buffer.from(html).toString('base64')], { encoding: 'utf8' });
  assert.equal(result.status, 0, result.stderr);
  return JSON.parse(result.stdout);
}

test('crawler emits bounded measured schema types without retaining script bodies', () => {
  const types = Array.from({ length: 30 }, (_, i) => ({ '@type': `Type${i}`, secret: 'do-not-persist' }));
  const measured = extract(`<html><body><script type="application/ld+json">${JSON.stringify(types)}</script><div itemscope itemtype="https://schema.org/LocalBusiness"></div></body></html>`);
  assert.equal(measured.version, 1);
  assert.equal(measured.status, 'measured');
  assert.equal(measured.present, true);
  assert.equal(measured.provenance, 'connector_html');
  assert.equal(measured.types.length, 20);
  assert.equal(JSON.stringify(measured).includes('do-not-persist'), false);
});

test('crawler distinguishes measured absence from failed extraction', () => {
  const absent = extract('<html><body><h1>No schema here</h1></body></html>');
  assert.deepEqual(absent, { version: 1, status: 'measured', present: false, types: [], provenance: 'connector_html', scriptCount: 0 });
  const failed = extract('<html><body><script type="application/ld+json">{"@type":</script></body></html>');
  assert.equal(failed.status, 'extraction_failed');
  assert.equal(failed.present, null);
  assert.equal(failed.reason, 'json_parse_error');
});

test('crawler recognizes legal JSON-LD type attribute forms', () => {
  for (const tag of [
    '<script type=application/ld+json>{"@type":"Organization"}</script>',
    '<SCRIPT data-x="1" TYPE = "APPLICATION/LD+JSON">{"@type":"LocalBusiness"}</SCRIPT>',
    "<script nonce='abc' type='application/ld+json; charset=utf-8'>{\"@type\":\"WebSite\"}</script>"
  ]) {
    const result = extract(tag);
    assert.equal(result.status, 'measured');
    assert.equal(result.present, true);
    assert.equal(result.scriptCount, 1);
    assert.equal(result.types.length, 1);
  }
});

test('crawler fails closed when JSON-LD exceeds its bounded inspection envelope', () => {
  const twenty = Array.from({ length: 20 }, (_, i) =>
    `<script type="application/ld+json">${JSON.stringify({ '@type': `Type${i}` })}</script>`
  ).join('');
  const overLimit = extract(twenty + '<script type=application/ld+json>{"@type":</script>');
  assert.equal(overLimit.status, 'extraction_failed');
  assert.equal(overLimit.present, null);
  assert.equal(overLimit.reason, 'script_limit_exceeded');
  assert.equal(overLimit.scriptCount, 20);

  const oversized = extract(`<script type=application/ld+json>${' '.repeat(262145)}</script>`);
  assert.equal(oversized.status, 'extraction_failed');
  assert.equal(oversized.reason, 'script_size_limit_exceeded');
  assert.equal(JSON.stringify(oversized).length < 500, true);
});

test('crawler leaves ambiguous or unterminated JSON-LD unmeasured', () => {
  const ambiguous = extract('<script type application/ld+json>{"@type":"Organization"}</script>');
  assert.equal(ambiguous.status, 'extraction_failed');
  assert.equal(ambiguous.reason, 'ambiguous_script_type');
  const unterminated = extract('<script type=application/ld+json>{"@type":"Organization"}');
  assert.equal(unterminated.status, 'extraction_failed');
  assert.equal(unterminated.reason, 'unterminated_jsonld');
});

test('crawler does not treat script text containing a fake script tag as JSON-LD', () => {
  const result = extract('<script>const example = `<script type=application/ld+json>{"@type":"Fake"}</script>`;</script>');
  assert.equal(result.status, 'measured');
  assert.equal(result.present, false);
  assert.equal(result.scriptCount, 0);
});
