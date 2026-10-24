<?php
/**
 * Offline hook-preservation regression fixture; no WordPress database/network.
 *
 * Supply unmodified WordPress 6.8.3 wp-includes/plugin.php and class-wp-hook.php
 * with --wordpress-root=/disposable/path, and a disposable --workspace=/path.
 * Run again with --missing-dom to MODEL an unavailable provider dependency.
 * This mode does not claim the host PHP binary lacks DOM or run SVG Support.
 * The named provider fixture preserves its documented callback contract; it
 * rejects SVGs instead of implementing a substitute sanitizer.
 */
$config = array( 'root' => dirname( __DIR__ ), 'missing-dom' => false );
foreach ( array_slice( $argv, 1 ) as $argument ) {
    if ( '--missing-dom' === $argument ) {
        $config['missing-dom'] = true;
    } elseif ( preg_match( '/^--(root|wordpress-root|workspace)=(.+)$/', $argument, $match ) ) {
        $config[ $match[1] ] = $match[2];
    } else {
        throw new InvalidArgumentException( 'Unknown fixture argument.' );
    }
}
foreach ( array( 'wordpress-root', 'workspace' ) as $required ) {
    if ( empty( $config[ $required ] ) || ! is_dir( $config[ $required ] ) ) {
        throw new InvalidArgumentException( 'Supply a disposable --' . $required . ' directory.' );
    }
}
$checks = 0;
function verify_svg_hook( $condition, $label ) {
    $GLOBALS['checks']++;
    if ( ! $condition ) {
        fwrite( STDERR, 'FAIL: ' . $label . "\n" );
        exit( 1 );
    }
}
$wordpress_files = array(
    'plugin.php' => '87a819241f339b4702b8e986dd2bd9f817755bae61d4631a1db11fdc34149c31',
    'class-wp-hook.php' => '711375e96ed5b31d2435e079602168c4d15032e4b7bd96271c89eac039600766',
);
foreach ( $wordpress_files as $name => $digest ) {
    verify_svg_hook( hash_file( 'sha256', $config['wordpress-root'] . '/' . $name ) === $digest, 'unmodified WordPress 6.8.3 ' . $name );
}
require $config['wordpress-root'] . '/plugin.php';
define( 'ABSPATH', $config['workspace'] . '/' );
define( 'WP_PLUGIN_DIR', dirname( $config['root'] ) );
define( 'WPMU_PLUGIN_DIR', $config['workspace'] . '/mu-plugins' );
$wp_plugin_paths = array();
function trailingslashit( $path ) { return rtrim( $path, '/' ) . '/'; }
function wp_normalize_path( $path ) { return str_replace( '\\', '/', $path ); }
function plugins_url( ...$unused ) { return 'https://fixture.invalid/connector'; }
function __( $message, ...$unused ) { return $message; }
function get_option( $name, $default = false ) { return $GLOBALS['fixture_options'][ $name ] ?? $default; }
function get_transient( $name ) { return $GLOBALS['fixture_transients'][ $name ] ?? false; }
function delete_transient( $name ) { unset( $GLOBALS['fixture_transients'][ $name ] ); }
function wp_remote_get( ...$unused ) { throw new RuntimeException( 'Unexpected HTTP in offline fixture.' ); }
$fixture_options = array();
$fixture_transients = array();
$provider_calls = array();
$sentinel_calls = array();
/** Models the exact callback name, array contract, and non-SVG early return. */
function bodhi_svgs_sanitize_svg( $file ) {
    $GLOBALS['provider_calls'][] = array( current_filter(), $file['name'] ?? '' );
    $extension = strtolower( pathinfo( $file['name'] ?? '', PATHINFO_EXTENSION ) );
    if ( ! empty( $file['name'] ) && ! in_array( $extension, array( 'svg', 'svgz' ), true ) ) {
        return $file;
    }
    if ( $GLOBALS['config']['missing-dom'] ) {
        // A real provider may throw while loading/sanitizing without DOM.
        // Catching it below records failure to accept; D2 must not bypass it.
        throw new Error( 'Fixture models provider DOMDocument dependency failure.' );
    }
    $file['error'] = 'Fixture provider rejected SVG; no sanitizer replacement.';
    return $file;
}
function bodhi_svgs_allow_svg_upload( $checked, $file, $filename, $mimes ) {
    $GLOBALS['sentinel_calls'][] = 'known-type:' . $filename;
    return $checked;
}
function unrelated_svg_security( $file ) {
    $GLOBALS['sentinel_calls'][] = 'named:' . current_filter();
    return $file;
}
class Svg_Hook_Sentinel {
    public function verify_svg( $file ) {
        $GLOBALS['sentinel_calls'][] = 'object:' . current_filter();
        return $file;
    }
}
$sentinel = new Svg_Hook_Sentinel();
$protected_tags = array( 'wp_handle_upload_prefilter', 'wp_handle_sideload_prefilter', 'wp_handle_upload', 'wp_check_filetype_and_ext' );
foreach ( array_slice( $protected_tags, 0, 2 ) as $tag ) {
    add_filter( $tag, function( $file ) { $GLOBALS['sentinel_calls'][] = 'early:' . current_filter(); return $file; }, -5 );
    add_filter( $tag, 'bodhi_svgs_sanitize_svg', 10 );
    add_filter( $tag, 'unrelated_svg_security', 15 );
    add_filter( $tag, array( $sentinel, 'verify_svg' ), 20 );
    add_filter( $tag, function( $file ) { $GLOBALS['sentinel_calls'][] = 'late:' . current_filter(); return $file; }, 30 );
}
add_filter( 'wp_handle_upload', 'unrelated_svg_security', 10 );
add_filter( 'wp_handle_upload', array( $sentinel, 'verify_svg' ), 20 );
add_filter( 'wp_check_filetype_and_ext', 'bodhi_svgs_allow_svg_upload', 10, 4 );
add_filter( 'wp_check_filetype_and_ext', function( $checked, $file, $filename, $mimes ) {
    $GLOBALS['sentinel_calls'][] = 'other-type:' . $filename;
    return $checked;
}, 20, 4 );
function svg_hook_registry() {
    $snapshot = array();
    foreach ( $GLOBALS['protected_tags'] as $tag ) {
        $snapshot[ $tag ] = $GLOBALS['wp_filter'][ $tag ]->callbacks ?? array();
    }
    return $snapshot;
}
$registry = svg_hook_registry();
// Run the real bootstrap prefix through its first include. A namespaced
// class_exists supplies the explicitly modeled DOM-present/missing condition.
// Other functions resolve to the actual global WordPress hook implementation.
$bootstrap = file_get_contents( $config['root'] . '/dsquared-hub-connector.php' );
$prefix = '';
foreach ( token_get_all( $bootstrap ) as $token ) {
    if ( is_array( $token ) && T_REQUIRE_ONCE === $token[0] ) {
        break;
    }
    if ( ! is_array( $token ) || T_OPEN_TAG !== $token[0] ) {
        $prefix .= is_array( $token ) ? ( T_FILE === $token[0] ? var_export( $config['root'] . '/dsquared-hub-connector.php', true ) : $token[1] ) : $token;
    }
}
eval( 'namespace DHC_Svg_Hook_Fixture; function class_exists($name, $autoload = true) { return $name === "DOMDocument" ? !$GLOBALS["config"]["missing-dom"] : \\class_exists($name, $autoload); } ' . $prefix );
do_action( 'plugins_loaded' );
verify_svg_hook( svg_hook_registry() === $registry, 'bootstrap preserves every security callback and accepted argument count' );
require $config['root'] . '/includes/class-dhc-updater.php';
DHC_Updater::init();
verify_svg_hook( svg_hook_registry() === $registry, 'updater initialization preserves upload controls' );

