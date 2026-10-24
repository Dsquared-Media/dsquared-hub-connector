<?php
/** Scanner-only HTTP connection boundary. WordPress 5.8+ / native Requests 1.x and 2.x. */
namespace DHC\Scanner;

if ( ! defined( 'ABSPATH' ) ) exit;

// Select the library used by WordPress's own hook bridge, not an unrelated
// Requests copy registered by another plugin. Aliases remain plugin-local.
if ( ! class_exists( 'WP_Http' ) || ! class_exists( 'WP_HTTP_Requests_Hooks' ) ) return;
$modern = is_subclass_of( 'WP_HTTP_Requests_Hooks', 'WpOrg\\Requests\\Hooks' );
$legacy = is_subclass_of( 'WP_HTTP_Requests_Hooks', 'Requests_Hooks' );
if ( ! $modern && ! $legacy ) return;
$native = $modern ? array(
    'Requests' => 'WpOrg\\Requests\\Requests', 'Hooks' => 'WpOrg\\Requests\\Hooks',
    'Proxy' => 'WpOrg\\Requests\\Proxy', 'Transport' => 'WpOrg\\Requests\\Transport',
    'Curl' => 'WpOrg\\Requests\\Transport\\Curl', 'Fsockopen' => 'WpOrg\\Requests\\Transport\\Fsockopen',
    'Exception' => 'WpOrg\\Requests\\Exception', 'HttpProxy' => 'WpOrg\\Requests\\Proxy\\Http',
    'Iri' => 'WpOrg\\Requests\\Iri',
) : array(
    'Requests' => 'Requests', 'Hooks' => 'Requests_Hooks', 'Proxy' => 'Requests_Proxy',
    'Transport' => 'Requests_Transport', 'Curl' => 'Requests_Transport_cURL',
    'Fsockopen' => 'Requests_Transport_fsockopen', 'Exception' => 'Requests_Exception',
    'HttpProxy' => 'Requests_Proxy_HTTP', 'Iri' => 'Requests_IRI',
);
foreach ( $native as $class ) {
    if ( ! class_exists( $class ) && ! interface_exists( $class ) ) return;
}
foreach ( $native as $name => $class ) {
    class_alias( $class, __NAMESPACE__ . '\\Native' . $name );
}
unset( $modern, $legacy, $native, $name, $class );


/** Expose Requests' normal capability-based selection without changing it. */
class Requests extends NativeRequests {
    public static function scanner_transport( $https ) {
        return parent::get_transport( array( 'ssl' => $https ) );
    }
}

/** Request-owned hooks: run native callbacks before enforcing the connection. */
final class Hooks extends NativeHooks {
    private $delegate;
    private $enforce;
    public function __construct( $delegate, $enforce ) { $this->delegate = $delegate; $this->enforce = $enforce; }
    public function register( $hook, $callback, $priority = 0 ) { $this->delegate->register( $hook, $callback, $priority ); }
    public function dispatch( $hook, $parameters = array() ) {
        $result = $this->delegate->dispatch( $hook, $parameters );
        call_user_func( $this->enforce, $hook, $parameters );
        return $result;
    }
}

/** Keep native cURL proxy authentication; streams uses a numeric CONNECT. */
final class Proxy implements NativeProxy {
    public $native;
    public function __construct( $native ) { $this->native = $native; }
    public function register( NativeHooks $hooks ) {
        $hooks->register( 'curl.before_send', array( $this->native, 'curl_before_send' ) );
    }
}

