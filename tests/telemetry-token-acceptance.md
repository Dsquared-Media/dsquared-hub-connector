# Telemetry Token Acceptance Tests

Manual verification steps for the two-token credential model introduced in v1.17.4.

- `dhc_api_key` — private, server-only; authenticates privileged Hub/WP REST write routes
- `dhc_telemetry_token` — public-safe; authenticates only heartbeat/event/cwv-report

---

## 1. Verify dhc_telemetry_token is provisioned after fresh activation

1. On a clean WordPress install, activate the Dsquared Hub Connector plugin.
2. Navigate to **D2 Hub → Connection** and enter a valid API key, then click **Save & Validate**.
3. Wait 10 seconds for the deferred cron to fire (or trigger WP-Cron manually: `wp cron event run dhc_provision_telemetry_token`).
4. Run: `wp option get dhc_telemetry_token`
5. **Expected:** A non-empty token string is returned.
6. Also confirm: `wp option get dhc_api_key` returns a different value (the two keys must differ).

---

## 2. Verify dhc_api_key does NOT appear in public page source HTML

1. Ensure the plugin is active, the API key is set, and the Event Tracker module is enabled with **Send event counts to Hub** turned on.
2. Open a public-facing page of the WordPress site in a browser.
3. View the page source (Ctrl+U / Cmd+U).
4. Search (`Ctrl+F`) for the value of `dhc_api_key` (copy it from **D2 Hub → Connection**).
5. **Expected:** Zero matches. The API key must not appear anywhere in the page HTML.
6. Also search for `dhc_api_key` (the option name as a string literal). **Expected:** Zero matches in the rendered page source.
7. Repeat for the CWV script: view source of any page with the Site Health module enabled and confirm the private API key is absent.

---

## 3. Verify CWV beacon uses header, not URL parameter

1. Open Chrome DevTools and go to the **Network** tab.
2. Filter by `cwv-report`.
3. Load or refresh a public page on the site.
4. Wait up to 30 seconds (or trigger a `visibilitychange` event by switching tabs).
5. Click the `cwv-report` request in the Network tab.
6. Select the **Headers** sub-tab.
7. **Expected — Request Headers must contain:**
   - `X-DHC-API-Key: <token value>`
   - `X-DHC-Site-Url: <site URL>`
   - `Content-Type: application/json`
8. **Expected — Request URL must NOT contain `?key=` or `?k=`.**
9. Confirm the same for event beacon requests: filter by `/plugin/events` and verify no `?k=` in the URL.

---

## 4. Verify dhc_telemetry_token is re-provisioned after key rotation in settings

1. Note the current value of `dhc_telemetry_token`: `wp option get dhc_telemetry_token`
2. In **D2 Hub → Connection**, change the API key to a new valid key and click **Save & Validate**.
3. Immediately check: `wp option get dhc_telemetry_token`
4. **Expected:** The option is empty (deleted on key change).
5. Wait 10 seconds (or run `wp cron event run dhc_provision_telemetry_token`).
6. Run: `wp option get dhc_telemetry_token`
7. **Expected:** A new, non-empty token that is different from the original value.
8. Repeat step 2 using the **Save & Validate** path (same expectation: old token cleared, new token provisioned).

---

## 5. Verify existing 1.17.3 installs auto-upgrade on first page load

1. On a site that was running v1.17.3, confirm `dhc_telemetry_token` does not exist:
   `wp option get dhc_telemetry_token` — expected: empty or "Option 'dhc_telemetry_token' not found."
2. Update the plugin to v1.17.4 (upload zip or auto-update).
3. Load any WordPress admin page (the version-change block in `dhc_init()` fires on `plugins_loaded`).
4. The `dhc_init()` function detects `dhc_installed_version !== '1.17.4'`, updates the stored version, and schedules `dhc_provision_telemetry_token` to run in 5 seconds.
5. Trigger WP-Cron: `wp cron event run dhc_provision_telemetry_token`
6. Run: `wp option get dhc_telemetry_token`
7. **Expected:** A non-empty token string.
8. Reload the admin page and confirm `wp option get dhc_installed_version` returns `1.17.4`.

---

## Quick WP-CLI reference

```bash
# Check option values
wp option get dhc_api_key
wp option get dhc_telemetry_token
wp option get dhc_installed_version

# Manually trigger the provisioning cron
wp cron event run dhc_provision_telemetry_token

# List all scheduled DHC cron events
wp cron event list | grep dhc

# Force a version-change re-run (simulate upgrade from 1.17.3)
wp option update dhc_installed_version 1.17.3
# then load any admin page or run: wp eval 'dhc_init();'
```
