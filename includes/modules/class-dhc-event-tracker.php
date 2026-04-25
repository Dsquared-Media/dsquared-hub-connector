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
		// Register the admin sub-page (hooked through DHC_Admin's menu loader).
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 20 );

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
		$name = sanitize_key( (string) $name );
		if ( empty( $name ) ) return;
		$queue = get_option( 'dhc_track_queue', array() );
		if ( ! is_array( $queue ) ) $queue = array();
		$queue[] = array(
			'name'       => $name,
			'params'     => is_array( $params ) ? $params : array(),
			'enqueued_at' => current_time( 'mysql' ),
		);
		// Cap the queue so a misbehaving plugin can't explode options.
		if ( count( $queue ) > 50 ) $queue = array_slice( $queue, -50 );
		update_option( 'dhc_track_queue', $queue, false );
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
		$cfg['custom'] = array();
		$names      = isset( $_POST['custom_name'] )     ? (array) wp_unslash( $_POST['custom_name'] )     : array();
		$selectors  = isset( $_POST['custom_selector'] ) ? (array) wp_unslash( $_POST['custom_selector'] ) : array();
		for ( $i = 0; $i < min( count( $names ), count( $selectors ) ); $i++ ) {
			$name = sanitize_key( trim( (string) $names[ $i ] ) );
			$sel  = sanitize_text_field( trim( (string) $selectors[ $i ] ) );
			if ( $name === '' || $sel === '' ) continue;
			$cfg['custom'][] = array( 'name' => $name, 'selector' => $sel );
		}

		update_option( self::OPT, $cfg, false );

		wp_safe_redirect( add_query_arg( array( 'page' => 'dsquared-hub-events', 'saved' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render_admin_page() {
		$cfg = self::get_config();
		$presets = array(
			'form_submit'   => array( 'Form submissions',        'Auto-detects Contact Form 7, Gravity Forms, WPForms, Elementor Forms + any generic <form> submit.' ),
			'phone_click'   => array( 'Phone clicks',            'Fires on every <a href="tel:…"> click. Pushes the phone number as a param.' ),
			'email_click'   => array( 'Email clicks',            'Fires on every <a href="mailto:…"> click.' ),
			'outbound'      => array( 'Outbound link clicks',    'Fires on any link whose hostname differs from yours.' ),
			'scroll_depth'  => array( 'Scroll depth milestones', 'Fires once per page load at 25 / 50 / 75 / 100%.' ),
			'video_play'    => array( 'Video play / complete',   'Detects YouTube + Vimeo embeds. Fires on first play and on complete.' ),
			'cta_click'     => array( 'CTA button clicks',       'Fires on clicks matching the CTA selectors you configure below.' ),
			'file_download' => array( 'File downloads',          'Fires on links ending in .pdf / .doc(x) / .xls(x) / .zip / .csv / .txt / .ppt(x).' ),
		);
		?>
		<div class="wrap dhc-wrap">
			<div class="dhc-header">
				<div class="dhc-header-left">
					<div class="dhc-logo"><div class="dhc-logo-icon"><span class="dashicons dashicons-chart-line" style="color:#EC4899;font-size:22px;display:flex;align-items:center;justify-content:center;height:100%;"></span></div></div>
					<div>
						<h1 class="dhc-title">Event Tracking</h1>
						<div class="dhc-version">Skip GTM — turn on common events with a checkbox.</div>
					</div>
				</div>
			</div>

			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>Event tracking settings saved.</p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'dhc_event_tracker' ); ?>
				<input type="hidden" name="action" value="dhc_event_tracker_save">

				<div class="dhc-card">
					<div class="dhc-card-header"><h2>Master switch</h2></div>
					<div class="dhc-card-body" style="display:flex;flex-direction:column;gap:14px;">
						<label style="display:flex;align-items:flex-start;gap:12px;cursor:pointer;">
							<input type="checkbox" name="enabled" value="1" style="margin-top:2px;" <?php checked( ! empty( $cfg['enabled'] ) ); ?>>
							<div>
								<strong>Enable event tracking</strong>
								<div style="font-size:13px;color:#64748b;margin-top:2px;">Injects a tiny listener script on every front-end page. Events fire to <code>window.dataLayer</code> (GTM) and directly to <code>gtag()</code> (GA4) — whichever you have set up.</div>
							</div>
						</label>
						<label style="display:flex;align-items:flex-start;gap:12px;cursor:pointer;">
							<input type="checkbox" name="send_to_hub" value="1" style="margin-top:2px;" <?php checked( ! empty( $cfg['send_to_hub'] ) ); ?>>
							<div>
								<strong>Send event counts to the Dsquared Hub</strong>
								<div style="font-size:13px;color:#64748b;margin-top:2px;">Beacons captured events to <code>hub.dsquaredmedia.net</code> so your agency can see "X form submits this week" across every site in one rollup. No personal data — just event names + counts. Batched and flushed via <code>navigator.sendBeacon</code> so it never blocks page loads.</div>
							</div>
						</label>
					</div>
				</div>

				<div class="dhc-card">
					<div class="dhc-card-header">
						<h2>Preset events</h2>
						<p class="dhc-card-desc">Toggle only what this site actually cares about — each preset is independent.</p>
					</div>
					<div class="dhc-card-body" style="display:flex;flex-direction:column;gap:14px;">
						<?php foreach ( $presets as $key => $meta ) : ?>
							<label style="display:flex;align-items:flex-start;gap:12px;padding:12px 14px;border:1px solid #e2e8f0;border-radius:10px;cursor:pointer;">
								<input type="checkbox" name="preset[<?php echo esc_attr( $key ); ?>]" value="1" style="margin-top:2px;" <?php checked( ! empty( $cfg['presets'][ $key ] ) ); ?>>
								<div style="flex:1;">
									<strong style="font-size:14px;"><?php echo esc_html( $meta[0] ); ?></strong>
									<span style="display:inline-block;margin-left:8px;padding:2px 8px;border-radius:999px;background:#f1f5f9;color:#475569;font-size:11px;font-weight:700;font-family:monospace;"><?php echo esc_html( $key ); ?></span>
									<div style="font-size:13px;color:#64748b;margin-top:4px;line-height:1.5;"><?php echo esc_html( $meta[1] ); ?></div>
								</div>
							</label>
						<?php endforeach; ?>

						<div style="padding:12px 14px;border:1px dashed #cbd5e1;border-radius:10px;background:#f8fafc;">
							<label style="font-size:13px;font-weight:600;display:block;margin-bottom:4px;color:#334155;">CTA selectors (comma-separated)</label>
							<input type="text" name="cta_selectors" value="<?php echo esc_attr( $cfg['cta_selectors'] ); ?>" class="dhc-input" placeholder=".btn-primary, .elementor-button, a.button" style="width:100%;">
							<div style="font-size:12px;color:#64748b;margin-top:4px;">Used by the "CTA button clicks" preset. Matches standard CSS selectors — text content is handled separately via custom events.</div>
						</div>
					</div>
				</div>

				<div class="dhc-card">
					<div class="dhc-card-header">
						<h2>Custom events</h2>
						<p class="dhc-card-desc">Fire your own named event when a specific element is clicked. Good for "Book Now" buttons, "Download Menu" links, anything you want to A/B test or track.</p>
					</div>
					<div class="dhc-card-body">
						<table class="widefat striped" style="border:0;margin-bottom:10px;" id="dhc-custom-table">
							<thead>
								<tr>
									<th style="width:240px;">Event name</th>
									<th>CSS selector</th>
									<th style="width:40px;"></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $cfg['custom'] as $i => $r ) : ?>
									<tr>
										<td><input type="text" name="custom_name[]" value="<?php echo esc_attr( $r['name'] ); ?>" class="dhc-input" style="width:100%;" placeholder="book_now_click"></td>
										<td><input type="text" name="custom_selector[]" value="<?php echo esc_attr( $r['selector'] ); ?>" class="dhc-input" style="width:100%;" placeholder=".book-now-btn, a[href*='/book']"></td>
										<td><button type="button" class="dhc-btn dhc-btn-outline dhc-row-remove" style="padding:4px 10px;color:#dc2626;border-color:#fecaca;">×</button></td>
									</tr>
								<?php endforeach; ?>
								<tr id="dhc-row-template" style="display:none;">
									<td><input type="text" name="custom_name[]" class="dhc-input" style="width:100%;" placeholder="book_now_click"></td>
									<td><input type="text" name="custom_selector[]" class="dhc-input" style="width:100%;" placeholder=".book-now-btn"></td>
									<td><button type="button" class="dhc-btn dhc-btn-outline dhc-row-remove" style="padding:4px 10px;color:#dc2626;border-color:#fecaca;">×</button></td>
								</tr>
							</tbody>
						</table>
						<button type="button" id="dhc-add-custom" class="dhc-btn dhc-btn-outline" style="font-size:13px;">+ Add custom event</button>
					</div>
				</div>

				<div class="dhc-card dhc-card-subtle">
					<div class="dhc-card-body">
						<strong style="font-size:13px;">Fire events from your own code (two ways):</strong>
						<div style="font-size:13px;color:#334155;margin-top:8px;line-height:1.6;">
							<div><strong>JavaScript:</strong> <code style="background:#f1f5f9;padding:2px 6px;border-radius:4px;">window.dhcTrack('checkout_started', { plan: 'pro' });</code></div>
							<div style="margin-top:6px;"><strong>PHP / other plugins:</strong> <code style="background:#f1f5f9;padding:2px 6px;border-radius:4px;">do_action('dhc_track_event', 'lead_scored', [ 'score' =&gt; 42 ]);</code></div>
						</div>
					</div>
				</div>

				<button type="submit" class="dhc-btn dhc-btn-primary">Save settings</button>
			</form>
		</div>
		<script>
		(function(){
			document.getElementById('dhc-add-custom').addEventListener('click', function(){
				var tbody = document.querySelector('#dhc-custom-table tbody');
				var tpl = document.getElementById('dhc-row-template');
				var clone = tpl.cloneNode(true);
				clone.id = '';
				clone.style.display = '';
				tbody.insertBefore(clone, tpl);
			});
			document.addEventListener('click', function(e){
				if (e.target && e.target.classList && e.target.classList.contains('dhc-row-remove')) {
					var tr = e.target.closest('tr');
					if (tr && tr.id !== 'dhc-row-template') tr.remove();
				}
			});
		})();
		</script>
		<?php
	}

	/** Inject the in-page event listener. Runs on wp_footer when enabled. */
	public static function inject_tracker() {
		$cfg = self::get_config();

		// Drain the PHP-queued events — they'll fire on the next client
		// page load (this one). Used for server-initiated events.
		$queue = get_option( 'dhc_track_queue', array() );
		if ( ! is_array( $queue ) ) $queue = array();
		if ( ! empty( $queue ) ) update_option( 'dhc_track_queue', array(), false );

		// Hub beacon config — only attached when the user opted in AND
		// an API key is configured. Without one we silently skip the
		// beacon path; events still go to dataLayer/gtag.
		$api_key = get_option( 'dhc_api_key', '' );
		$hub_base = defined( 'DHC_HUB_API_BASE' ) ? DHC_HUB_API_BASE : 'https://hub.dsquaredmedia.net/api';
		$beacon = ( ! empty( $cfg['send_to_hub'] ) && ! empty( $api_key ) )
			? array(
				'url'      => rtrim( $hub_base, '/' ) . '/plugin/events',
				'api_key'  => $api_key,
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
			var beacon_queue = [];
			var BEACON_FLUSH_MS = 30 * 1000;
			var BEACON_MAX_QUEUE = 50; // flush early if queue grows past this

			function flushBeacon(useSendBeacon) {
				if (!BEACON || !beacon_queue.length) return;
				var batch = beacon_queue.splice(0, beacon_queue.length);
				var payload = JSON.stringify({ events: batch, site_url: BEACON.site_url });
				try {
					if (useSendBeacon && navigator.sendBeacon) {
						// sendBeacon doesn't support custom headers — encode the
						// API key as a query param for this code path only.
						var url = BEACON.url + (BEACON.url.indexOf('?') >= 0 ? '&' : '?') + 'k=' + encodeURIComponent(BEACON.api_key);
						navigator.sendBeacon(url, new Blob([payload], { type: 'application/json' }));
					} else {
						fetch(BEACON.url, {
							method: 'POST',
							credentials: 'omit',
							headers: { 'Content-Type': 'application/json', 'X-DHC-API-Key': BEACON.api_key, 'X-DHC-Site-Url': BEACON.site_url },
							body: payload,
							keepalive: true
						}).catch(function() { /* network blip — give up, next flush will pick up new events */ });
					}
				} catch (e) { /* never let beacon throw block the page */ }
			}
			if (BEACON) {
				setInterval(function() { flushBeacon(false); }, BEACON_FLUSH_MS);
				window.addEventListener('pagehide', function() { flushBeacon(true); }, false);
			}

			// Core push — goes to dataLayer (GTM) + gtag (GA4). Both if both
			// are present; either if only one is; skips silently if neither.
			// Also pushes onto the Hub beacon queue when configured.
			function track(name, params) {
				if (!name) return;
				params = params || {};
				params.event = name;
				try {
					window.dataLayer = window.dataLayer || [];
					window.dataLayer.push(params);
				} catch (e) {}
				try { if (typeof window.gtag === 'function') window.gtag('event', name, params); } catch (e) {}
				// Hub beacon — strip the synthesized "event" key (server already
				// has the name as a top-level field) to keep payload tight.
				if (BEACON) {
					var p = {};
					for (var k in params) { if (params.hasOwnProperty(k) && k !== 'event') p[k] = params[k]; }
					beacon_queue.push({ name: name, params: p, ts: Date.now() });
					if (beacon_queue.length >= BEACON_MAX_QUEUE) flushBeacon(false);
				}
			}
			// Expose for devs — window.dhcTrack(name, params).
			window.dhcTrack = track;

			// Flush any PHP-queued events on page load.
			(CFG.queued || []).forEach(function(q){ track(q.name, q.params || {}); });

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

			// ── Custom events — delegated click matcher ──────────
			if (CFG.custom && CFG.custom.length) {
				document.addEventListener('click', function(ev){
					CFG.custom.forEach(function(rule){
						if (!rule.selector || !rule.name) return;
						try {
							var el = ev.target.closest && ev.target.closest(rule.selector);
							if (el) track(rule.name, {
								element_text: (el.innerText || el.textContent || '').trim().slice(0, 80),
								element_href: el.getAttribute ? (el.getAttribute('href') || '') : ''
							});
						} catch (e) {}
					});
				}, true);
			}
		})();
		</script>
		<?php
	}
}
