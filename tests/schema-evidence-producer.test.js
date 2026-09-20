'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const { execFileSync } = require('node:child_process');

const crawlerPath = path.join(__dirname, '..', 'includes/class-dhc-crawler.php');

function extract(html) {
  const php = String.raw`
    define('ABSPATH', __DIR__);
    define('DHC_VERSION', '1.17.10');
    class DHC_Heartbeat { const CRON_HOOK = 'dhc_heartbeat'; }
    function add_filter() {} function add_action() {}
    function esc_html__($v) { return $v; }
    require ${JSON.stringify(crawlerPath)};
    $crawler = DHC_Crawler::init();
    $method = new ReflectionMethod(DHC_Crawler::class, 'extract_schema_evidence');
    echo json_encode($method->invoke($crawler, base64_decode(${JSON.stringify(Buffer.from(html).toString('base64'))})));
  `;
  return JSON.parse(execFileSync('php', ['-r', php], { encoding: 'utf8' }));
}

test('producer emits bounded versioned connector_html evidence for valid JSON-LD', () => {
  const html = `<html><head><script nonce="x" type="application/ld+json; charset=utf-8">${JSON.stringify({
    '@context': 'https://schema.org',
    '@graph': [
      { '@type': 'Organization' },
      { '@type': ['WebSite', 'Organization', '<script>'] },
      { '@type': 'Service' },
    ],
  })}</script></head><body>${'x'.repeat(220)}</body></html>`;
  assert.deepEqual(extract(html), {
    version: 1,
    status: 'measured',
    present: true,
    types: ['Organization', 'WebSite', 'Service'],
    provenance: 'connector_html',
    scriptCount: 1,
  });
});

test('producer records a definitive measured absence only for complete bounded HTML', () => {
  const evidence = extract(`<html><body><h1>No structured data</h1>${'x'.repeat(220)}</body></html>`);
  assert.deepEqual(evidence, {
    version: 1,
    status: 'measured',
    present: false,
    types: [],
    provenance: 'connector_html',
    scriptCount: 0,
  });
});

test('producer keeps malformed or incomplete JSON-LD unmeasured', () => {
  const malformed = extract(`<html><script type="application/ld+json">{"@type":</script>${'x'.repeat(220)}</html>`);
  assert.equal(malformed.status, 'extraction_failed');
  assert.equal(malformed.present, null);
  assert.equal(malformed.reason, 'json_parse_error');

  const unterminated = extract(`<html><script type="application/ld+json">{"@type":"Thing"}${'x'.repeat(220)}</html>`);
  assert.equal(unterminated.status, 'extraction_failed');
  assert.equal(unterminated.reason, 'unterminated_script_tag');
});

test('producer reports input, script count, and script size limits without fabricating absence', () => {
  // The transports read at most MAX_HTML_PARSE_BYTES + 1. That sentinel byte
  // must make an otherwise schema-free response unmeasured, never absent.
  const inputLimited = extract('x'.repeat(409601));
  assert.equal(inputLimited.status, 'extraction_failed');
  assert.equal(inputLimited.reason, 'input_limit_exceeded');

  const manyScripts = extract(`<html>${Array.from({ length: 21 }, () => '<script type="application/ld+json">{"@type":"Thing"}</script>').join('')}${'x'.repeat(220)}</html>`);
  assert.equal(manyScripts.status, 'extraction_failed');
  assert.equal(manyScripts.reason, 'script_limit_exceeded');

  const largeScript = extract(`<html><script type="application/ld+json">${JSON.stringify({ '@type': 'Thing', value: 'x'.repeat(132000) })}</script></html>`);
  assert.equal(largeScript.status, 'extraction_failed');
  assert.equal(largeScript.reason, 'script_size_limit_exceeded');
});

test('page payload stores only the bounded evidence envelope, never raw schema markup', () => {
  const fs = require('node:fs');
  const crawler = fs.readFileSync(crawlerPath, 'utf8');
  assert.match(crawler, /'schemaEvidence'\s*=>\s*\$schema_evidence/);
  const envelope = crawler.match(/private function schema_measurement[\s\S]+?\n\t}/)?.[0] || '';
  assert.doesNotMatch(envelope, /'(?:html|blocks|raw)'\s*=>/i);
  assert.match(envelope, /'version'\s*=>\s*1/);
  assert.match(envelope, /'provenance'\s*=>\s*'connector_html'/);
});
