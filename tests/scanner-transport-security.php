<?php
/** Exact WP5.8/6.2 Requests + local-only TLS/proxy; numeric destinations are intercepted. */
namespace DHC\Scanner {
    function defined( $name ) {
        // --legacy-progress models PHP7.4's absent XFERINFO binding on newer PHP.
        if ( 'CURLOPT_XFERINFOFUNCTION' === $name && ! empty( $GLOBALS['legacy_progress'] ) ) return false;
        if ( 'CURLOPT_CONNECT_TO' === $name && ! empty( $GLOBALS['missing_connect_to'] ) ) return false;
        return \defined( $name );
    }
    function curl_version() {
        $version = \curl_version();
        if ( ! empty( $GLOBALS['old_curl'] ) ) $version['version_number'] = 0x073000;
        return $version;
    }
    function curl_setopt( $handle, $option, $value ) {
        if ( CURLOPT_CONNECT_TO === $option ) {
            $GLOBALS['pins'][] = $value[0];
            if ( ! empty( $GLOBALS['reject_pin'] ) ) return false;
            if ( ! $GLOBALS['socket_proxy'] ) {
                preg_match( '/\A(\[[^]]+\]|[^:]*):(\d+):(\[[^]]+\]|[^:]*):(\d+)\z/', $value[0], $m );
                if ( ! $m ) throw new \RuntimeException( 'Malformed numeric cURL pin' );
                $port = '8.8.8.8' === $m[3] && $GLOBALS['fail_first'] ? 1 : $GLOBALS['ports'][ $m[4] === '443' ? 'tls' : 'plain' ];
                $value = array( $m[1] . ':' . $m[2] . ':127.0.0.1:' . $port );
            }
        }
        $GLOBALS['curl_options'][$option] = $value;
        return \curl_setopt( $handle, $option, $value );
    }
    function stream_socket_client( $remote, &$errno, &$errstr, $timeout, $flags, $context ) {
        $GLOBALS['sockets'][] = array( $remote, stream_context_get_options( $context ) );
        if ( ! $GLOBALS['socket_proxy'] ) {
            $parts = parse_url( $remote );
            $port = '8.8.8.8' === $parts['host'] && $GLOBALS['fail_first'] ? 1 : $GLOBALS['ports'][ $parts['port'] === 443 ? 'tls' : 'plain' ];
            $remote = 'tcp://127.0.0.1:' . $port;
        }
        return \stream_socket_client( $remote, $errno, $errstr, $timeout, $flags, $context );
    }
}

