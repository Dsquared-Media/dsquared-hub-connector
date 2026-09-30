const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.join(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

test('plugin alt-text admin keeps API key server-side and requires nonce plus capability', () => {
  const php = read('includes/modules/class-dhc-alt-text-generator.php');
  assert.match(php, /current_user_can\( 'manage_options' \)/);
  assert.match(php, /check_ajax_referer\( 'dhc_alt_text_nonce', 'nonce' \)/);
  assert.match(php, /wp_remote_post\( DHC_HUB_API_BASE \. '\/plugin\/alt-text\/generate'/);
  assert.match(php, /'X-DHC-API-Key'\s*=>\s*\$api_key/);
  assert.doesNotMatch(read('admin/js/dhc-alt-text.js'), /X-DHC-API-Key|dhc_api_key/);
});

test('plugin alt-text save is review-first and attachment scoped', () => {
  const php = read('includes/modules/class-dhc-alt-text-generator.php');
  assert.match(php, /'attachment' !== get_post_type\( \$id \)/);
  assert.match(php, /'_wp_attachment_image_alt'/);
  assert.match(php, /in_array\( \$classification, array\( 'usable', 'decorative' \), true \)/);
  assert.match(php, /Alt text must be 125 characters or fewer/);
  assert.match(php, /media_alt_review_save/);
});

test('plugin generator exposes responsive containment and explicit paid confirmation', () => {
  const css = read('admin/css/dhc-alt-text.css');
  const js = read('admin/js/dhc-alt-text.js');
  assert.match(css, /grid-template-columns:repeat\(4,minmax\(0,1fr\)\)/);
  assert.match(css, /@media\(max-width:600px\).*grid-template-columns:1fr/s);
  assert.match(js, /window\.confirm\(/);
  assert.match(js, /up to.*Hub credit/s);
  assert.match(js, /people and headshots are not auto-described/i);
});
