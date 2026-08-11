<?php
/**
 * DHC_Crawler — Outbound plugin-pull site crawler (v1.15.0)
 *
 * Polls the Hub every 5 minutes for queued crawl jobs assigned to this
 * website. When a job is found: claims it, crawls the site via internal
 * loopback requests (wp_remote_get bypasses the edge WAF on managed
 * hosting), and uploads page data in bounded chunks.
 *
 * Non-negotiable safety invariants:
 * - NEVER modifies WordPress content, settings, users, plugins, or themes.
 * - All fetching via wp_remote_get() — read-only, no wp_insert_*, update_*, etc.
 * - Only fetches URLs on the job's allowed_domain.
 * - Bounded: honours job.config.max_pages and self::MAX_PAGES_HARD_CAP.
 * - Crawl state persisted in wp_options; survives cron tick interruptions.
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

    /** Pages to crawl per cron tick (keeps each tick well under 30 s) */
    const PAGES_PER_TICK = 20;

    /** Pages per chunk upload — Hub MAX_PAGES_PER_CHUNK is 100 */
    const PAGES_PER_CHUNK = 50;

    /** Absolute upper bound on total pages per job */
    const MAX_PAGES_HARD_CAP = 500;

    /** Seconds before we abandon a stale job state */
    const STATE_TTL_SEC = 3600;

    /** Per-page fetch timeout in seconds */
    const PAGE_TIMEOUT = 15;

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

    public function schedule_poll() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), self::INTERVAL_NAME, self::CRON_HOOK );
        }
    }

    public static function deactivate() {
        $ts = wp_next_scheduled( self::CRON_HOOK );
        if ( $ts ) {
            wp_unschedule_event( $ts, self::CRON_HOOK );
        }
        wp_clear_scheduled_hook( self::CRON_HOOK );
        delete_option( self::STATE_KEY );
    }

    // ── Main cron tick ───────────────────────────────────────────────────────────

    public function run_poll_tick() {
        $api_key = get_option( 'dhc_api_key', '' );
        if ( empty( $api_key ) ) {
            return;
        }

        $hub_url = DHC_Heartbeat::get_hub_url();
        $state   = get_option( self::STATE_KEY, null );

        // Discard state that has been running too long (Hub reaper handles the job).
        if ( $state && ! empty( $state['created_at'] ) ) {
            if ( ( time() - (int) $state['created_at'] ) > self::STATE_TTL_SEC ) {
                delete_option( self::STATE_KEY );
                $state = null;
            }
        }

        if ( $state && ! empty( $state['job_id'] ) ) {
            $this->continue_crawl( $api_key, $hub_url, $state );
        } else {
            $this->poll_and_start( $api_key, $hub_url );
        }
    }

    // ── Poll + claim ─────────────────────────────────────────────────────────────

    private function poll_and_start( $api_key, $hub_url ) {
        $resp = wp_remote_get( $hub_url . '/api/connector/poll', array(
            'headers' => array( 'X-DHC-API-Key' => $api_key ),
            'timeout' => 10,
        ) );

        if ( is_wp_error( $resp ) ) {
            return;
        }
        if ( 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
            return; // 503 = feature disabled; 401 = bad key; either way, skip.
        }

        $body = json_decode( wp_remote_retrieve_body( $resp ), true );
        if ( empty( $body['job']['id'] ) || empty( $body['job']['offer_token'] ) ) {
            return; // No job queued.
        }

        $job   = $body['job'];
        $nonce = wp_generate_password( 32, false );

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
            return;
        }
        if ( 200 !== (int) wp_remote_retrieve_response_code( $claim_resp ) ) {
            return; // Already claimed or offer expired.
        }

        $claim_body = json_decode( wp_remote_retrieve_body( $claim_resp ), true );
        if ( empty( $claim_body['claim_token'] ) ) {
            return;
        }

        $config         = $job['config'] ?? array();
        $max_pages      = min( (int) ( $config['max_pages'] ?? 150 ), self::MAX_PAGES_HARD_CAP );
        $start_url      = ! empty( $config['start_url'] ) ? esc_url_raw( $config['start_url'] ) : home_url( '/' );
        $allowed_domain = ! empty( $config['allowed_domain'] )
            ? sanitize_text_field( $config['allowed_domain'] )
            : $this->site_host();

        $state = array(
            'job_id'         => sanitize_text_field( $job['id'] ),
            'claim_token'    => sanitize_text_field( $claim_body['claim_token'] ),
            'hub_url'        => esc_url_raw( $hub_url ),
            'api_key'        => $api_key,
            'start_url'      => $start_url,
            'allowed_domain' => $allowed_domain,
            'max_pages'      => $max_pages,
            'url_queue'      => array( $start_url ),
            'visited'        => array(),
            'chunk_index'    => 0,
            'total_uploaded' => 0,
            'created_at'     => time(),
        );

        update_option( self::STATE_KEY, $state, false );
        $this->continue_crawl( $api_key, $hub_url, $state );
    }

    // ── Crawl continuation ───────────────────────────────────────────────────────

    private function continue_crawl( $api_key, $hub_url, array $state ) {
        $job_id         = $state['job_id'];
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
                continue; // Skip non-HTML / error / external
            }

            // Discover links and enqueue unseen internal URLs.
            foreach ( $page_data['_raw_links'] as $link ) {
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
            $ok = $this->upload_chunk( $api_key, $hub_url, $job_id, $chunk_index, $pages_batch );
            if ( $ok ) {
                $chunk_index++;
            } else {
                // Upload failed — persist state and retry next tick without advancing.
                $state['url_queue']      = $url_queue;
                $state['visited']        = $visited;
                $state['chunk_index']    = $chunk_index;
                $state['total_uploaded'] = $total_uploaded - count( $pages_batch );
                update_option( self::STATE_KEY, $state, false );
                return;
            }
        }

        $done = empty( $url_queue ) || $total_uploaded >= $max_pages;

        if ( $done ) {
            $this->complete_job( $api_key, $hub_url, $job_id );
            delete_option( self::STATE_KEY );
        } else {
            $state['url_queue']      = $url_queue;
            $state['visited']        = $visited;
            $state['chunk_index']    = $chunk_index;
            $state['total_uploaded'] = $total_uploaded;
            update_option( self::STATE_KEY, $state, false );
        }
    }

    // ── Page fetching and parsing ────────────────────────────────────────────────

    /**
     * Fetch one URL and extract SEO fields.
     * Returns null for non-HTML, errors, or external URLs.
     * Returns a page array with a '_raw_links' key for BFS discovery.
     */
    private function crawl_page( $url, $allowed_domain ) {
        if ( ! $this->is_same_domain( $url, $allowed_domain ) ) {
            return null;
        }
        if ( ! $this->is_crawlable_url( $url ) ) {
            return null;
        }

        $resp = wp_remote_get( $url, array(
            'timeout'     => self::PAGE_TIMEOUT,
            'redirection' => 3,
            'sslverify'   => apply_filters( 'dhc_crawl_sslverify', true ),
            'headers'     => array(
                'User-Agent' => 'DsquaredHubConnector/' . DHC_VERSION . ' (plugin-crawl)',
                'Accept'     => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
            ),
        ) );

        if ( is_wp_error( $resp ) ) {
            return null;
        }

        $status_code = (int) wp_remote_retrieve_response_code( $resp );
        if ( $status_code >= 400 ) {
            return null;
        }

        $content_type = strtolower( (string) wp_remote_retrieve_header( $resp, 'content-type' ) );
        if ( strpos( $content_type, 'text/html' ) === false &&
             strpos( $content_type, 'application/xhtml' ) === false ) {
            return null;
        }

        $html = (string) wp_remote_retrieve_body( $resp );

        // Skip Cloudflare challenge pages (< 2 KB of real content).
        if ( strlen( $html ) < 200 ) {
            return null;
        }

        // Cap HTML for parsing (prevents memory exhaustion on huge pages).
        if ( strlen( $html ) > 400 * 1024 ) {
            $html = substr( $html, 0, 400 * 1024 );
        }

        return $this->parse_html( $url, $html, $status_code, $allowed_domain );
    }

    /**
     * Extract SEO fields from raw HTML.
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
        $no_scripts = preg_replace( '/<(script|style|noscript)[^>]*>.*?<\/\1>/si', ' ', $html );
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
            // Strip fragment + normalize
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
                // Add crawlable internal links to BFS queue candidate list.
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

    private function upload_chunk( $api_key, $hub_url, $job_id, $chunk_index, array $pages ) {
        // Cap pages per chunk to Hub's limit.
        $pages = array_slice( $pages, 0, self::PAGES_PER_CHUNK );

        $resp = wp_remote_post(
            $hub_url . '/api/connector/jobs/' . rawurlencode( $job_id ) . '/chunks',
            array(
                'headers' => array(
                    'Content-Type'  => 'application/json',
                    'X-DHC-API-Key' => $api_key,
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
        // 409 duplicate is acceptable (idempotent re-upload).
        $body = json_decode( wp_remote_retrieve_body( $resp ), true );
        return ! empty( $body['duplicate'] );
    }

    private function complete_job( $api_key, $hub_url, $job_id ) {
        // Fire-and-forget — Hub transitions to finalization_pending.
        wp_remote_post(
            $hub_url . '/api/connector/jobs/' . rawurlencode( $job_id ) . '/complete',
            array(
                'headers' => array(
                    'Content-Type'  => 'application/json',
                    'X-DHC-API-Key' => $api_key,
                ),
                'body'    => '{}',
                'timeout' => 10,
                'blocking' => false,
            )
        );
    }

    // ── HTML helpers ─────────────────────────────────────────────────────────────

    private function extract_meta( $html, $name ) {
        // name=... content=... order
        if ( preg_match( '/<meta[^>]+name=["\']' . preg_quote( $name, '/' ) . '["\'][^>]+content=["\']([^"\']*)["\'][^>]*\/?>/si', $html, $m ) ) {
            return $m[1];
        }
        // content=... name=... order
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
        // Skip non-HTTP schemes.
        if ( preg_match( '/^(mailto|tel|javascript|data|#):/i', $href ) ) {
            return null;
        }
        // Already absolute.
        if ( preg_match( '/^https?:\/\//i', $href ) ) {
            return $href;
        }
        // Protocol-relative.
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

        // Root-relative.
        if ( 0 === strpos( $href, '/' ) ) {
            return $origin . $href;
        }
        // Path-relative — resolve against directory of current page.
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
