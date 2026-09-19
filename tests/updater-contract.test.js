'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');

const root = path.join(__dirname, '..');
const updater = fs.readFileSync(path.join(root, 'includes/class-dhc-updater.php'), 'utf8');

test('forced WordPress update checks invalidate the connector release cache', () => {
    assert.match(updater, /add_action\(\s*'load-update-core\.php'.*'clear_cache_on_forced_check'.*\)/);
    assert.match(updater, /current_user_can\(\s*'update_plugins'\s*\)/);
    assert.match(updater, /empty\(\s*\$_GET\['force-check'\]\s*\)/);
    assert.match(updater, /delete_transient\(\s*self::CACHE_KEY\s*\)/);
});

test('plugin-row update link requests a forced refresh', () => {
    assert.match(updater, /admin_url\(\s*'update-core\.php\?force-check=1'\s*\)/);
});

test('release discovery uses the canonical repository path', () => {
    assert.match(updater, /Dsquared-Media\/dsquared-hub-connector/);
    assert.doesNotMatch(updater, /repos\/dsquaredmedia\/dsquared-hub-connector/);
});

test('forced-check hook runs at priority 1 and only capable users clear the cache', () => {
    const updaterPath = path.join(root, 'includes/class-dhc-updater.php');
    const php = `
        define('ABSPATH', __DIR__);
        define('DHC_PLUGIN_BASENAME', 'dsquared-hub-connector/dsquared-hub-connector.php');
        $GLOBALS['hooks'] = array();
        $GLOBALS['can_update'] = false;
        $GLOBALS['deleted'] = array();
        function add_action($tag, $callback, $priority = 10, $args = 1) { $GLOBALS['hooks'][$tag][] = array($callback, $priority); }
        function add_filter($tag, $callback, $priority = 10, $args = 1) { $GLOBALS['hooks'][$tag][] = array($callback, $priority); }
        function current_user_can($capability) { return $capability === 'update_plugins' && $GLOBALS['can_update']; }
        function delete_transient($key) { $GLOBALS['deleted'][] = $key; }
        require ${JSON.stringify(updaterPath)};
        DHC_Updater::init();
        $hook = $GLOBALS['hooks']['load-update-core.php'][0];

        $_GET['force-check'] = '1';
        DHC_Updater::clear_cache_on_forced_check();
        $unauthorized = count($GLOBALS['deleted']);

        $GLOBALS['can_update'] = true;
        unset($_GET['force-check']);
        DHC_Updater::clear_cache_on_forced_check();
        $without_force = count($GLOBALS['deleted']);

        $_GET['force-check'] = '1';
        DHC_Updater::clear_cache_on_forced_check();

        echo json_encode(array(
            'priority' => $hook[1],
            'callback' => $hook[0][1],
            'unauthorized' => $unauthorized,
            'without_force' => $without_force,
            'deleted' => $GLOBALS['deleted'],
        ));
    `;

    const result = JSON.parse(execFileSync('php', ['-r', php], { encoding: 'utf8' }));
    assert.equal(result.priority, 1);
    assert.equal(result.callback, 'clear_cache_on_forced_check');
    assert.equal(result.unauthorized, 0);
    assert.equal(result.without_force, 0);
    assert.deepEqual(result.deleted, ['dhc_update_cache']);
});

