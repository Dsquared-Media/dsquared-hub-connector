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