// Real local file controls, without uploading or installing anything.
$zip = $config['workspace'] . '/plugin.zip';
$archive = new ZipArchive();
verify_svg_hook( true === $archive->open( $zip, ZipArchive::CREATE | ZipArchive::OVERWRITE ), 'create local archive control' );
$archive->addFromString( 'fixture/fixture.php', "<?php\n/* Plugin Name: Local fixture */\n" );
$archive->close();
verify_svg_hook( true === $archive->open( $zip ) && 1 === $archive->numFiles, 'local ZIP is a readable archive' );
$archive->close();
$png = $config['workspace'] . '/pixel.png';
file_put_contents( $png, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=' ) );
verify_svg_hook( 'image/png' === getimagesize( $png )['mime'], 'local bitmap is a readable PNG' );
$dirty = $config['workspace'] . '/dirty.svg';
file_put_contents( $dirty, '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(1)</script></svg>' );
$svgz = $config['workspace'] . '/dirty.svgz';
file_put_contents( $svgz, gzencode( file_get_contents( $dirty ) ) );
function run_svg_file( $tag, $file, $is_svg ) {
    $calls_before = count( $GLOBALS['provider_calls'] );
    $sentinels_before = count( $GLOBALS['sentinel_calls'] );
    try {
        $result = apply_filters( $tag, $file );
        verify_svg_hook( ! $is_svg || ! empty( $result['error'] ), $tag . ' never accepts unchecked SVG' );
        verify_svg_hook( $is_svg || $result === $file, $tag . ' ordinary file unchanged' );
        verify_svg_hook( count( $GLOBALS['sentinel_calls'] ) === $sentinels_before + 4, $tag . ' all unrelated prefilters execute in order' );
        verify_svg_hook( array_slice( $GLOBALS['sentinel_calls'], -4 ) === array( 'early:' . $tag, 'named:' . $tag, 'object:' . $tag, 'late:' . $tag ), $tag . ' sentinel ordering preserved' );
    } catch ( Error $error ) {
        verify_svg_hook( $GLOBALS['config']['missing-dom'] && $is_svg && $error->getMessage() === 'Fixture models provider DOMDocument dependency failure.', 'only modeled missing-DOM provider failure is expected' );
    }
    verify_svg_hook( count( $GLOBALS['provider_calls'] ) === $calls_before + 1, $tag . ' original named sanitizer executes' );
    verify_svg_hook( svg_hook_registry() === $GLOBALS['registry'], $tag . ' hook registry unchanged across sequential files' );
}
foreach ( array_slice( $protected_tags, 0, 2 ) as $tag ) {
    foreach ( array( 'application/zip', 'application/x-zip-compressed', 'application/octet-stream' ) as $mime ) {
        run_svg_file( $tag, array( 'name' => 'plugin.zip', 'tmp_name' => $zip, 'type' => $mime ), false );
        run_svg_file( $tag, array( 'name' => 'dirty.svg', 'tmp_name' => $dirty, 'type' => $mime ), true );
    }
    run_svg_file( $tag, array( 'name' => 'theme.zip', 'tmp_name' => $zip, 'type' => 'application/zip' ), false );
    run_svg_file( $tag, array( 'name' => 'pixel.png', 'tmp_name' => $png, 'type' => 'image/png' ), false );
    run_svg_file( $tag, array( 'name' => 'DIRTY.SVG', 'tmp_name' => $dirty, 'type' => 'image/svg+xml' ), true );
    run_svg_file( $tag, array( 'name' => 'dirty.svgz', 'tmp_name' => $svgz, 'type' => 'image/svg+xml' ), true );
    run_svg_file( $tag, array( 'name' => 'dirty.svgz', 'tmp_name' => $dirty, 'type' => 'text/plain' ), true );
    run_svg_file( $tag, array( 'name' => 'dirty.svg', 'tmp_name' => $dirty ), true );
    run_svg_file( $tag, array( 'name' => 'dirty.svg', 'tmp_name' => $dirty, 'error' => 'already rejected' ), true );
}
foreach ( array( 'plugin', 'theme', 'core' ) as $type ) {
    $extra = array( 'type' => $type, 'action' => 'update', 'bulk' => true );
    verify_svg_hook( true === apply_filters( 'upgrader_pre_install', true, $extra ), $type . ' pre-install response unchanged' );
    verify_svg_hook( svg_hook_registry() === $registry, $type . ' pre-install controls unchanged' );
    $failed = new stdClass();
    verify_svg_hook( $failed === apply_filters( 'upgrader_pre_install', $failed, $extra ), $type . ' failed pre-install response unchanged' );
    verify_svg_hook( true === apply_filters( 'upgrader_post_install', true, $extra, array() ), $type . ' post-install response unchanged' );
    verify_svg_hook( svg_hook_registry() === $registry, $type . ' post-install controls unchanged' );
}
do_action( 'shutdown' );
verify_svg_hook( svg_hook_registry() === $registry, 'shutdown leaves security controls intact' );
$checked = array( 'ext' => 'png', 'type' => 'image/png' );
verify_svg_hook( $checked === apply_filters( 'wp_check_filetype_and_ext', $checked, $png, 'pixel.png', array( 'png' => 'image/png' ) ), 'type checks and four-argument callbacks preserved' );
$post_upload = array( 'file' => $png, 'type' => 'image/png', 'url' => 'https://fixture.invalid/pixel.png' );
verify_svg_hook( $post_upload === apply_filters( 'wp_handle_upload', $post_upload ), 'post-upload controls preserved' );
$transient = (object) array( 'checked' => array( DHC_PLUGIN_BASENAME => DHC_VERSION ) );
$fixture_transients[ DHC_Updater::CACHE_KEY ] = array( 'version' => '9.0.0', 'download_url' => 'https://fixture.invalid/plugin.zip' );
$updated = apply_filters( 'pre_set_site_transient_update_plugins', $transient );
verify_svg_hook( $updated->response[ DHC_PLUGIN_BASENAME ]->package === 'https://fixture.invalid/plugin.zip', 'cached update discovery and ZIP package preserved' );
$item = (object) array( 'plugin' => DHC_PLUGIN_BASENAME );
verify_svg_hook( true === apply_filters( 'auto_update_plugin', false, $item ), 'connector auto-update opt-in preserved' );
$fixture_options['dhc_disable_auto_update'] = true;
verify_svg_hook( false === apply_filters( 'auto_update_plugin', true, $item ), 'site owner auto-update opt-out preserved' );
verify_svg_hook( false === apply_filters( 'auto_update_plugin', false, (object) array( 'plugin' => 'other/other.php' ) ), 'unrelated auto-update preference preserved' );
do_action( 'upgrader_process_complete', null, array( 'plugins' => array( 'other/other.php' ) ) );
verify_svg_hook( isset( $fixture_transients[ DHC_Updater::CACHE_KEY ] ), 'unrelated update keeps D2 cache' );
do_action( 'upgrader_process_complete', null, array( 'plugins' => array( DHC_PLUGIN_BASENAME ) ) );
verify_svg_hook( ! isset( $fixture_transients[ DHC_Updater::CACHE_KEY ] ), 'D2 update clears cache' );
// Public connector package-path normalization is independent of upload hooks.
function untrailingslashit( $path ) { return rtrim( $path, '/' ); }
class Svg_Fixture_Filesystem {
    public $files = array();
    public $moves = array();
    public $move_result = true;
    public function exists( $path ) { return in_array( $path, $this->files, true ); }
    public function move( $from, $to, $overwrite ) {
        $this->moves[] = array( $from, $to, $overwrite );
        return $this->move_result;
    }
}
$wp_filesystem = new Svg_Fixture_Filesystem();
$remote = $config['workspace'] . '/unpacked/';
$canonical = $remote . 'dsquared-hub-connector/';
$own = array( 'plugin' => DHC_PLUGIN_BASENAME );
verify_svg_hook( $canonical === apply_filters( 'upgrader_source_selection', $canonical, $remote, null, $own ), 'canonical package folder stays unchanged' );
verify_svg_hook( empty( $wp_filesystem->moves ), 'canonical folder needs no move' );
$odd = $remote . 'Dsquared-Media-sha/';
verify_svg_hook( $canonical === apply_filters( 'upgrader_source_selection', $odd, $remote, null, $own ), 'explicit own package is normalized' );
verify_svg_hook( $wp_filesystem->moves === array( array( rtrim( $odd, '/' ), rtrim( $canonical, '/' ), true ) ), 'own package move retains overwrite contract' );
$wp_filesystem->moves = array();
$wp_filesystem->files = array( $odd . 'dsquared-hub-connector.php' );
verify_svg_hook( $canonical === apply_filters( 'upgrader_source_selection', $odd, $remote, null, array() ), 'manual archive identified by main file is normalized' );
$wp_filesystem->files = array();
$wp_filesystem->moves = array();
verify_svg_hook( $odd === apply_filters( 'upgrader_source_selection', $odd, $remote, null, array( 'plugin' => 'other/other.php' ) ), 'unrelated package remains untouched' );
verify_svg_hook( empty( $wp_filesystem->moves ), 'unrelated package causes no move' );
$wp_filesystem->move_result = false;
verify_svg_hook( $odd === apply_filters( 'upgrader_source_selection', $odd, $remote, null, $own ), 'failed move retains original source' );
$wp_filesystem = null;
verify_svg_hook( $odd === apply_filters( 'upgrader_source_selection', $odd, $remote, null, $own ), 'missing filesystem retains explicit source' );
verify_svg_hook( $odd === apply_filters( 'upgrader_source_selection', $odd, $remote, null, array() ), 'missing filesystem preserves unrecognized source' );
verify_svg_hook( svg_hook_registry() === $registry, 'all package-path controls preserve upload security hooks' );
echo 'PASS: ' . $checks . ' checks; WordPress 6.8.3 hooks; provider DOM ' . ( $config['missing-dom'] ? 'missing MODEL' : 'available MODEL' ) . "; actual installed-provider/runtime acceptance unverified.\n";
