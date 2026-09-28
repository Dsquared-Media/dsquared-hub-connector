'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const path = require('node:path');

test('legacy Brand Profile schema uses SportsClub only on the homepage and yields to reviewed schema', () => {
  const modulePath = path.resolve(__dirname, '../includes/modules/class-dhc-ai-discovery.php');
  const script = `
    define('ABSPATH', '/tmp/');
    $front = false;
    $reviewed = array();
    function add_action() {}
    function add_filter() {}
    function is_front_page() { global $front; return $front; }
    function is_page() { return false; }
    function is_singular() { return false; }
    function get_queried_object_id() { return 0; }
    function home_url($path = '/') { return 'https://theclubnj.com' . $path; }
    function get_bloginfo($key) { return 'The Club'; }
    function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
    function wp_json_encode($value, $options = 0) { return json_encode($value, $options); }
    function get_option($key, $fallback = null) {
      global $reviewed;
      if ($key === 'dhc_global_schemas') return $reviewed;
      if ($key === 'dhc_business_profile') return array(
        'business_name' => 'The Club', 'business_type' => 'Indoor Sports Club',
        'address' => '4 Farrington Blvd', 'city' => 'Monroe Township', 'state' => 'NJ'
      );
      return $fallback;
    }
    require ${JSON.stringify(modulePath)};
    $discovery = new DHC_AI_Discovery();
    ob_start(); $discovery->inject_ai_schema(); $interior = ob_get_clean();
    $front = true;
    ob_start(); $discovery->inject_ai_schema(); $homepage = ob_get_clean();
    $reviewed = array('SportsClub' => array('url' => 'https://theclubnj.com/',
      'markup' => array('@context' => 'https://schema.org', '@type' => 'SportsClub', 'name' => 'The Club')));
    ob_start(); $discovery->inject_ai_schema(); $approved = ob_get_clean();
    $reviewed = array('Organization' => array('url' => 'https://theclubnj.com/',
      'markup' => array('@context' => 'https://schema.org', '@type' => 'Organization', 'name' => 'The Club')));
    ob_start(); $discovery->inject_ai_schema(); $organization_only = ob_get_clean();
    $reviewed = array('Dentist' => array('url' => 'https://theclubnj.com/',
      'markup' => array('@context' => 'https://schema.org', '@type' => 'Dentist', 'name' => 'The Club')));
    ob_start(); $discovery->inject_ai_schema(); $reviewed_subtype = ob_get_clean();
    $type_method = new ReflectionMethod('DHC_AI_Discovery', 'schema_type_for_profile');
    $other = $type_method->invoke($discovery, array('business_type' => 'Painting Services', 'address' => '123 Main St'));
    $dentist = $type_method->invoke($discovery, array('business_type' => 'https://schema.org/Dentist', 'address' => '123 Main St'));
    echo json_encode(array('interior' => $interior, 'homepage' => $homepage, 'approved' => $approved,
      'organization_only' => $organization_only, 'reviewed_subtype' => $reviewed_subtype,
      'other' => $other, 'dentist' => $dentist));
  `;
  const result = spawnSync('php', ['-r', script], { encoding: 'utf8' });
  assert.equal(result.status, 0, result.stderr);
  const { interior, homepage, approved, organization_only, reviewed_subtype, other, dentist } = JSON.parse(result.stdout);
  assert.equal(interior, '');
  assert.equal(approved, '');
  assert.equal(reviewed_subtype, '');
  assert.equal(other, 'LocalBusiness');
  assert.equal(dentist, 'Dentist');
  assert.match(organization_only, /"@type": "SportsClub"/);
  const json = JSON.parse(homepage.match(/<script type="application\/ld\+json">([\s\S]*?)<\/script>/)[1]);
  assert.equal(json['@type'], 'SportsClub');
  assert.equal(json.address.addressLocality, 'Monroe Township');
});
