<?php
// Isolated privacy and CSV fixture: no network, database, or real credentials.
define( 'ABSPATH', '/nonexistent-wordpress-fixture/' );

$options = array();
function check( $condition, $label ) { if ( ! $condition ) { fwrite( STDERR, "FAIL: $label\n" ); exit( 1 ); } }
function get_option( $name, $default = false ) { return $GLOBALS['options'][ $name ] ?? $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['options'][ $name ] = $value; return true; }
function sanitize_email( $value ) { return filter_var( strtolower( trim( (string) $value ) ), FILTER_VALIDATE_EMAIL ) ?: ''; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function esc_html__( $value, $domain = null ) { return $value; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function add_action() {}
function add_filter() {}

require __DIR__ . '/../includes/class-dhc-csv.php';
require __DIR__ . '/../includes/modules/class-dhc-form-capture.php';
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

$matching = DHC_Form_Capture::get_submissions_for_email( 'PERSON@example.test' );
check( 2 === count( $matching ), 'privacy lookup is case-insensitive exact email match' );
check( 'clean-one' === $matching[0]['id'] && 'spam-two' === $matching[1]['id'], 'privacy lookup preserves inbox order' );

$export = DHC_Privacy::export_personal_data( 'person@example.test', 1 );
check( true === $export['done'] && 2 === count( $export['data'] ), 'privacy exporter returns every matching clean and spam row' );
check( 'dhc-form-clean-one' === $export['data'][0]['item_id'], 'privacy export keeps stable item id' );
check( array() === DHC_Privacy::export_personal_data( 'person@example.test', 2 )['data'], 'privacy export pagination terminates' );

$erased = DHC_Privacy::erase_personal_data( 'PERSON@example.test', 1 );
check( true === $erased['items_removed'], 'privacy eraser reports local removal' );
check( true === $erased['items_retained'], 'privacy eraser reports possible forwarded clean lead' );
check( 1 === count( $options['dhc_form_inbox'] ) && 'other' === $options['dhc_form_inbox'][0]['id'], 'privacy eraser preserves nonmatching rows and order' );
$again = DHC_Privacy::erase_personal_data( 'person@example.test', 1 );
check( false === $again['items_removed'], 'privacy eraser is idempotent' );

$options['dhc_form_inbox'] = array(
    array( 'id' => 'legacy', 'email' => 'legacy@example.test', 'spam' => true ),
);
$legacy = DHC_Form_Capture::erase_submissions_for_email( 'legacy@example.test' );
check( true === $legacy['forwarded'], 'legacy row without immutable provenance is handled conservatively' );

$options['dhc_form_inbox'] = 'malformed';
check( array() === DHC_Form_Capture::get_submissions_for_email( 'person@example.test' ), 'malformed inbox fails closed' );

$method = new ReflectionMethod( 'DHC_Privacy', 'get_privacy_policy_text' );
if ( PHP_VERSION_ID < 80100 ) $method->setAccessible( true );
$policy = $method->invoke( null );
foreach ( array( 'newest 250', 'request IP address', 'exact email-address match', 'connected Dsquared Media Hub account' ) as $needle ) {
    check( false !== strpos( $policy, $needle ), 'privacy policy discloses ' . $needle );
}
check( false === strpos( $policy, 'NOT stored locally' ), 'privacy policy removes false no-storage claim' );

echo "PASS: shared CSV neutralization and truthful form-inbox privacy lifecycle\n";