test('legacy cleanup removes only verified inactive versioned copies and preserves shared options', () => {
    const updaterPath = path.join(root, 'includes/class-dhc-updater.php');
    const os = require('node:os');
    const tempRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'dhc-cleanup-'));
    const pluginRoot = path.join(tempRoot, 'plugins');
    fs.mkdirSync(pluginRoot, { recursive: true });

    const fixtures = {
        'dsquared-hub-connector-1.17.3': 'Dsquared Hub Connector',
        'dsquared-hub-connector-1.17.2': 'Dsquared Hub Connector',
        'dsquared-hub-connector-1.17.1': 'Different Plugin',
        'dsquared-hub-connector-1.17.0': 'Dsquared Hub Connector',
        'dsquared-hub-connector-backup': 'Dsquared Hub Connector',
    };
    for (const [folder, name] of Object.entries(fixtures)) {
        const dir = path.join(pluginRoot, folder);
        fs.mkdirSync(dir, { recursive: true });
        fs.writeFileSync(path.join(dir, 'dsquared-hub-connector.php'), `<?php\n/*\nPlugin Name: ${name}\n*/\n`);
    }

    const php = `
        define('ABSPATH', ${JSON.stringify(tempRoot + path.sep)});
        define('WP_PLUGIN_DIR', ${JSON.stringify(pluginRoot)});
        define('DHC_VERSION', '1.17.6');
        define('DHC_PLUGIN_BASENAME', 'dsquared-hub-connector/dsquared-hub-connector.php');
        $GLOBALS['options'] = array('dhc_api_key' => 'must-stay', 'dhc_modules' => array('schema' => true));
        $GLOBALS['active_plugins'] = array('dsquared-hub-connector-1.17.2/dsquared-hub-connector.php');
        $GLOBALS['network_plugins'] = array('dsquared-hub-connector-1.17.0/dsquared-hub-connector.php');
        $GLOBALS['hooks'] = array();
        function add_action($tag, $callback, $priority = 10, $args = 1) { $GLOBALS['hooks'][$tag][] = array($callback, $priority); }
        function add_filter($tag, $callback, $priority = 10, $args = 1) { $GLOBALS['hooks'][$tag][] = array($callback, $priority); }
        function current_user_can($capability) { return $capability === 'update_plugins'; }
        function get_option($key, $default = false) { return array_key_exists($key, $GLOBALS['options']) ? $GLOBALS['options'][$key] : $default; }
        function update_option($key, $value, $autoload = null) { $GLOBALS['options'][$key] = $value; return true; }
        function trailingslashit($value) { return rtrim($value, '/\\\\') . '/'; }
        function untrailingslashit($value) { return rtrim($value, '/\\\\'); }
        function get_file_data($file, $headers, $context = '') {
            $contents = file_get_contents($file);
            preg_match('/Plugin Name:\\s*(.+)/', $contents, $match);
            return array('name' => trim($match[1] ?? ''));
        }
        function is_plugin_active($plugin) { return in_array($plugin, $GLOBALS['active_plugins'], true); }
        function is_plugin_active_for_network($plugin) { return in_array($plugin, $GLOBALS['network_plugins'], true); }
        class Test_Filesystem {
            public function delete($path, $recursive = false, $type = false) {
                $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($iterator as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
                return rmdir($path);
            }
        }
        $GLOBALS['wp_filesystem'] = new Test_Filesystem();
        require ${JSON.stringify(updaterPath)};
        DHC_Updater::init();
        $result = DHC_Updater::cleanup_legacy_copies();
        echo json_encode(array(
            'result' => $result,
            'remaining' => array_values(array_map('basename', glob(WP_PLUGIN_DIR . '/*', GLOB_ONLYDIR))),
            'api_key' => $GLOBALS['options']['dhc_api_key'],
            'modules' => $GLOBALS['options']['dhc_modules'],
            'cleanup_version' => $GLOBALS['options']['dhc_legacy_cleanup_version'] ?? '',
            'admin_hook' => $GLOBALS['hooks']['admin_init'][0][0][1] ?? '',
        ));
    `;

    try {
        const result = JSON.parse(execFileSync('php', ['-r', php], { encoding: 'utf8' }));
        assert.deepEqual(result.result.removed, ['dsquared-hub-connector-1.17.3']);
        assert.ok(result.result.skipped.includes('dsquared-hub-connector-1.17.2'));
        assert.ok(result.result.skipped.includes('dsquared-hub-connector-1.17.0'));
        assert.ok(result.result.skipped.includes('dsquared-hub-connector-1.17.1'));
        assert.ok(result.result.skipped.includes('dsquared-hub-connector-backup'));
        assert.ok(!result.remaining.includes('dsquared-hub-connector-1.17.3'));
        assert.ok(result.remaining.includes('dsquared-hub-connector-1.17.2'));
        assert.ok(result.remaining.includes('dsquared-hub-connector-1.17.0'));
        assert.equal(result.api_key, 'must-stay');
        assert.deepEqual(result.modules, { schema: true });
        assert.equal(result.cleanup_version, '1.17.6');
        assert.equal(result.admin_hook, 'cleanup_legacy_copies');
    } finally {
        fs.rmSync(tempRoot, { recursive: true, force: true });
    }
});
