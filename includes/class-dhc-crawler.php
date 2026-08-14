<?php
/**
 * DHC_Crawler — Outbound plugin-pull site crawler (v1.15.0, Stage 3 hardened)
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
 * wp_remote_get() calls are made to the site's own public canonical domain.
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

	/** Pages per chunk upload — Hub MAX_PAGES_PER_CHUNK is 100 */
	const PAGES_PER_CHUNK = 50;

	/**
	 * Bound the crawl to eight ticks (40 minutes at the normal cadence).
	 * Hub claim tokens and leases expire after one hour, so accepting the old
	 * 500-page value (25 ticks / 125 minutes) guaranteed expiry mid-crawl.
	 * The remaining 20 minutes cover delayed WP-Cron ticks and completion retries.
	 */
	const MAX_PAGES_HARD_CAP = 160;

	/**
	 * Maximum URL queue depth.
	 * At 500 pages × 4 links/page each URL is ~50 bytes; 2 000 entries ≈ 100 KB.
	 * Prevents dhc_active_crawl from growing unbounded on highly-linked sites.
	 */
	const MAX_QUEUE_SIZE = 2000;

	/** Seconds before we abandon a stale job state */
	const STATE_TTL_SEC = 3300;

	/** Per-page fetch timeout in seconds */
	const PAGE_TIMEOUT = 15;

	/** Maximum redirect hops to follow per URL */
	const MAX_REDIRECTS = 3;

	/**
	 * Maximum completion attempts before treating the job as terminally failed.
	 * Each attempt happens on a separate cron tick (≥ 5 min apart).
	 */
	const MAX_COMPLETE_ATTEMPTS = 3;

	/** @var self|null */
	private static $instance = null;

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
		delete_option( self::STATE_KEY );
		delete_option( self::DIAGNOSTICS_KEY );
		delete_transient( self::LOCK_TRANSIENT );
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
	 * - After STATE_TTL_SEC (1 h) of no ticks, state is discarded and the
	 *   Hub reaper marks the job failed/expired.
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
			$this->record_diagnostic( 'locked' );
			return;
		}
		set_transient( self::LOCK_TRANSIENT, 1, self::LOCK_TTL_SEC );
		$this->record_diagnostic( 'tick_started' );

		try {
			$hub_url = DHC_Heartbeat::get_hub_url();
			$state   = get_option( self::STATE_KEY, null );

			// Discard state that has been running too long.
			if ( is_array( $state ) && ! empty( $state['created_at'] ) ) {
				if ( ( time() - (int) $state['created_at'] ) > self::STATE_TTL_SEC ) {
					$this->record_diagnostic( 'state_expired', array(
						'job_id' => sanitize_text_field( $state['job_id'] ?? '' ),
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
			delete_transient( self::LOCK_TRANSIENT );
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

		$pages_batch = array();
		$budget      = self::PAGES_PER_TICK;

		while ( $budget > 0 && ! empty( $url_queue ) && $total_uploaded < $max_pages ) {
			$url = array_shift( $url_queue );

			if ( in_array( $url, $visited, true ) ) {
				continue;
			}
			$visited[] = $url;

			$page_data = $this->crawl_page( $url, $allowed_domain );
			if ( null === $page_data ) {
				$budget--; // Count attempted pages against tick budget.
				continue;
			}

			// Discover links and enqueue unseen internal URLs (bounded).
			foreach ( $page_data['_raw_links'] as $link ) {
				if ( count( $url_queue ) >= self::MAX_QUEUE_SIZE ) {
					break; // Queue cap reached — stop adding.
				}
				if ( ! in_array( $link, $visited, true ) && ! in_array( $link, $url_queue, true ) ) {
					$url_queue[] = $link;
				}
			}
			unset( $page_data['_raw_links'] ); // Not part of the Hub payload.

			$pages_batch[] = $page_data;
			$total_uploaded++;
			$budget--;
		}

		// Upload this tick's pages as one chunk.
		if ( ! empty( $pages_batch ) ) {
			$ok = $this->upload_chunk( $api_key, $hub_url, $job_id, $claim_token, $chunk_index, $pages_batch );
			if ( $ok ) {
				$chunk_index++;
			} else {
				// Upload failed — persist state and retry next tick without advancing.
				$state['url_queue']      = $url_queue;
				$state['visited']        = $visited;
				$state['chunk_index']    = $chunk_index;
				$state['total_uploaded'] = $total_uploaded - count( $pages_batch );
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
		}
	}

	// ── Completion (blocking + retry) ─────────────────────────────────────────────

	/**
	 * Single blocking completion attempt.
	 *
	 * @return bool true if Hub acknowledged (200 or idempotent 409); false otherwise.
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

		// 409 = job already past the crawling state (e.g. Hub already finalised it
		// from a prior partial response, or admin triggered finalization).
		// Idempotent: treat as success — no need to retry.
		if ( 409 === $code ) {
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

	/**
	 * Fetch a URL, following redirects with per-hop domain validation.
	 *
	 * Redirect chain contract:
	 * - Only http:// and https:// schemes are followed.
	 * - Every redirect destination is validated against $allowed_domain before
	 *   following. A redirect to a different host is rejected (returns null).
	 * - Maximum MAX_REDIRECTS hops before giving up.
	 * - Redirects that carry userinfo (user:pass@host) are rejected by PHP's
	 *   parse_url() returning a host without auth, plus the domain check.
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
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return null;
		}

		$resp = wp_remote_get( $url, array(
			'timeout'     => self::PAGE_TIMEOUT,
			'redirection' => 0, // Handle redirects manually for per-hop domain checks.
			'sslverify'   => apply_filters( 'dhc_crawl_sslverify', true ),
			'headers'     => array(
				'User-Agent' => 'DsquaredHubConnector/' . DHC_VERSION . ' (plugin-crawl)',
				'Accept'     => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
			),
		) );

		if ( is_wp_error( $resp ) ) {
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );

		if ( $code >= 300 && $code < 400 ) {
			$location = trim( (string) wp_remote_retrieve_header( $resp, 'location' ) );
			if ( empty( $location ) ) {
				return null;
			}

			$abs_location = $this->resolve_url( $url, $location );
			if ( null === $abs_location ) {
				return null;
			}

			// Reject non-HTTP destination schemes.
			$dest_scheme = strtolower( (string) parse_url( $abs_location, PHP_URL_SCHEME ) );
			if ( ! in_array( $dest_scheme, array( 'http', 'https' ), true ) ) {
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

		$content_type = strtolower( (string) wp_remote_retrieve_header( $resp, 'content-type' ) );
		if ( strpos( $content_type, 'text/html' ) === false &&
			 strpos( $content_type, 'application/xhtml' ) === false ) {
			return null;
		}

		$html = (string) wp_remote_retrieve_body( $resp );

		// Challenge page detection — CF/WP Engine Bot Fight Mode.
		if ( $this->is_challenge_page( $html ) ) {
			return null;
		}

		// Cap HTML for parsing (prevents memory exhaustion on huge pages).
		if ( strlen( $html ) > 400 * 1024 ) {
			$html = substr( $html, 0, 400 * 1024 );
		}

		$status_code = (int) wp_remote_retrieve_response_code( $resp );
		return $this->parse_html( $url, $html, $status_code, $allowed_domain );
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
	private function parse_html( $url, $html, $status_code, $allowed_domain ) {
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
			'issues'          => array( 'critical' => 0, 'warnings' => 0, 'notices' => 0 ),
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
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( 200 === $code ) {
			return true;
		}
		// 409 duplicate is acceptable (idempotent re-upload on retry).
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		return ! empty( $body['duplicate'] );
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

	private function is_crawlable_url( $url ) {
		$skip = array(
			'#/wp-admin#', '#/wp-login\.php#', '#/xmlrpc\.php#',
			'#/feed/?$#', '#/tag/#', '#/author/#', '#\?replytocom=#',
			'#\.xml$#', '#\.pdf$#', '#\.docx?$#', '#\.xlsx?$#',
			'#\.(jpg|jpeg|png|gif|webp|svg|ico|bmp)([?#]|$)#i',
			'#\.(css|js|woff2?|ttf|eot|otf|mp4|mp3|zip|tar|gz)([?#]|$)#i',
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
