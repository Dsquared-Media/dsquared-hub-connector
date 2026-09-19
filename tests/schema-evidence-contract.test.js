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
});
