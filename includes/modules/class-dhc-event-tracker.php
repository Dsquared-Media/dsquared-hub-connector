<?php
/**
 * DHC_Event_Tracker — Module: "Skip GTM" event tracking.
 *
 * Lets agency clients turn on common GTM-style events (form submits,
 * phone/email clicks, outbound clicks, scroll depth, video plays, CTA
 * clicks, file downloads) plus custom selector-based events, without
 * ever opening GTM. Fires events to window.dataLayer AND to gtag
 * directly, so it works whether the site uses GTM, gtag, both, or
 * neither.
 *
 * Also exposes:
 *   - window.dhcTrack(name, params)            // JS API for devs
 *   - do_action('dhc_track_event', $n, $p)     // PHP action for plugins
 *
 * Architecture: all listeners run in-page for zero latency. The Hub
 * is never in the hot path. Config lives in WP options.
 *
 * @package Dsquared_Hub_Connector
 * @since   1.13.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DHC_Event_Tracker {

	const OPT = 'dhc_event_tracker';

	/** Defaults — presets default OFF so turning this on doesn't
	 * immediately start double-counting anything a client is already
	 * tracking by hand. They opt into each one. */
	public static function defaults() {
		return array(
			'enabled'        => false,       // master switch
			'send_to_hub'    => true,        // beacon events to Hub for agency rollup
			'presets'        => array(
				'form_submit'   => false,
				'phone_click'   => false,
				'email_click'   => false,
				'outbound'      => false,
				'scroll_depth'  => false,
				'video_play'    => false,
				'cta_click'     => false,
				'file_download' => false,
			),
			'custom'         => array(),     // [{ name, selector }]
			'cta_selectors'  => '.btn-primary,.elementor-button,a.button',
		);
	}

	public static function init() {
		// DHC_Admin owns the single ordered submenu registration in 1.23.

		// Receive events from the PHP action hook (other plugins / themes).
		add_action( 'dhc_track_event', array( __CLASS__, 'php_hook_event' ), 10, 2 );

		// Inject the tracker JS on the front-end when enabled.
		if ( self::is_enabled() ) {
			add_action( 'wp_footer', array( __CLASS__, 'inject_tracker' ), 5 );
		}

		// Save handler.
		add_action( 'admin_post_dhc_event_tracker_save', array( __CLASS__, 'handle_save' ) );
	}

	public static function get_config() {
		$cfg = get_option( self::OPT, array() );
		if ( ! is_array( $cfg ) ) $cfg = array();
		return array_replace_recursive( self::defaults(), $cfg );
	}

	/** Build a unique custom-event table; the last row for a normalized name wins. */
	private static function normalize_custom_events( $names, $selectors, $triggers ) {
		$by_name = array();
		$names = (array) $names; $selectors = (array) $selectors; $triggers = (array) $triggers;
		for ( $i = 0; $i < min( count( $names ), count( $selectors ) ); $i++ ) {
			$name = sanitize_key( trim( (string) $names[ $i ] ) ); $selector = sanitize_text_field( trim( (string) $selectors[ $i ] ) );
			if ( '' === $name || '' === $selector ) continue;
			$trigger = sanitize_key( $triggers[ $i ] ?? 'click' ); if ( ! in_array( $trigger, array( 'click', 'view', 'hover' ), true ) ) $trigger = 'click';
			$by_name[ $name ] = array( 'name' => $name, 'selector' => $selector, 'trigger' => $trigger );
		}
		return array_values( $by_name );
	}

	public static function is_enabled() {
		$c = self::get_config();
		return ! empty( $c['enabled'] );
	}

	/**
	 * PHP-side event emit. Queues the event into the dhc_track_queue
	 * option so the JS that runs next page load picks it up. Useful
	 * for server-side events like "post published" that should hit
	 * analytics on the next user page view.
	 */
	public static function php_hook_event( $name, $params = array() ) {
		$name = substr( sanitize_key( (string) $name ), 0, 64 );
		if ( empty( $name ) ) return;
		$queue = get_option( 'dhc_track_queue', array() );
		if ( ! is_array( $queue ) ) $queue = array();
		$counts = array();
		foreach ( $queue as $row ) {
			$queued_name = substr( sanitize_key( $row['name'] ?? '' ), 0, 64 );
			if ( '' === $queued_name ) continue;
			$counts[ $queued_name ] = min( 10000, ( $counts[ $queued_name ] ?? 0 ) + max( 1, absint( $row['count'] ?? 1 ) ) );
		}
		$counts[ $name ] = min( 10000, ( $counts[ $name ] ?? 0 ) + 1 );
		$aggregate = array();
		foreach ( array_slice( $counts, -50, null, true ) as $event_name => $count ) $aggregate[] = array( 'name' => $event_name, 'count' => $count );
		// Never persist event params here. They may contain contact details,
		// URLs or element text and are not part of Hub telemetry.
		update_option( 'dhc_track_queue', $aggregate, false );
	}

	public static function register_menu() {
		add_submenu_page(
			'dsquared-hub',
			__( 'Event Tracking', 'dsquared-hub-connector' ),
			__( 'Event Tracking', 'dsquared-hub-connector' ),
			'manage_options',
			'dsquared-hub-events',
			array( __CLASS__, 'render_admin_page' )
		);
	}

	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'no' );
		check_admin_referer( 'dhc_event_tracker' );

		$cfg = self::defaults();
		$cfg['enabled']     = ! empty( $_POST['enabled'] );
		$cfg['send_to_hub'] = ! empty( $_POST['send_to_hub'] );
		foreach ( $cfg['presets'] as $k => $_ ) {
			$cfg['presets'][ $k ] = ! empty( $_POST['preset'][ $k ] );
		}
		$cfg['cta_selectors'] = isset( $_POST['cta_selectors'] )
			? sanitize_text_field( wp_unslash( $_POST['cta_selectors'] ) )
			: $cfg['cta_selectors'];

		// Custom events — parallel arrays from the form.
		$names      = isset( $_POST['custom_name'] )     ? (array) wp_unslash( $_POST['custom_name'] )     : array();
		$selectors  = isset( $_POST['custom_selector'] ) ? (array) wp_unslash( $_POST['custom_selector'] ) : array();
		$triggers   = isset( $_POST['custom_trigger'] )  ? (array) wp_unslash( $_POST['custom_trigger'] )  : array();
		$cfg['custom'] = self::normalize_custom_events( $names, $selectors, $triggers );

		update_option( self::OPT, $cfg, false );

		wp_safe_redirect( add_query_arg( array( 'page' => 'dsquared-hub-events', 'saved' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) return;
		$cfg = self::get_config();
		$presets = array(
			'form_submit'   => array( 'Form submissions', 'Auto-detects Contact Form 7, Gravity Forms, WPForms, Elementor Forms and generic forms.' ),
			'phone_click'   => array( 'Phone clicks', 'Tracks every tel: link click.' ),
			'email_click'   => array( 'Email clicks', 'Tracks every mailto: link click.' ),
			'outbound'      => array( 'Outbound link clicks', 'Tracks links that leave this website.' ),
			'scroll_depth'  => array( 'Scroll depth milestones', 'Tracks 25, 50, 75 and 100 percent depth.' ),
			'video_play'    => array( 'Video play / complete', 'Tracks supported YouTube and Vimeo embeds.' ),
			'cta_click'     => array( 'CTA button clicks', 'Tracks the CTA selectors configured below.' ),
			'file_download' => array( 'File downloads', 'Tracks PDF, document, spreadsheet, ZIP and presentation downloads.' ),
		);
		?>
		<div class="wrap dhc-wrap dhc-v2">
			<?php DHC_Admin_Pages::header( 'dashicons-chart-line', 'Event Tracking', 'Skip GTM — turn on common events with a checkbox.' ); ?>
			<?php if ( isset( $_GET['saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Event tracking settings saved.</p></div><?php endif; ?>
			<form id="dhc-event-settings-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'dhc_event_tracker' ); ?><input type="hidden" name="action" value="dhc_event_tracker_save">
				<section class="dhc-card"><div class="dhc-card-header"><h2>Master switch</h2></div><div class="dhc-card-body">
					<label class="dhc-preset-row"><input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $cfg['enabled'] ) ); ?>><span class="dhc-preset-copy"><strong>Enable event tracking</strong><p>Injects a listener script on every front-end page. Events fire to window.dataLayer (GTM) and gtag() (GA4).</p></span></label>
					<label class="dhc-preset-row"><input type="checkbox" name="send_to_hub" value="1" <?php checked( ! empty( $cfg['send_to_hub'] ) ); ?>><span class="dhc-preset-copy"><strong>Send event counts to the Hub</strong><p>Beacons counts only so your agency can see activity across the site. No personal data.</p></span></label>
				</div></section>
				<section class="dhc-card"><div class="dhc-card-header"><h2>Preset events</h2><p>Toggle only what this site cares about.</p></div><div class="dhc-card-body"><div class="dhc-preset-list">
					<?php foreach ( $presets as $key => $meta ) : ?><div class="dhc-preset-row-wrap"><label class="dhc-preset-row"><input type="checkbox" name="preset[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( ! empty( $cfg['presets'][ $key ] ) ); ?>><span class="dhc-preset-copy"><strong><?php echo esc_html( $meta[0] ); ?></strong><code><?php echo esc_html( $key ); ?></code><p><?php echo esc_html( $meta[1] ); ?></p></span></label><?php if ( 'cta_click' === $key ) : ?><label class="dhc-v2-field dhc-preset-inset"><span>CTA selectors (comma-separated)</span><input type="text" name="cta_selectors" value="<?php echo esc_attr( $cfg['cta_selectors'] ); ?>" placeholder=".button-primary, #contact-submit"></label><?php endif; ?></div><?php endforeach; ?>
				</div></div></section>
				<section class="dhc-card"><div class="dhc-card-header"><h2>Custom events</h2><p>Use Track Event in the WordPress toolbar to point-and-click tag one visually.</p></div><div class="dhc-card-body">
					<?php if ( empty( $cfg['custom'] ) ) : ?><div class="dhc-empty"><strong>No custom events yet</strong><p>Open the visual picker and click any element on the live site.</p><button type="button" class="dhc-btn dhc-btn-outline" data-open-event-picker>+ Add custom event</button></div><?php else : ?>
					<div class="dhc-table-wrap"><table class="dhc-v2-table" id="dhc-custom-table"><thead><tr><th>Event name</th><th>Element</th><th>Fires on</th></tr></thead><tbody><?php foreach ( $cfg['custom'] as $event ) : ?><tr data-event-name="<?php echo esc_attr( $event['name'] ?? '' ); ?>"><td><input type="text" name="custom_name[]" value="<?php echo esc_attr( $event['name'] ?? '' ); ?>"></td><td><input type="text" name="custom_selector[]" value="<?php echo esc_attr( $event['selector'] ?? '' ); ?>"></td><td><select name="custom_trigger[]" aria-label="Trigger for <?php echo esc_attr( $event['name'] ?? 'custom event' ); ?>"><option value="click" <?php selected( $event['trigger'] ?? 'click', 'click' ); ?>>Click</option><option value="view" <?php selected( $event['trigger'] ?? 'click', 'view' ); ?>>View</option><option value="hover" <?php selected( $event['trigger'] ?? 'click', 'hover' ); ?>>Hover</option></select></td></tr><?php endforeach; ?></tbody></table></div><button type="button" class="dhc-btn dhc-btn-outline" data-open-event-picker>+ Add custom event</button><?php endif; ?><div id="dhc-custom-picker-state"></div>
				</div></section>
				<section class="dhc-card"><div class="dhc-card-header"><h2>Fire events from your own code</h2></div><div class="dhc-card-body dhc-code-pair"><div><p>JavaScript global</p><div class="dhc-code-snippet">window.dhcTrack('event_name', { key: 'value' });</div></div><div><p>PHP action hook</p><div class="dhc-code-snippet">do_action('dhc_track_event', 'event_name', $params);</div></div></div></section>
				<button type="submit" class="dhc-btn dhc-btn-primary">Save settings</button>
			</form>
		</div>
		<?php
	}

	public static function inject_tracker() {
		$cfg = self::get_config();

		// Drain the PHP-queued events — they'll fire on the next client
		// page load (this one). Used for server-initiated events.
		$queue = get_option( 'dhc_track_queue', array() );
		if ( ! is_array( $queue ) ) $queue = array();
		if ( ! empty( $queue ) ) update_option( 'dhc_track_queue', array(), false );

		// Hub beacon config — only attached when the user opted in AND
		// a telemetry token is configured. Use dhc_telemetry_token (narrow,
		// public-safe) NOT dhc_api_key (private connector key — must never
		// appear in public HTML because the same key authenticates privileged
		// Hub and WordPress REST write routes).
		$telemetry_token = get_option( 'dhc_telemetry_token', '' );
		$hub_base = defined( 'DHC_HUB_API_BASE' ) ? DHC_HUB_API_BASE : 'https://hub.dsquaredmedia.net/api';
		$beacon = ( ! empty( $cfg['send_to_hub'] ) && ! empty( $telemetry_token ) )
			? array(
				'url'      => rtrim( $hub_base, '/' ) . '/plugin/events',
				'api_key'  => $telemetry_token,
				'site_url' => home_url( '/' ),
			)
			: null;

		$js_cfg = array(
			'presets'       => array_filter( $cfg['presets'] ),
			'custom'        => $cfg['custom'],
			'cta_selectors' => $cfg['cta_selectors'],
			'queued'        => $queue,
			'beacon'        => $beacon,
		);
		?>
		<script id="dhc-event-tracker" data-version="<?php echo esc_attr( defined( 'DHC_VERSION' ) ? DHC_VERSION : '1.13.0' ); ?>">
		(function(){
			var CFG = <?php echo wp_json_encode( $js_cfg ); ?>;
			if (!CFG) return;

			// Hub beacon queue — flushes batched events to the Hub for the
			// agency rollup view. Uses navigator.sendBeacon when leaving
			// the page so we don't lose the last few events on tab close.
			var BEACON = CFG.beacon || null;
			var beacon_queue = []; // Aggregate-only records: {name,count}.
			var BEACON_FLUSH_MS = 30 * 1000;
			var BEACON_MAX_QUEUE = 50; // flush early if queue grows past this
			var _beaconFlushing = false; // guard: only one in-flight fetch at a time
			var BEACON_MAX_RETRY = 3;

			function flushBeacon(useSendBeacon) {
				if (!BEACON || !beacon_queue.length) return;
				// Snapshot the queue without removing — removal only happens after
				// confirmed delivery so a network failure doesn't silently discard events.
				var rawCount = beacon_queue.length;
				var totals = Object.create(null);
				beacon_queue.slice(0, rawCount).forEach(function(row){
					var eventName = normalizeEventName(row && row.name);
					if (!eventName) return;
					totals[eventName] = Math.min(10000, (totals[eventName] || 0) + Math.max(1, Number(row.count) || 1));
				});
				var batch = Object.keys(totals).map(function(name){ return { name: name, count: totals[name] }; });
				if (!batch.length) { beacon_queue.splice(0, rawCount); return; }
				var payload = JSON.stringify({ events: batch, site_url: BEACON.site_url });

				if (useSendBeacon) {
					// pagehide path: sendBeacon cannot set custom headers, and putting the
					// token in the URL (?k=) would expose it in server access logs and CDN
					// caches. Use fetch(keepalive:true) instead — browsers are required to
					// honour keepalive during page unload since the Fetch spec was updated.
					// If fetch isn't available (very old browsers) we drop the batch rather
					// than risk leaking the token in a URL.
					beacon_queue.splice(0, rawCount);
					try {
						fetch(BEACON.url, {
							method: 'POST',
							credentials: 'omit',
							headers: { 'Content-Type': 'application/json', 'X-DHC-API-Key': BEACON.api_key, 'X-DHC-Site-Url': BEACON.site_url },
							body: payload,
							keepalive: true
						}).catch(function() { /* fire-and-forget on unload */ });
					} catch (e) { /* never block page unload */ }
					return;
				}

				// Interval path: use fetch with bounded retry so transient network
				// blips don't permanently lose events.
				if (_beaconFlushing) return; // previous flush still in-flight
				_beaconFlushing = true;

				function attempt(n) {
					fetch(BEACON.url, {
						method: 'POST',
						credentials: 'omit',
						headers: { 'Content-Type': 'application/json', 'X-DHC-API-Key': BEACON.api_key, 'X-DHC-Site-Url': BEACON.site_url },
						body: payload,
						keepalive: true
					}).then(function(resp) {
						if (resp.ok) {
							// Confirmed delivery — now safe to remove from queue.
							beacon_queue.splice(0, rawCount);
							_beaconFlushing = false;
						} else if (n < BEACON_MAX_RETRY) {
							setTimeout(function() { attempt(n + 1); }, 1000 * Math.pow(2, n - 1));
						} else {
							beacon_queue.splice(0, rawCount);
							console.warn('[DHC] Hub beacon: ' + batch.length + ' events lost after ' + BEACON_MAX_RETRY + ' attempts (HTTP ' + resp.status + ')');
							_beaconFlushing = false;
						}
					}).catch(function() {
						if (n < BEACON_MAX_RETRY) {
							setTimeout(function() { attempt(n + 1); }, 1000 * Math.pow(2, n - 1));
						} else {
							beacon_queue.splice(0, rawCount);
							console.warn('[DHC] Hub beacon: ' + batch.length + ' events lost after ' + BEACON_MAX_RETRY + ' attempts (network error)');
							_beaconFlushing = false;
						}
					});
				}
				attempt(1);
			}
			if (BEACON) {
				setInterval(function() { flushBeacon(false); }, BEACON_FLUSH_MS);
				window.addEventListener('pagehide', function() { flushBeacon(true); }, false);
			}

			function normalizeEventName(name) {
				return String(name || '').toLowerCase().replace(/[^a-z0-9_]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 64);
			}

			function queueAggregate(name, count) {
				name = normalizeEventName(name);
				if (!BEACON || !name) return;
				beacon_queue.push({ name: name, count: Math.max(1, Number(count) || 1) });
				if (beacon_queue.length >= BEACON_MAX_QUEUE) flushBeacon(false);
			}

			// Core push — goes to dataLayer (GTM) + gtag (GA4). Both if both
			// are present; either if only one is; skips silently if neither.
			// Also pushes onto the Hub beacon queue when configured.
			function track(name, params, aggregateCount) {
				if (!name) return;
				params = params || {};
				params.event = name;
				try {
					window.dataLayer = window.dataLayer || [];
					window.dataLayer.push(params);
				} catch (e) {}
				try { if (typeof window.gtag === 'function') window.gtag('event', name, params); } catch (e) {}
				// Hub telemetry is deliberately separate from analytics params.
				// Only a normalized event name and aggregate count leave the browser.
				queueAggregate(name, aggregateCount || 1);
			}
			// Expose for devs — window.dhcTrack(name, params).
			window.dhcTrack = track;

			// Flush any PHP-queued events on page load.
			(CFG.queued || []).forEach(function(q){
				var count = Math.max(1, Math.min(10000, Number(q.count) || 1));
				track(q.name, { event_count: count }, count);
			});

			// ── Preset: form_submit ───────────────────────────────
			if (CFG.presets.form_submit) {
				// Contact Form 7
				document.addEventListener('wpcf7mailsent', function(e){ track('form_submit', { form_plugin: 'cf7', form_id: e.detail && e.detail.contactFormId }); });
				// Gravity Forms
				if (window.jQuery) { window.jQuery(document).on('gform_confirmation_loaded', function(e, formId){ track('form_submit', { form_plugin: 'gravityforms', form_id: formId }); }); }
				// WPForms
				document.addEventListener('wpformsAjaxSubmitSuccess', function(e){ track('form_submit', { form_plugin: 'wpforms', form_id: e.detail && e.detail.formId }); });
				// Elementor Forms
				if (window.jQuery) { window.jQuery(document).on('submit_success', function(e, response){ track('form_submit', { form_plugin: 'elementor' }); }); }
				// Generic fallback — any form.submit, but only if none of the plugin-specific events have fired in the last 2s.
				var _genericLock = 0;
				document.addEventListener('submit', function(ev){
					if (Date.now() - _genericLock < 2000) return;
					_genericLock = Date.now();
					var f = ev.target;
					if (!f || f.tagName !== 'FORM') return;
					track('form_submit', { form_plugin: 'generic', form_id: f.id || '', form_name: f.getAttribute('name') || '' });
				}, true);
			}

			// ── Preset: phone_click ──────────────────────────────
			if (CFG.presets.phone_click) {
				document.addEventListener('click', function(ev){
					var a = ev.target.closest && ev.target.closest('a[href^="tel:"]');
					if (!a) return;
					track('phone_click', { phone_number: (a.href || '').replace('tel:', '') });
				}, true);
			}

			// ── Preset: email_click ──────────────────────────────
			if (CFG.presets.email_click) {
				document.addEventListener('click', function(ev){
					var a = ev.target.closest && ev.target.closest('a[href^="mailto:"]');
					if (!a) return;
					var email = (a.href || '').replace('mailto:', '').split('?')[0];
					track('email_click', { email: email });
				}, true);
			}

			// ── Preset: outbound ─────────────────────────────────
			if (CFG.presets.outbound) {
				var thisHost = location.hostname.replace(/^www\./, '');
				document.addEventListener('click', function(ev){
					var a = ev.target.closest && ev.target.closest('a[href]');
					if (!a) return;
					var href = a.getAttribute('href') || '';
					if (!/^https?:\/\//i.test(href)) return;
					try {
						var h = new URL(href).hostname.replace(/^www\./, '');
						if (h && h !== thisHost) track('outbound_click', { url: href, host: h });
					} catch (e) {}
				}, true);
			}

			// ── Preset: file_download ────────────────────────────
			if (CFG.presets.file_download) {
				var FILE_RE = /\.(pdf|docx?|xlsx?|pptx?|zip|csv|txt|mp3|mp4)(\?|$)/i;
				document.addEventListener('click', function(ev){
					var a = ev.target.closest && ev.target.closest('a[href]');
					if (!a) return;
					var href = a.getAttribute('href') || '';
					if (FILE_RE.test(href)) {
						var ext = (href.match(FILE_RE) || [])[1] || '';
						track('file_download', { file: href, file_extension: ext.toLowerCase() });
					}
				}, true);
			}

			// ── Preset: cta_click ────────────────────────────────
			if (CFG.presets.cta_click && CFG.cta_selectors) {
				document.addEventListener('click', function(ev){
					var el = ev.target.closest && ev.target.closest(CFG.cta_selectors);
					if (!el) return;
					track('cta_click', {
						cta_text: (el.innerText || el.textContent || '').trim().slice(0, 80),
						cta_href: el.getAttribute('href') || '',
						cta_class: el.className || ''
					});
				}, true);
			}

			// ── Preset: scroll_depth ─────────────────────────────
			if (CFG.presets.scroll_depth) {
				var fired = { 25: false, 50: false, 75: false, 100: false };
				function checkScroll(){
					var doc = document.documentElement, body = document.body;
					var scrollTop = window.scrollY || doc.scrollTop;
					var height = Math.max(doc.scrollHeight, body.scrollHeight) - window.innerHeight;
					if (height <= 0) return;
					var pct = Math.round((scrollTop / height) * 100);
					[25, 50, 75, 100].forEach(function(t){
						if (!fired[t] && pct >= t) {
							fired[t] = true;
							track('scroll_depth', { percent: t });
						}
					});
				}
				var _st;
				window.addEventListener('scroll', function(){
					clearTimeout(_st);
					_st = setTimeout(checkScroll, 200);
				}, { passive: true });
			}

			// ── Preset: video_play (YouTube + Vimeo postMessage) ─
			if (CFG.presets.video_play) {
				window.addEventListener('message', function(ev){
					if (!ev.data) return;
					// YouTube iframe API sends {"event":"onStateChange","info":1} after enableJsApi.
					try {
						var d = typeof ev.data === 'string' ? JSON.parse(ev.data) : ev.data;
						if (d && d.event === 'onStateChange' && d.info === 1) track('video_play', { platform: 'youtube' });
						if (d && d.event === 'onStateChange' && d.info === 0) track('video_complete', { platform: 'youtube' });
						// Vimeo — {method:'play'} / {event:'finish'}
						if (d && (d.method === 'play' || d.event === 'play')) track('video_play', { platform: 'vimeo' });
						if (d && (d.event === 'ended' || d.event === 'finish')) track('video_complete', { platform: 'vimeo' });
					} catch (e) {}
				}, false);
			}

			// ── Custom events — click, hover or in-view matchers ──
			if (CFG.custom && CFG.custom.length) {
				document.addEventListener('click', function(ev){
					CFG.custom.forEach(function(rule){
						if (!rule.selector || !rule.name || (rule.trigger && rule.trigger !== 'click')) return;
						try {
							var el = ev.target.closest && ev.target.closest(rule.selector);
							if (el) track(rule.name, {
								element_text: (el.innerText || el.textContent || '').trim().slice(0, 80),
								element_href: el.getAttribute ? (el.getAttribute('href') || '') : ''
							});
						} catch (e) {}
					});
				}, true);
				document.addEventListener('mouseover', function(ev){
					CFG.custom.forEach(function(rule){
						if (!rule.selector || !rule.name || rule.trigger !== 'hover') return;
						try { var el = ev.target.closest && ev.target.closest(rule.selector); if (el && !el.__dhcHoverFired) { el.__dhcHoverFired = true; track(rule.name, { element_text: (el.innerText || '').trim().slice(0,80) }); } } catch(e) {}
					});
				}, true);
				if ('IntersectionObserver' in window) {
					CFG.custom.forEach(function(rule){
						if (!rule.selector || !rule.name || rule.trigger !== 'view') return;
						try { document.querySelectorAll(rule.selector).forEach(function(el){ var observer = new IntersectionObserver(function(entries){ entries.forEach(function(entry){ if(entry.isIntersecting){ track(rule.name,{ element_text:(el.innerText||'').trim().slice(0,80) }); observer.disconnect(); } }); },{threshold:.5}); observer.observe(el); }); } catch(e) {}
					});
				}
			}
		})();
		</script>
		<?php
	}
}
