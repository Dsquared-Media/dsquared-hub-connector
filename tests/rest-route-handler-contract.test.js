'use strict';

const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const assert = require('node:assert/strict');

const root = path.join(__dirname, '..');
const sharedRoutes = fs.readFileSync(path.join(root, 'includes/class-dhc-rest.php'), 'utf8');
const decayModule = fs.readFileSync(path.join(root, 'includes/modules/class-dhc-content-decay.php'), 'utf8');
const formModule = fs.readFileSync(path.join(root, 'includes/modules/class-dhc-form-capture.php'), 'utf8');
const bootstrap = fs.readFileSync(path.join(root, 'dsquared-hub-connector.php'), 'utf8');
const readme = fs.readFileSync(path.join(root, 'readme.txt'), 'utf8');

test('module-owned REST routes do not point at nonexistent static handlers', () => {
  assert.doesNotMatch(sharedRoutes, /DHC_Content_Decay'\s*,\s*'handle_request/);
  assert.doesNotMatch(sharedRoutes, /DHC_Form_Capture'\s*,\s*'handle_stats_request/);
});

test('content decay is registered by its initialized module instance', () => {
  assert.match(decayModule, /register_rest_route\(\s*'dsquared-hub\/v1'\s*,\s*'\/content-decay'/);
  assert.match(decayModule, /'callback'\s*=>\s*array\(\s*\$this\s*,\s*'get_decay_report'\s*\)/);
});

test('lead stats supports both the documented and explicit stats paths through one instance handler', () => {
  assert.match(formModule, /'callback'\s*=>\s*array\(\s*\$this\s*,\s*'get_lead_stats'\s*\)/);
  assert.match(formModule, /register_rest_route\(\s*'dsquared-hub\/v1'\s*,\s*'\/leads\/stats'\s*,\s*\$definition\s*\)/);
  assert.match(formModule, /register_rest_route\(\s*'dsquared-hub\/v1'\s*,\s*'\/leads'\s*,\s*\$definition\s*\)/);
});

test('route repair ships as connector patch release 1.23.1', () => {
  assert.match(bootstrap, /Version:\s+1\.23\.1/);
  assert.match(bootstrap, /define\(\s*'DHC_VERSION'\s*,\s*'1\.23\.1'\s*\)/);
  assert.match(readme, /Stable tag:\s+1\.23\.1/);
});
