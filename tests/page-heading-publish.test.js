'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const rest = fs.readFileSync(path.join(root, 'includes/class-dhc-rest.php'), 'utf8');
const posts = fs.readFileSync(path.join(root, 'includes/modules/class-dhc-posts.php'), 'utf8');

test('reviewed page headings use an authenticated dedicated connector route', () => {
  const route = rest.slice(rest.indexOf("'/posts/heading'"), rest.indexOf('// ── Bulk SEO Meta', rest.indexOf("'/posts/heading'")));
  assert.match(route, /DHC_Posts', 'handle_heading_update/);
  assert.match(route, /DHC_API_Key', 'authenticate_request/);
  assert.match(route, /'h1'\s*=>[\s\S]*'required'\s*=>\s*true/);
});

test('heading publishing is bounded, revision-backed, and never replaces unrelated content', () => {
  const handler = posts.slice(posts.indexOf('public static function handle_heading_update'), posts.indexOf('public static function handle_content_update'));
  assert.match(handler, /strlen\( \$h1 \) > 250/);
  assert.match(handler, /dhc_editable_post_types/);
  assert.match(handler, /preg_replace\([\s\S]*?<h1/);
  assert.match(handler, /if \( \$replacement_count > 0 \)/);
  assert.match(handler, /\$payload\['post_content'\] = wp_kses_post/);
  assert.match(handler, /wp_update_post\( \$payload, true \)/);
  assert.match(handler, /wp_get_post_revisions/);
  assert.match(handler, /post_heading_updated/);
  assert.doesNotMatch(handler, /\$payload\['post_content'\][\s\S]*else/);
});
