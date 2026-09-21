'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');

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
  assert.match(handler, /\$heading_length > 250/);
  assert.match(handler, /dhc_editable_post_types/);
  assert.match(posts, /public static function replace_first_heading[\s\S]*?preg_replace_callback\(/);
  assert.match(handler, /if \( \$replacement_count > 0 \)/);
  assert.match(handler, /\$payload\['post_content'\] = \$updated_content/);
  assert.doesNotMatch(handler, /wp_kses_post\( \$updated_content \)/);
  assert.match(handler, /kses_remove_filters\(\)/);
  assert.match(handler, /kses_init_filters\(\)/);
  assert.match(handler, /wp_update_post\( \$payload, true \)/);
  assert.match(handler, /wp_get_post_revisions/);
  assert.match(handler, /post_heading_updated/);
  assert.doesNotMatch(handler, /\$payload\['post_content'\][\s\S]*else/);
});

test('literal reviewed headings preserve dollar signs, slashes, Unicode, and unrelated markup', () => {
  const classFile = path.join(root, 'includes/modules/class-dhc-posts.php');
  const script = [
    "define('ABSPATH', __DIR__);",
    "function esc_html($value) { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }",
    `require ${JSON.stringify(classFile)};`,
    "$cases = array('Price $1 Today', 'Use $2 Plan', 'Path \\\\ Plan', 'Fish & Chips', 'Café Español');",
    "$content = '<section><iframe src=\"keep\"></iframe><h1 class=\"hero\">Old</h1><svg><path d=\"M0 0\"/></svg><p>$1 stays</p></section>';",
    "$rows = array();",
    "foreach ($cases as $heading) { $count = 0; $updated = DHC_Posts::replace_first_heading($content, $heading, $count); $rows[] = array('heading' => $heading, 'content' => $updated, 'count' => $count); }",
    "echo json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);"
  ].join(' ');
  const rows = JSON.parse(execFileSync('php', ['-r', script], { encoding: 'utf8' }));
  assert.equal(rows.length, 5);
  for (const row of rows) {
    assert.equal(row.count, 1);
    assert.match(row.content, /<iframe src="keep"><\/iframe>/);
    assert.match(row.content, /<svg><path d="M0 0"\/><\/svg><p>\$1 stays<\/p>/);
    assert.equal((row.content.match(/<h1\b/g) || []).length, 1);
    assert.equal((row.content.match(/<\/h1>/g) || []).length, 1);
  }
  assert.match(rows[0].content, />Price \$1 Today<\/h1>/);
  assert.match(rows[1].content, />Use \$2 Plan<\/h1>/);
  assert.match(rows[2].content, />Path \\ Plan<\/h1>/);
  assert.match(rows[3].content, />Fish &amp; Chips<\/h1>/);
  assert.match(rows[4].content, />Café Español<\/h1>/);
});
