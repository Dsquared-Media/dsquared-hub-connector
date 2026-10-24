<?php
/** Synthetic WordPress/HTTP fixtures. No DNS, sockets, or real services. */
define( 'ABSPATH', '/offline-dhc/' );
$root = dirname( __DIR__ );
foreach ( $argv as $arg ) if ( strpos( $arg, '--root=' ) === 0 ) $root = substr( $arg, 7 );
$checks = 0; $options = array( 'home' => 'https://site.example.test' ); $posts = array(); $responses = array();
$dns_addresses = array(); $after_request = null;
$operations = array(); $hops = array(); $events = array(); $admin = false; $can_manage = false;
function check( $condition, $label ) {
    $GLOBALS['checks']++;
    if ( ! $condition ) { fwrite( STDERR, "FAIL: $label\n" ); exit( 1 ); }
}
function get_option( $key, $default = false ) { return $GLOBALS['options'][$key] ?? $default; }
function update_option( $key, $value, ...$args ) { $GLOBALS['options'][$key] = $value; }
function get_posts( $args ) {
    check( $args['post_status'] === 'publish' && $args['post_type'] === array( 'post', 'page' ) && $args['posts_per_page'] === 200, 'actual scanner keeps its published-content query and cap' );
    return array_keys( array_filter( $GLOBALS['posts'], function ( $post ) use ( $args ) { return $post->post_status === $args['post_status']; } ) );
}
function get_post( $id ) { return $GLOBALS['posts'][$id] ?? null; }
function get_permalink( $id ) { return 'https://site.example.test/post-' . $id; }
function current_time( $format ) { return '2026-10-24 12:00:00'; }
function home_url() { return 'https://site.example.test'; }
function is_admin() { return $GLOBALS['admin']; }
function is_404() { return true; }
function wp_unslash( $value ) { return $value; }
function current_user_can( $capability ) { return $GLOBALS['can_manage']; }
function wp_verify_nonce( $nonce, $action ) { return $nonce === 'offline-nonce'; }
class WP_Error { public $code; public $message; function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; } function get_error_code() { return $this->code; } function get_error_message() { return $this->message; } }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
class WP_REST_Response { public $data; function __construct( $data, $status = 200 ) { $this->data = $data; } }
class Request { private $nonce; function __construct( $nonce ) { $this->nonce = $nonce; } function get_header( $name ) { return $this->nonce; } }
class DHC_Event_Logger { public static function log( $event, $data ) { $GLOBALS['events'][] = array( $event, $data ); } }
function wp_remote_retrieve_response_code( $response ) { return $response['response']['code']; }
function wp_remote_retrieve_header( $response, $name ) { return $response['headers'][$name] ?? ''; }
function wp_kses_bad_protocol( $url, $allowed ) { return preg_match( '~\Ahttps?://~i', $url ) ? $url : ''; }
function apply_filters( $name, $value, ...$args ) { return $value; }
function fixture_dns_ipv4( $host ) {
    foreach ( $GLOBALS['dns_addresses'][$host] ?? array() as $address ) {
        if ( filter_var( $address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) return $address;
    }
    return $host;
}
// Exact WP 5.8 validator function, isolated only to intercept hostname lookup.
// Source: https://core.svn.wordpress.org/tags/5.8/wp-includes/http.php (GPL-2.0+).
// Immutable original release function at lines 516-594, not the maintained branch.
$wp58 = file_get_contents( __DIR__ . '/fixtures/wp-5.8-http-policy.php' );
eval( 'namespace DHC_WP58_Policy; function gethostbyname($host) { return \\fixture_dns_ipv4($host); }' . substr( $wp58, 5 ) );
// Unmodified native IRI implementations, pinned to immutable WordPress releases.
// Production uses the copy already bundled with the site's WordPress install.
$iri_variant = in_array( '--iri=wp62', $argv, true ) ? 'wp62' : 'wp58';
foreach ( json_decode( file_get_contents( __DIR__ . '/fixtures/iri-sources.json' ), true ) as $fixture ) {
    check( hash_file( 'sha256', __DIR__ . '/fixtures/' . $fixture['file'] ) === $fixture['sha256'], 'exact official IRI dependency: ' . $fixture['file'] );
}
spl_autoload_register( function ( $class ) use ( $iri_variant ) {
    if ( 'wp62' === $iri_variant && strpos( $class, 'WpOrg\\Requests\\' ) === 0 ) {
        $relative = str_replace( '\\', '/', substr( $class, strlen( 'WpOrg\\Requests\\' ) ) );
        $file = __DIR__ . '/fixtures/wp62-requests/' . $relative . '.php';
    } elseif ( 'wp58' === $iri_variant && strpos( $class, 'Requests_' ) === 0 ) {
        $file = __DIR__ . '/fixtures/wp58-requests/' . substr( $class, strlen( 'Requests_' ) ) . '.php';
    } else { return; }
    if ( is_file( $file ) ) require_once $file;
} );
$fixture_iri_class = 'wp62' === $iri_variant ? 'WpOrg\\Requests\\Iri' : 'Requests_IRI';
check( class_exists( $fixture_iri_class ), 'native IRI implementation loads for selected WordPress generation' );
function fixture_http( $method, $url, $args, $safe ) {
    $GLOBALS['operations'][] = array( 'method' => $method, 'url' => $url, 'args' => $args, 'safe' => $safe );
    // Execute the actual supported old-core policy, with all DNS/HTTP inert.
    $remaining = $args['redirection'];
    do {
        if ( true === $safe && ! DHC_WP58_Policy\wp_http_validate_url( $url ) ) return new WP_Error( 'unsafe-url' );
        $GLOBALS['hops'][] = array( $method, $url, $safe );
        $response = $GLOBALS['responses'][$method . ' ' . $url] ?? new WP_Error( 'unconfigured-fixture' );
        if ( is_wp_error( $response ) ) return $response;
        if ( $GLOBALS['after_request'] ) { $callback = $GLOBALS['after_request']; $GLOBALS['after_request'] = null; $callback(); }
        if ( ! isset( $response['location'] ) || 0 === $args['redirection'] ) return array(
            'response' => array( 'code' => $response['status'] ),
            'headers' => isset( $response['location'] ) ? array( 'location' => $response['location'] ) : array(),
            'body' => 'fixture body must never be returned',
        );
        $url = $response['location'];
    } while ( $remaining-- > 0 );
    return new WP_Error( 'redirect-limit' );
}
function wp_safe_remote_head( $url, $args ) { return fixture_http( 'HEAD', $url, $args, true ); }
function wp_safe_remote_get( $url, $args ) { return fixture_http( 'GET', $url, $args, true ); }
function wp_remote_head( $url, $args ) { return fixture_http( 'HEAD', $url, $args, false ); }
function wp_remote_get( $url, $args ) { return fixture_http( 'GET', $url, $args, false ); }
require $root . '/includes/modules/class-dhc-link-scanner.php';
class Offline_Link_Scanner extends DHC_Link_Scanner {
    protected static function requests_iri_class() { return $GLOBALS['fixture_iri_class']; }
    protected static function fetch_approved( $admission, $method, $args ) {
        $GLOBALS['admissions'][] = $admission;
        return fixture_http( $method, $admission['url'], $args, 'admitted' );
    }
    protected static function resolve_host_addresses( $host ) {
        if ( ! array_key_exists( $host, $GLOBALS['dns_addresses'] ) ) throw new RuntimeException( 'Unconfigured synthetic DNS: ' . $host );
        return $GLOBALS['dns_addresses'][$host];
    }
}

$urls = array();
foreach ( array( 'ok', 'missing', 'denied', 'head405', 'head501', 'offline', 'get-offline', 'redirect-ok', 'redirect-denied', 'get-redirect-denied', 'landing', 'draft-only' ) as $name ) $urls[$name] = 'https://' . $name . '.example.test/path';
foreach ( $urls as $url ) {
    $responses['HEAD ' . $url] = array( 'status' => 200 );
    $responses['GET ' . $url] = array( 'status' => 200 );
    $dns_addresses[parse_url( $url, PHP_URL_HOST )] = array( '8.8.8.8', '2001:4860:4860::8888' );
}
$responses['HEAD ' . $urls['missing']] = array( 'status' => 404 );
$responses['HEAD ' . $urls['head405']] = array( 'status' => 405 );
$responses['GET ' . $urls['head405']] = array( 'status' => 200 );
$responses['HEAD ' . $urls['head501']] = array( 'status' => 501 );
$responses['GET ' . $urls['head501']] = array( 'status' => 404 );
$responses['HEAD ' . $urls['offline']] = new WP_Error( 'offline' );
$responses['HEAD ' . $urls['get-offline']] = array( 'status' => 405 );
$responses['GET ' . $urls['get-offline']] = new WP_Error( 'offline' );
$responses['HEAD ' . $urls['redirect-ok']] = array( 'status' => 302, 'location' => $urls['landing'] );
$responses['HEAD ' . $urls['redirect-denied']] = array( 'status' => 302, 'location' => $urls['denied'] );
$responses['HEAD ' . $urls['get-redirect-denied']] = array( 'status' => 405 );
$responses['GET ' . $urls['get-redirect-denied']] = array( 'status' => 302, 'location' => $urls['denied'] );
$responses['GET ' . $urls['denied']] = array( 'status' => 200 );
$dns_addresses['denied.example.test'] = array( '169.254.169.254' );
$content = '';
foreach ( array_slice( $urls, 0, 10 ) as $url ) $content .= '<a href="' . $url . '">Synthetic link</a>';
$content .= '<a href="mailto:person@example.test">Email</a><a href="#fragment">Fragment</a><a href="/relative">Relative</a>';
$posts = array(
    1 => (object) array( 'post_content' => $content, 'post_title' => 'First synthetic post', 'post_status' => 'publish' ),
    2 => (object) array( 'post_content' => '<a href="' . $urls['missing'] . '">Repeated missing</a><a href="' . $urls['ok'] . '">Repeated good</a>', 'post_title' => 'Second synthetic post', 'post_status' => 'publish' ),
    3 => (object) array( 'post_content' => '<a href="' . $urls['draft-only'] . '">Not scanned</a>', 'post_title' => 'Draft', 'post_status' => 'draft' ),
);
Offline_Link_Scanner::run_weekly_scan();
$broken = get_option( DHC_Link_Scanner::OPT_BROKEN_LINKS );
$by_url = array_count_values( array_column( $broken, 'url' ) );
if ( in_array( '--baseline', $argv, true ) ) {
    echo json_encode( array(
        'unsafe_operations' => count( array_filter( $operations, function ( $op ) { return ! $op['safe']; } ) ),
        'denied_destination_was_fetched' => count( array_filter( $hops, function ( $hop ) use ( $urls ) { return $hop[1] === $urls['denied']; } ) ) > 0,
        'ordinary_200_stays_unbroken' => ! isset( $by_url[$urls['ok']] ),
        'ordinary_404_is_recorded' => ( $by_url[$urls['missing']] ?? 0 ) === 2,
    ), JSON_PRETTY_PRINT ), "\n";
    exit;
}
foreach ( $operations as $op ) {
    check( 'admitted' === $op['safe'], 'both HEAD and GET fallback use the immutable-admission transport boundary' );
    check( $op['args']['sslverify'] === true && $op['args']['timeout'] === 10 && $op['args']['redirection'] === 0, 'TLS and existing timeout remain enabled while native automatic redirects are disabled' );
    check( $op['args']['limit_response_size'] === 1024, 'status-only scanner requests a bounded retained response; this is not a transferred-byte guarantee' );
    check( ! isset( $op['args']['headers'] ) && ! isset( $op['args']['cookies'] ), 'scanner adds no credentials to arbitrary destinations' );
}
foreach ( array( 'ok', 'head405', 'redirect-ok' ) as $name ) check( ! isset( $by_url[$urls[$name]] ), 'successful public and safe redirected/fallback links remain unbroken' );
check( $by_url[$urls['missing']] === 2, 'one missing URL is still attributed to both source posts' );
foreach ( array( 'denied', 'head501', 'offline', 'get-offline', 'redirect-denied', 'get-redirect-denied' ) as $name ) check( $by_url[$urls[$name]] === 1, 'denied, failing, and unsuccessful fallback links retain status results' );
foreach ( $broken as $row ) {
    check( is_int( $row['status'] ) && ! array_key_exists( 'body', $row ), 'persisted scanner output remains status-only' );
    if ( in_array( $row['url'], array( $urls['denied'], $urls['redirect-denied'], $urls['get-redirect-denied'] ), true ) ) check( $row['status'] === 0, 'unsafe admission maps to the existing unavailable status' );
}
check( strpos( json_encode( $broken ), 'fixture body' ) === false, 'response bodies never enter saved findings' );
foreach ( array( 'ok', 'missing' ) as $name ) check( count( array_filter( $operations, function ( $op ) use ( $urls, $name ) { return $op['url'] === $urls[$name]; } ) ) === 1, 'deduplication still avoids repeated network operations' );
check( ! array_filter( $hops, function ( $hop ) use ( $urls ) { return $hop[1] === $urls['denied'] || $hop[1] === $urls['draft-only']; } ), 'denied redirect destinations and draft-only URLs are never fetched by the synthetic safe transport' );
check( $events[0][1]['posts_scanned'] === 2 && $events[0][1]['urls_checked'] === 10, 'existing scan summary remains accurate' );

function scan_url( $url, $routes = array(), $scanner = 'Offline_Link_Scanner' ) {
    $GLOBALS['posts'] = array( 1 => (object) array( 'post_content' => '<a href="' . $url . '">Synthetic destination</a>', 'post_title' => 'Synthetic post', 'post_status' => 'publish' ) );
    $GLOBALS['responses'] = $routes + array( 'HEAD ' . $url => array( 'status' => 200 ), 'GET ' . $url => array( 'status' => 200 ) );
    $GLOBALS['operations'] = array(); $GLOBALS['hops'] = array();
    $scanner::run_weekly_scan();
    $broken = get_option( DHC_Link_Scanner::OPT_BROKEN_LINKS );
    return empty( $broken ) ? 200 : $broken[0]['status'];
}
foreach ( array( '169.254.169.254', '169.254.10.10', '100.100.100.200' ) as $address ) {
    check( DHC_WP58_Policy\wp_http_validate_url( 'http://' . $address . '/' ) !== false, 'exact supported WP5.8 policy admits the recorded bypass control' );
}
foreach ( array(
    '0.0.0.1', '10.0.0.1', '100.64.0.1', '100.127.255.254', '127.0.0.1',
    '169.254.0.1', '169.254.169.254', '169.254.255.255', '172.16.0.1', '192.0.0.1', '192.0.2.1',
    '192.88.99.1', '192.168.1.1', '198.18.0.1', '198.51.100.1', '203.0.113.1', '224.0.0.1', '240.0.0.1',
    '2130706433', '0177.0.0.1', '0x7f000001', '127.1', '0xA9FEA9FE', '0251.0376.0251.0376', '169.254.169.254.',
    '[8.8.8.8]', '[::ffff:169.254.169.254]', '[::ffff:a9fe:a9fe]', '[fe80::1]', '[fc00::1]', '[::1]', '[::]', '[ff02::1]',
    '[64:ff9b::a9fe:a9fe]', '[2002:a9fe:a9fe::1]', '[2001:db8::1]', '[3fff::1]',
) as $host ) {
    check( scan_url( 'http://' . $host . '/' ) === 0 && count( $operations ) === 0, 'nonpublic and alternate IP forms reject before any native request' );
}
foreach ( array( '8.8.8.8', '1.1.1.1', '100.63.255.254', '100.128.0.1' ) as $host ) {
    check( scan_url( 'http://' . $host . '/' ) === 200 && count( $operations ) === 1, 'ordinary canonical public IPv4 controls remain available' );
}
foreach ( array( false, array(), array( '169.254.169.254' ), array( '8.8.8.8', '10.0.0.1' ), array( '8.8.8.8', 'fc00::1' ), array( '8.8.8.8', 42 ) ) as $answers ) {
    $dns_addresses['mixed.example.test'] = $answers;
    check( scan_url( 'http://mixed.example.test/' ) === 0 && count( $operations ) === 0, 'empty, malformed, or any nonpublic A/AAAA answer denies the hostname' );
}
$dns_addresses['mixed.example.test'] = array( '8.8.8.8', '1.1.1.1', '2001:4860:4860::8888' );
check( scan_url( 'https://mixed.example.test/' ) === 200 && count( $operations ) === 1, 'all-public multi-address DNS control still scans' );
$options['home'] = 'http://10.0.0.1';
check( DHC_WP58_Policy\wp_http_validate_url( 'http://10.0.0.1/' ) !== false, 'old-core same-home-host exception admits an otherwise rejected address' );
check( scan_url( 'http://10.0.0.1/' ) === 0 && count( $operations ) === 0, 'native same-home-host exception cannot bypass the scanner policy' );
$options['home'] = 'https://site.example.test';
foreach ( array( 'http://8.8.8.8:81/', 'http://user:pass@8.8.8.8/', 'http://%31%36%39.254.169.254/', 'http://8.8.8.8\\@169.254.169.254/', "http://8.8.8.8/\r\nheader", 'http://localhost/' ) as $url ) {
    check( scan_url( $url ) === 0 && count( $operations ) === 0, 'unsupported port, credentials, encoded host and parser ambiguity reject before native HTTP' );
}
$start = 'http://8.8.8.8/start';
foreach ( array( 301, 302, 303, 307, 308 ) as $status ) {
    check( scan_url( $start, array( 'HEAD ' . $start => array( 'status' => $status, 'location' => 'http://169.254.169.254/redirected' ) ) ) === 0 && count( $operations ) === 1, 'each redirect status is rechecked before following a link-local destination' );
}
check( scan_url( $start, array( 'HEAD ' . $start => array( 'status' => 405 ), 'GET ' . $start => array( 'status' => 307, 'location' => 'http://100.100.100.200/redirected' ) ) ) === 0 && count( $operations ) === 2, 'HEAD405 -> GET -> shared-address redirect is rejected before an admitted connection' );
foreach ( array( '/target', '//1.1.1.1/target', '../target', '//1.1.1.1', '?0', '?q=test', './target', '.././target' ) as $location ) {
    $target = $fixture_iri_class::absolutize( $start, $location )->uri;
    check( scan_url( $start, array( 'HEAD ' . $start => array( 'status' => 301, 'location' => $location ), 'HEAD ' . $target => array( 'status' => 200 ) ) ) === 200 && count( $operations ) === 2, 'native IRI preserves public path/query/authority redirect semantics: ' . $location );
}
check( scan_url( $start, array( 'HEAD ' . $start => array( 'status' => 301, 'location' => $start ) ) ) === 0 && count( $operations ) === 6, 'manual redirects stop after five hops beyond the initial request' );
$chain = array();
for ( $step = 0; $step < 5; $step++ ) $chain['HEAD http://8.8.8.8/hop' . $step] = array( 'status' => 301, 'location' => '/hop' . ( $step + 1 ) );
$chain['HEAD http://8.8.8.8/hop5'] = array( 'status' => 200 );
check( scan_url( 'http://8.8.8.8/hop0', $chain ) === 200 && count( $operations ) === 6, 'a public chain using all five allowed redirects still succeeds' );
foreach ( array( '//user@8.8.8.8/target', "http://8.8.8.8/\r\nheader", array( 'http://8.8.8.8/target' ) ) as $location ) {
    check( scan_url( $start, array( 'HEAD ' . $start => array( 'status' => 301, 'location' => $location ) ) ) === 0 && count( $operations ) === 1, 'ambiguous or credential-bearing Location values reject rather than being reinterpreted' );
}
$dns_addresses['changing.example.test'] = array( '8.8.8.8' );
$after_request = function () { $GLOBALS['dns_addresses']['changing.example.test'] = array( '169.254.169.254' ); };
check( scan_url( 'http://changing.example.test/', array( 'HEAD http://changing.example.test/' => array( 'status' => 405 ) ) ) === 0 && count( $operations ) === 1, 'GET fallback rechecks DNS after the HEAD decision rather than reusing a stale allow' );

// Exercise the actual production CNAME resolver with only inert DNS records.
class Offline_Record_Link_Scanner extends DHC_Link_Scanner {
    protected static function requests_iri_class() { return $GLOBALS['fixture_iri_class']; }
    protected static function fetch_approved( $admission, $method, $args ) {
        $GLOBALS['admissions'][] = $admission;
        return fixture_http( $method, $admission['url'], $args, 'admitted' );
    }
    protected static function query_host_records( $host ) {
        $GLOBALS['record_queries'][] = $host;
        if ( ! array_key_exists( $host, $GLOBALS['record_answers'] ) ) throw new RuntimeException( 'Unconfigured DNS records: ' . $host );
        return $GLOBALS['record_answers'][$host];
    }
}
function scan_records( $answers ) {
    $GLOBALS['record_answers'] = $answers;
    $GLOBALS['record_queries'] = array();
    return scan_url( 'https://alias.example.test/', array(), 'Offline_Record_Link_Scanner' );
}
$public_records = array( array( 'type' => 'A', 'ip' => '8.8.8.8' ), array( 'type' => 'AAAA', 'ipv6' => '2001:4860:4860::8888' ) );
$alias_record = array( array( 'type' => 'CNAME', 'target' => 'TARGET.EXAMPLE.TEST.' ) );
check( scan_records( array( 'alias.example.test' => $public_records ) ) === 200 && count( $operations ) === 1, 'direct public A/AAAA records remain checkable' );
check( scan_records( array( 'alias.example.test' => $alias_record, 'target.example.test' => $public_records ) ) === 200 && count( $operations ) === 1 && $record_queries === array( 'alias.example.test', 'target.example.test' ), 'alias-only response follows canonical CNAME target and retains ordinary public sites' );
foreach ( array( false, array(), array( array( 'type' => 'A', 'ip' => '169.254.169.254' ) ), array_merge( $public_records, array( array( 'type' => 'AAAA', 'ipv6' => 'fc00::1' ) ) ) ) as $answer ) {
    check( scan_records( array( 'alias.example.test' => $alias_record, 'target.example.test' => $answer ) ) === 0 && count( $operations ) === 0, 'unresolved, failed and mixed unsafe CNAME results reject before HTTP' );
}
foreach ( array( '', 'localhost', '//target.example.test', "target.example.test\r\n", array( 'target.example.test' ) ) as $target ) {
    check( scan_records( array( 'alias.example.test' => array( array( 'type' => 'CNAME', 'target' => $target ) ) ) ) === 0 && count( $operations ) === 0 && count( $record_queries ) === 1, 'malformed alias targets never reach a resolver or HTTP fallback' );
}
check( scan_records( array( 'alias.example.test' => $alias_record, 'target.example.test' => array( array( 'type' => 'CNAME', 'target' => 'alias.example.test' ) ) ) ) === 0 && count( $operations ) === 0 && count( $record_queries ) === 2, 'alias cycle terminates without unchecked fallback' );
$long_chain = array( 'alias.example.test' => array( array( 'type' => 'CNAME', 'target' => 'step1.example.test' ) ) );
for ( $i = 1; $i < 8; $i++ ) $long_chain['step' . $i . '.example.test'] = array( array( 'type' => 'CNAME', 'target' => 'step' . ( $i + 1 ) . '.example.test' ) );
$long_chain['step8.example.test'] = $public_records;
check( scan_records( $long_chain ) === 0 && count( $operations ) === 0 && count( $record_queries ) === 8, 'alias traversal has a fixed eight-name ceiling' );
$long_chain['step7.example.test'] = $public_records;
check( scan_records( $long_chain ) === 200 && count( $record_queries ) === 8, 'public alias chain at the documented ceiling still works' );

$dns_addresses['aaaa.example.test'] = array( '2001:4860:4860::8888' );
check( scan_url( 'https://aaaa.example.test/' ) === 200 && count( $operations ) === 1, 'AAAA-only DNS hostname retains a usable immutable admission' );
$literal = $fixture_iri_class::absolutize( 'http://[2001:4860:4860::8888]/', '/' )->uri;
check( scan_url( $literal ) === 200 && count( $operations ) === 1, 'public IPv6 literal is not rejected by the old core IPv4-only safe validator' );
$last_admission = end( $admissions );
check( $last_admission['host'] === parse_url( $literal, PHP_URL_HOST ) && $last_admission['addresses'] === array( trim( parse_url( $literal, PHP_URL_HOST ), '[]' ) ), 'native-normalized logical IPv6 host and admitted numeric address are both retained' );
$unavailable = 'http://8.8.8.8/unavailable';
$fixed_error = 'The selected transport cannot enforce the connection policy.';
check( scan_url( $unavailable, array( 'HEAD ' . $unavailable => new WP_Error( 'dhc_scan_transport', $fixed_error ) ) ) === 0, 'unsupported transport remains unavailable rather than inventing an HTTP status' );
check( DHC_Link_Scanner::get_broken_links()[0]['error'] === $fixed_error, 'fixed capability error survives saved results and the existing result reader' );
check( scan_url( $unavailable, array( 'HEAD ' . $unavailable => new WP_Error( 'socket_error', 'synthetic-private-proxy-details' ) ) ) === 0, 'unknown transport errors still record an unavailable link' );
check( DHC_Link_Scanner::get_broken_links()[0]['error'] === 'The request could not be completed.', 'native socket details are not copied to stored findings' );
check( scan_url( $unavailable, array( 'HEAD ' . $unavailable => array( 'status' => 404 ) ) ) === 404 && DHC_Link_Scanner::get_broken_links()[0]['error'] === '', 'a later ordinary missing link does not inherit the previous transport failure' );
echo "PASS: $checks offline link-scanner checks ($iri_variant IRI)\n";
