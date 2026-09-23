<?php
/**
 * DHC_Crawler — Outbound plugin-pull site crawler (v1.16.0)
 *
 * Polls the Hub every 5 minutes for queued crawl jobs assigned to this
 * website. When a job is found: claims it, crawls the site via internal
 * loopback requests, and uploads page data in bounded chunks.
 *
 * ── Mutation contract (complete) ────────────────────────────────────────────
 * No content, theme, plugin, user, configuration, or SEO-setting mutations.
 * The plugin creates only its own bounded operational state and
 * scheduled-event records. Specifically, this class:
 *
 *   wp_options rows WRITTEN:
 *     dhc_active_crawl   — crawl state (job_id, claim_token, urls, counters).
 *                          Written on claim; updated each tick; deleted on
 *                          completion, terminal failure, TTL expiry,
 *                          plugin deactivation, and plugin upgrade.
 *     dhc_crawler_diagnostics — bounded last-attempt timestamp/result plus an
 *                          optional HTTP status, error code, job ID, or bounded
 *                          requested/effective page counts. It
 *                          never contains credentials, tokens, URLs, or bodies.
 *
 *   wp_options rows READ (never written by this class):
 *     dhc_api_key        — plugin API key (owner sets this in wp-admin).
 *     dhc_installed_version — read for upgrade detection.
 *
 *   Transients WRITTEN:
 *     dhc_crawler_lock   — overlap prevention. TTL = LOCK_TTL_SEC (270 s).
 *                          Written at start of run_poll_tick(); deleted on
 *                          exit. Auto-expires before the next 300 s tick.
 *
 *   WP-Cron events WRITTEN:
 *     dhc_crawler_poll   — registered on init (schedule_poll()). Fires every
 *                          5 minutes on the dhc_five_minutes schedule.
 *                          Unscheduled on plugin deactivation (dhc_deactivate()
 *                          in dsquared-hub-connector.php).
 *
 *   No rows are written to any other option, transient, post-meta, user-meta,
 *   or custom table. No WordPress content (posts, pages, attachments, comments,
 *   users, terms, options outside the above) is modified.
 *
 * ── Request path (WP Engine loopback assumption) ─────────────────────────────
 * Page requests are made to the site's own public canonical domain through
 * WordPress's bundled Requests cURL-multi transport, with wp_remote_get() as a
 * compatibility fallback when true concurrency is unavailable.
 * On managed hosts (WP Engine, Flywheel), the server-side DNS typically
 * resolves the site's own hostname to the local web server rather than to
 * the upstream CDN (Cloudflare), bypassing the edge WAF. This behaviour
 * HAS NOT been proven in a live WP Engine environment and must be validated
 * in Stage 3 testing. If a WAF challenge response is received, is_challenge_page()
 * will detect it and skip the URL — the job will finalize with those URLs
 * missing from the crawl result. A loopback redesign is required before
 * Delray rollout if every URL returns a challenge.
 *
 * ── Token protocol (summary) ────────────────────────────────────────────────
 * 1. Hub issues HS256 offer_token {typ:'offer', job_id, website_id, exp, nonce}
 *    signed with CONNECTOR_JOB_SECRET. Plugin receives it via the poll response.
 * 2. Plugin sends offer_token + its own nonce to /claim. Plugin NEVER modifies
 *    the token — it is passed through unchanged.
 * 3. Hub validates: HS256 signature, typ='offer', matching job_id, matching
 *    website_id, expiry, and that the nonce has not been seen before.
 * 4. Hub issues claim_token {typ:'claim', job_id, website_id, nonce_hash, exp}.
 * 5. Plugin stores claim_token in dhc_active_crawl (never logged).
 * 6. Plugin sends claim_token on each chunk and completion call. Hub verifies
 *    its signature, website/job binding, expiry, and claim nonce session.
 * 7. offer_tokens cannot upload chunks; claim_tokens cannot claim another job.
 * 8. dhc_active_crawl is deleted at terminal states (success, max retries,
 *    TTL expiry, deactivation, upgrade) — claim_token is deleted with it.
 *
 * @package Dsquared_Hub_Connector
 * @since   1.15.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DHC_Crawler {

	/** Cron hook name */
	const CRON_HOOK = 'dhc_crawler_poll';

	/** One-shot hook used to continue an active crawl without a five-minute gap. */
	const CONTINUE_HOOK = 'dhc_crawler_continue';

	/** Reuse the heartbeat's five-minute schedule */
	const INTERVAL_NAME = 'dhc_five_minutes';

	/** wp_options key for active crawl state */
	const STATE_KEY = 'dhc_active_crawl';

	/** Transient key for per-tick execution lock */
	const LOCK_TRANSIENT = 'dhc_crawler_lock';

	/** Bounded, non-secret diagnostics shown to site administrators. */
	const DIAGNOSTICS_KEY = 'dhc_crawler_diagnostics';

	/**
	 * Lock TTL: 270 s < 300 s tick interval.
	 * If a tick dies without releasing the lock, it auto-expires before the
	 * next run — preventing a permanent lock-up.
	 */
	const LOCK_TTL_SEC = 270;

	/** Pages to crawl per cron tick (keeps each tick under ~30 s) */
	const PAGES_PER_TICK = 20;

	/** Conservative same-origin concurrency; avoids flooding small WordPress hosts. */
	const CONCURRENT_FETCH_WORKERS = 3;

	/** Stop starting page-fetch rounds after this many wall-clock seconds. */
	const FETCH_WALL_CLOCK_BUDGET_SEC = 27;

	/** Pages per chunk upload — Hub MAX_PAGES_PER_CHUNK is 100 */
	const PAGES_PER_CHUNK = 50;

	/**
	 * Match the Hub-supported 500-page maximum. The Hub renews the same
	 * website/job/connector/nonce-bound claim after each accepted chunk, while
	 * retaining a one-hour idle lease and a four-hour absolute duration cap.
	 */
	const MAX_PAGES_HARD_CAP = 500;

	/**
	 * Maximum URL queue depth.
	 * At 500 pages × 4 links/page each URL is ~50 bytes; 2 000 entries ≈ 100 KB.
	 * Prevents dhc_active_crawl from growing unbounded on highly-linked sites.
	 */
	const MAX_QUEUE_SIZE = 2000;

	/** One hour without an accepted chunk is genuinely stale. */
	const STATE_IDLE_TTL_SEC = 3600;

	/** Absolute local runaway guard, aligned with the Hub claim-duration cap. */
	const STATE_ABSOLUTE_TTL_SEC = 14400;

	/** Per-page fetch timeout in seconds */
	const PAGE_TIMEOUT = 15;

	/** Bounded HTML/schema extraction limits shared with the Hub envelope. */
	const MAX_HTML_PARSE_BYTES = 409600;
	const MAX_SCHEMA_SCRIPTS   = 20;
	const MAX_SCHEMA_SCRIPT_BYTES = 131072;
	const MAX_SCHEMA_TYPES     = 20;
	const MAX_SCHEMA_NODES     = 5000;

	/** Maximum redirect hops to follow per URL */
	const MAX_REDIRECTS = 3;

	/**
	 * Maximum completion attempts before treating the job as terminally failed.
	 * Each attempt happens on a separate cron tick (≥ 5 min apart).
	 */
	const MAX_COMPLETE_ATTEMPTS = 3;

	/** @var self|null */
	private static $instance = null;

	/** @var bool Prevent duplicate shutdown continuations in one PHP request. */
	private $continuation_scheduled = false;

	public static function init() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		add_filter( 'cron_schedules', array( $this, 'add_cron_interval' ) );
		add_action( 'init',           array( $this, 'schedule_poll' ) );
		add_action( self::CRON_HOOK,  array( $this, 'run_poll_tick' ) );
		add_action( self::CONTINUE_HOOK, array( $this, 'run_poll_tick' ) );
		// Heartbeats are already proven to run on connected sites. Use that
		// authoritative five-minute event as a fallback so a missing/stale
		// crawler-specific cron row cannot strand Hub scans in queued forever.
		// Priority 20 runs after the heartbeat POST; the shared transient below
		// prevents a second tick if both cron hooks fire in the same window.
		add_action( DHC_Heartbeat::CRON_HOOK, array( $this, 'run_poll_tick' ), 20 );
		add_action( 'admin_init',     array( $this, 'maybe_wake_overdue_poll' ), 1 );
	}

	public function add_cron_interval( $schedules ) {
		if ( ! isset( $schedules[ self::INTERVAL_NAME ] ) ) {
			$schedules[ self::INTERVAL_NAME ] = array(
				'interval' => 300,
				'display'  => esc_html__( 'Every 5 Minutes', 'dsquared-hub-connector' ),
			);
		}
		return $schedules;
	}

	/**
	 * Schedule the poll cron and clean up state from a previous plugin version.
	 *
	 * Runs on every 'init' action. When the installed plugin version changes
	 * (e.g. auto-update), any in-progress crawl state is discarded because
	 * protocol or field contracts may have changed across versions.
	 */
	public function schedule_poll() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			$scheduled = wp_schedule_event( time(), self::INTERVAL_NAME, self::CRON_HOOK, array(), true );
			if ( is_wp_error( $scheduled ) ) {
				$this->record_diagnostic( 'schedule_failed', array(
					'error_code' => sanitize_key( $scheduled->get_error_code() ),
				) );
			} else {
				$this->record_diagnostic( 'scheduled' );
			}
		}

		// Upgrade state cleanup: if the stored state was written by a different
		// plugin version, discard it rather than risk protocol mismatch.
		$state = get_option( self::STATE_KEY, null );
		if ( is_array( $state ) &&
			 ! empty( $state['plugin_version'] ) &&
			 $state['plugin_version'] !== DHC_VERSION ) {
			delete_option( self::STATE_KEY );
			delete_transient( self::LOCK_TRANSIENT );
		}
	}

	/**
	 * Ask WordPress to dispatch an overdue crawler event on admin traffic.
	 *
	 * Normal front-end requests already invoke WordPress cron. This small
	 * fallback covers sites with unusual cron bootstrap/order behaviour after
	 * an auto-update, without running the network crawl inside an admin request.
	 */
	public function maybe_wake_overdue_poll() {
		$this->schedule_poll();
		$next = wp_next_scheduled( self::CRON_HOOK );
		if ( $next && $next <= time() && function_exists( 'spawn_cron' ) ) {
			spawn_cron( time() );
		}
	}

	/** Return bounded diagnostics. Tokens, keys, URLs and response bodies are never stored. */
	public static function get_diagnostics() {
		$value = get_option( self::DIAGNOSTICS_KEY, array() );
		return is_array( $value ) ? $value : array();
	}

	private function record_diagnostic( $result, array $extra = array() ) {
		$diagnostic = array_merge( array(
			'last_attempt_at' => gmdate( 'c' ),
			'last_result'     => sanitize_key( $result ),
		), array_intersect_key( $extra, array_flip( array(
			'http_status', 'error_code', 'job_id', 'requested_pages', 'effective_pages',
		) ) ) );
		update_option( self::DIAGNOSTICS_KEY, $diagnostic, false );
	}

	public static function deactivate() {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( self::CONTINUE_HOOK );
		delete_option( self::STATE_KEY );
		delete_option( self::DIAGNOSTICS_KEY );
		delete_transient( self::LOCK_TRANSIENT );
	}

	/**
	 * Queue the next bounded crawl batch immediately after this cron request.
	 *
	 * The regular five-minute poll remains the recovery path if loopback cron
	 * spawning is disabled by the host. Scheduling the event before shutdown
	 * makes the work durable; spawning at shutdown avoids WordPress's current
	 * cron lock and keeps the browser/request that triggered this tick fast.
	 */
	private function schedule_continuation() {
		if ( $this->continuation_scheduled ) {
			return;
		}

		if ( ! wp_next_scheduled( self::CONTINUE_HOOK ) ) {
			$scheduled = wp_schedule_single_event( time(), self::CONTINUE_HOOK, array(), true );
			if ( is_wp_error( $scheduled ) ) {
				$this->record_diagnostic( 'continuation_schedule_failed', array(
					'error_code' => sanitize_key( $scheduled->get_error_code() ),
				) );
				return;
			}
		}

		$this->continuation_scheduled = true;
		register_shutdown_function( function() {
			// The cadence lock intentionally covers the whole current request. It
			// is released only here, after WordPress has finished this cron batch,
			// so the heartbeat and regular crawler hook cannot overlap it.
			delete_transient( self::LOCK_TRANSIENT );
			if ( function_exists( 'spawn_cron' ) ) {
				spawn_cron( time() );
			}
		} );
	}

	/**
	 * Queue an immediate, bounded poll after an authenticated Hub wake request.
	 *
	 * The wake is only an acceleration hint: the durable five-minute cron and
	 * heartbeat hooks remain the recovery path. Repeated requests are idempotent
	 * because WordPress stores at most one pending continuation event, and the
	 * normal crawler lock still prevents overlapping workers.
	 *
	 * @return bool True when an immediate event is queued or already pending.
	 */
	public function schedule_immediate_poll() {
		// Do not call schedule_continuation() here. That helper is entered by the
		// crawler worker itself and its shutdown callback releases the lock owned by
		// that worker. A concurrent REST wake must never delete another request's
		// lock, otherwise two crawler ticks can overlap.
		if ( $this->continuation_scheduled || wp_next_scheduled( self::CONTINUE_HOOK ) ) {
			return true;
		}

		// If a worker currently owns the cadence lock, keep the wake durable but
		// defer it until that lock has certainly expired. The normal five-minute
		// poll remains the final recovery path. When no worker is active, dispatch
		// the newly-created event at shutdown without touching lock state.
		$lock_held = (bool) get_transient( self::LOCK_TRANSIENT );
		$run_at    = time() + ( $lock_held ? self::LOCK_TTL_SEC + 1 : 0 );
		$scheduled = wp_schedule_single_event( $run_at, self::CONTINUE_HOOK, array(), true );
		if ( is_wp_error( $scheduled ) ) {
			$this->record_diagnostic( 'wake_schedule_failed', array(
				'error_code' => sanitize_key( $scheduled->get_error_code() ),
			) );
			return false;
		}

		$this->continuation_scheduled = true;
		if ( ! $lock_held ) {
			register_shutdown_function( function() {
				if ( function_exists( 'spawn_cron' ) ) {
					spawn_cron( time() );
				}
			} );
		}

		return true;
	}

	// ── Main cron tick ───────────────────────────────────────────────────────────

	/**
	 * Entry point called by WP-Cron every 5 minutes.
	 *
	 * WP-Cron reliability on low-traffic sites:
	 * - WP-Cron fires on page load. Low-traffic sites may miss ticks.
	 * - The Hub's job reaper expires unclaimed jobs after their TTL.
	 * - An in-progress crawl that misses ticks will resume on the next
	 *   real page load — STATE_KEY preserves the queue and chunk_index.
	 * - After one hour without accepted progress, or four hours total, state is
	 *   discarded and the Hub reaper marks the job failed/expired.
	 * - Alternatives (WP Cron alternative like Action Scheduler, server cron)
	 *   are outside this plugin's scope and may be recommended to site owners.
	 *
	 * Overlap prevention: a transient lock (LOCK_TTL_SEC = 270 s) prevents
	 * two overlapping PHP processes from running the crawler simultaneously.
	 * The lock auto-expires in 270 s — before the next 300 s tick — so a
	 * crashed tick cannot permanently block the next run.
	 */
	public function run_poll_tick() {
		$api_key = get_option( 'dhc_api_key', '' );
		if ( empty( $api_key ) ) {
			$this->record_diagnostic( 'missing_api_key' );
			return;
		}

		// Overlap prevention.
		if ( get_transient( self::LOCK_TRANSIENT ) ) {
			// The heartbeat fallback and dedicated crawler event can fire in the
			// same cron window. Preserve the first tick's meaningful result (idle,
			// claimed, crawling, completed, or an actionable error) instead of
			// replacing it with an expected cadence-lock observation.
			$diagnostic = self::get_diagnostics();
			$diagnostic['last_locked_at'] = gmdate( 'c' );
			update_option( self::DIAGNOSTICS_KEY, $diagnostic, false );
			return;
		}
		set_transient( self::LOCK_TRANSIENT, 1, self::LOCK_TTL_SEC );
		$this->record_diagnostic( 'tick_started' );

		try {
			$hub_url = DHC_Heartbeat::get_hub_url();
			$state   = get_option( self::STATE_KEY, null );

			// Discard only genuinely idle or absolutely overlong state. A healthy
			// 500-page crawl can span more than one hour at the five-minute cadence.
			if ( is_array( $state ) && ! empty( $state['created_at'] ) ) {
				$created_at      = (int) $state['created_at'];
				$last_progress_at = (int) ( $state['last_progress_at'] ?? $created_at );
				$absolute_expired = ( time() - $created_at ) > self::STATE_ABSOLUTE_TTL_SEC;
				$idle_expired     = ( time() - $last_progress_at ) > self::STATE_IDLE_TTL_SEC;
				if ( $absolute_expired || $idle_expired ) {
					$this->record_diagnostic( 'state_expired', array(
						'job_id' => sanitize_text_field( $state['job_id'] ?? '' ),
						'reason' => $absolute_expired ? 'absolute_duration' : 'idle_timeout',
					) );
					delete_option( self::STATE_KEY );
					$state = null;
				}
			}

			if ( is_array( $state ) && ! empty( $state['job_id'] ) ) {
				$phase = $state['phase'] ?? 'crawling';
				if ( 'completing' === $phase ) {
					$this->retry_complete( $api_key, $hub_url, $state );
				} else {
					$this->continue_crawl( $api_key, $hub_url, $state );
				}
			} else {
				$this->poll_and_start( $api_key, $hub_url );
			}
		} finally {
			// Keep the cadence lock until its 270-second TTL expires. The separate
			// crawler event and heartbeat fallback can occur in the same cron
			// request; releasing here would allow two crawl batches in one window.
		}
	}

	// ── Poll + claim ─────────────────────────────────────────────────────────────

	private function poll_and_start( $api_key, $hub_url ) {
		$resp = wp_remote_get( $hub_url . '/api/connector/poll', array(
			'headers' => array( 'X-DHC-API-Key' => $api_key ),
			'timeout' => 10,
		) );

		if ( is_wp_error( $resp ) ) {
			$this->record_diagnostic( 'poll_transport_error', array(
				'error_code' => sanitize_key( $resp->get_error_code() ),
			) );
			return;
		}
		$poll_status = (int) wp_remote_retrieve_response_code( $resp );
		if ( 200 !== $poll_status ) {
			$this->record_diagnostic( 204 === $poll_status ? 'idle' : 'poll_http_error', array(
				'http_status' => $poll_status,
			) );
			return; // 503 = feature disabled; 401 = bad key; 204 = no jobs.
		}

		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( empty( $body['job']['id'] ) || empty( $body['job']['offer_token'] ) ) {
			$this->record_diagnostic( 'idle', array( 'http_status' => 200 ) );
			return; // No job queued.
		}

		$job   = $body['job'];
		$config = $job['config'] ?? array();
		$allowed_domain = ! empty( $config['allowed_domain'] )
			? sanitize_text_field( $config['allowed_domain'] )
			: $this->site_host();
		$start_url = ! empty( $config['start_url'] )
			? esc_url_raw( $config['start_url'] )
			: home_url( '/' );

		// A Hub job must be bound to this exact WordPress site. Reject before
		// claiming so a stale/misconfigured website record cannot turn the plugin
		// into a crawler for another host or strand the job in claimed state.
		if ( $this->norm_host( $allowed_domain ) !== $this->norm_host( $this->site_host() ) ||
			 ! $this->is_same_domain( $start_url, $this->site_host() ) ) {
			$this->record_diagnostic( 'offer_domain_mismatch', array(
				'job_id' => sanitize_text_field( $job['id'] ),
			) );
			return;
		}
		$nonce = wp_generate_password( 32, false );

		// Pass the offer_token through unchanged — never modify it.
		$claim_resp = wp_remote_post(
			$hub_url . '/api/connector/jobs/' . rawurlencode( $job['id'] ) . '/claim',
			array(
				'headers' => array(
					'Content-Type'  => 'application/json',
					'X-DHC-API-Key' => $api_key,
				),
				'body'    => wp_json_encode( array(
					'offer_token' => $job['offer_token'],
					'nonce'       => $nonce,
				) ),
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $claim_resp ) ) {
			$this->record_diagnostic( 'claim_transport_error', array(
				'error_code' => sanitize_key( $claim_resp->get_error_code() ),
				'job_id'     => sanitize_text_field( $job['id'] ),
			) );
			return;
		}
		$claim_status = (int) wp_remote_retrieve_response_code( $claim_resp );
		if ( 200 !== $claim_status ) {
			$this->record_diagnostic( 'claim_http_error', array(
				'http_status' => $claim_status,
				'job_id'     => sanitize_text_field( $job['id'] ),
			) );
			return; // Already claimed or offer expired.
		}

		$claim_body = json_decode( wp_remote_retrieve_body( $claim_resp ), true );
		if ( empty( $claim_body['claim_token'] ) ) {
			$this->record_diagnostic( 'claim_token_missing', array(
				'job_id' => sanitize_text_field( $job['id'] ),
			) );
			return;
		}

		$requested_max_pages = max( 1, (int) ( $config['max_pages'] ?? 150 ) );
		$max_pages           = min( $requested_max_pages, self::MAX_PAGES_HARD_CAP );

		// api_key and hub_url are intentionally NOT stored in state.
		// They are loaded fresh from get_option() / DHC_Heartbeat on each tick,
		// reducing the sensitivity of the persisted state.
		$state = array(
			'plugin_version'    => DHC_VERSION,
			'phase'             => 'crawling',
			'job_id'            => sanitize_text_field( $job['id'] ),
			'claim_token'       => sanitize_text_field( $claim_body['claim_token'] ),
			'start_url'         => $start_url,
			'allowed_domain'    => $allowed_domain,
			'max_pages'         => $max_pages,
			'url_queue'         => array( $start_url ),
			'visited'           => array(),
			'chunk_index'       => 0,
			'total_uploaded'    => 0,
			'complete_attempts' => 0,
			'created_at'        => time(),
			'last_progress_at'  => time(),
		);

		update_option( self::STATE_KEY, $state, false );
		$this->record_diagnostic( $requested_max_pages > $max_pages ? 'claimed_with_page_cap' : 'claimed', array(
			'http_status'        => 200,
			'job_id'            => sanitize_text_field( $job['id'] ),
			'requested_pages'    => $requested_max_pages,
			'effective_pages'    => $max_pages,
		) );
		$this->continue_crawl( $api_key, $hub_url, $state );
	}

	// ── Crawl continuation ───────────────────────────────────────────────────────

	private function continue_crawl( $api_key, $hub_url, array $state ) {
		$job_id         = $state['job_id'];
		$claim_token    = $state['claim_token'];
		$allowed_domain = $state['allowed_domain'];
		$max_pages      = $state['max_pages'];
		$url_queue      = $state['url_queue'];
		$visited        = $state['visited'];
		$chunk_index    = $state['chunk_index'];
		$total_uploaded = $state['total_uploaded'];

		// Re-check the persisted site binding on every continuation. This prevents
		// stale or manually altered state from turning the connector into a fetcher
		// for a different host after the original offer was claimed.
		if ( $this->norm_host( $allowed_domain ) !== $this->norm_host( $this->site_host() ) ||
			 ! $this->is_same_domain( $state['start_url'] ?? '', $this->site_host() ) ) {
			delete_option( self::STATE_KEY );
			$this->record_diagnostic( 'state_domain_mismatch', array(
				'job_id' => sanitize_text_field( $job_id ),
			) );
			return;
		}

		$pages_batch = array();
		$budget      = self::PAGES_PER_TICK;
		$deadline    = microtime( true ) + self::FETCH_WALL_CLOCK_BUDGET_SEC;
		$queue_before_tick    = $url_queue;
		$visited_before_tick  = $visited;
		$uploaded_before_tick = $total_uploaded;

		while ( $budget > 0 && ! empty( $url_queue ) && $total_uploaded < $max_pages && microtime( true ) < $deadline ) {
			$fetch_urls = array();
			$batch_limit = min( self::CONCURRENT_FETCH_WORKERS, $max_pages - $total_uploaded );
			while ( count( $fetch_urls ) < $batch_limit && $budget > 0 && ! empty( $url_queue ) ) {
				$url = array_shift( $url_queue );
				if ( in_array( $url, $visited, true ) ) {
					continue;
				}
				$visited[]    = $url;
				$fetch_urls[] = $url;
				$budget--; // Every attempted URL consumes the bounded tick allowance.
			}

			if ( empty( $fetch_urls ) ) {
				continue;
			}

			$page_results = $this->crawl_pages_batch( $fetch_urls, $allowed_domain, $deadline );

			// Honor deletion/replacement of active state while network I/O was in
			// flight. In that case do not upload the completed responses.
			if ( ! $this->crawl_state_is_current( $job_id, $claim_token ) ) {
				$this->record_diagnostic( 'crawl_cancelled', array(
					'job_id' => sanitize_text_field( $job_id ),
				) );
				return;
			}

			// Requests may complete out of order. Consume results in frontier order so
			// page evidence, discovered links, and chunk payloads remain deterministic.
			foreach ( $fetch_urls as $result_index => $url ) {
				$page_data = $page_results[ $result_index ] ?? null;
				if ( null === $page_data ) {
					continue;
				}

				foreach ( $page_data['_raw_links'] as $link ) {
					if ( count( $url_queue ) >= self::MAX_QUEUE_SIZE ) {
						break;
					}
					if ( ! in_array( $link, $visited, true ) && ! in_array( $link, $url_queue, true ) ) {
						$url_queue[] = $link;
					}
				}
				unset( $page_data['_raw_links'] );

				$pages_batch[] = $page_data;
				$total_uploaded++;
				if ( $total_uploaded >= $max_pages ) {
					break;
				}
			}
		}

		// Upload this tick's pages as one chunk.
		if ( ! empty( $pages_batch ) ) {
			$upload = $this->upload_chunk( $api_key, $hub_url, $job_id, $claim_token, $chunk_index, $pages_batch );
			if ( ! empty( $upload['ok'] ) ) {
				$chunk_index++;
				if ( ! empty( $upload['claim_token'] ) ) {
					$claim_token = sanitize_text_field( $upload['claim_token'] );
				}
				$state['claim_token']      = $claim_token;
				$state['last_progress_at'] = time();
			} else {
				// Upload failed — restore the exact pre-tick frontier. Advancing the
				// visited set here would silently lose pages on a transient Hub error.
				$state['url_queue']      = $queue_before_tick;
				$state['visited']        = $visited_before_tick;
				$state['chunk_index']    = $chunk_index;
				$state['total_uploaded'] = $uploaded_before_tick;
				update_option( self::STATE_KEY, $state, false );
				$this->record_diagnostic( 'chunk_upload_failed', array(
					'job_id' => sanitize_text_field( $job_id ),
				) );
				return;
			}
		}

		$done = empty( $url_queue ) || $total_uploaded >= $max_pages;

		if ( $done ) {
			// Blocking completion: verify Hub acknowledges before clearing state.
			$completed = $this->attempt_complete( $api_key, $hub_url, $job_id, $claim_token );
			if ( $completed ) {
				delete_option( self::STATE_KEY );
				$this->record_diagnostic( 'completed', array(
					'job_id' => sanitize_text_field( $job_id ),
				) );
			} else {
				// First completion attempt failed — enter retry phase.
				// State is preserved so crawl data is not lost; next tick retries.
				$state['phase']             = 'completing';
				$state['url_queue']         = array();
				$state['visited']           = $visited;
				$state['chunk_index']       = $chunk_index;
				$state['total_uploaded']    = $total_uploaded;
				$state['complete_attempts'] = 1;
				update_option( self::STATE_KEY, $state, false );
				$this->record_diagnostic( 'completion_pending', array(
					'job_id' => sanitize_text_field( $job_id ),
				) );
			}
		} else {
			$state['url_queue']      = $url_queue;
			$state['visited']        = $visited;
			$state['chunk_index']    = $chunk_index;
			$state['total_uploaded'] = $total_uploaded;
			update_option( self::STATE_KEY, $state, false );
			$this->record_diagnostic( 'crawl_in_progress', array(
				'job_id' => sanitize_text_field( $job_id ),
			) );
			$this->schedule_continuation();
		}
	}

	// ── Completion (blocking + retry) ─────────────────────────────────────────────

	/**
	 * Single blocking completion attempt.
	 *
	 * @return bool true only when Hub acknowledged success (including an
	 *              explicit idempotent-success response); false otherwise.
	 */
	private function attempt_complete( $api_key, $hub_url, $job_id, $claim_token ) {
		$resp = wp_remote_post(
			$hub_url . '/api/connector/jobs/' . rawurlencode( $job_id ) . '/complete',
			array(
				'headers' => array(
					'Content-Type'      => 'application/json',
					'X-DHC-API-Key'     => $api_key,
					'X-DHC-Claim-Token' => $claim_token,
				),
				'body'    => '{}',
				'timeout' => 15,
				'blocking' => true, // Must wait for Hub acknowledgement.
			)
		);

		if ( is_wp_error( $resp ) ) {
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );

		if ( 200 === $code ) {
			return true;
		}

		return false;
	}

	/**
	 * Called on subsequent ticks when a prior completion attempt failed.
	 *
	 * Retries up to MAX_COMPLETE_ATTEMPTS total. After that, discards state so
	 * the Hub reaper can handle the abandoned job. Crawl data already uploaded
	 * to the Hub is not lost — only the finalization signal fails.
	 */
	private function retry_complete( $api_key, $hub_url, array $state ) {
		$attempts = (int) ( $state['complete_attempts'] ?? 1 );

		if ( $attempts >= self::MAX_COMPLETE_ATTEMPTS ) {
			// Terminal: exceeded retry budget. Hub reaper will clean up the job.
			delete_option( self::STATE_KEY );
			$this->record_diagnostic( 'completion_abandoned', array(
				'job_id' => sanitize_text_field( $state['job_id'] ),
			) );
			return;
		}

		$completed = $this->attempt_complete( $api_key, $hub_url, $state['job_id'], $state['claim_token'] );
		if ( $completed ) {
			delete_option( self::STATE_KEY );
			$this->record_diagnostic( 'completed', array(
				'job_id' => sanitize_text_field( $state['job_id'] ),
			) );
		} else {
			$state['complete_attempts'] = $attempts + 1;
			update_option( self::STATE_KEY, $state, false );
			$this->record_diagnostic( 'completion_pending', array(
				'job_id' => sanitize_text_field( $state['job_id'] ),
			) );
		}
	}

	// ── Page fetching and parsing ────────────────────────────────────────────────

	/** Return true only while this exact job/claim still owns active state. */
	private function crawl_state_is_current( $job_id, $claim_token ) {
		$current = get_option( self::STATE_KEY, null );
		return is_array( $current ) &&
			isset( $current['job_id'], $current['claim_token'] ) &&
			hash_equals( (string) $current['job_id'], (string) $job_id ) &&
			hash_equals( (string) $current['claim_token'], (string) $claim_token );
	}

	/**
	 * Fetch and parse a small batch while preserving the caller's URL order.
	 *
	 * @param string[] $urls           Same-site URLs in BFS frontier order.
	 * @param string   $allowed_domain Selected site's authorised host.
	 * @param float    $deadline       Absolute microtime deadline.
	 * @return array<int,array|null> Page data aligned to the input indexes.
	 */
	private function crawl_pages_batch( array $urls, $allowed_domain, $deadline ) {
		$responses = $this->safe_fetch_batch( $urls, $allowed_domain, $deadline );
		$pages     = array();

		foreach ( $urls as $index => $url ) {
			$resp = $responses[ $index ] ?? null;
			if ( null === $resp ) {
				$pages[ $index ] = null;
				continue;
			}
			$pages[ $index ] = $this->crawl_page_response( $url, $resp, $allowed_domain );
		}

		return $pages;
	}

	/**
	 * Concurrently fetch same-site URLs and manually validate every redirect hop.
	 * Results are keyed by the original input index regardless of completion order.
	 */
	private function safe_fetch_batch( array $urls, $allowed_domain, $deadline ) {
		$results = array_fill( 0, count( $urls ), null );
		$pending = array();

		foreach ( array_values( $urls ) as $index => $url ) {
			$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );
			if ( in_array( $scheme, array( 'http', 'https' ), true ) &&
				 ! $this->url_has_credentials( $url ) &&
				 $this->is_same_domain( $url, $allowed_domain ) &&
				 $this->is_crawlable_url( $url ) ) {
				$pending[ $index ] = array( 'url' => $url, 'hop' => 0 );
			}
		}

		while ( ! empty( $pending ) && microtime( true ) < $deadline ) {
			$remaining = max( 1, (int) ceil( $deadline - microtime( true ) ) );
			$timeout   = min( self::PAGE_TIMEOUT, $remaining );
			$round_urls = array();
			foreach ( $pending as $index => $entry ) {
				$round_urls[ $index ] = $entry['url'];
			}

			$round_responses = $this->request_multiple( $round_urls, $timeout, $deadline );
			$next_pending    = array();

			foreach ( $pending as $index => $entry ) {
				$resp = $round_responses[ $index ] ?? null;
				if ( null === $resp ) {
					continue;
				}

				$code = $this->response_code( $resp );
				if ( $code >= 300 && $code < 400 ) {
					$location = trim( $this->response_header( $resp, 'location' ) );
					$next_url = $location ? $this->resolve_url( $entry['url'], $location ) : null;
					$next_hop = (int) $entry['hop'] + 1;
					$scheme   = $next_url ? strtolower( (string) parse_url( $next_url, PHP_URL_SCHEME ) ) : '';

					if ( $next_url && $next_hop <= self::MAX_REDIRECTS &&
						 ! $this->url_has_credentials( $next_url ) &&
						 in_array( $scheme, array( 'http', 'https' ), true ) &&
						 $this->is_same_domain( $next_url, $allowed_domain ) ) {
						$next_pending[ $index ] = array( 'url' => $next_url, 'hop' => $next_hop );
					}
					continue;
				}

				if ( $code > 0 && $code < 400 ) {
					$results[ $index ] = $resp;
				}
			}

			$pending = $next_pending;
		}

		return $results;
	}

	/**
	 * Use WordPress's bundled Requests multi transport, with a serial WP HTTP
	 * fallback for older installations where the bundled class is unavailable.
	 */
	private function request_multiple( array $urls, $timeout, $deadline ) {
		$requests = array();
		$headers  = array(
			'User-Agent' => 'DsquaredHubConnector/' . DHC_VERSION . ' (plugin-crawl)',
			'Accept'     => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
		);
		$options = array(
			'timeout'          => max( 1, (int) $timeout ),
			'connect_timeout'  => min( 5, max( 1, (int) $timeout ) ),
			'follow_redirects' => false,
			'redirects'        => 0,
			'verify'           => apply_filters( 'dhc_crawl_sslverify', true ),
			// Read one byte beyond the parser cap so extraction can distinguish a
			// complete 400 KB response from a truncated response without buffering
			// the remainder of an unexpectedly large page.
			'max_bytes'        => self::MAX_HTML_PARSE_BYTES + 1,
		);

		foreach ( $urls as $index => $url ) {
			$requests[ $index ] = array(
				'url'     => $url,
				'type'    => 'GET',
				'headers' => $headers,
				'data'    => array(),
				'options' => $options,
			);
		}

		$responses = null;
		try {
			// Requests can only provide true bounded concurrency through cURL multi.
			// Its socket fallback may serialize the whole group and exceed the tick
			// deadline, so hosts without cURL use the deadline-aware WP HTTP fallback.
			if ( function_exists( 'curl_multi_init' ) && class_exists( '\\WpOrg\\Requests\\Requests' ) ) {
				$responses = \WpOrg\Requests\Requests::request_multiple( $requests );
			} elseif ( function_exists( 'curl_multi_init' ) && class_exists( 'Requests' ) ) {
				$responses = \Requests::request_multiple( $requests );
			}
		} catch ( \Throwable $error ) {
			$responses = null;
		}

		if ( is_array( $responses ) ) {
			$normalised = array();
			foreach ( $urls as $index => $url ) {
				$response = $responses[ $index ] ?? null;
				if ( is_object( $response ) && isset( $response->status_code ) ) {
					$normalised[ $index ] = array(
						'dhc_code'    => (int) $response->status_code,
						'dhc_headers' => $response->headers ?? array(),
						'dhc_body'    => isset( $response->body ) ? (string) $response->body : '',
					);
				} else {
					$normalised[ $index ] = null;
				}
			}
			return $normalised;
		}

		// Compatibility fallback remains bounded by the same worker batch and
		// timeout. It is deliberately not used when Requests multi is available.
		$fallback = array();
		foreach ( $urls as $index => $url ) {
			if ( microtime( true ) >= $deadline ) {
				$fallback[ $index ] = null;
				continue;
			}
			$remaining = max( 1, (int) ceil( $deadline - microtime( true ) ) );
			$response = wp_remote_get( $url, array(
				'timeout'     => min( max( 1, (int) $timeout ), $remaining ),
				'redirection' => 0,
				'sslverify'   => apply_filters( 'dhc_crawl_sslverify', true ),
				'limit_response_size' => self::MAX_HTML_PARSE_BYTES + 1,
				'headers'     => $headers,
			) );
			$fallback[ $index ] = is_wp_error( $response ) ? null : $response;
		}
		return $fallback;
	}

	private function response_code( $response ) {
		return isset( $response['dhc_code'] )
			? (int) $response['dhc_code']
			: (int) wp_remote_retrieve_response_code( $response );
	}

	private function response_header( $response, $name ) {
		if ( ! isset( $response['dhc_headers'] ) ) {
			return (string) wp_remote_retrieve_header( $response, $name );
		}
		$headers = $response['dhc_headers'];
		if ( is_array( $headers ) ) {
			return isset( $headers[ $name ] ) ? (string) $headers[ $name ] : '';
		}
		if ( $headers instanceof \ArrayAccess && isset( $headers[ $name ] ) ) {
			return (string) $headers[ $name ];
		}
		if ( is_object( $headers ) && method_exists( $headers, 'getValues' ) ) {
			$values = $headers->getValues( $name );
			return is_array( $values ) ? implode( ', ', $values ) : (string) $values;
		}
		return '';
	}

	private function response_body( $response ) {
		return isset( $response['dhc_body'] )
			? (string) $response['dhc_body']
			: (string) wp_remote_retrieve_body( $response );
	}

	/**
	 * Fetch a URL, following redirects with per-hop domain validation.
	 *
	 * Redirect chain contract:
	 * - Only http:// and https:// schemes are followed.
	 * - Every redirect destination is validated against $allowed_domain before
	 *   following. A redirect to a different host is rejected (returns null).
	 * - Maximum MAX_REDIRECTS hops before giving up.
	 * - Redirects that carry userinfo (user:pass@host) are rejected explicitly.
	 *
	 * SSRF note: we trust $allowed_domain because it was set by the Hub admin
	 * (not derived from user input). Private-IP SSRF via open redirects is
	 * mitigated by enforcing that every hop stays on $allowed_domain, which the
	 * Hub operator explicitly authorised.
	 *
	 * @param string $url            Absolute URL to fetch.
	 * @param string $allowed_domain Normalised domain (no www prefix).
	 * @param int    $hop            Current redirect depth (0-based).
	 * @return array|null WP HTTP response array, or null on any error.
	 */
	private function safe_fetch( $url, $allowed_domain, $hop = 0 ) {
		if ( $hop > self::MAX_REDIRECTS ) {
			return null;
		}

		// Only HTTP(S) schemes.
		$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || $this->url_has_credentials( $url ) ) {
			return null;
		}

		$resp = wp_remote_get( $url, array(
			'timeout'     => self::PAGE_TIMEOUT,
			'redirection' => 0, // Handle redirects manually for per-hop domain checks.
			'sslverify'   => apply_filters( 'dhc_crawl_sslverify', true ),
			'limit_response_size' => self::MAX_HTML_PARSE_BYTES + 1,
			'headers'     => array(
				'User-Agent' => 'DsquaredHubConnector/' . DHC_VERSION . ' (plugin-crawl)',
				'Accept'     => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
			),
		) );

		if ( is_wp_error( $resp ) ) {
			return null;
		}

		$code = $this->response_code( $resp );

		if ( $code >= 300 && $code < 400 ) {
			$location = trim( $this->response_header( $resp, 'location' ) );
			if ( empty( $location ) ) {
				return null;
			}

			$abs_location = $this->resolve_url( $url, $location );
			if ( null === $abs_location ) {
				return null;
			}

			// Reject non-HTTP destination schemes.
			$dest_scheme = strtolower( (string) parse_url( $abs_location, PHP_URL_SCHEME ) );
			if ( ! in_array( $dest_scheme, array( 'http', 'https' ), true ) || $this->url_has_credentials( $abs_location ) ) {
				return null;
			}

			// Reject: redirect leaves the allowed domain.
			if ( ! $this->is_same_domain( $abs_location, $allowed_domain ) ) {
				return null;
			}

			return $this->safe_fetch( $abs_location, $allowed_domain, $hop + 1 );
		}

		// 4xx/5xx — skip.
		if ( $code >= 400 ) {
			return null;
		}

		return $resp;
	}

	/**
	 * Fetch one URL and extract SEO fields.
	 *
	 * Returns null for non-HTML, errors, external URLs, or challenge pages.
	 * Returns a page array including '_raw_links' for BFS discovery (removed
	 * before upload in continue_crawl).
	 */
	private function crawl_page( $url, $allowed_domain ) {
		if ( ! $this->is_same_domain( $url, $allowed_domain ) ) {
			return null;
		}
		if ( ! $this->is_crawlable_url( $url ) ) {
			return null;
		}

		$resp = $this->safe_fetch( $url, $allowed_domain );
		if ( null === $resp ) {
			return null;
		}

		return $this->crawl_page_response( $url, $resp, $allowed_domain );
	}

	/** Parse one already-fetched response into the canonical page payload. */
	private function crawl_page_response( $url, $resp, $allowed_domain ) {

		$content_type = strtolower( $this->response_header( $resp, 'content-type' ) );
		if ( strpos( $content_type, 'text/html' ) === false &&
			 strpos( $content_type, 'application/xhtml' ) === false ) {
			return null;
		}

		$html = $this->response_body( $resp );

		// Challenge page detection — CF/WP Engine Bot Fight Mode.
		if ( $this->is_challenge_page( $html ) ) {
			return null;
		}

		$schema_evidence = $this->extract_schema_evidence( $html );

		// Cap HTML for the remaining page parser (prevents memory exhaustion on
		// huge pages). Schema extraction records an explicit failure rather than a
		// false absence when this input limit prevents a complete measurement.
		if ( strlen( $html ) > self::MAX_HTML_PARSE_BYTES ) {
			$html = substr( $html, 0, self::MAX_HTML_PARSE_BYTES );
		}

		$status_code = $this->response_code( $resp );
		return $this->parse_html( $url, $html, $status_code, $allowed_domain, $schema_evidence );
	}

	/**
	 * Extract bounded JSON-LD presence/type evidence from connector-fetched HTML.
	 * No raw markup is persisted. A negative result is emitted only when the full
	 * bounded input was measured successfully; ambiguous inputs return a failure.
	 */
	private function extract_schema_evidence( $html ) {
		$input_limited = strlen( $html ) > self::MAX_HTML_PARSE_BYTES;
		$bounded_html  = $input_limited ? substr( $html, 0, self::MAX_HTML_PARSE_BYTES ) : $html;
		$open_count    = 0;
		$blocks        = array();

		if ( preg_match_all( '/<script\b([^>]*)>/is', $bounded_html, $open_tags, PREG_SET_ORDER ) ) {
			foreach ( $open_tags as $tag ) {
				if ( $this->is_jsonld_script_attributes( $tag[1] ?? '' ) ) {
					$open_count++;
				}
			}
		}

		if ( preg_match_all( '/<script\b([^>]*)>(.*?)<\/script\s*>/is', $bounded_html, $scripts, PREG_SET_ORDER ) ) {
			foreach ( $scripts as $script ) {
				if ( $this->is_jsonld_script_attributes( $script[1] ?? '' ) ) {
					$blocks[] = isset( $script[2] ) ? (string) $script[2] : '';
				}
			}
		}

		if ( $open_count > count( $blocks ) ) {
			return $this->schema_extraction_failure( 'unterminated_script_tag' );
		}
		if ( $open_count > self::MAX_SCHEMA_SCRIPTS ) {
			return $this->schema_extraction_failure( 'script_limit_exceeded' );
		}
		if ( 0 === $open_count ) {
			return $input_limited
				? $this->schema_extraction_failure( 'input_limit_exceeded' )
				: $this->schema_measurement( false, array(), 0 );
		}

		$types      = array();
		$node_count = 0;
		foreach ( $blocks as $block ) {
			if ( strlen( $block ) > self::MAX_SCHEMA_SCRIPT_BYTES ) {
				return $this->schema_extraction_failure( 'script_size_limit_exceeded' );
			}
			$decoded = json_decode( trim( $block ), true );
			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
				return $this->schema_extraction_failure( 'json_parse_error' );
			}
			if ( ! $this->collect_schema_types( $decoded, $types, $node_count ) ) {
				return $this->schema_extraction_failure( 'extraction_failed' );
			}
		}

		return $this->schema_measurement( true, $types, $open_count );
	}

	private function is_jsonld_script_attributes( $attributes ) {
		if ( ! preg_match( '/\btype\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $attributes, $match ) ) {
			return false;
		}
		$type = '';
		foreach ( array_slice( $match, 1 ) as $candidate ) {
			if ( '' !== (string) $candidate ) {
				$type = strtolower( trim( (string) $candidate ) );
				break;
			}
		}
		$type = trim( explode( ';', $type )[0] );
		return 'application/ld+json' === $type;
	}

	private function collect_schema_types( $value, array &$types, &$node_count ) {
		if ( count( $types ) >= self::MAX_SCHEMA_TYPES ) {
			return true;
		}
		$node_count++;
		if ( $node_count > self::MAX_SCHEMA_NODES ) {
			return false;
		}
		if ( ! is_array( $value ) ) {
			return true;
		}

		if ( array_key_exists( '@type', $value ) ) {
			$raw_types = is_array( $value['@type'] ) ? $value['@type'] : array( $value['@type'] );
			foreach ( $raw_types as $type ) {
				if ( ! is_string( $type ) ) {
					continue;
				}
				$type = trim( (string) $type );
				if ( preg_match( '/^[A-Za-z][A-Za-z0-9_.:-]{0,79}$/', $type ) && ! in_array( $type, $types, true ) ) {
					$types[] = $type;
					if ( count( $types ) >= self::MAX_SCHEMA_TYPES ) {
						return true;
					}
				}
			}
		}

		foreach ( $value as $child ) {
			if ( is_array( $child ) && ! $this->collect_schema_types( $child, $types, $node_count ) ) {
				return false;
			}
		}
		return true;
	}

	private function schema_measurement( $present, array $types, $script_count ) {
		return array(
			'version'    => 1,
			'status'     => 'measured',
			'present'    => (bool) $present,
			'types'      => $present ? array_slice( array_values( array_unique( $types ) ), 0, self::MAX_SCHEMA_TYPES ) : array(),
			'provenance' => 'connector_html',
			'scriptCount' => $present ? min( self::MAX_SCHEMA_SCRIPTS, max( 0, (int) $script_count ) ) : 0,
		);
	}

	private function schema_extraction_failure( $reason ) {
		return array(
			'version'    => 1,
			'status'     => 'extraction_failed',
			'present'    => null,
			'types'      => array(),
			'provenance' => 'connector_html',
			'scriptCount' => 0,
			'reason'     => $reason,
		);
	}

	/**
	 * Detect Cloudflare and WP Engine challenge/interstitial pages.
	 *
	 * These are returned when Bot Fight Mode or Global Edge Security blocks
	 * the request. They are NOT real page content and must never be stored.
	 *
	 * If every crawled URL returns a challenge, the wp_remote_get() loopback
	 * assumption (internal DNS bypasses the edge) is wrong for this host.
	 * A fallback using WordPress's internal REST dispatch is required.
	 *
	 * @param string $html Raw HTTP response body.
	 * @return bool true if this looks like a challenge interstitial.
	 */
	private function is_challenge_page( $html ) {
		if ( strlen( $html ) < 200 ) {
			return true; // Too small to be real content.
		}

		// Definitive Cloudflare / WP Engine challenge markers.
		$cf_markers = array(
			'cf-browser-verification',
			'_cf_chl_opt',
			'__cf_chl_f_tk',
			'cf_clearance',
			'DDoS protection by Cloudflare',
			'Bot Management by Cloudflare',
			'Attention Required! | Cloudflare',
		);
		foreach ( $cf_markers as $marker ) {
			if ( strpos( $html, $marker ) !== false ) {
				return true;
			}
		}

		// WP Engine interstitial patterns.
		$wpe_markers = array(
			'WP Engine Site Error',
			'ERR_GATEWAY_BLOCKED',
		);
		foreach ( $wpe_markers as $marker ) {
			if ( strpos( $html, $marker ) !== false ) {
				return true;
			}
		}

		// Generic interstitial: has neither <html> nor <body> and is small.
		// Real pages almost always have these; JS-heavy challenges sometimes don't.
		if ( stripos( $html, '<html' ) === false &&
			 stripos( $html, '<body' ) === false &&
			 strlen( $html ) < 5000 ) {
			return true;
		}

		return false;
	}

	/**
	 * Extract SEO fields from raw HTML.
	 *
	 * Uses regex rather than DOMDocument to avoid loading the php-xml extension
	 * (not available on all shared hosting). Regex is sufficient for the field
	 * set here (title, meta, h1, links) because we target well-known tag patterns
	 * in CMS-generated pages. Result caps match Hub's clampPageArrays() contract:
	 *   headings: 100   internalLinks: 100   externalLinks: 50   images: 50
	 *
	 * The '_raw_links' key contains internal URLs for BFS — removed before upload.
	 */
	private function parse_html( $url, $html, $status_code, $allowed_domain, array $schema_evidence ) {
		$page = array(
			'url'             => $url,
			'statusCode'      => $status_code,
			'title'           => '',
			'metaDescription' => '',
			'h1'              => '',
			'headings'        => array(),
			'canonical'       => '',
			'noindex'         => false,
			'wordCount'       => 0,
			'ogTitle'         => '',
			'ogDescription'   => '',
			'internalLinks'   => array(),
			'externalLinks'   => array(),
			'images'          => array(),
			'schemaEvidence'  => $schema_evidence,
			'issues'          => array( 'critical' => 0, 'warnings' => 0, 'notices' => 0 ),
			// Bounded, presence-only schema evidence. The Hub may use this when a
			// direct validator fetch is challenged, but it cannot claim validity
			// because no JSON-LD bodies or page HTML leave WordPress.
			'schemaEvidence'  => $this->extract_schema_evidence( $html ),
			'_raw_links'      => array(),
		);

		// ── Title ────────────────────────────────────────────────────────────────
		if ( preg_match( '/<title[^>]*>(.*?)<\/title>/si', $html, $m ) ) {
			$page['title'] = $this->clean_text( $m[1], 200 );
		}

		// ── Meta description ─────────────────────────────────────────────────────
		$meta_desc = $this->extract_meta( $html, 'description' );
		if ( null !== $meta_desc ) {
			$page['metaDescription'] = $this->clean_text( $meta_desc, 500 );
		}

		// ── Open Graph ───────────────────────────────────────────────────────────
		$og_title = $this->extract_og( $html, 'og:title' );
		if ( null !== $og_title ) {
			$page['ogTitle'] = $this->clean_text( $og_title, 200 );
		}
		$og_desc = $this->extract_og( $html, 'og:description' );
		if ( null !== $og_desc ) {
			$page['ogDescription'] = $this->clean_text( $og_desc, 500 );
		}

		// ── Canonical ────────────────────────────────────────────────────────────
		if ( preg_match( '/<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']*)["\'][^>]*\/?>/si', $html, $m ) ||
			 preg_match( '/<link[^>]+href=["\']([^"\']*)["\'][^>]+rel=["\']canonical["\'][^>]*\/?>/si', $html, $m ) ) {
			$page['canonical'] = esc_url_raw( trim( $m[1] ) );
		}

		// ── Robots noindex ───────────────────────────────────────────────────────
		$robots_val = $this->extract_meta( $html, 'robots' );
		if ( null !== $robots_val && strpos( strtolower( $robots_val ), 'noindex' ) !== false ) {
			$page['noindex'] = true;
		}

		// ── H1 ───────────────────────────────────────────────────────────────────
		if ( preg_match( '/<h1[^>]*>(.*?)<\/h1>/si', $html, $m ) ) {
			$page['h1'] = $this->clean_text( $m[1], 200 );
		}

		// ── H2–H6 ────────────────────────────────────────────────────────────────
		preg_match_all( '/<(h[2-6])[^>]*>(.*?)<\/\1>/si', $html, $hm, PREG_SET_ORDER );
		foreach ( array_slice( $hm, 0, 100 ) as $hit ) {
			$text = $this->clean_text( $hit[2], 200 );
			if ( $text ) {
				$page['headings'][] = strtoupper( $hit[1] ) . ': ' . $text;
			}
		}

		// ── Word count ───────────────────────────────────────────────────────────
		$no_scripts      = preg_replace( '/<(script|style|noscript)[^>]*>.*?<\/\1>/si', ' ', $html );
		$page['wordCount'] = str_word_count( strip_tags( $no_scripts ) );

		// ── Links ────────────────────────────────────────────────────────────────
		preg_match_all( '/<a[^>]+href=["\']([^"\'#][^"\']*)["\'][^>]*>/si', $html, $lm );
		$page_host  = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );
		$seen_links = array();

		foreach ( $lm[1] as $raw_href ) {
			$raw_href = trim( $raw_href );
			if ( empty( $raw_href ) ) {
				continue;
			}
			$abs = $this->resolve_url( $url, $raw_href );
			if ( null === $abs ) {
				continue;
			}
			// Strip fragment + normalize.
			$abs = preg_replace( '/#[^?]*$/', '', $abs );
			if ( in_array( $abs, $seen_links, true ) ) {
				continue;
			}
			$seen_links[] = $abs;

			$link_host = strtolower( (string) parse_url( $abs, PHP_URL_HOST ) );
			if ( $this->norm_host( $link_host ) === $this->norm_host( $page_host ) ) {
				if ( count( $page['internalLinks'] ) < 100 ) {
					$page['internalLinks'][] = $abs;
				}
				// Add crawlable internal links to BFS candidate list.
				if ( $this->is_crawlable_url( $abs ) && $this->is_same_domain( $abs, $allowed_domain ) ) {
					$page['_raw_links'][] = $abs;
				}
			} else {
				if ( count( $page['externalLinks'] ) < 50 ) {
					$page['externalLinks'][] = $abs;
				}
			}
		}

		// ── Images ───────────────────────────────────────────────────────────────
		preg_match_all( '/<img[^>]+>/si', $html, $im );
		foreach ( array_slice( $im[0], 0, 50 ) as $img_tag ) {
			preg_match( '/src=["\']([^"\']*)["\']/', $img_tag, $sm );
			preg_match( '/alt=["\']([^"\']*)["\']/', $img_tag, $am );
			if ( ! empty( $sm[1] ) ) {
				$page['images'][] = array(
					'src' => esc_url_raw( $sm[1] ),
					'alt' => isset( $am[1] ) ? sanitize_text_field( $am[1] ) : '',
				);
			}
		}

		// ── Issues ───────────────────────────────────────────────────────────────
		if ( empty( $page['title'] ) )           { $page['issues']['critical']++; }
		if ( empty( $page['metaDescription'] ) ) { $page['issues']['warnings']++; }
		if ( empty( $page['h1'] ) )              { $page['issues']['warnings']++; }
		if ( $page['noindex'] )                  { $page['issues']['notices']++; }

		return $page;
	}

	/**
	 * Measure structured-data presence and normalized types without retaining
	 * scripts or full HTML. Any malformed JSON-LD makes the result unknown so a
	 * partial parse can never be presented as a clean, complete measurement.
	 */
	private function extract_schema_evidence( $html ) {
		$types            = array();
		$script_count     = 0;
		$offset           = 0;
		$inspected_bytes  = 0;
		$max_html_bytes   = 2000000;
		$max_script_bytes = 262144;
		$max_total_bytes  = 524288;
		$failure_reason   = '';

		// Fail closed before tokenizing an unexpectedly large document. The crawl
		// can still persist the page's ordinary SEO fields, but Schema remains
		// explicitly unmeasured instead of risking an unbounded parse.
		if ( strlen( $html ) > $max_html_bytes ) {
			return $this->schema_extraction_failed( 'input_limit_exceeded', 0 );
		}

		// Tokenize opening tags with a bounded byte scanner. A regex ending at the
		// first `>` is not HTML-aware: `data-note=">"` used to truncate a valid
		// script tag and turn real JSON-LD into a measured absence. The scanner
		// respects quoted attributes, skips comments and jumps over script bodies.
		// The document-size guard above keeps this linear pass bounded.
		$html_length = strlen( $html );
		while ( $offset < $html_length ) {
			$open_start = strpos( $html, '<', $offset );
			if ( false === $open_start ) break;

			if ( 0 === substr_compare( $html, '<!--', $open_start, 4 ) ) {
				$comment_end = strpos( $html, '-->', $open_start + 4 );
				if ( false === $comment_end ) {
					// An unfinished comment can hide candidate markup. If its bounded
					// tail mentions JSON-LD, absence is not a defensible measurement.
					$tail = substr( $html, $open_start, min( 4096, $html_length - $open_start ) );
					if ( false !== stripos( $tail, 'ld+json' ) || false !== stripos( $tail, '<script' ) ) {
						return $this->schema_extraction_failed( 'unterminated_script_tag', $script_count );
					}
					break;
				}
				$offset = $comment_end + 3;
				continue;
			}

			$name_start = $open_start + 1;
			while ( $name_start < $html_length && ctype_space( $html[ $name_start ] ) ) $name_start++;
			if ( $name_start >= $html_length || in_array( $html[ $name_start ], array( '/', '!', '?' ), true ) ) {
				$offset = $open_start + 1;
				continue;
			}
			$name_end = $name_start;
			while ( $name_end < $html_length && preg_match( '/[A-Za-z0-9:-]/', $html[ $name_end ] ) ) $name_end++;
			$tag_name = strtolower( substr( $html, $name_start, $name_end - $name_start ) );
			if ( '' === $tag_name ) {
				$offset = $open_start + 1;
				continue;
			}

			$quote = '';
			$tag_end = false;
			for ( $i = $name_end; $i < $html_length; $i++ ) {
				$char = $html[ $i ];
				if ( '' !== $quote ) {
					if ( $char === $quote ) $quote = '';
					continue;
				}
				if ( '"' === $char || "'" === $char ) {
					$quote = $char;
					continue;
				}
				if ( '>' === $char ) {
					$tag_end = $i;
					break;
				}
			}
			if ( false === $tag_end ) {
				if ( 'script' === $tag_name || false !== stripos( substr( $html, $open_start, min( 4096, $html_length - $open_start ) ), 'ld+json' ) ) {
					return $this->schema_extraction_failed( 'unterminated_script_tag', $script_count );
				}
				break;
			}
			$offset = $tag_end + 1;
			if ( 'script' !== $tag_name ) continue;

			$attrs      = substr( $html, $name_end, $tag_end - $name_end );
			$body_start = $tag_end + 1;
			$close_start = stripos( $html, '</script', $body_start );
			$close_end   = false === $close_start ? false : strpos( $html, '>', $close_start );
			$type_attr = $this->parse_script_type_attribute( $attrs );
			if ( $type_attr['malformed'] ) {
				return $this->schema_extraction_failed( 'ambiguous_script_type', $script_count );
			}
			$type_value = $type_attr['found'] ? $type_attr['value'] : null;
			$looks_jsonld = false;
			if ( null !== $type_value ) {
				$mime = strtolower( trim( explode( ';', html_entity_decode( $type_value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), 2 )[0] ) );
				$looks_jsonld = 'application/ld+json' === $mime;
			}
			if ( false === $close_start || false === $close_end ) {
				if ( $looks_jsonld ) return $this->schema_extraction_failed( 'unterminated_jsonld', $script_count + 1 );
				break;
			}
			$offset = $close_end + 1;
			if ( ! $looks_jsonld ) continue;

			$script_count++;
			if ( $script_count > 20 ) return $this->schema_extraction_failed( 'script_limit_exceeded', 20 );
			$body_length = $close_start - $body_start;
			if ( $body_length > $max_script_bytes || $inspected_bytes + $body_length > $max_total_bytes ) {
				return $this->schema_extraction_failed( 'script_size_limit_exceeded', $script_count );
			}
			$inspected_bytes += $body_length;
			$body = substr( $html, $body_start, $body_length );
			$decoded = json_decode( html_entity_decode( trim( $body ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), true );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				$failure_reason = 'json_parse_error';
				break;
			}
			$this->collect_schema_types( $decoded, $types );
		}

		if ( $failure_reason ) return $this->schema_extraction_failed( $failure_reason, $script_count );

		// Microdata is presence/type evidence too. itemtype URLs are reduced to
		// their final fragment/path component and subjected to the same cap.
		preg_match_all( '/\bitemtype\s*=\s*["\']([^"\']+)["\']/si', $html, $microdata );
		foreach ( array_slice( $microdata[1] ?? array(), 0, 20 ) as $raw_types ) {
			foreach ( preg_split( '/\s+/', trim( $raw_types ) ) as $raw_type ) {
				$type = preg_replace( '/^.*[\/#]/', '', $raw_type );
				$this->add_schema_type( $type, $types );
			}
		}

		$present = $script_count > 0 || ! empty( $microdata[1] );
		return array(
			'version'     => 1,
			'status'      => 'measured',
			'present'     => $present,
			'types'       => array_values( array_slice( $types, 0, 20 ) ),
			'provenance'  => 'connector_html',
			'scriptCount' => min( 20, $script_count ),
		);
	}

	/**
	 * Parse only real attributes from an already bounded, quote-aware opening
	 * tag. Text such as data-note="type=application/ld+json" is a value of a
	 * different attribute and must never be promoted to the script MIME type.
	 */
	private function parse_script_type_attribute( $attrs ) {
		$length = strlen( $attrs );
		$offset = 0;
		$found = false;
		$value = null;
		$malformed = false;
		while ( $offset < $length ) {
			while ( $offset < $length && ( ctype_space( $attrs[ $offset ] ) || '/' === $attrs[ $offset ] ) ) $offset++;
			if ( $offset >= $length ) break;
			$name_start = $offset;
			while ( $offset < $length && preg_match( '/[A-Za-z0-9_:-]/', $attrs[ $offset ] ) ) $offset++;
			if ( $offset === $name_start ) {
				$offset++;
				continue;
			}
			$name = strtolower( substr( $attrs, $name_start, $offset - $name_start ) );
			while ( $offset < $length && ctype_space( $attrs[ $offset ] ) ) $offset++;
			if ( $offset >= $length || '=' !== $attrs[ $offset ] ) {
				if ( 'type' === $name ) $malformed = true;
				continue;
			}
			$offset++;
			while ( $offset < $length && ctype_space( $attrs[ $offset ] ) ) $offset++;
			$attr_value = '';
			if ( $offset < $length && ( '"' === $attrs[ $offset ] || "'" === $attrs[ $offset ] ) ) {
				$quote = $attrs[ $offset++ ];
				$value_start = $offset;
				while ( $offset < $length && $attrs[ $offset ] !== $quote ) $offset++;
				if ( $offset >= $length ) {
					if ( 'type' === $name ) $malformed = true;
					break;
				}
				$attr_value = substr( $attrs, $value_start, $offset - $value_start );
				$offset++;
			} else {
				$value_start = $offset;
				while ( $offset < $length && ! ctype_space( $attrs[ $offset ] ) ) $offset++;
				$attr_value = substr( $attrs, $value_start, $offset - $value_start );
			}
			if ( 'type' === $name ) {
				if ( $found || '' === trim( $attr_value ) ) $malformed = true;
				$found = true;
				$value = $attr_value;
			}
		}
		return array( 'found' => $found, 'value' => $value, 'malformed' => $malformed );
	}

	private function schema_extraction_failed( $reason, $script_count ) {
		$allowed = array( 'input_limit_exceeded', 'script_limit_exceeded', 'script_size_limit_exceeded', 'unterminated_jsonld', 'unterminated_script_tag', 'ambiguous_script_type', 'json_parse_error' );
		return array(
			'version'     => 1,
			'status'      => 'extraction_failed',
			'present'     => null,
			'types'       => array(),
			'provenance'  => 'connector_html',
			'scriptCount' => max( 0, min( 20, (int) $script_count ) ),
			'reason'      => in_array( $reason, $allowed, true ) ? $reason : 'extraction_failed',
		);
	}

	private function collect_schema_types( $value, array &$types ) {
		if ( count( $types ) >= 20 || ! is_array( $value ) ) return;
		if ( isset( $value['@type'] ) ) {
			foreach ( (array) $value['@type'] as $type ) $this->add_schema_type( $type, $types );
		}
		foreach ( $value as $child ) {
			if ( is_array( $child ) ) $this->collect_schema_types( $child, $types );
			if ( count( $types ) >= 20 ) break;
		}
	}

	private function add_schema_type( $value, array &$types ) {
		if ( count( $types ) >= 20 || ! is_scalar( $value ) ) return;
		$type = sanitize_text_field( (string) $value );
		$type = preg_replace( '/^.*[\/#]/', '', $type );
		$type = substr( trim( $type ), 0, 80 );
		if ( $type && preg_match( '/^[A-Za-z][A-Za-z0-9_.:-]{0,79}$/', $type ) && ! in_array( $type, $types, true ) ) {
			$types[] = $type;
		}
	}

	// ── Hub API calls ─────────────────────────────────────────────────────────────

	private function upload_chunk( $api_key, $hub_url, $job_id, $claim_token, $chunk_index, array $pages ) {
		// Cap pages per chunk to Hub's limit.
		$pages = array_slice( $pages, 0, self::PAGES_PER_CHUNK );

		$resp = wp_remote_post(
			$hub_url . '/api/connector/jobs/' . rawurlencode( $job_id ) . '/chunks',
			array(
				'headers' => array(
					'Content-Type'      => 'application/json',
					'X-DHC-API-Key'     => $api_key,
					'X-DHC-Claim-Token' => $claim_token,
				),
				'body'    => wp_json_encode( array(
					'chunk_index' => $chunk_index,
					'pages'       => $pages,
				) ),
				'timeout' => 20,
			)
		);

		if ( is_wp_error( $resp ) ) {
			return array( 'ok' => false );
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( 200 !== $code || ( empty( $body['ok'] ) && empty( $body['duplicate'] ) ) ) {
			return array( 'ok' => false );
		}

		// Every accepted or idempotent chunk rotates the short-lived claim token.
		// Refuse to advance without it: continuing on the old token would make a
		// large crawl fail unpredictably at the original one-hour boundary.
		if ( empty( $body['claim_token'] ) ) {
			return array( 'ok' => false );
		}
		return array(
			'ok'          => true,
			'duplicate'   => ! empty( $body['duplicate'] ),
			'claim_token' => sanitize_text_field( $body['claim_token'] ),
		);
	}

	// ── HTML helpers ─────────────────────────────────────────────────────────────

	private function extract_meta( $html, $name ) {
		if ( preg_match( '/<meta[^>]+name=["\']' . preg_quote( $name, '/' ) . '["\'][^>]+content=["\']([^"\']*)["\'][^>]*\/?>/si', $html, $m ) ) {
			return $m[1];
		}
		if ( preg_match( '/<meta[^>]+content=["\']([^"\']*)["\'][^>]+name=["\']' . preg_quote( $name, '/' ) . '["\'][^>]*\/?>/si', $html, $m ) ) {
			return $m[1];
		}
		return null;
	}

	private function extract_og( $html, $property ) {
		if ( preg_match( '/<meta[^>]+property=["\']' . preg_quote( $property, '/' ) . '["\'][^>]+content=["\']([^"\']*)["\'][^>]*\/?>/si', $html, $m ) ) {
			return $m[1];
		}
		if ( preg_match( '/<meta[^>]+content=["\']([^"\']*)["\'][^>]+property=["\']' . preg_quote( $property, '/' ) . '["\'][^>]*\/?>/si', $html, $m ) ) {
			return $m[1];
		}
		return null;
	}

	private function clean_text( $raw, $max_len ) {
		$text = html_entity_decode( strip_tags( $raw ), ENT_QUOTES, 'UTF-8' );
		$text = trim( preg_replace( '/\s+/', ' ', $text ) );
		if ( strlen( $text ) > $max_len ) {
			$text = substr( $text, 0, $max_len );
		}
		return $text;
	}

	// ── URL utilities ─────────────────────────────────────────────────────────────

	/**
	 * Resolve a possibly-relative href against a base URL.
	 * Returns null for non-HTTP schemes (mailto, tel, javascript, data).
	 */
	private function resolve_url( $base, $href ) {
		$href = trim( $href );
		if ( empty( $href ) ) {
			return null;
		}
		if ( preg_match( '/^(mailto|tel|javascript|data|#):/i', $href ) ) {
			return null;
		}
		if ( preg_match( '/^https?:\/\//i', $href ) ) {
			return $href;
		}
		if ( 0 === strpos( $href, '//' ) ) {
			$scheme = parse_url( $base, PHP_URL_SCHEME ) ?: 'https';
			return $scheme . ':' . $href;
		}
		$parsed_base = parse_url( $base );
		$scheme      = $parsed_base['scheme'] ?? 'https';
		$host        = $parsed_base['host'] ?? '';
		if ( empty( $host ) ) {
			return null;
		}
		$port_str = isset( $parsed_base['port'] ) ? ':' . $parsed_base['port'] : '';
		$origin   = $scheme . '://' . $host . $port_str;

		if ( 0 === strpos( $href, '/' ) ) {
			return $origin . $href;
		}
		$base_path = isset( $parsed_base['path'] ) ? $parsed_base['path'] : '/';
		$dir       = rtrim( dirname( $base_path ), '/' );
		return $origin . $dir . '/' . $href;
	}

	private function is_same_domain( $url, $allowed_domain ) {
		$host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );
		return $this->norm_host( $host ) === $this->norm_host( $allowed_domain );
	}

	private function url_has_credentials( $url ) {
		$parts = parse_url( $url );
		return is_array( $parts ) && ( isset( $parts['user'] ) || isset( $parts['pass'] ) );
	}

	private function is_crawlable_url( $url ) {
		$skip = array(
			'#/wp-admin#', '#/wp-login\.php#', '#/xmlrpc\.php#',
			'#/feed/?$#', '#/tag/#', '#/author/#', '#\?replytocom=#',
			'#\.xml$#', '#\.pdf$#', '#\.docx?$#', '#\.xlsx?$#',
			'#\.(jpg|jpeg|png|gif|webp|svg|ico|bmp)([?\#]|$)#i',
			'#\.(css|js|woff2?|ttf|eot|otf|mp4|mp3|zip|tar|gz)([?\#]|$)#i',
		);
		foreach ( $skip as $pattern ) {
			if ( preg_match( $pattern, $url ) ) {
				return false;
			}
		}
		return true;
	}

	private function norm_host( $host ) {
		return preg_replace( '/^www\./', '', strtolower( (string) $host ) );
	}

	private function site_host() {
		return strtolower( (string) parse_url( home_url( '/' ), PHP_URL_HOST ) );
	}
}