final class Http {
    public static function request( $admission, $method, $args ) {
        try {
            // Use the original hostname for both the WP blocklist and proxy bypass.
            $args = apply_filters( 'http_request_args', $args + array(
                'method' => $method, 'headers' => array(), 'body' => null,
                'cookies' => array(), 'blocking' => true, 'stream' => false,
                'filename' => null, 'sslcertificates' => ABSPATH . WPINC . '/certificates/ca-bundle.crt',
            ), $admission['url'] );
            if ( $args['method'] !== $method || ! $args['blocking'] || $args['stream']
                || ( 'https' === $admission['scheme'] && ! $args['sslverify'] ) || ! empty( $args['body'] ) || ! empty( $args['cookies'] ) ) {
                return new \WP_Error( 'dhc_scan_options', 'Scanner requires a verified, status-only HEAD or GET request.' );
            }
            $pre = apply_filters( 'pre_http_request', false, $args, $admission['url'] );
            if ( false !== $pre ) return $pre;
            // Match core's accepted header forms before passing to Requests.
            if ( null === $args['headers'] ) $args['headers'] = array();
            if ( ! is_array( $args['headers'] ) ) {
                $processed = \WP_Http::processHeaders( $args['headers'] );
                $args['headers'] = $processed['headers'];
            }
            if ( ( new \WP_Http() )->block_request( $admission['url'] ) ) {
                $blocked = new \WP_Error( 'dhc_scan_blocked', 'WordPress blocks this outbound request.' );
                do_action( 'http_api_debug', $blocked, 'response', get_parent_class( Requests::class ), $args, $admission['url'] );
                return $blocked;
            }
            $verify = 'https' === $admission['scheme']
                ? apply_filters( 'https_ssl_verify', $args['sslcertificates'], $admission['url'] ) : true;
            if ( true !== $verify && ( ! is_string( $verify ) || '' === $verify ) ) {
                return new \WP_Error( 'dhc_scan_tls', 'Scanner requires certificate verification.' );
            }
            $proxy = false;
            $wp_proxy = new \WP_HTTP_Proxy();
            if ( $wp_proxy->is_enabled() && $wp_proxy->send_through_proxy( $admission['url'] ) ) {
                $native = new NativeHttpProxy( $wp_proxy->host() . ':' . $wp_proxy->port() );
                if ( $wp_proxy->use_authentication() ) {
                    $native->use_authentication = true;
                    $native->user = $wp_proxy->username();
                    $native->pass = $wp_proxy->password();
                }
                $proxy = new Proxy( $native );
            }
            $selected = Requests::scanner_transport( 'https' === $admission['scheme'] );
            // Older streams cannot verify a 16-byte IP SAN. DNS names using
            // IPv6 addresses still verify their DNS identity normally.
            if ( PHP_VERSION_ID < 80300 && 'https' === $admission['scheme']
                && $selected instanceof NativeFsockopen
                && filter_var( trim( $admission['host'], '[]' ), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
                return new \WP_Error( 'dhc_scan_ipv6_tls_unsupported', 'This PHP streams transport cannot verify HTTPS IPv6 address identities. The link was not requested.' );
            }
            $transport = new Transport( $admission, $selected, $proxy, $method );
            $options = array(
                'timeout' => min( 10, max( 0.001, (float) $args['timeout'] ) ),
                'useragent' => $args['user-agent'], 'blocking' => true,
                'follow_redirects' => false, 'verify' => $verify, 'verifyname' => true,
                'proxy' => $proxy, 'max_bytes' => 1024, 'transport' => $transport,
            );
            $delegate = new \WP_HTTP_Requests_Hooks( $admission['url'], $args );
            $options['hooks'] = new Hooks( $delegate, array( $transport, 'enforce' ) );
            $response = Requests::request( $admission['url'], $args['headers'], array(), $method, $options );
            $result = array( 'response' => array( 'code' => $response->status_code ), 'headers' => $response->headers, 'body' => '' );
        } catch ( \Throwable $error ) {
            // Fixed, non-secret messages; never persist proxy credentials or raw socket errors.
            $result = new \WP_Error( 'dhc_scan_transport', 'The selected transport or proxy could not complete a verified connection to the approved address.' );
        }
        // Keep the usual observer/filter boundary without passing socket errors
        // or a downloaded response body to plugins that observe HTTP requests.
        do_action( 'http_api_debug', $result, 'response', get_parent_class( Requests::class ), $args, $admission['url'] );
        return is_wp_error( $result ) ? $result : apply_filters( 'http_response', $result, $args, $admission['url'] );
    }
}

/** A numeric origin is selected once and carried to every connection attempt. */
final class Transport implements NativeTransport {
    private $admission;
    private $selected;
    private $proxy;
    private $address;
    private $deadline;
    private $method;

    public function __construct( $admission, $selected, $proxy, $method ) {
        $this->admission = $admission;
        $this->selected = $selected;
        $this->proxy = $proxy;
        $this->method = $method;
    }

