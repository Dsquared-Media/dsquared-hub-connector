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
  assert.match(source, /name="dhc_guided_dirty" value="0"/);
  assert.match(source, /if \( empty\( \$_POST\['dhc_guided_dirty'\] \) \) return/);
  assert.match(source, /data-add-same-as/);
  assert.match(source, /data-remove-row/);
  assert.match(source, /dhc_guided\[same_as\]\[/);
  assert.match(source, /t==='Organization'/);
  assert.match(source, /manage_pages_custom_column/);
  assert.match(source, /type="application\/ld\+json"/);
  const route = rest.slice(rest.indexOf("'/schema'"), rest.indexOf('// ── SEO Meta Sync'));
  assert.match(route, /DHC_API_Key', 'authenticate_request/);
  assert.match(route, /'page_url'/);
});

test('guided mode only accepts schemas it can round-trip without data loss', () => {
  const script = [
    "define('ABSPATH', __DIR__);",
    'function sanitize_text_field($v){return trim(strip_tags((string)$v));}',
    'function sanitize_textarea_field($v){return trim(strip_tags((string)$v));}',
    'function esc_url_raw($v){return filter_var($v,FILTER_VALIDATE_URL)?$v:"";}',
    'function url_to_postid($v){return $v==="https://example.com/about/"?42:($v==="https://example.com/plain/"?43:0);}',
    'function get_permalink($id){return $id===42?"https://example.com/about/":($id===43?"https://example.com/plain/":"");}',
    'function untrailingslashit($v){return rtrim($v,"/");}',
    'function wp_json_encode($v,$f=0){return json_encode($v,$f);}',
    'function get_post($id){return in_array($id,array(42,43),true)?(object)array("post_type"=>"page","post_status"=>"publish"):null;}',
    'function get_post_meta($id,$key,$single=true){return $id===42&&$key==="_d2_custom_schema"?array("@type"=>"Organization"):"";}',
    `require ${JSON.stringify(classFile)};`,
    '$method=new ReflectionMethod("DHC_Schema","guided_schema_is_lossless");',
    '$article=$method->invoke(null,array("@context"=>"https://schema.org","@type"=>"Article","headline"=>"Title","author"=>array("@type"=>"Person","name"=>"Jane")));',
    '$organization=$method->invoke(null,array("@context"=>"https://schema.org","@type"=>"Organization","name"=>"Example","telephone"=>"555-1212"));',
    '$context=$method->invoke(null,array("@context"=>array("https://schema.org"),"@type"=>"Organization","name"=>"Example"));',
    '$markup=$method->invoke(null,array("@context"=>"https://schema.org","@type"=>"Organization","description"=>"<b>Example</b>"));',
    '$external=$method->invoke(null,array("@context"=>"https://schema.org","@type"=>"LocalBusiness","branchOf"=>array("@type"=>"Organization","url"=>"https://other.example/")));',
    '$weekday=$method->invoke(null,array("@context"=>"https://schema.org","@type"=>"LocalBusiness","openingHoursSpecification"=>array(array("@type"=>"OpeningHoursSpecification","dayOfWeek"=>"https://schema.org/Monday","opens"=>"09:00","closes"=>"17:00"))));',
    '$parent=$method->invoke(null,array("@context"=>"https://schema.org","@type"=>"LocalBusiness","branchOf"=>array("@type"=>"Organization","url"=>"https://example.com/about/")));',
    '$self=$method->invoke(null,array("@context"=>"https://schema.org","@type"=>"LocalBusiness","branchOf"=>array("@type"=>"Organization","url"=>"https://example.com/about/")),42);',
    '$plain=$method->invoke(null,array("@context"=>"https://schema.org","@type"=>"LocalBusiness","branchOf"=>array("@type"=>"Organization","url"=>"https://example.com/plain/")));',
    'echo json_encode(array("article"=>$article,"organization"=>$organization,"context"=>$context,"markup"=>$markup,"external"=>$external,"weekday"=>$weekday,"parent"=>$parent,"self"=>$self,"plain"=>$plain));'
  ].join(' ');
  const result = JSON.parse(execFileSync('php', ['-r', script], { encoding: 'utf8' }));
  assert.equal(result.article, false);
  assert.equal(result.organization, true);
  assert.equal(result.context, false);
  assert.equal(result.markup, false);
  assert.equal(result.external, false);
  assert.equal(result.weekday, false);
  assert.equal(result.parent, true);
  assert.equal(result.self, false);
  assert.equal(result.plain, false);
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
