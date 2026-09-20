'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const fixture = JSON.parse(fs.readFileSync(
  path.join(__dirname, 'fixtures/crawl-latencies.json'),
  'utf8',
));

function serialDuration(latencies) {
  return latencies.reduce((sum, value) => sum + value, 0);
}

function boundedBatchDuration(latencies, workers) {
  let total = 0;
  for (let index = 0; index < latencies.length; index += workers) {
    total += Math.max(...latencies.slice(index, index + workers));
  }
  return total;
}

test('three-worker fixture benchmark materially improves the serial crawl baseline', (t) => {
  const serialMs = serialDuration(fixture.latenciesMs);
  const concurrentMs = boundedBatchDuration(fixture.latenciesMs, fixture.workers);
  const speedup = serialMs / concurrentMs;
  const reduction = 1 - (concurrentMs / serialMs);

  t.diagnostic(JSON.stringify({ serialMs, concurrentMs, speedup, reduction }));
  assert.equal(serialMs, 7200);
  assert.equal(concurrentMs, 3300);
  assert.ok(speedup >= 2, `expected >=2x speedup, received ${speedup.toFixed(2)}x`);
  assert.ok(reduction >= 0.5, `expected >=50% reduction, received ${(reduction * 100).toFixed(1)}%`);
});