namespace {
    error_reporting( E_ALL & ~E_DEPRECATED ); // Immutable WP6.2 has PHP8.5 deprecations.
    $temp = null; $wp_variant = 'wp58';
    $legacy_progress = in_array( '--legacy-progress', $argv, true );
    $legacy_ipv6_tls = in_array( '--legacy-ipv6-tls', $argv, true );
    $missing_bridge = in_array( '--missing-bridge', $argv, true );
    // Model only the explicit older-streams capability gate on newer PHP.
    if ( $legacy_ipv6_tls ) define( 'DHC\\Scanner\\PHP_VERSION_ID', 80299 );
    foreach ( $argv as $arg ) {
        if ( 0 === strpos( $arg, '--temp=' ) ) $temp = substr( $arg, 7 );
        if ( 0 === strpos( $arg, '--wp=' ) ) $wp_variant = substr( $arg, 5 );
    }
    if ( ! in_array( $wp_variant, array( 'wp58', 'wp62' ), true ) ) throw new RuntimeException( 'Use --wp=wp58 or --wp=wp62.' );
    if ( ! $temp || ! is_dir( $temp ) ) throw new RuntimeException( 'Pass a managed disposable directory using --temp=' );
    define( 'ABSPATH', __DIR__ . '/fixtures/' );
    define( 'WPINC', $wp_variant . '-http' );
    $checks = 0; $filters = array(); $pins = array(); $sockets = array(); $curl_options = array(); $fail_first = false;
    function check( $condition, $label ) {
        $GLOBALS['checks']++;
        if ( ! $condition ) throw new RuntimeException( 'FAIL: ' . $label );
    }
    function apply_filters( $name, $value, ...$args ) {
        foreach ( $GLOBALS['filters'][$name] ?? array() as $callback ) $value = $callback( $value, ...$args );
        return $value;
    }
    function do_action_ref_array( $name, $args, ...$ignored ) {
        foreach ( $GLOBALS['filters'][$name] ?? array() as $callback ) $callback( ...$args );
    }
    function do_action( $name, ...$args ) {
        foreach ( $GLOBALS['filters'][$name] ?? array() as $callback ) $callback( ...$args );
    }
    function get_option( $key, $default = false ) { return 'siteurl' === $key ? 'https://site.example.test' : $default; }
    function update_option( $key, $value, ...$args ) { $GLOBALS['saved'][$key] = $value; }
    function get_posts( $args ) { return array( 1 ); }
    function get_post( $id ) { return (object) array( 'post_title' => 'Synthetic', 'post_content' => '<a href="' . $GLOBALS['scan_url'] . '">Test</a>' ); }
    function get_permalink( $id ) { return 'https://site.example.test/source'; }
    function current_time( $format ) { return '2026-10-24 12:00:00'; }
    function wp_remote_retrieve_response_code( $response ) { return $response['response']['code']; }
    function wp_remote_retrieve_header( $response, $header ) { return $response['headers'][$header] ?? ''; }
    class DHC_Event_Logger { public static function log( $event, $data ) {} }
    function is_wp_error( $value ) { return $value instanceof WP_Error; }
    class WP_Error {
        private $message;
        private $code;
        public function __construct( $code, $message ) { $this->code = $code; $this->message = $message; }
        public function get_error_message() { return $this->message; }
        public function get_error_code() { return $this->code; }
    }
    foreach ( json_decode( file_get_contents( __DIR__ . '/fixtures/transport-sources.json' ), true ) as $source ) {
        check( hash_file( 'sha256', __DIR__ . '/fixtures/' . $source['file'] ) === $source['sha256'], 'immutable official source ' . $source['file'] );
    }
    // Both real Requests families remain loadable. The adapter must choose the
    // generation used by the WP hook bridge, including on legacy WordPress.
    require __DIR__ . '/fixtures/wp58-requests/Requests.php';
    spl_autoload_register( function ( $class ) {
        if ( 0 !== strpos( $class, 'Requests_' ) ) return;
        $file = __DIR__ . '/fixtures/wp58-requests/' . str_replace( '_', '/', substr( $class, 9 ) ) . '.php';
        if ( is_file( $file ) ) require_once $file;
    } );
    require __DIR__ . '/fixtures/wp62-requests/Autoload.php';
    WpOrg\Requests\Autoload::register();
    check( '1.8.1' === Requests::VERSION && '2.0.5' === WpOrg\Requests\Requests::VERSION, 'both immutable Requests libraries are available without global aliases' );
    if ( 'wp58' === $wp_variant ) {
        require __DIR__ . '/fixtures/wp58-http/class-http.php';
    } else {
        require __DIR__ . '/fixtures/wp62-http/class-wp-http.php';
    }
    if ( $missing_bridge ) {
        require dirname( __DIR__ ) . '/includes/class-dhc-scanner-http.php';
        // PHP may compile an unparented helper class before the early return;
        // the native dependency aliases are the usable-boundary prerequisite.
        check( ! class_exists( 'DHC\\Scanner\\NativeIri', false ) && ! class_exists( 'DHC\\Scanner\\NativeRequests', false ), 'missing compatible WP hook bridge leaves native connection dependencies unavailable' );
        require dirname( __DIR__ ) . '/includes/modules/class-dhc-link-scanner.php';
        $scan_url = 'https://scan.example.test/ok';
        DHC_Link_Scanner::run_weekly_scan();
        $rows = $saved[DHC_Link_Scanner::OPT_BROKEN_LINKS];
        check( 1 === count( $rows ) && 0 === $rows[0]['status'] && false !== strpos( $rows[0]['error'], 'WordPress HTTP connection policy is unavailable' ), 'missing native bridge fails visibly before DNS or transport execution' );
        echo 'PASS: ' . $checks . ' ' . $wp_variant . " missing-native-bridge checks; no sockets started\n";
        exit;
    }
    require __DIR__ . '/fixtures/' . $wp_variant . '-http/class-wp-http-proxy.php';
    require __DIR__ . '/fixtures/' . $wp_variant . '-http/class-wp-http-requests-hooks.php';
    require dirname( __DIR__ ) . '/includes/class-dhc-scanner-http.php';
    check( ( 'wp58' === $wp_variant ? '1.8.1' : '2.0.5' ) === DHC\Scanner\NativeRequests::VERSION
        && is_subclass_of( 'WP_HTTP_Requests_Hooks', 'DHC\\Scanner\\NativeHooks' ), 'adapter native aliases match actual WordPress hook ancestry' );
    require dirname( __DIR__ ) . '/includes/modules/class-dhc-link-scanner.php';
    class Native_Scanner extends DHC_Link_Scanner {
        protected static function resolve_host_addresses( $host ) {
            $GLOBALS['dns_queries'][] = $host;
            return $GLOBALS['dns_answer'];
        }
    }
    class Unknown_Transport implements DHC\Scanner\NativeTransport {
        public static function test( $capabilities = array() ) { return true; }
        public function request( $url, $headers = array(), $data = array(), $options = array() ) { $GLOBALS['unknown_called'] = true; throw new RuntimeException( 'An unpinned transport was reached' ); }
        public function request_multiple( $requests, $options ) { $GLOBALS['unknown_called'] = true; throw new RuntimeException( 'An unpinned transport was reached' ); }
    }
    $config = $temp . '/openssl.cnf';
    file_put_contents( $config, "[req]\ndistinguished_name=dn\nx509_extensions=ext\nprompt=no\n[dn]\nCN=scan.example.test\n[ext]\nbasicConstraints=critical,CA:TRUE\nsubjectAltName=DNS:scan.example.test,DNS:bypass.example.test,IP:2001:4860:4860::8888\nkeyUsage=digitalSignature,keyEncipherment,keyCertSign\n" );
    $key = openssl_pkey_new( array( 'private_key_bits' => 2048 ) );
    $csr = openssl_csr_new( array( 'commonName' => 'scan.example.test' ), $key, array( 'config' => $config ) );
    $cert = openssl_csr_sign( $csr, null, $key, 1, array( 'config' => $config, 'x509_extensions' => 'ext' ) );
    openssl_pkey_export( $key, $key_pem ); openssl_x509_export( $cert, $cert_pem );
    file_put_contents( $temp . '/key.pem', $key_pem ); file_put_contents( $temp . '/cert.pem', $cert_pem );
    $server = proc_open( array( '/usr/bin/python3', __DIR__ . '/fixtures/scanner-local-server.py', $temp . '/cert.pem', $temp . '/key.pem' ),
        array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes );
    if ( ! is_resource( $server ) ) throw new RuntimeException( 'Local fixture failed to start' );
    try {
        $ports = json_decode( fgets( $pipes[1] ), true );
        check( is_array( $ports ) && isset( $ports['tls'], $ports['proxy'], $ports['plain'] ), 'local-only fixture starts' );
        define( 'WP_PROXY_HOST', '127.0.0.1' ); define( 'WP_PROXY_PORT', $ports['proxy'] );
        define( 'WP_PROXY_USERNAME', 'synthetic' ); define( 'WP_PROXY_PASSWORD', 'synthetic' );
        define( 'WP_PROXY_BYPASS_HOSTS', 'bypass.example.test' );
        $via_proxy = false;
        $filters['pre_http_send_through_proxy'][] = function ( $result, $url ) {
            if ( false !== strpos( $url, 'bypass.example.test' ) ) return null;
            return $GLOBALS['via_proxy'];
        };
        $filters['http_request_args'][] = function ( $args ) use ( $temp ) { $args['sslcertificates'] = $temp . '/cert.pem'; return $args; };
        function selected( $class ) {
            foreach ( array( 'transports' => array( $class ), 'transport' => array() ) as $property => $value ) {
                $field = new ReflectionProperty( DHC\Scanner\NativeRequests::class, $property ); $field->setAccessible( true ); $field->setValue( null, $value );
            }
        }
        function fetch( $url = 'https://scan.example.test/ok', $addresses = array( '1.1.1.1' ), $method = 'HEAD' ) {
            $parts = parse_url( $url );
            $GLOBALS['socket_proxy'] = ( new WP_HTTP_Proxy() )->is_enabled() && ( new WP_HTTP_Proxy() )->send_through_proxy( $url );
            return DHC\Scanner\Http::request( array( 'url' => $url, 'host' => $parts['host'], 'scheme' => $parts['scheme'],
                'port' => $parts['port'] ?? ( 'https' === $parts['scheme'] ? 443 : 80 ), 'addresses' => $addresses ), $method,
                array( 'timeout' => 3, 'redirection' => 0, 'sslverify' => true, 'user-agent' => 'SyntheticScanner/1.0' ) );
        }
        function events() {
            fwrite( $GLOBALS['pipes'][0], "events\n" );
            return json_decode( fgets( $GLOBALS['pipes'][1] ), true );
        }
        function scan( $path ) {
            $GLOBALS['scan_url'] = 'https://scan.example.test' . $path;
            $GLOBALS['socket_proxy'] = false;
            Native_Scanner::run_weekly_scan();
            return $GLOBALS['saved'][DHC_Link_Scanner::OPT_BROKEN_LINKS];
        }
        putenv( 'HTTPS_PROXY=http://127.0.0.1:' . $ports['proxy'] ); putenv( 'HTTP_PROXY=http://127.0.0.1:' . $ports['proxy'] );
        putenv( 'ALL_PROXY=http://127.0.0.1:' . $ports['proxy'] ); putenv( 'NO_PROXY=*' );
        foreach ( array( DHC\Scanner\NativeCurl::class, DHC\Scanner\NativeFsockopen::class ) as $backend ) {
            selected( $backend );
            $via_proxy = false;
            $res = fetch();
            check( ! is_wp_error( $res ) && 200 === $res['response']['code'], $backend . ' real local TLS retains logical Host/SNI and trusts supplied CA' );
            $filters['http_request_args'][] = function ( $args, $url ) {
                check( 'https://scan.example.test/ok' === $url, 'argument filter receives the logical hostname' );
                $args['headers'] = null;
                return $args;
            };
            check( ! is_wp_error( fetch() ), $backend . ' WordPress null header form remains supported' );
            array_pop( $filters['http_request_args'] );
            $filters['http_request_args'][] = function ( $args ) { $args['headers'] = "X-Fixture: string-header\r\n"; return $args; };
            check( ! is_wp_error( fetch() ), $backend . ' WordPress string header form remains supported' );
            array_pop( $filters['http_request_args'] );
            $header_requests = array_column( events(), 'origin' );
            check( false !== stripos( end( $header_requests ), 'X-Fixture: string-header' ), $backend . ' normalized string header reaches the logical origin' );
            $debug_events = array(); $response_events = 0;
            $filters['http_api_debug'][] = function ( $response, $context, $class, $args, $url ) use ( &$debug_events ) {
                check( 'response' === $context && 'https://scan.example.test/ok' === $url, 'HTTP observer receives response context and logical hostname' );
                check( $class === ( 'wp58' === $GLOBALS['wp_variant'] ? 'Requests' : 'WpOrg\\Requests\\Requests' ), 'HTTP observer receives the native library identifier for this WordPress version' );
                if ( ! is_wp_error( $response ) ) check( '' === $response['body'], 'HTTP observer receives no downloaded body' );
                $debug_events[] = $response;
            };
            $filters['http_response'][] = function ( $response, $args, $url ) use ( &$response_events ) {
                $response_events++;
                check( '' === $response['body'] && 'https://scan.example.test/ok' === $url, 'response filter receives a status-only result for the logical hostname' );
                $response['response']['code'] = 204;
                $response['headers']['X-Fixture-Filtered'] = 'yes';
                return $response;
            };
            $filtered = fetch();
            check( ! is_wp_error( $filtered ) && 204 === $filtered['response']['code'] && 'yes' === $filtered['headers']['X-Fixture-Filtered'], $backend . ' normal HTTP response filtering remains observable' );
            check( 1 === count( $debug_events ) && 200 === $debug_events[0]['response']['code'] && 1 === $response_events, 'HTTP debug observes the sanitized result before the response filter' );
            $filters['requests-requests.before_request'][] = function () { throw new RuntimeException( 'synthetic-raw-native-secret-must-not-escape' ); };
            $before_error_origins = count( array_column( events(), 'origin' ) );
            $failed = fetch();
            check( is_wp_error( $failed ) && 0 === strpos( $failed->get_error_code(), 'dhc_scan_' ) && false === strpos( $failed->get_error_message(), 'synthetic-raw-native-secret' ), $backend . ' native exceptions produce only fixed scanner errors' );
            check( 2 === count( $debug_events ) && is_wp_error( $debug_events[1] ) && 1 === $response_events && $before_error_origins === count( array_column( events(), 'origin' ) ), 'HTTP debug sees a sanitized error while success filters and origin traffic stay untouched' );
            array_pop( $filters['requests-requests.before_request'] );
            array_pop( $filters['http_api_debug'] ); array_pop( $filters['http_response'] );
            if ( DHC\Scanner\NativeCurl::class === $backend ) {
                $progress_option = ! $legacy_progress && defined( 'CURLOPT_XFERINFOFUNCTION' ) ? constant( 'CURLOPT_XFERINFOFUNCTION' ) : CURLOPT_PROGRESSFUNCTION;
                check( isset( $curl_options[$progress_option] ) && false === $curl_options[CURLOPT_NOPROGRESS], 'available PHP progress binding remains installed and enabled' );
                $progress = $curl_options[$progress_option];
                check( 0 === $progress(), 'progress callback permits a transfer within its deadline' );
                $transport = ( new ReflectionFunction( $progress ) )->getClosureThis();
                $deadline = new ReflectionProperty( $transport, 'deadline' ); $deadline->setAccessible( true );
                $original_deadline = $deadline->getValue( $transport );
                $deadline->setValue( $transport, microtime( true ) - 1 );
                check( 1 === $progress(), 'progress callback aborts a transfer after the same deadline on either PHP binding' );
                $deadline->setValue( $transport, $original_deadline );
            }
            $before_origins = count( array_column( events(), 'origin' ) );
            $before_sockets = count( $sockets );
            $literal = fetch( 'https://[2001:4860:4860::8888]/ok', array( '2001:4860:4860::8888' ) );
            if ( DHC\Scanner\NativeFsockopen::class === $backend && ( PHP_VERSION_ID < 80300 || $legacy_ipv6_tls ) ) {
                check( is_wp_error( $literal ) && 'dhc_scan_ipv6_tls_unsupported' === $literal->get_error_code()
                    && $before_sockets === count( $sockets ) && $before_origins === count( array_column( events(), 'origin' ) ), 'older streams explicitly reject unsupported IPv6 IP identity before any socket or origin traffic' );
            } else {
                check( ! is_wp_error( $literal ), $backend . ' IPv6 literal retains IP certificate identity' );
                $before_origins = count( array_column( events(), 'origin' ) );
                check( is_wp_error( fetch( 'https://[2001:4860:4860::8844]/ok', array( '2001:4860:4860::8844' ) ) )
                    && $before_origins === count( array_column( events(), 'origin' ) ), $backend . ' wrong IPv6 IP SAN is rejected before any origin request' );
            }
            check( ! is_wp_error( fetch( 'https://scan.example.test/ok', array( '2001:4860:4860::8888' ) ) ), $backend . ' DNS identity over an admitted AAAA address remains supported' );
            $res = fetch( 'https://wrong.example.test/ok' );
            check( is_wp_error( $res ), $backend . ' rejects valid CA with wrong hostname' );
            $filters['https_ssl_verify'][] = function () { return false; };
            check( is_wp_error( fetch() ), $backend . ' refuses disabled certificate verification' );
            $filters['https_ssl_verify'] = array( function () { return true; } );
            check( is_wp_error( fetch() ), $backend . ' untrusted CA cannot complete the TLS handshake' );
            $filters['https_ssl_verify'] = array();
            $fail_first = true;
            check( ! is_wp_error( fetch( 'https://scan.example.test/ok', array( '8.8.8.8', '1.1.1.1' ) ) ), $backend . ' approved address failover succeeds without hostname lookup' );
            $fail_first = false;
            check( ! is_wp_error( fetch( 'http://scan.example.test/ok', array( '2001:4860:4860::8888' ), 'GET' ) ), $backend . ' numeric IPv6 pin syntax and GET status remain supported' );
            $res = fetch( 'http://scan.example.test/redirect' );
            check( ! is_wp_error( $res ) && 302 === $res['response']['code'] && 'http://169.254.169.254/private' === $res['headers']['location'], $backend . ' native Requests cannot auto-follow redirects behind the boundary' );
            $via_proxy = true;
            check( ! is_wp_error( fetch() ), $backend . ' selected authenticated HTTP proxy establishes a numeric HTTPS tunnel despite NO_PROXY=*' );
            check( ! is_wp_error( fetch( 'http://scan.example.test/ok' ) ), $backend . ' selected proxy establishes a numeric plain HTTP tunnel' );
            $before = count( array_column( events(), 'origin' ) );
            check( is_wp_error( fetch( 'http://scan.example.test:8080/ok' ) ) && $before === count( array_column( events(), 'origin' ) ), $backend . ' proxy tunnel refusal fails visibly without direct fallback' );
            $via_proxy = true;
            check( ! is_wp_error( fetch( 'https://bypass.example.test/ok' ) ), $backend . ' configured hostname bypass stays direct' );
            $via_proxy = false;
            $dns_answer = array( '1.1.1.1' ); $dns_queries = array();
            $filters['requests-requests.before_request'][] = function () { $GLOBALS['dns_answer'] = array( '169.254.169.254' ); };
            check( array() === scan( '/ok' ) && 1 === count( $dns_queries ), $backend . ' resolver changes after admission cannot change the connected origin' );
            $dns_answer = array( '1.1.1.1' ); $dns_queries = array();
            $before = count( array_column( events(), 'origin' ) );
            $rows = scan( '/head405' );
            check( 0 === $rows[0]['status'] && 2 === count( $dns_queries ) && $before + 1 === count( array_column( events(), 'origin' ) ), $backend . ' HEAD-to-GET fallback re-admits DNS and sends nothing after a private answer' );
            $filters['requests-requests.before_request'] = array();
            $dns_answer = array( '1.1.1.1' );
            check( array() === scan( '/head405' ), $backend . ' ordinary HEAD405-to-GET succeeds through the real connection boundary' );
            check( array() === scan( '/redirect-ok' ), $backend . ' public redirect follows only through fresh scanner admission' );
            $before = count( array_column( events(), 'origin' ) );
            $rows = scan( '/redirect' );
            check( 0 === $rows[0]['status'] && $before + 1 === count( array_column( events(), 'origin' ) ), $backend . ' private redirect never gets a second connection' );
            if ( DHC\Scanner\NativeFsockopen::class === $backend ) {
                check( is_wp_error( fetch( 'http://scan.example.test/oversized' ) ), 'streams rejects headers above 64KiB' );
            }
        }
        selected( DHC\Scanner\NativeCurl::class );
        $via_proxy = false;
        $reject_pin = true; $before = count( events() );
        check( is_wp_error( fetch() ) && $before === count( events() ), 'failed cURL pin produces visible failure without executing an unpinned request' );
        $reject_pin = false;
        $missing_connect_to = true; $before = count( events() );
        check( is_wp_error( fetch() ) && $before === count( events() ), 'unavailable cURL binding capability fails before any unpinned traffic' );
        $missing_connect_to = false;
        $old_curl = true; $before = count( events() );
        check( is_wp_error( fetch() ) && $before === count( events() ), 'old cURL without connection binding fails before any unpinned traffic' );
        $old_curl = false;
        $filters['requests-requests.before_request'][] = function ( &$url ) { $url = 'http://169.254.169.254/private'; };
        $before = count( events() );
        check( is_wp_error( fetch() ) && $before === count( events() ), 'native before-request URL mutation fails before any connection' );
        $filters['requests-requests.before_request'] = array();
        selected( Unknown_Transport::class );
        $before = count( events() );
        check( is_wp_error( fetch() ) && empty( $GLOBALS['unknown_called'] ) && $before === count( events() ), 'unknown selected transport fails visibly without native or unpinned fallback' );
        selected( DHC\Scanner\NativeCurl::class );
        $filters['requests-requests.before_request'][] = function ( &$url, &$headers, &$data, &$type, &$options ) { $options['transport'] = new Unknown_Transport(); };
        check( ! is_wp_error( fetch() ), 'request-owned enforcement retains the pinned transport after native hook transport mutation' );
        $filters['requests-requests.before_request'] = array();
        $filters['requests-requests.before_request'][] = function ( &$url, &$headers, &$data, &$type, &$options ) { $options['type'] = 'POST'; };
        $before = count( events() );
        check( is_wp_error( fetch() ) && $before === count( events() ), 'native request-option method mutation cannot turn a scan into a write request' );
        $filters['requests-requests.before_request'] = array();
        $nested = false;
        $filters['requests-requests.before_request'][] = function () use ( &$nested ) {
            if ( $nested ) return;
            $nested = true;
            check( ! is_wp_error( fetch( 'https://bypass.example.test/ok' ) ), 'reentrant unrelated request keeps its own admission and transport' );
        };
        check( ! is_wp_error( fetch() ) && $nested, 'outer request remains pinned after an unrelated nested request' );
        $filters['requests-requests.before_request'] = array();
        $all = events();
        $origin = array_column( $all, 'origin' ); $proxy_events = array_column( $all, 'proxy' ); $sni = array_column( $all, 'sni' );
        check( in_array( 'scan.example.test', $sni, true ), 'actual TLS handshake sends logical SNI' );
        foreach ( $origin as $request ) {
            check( false !== strpos( $request, 'Host: scan.example.test' ) || false !== strpos( $request, 'Host: bypass.example.test' ) || false !== strpos( $request, 'Host: [2001:4860:4860::8888]' ), 'origin receives logical Host' );
            check( false === stripos( $request, 'Proxy-Authorization:' ), 'proxy credentials never enter the origin request' );
        }
        foreach ( $proxy_events as $request ) check( (bool) preg_match( '/\ACONNECT (?:1\.1\.1\.1|\[2001:4860:4860::8888\]):(?:80|443|8080) HTTP\//', $request ), 'proxy receives only admitted numeric CONNECT target' );
        foreach ( $sockets as $socket ) check( true === $socket[1]['ssl']['verify_peer'] && true === $socket[1]['ssl']['verify_peer_name'] && isset( $socket[1]['ssl']['peer_name'] ), 'each stream owns a verified logical peer context' );
        check( count( $proxy_events ) >= 4 && count( $pins ) > 5 && count( $sockets ) > 5, 'both real transports and proxy modes were exercised' );
        define( 'WP_HTTP_BLOCK_EXTERNAL', true );
        $blocked_observers = array();
        $filters['http_api_debug'][] = function ( $response, $context, $class, $args, $url ) use ( &$blocked_observers ) {
            $blocked_observers[] = array( $response, $context, $class, $url );
        };
        $before_block = count( events() );
        $blocked = fetch();
        check( is_wp_error( $blocked ) && 'dhc_scan_blocked' === $blocked->get_error_code() && count( events() ) === $before_block, 'WordPress outbound blocklist denies before origin or proxy traffic' );
        check( count( $blocked_observers ) === 1 && $blocked_observers[0][0] === $blocked && $blocked_observers[0][1] === 'response' && $blocked_observers[0][3] === 'https://scan.example.test/ok', 'blocked requests still emit the native HTTP debug boundary once with the logical URL' );
        check( $blocked_observers[0][2] === ( 'wp58' === $wp_variant ? 'Requests' : 'WpOrg\\Requests\\Requests' ), 'blocked observer also identifies the native library correctly' );
        echo 'PASS: ' . $checks . ' ' . $wp_variant . ' Requests ' . DHC\Scanner\NativeRequests::VERSION . " local TLS/proxy transport checks\n";
    } finally {
        @fwrite( $pipes[0], "stop\n" ); fclose( $pipes[0] ); fclose( $pipes[1] );
        $stderr = stream_get_contents( $pipes[2] ); fclose( $pipes[2] ); proc_close( $server );
        if ( '' !== $stderr ) fwrite( STDERR, $stderr );
        foreach ( array( '/key.pem', '/cert.pem', '/openssl.cnf' ) as $file ) @unlink( $temp . $file );
    }
}
