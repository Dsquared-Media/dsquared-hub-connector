<?php
// Isolated privacy and CSV fixture: no network, database, or real credentials.
define( 'ABSPATH', '/nonexistent-wordpress-fixture/' );

$options = array();
$subscription_valid = true;
$subscription_checks = 0;
$remote_posts = 0;
function check( $condition, $label ) { if ( ! $condition ) { fwrite( STDERR, "FAIL: $label\n" ); exit( 1 ); } }
function get_option( $name, $default = false ) { return $GLOBALS['options'][ $name ] ?? $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['options'][ $name ] = $value; return true; }
function sanitize_email( $value ) { return filter_var( strtolower( trim( (string) $value ) ), FILTER_VALIDATE_EMAIL ) ?: ''; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function esc_html__( $value, $domain = null ) { return $value; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_unslash( $value ) { return $value; }
function current_time( $type ) { return '2026-10-11 12:00:00'; }
function home_url( $path = '/' ) { return 'https://example.test' . $path; }
function wp_get_current_user() { return new class { public $display_name = 'Fixture User'; public $user_email = 'fixture@example.test'; public function exists() { return true; } }; }
function wp_remote_post() { $GLOBALS['remote_posts']++; return array(); }
function add_action() {}
function add_filter() {}
class DHC_API_Key { public static function validate() { $GLOBALS['subscription_checks']++; return array( 'valid' => $GLOBALS['subscription_valid'] ); } }
class DHC_Heartbeat { public static function get_hub_url() { return 'https://hub.example.test'; } }

require __DIR__ . '/../includes/class-dhc-csv.php';
require __DIR__ . '/../includes/modules/class-dhc-form-capture.php';
require __DIR__ . '/../includes/class-dhc-event-logger.php';
require __DIR__ . '/../includes/class-dhc-privacy.php';

$dangerous = array(
    '=1+1', '+15551234567', '-2+3', '@SUM(1,1)',
    " \t=HYPERLINK(\"https://example.test\")",
    "\r+1", "\n-1", "\xEF\xBB\xBF@SUM(1,1)", "\xC2\xA0=1+1",
);
foreach ( $dangerous as $value ) {
    check( "'" . $value === DHC_CSV::safe_cell( $value ), 'dangerous CSV cell neutralized without trimming' );
}
foreach ( array( 'Normal Name', 'person@example.test', 'https://example.test/', '2026-10-11 12:00:00', "'=@already-safe" ) as $value ) {
    check( $value === DHC_CSV::safe_cell( $value ), 'benign CSV cell preserved' );
}

$stream = fopen( 'php://temp', 'w+' );
DHC_CSV::write_row( $stream, array( '=1+1', 'Normal, value', "+15551234567" ) );
rewind( $stream );
$parsed = fgetcsv( $stream, 0, ',', '"', '' );
fclose( $stream );
check( array( "'=1+1", 'Normal, value', "'+15551234567" ) === $parsed, 'CSV row remains structurally valid and text-safe' );

$options['dhc_form_inbox'] = array(
    array( 'id' => 'clean-one', 'email' => 'Person@Example.test', 'name' => 'Person', 'phone' => '+15551234567', 'message' => 'Hello', 'source_url' => 'https://example.test/contact', 'ip_address' => '192.0.2.1', 'created_at' => '2026-10-11 12:00:00', 'spam' => true, 'forwarded_to_hub' => true ),
    array( 'id' => 'other', 'email' => 'other@example.test', 'name' => 'Other', 'spam' => false ),
    array( 'id' => 'spam-two', 'email' => 'person@example.test', 'name' => '=Formula', 'spam' => true, 'spam_reason' => 'Test', 'forwarded_to_hub' => false ),
);
$options['dhc_activity_log'] = array(
    array( 'action' => 'user_login', 'module' => 'wordpress', 'message' => 'Logged in.', 'time' => '2026-10-11 12:00:00', 'origin' => 'WordPress', 'user' => 'Person', 'email' => 'Person@Example.test', 'ip' => '192.0.2.2' ),
    array( 'action' => 'user_login', 'module' => 'wordpress', 'message' => 'Logged in.', 'time' => '2026-10-11 12:00:00', 'origin' => 'WordPress', 'user' => 'Person', 'email' => 'person@example.test', 'ip' => '192.0.2.2' ),
    array( 'action' => 'content_updated', 'email' => 'other@example.test', 'message' => 'Other row.' ),
    'legacy-malformed-row',
    array( 'action' => 'system', 'email' => '', 'message' => 'No personal email.' ),
);

$matching = DHC_Form_Capture::get_submissions_for_email( 'PERSON@example.test' );
check( 2 === count( $matching ), 'privacy lookup is case-insensitive exact email match' );
check( 'clean-one' === $matching[0]['id'] && 'spam-two' === $matching[1]['id'], 'privacy lookup preserves inbox order' );

$export = DHC_Privacy::export_personal_data( 'person@example.test', 1 );
check( true === $export['done'] && 4 === count( $export['data'] ), 'privacy exporter returns matching form and activity rows' );
check( 'dhc-form-clean-one' === $export['data'][0]['item_id'], 'privacy export keeps stable item id' );
check( 'dsquared-hub-activity-log' === $export['data'][2]['group_id'], 'privacy export identifies activity rows' );
check( $export['data'][2]['item_id'] !== $export['data'][3]['item_id'], 'identical activity rows receive unique item ids' );
check( array() === DHC_Privacy::export_personal_data( 'person@example.test', 2 )['data'], 'privacy export pagination terminates' );

$erased = DHC_Privacy::erase_personal_data( 'PERSON@example.test', 1 );
check( true === $erased['items_removed'], 'privacy eraser reports local removal' );
check( true === $erased['items_retained'], 'privacy eraser reports possible forwarded clean lead' );
check( 1 === count( $options['dhc_form_inbox'] ) && 'other' === $options['dhc_form_inbox'][0]['id'], 'privacy eraser preserves nonmatching rows and order' );
check( 3 === count( $options['dhc_activity_log'] ), 'privacy eraser removes every exact-email activity row' );
check( 'other@example.test' === $options['dhc_activity_log'][0]['email'] && 'legacy-malformed-row' === $options['dhc_activity_log'][1], 'privacy eraser preserves unrelated and malformed activity rows in order' );
$again = DHC_Privacy::erase_personal_data( 'person@example.test', 1 );
check( false === $again['items_removed'], 'privacy eraser is idempotent' );

$options['dhc_form_inbox'] = array(
    array( 'id' => 'legacy', 'email' => 'legacy@example.test', 'spam' => true ),
);
$legacy = DHC_Form_Capture::erase_submissions_for_email( 'legacy@example.test' );
check( true === $legacy['forwarded'], 'legacy row without immutable provenance is handled conservatively' );

$options['dhc_form_inbox'] = 'malformed';
check( array() === DHC_Form_Capture::get_submissions_for_email( 'person@example.test' ), 'malformed inbox fails closed' );

$options['dhc_activity_log'] = array();
$subscription_valid = true;
$subscription_checks = 0;
DHC_Event_Logger::activity( 'active_event', 'wordpress', 'Active event.' );
check( 1 === count( $options['dhc_activity_log'] ), 'active subscription records activity' );
check( 1 === $subscription_checks, 'direct activity performs one subscription check' );
$subscription_valid = false;
DHC_Event_Logger::activity( 'inactive_event', 'wordpress', 'Inactive event.' );
check( 1 === count( $options['dhc_activity_log'] ), 'inactive subscription blocks local activity' );
$remote_posts = 0;
$subscription_checks = 0;
$options['dhc_api_key'] = 'dhc_test_' . str_repeat( 'a', 40 );
DHC_Event_Logger::log( 'inactive_log', 'wordpress', array(), 'Inactive log.' );
check( 0 === $remote_posts && 1 === count( $options['dhc_activity_log'] ), 'inactive subscription blocks remote and local logging' );
check( 1 === $subscription_checks, 'log performs only one subscription check' );
$subscription_valid = true;
DHC_Event_Logger::log( 'active_log', 'wordpress', array(), 'Active log.' );
check( 1 === $remote_posts && 2 === count( $options['dhc_activity_log'] ), 'collection resumes when subscription becomes active' );

$method = new ReflectionMethod( 'DHC_Privacy', 'get_privacy_policy_text' );
if ( PHP_VERSION_ID < 80100 ) $method->setAccessible( true );
$policy = $method->invoke( null );
foreach ( array( 'newest 250', 'request IP address', 'Activity history', 'form inbox and activity history rows', 'exact email-address match', 'connected Dsquared Media Hub account' ) as $needle ) {
    check( false !== strpos( $policy, $needle ), 'privacy policy discloses ' . $needle );
}
check( false === strpos( $policy, 'NOT stored locally' ), 'privacy policy removes false no-storage claim' );

echo "PASS: shared CSV neutralization and truthful form-inbox privacy lifecycle\n";
