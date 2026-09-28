'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');

const root = path.resolve(__dirname, '..');
const classFile = path.join(root, 'includes/modules/class-dhc-schema.php');
const source = fs.readFileSync(classFile, 'utf8');
const rest = fs.readFileSync(path.join(root, 'includes/class-dhc-rest.php'), 'utf8');

test('schema editor, front-end output, and authenticated page_url route share one canonical field', () => {
  assert.match(source, /const META_KEY\s*=\s*'_d2_custom_schema'/);
  assert.match(source, /add_meta_box\( 'dhc-page-schema', 'Schema \(JSON-LD\)'/);
  assert.match(source, />Guided</);
  assert.match(source, />Raw JSON</);
  assert.match(source, /openingHoursSpecification/);
  assert.match(source, /Latitude and longitude must be entered together/);
  assert.match(source, /manage_pages_custom_column/);
  assert.match(source, /type="application\/ld\+json"/);
  const route = rest.slice(rest.indexOf("'/schema'"), rest.indexOf('// ── SEO Meta Sync'));
  assert.match(route, /DHC_API_Key', 'authenticate_request/);
  assert.match(route, /'page_url'/);
});

test('JSON validation and REST URL matching reject invalid or unmatched page data', () => {
  const script = [
    "define('ABSPATH', __DIR__);",
    '$GLOBALS["meta"] = array();',
    'function wp_json_encode($v,$f=0){return json_encode($v,$f);}',
    'function sanitize_key($v){return preg_replace("/[^a-z0-9_-]/","",strtolower($v));}',
    'function update_post_meta($id,$k,$v){$GLOBALS["meta"][$id][$k]=$v;return true;}',
    'function delete_post_meta($id,$k){unset($GLOBALS["meta"][$id][$k]);return true;}',
    'function get_post_meta($id,$k,$single=true){return isset($GLOBALS["meta"][$id][$k])?$GLOBALS["meta"][$id][$k]:"";}',
    'function esc_url_raw($v){return trim($v);}',
    'function wp_parse_url($v){return parse_url($v);}',
    'function home_url($p="/"){return "https://example.com".$p;}',
    'function untrailingslashit($v){return rtrim($v,"/");}',
    'function get_option($k,$d=0){return $k==="page_on_front"?7:$d;}',
    'function get_permalink($id){return $id===42?"https://example.com/about/":"https://example.com/";}',
    'function get_post_type($id){return in_array($id,array(7,42),true)?"page":false;}',
    'function current_time($t){return "2026-09-28 12:00:00";}',
    'function update_option($k,$v){return true;}',
    'class DHC_Core{public static function resolve_post_id_from_url($u){return strpos($u,"/about")!==false?42:0;}}',
    'class DHC_API_Key{public static function is_module_available($m){return true;}}',
    'class WP_Error{public $code,$message,$data;function __construct($c,$m,$d=array()){$this->code=$c;$this->message=$m;$this->data=$d;}}',
    'class WP_REST_Response{public $data,$status;function __construct($d,$s){$this->data=$d;$this->status=$s;}}',
    'class Req{private $p;function __construct($p){$this->p=$p;}function get_param($k){return isset($this->p[$k])?$this->p[$k]:null;}}',
    `require ${JSON.stringify(classFile)};`,
    '$valid=DHC_Schema::validate_json("{\\"@context\\":\\"https://schema.org\\",\\"@type\\":\\"Organization\\"}");',
    '$array=DHC_Schema::validate_json("[{\\"@type\\":\\"Organization\\"}]");',
    '$invalid=DHC_Schema::validate_json("{broken");',
    '$exact=DHC_Schema::resolve_page_url("https://example.com/about/");',
    '$front=DHC_Schema::resolve_page_url("https://example.com/");',
    '$near=DHC_Schema::resolve_page_url("https://example.com/about/team/");',
    '$foreign=DHC_Schema::resolve_page_url("https://agency.example/about/");',
    '$ok=DHC_Schema::handle_request(new Req(array("page_url"=>"https://example.com/about/","schema"=>array("@context"=>"https://schema.org","@type"=>"Organization"))));',
    '$mismatch=DHC_Schema::handle_request(new Req(array("post_id"=>7,"page_url"=>"https://example.com/about/","schema"=>array("@type"=>"Organization"))));',
    '$missing=DHC_Schema::handle_request(new Req(array("page_url"=>"https://example.com/missing/","schema"=>array("@type"=>"Organization"))));',
    'echo json_encode(array("valid"=>$valid,"array"=>$array,"invalid"=>$invalid,"exact"=>$exact,"front"=>$front,"near"=>$near,"foreign"=>$foreign,"ok_status"=>$ok->status,"ok_source"=>$GLOBALS["meta"][42]["_d2_custom_schema_source"],"mismatch_code"=>$mismatch->code,"missing_code"=>$missing->code,"missing_status"=>$missing->data["status"]));'
  ].join(' ');
  const result = JSON.parse(execFileSync('php', ['-r', script], { encoding: 'utf8' }));
  assert.equal(result.valid.valid, true);
  assert.equal(result.array.valid, true);
  assert.equal(result.invalid.valid, false);
  assert.equal(result.exact, 42);
  assert.equal(result.front, 7);
  assert.equal(result.near, 0);
  assert.equal(result.foreign, 0);
  assert.equal(result.ok_status, 200);
  assert.equal(result.ok_source, 'api');
  assert.equal(result.mismatch_code, 'dhc_schema_page_mismatch');
  assert.equal(result.missing_code, 'dhc_schema_page_not_found');
  assert.equal(result.missing_status, 404);
});
