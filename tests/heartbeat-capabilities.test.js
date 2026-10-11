'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.join(__dirname, '..');
const rest = fs.readFileSync(path.join(root, 'includes/class-dhc-rest.php'), 'utf8');
const heartbeat = fs.readFileSync(path.join(root, 'includes/class-dhc-heartbeat.php'), 'utf8');

test('heartbeat sends the connector capability inventory to the Hub', () => {
  assert.match(heartbeat, /'advertised_routes'\s*=>\s*DHC_REST::advertised_routes\(\)/);
});

test('status exposes the same capability inventory', () => {
  assert.match(rest, /'advertised_routes'\s*=>\s*self::advertised_routes\(\)/);
});

test('capability inventory includes the authenticated same-site fetch route', () => {
  const inventoryStart = rest.indexOf('public static function advertised_routes()');
  assert.ok(inventoryStart >= 0, 'advertised_routes() must exist');
  const inventory = rest.slice(inventoryStart, rest.indexOf('public static function register_routes()', inventoryStart));
  assert.match(inventory, /'\/fetch-page'/);
  assert.match(inventory, /'\/posts\/heading'/);
  assert.match(inventory, /'\/media\/alt'/);
  assert.match(inventory, /'\/ai-discovery\/raw'/);
  assert.match(inventory, /DHC_API_Key::is_module_available\( 'ai_discovery' \)/);
});