    public function enforce( $hook, $parameters ) {
        if ( 'requests.before_request' === $hook ) {
            list( $url, $headers, $data, $type, $options ) = $parameters;
            if ( $url !== $this->admission['url'] || ! in_array( $type, array( 'HEAD', 'GET' ), true )
                || $type !== $this->method || $options['type'] !== $this->method
                || ! empty( $data ) || false === $options['verify'] || false === $options['verifyname']
                || $options['proxy'] !== $this->proxy ) self::fail();
            foreach ( $headers as $name => $value ) {
                if ( 0 === strcasecmp( $name, 'Host' ) && $value !== $this->authority() ) self::fail();
            }
            $parameters[4]['transport'] = $this;
            $parameters[4]['hooks'] = $parameters[4]['hooks'] instanceof Hooks ? $parameters[4]['hooks'] : null;
            if ( null === $parameters[4]['hooks'] ) self::fail();
            $parameters[4]['follow_redirects'] = false;
            $parameters[4]['blocking'] = true;
            $parameters[4]['filename'] = false;
            $parameters[4]['max_bytes'] = 1024;
        }
        if ( 'curl.before_send' === $hook ) {
            if ( ! defined( 'CURLOPT_CONNECT_TO' ) || curl_version()['version_number'] < 0x073100 ) self::fail();
            $handle = $parameters[0];
            $source = $this->admission['host'];
            $target = self::endpoint_host( $this->address );
            $port = $this->admission['port'];
            // PHP exposes XFERINFOFUNCTION from 8.2; older runtimes use the
            // equivalent progress callback with the same abort deadline.
            $progress_option = defined( 'CURLOPT_XFERINFOFUNCTION' ) ? constant( 'CURLOPT_XFERINFOFUNCTION' ) : CURLOPT_PROGRESSFUNCTION;
            $settings = array(
                CURLOPT_URL => $this->admission['url'],
                CURLOPT_CONNECT_TO => array( $source . ':' . $port . ':' . $target . ':' . $port ),
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_FRESH_CONNECT => true, CURLOPT_FORBID_REUSE => true,
                CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_SSL_VERIFYPEER => true,
                // A proxy must route the numeric origin, including for plain HTTP.
                CURLOPT_HTTPPROXYTUNNEL => true,
                CURLOPT_PROXY => $this->proxy ? $this->proxy->native->proxy : '',
                CURLOPT_PROXYTYPE => CURLPROXY_HTTP, CURLOPT_NOPROXY => '',
                CURLOPT_NOPROGRESS => false,
                $progress_option => function () { return microtime( true ) >= $this->deadline ? 1 : 0; },
            );
            foreach ( $settings as $option => $value ) if ( ! curl_setopt( $handle, $option, $value ) ) self::fail();
        }
    }

    public function request( $url, $headers = array(), $data = array(), $options = array() ) {
        if ( $url !== $this->admission['url'] || ! empty( $data ) ) self::fail();
        if ( ! $this->selected instanceof NativeCurl
            && ! $this->selected instanceof NativeFsockopen ) self::fail();
        $this->deadline = microtime( true ) + min( 10, (float) $options['timeout'] );
        $last = null;
        // Validate all DNS answers at admission; try at most eight within one budget.
        foreach ( array_slice( $this->admission['addresses'], 0, 8 ) as $address ) {
            $this->address = $address;
            $remaining = $this->remaining();
            $options['timeout'] = $remaining;
            $options['connect_timeout'] = $remaining;
            try {
                if ( $this->selected instanceof NativeCurl ) {
                    // Fresh native handles prevent an earlier connection or failed pin from being reused.
                    return ( new NativeCurl() )->request( $url, $headers, $data, $options );
                }
                return $this->stream_request( $headers, $options );
            } catch ( NativeException $error ) { $last = $error; }
        }
        if ( $last ) throw $last;
        self::fail();
    }

