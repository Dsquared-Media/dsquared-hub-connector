'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const source = fs.readFileSync(
    path.join(__dirname, '..', 'includes', 'modules', 'class-dhc-ai-discovery.php'),
    'utf8'
);

test('static AI Discovery files use summary for llms.txt and full content for llms-full.txt', () => {
    const start = source.indexOf('public function regenerate_static_files');
    const end = source.indexOf('public function maybe_serve_llms_txt_early', start);
    const method = source.slice(start, end);

    assert.match(method, /\$summary\s*=\s*\$this->generate_llms_summary/);
    assert.match(method, /\$full\s*=\s*\$this->generate_llms_full/);
    assert.match(method, /ABSPATH\s*\.\s*'llms-full\.txt'\s*=>\s*\$full/);
    assert.match(method, /'llms\.txt'\s*\]\s*=\s*\$summary/);
});

test('early dynamic handler preserves the same summary/full split', () => {
    const start = source.indexOf('public function maybe_serve_llms_txt_early');
    const end = source.indexOf('public function add_rewrite_rules', start);
    const method = source.slice(start, end);

    assert.match(method, /\$is_full\s*\?\s*\$this->generate_llms_full/);
    assert.match(method, /:\s*\$this->generate_llms_summary/);
});

test('summary points crawlers to the expanded file and full output adds site pages', () => {
    const summaryStart = source.indexOf('private function generate_llms_summary');
    const fullStart = source.indexOf('private function generate_llms_full', summaryStart);
    const summary = source.slice(summaryStart, fullStart);
    const full = source.slice(fullStart, source.indexOf('/* ─── AI Schema Injection', fullStart));

    assert.match(summary, /Full details:.*llms-full\.txt/);
    assert.doesNotMatch(summary, /## Key Pages/);
    assert.match(full, /## Key Pages/);
    assert.match(full, /## Recent Articles/);
});