    private function stream_request( $headers, $options ) {
        if ( ! function_exists( 'stream_socket_client' ) ) self::fail();
        $https = 'https' === $this->admission['scheme'];
        $ssl = array( 'verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false,
            'peer_name' => trim( $this->admission['host'], '[]' ), 'SNI_enabled' => true );
        if ( is_string( $options['verify'] ) ) $ssl['cafile'] = $options['verify'];
        $context = stream_context_create( array( 'ssl' => $ssl ) );
        $origin = self::endpoint_host( $this->address ) . ':' . $this->admission['port'];
        $remote = 'tcp://' . $origin;
        if ( $this->proxy ) {
            if ( ! $this->proxy instanceof Proxy ) self::fail();
            $remote = 'tcp://' . $this->proxy->native->proxy;
        }
        $options['hooks']->dispatch( 'fsockopen.before_request' );
        $candidate = $remote;
        $options['hooks']->dispatch( 'fsockopen.remote_socket', array( &$candidate ) );
        // An extension may observe the selected endpoint, but cannot replace it.
        if ( $candidate !== $remote ) self::fail();
        $socket = @stream_socket_client( $remote, $errno, $errstr, $this->remaining(), STREAM_CLIENT_CONNECT, $context );
        if ( false === $socket ) self::fail();
        try {
            if ( $this->proxy ) {
                $connect = 'CONNECT ' . $origin . " HTTP/1.1\r\nHost: " . $origin . "\r\n";
                if ( $this->proxy->native->use_authentication ) {
                    $connect .= 'Proxy-Authorization: Basic ' . base64_encode( $this->proxy->native->get_auth_string() ) . "\r\n";
                }
                $this->write( $socket, $connect . "\r\n" );
                $reply = $this->read_headers( $socket );
                if ( ! preg_match( '~\AHTTP/1\.[01] 2[0-9]{2}(?:[ \r])~', $reply ) ) self::fail();
            }
            if ( $https ) {
                $this->set_timeout( $socket );
                if ( true !== @stream_socket_enable_crypto( $socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT ) ) self::fail();
            }
            $parts = parse_url( $this->admission['url'] );
            $path = ( $parts['path'] ?? '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
            $options['hooks']->dispatch( 'fsockopen.remote_host_path', array( &$path, $this->admission['url'] ) );
            if ( preg_match( '/[\x00-\x20\x7f]/', $path ) ) self::fail();
            $out = $options['type'] . ' ' . $path . " HTTP/1.1\r\n";
            $headers = array_merge( array( 'Host' => $this->authority(), 'User-Agent' => $options['useragent'], 'Connection' => 'close' ), $headers );
            foreach ( $headers as $name => $value ) {
                if ( ! preg_match( '/\A[!#$%&\x27*+.^_`|~0-9a-z-]+\z/i', $name ) || ! is_scalar( $value )
                    || preg_match( '/[\r\n\x00]/', (string) $value ) || 0 === strcasecmp( $name, 'Proxy-Authorization' ) ) self::fail();
                $out .= $name . ': ' . $value . "\r\n";
            }
            $options['hooks']->dispatch( 'fsockopen.after_headers', array( &$out ) );
            $out .= "\r\n";
            $options['hooks']->dispatch( 'fsockopen.before_send', array( &$out ) );
            $this->write( $socket, $out );
            $options['hooks']->dispatch( 'fsockopen.after_send', array( $out ) );
            // The scanner consumes status/Location only; never download a response body.
            $response = $this->read_headers( $socket );
            $info = stream_get_meta_data( $socket );
            $options['hooks']->dispatch( 'fsockopen.after_request', array( &$response, &$info ) );
            return $response;
        } finally { fclose( $socket ); }
    }

    private function authority() {
        $default = 'https' === $this->admission['scheme'] ? 443 : 80;
        return $this->admission['host'] . ( $this->admission['port'] === $default ? '' : ':' . $this->admission['port'] );
    }
    private static function endpoint_host( $address ) { return false === strpos( $address, ':' ) ? $address : '[' . $address . ']'; }
    private function remaining() {
        $remaining = $this->deadline - microtime( true );
        if ( $remaining <= 0 ) self::fail();
        return $remaining;
    }
    private function set_timeout( $socket ) {
        $seconds = $this->remaining();
        stream_set_timeout( $socket, (int) $seconds, (int) ( ( $seconds - (int) $seconds ) * 1000000 ) );
    }
    private function write( $socket, $data ) {
        while ( '' !== $data ) {
            $this->set_timeout( $socket );
            $sent = @fwrite( $socket, $data );
            if ( ! $sent ) self::fail();
            $data = substr( $data, $sent );
        }
    }
    private function read_headers( $socket ) {
        for ( $interim = 0; $interim < 6; $interim++ ) {
            $headers = '';
            do {
                $this->set_timeout( $socket );
                $line = @fgets( $socket, 8193 );
                if ( false === $line || strlen( $headers ) + strlen( $line ) > 65536 ) self::fail();
                $headers .= $line;
            } while ( "\r\n" !== $line );
            if ( ! preg_match( '~\AHTTP/1\.[01] 1[0-9]{2}(?:[ \r])~', $headers ) ) return $headers;
        }
        self::fail();
    }
    private static function fail() { throw new NativeException( 'Scanner connection policy could not be enforced.', 'dhc_scanner_connection' ); }
    public function request_multiple( $requests, $options ) { self::fail(); }
    public static function test( $capabilities = array() ) { return false; }
}
