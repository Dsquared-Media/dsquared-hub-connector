<?php
/**
 * Module 5: AI Discovery
 *
 * Makes the website discoverable by AI platforms (ChatGPT, Gemini, Perplexity,
 * Claude, Copilot) by generating machine-readable business profiles, llms.txt,
 * enhanced schema markup, and pinging indexing services.
 *
 * @package Dsquared_Hub_Connector
 * @since   1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DHC_AI_Discovery {

    private static $instance = null;

    const SETTINGS_OPTION       = 'dhc_ai_discovery_llms_settings';
    const SETTINGS_VERSION      = 2;
    const VALIDATION_OPTION     = 'dhc_ai_discovery_last_validation';
    const INDEXNOW_QUEUE_OPTION = 'dhc_ai_discovery_indexnow_queue';
    const INDEXNOW_LAST_OPTION  = 'dhc_ai_discovery_indexnow_last_submit';
    const INDEXNOW_CRON_HOOK    = 'dhc_ai_discovery_flush_indexnow';
    const REGENERATE_CRON_HOOK  = 'dhc_ai_discovery_daily_regenerate';

    public static function init() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /** Convert the textarea/list variants used by every profile editor into one canonical array. */
    private static function normalize_profile_list( $value, $object_keys = array() ) {
        if ( is_string( $value ) ) $value = preg_split( '/\r\n|\r|\n/', $value );
        if ( ! is_array( $value ) ) return array();
        $clean = array();
        foreach ( $value as $item ) {
            if ( is_array( $item ) && $object_keys ) {
                $row = array();
                foreach ( $object_keys as $key ) if ( isset( $item[ $key ] ) && is_scalar( $item[ $key ] ) ) $row[ $key ] = sanitize_text_field( $item[ $key ] );
                if ( ! empty( $row ) ) $clean[] = $row;
            } elseif ( is_scalar( $item ) ) {
                $item = sanitize_text_field( $item );
                if ( '' !== $item ) $clean[] = $item;
            }
        }
        return array_values( $clean );
    }

    /** Preserve service metadata when the visual editor submits the readable name list. */
    private static function normalize_services( $value, $existing = array() ) {
        $existing = self::normalize_profile_list( $existing, array( 'name', 'description', 'price_range' ) );
        $by_name = array();
        foreach ( $existing as $service ) {
            if ( is_array( $service ) && ! empty( $service['name'] ) ) $by_name[ strtolower( $service['name'] ) ] = $service;
        }
        if ( is_string( $value ) ) $value = preg_split( '/\r\n|\r|\n/', $value );
        if ( ! is_array( $value ) ) return array();
        $services = array();
        foreach ( $value as $item ) {
            if ( is_array( $item ) ) {
                $row = self::normalize_profile_list( array( $item ), array( 'name', 'description', 'price_range' ) );
                if ( $row ) {
                    $service = $row[0];
                    $key = strtolower( (string) ( $service['name'] ?? '' ) );
                    $services[] = $key && isset( $by_name[ $key ] ) ? array_merge( $by_name[ $key ], $service ) : $service;
                }
                continue;
            }
            if ( ! is_scalar( $item ) ) continue;
            $name = sanitize_text_field( $item );
            if ( '' === $name ) continue;
            $key = strtolower( $name );
            $services[] = isset( $by_name[ $key ] ) ? $by_name[ $key ] : array( 'name' => $name );
        }
        return array_values( $services );
    }

    /** Every field accepted by the redesigned editor and the authenticated Hub APIs. */
    public static function business_profile_fields() {
        return array(
            'business_name','business_type','schema_type','business_category','alternate_name','slogan','website_url','logo_url','hero_image_url','price_range','description',
            'audience','next_action','conversion_destination','phone','email','contact_url','social_urls','social_profiles',
            'address','street','street_address','city','state','region','zip','postal_code','country','latitude','longitude','maps_url','service_areas','service_areas_text',
            'hours','special_hours','services','services_text','featured_offerings','payment_methods','currencies',
            'year_founded','founders','employee_count','years_in_business','awards','certifications','expertise','languages','proof_points','unique_selling_points','brands','faqs',
            'product_brand','sku','gtin','mpn','product_color','product_material','product_size','offer_price','offer_currency','availability','condition',
            'job_title','works_for','bio','cuisine','menu_url','accepts_reservations','star_rating','medical_specialties',
            'aggregate_rating','review_count','best_rating','reviewer_name','reviewer_rating','review_text','notes','extra_info'
        );
    }

    /** Canonical Business Profile shared by every editor, generator, and JSON-LD output. */
    public static function normalize_business_profile( $incoming, $existing = array() ) {
        $incoming = is_array( $incoming ) ? $incoming : array();
        $profile  = is_array( $existing ) ? $existing : array();
        $aliases  = array(
            'schema_type' => 'business_type', 'street_address' => 'street',
            'region' => 'state', 'postal_code' => 'zip',
            'social_urls' => 'social_profiles', 'proof_points' => 'unique_selling_points',
        );
        foreach ( $aliases as $alias => $canonical ) if ( array_key_exists( $alias, $incoming ) && ! array_key_exists( $canonical, $incoming ) ) $incoming[ $canonical ] = $incoming[ $alias ];
        if ( isset( $incoming['address'] ) && is_array( $incoming['address'] ) ) {
            $address = $incoming['address'];
            foreach ( array( 'streetAddress' => 'street', 'addressLocality' => 'city', 'addressRegion' => 'state', 'postalCode' => 'zip', 'addressCountry' => 'country' ) as $source => $target ) if ( isset( $address[ $source ] ) && ! isset( $incoming[ $target ] ) ) $incoming[ $target ] = $address[ $source ];
            unset( $incoming['address'] );
        }
        if ( isset( $incoming['hours'] ) && is_array( $incoming['hours'] ) ) {
            $hours = array();
            foreach ( $incoming['hours'] as $day => $value ) {
                if ( ! is_scalar( $value ) ) continue;
                $value = sanitize_text_field( $value );
                if ( '' !== $value ) $hours[] = is_string( $day ) ? sanitize_text_field( $day ) . ': ' . $value : $value;
            }
            $incoming['hours'] = implode( '; ', $hours );
        }
        $list_fields = array(
            'services' => array( 'name', 'description', 'price_range' ),
            'service_areas' => array(), 'social_profiles' => array(),
            'certifications' => array(), 'brands' => array(),
            'unique_selling_points' => array(), 'faqs' => array( 'question', 'answer' ),
        );
        foreach ( $list_fields as $field => $keys ) {
            if ( ! array_key_exists( $field, $incoming ) ) continue;
            $profile[ $field ] = 'services' === $field
                ? self::normalize_services( $incoming[ $field ], $profile[ $field ] ?? array() )
                : self::normalize_profile_list( $incoming[ $field ], $keys );
        }
        if ( array_key_exists( 'services_text', $incoming ) && ! array_key_exists( 'services', $incoming ) ) $profile['services'] = self::normalize_services( $incoming['services_text'], $profile['services'] ?? array() );
        if ( array_key_exists( 'service_areas_text', $incoming ) && ! array_key_exists( 'service_areas', $incoming ) ) $profile['service_areas'] = self::normalize_profile_list( $incoming['service_areas_text'] );
        $url_fields = array( 'website_url', 'logo_url', 'hero_image_url', 'contact_url', 'maps_url', 'menu_url' );
        foreach ( $incoming as $field => $value ) {
            if ( isset( $list_fields[ $field ] ) || in_array( $field, array_keys( $aliases ), true ) || 'address' === $field || ! is_scalar( $value ) ) continue;
            if ( in_array( $field, $url_fields, true ) ) $profile[ $field ] = esc_url_raw( $value );
            elseif ( in_array( $field, array( 'description', 'hours', 'special_hours', 'featured_offerings', 'review_text', 'notes', 'extra_info' ), true ) ) $profile[ $field ] = sanitize_textarea_field( $value );
            elseif ( 'email' === $field ) $profile[ $field ] = sanitize_email( $value );
            else $profile[ $field ] = sanitize_text_field( $value );
        }
        foreach ( array( 'street', 'city', 'state', 'zip', 'country' ) as $field ) if ( array_key_exists( $field, $incoming ) ) $profile[ $field ] = sanitize_text_field( $incoming[ $field ] );
        $has_address_parts = false;
        foreach ( array( 'street', 'city', 'state', 'zip', 'country' ) as $field ) if ( array_key_exists( $field, $incoming ) ) $has_address_parts = true;
        if ( $has_address_parts ) {
            $region = trim( (string) ( $profile['state'] ?? '' ) . ' ' . (string) ( $profile['zip'] ?? '' ) );
            $profile['address'] = implode( ', ', array_values( array_filter( array( $profile['street'] ?? '', $profile['city'] ?? '', $region, $profile['country'] ?? '' ) ) ) );
        } elseif ( array_key_exists( 'address', $incoming ) && is_scalar( $incoming['address'] ) ) $profile['address'] = sanitize_text_field( $incoming['address'] );
        $profile['business_type'] = sanitize_text_field( $profile['business_type'] ?? 'LocalBusiness' );
        $profile['schema_type'] = $profile['business_type'];
        $profile['street_address'] = (string) ( $profile['street'] ?? '' );
        $profile['region'] = (string) ( $profile['state'] ?? '' );
        $profile['postal_code'] = (string) ( $profile['zip'] ?? '' );
        $profile['social_urls'] = implode( "\n", (array) ( $profile['social_profiles'] ?? array() ) );
        $profile['proof_points'] = implode( "\n", array_map( function( $item ) { return is_scalar( $item ) ? (string) $item : ''; }, (array) ( $profile['unique_selling_points'] ?? array() ) ) );
        $profile['services_text'] = implode( "\n", array_map( function( $item ) { return is_array( $item ) ? (string) ( $item['name'] ?? '' ) : (string) $item; }, (array) ( $profile['services'] ?? array() ) ) );
        $profile['service_areas_text'] = implode( "\n", (array) ( $profile['service_areas'] ?? array() ) );
        return $profile;
    }

    /** Save one canonical profile while preserving supported legacy fields. */
    public static function save_business_profile_data( $incoming, $replace = false ) {
        $existing = $replace ? array() : get_option( 'dhc_business_profile', get_option( 'dhc_ai_business_profile', array() ) );
        $profile = self::normalize_business_profile( $incoming, $existing );
        update_option( 'dhc_business_profile', $profile, false );
        update_option( 'dhc_ai_business_profile', $profile, false );
        return $profile;
    }

    /**
     * Static wrapper so callers in static context (admin save, Hub sync)
     * can regenerate the physical llms.txt / llms-full.txt files. The admin
     * and sync code called DHC_AI_Discovery::regenerate_files() which never
     * existed (the real method is the instance regenerate_static_files),
     * so files silently never got rewritten. This bridges the two.
     */
    public static function regenerate_files( $profile = null ) {
        return self::init()->regenerate_static_files( $profile );
    }

    public function __construct() {
        // Rewrite rules for llms.txt and llms-full.txt
        add_action( 'init', array( $this, 'add_rewrite_rules' ) );
        // Serve at the EARLIEST possible point on every request lifecycle:
        //   1. parse_request — runs before WP query is built; if the URL is
        //      /llms.txt we short-circuit immediately so nothing else can
        //      override us (themes, security plugins, custom 404 handlers).
        //   2. template_redirect priority 1 — backup for hosts where some
        //      plugin loads earlier than us on parse_request.
        // Earlier deploys hooked template_redirect at default priority 10
        // and a sibling plugin / theme was returning the WP 404 template
        // before our handler could run on ewmacdowellroofing.com.
        add_action( 'parse_request', array( $this, 'maybe_serve_llms_txt_early' ), 1 );
        add_action( 'template_redirect', array( $this, 'serve_llms_txt' ), 1 );

        // Inject schema into wp_head
        add_action( 'wp_head', array( $this, 'inject_ai_schema' ), 1 );

        // Rebuild discovery files and queue IndexNow for public pages/posts.
        add_action( 'save_post', array( $this, 'on_content_update' ), 20, 3 );
        add_action( 'trashed_post', array( $this, 'on_content_removed' ), 20, 1 );
        add_action( 'untrashed_post', array( $this, 'on_content_removed' ), 20, 1 );
        add_action( 'update_option_dhc_redirects', array( $this, 'on_redirects_changed' ), 10, 3 );
        add_action( self::INDEXNOW_CRON_HOOK, array( $this, 'flush_indexnow_queue' ) );
        add_action( self::REGENERATE_CRON_HOOK, array( $this, 'regenerate_static_files' ) );

        // REST endpoint for saving business profile from Hub
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );

        // Add .well-known/ai-plugin.json
        add_action( 'template_redirect', array( $this, 'serve_ai_plugin_json' ), 1 );

        // Generate IndexNow key file
        add_action( 'template_redirect', array( $this, 'serve_indexnow_key' ), 1 );

        // Run after Yoast and other SEO plugins so we can append valid groups
        // without placing orphan directives above their User-agent block.
        add_filter( 'robots_txt', array( $this, 'add_robots_entries' ), 999, 2 );

        if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::REGENERATE_CRON_HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::REGENERATE_CRON_HOOK );
        }
    }

    /**
     * Earliest-possible llms.txt handler.
     *
     * Runs on `parse_request` BEFORE WordPress has even decided what
     * post/page/404 the URL maps to. This is the only spot guaranteed
     * to run before themes and other plugins can hijack the response.
     * If the path matches /llms.txt or /llms-full.txt we serve the
     * file content and exit — no further WP processing.
     */
    /**
     * Write llms.txt and llms-full.txt as physical files in the WP root.
     *
     * Some hosts (including ewmacdowellroofing.com) ship an nginx
     * config with `location ~* \.txt$ { try_files $uri =404; }` which
     * serves .txt files only from disk and NEVER proxies to PHP. In
     * that environment our parse_request / template_redirect handlers
     * never run for /llms.txt — nginx returns its own 404 page before
     * WordPress sees the request.
     *
     * Writing the files to ABSPATH satisfies nginx's try_files lookup
     * and works regardless of host config. We regenerate them whenever
     * the business profile is saved.
     *
     * Returns array of written file paths or WP_Error on failure.
     */
    public function regenerate_static_files( $profile = null ) {
        $written = array();
        if ( ! $profile ) {
            $profile = get_option( 'dhc_business_profile', array() );
            if ( empty( $profile ) ) $profile = $this->build_fallback_profile();
        }
        if ( empty( $profile['business_name'] ) && empty( $profile['description'] ) ) {
            return new WP_Error( 'empty_profile', 'No business profile to write.' );
        }

        $rendered = $this->render_files( $profile, $this->get_editor_settings() );
        $files = array(
            ABSPATH . 'llms.txt'      => $rendered['llms'],
            ABSPATH . 'llms-full.txt' => $rendered['full'],
        );

        $failed = array();
        foreach ( $files as $path => $content ) {
            $ok = @file_put_contents( $path, $content );
            if ( $ok !== false ) {
                $written[] = $path;
                @chmod( $path, 0644 );
                $ownership = get_option( 'dhc_generated_file_ownership', array() );
                if ( ! is_array( $ownership ) ) $ownership = array();
                $ownership[ basename( $path ) ] = hash( 'sha256', $content );
                update_option( 'dhc_generated_file_ownership', $ownership, false );
            } else {
                $failed[] = $path;
            }
        }

        // Ensure nginx/Apache serves the physical .txt files as UTF-8.
        // Without this, servers that don't add a charset header will render
        // smart quotes and em dashes as mojibake (â€" instead of —).
        $htaccess = ABSPATH . '.htaccess';
        $marker   = '# DHC: force UTF-8 charset on llms txt files';
        $block    = "\n{$marker}\n<FilesMatch \"^llms(?:-full)?\\.txt$\">\n    AddCharset UTF-8 .txt\n    <IfModule mod_headers.c>\n        Header set Content-Type \"text/plain; charset=utf-8\"\n    </IfModule>\n</FilesMatch>\n# /DHC: force UTF-8 charset\n";
        $current  = @file_get_contents( $htaccess );
        if ( $current !== false ) {
            $pattern = '/\\n?# DHC: force UTF-8 charset on llms txt files.*?# \/DHC: force UTF-8 charset\\n?/s';
            $next = preg_replace( $pattern, "\n", $current ) . $block;
            if ( $next !== $current ) @file_put_contents( $htaccess, $next );
        }

        if ( ! empty( $failed ) ) {
            return new WP_Error(
                'write_failed',
                'Could not write every AI Discovery file. Check WP-root write permissions.',
                array( 'written' => $written, 'failed' => $failed )
            );
        }
        return $written;
    }

    /** Return one versioned editor option and retain legacy raw content safely. */
    public function get_editor_settings() {
        $stored = get_option( self::SETTINGS_OPTION, array() );
        $legacy = get_option( 'dhc_llms_txt_raw', '' );
        $defaults = array(
            'version'             => self::SETTINGS_VERSION,
            'mode'                => 'auto',
            'links'               => array(),
            'custom_content'      => is_string( $legacy ) ? $legacy : '',
            'full_manual'         => false,
            'full_custom_content' => '',
            'updated_at'          => '',
        );
        if ( ! is_array( $stored ) ) $stored = array();
        return array_merge( $defaults, $stored );
    }

    public function migrate_editor_settings() {
        return self::migrate_editor_settings_option();
    }

    /** Migrate stored editor data without initializing any gated runtime hooks. */
    public static function migrate_editor_settings_option() {
        $stored = get_option( self::SETTINGS_OPTION, null );
        if ( is_array( $stored ) && intval( $stored['version'] ?? 0 ) >= self::SETTINGS_VERSION ) return $stored;
        $legacy = get_option( 'dhc_llms_txt_raw', '' );
        $defaults = array(
            'version' => self::SETTINGS_VERSION, 'mode' => 'auto', 'links' => array(),
            'custom_content' => is_string( $legacy ) ? $legacy : '',
            'full_manual' => false, 'full_custom_content' => '', 'updated_at' => '',
        );
        $settings = array_merge( $defaults, is_array( $stored ) ? $stored : array() );
        if ( is_string( $legacy ) && trim( $legacy ) !== '' ) {
            // Preserve the existing curated text while adding the verified
            // page links introduced by the new editor.
            $settings['mode'] = 'append';
        }
        $settings['version'] = self::SETTINGS_VERSION;
        $settings['updated_at'] = current_time( 'mysql', true );
        update_option( self::SETTINGS_OPTION, $settings, false );
        // get_editor_settings copied the legacy raw text into custom_content.
        // Append mode rebuilds the public file with verified links while
        // retaining the prior curated text exactly.
        delete_option( 'dhc_llms_txt_raw' );
        return $settings;
    }

    /** Sanitize editor settings from wp-admin or the authenticated Hub API. */
    public function sanitize_editor_settings( $input ) {
        if ( ! is_array( $input ) ) $input = array();
        $mode = sanitize_key( $input['mode'] ?? 'auto' );
        if ( ! in_array( $mode, array( 'auto', 'append', 'manual' ), true ) ) $mode = 'auto';
        $allowed_sections = array( 'Key Pages', 'Services', 'Locations', 'Blog/Resources', 'Contact' );
        $links = array();
        foreach ( (array) ( $input['links'] ?? array() ) as $index => $link ) {
            if ( ! is_array( $link ) ) continue;
            $url = esc_url_raw( trim( (string) ( $link['url'] ?? '' ) ) );
            $title = sanitize_text_field( $link['title'] ?? '' );
            if ( ! $url || ! $title ) continue;
            $section = sanitize_text_field( $link['section'] ?? 'Key Pages' );
            if ( ! in_array( $section, $allowed_sections, true ) ) $section = 'Key Pages';
            $links[] = array(
                'title'       => $title,
                'url'         => $url,
                'description' => sanitize_text_field( $link['description'] ?? '' ),
                'section'     => $section,
                'sort_order'  => max( 0, min( 9999, intval( $link['sort_order'] ?? $index ) ) ),
                'enabled'     => ! empty( $link['enabled'] ),
            );
        }
        usort( $links, function( $a, $b ) {
            return ( $a['sort_order'] <=> $b['sort_order'] ) ?: strcmp( $a['title'], $b['title'] );
        } );
        return array(
            'version'             => self::SETTINGS_VERSION,
            'mode'                => $mode,
            'links'               => array_slice( $links, 0, 100 ),
            'custom_content'      => $this->sanitize_manual_content( $input['custom_content'] ?? '' ),
            'full_manual'         => ! empty( $input['full_manual'] ),
            'full_custom_content' => $this->sanitize_manual_content( $input['full_custom_content'] ?? '' ),
            'updated_at'          => current_time( 'mysql', true ),
        );
    }

    private function sanitize_manual_content( $content ) {
        // Admin JSON is unslashed before it reaches this method and REST JSON
        // is already decoded. Unslashing again would corrupt legitimate
        // backslashes in manually authored Markdown.
        $content = str_replace( array( "\r\n", "\r", "\0" ), array( "\n", "\n", '' ), (string) $content );
        return trim( wp_check_invalid_utf8( $content ) );
    }

    public function save_editor_settings( $input, $source = 'admin' ) {
        $settings = $this->sanitize_editor_settings( $input );
        update_option( self::SETTINGS_OPTION, $settings, false );
        // The versioned editor is authoritative now. The legacy option is
        // retained inside custom_content but may no longer override output.
        delete_option( 'dhc_llms_txt_raw' );
        // First validate every rendered public URL over HTTP. The results are
        // cached, then regeneration omits any URL that failed the live check.
        $candidate = $this->render_files( null, $settings );
        $this->validate_rendered_files( $candidate, true );
        $result = $this->regenerate_static_files();
        $rendered = $this->render_files( null, $settings );
        $validation = $this->validate_rendered_files( $rendered, false );
        update_option( self::VALIDATION_OPTION, $validation, false );
        $this->log_activity( 'AI Discovery editor saved and files regenerated (' . sanitize_key( $source ) . ')', 'hub' === sanitize_key( $source ) ? 'hub' : 'wordpress' );
        return array( 'settings' => $settings, 'rendered' => $rendered, 'validation' => $validation, 'files' => $result );
    }

    public function reset_editor_settings( $source = 'admin' ) {
        $settings = $this->sanitize_editor_settings( array( 'mode' => 'auto', 'links' => array() ) );
        update_option( self::SETTINGS_OPTION, $settings, false );
        delete_option( 'dhc_llms_txt_raw' );
        $candidate = $this->render_files( null, $settings );
        $this->validate_rendered_files( $candidate, true );
        $result = $this->regenerate_static_files();
        $rendered = $this->render_files( null, $settings );
        $validation = $this->validate_rendered_files( $rendered, false );
        update_option( self::VALIDATION_OPTION, $validation, false );
        $this->log_activity( 'AI Discovery editor reset to auto (' . sanitize_key( $source ) . ')', 'hub' === sanitize_key( $source ) ? 'hub' : 'wordpress' );
        return array( 'settings' => $settings, 'rendered' => $rendered, 'validation' => $validation, 'files' => $result );
    }

    public function render_files( $profile = null, $settings = null ) {
        if ( ! is_array( $profile ) ) {
            $profile = get_option( 'dhc_business_profile', array() );
            if ( empty( $profile ) ) $profile = $this->build_fallback_profile();
        }
        $profile = self::normalize_business_profile( $profile );
        if ( ! is_array( $settings ) ) $settings = $this->get_editor_settings();
        $auto = $this->generate_llms_summary( $profile, $settings );
        $full = $this->generate_llms_full( $profile, $settings );
        if ( 'manual' === $settings['mode'] ) {
            $llms = (string) $settings['custom_content'];
        } elseif ( 'append' === $settings['mode'] && trim( (string) $settings['custom_content'] ) !== '' ) {
            $llms = rtrim( $auto ) . "\n\n" . trim( $settings['custom_content'] ) . "\n";
        } else {
            $llms = $auto;
        }
        if ( ! empty( $settings['full_manual'] ) && trim( (string) $settings['full_custom_content'] ) !== '' ) {
            $full = trim( $settings['full_custom_content'] ) . "\n";
        }
        return array( 'llms' => $this->normalize_generated_text( $llms, 'manual' === $settings['mode'] ),
            'full' => $this->normalize_generated_text( $full, ! empty( $settings['full_manual'] ) ) );
    }

    private function normalize_generated_text( $text, $manual = false ) {
        $text = str_replace( array( "\r\n", "\r", "\0" ), array( "\n", "\n", '' ), (string) $text );
        if ( ! $manual ) {
            // Static-file accelerators sometimes omit charset. Keep generated
            // punctuation ASCII-safe while dynamic responses still declare UTF-8.
            $text = str_replace( array( '—', '–', '“', '”', '‘', '’', "\xC2\xA0" ), array( '-', '-', '"', '"', "'", "'", ' ' ), $text );
        }
        return rtrim( wp_check_invalid_utf8( $text ) ) . "\n";
    }

    public function maybe_serve_llms_txt_early( $wp ) {
        if ( ! isset( $_SERVER['REQUEST_URI'] ) ) return;
        $raw_uri = (string) $_SERVER['REQUEST_URI'];
        $path    = parse_url( $raw_uri, PHP_URL_PATH );
        if ( ! is_string( $path ) ) return;
        $uri = strtolower( trim( $path, '/' ) );
        if ( $uri !== 'llms.txt' && $uri !== 'llms-full.txt' ) return;

        $is_full = ( $uri === 'llms-full.txt' );

        $profile = get_option( 'dhc_business_profile', array() );
        if ( empty( $profile ) ) {
            $profile = $this->build_fallback_profile();
            if ( empty( $profile['business_name'] ) && empty( $profile['description'] ) ) {
                status_header( 404 );
                header( 'Content-Type: text/plain; charset=utf-8' );
                echo "# No business profile configured.\n# Set up AI Discovery in the Dsquared Hub Connector settings.";
                exit;
            }
        }

        status_header( 200 );
        header( 'Content-Type: text/plain; charset=utf-8' );
        header( 'X-Robots-Tag: noindex' );
        header( 'Cache-Control: public, max-age=3600' );
        $rendered = $this->render_files( $profile, $this->get_editor_settings() );
        echo $is_full ? $rendered['full'] : $rendered['llms'];
        exit;
    }

    /* ─── Rewrite Rules ─── */

    public function add_rewrite_rules() {
        add_rewrite_rule( '^llms\.txt$', 'index.php?dhc_llms=1', 'top' );
        add_rewrite_rule( '^llms-full\.txt$', 'index.php?dhc_llms_full=1', 'top' );
        add_rewrite_rule( '^\.well-known/ai-plugin\.json$', 'index.php?dhc_ai_plugin=1', 'top' );

        add_rewrite_tag( '%dhc_llms%', '1' );
        add_rewrite_tag( '%dhc_llms_full%', '1' );
        add_rewrite_tag( '%dhc_ai_plugin%', '1' );
    }

    /* ─── Serve llms.txt ─── */

    public function serve_llms_txt() {
        global $wp_query;

        $is_llms      = get_query_var( 'dhc_llms' );
        $is_llms_full = get_query_var( 'dhc_llms_full' );

        if ( ! $is_llms && ! $is_llms_full ) {
            // Fallback: check REQUEST_URI directly. Previously this used a
            // strict string compare which missed URLs with trailing query
            // strings (e.g. /llms.txt?utm_source=…) and cached prefixes.
            // Strip the path only, then normalize.
            $raw_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
            $path    = parse_url( $raw_uri, PHP_URL_PATH );
            $uri     = strtolower( trim( (string) $path, '/' ) );
            if ( $uri === 'llms.txt' ) {
                $is_llms = true;
            } elseif ( $uri === 'llms-full.txt' ) {
                $is_llms_full = true;
            } else {
                return;
            }
        }

        $profile = get_option( 'dhc_business_profile', array() );
        // Fall back to a site-metadata-derived profile so /llms.txt is
        // always a valid, useful response even before the user has filled
        // in the AI Discovery settings. Returning 404 here was causing the
        // plugin to look broken on fresh installs — AI crawlers can't
        // discover anything about the business when the URL is dead.
        if ( empty( $profile ) ) {
            $profile = $this->build_fallback_profile();
            // If even the fallback is completely empty (totally fresh WP
            // install with no title/tagline), THEN 404 is appropriate.
            if ( empty( $profile['business_name'] ) && empty( $profile['description'] ) ) {
                status_header( 404 );
                header( 'Content-Type: text/plain; charset=utf-8' );
                echo "# No business profile configured.\n# Set up AI Discovery in the Dsquared Hub Connector settings.";
                exit;
            }
        }

        status_header( 200 );
        if ( isset( $wp_query ) && is_object( $wp_query ) ) $wp_query->is_404 = false;
        header( 'Content-Type: text/plain; charset=utf-8' );
        header( 'X-Robots-Tag: noindex' );
        header( 'Cache-Control: public, max-age=3600' );

        $rendered = $this->render_files( $profile, $this->get_editor_settings() );
        echo $is_llms_full ? $rendered['full'] : $rendered['llms'];
        exit;
    }

    /* ─── Build a fallback profile from site metadata ─── */
    /*
     * Used when no business profile has been saved yet so /llms.txt is
     * still a valid response instead of a 404. Pulls from:
     *   1. WordPress options (blogname, blogdescription, admin_email)
     *   2. schema.org LocalBusiness / Organization JSON-LD on the homepage
     *   3. An existing dhc_ai_business_profile row if it exists
     * Whatever comes back gets cached in the `dhc_business_profile` option
     * so subsequent hits don't re-scrape the homepage every time.
     */
    private function build_fallback_profile() {
        $cached = get_transient( 'dhc_fallback_profile_v1' );
        if ( is_array( $cached ) ) return $cached;

        $profile = array(
            'business_name' => get_bloginfo( 'name' ),
            'description'   => get_bloginfo( 'description' ),
            'phone'         => '',
            'address'       => '',
            'email'         => '',
            'services'      => array(),
            'service_areas' => array(),
            'hours'         => '',
            'extra_info'    => '',
        );

        // Try to enrich from homepage schema.org JSON-LD. One synchronous
        // HTTP round-trip against our own origin; cached for 6 hours.
        $home = home_url( '/' );
        $resp = wp_remote_get( $home, array( 'timeout' => 6, 'redirection' => 2 ) );
        if ( ! is_wp_error( $resp ) && wp_remote_retrieve_response_code( $resp ) === 200 ) {
            $html = wp_remote_retrieve_body( $resp );
            if ( ! empty( $html ) && class_exists( 'DOMDocument' ) ) {
                libxml_use_internal_errors( true );
                $doc = new DOMDocument();
                $doc->loadHTML( '<?xml encoding="UTF-8">' . $html );
                libxml_clear_errors();
                $xpath = new DOMXPath( $doc );
                $jsonlds = $xpath->query( '//script[@type="application/ld+json"]' );
                foreach ( $jsonlds as $s ) {
                    $decoded = json_decode( $s->textContent, true );
                    if ( ! is_array( $decoded ) ) continue;
                    $nodes = isset( $decoded['@graph'] ) && is_array( $decoded['@graph'] ) ? $decoded['@graph'] : array( $decoded );
                    foreach ( $nodes as $n ) {
                        if ( ! is_array( $n ) ) continue;
                        $type = $n['@type'] ?? '';
                        if ( is_array( $type ) ) $type = implode( ',', $type );
                        if ( stripos( $type, 'LocalBusiness' ) === false
                            && stripos( $type, 'Organization' ) === false
                            && stripos( $type, 'ProfessionalService' ) === false ) continue;
                        if ( ! empty( $n['name'] ) )        $profile['business_name'] = (string) $n['name'];
                        if ( ! empty( $n['description'] ) ) $profile['description']   = (string) $n['description'];
                        if ( ! empty( $n['telephone'] ) )   $profile['phone']         = (string) $n['telephone'];
                        if ( ! empty( $n['email'] ) )       $profile['email']         = (string) $n['email'];
                        if ( ! empty( $n['address'] ) && is_array( $n['address'] ) ) {
                            $addr  = $n['address'];
                            $parts = array_filter( array(
                                $addr['streetAddress']   ?? '',
                                $addr['addressLocality'] ?? '',
                                ( $addr['addressRegion'] ?? '' ) . ' ' . ( $addr['postalCode'] ?? '' ),
                            ) );
                            $profile['address'] = trim( implode( ', ', array_map( 'trim', $parts ) ) );
                        }
                        if ( ! empty( $n['openingHours'] ) ) {
                            $profile['hours'] = is_array( $n['openingHours'] )
                                ? implode( '; ', $n['openingHours'] )
                                : (string) $n['openingHours'];
                        }
                        if ( ! empty( $n['areaServed'] ) && is_array( $n['areaServed'] ) ) {
                            $areas = array();
                            foreach ( $n['areaServed'] as $a ) {
                                if ( is_string( $a ) ) { $areas[] = $a; continue; }
                                if ( is_array( $a ) && ! empty( $a['name'] ) ) $areas[] = (string) $a['name'];
                            }
                            if ( ! empty( $areas ) ) $profile['service_areas'] = $areas;
                        }
                    }
                }
            }
        }

        set_transient( 'dhc_fallback_profile_v1', $profile, 6 * HOUR_IN_SECONDS );
        return $profile;
    }

    /* ─── Link discovery, validation, and rendering ─── */

    private function post_exclusion_reason( $post ) {
        if ( ! $post ) return 'unpublished';
        if ( in_array( (string) $post->post_type, array( 'attachment', 'elementor_library' ), true ) ) {
            return 'elementor_library' === $post->post_type ? 'elementor_library' : 'attachment';
        }
        if ( 'publish' !== $post->post_status ) return 'unpublished';
        // llms auto-discovery is intentionally narrower than WordPress' broad
        // definition of a public post type. Only normal pages and posts belong
        // in the files; templates and plugin content must never leak into them.
        if ( ! in_array( (string) $post->post_type, array( 'page', 'post' ), true ) ) return 'unsupported_post_type';
        $type = get_post_type_object( $post->post_type );
        if ( ! $type || empty( $type->public ) || empty( $type->publicly_queryable ) ) return 'non_public_post_type';
        if ( preg_match( '/(^|[-_])thank[-_]?you($|[-_])/i', (string) $post->post_name ) ) return 'thank_you';
        $yoast = strtolower( trim( (string) get_post_meta( $post->ID, '_yoast_wpseo_meta-robots-noindex', true ) ) );
        if ( in_array( $yoast, array( '1', 'noindex' ), true ) ) return 'noindex';
        $rank_math = get_post_meta( $post->ID, 'rank_math_robots', true );
        if ( is_array( $rank_math ) && in_array( 'noindex', $rank_math, true ) ) return 'noindex';
        if ( is_string( $rank_math ) && false !== stripos( $rank_math, 'noindex' ) ) return 'noindex';
        if ( get_post_meta( $post->ID, '_aioseo_robots_noindex', true ) ) return 'noindex';
        return null;
    }

    private function post_is_indexable( $post ) {
        return null === $this->post_exclusion_reason( $post );
    }

    private function canonical_url_for_post( $post ) {
        if ( ! $this->post_is_indexable( $post ) ) return '';
        $url = function_exists( 'wp_get_canonical_url' ) ? wp_get_canonical_url( $post->ID ) : '';
        if ( ! $url ) $url = get_permalink( $post->ID );
        $url = esc_url_raw( $url );
        if ( ! $url || ! preg_match( '#^https?://#i', $url ) ) return '';
        // Query-string permalinks (including ?elementor_library=...) are not
        // normal public canonicals and are deliberately excluded.
        if ( wp_parse_url( $url, PHP_URL_QUERY ) || wp_parse_url( $url, PHP_URL_FRAGMENT ) ) return '';
        $site_host = strtolower( preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
        $url_host  = strtolower( preg_replace( '/^www\./', '', (string) wp_parse_url( $url, PHP_URL_HOST ) ) );
        if ( ! $url_host || $url_host !== $site_host ) return '';
        return $url;
    }

    private function description_for_post( $post ) {
        $description = get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true );
        if ( ! $description ) $description = get_post_meta( $post->ID, 'rank_math_description', true );
        if ( ! $description && function_exists( 'aioseo' ) ) $description = get_post_meta( $post->ID, '_aioseo_description', true );
        if ( ! $description ) $description = has_excerpt( $post ) ? $post->post_excerpt : wp_strip_all_tags( strip_shortcodes( $post->post_content ) );
        $description = trim( preg_replace( '/\s+/', ' ', html_entity_decode( (string) $description, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
        if ( function_exists( 'wp_html_excerpt' ) ) return wp_html_excerpt( $description, 120, strlen( $description ) > 120 ? '...' : '' );
        return strlen( $description ) > 120 ? substr( $description, 0, 117 ) . '...' : $description;
    }

    private function section_for_post( $post ) {
        if ( 'post' === $post->post_type ) return 'Blog/Resources';
        $slug = strtolower( (string) $post->post_name );
        $front_id = intval( get_option( 'page_on_front', 0 ) );
        if ( $front_id === intval( $post->ID ) || preg_match( '/^(home|homepage)$/', $slug ) ) return 'Key Pages';
        if ( preg_match( '/^(about(?:-us)?|contact(?:-us)?|privacy(?:-policy)?|terms(?:-and-conditions|-of-service)?|polic(?:y|ies)|sponsors?)(?:-|$)/', $slug ) ) return 'Key Pages';
        if ( preg_match( '/(?:^|-)(monroe|marlboro|locations?|service-area|areas-served|find-us)(?:-|$)/', $slug ) ) return 'Locations';
        if ( preg_match( '/(?:^|-)(pickleball|padel|golf-simulator|recovery|private-events?|services?|treatments?|programs?|offerings?|solutions?)(?:-|$)/', $slug ) ) return 'Services';
        if ( preg_match( '/^(blog|resources?|news|guides?)(?:-|$)/', $slug ) ) return 'Blog/Resources';
        if ( preg_match( '/^(book|schedule|request-(?:a-)?(?:quote|consultation)|get-a-quote)(?:-|$)/', $slug ) ) return 'Contact';
        return 'Key Pages';
    }

    private function auto_discovery_links() {
        $links = array();
        $pages = get_posts( array(
            'post_type'      => 'page',
            'post_status'    => 'publish',
            'posts_per_page' => 80,
            'orderby'        => array( 'menu_order' => 'ASC', 'date' => 'DESC' ),
            'no_found_rows'  => true,
        ) );
        $articles = get_posts( array(
            'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 10,
            'orderby' => 'date', 'order' => 'DESC', 'no_found_rows' => true,
        ) );
        foreach ( array_merge( $pages, $articles ) as $post ) {
            if ( ! $this->post_is_indexable( $post ) ) continue;
            $section = $this->section_for_post( $post );
            if ( 'page' === $post->post_type && $post->post_parent && 'Locations' !== $section && 'Services' !== $section ) continue;
            $url = $this->canonical_url_for_post( $post );
            if ( ! $url ) continue;
            $links[] = array(
                'title'       => html_entity_decode( $post->post_title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
                'url'         => $url,
                'description' => $this->description_for_post( $post ),
                'section'     => $section,
                'sort_order'  => count( $links ) + 1000,
                'enabled'     => true,
                'source'      => 'auto',
            );
        }
        return $links;
    }

    public function validate_discovery_link( $url, $force = false ) {
        $url = esc_url_raw( $url );
        if ( ! $url || ! preg_match( '#^https?://#i', $url ) ) return array( 'ok' => false, 'status' => 0, 'noindex' => false, 'message' => 'URL must be absolute.' );
        if ( function_exists( 'wp_http_validate_url' ) && ! wp_http_validate_url( $url ) ) {
            return array( 'ok' => false, 'status' => 0, 'noindex' => false, 'message' => 'URL is not safe to request.' );
        }
        $site_host = strtolower( preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
        $link_host = strtolower( preg_replace( '/^www\./', '', (string) wp_parse_url( $url, PHP_URL_HOST ) ) );
        if ( ! $link_host || $link_host !== $site_host ) {
            return array( 'ok' => false, 'status' => 0, 'noindex' => false, 'message' => 'Only links on this WordPress site can be published.' );
        }
        $post_id = url_to_postid( $url );
        if ( $post_id ) {
            $post = get_post( $post_id );
            $excluded = $this->post_exclusion_reason( $post );
            if ( null !== $excluded ) {
                $message = 'elementor_library' === $excluded
                    ? 'Elementor Library templates cannot be published in AI Discovery files.'
                    : 'Excluded because the URL is not a public, queryable, published page or post.';
                $result = array( 'ok' => false, 'status' => 'publish' === get_post_status( $post_id ) ? 200 : 404,
                    'noindex' => 'noindex' === $excluded, 'post_type' => (string) ( $post->post_type ?? '' ),
                    'reason' => $excluded, 'message' => $message );
                $cache_key = 'dhc_llms_link_v2_' . md5( strtolower( $url ) );
                set_transient( $cache_key, $result, 12 * HOUR_IN_SECONDS );
                if ( function_exists( 'dhc_register_dynamic_transient' ) ) dhc_register_dynamic_transient( $cache_key, 12 * HOUR_IN_SECONDS );
                return $result;
            }
            $canonical = $this->canonical_url_for_post( $post );
            if ( ! $canonical || untrailingslashit( strtolower( $canonical ) ) !== untrailingslashit( strtolower( $url ) ) ) {
                $result = array( 'ok' => false, 'status' => 200, 'noindex' => false,
                    'post_type' => (string) $post->post_type, 'reason' => 'non_canonical',
                    'message' => $canonical ? 'Use the normal canonical permalink: ' . $canonical : 'This content does not have a normal public permalink.' );
                $cache_key = 'dhc_llms_link_v2_' . md5( strtolower( $url ) );
                set_transient( $cache_key, $result, 12 * HOUR_IN_SECONDS );
                if ( function_exists( 'dhc_register_dynamic_transient' ) ) dhc_register_dynamic_transient( $cache_key, 12 * HOUR_IN_SECONDS );
                return $result;
            }
        } elseif ( untrailingslashit( $url ) !== untrailingslashit( home_url( '/' ) ) ) {
            $result = array( 'ok' => false, 'status' => 404, 'noindex' => false, 'post_type' => '',
                'reason' => 'not_page_or_post', 'message' => 'Only the homepage and canonical URLs for published pages or posts can be included.' );
            $cache_key = 'dhc_llms_link_v2_' . md5( strtolower( $url ) );
            set_transient( $cache_key, $result, 12 * HOUR_IN_SECONDS );
            if ( function_exists( 'dhc_register_dynamic_transient' ) ) dhc_register_dynamic_transient( $cache_key, 12 * HOUR_IN_SECONDS );
            return $result;
        }

        $cache_key = 'dhc_llms_link_v2_' . md5( strtolower( $url ) );
        if ( ! $force ) {
            $cached = get_transient( $cache_key );
            if ( is_array( $cached ) ) return $cached;
        }
        if ( $post_id ) {
            if ( ! $force ) {
                $result = array( 'ok' => true, 'status' => 200, 'noindex' => false,
                    'post_type' => (string) $post->post_type, 'message' => 'Published canonical page or post; live status is checked on preview or save.' );
                set_transient( $cache_key, $result, 12 * HOUR_IN_SECONDS );
                if ( function_exists( 'dhc_register_dynamic_transient' ) ) dhc_register_dynamic_transient( $cache_key, 12 * HOUR_IN_SECONDS );
                return $result;
            }
        } elseif ( untrailingslashit( $url ) === untrailingslashit( home_url( '/' ) ) && ! $force ) {
            $result = array( 'ok' => true, 'status' => 200, 'noindex' => false, 'post_type' => 'page', 'message' => 'Homepage is published.' );
            set_transient( $cache_key, $result, 12 * HOUR_IN_SECONDS );
            if ( function_exists( 'dhc_register_dynamic_transient' ) ) dhc_register_dynamic_transient( $cache_key, 12 * HOUR_IN_SECONDS );
            return $result;
        }

        $response = wp_safe_remote_head( $url, array( 'timeout' => 8, 'redirection' => 0, 'user-agent' => 'DsquaredHubConnector/' . DHC_VERSION . ' (llms-link-validator)' ) );
        if ( is_wp_error( $response ) ) {
            $result = array( 'ok' => false, 'status' => 0, 'noindex' => false, 'post_type' => $post_id ? (string) $post->post_type : 'page', 'message' => $response->get_error_message() );
        } else {
            $status = intval( wp_remote_retrieve_response_code( $response ) );
            $robots = strtolower( (string) wp_remote_retrieve_header( $response, 'x-robots-tag' ) );
            $noindex = false !== strpos( $robots, 'noindex' );
            $result = array( 'ok' => 200 === $status && ! $noindex, 'status' => $status, 'noindex' => $noindex,
                'post_type' => $post_id ? (string) $post->post_type : 'page',
                'message' => $noindex ? 'Target sends an X-Robots-Tag noindex header.' : ( 200 === $status ? 'URL returned 200.' : 'URL returned HTTP ' . $status . '.' ) );
        }
        set_transient( $cache_key, $result, 12 * HOUR_IN_SECONDS );
        if ( function_exists( 'dhc_register_dynamic_transient' ) ) dhc_register_dynamic_transient( $cache_key, 12 * HOUR_IN_SECONDS );
        return $result;
    }

    private function build_discovery_links( $settings ) {
        $links = array();
        $seen = array();
        foreach ( (array) ( $settings['links'] ?? array() ) as $link ) {
            if ( empty( $link['enabled'] ) ) continue;
            $validation = $this->validate_discovery_link( $link['url'] );
            if ( empty( $validation['ok'] ) ) continue;
            $key = untrailingslashit( strtolower( $link['url'] ) );
            $seen[ $key ] = true;
            $link['source'] = 'curated';
            $links[] = $link;
        }
        foreach ( $this->auto_discovery_links() as $link ) {
            $validation = $this->validate_discovery_link( $link['url'] );
            if ( empty( $validation['ok'] ) ) continue;
            $key = untrailingslashit( strtolower( $link['url'] ) );
            if ( isset( $seen[ $key ] ) ) continue;
            $seen[ $key ] = true;
            $links[] = $link;
        }
        return $links;
    }

    private function render_link_sections( $links ) {
        $groups = array_fill_keys( array( 'Key Pages', 'Services', 'Locations', 'Blog/Resources', 'Contact' ), array() );
        foreach ( $links as $link ) {
            $section = isset( $groups[ $link['section'] ] ) ? $link['section'] : 'Key Pages';
            $groups[ $section ][] = $link;
        }
        $output = '';
        foreach ( $groups as $section => $items ) {
            if ( empty( $items ) ) continue;
            $output .= "## {$section}\n";
            foreach ( $items as $item ) {
                $title = preg_replace( '/[\[\]\r\n]+/', ' ', (string) ( $item['title'] ?? '' ) );
                $description = preg_replace( '/[\r\n]+/', ' ', trim( (string) ( $item['description'] ?? '' ) ) );
                $output .= '- [' . trim( $title ) . '](' . esc_url_raw( $item['url'] ) . ')' . ( $description ? ': ' . trim( $description ) : '' ) . "\n";
            }
            $output .= "\n";
        }
        return $output;
    }

    public function validate_rendered_files( $rendered, $force_links = false ) {
        $llms = (string) ( $rendered['llms'] ?? '' );
        $full = (string) ( $rendered['full'] ?? '' );
        // Validate both public discovery files. A manually curated full file
        // must not hide an Elementor template or other non-page URL simply
        // because the summary file is clean.
        preg_match_all( '/\[[^\]]+\]\((https?:\/\/[^)]+)\)/i', $llms . "\n" . $full, $matches );
        $link_results = array();
        foreach ( array_values( array_unique( $matches[1] ?? array() ) ) as $url ) {
            $link_results[] = array_merge( array( 'url' => $url ), $this->validate_discovery_link( $url, $force_links ) );
        }
        $markdown_ok = (bool) preg_match( '/^#\s+.+/m', $llms ) && (bool) preg_match( '/^>\s+.+/m', $llms ) && ! empty( $link_results );
        $links_ok = ! empty( $link_results ) && count( array_filter( $link_results, function( $row ) { return empty( $row['ok'] ); } ) ) === 0;
        $post_types_ok = count( array_filter( $link_results, function( $row ) {
            return 'elementor_library' === ( $row['post_type'] ?? '' ) || in_array( ( $row['reason'] ?? '' ), array( 'non_public_post_type', 'unsupported_post_type', 'attachment', 'not_page_or_post', 'non_canonical' ), true );
        } ) ) === 0;
        return array(
            'markdown' => array( 'ok' => $markdown_ok, 'message' => $markdown_ok ? 'Heading, summary, sections, and Markdown links are present.' : 'Add a # title, > summary, and at least one Markdown link.' ),
            'links'    => array( 'ok' => $links_ok, 'message' => $links_ok ? count( $link_results ) . ' links return 200 and are indexable.' : 'One or more links are unavailable, noindex, or missing.', 'items' => $link_results ),
            'noindex'  => array( 'ok' => count( array_filter( $link_results, function( $row ) { return ! empty( $row['noindex'] ); } ) ) === 0, 'message' => 'No noindex targets are included.' ),
            'post_types' => array( 'ok' => $post_types_ok, 'message' => $post_types_ok ? 'Only canonical public pages and posts are included.' : 'Elementor templates, attachments, non-public content, and non-canonical URLs must be removed.' ),
            'size'     => array( 'ok' => strlen( $llms ) < 102400 && strlen( $full ) < 102400,
                'message' => number_format_i18n( strlen( $llms ) ) . ' bytes for llms.txt; limit is 100KB per file.' ),
            'checked_at' => current_time( 'mysql', true ),
        );
    }

    /* ─── Generate llms.txt (summary) ─── */

    private function generate_llms_summary( $profile, $settings = null ) {
        // Format mirrors the Hub's Business Profile "Generate LLM Files"
        // output exactly (# name, > description, ## Type, ## Services,
        // ## Service Areas, ## Contact, ## Hours) so the live /llms.txt
        // matches what the user sees in the Hub, field for field.
        $name     = html_entity_decode( $profile['business_name'] ?? get_bloginfo( 'name' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $desc     = html_entity_decode( $profile['description'] ?? get_bloginfo( 'description' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $type     = $profile['business_type'] ?? '';
        $url      = ! empty( $profile['website_url'] ) ? $profile['website_url'] : home_url( '/' );
        $phone    = $profile['phone'] ?? '';
        $email    = $profile['email'] ?? '';
        $address  = $profile['address'] ?? '';
        $hours    = $profile['hours'] ?? '';
        $services = $profile['services'] ?? array();
        $areas    = $profile['service_areas'] ?? array();

        $output  = "# {$name}\n";
        $output .= "> {$desc}\n\n";

        if ( $type ) {
            $output .= "## Type\n{$type}\n\n";
        }

        if ( ! empty( $services ) ) {
            $output .= "## Services Offered\n";
            foreach ( $services as $service ) {
                $svc_name = is_array( $service ) ? ( $service['name'] ?? '' ) : $service;
                $svc_desc = is_array( $service ) ? ( $service['description'] ?? '' ) : '';
                $output  .= "- {$svc_name}";
                if ( $svc_desc ) {
                    $output .= ": {$svc_desc}";
                }
                $output .= "\n";
            }
            $output .= "\n";
        }

        if ( ! empty( $areas ) ) {
            $output .= "## Service Areas\n" . implode( ', ', $areas ) . "\n\n";
        }

        if ( $phone || $email || $address ) {
            $output .= "## Contact\n";
            if ( $phone )   $output .= "- Phone: {$phone}\n";
            if ( $email )   $output .= "- Email: {$email}\n";
            if ( $address ) $output .= "- Address: {$address}\n";
            $output .= "- Website: [{$name}]({$url})\n\n";
        }

        if ( $hours ) {
            $output .= "## Hours\n{$hours}\n\n";
        }

        if ( ! is_array( $settings ) ) $settings = $this->get_editor_settings();
        $output .= $this->render_link_sections( $this->build_discovery_links( $settings ) );

        $output .= "## More Information\n";
        $output .= "- [Complete business profile](" . home_url( '/llms-full.txt' ) . "): Expanded services, business facts, and resources.\n";

        return rtrim( $output ) . "\n";
    }

    /* ─── Generate llms-full.txt (detailed) ─── */

    private function generate_llms_full( $profile, $settings = null ) {
        $name     = html_entity_decode( $profile['business_name'] ?? get_bloginfo( 'name' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $desc     = html_entity_decode( $profile['description'] ?? get_bloginfo( 'description' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $url      = ! empty( $profile['website_url'] ) ? $profile['website_url'] : home_url( '/' );
        $phone    = $profile['phone'] ?? '';
        $email    = $profile['email'] ?? '';
        $address  = $profile['address'] ?? '';
        $hours    = $profile['hours'] ?? '';
        $services = $profile['services'] ?? array();
        $areas    = $profile['service_areas'] ?? array();
        $faqs     = $profile['faqs'] ?? array();
        $certs    = $profile['certifications'] ?? array();
        $brands   = $profile['brands'] ?? array();
        $years    = $profile['years_in_business'] ?? '';
        $usps     = $profile['unique_selling_points'] ?? array();

        $output  = "# {$name} — Complete Business Profile\n\n";
        $output .= "> {$desc}\n\n";

        // Contact info
        $output .= "## Contact Information\n\n";
        $output .= "- Website: [{$name}]({$url})\n";
        if ( $phone )   $output .= "- Phone: {$phone}\n";
        if ( $email )   $output .= "- Email: {$email}\n";
        if ( $address ) $output .= "- Address: {$address}\n";
        if ( $hours )   $output .= "- Hours: {$hours}\n";
        if ( $years )   $output .= "- In business since: {$years}\n";

        // Services
        if ( ! empty( $services ) ) {
            $output .= "\n## Services Offered\n\n";
            foreach ( $services as $service ) {
                $svc_name  = is_array( $service ) ? ( $service['name'] ?? '' ) : $service;
                $svc_desc  = is_array( $service ) ? ( $service['description'] ?? '' ) : '';
                $svc_price = is_array( $service ) ? ( $service['price_range'] ?? '' ) : '';

                $output .= "### {$svc_name}\n";
                if ( $svc_desc )  $output .= "{$svc_desc}\n";
                if ( $svc_price ) $output .= "Price range: {$svc_price}\n";
                $output .= "\n";
            }
        }

        // Service areas
        if ( ! empty( $areas ) ) {
            $output .= "## Service Areas\n\n";
            foreach ( $areas as $area ) {
                $output .= "- {$area}\n";
            }
            $output .= "\n";
        }

        // USPs
        if ( ! empty( $usps ) ) {
            $output .= "## Why Choose {$name}\n\n";
            foreach ( $usps as $usp ) {
                $output .= "- {$usp}\n";
            }
            $output .= "\n";
        }

        // Certifications
        if ( ! empty( $certs ) ) {
            $output .= "## Certifications & Credentials\n\n";
            foreach ( $certs as $cert ) {
                $output .= "- {$cert}\n";
            }
            $output .= "\n";
        }

        // Brands
        if ( ! empty( $brands ) ) {
            $output .= "## Brands We Carry / Work With\n\n";
            foreach ( $brands as $brand ) {
                $output .= "- {$brand}\n";
            }
            $output .= "\n";
        }

        // FAQs
        if ( ! empty( $faqs ) ) {
            $output .= "## Frequently Asked Questions\n\n";
            foreach ( $faqs as $faq ) {
                $q = is_array( $faq ) ? ( $faq['question'] ?? '' ) : $faq;
                $a = is_array( $faq ) ? ( $faq['answer'] ?? '' ) : '';
                $output .= "**Q: {$q}**\n";
                if ( $a ) $output .= "A: {$a}\n";
                $output .= "\n";
            }
        }

        if ( ! is_array( $settings ) ) $settings = $this->get_editor_settings();
        $output .= $this->render_link_sections( $this->build_discovery_links( $settings ) );

        $output .= "---\n";
        $output .= "Last updated: " . date( 'm/d/Y' ) . "\n";
        $output .= "Generated by Dsquared Hub Connector v" . DHC_VERSION . "\n";

        return $output;
    }

    /* ─── AI Schema Injection ─── */

    public function inject_ai_schema() {
        $profile = get_option( 'dhc_business_profile', array() );
        if ( empty( $profile ) ) return;
        $profile = self::normalize_business_profile( $profile );

        // This profile describes the primary location. Do not copy its address,
        // hours, or category onto every page (including other locations). An
        // approved Hub homepage schema takes precedence over this legacy block.
        if ( is_front_page() && ! $this->has_reviewed_home_business_schema() ) {
            $schema = $this->build_local_business_schema( $profile );
            echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) . '</script>' . "\n";
        }

        // FAQ schema (if FAQs exist and on front page or relevant pages)
        $faqs = $profile['faqs'] ?? array();
        if ( ! empty( $faqs ) && ( is_front_page() || is_page() ) ) {
            $faq_schema = $this->build_faq_schema( $faqs );
            echo '<script type="application/ld+json">' . wp_json_encode( $faq_schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) . '</script>' . "\n";
        }

        // Service schemas on relevant pages
        $services = $profile['services'] ?? array();
        if ( ! empty( $services ) && is_front_page() ) {
            $service_schema = $this->build_service_schema( $profile, $services );
            echo '<script type="application/ld+json">' . wp_json_encode( $service_schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) . '</script>' . "\n";
        }
    }

    private static function schema_text_list( $value ) {
        if ( is_string( $value ) ) $value = preg_split( '/[\r\n,]+/', $value );
        if ( ! is_array( $value ) ) return array();
        return array_values( array_filter( array_map( 'sanitize_text_field', $value ), 'strlen' ) );
    }

    /** Return a rating on the one-to-five schema scale, or null. */
    private static function schema_rating_value( $value ) {
        if ( ! is_numeric( $value ) ) return null;
        $rating = (float) $value;
        return $rating >= 1.0 && $rating <= 5.0 ? $rating : null;
    }

    /** Count evidence must be a positive whole number; never truncate decimals. */
    private static function positive_integer_count( $value ) {
        $value = trim( (string) $value );
        return preg_match( '/^[1-9][0-9]*$/', $value ) ? (int) $value : 0;
    }

    /** A supplied custom scale is valid only when it matches the declared 1-5 scale. */
    private static function uses_five_point_scale( $profile ) {
        $best = trim( (string) ( $profile['best_rating'] ?? '' ) );
        return '' === $best || ( is_numeric( $best ) && 5.0 === (float) $best );
    }

    private static function rating_schema( $value ) {
        $rating = self::schema_rating_value( $value );
        if ( null === $rating ) return array();
        return array(
            '@type'       => 'Rating',
            'ratingValue' => $rating,
            'bestRating'  => 5,
            'worstRating' => 1,
        );
    }

    private static function aggregate_rating_schema( $profile ) {
        $rating = self::schema_rating_value( $profile['aggregate_rating'] ?? '' );
        $count = self::positive_integer_count( $profile['review_count'] ?? '' );
        if ( null === $rating || $count < 1 || ! self::uses_five_point_scale( $profile ) ) return array();
        return array(
            '@type'       => 'AggregateRating',
            'ratingValue' => $rating,
            'reviewCount' => $count,
            'bestRating'  => 5,
            'worstRating' => 1,
        );
    }

    private static function review_schema( $profile ) {
        $name = sanitize_text_field( $profile['reviewer_name'] ?? '' );
        $text = sanitize_textarea_field( $profile['review_text'] ?? '' );
        $rating = $profile['reviewer_rating'] ?? '';
        if ( '' === $name || ( '' === $text && '' === trim( (string) $rating ) ) ) return array();
        if ( '' !== trim( (string) $rating ) && ( null === self::schema_rating_value( $rating ) || ! self::uses_five_point_scale( $profile ) ) ) return array();
        $review = array( '@type' => 'Review', 'author' => array( '@type' => 'Person', 'name' => $name ) );
        if ( '' !== $text ) $review['reviewBody'] = $text;
        if ( '' !== trim( (string) $rating ) ) $review['reviewRating'] = self::rating_schema( $rating );
        return $review;
    }

    private static function schema_enum_url( $value, $allowed ) {
        $value = trim( (string) $value );
        if ( '' === $value ) return '';
        $name = preg_replace( '#^https?://(?:www\.)?schema\.org/#i', '', $value );
        foreach ( $allowed as $term ) if ( 0 === strcasecmp( $name, $term ) ) return 'https://schema.org/' . $term;
        return '';
    }

    private static function medical_specialty_values( $value ) {
        $allowed = array( 'Anesthesia','Cardiovascular','CommunityHealth','Dentistry','Dermatology','DietNutrition','Emergency','Endocrine','Gastroenterologic','Genetic','Geriatric','Gynecologic','Hematologic','Infectious','LaboratoryScience','Midwifery','Musculoskeletal','Neurologic','Nursing','Obstetric','OccupationalTherapy','Oncology','Optometric','Otolaryngologic','Pathology','Pediatric','PharmacySpecialty','Physiotherapy','PlasticSurgery','Podiatric','PrimaryCare','Psychiatric','PublicHealth','Pulmonary','Radiography','Renal','RespiratoryTherapy','Rheumatologic','SpeechPathology','Surgical','Toxicologic','Urologic' );
        $aliases = array( 'cardiology'=>'Cardiovascular', 'pediatrics'=>'Pediatric' );
        $values = array();
        foreach ( self::schema_text_list( $value ) as $specialty ) {
            $candidate = $aliases[ strtolower( $specialty ) ] ?? $specialty;
            foreach ( $allowed as $term ) if ( 0 === strcasecmp( $candidate, $term ) ) { $values[] = 'https://schema.org/' . $term; break; }
        }
        return array_values( array_unique( $values ) );
    }

    private function build_local_business_schema( $profile ) {
        $profile = self::normalize_business_profile( $profile );
        $type = self::schema_type_for_profile( $profile );
        $local_types = $this->local_business_schema_types();
        $is_local = in_array( $type, $local_types, true );
        $is_organization = 'Organization' === $type || $is_local;
        $schema = array(
            '@context' => 'https://schema.org',
            '@type'    => $type,
            'name'     => $profile['business_name'] ?? get_bloginfo( 'name' ),
            'url'      => ! empty( $profile['website_url'] ) ? $profile['website_url'] : home_url( '/' ),
            'description' => $profile['description'] ?? get_bloginfo( 'description' ),
        );
        if ( ! empty( $profile['alternate_name'] ) ) $schema['alternateName'] = $profile['alternate_name'];
        if ( ! empty( $profile['slogan'] ) && $is_organization ) $schema['slogan'] = $profile['slogan'];
        if ( ! empty( $profile['hero_image_url'] ) ) $schema['image'] = $profile['hero_image_url'];
        elseif ( ! empty( $profile['logo_url'] ) ) $schema['image'] = $profile['logo_url'];
        if ( ! empty( $profile['logo_url'] ) && $is_organization ) $schema['logo'] = $profile['logo_url'];

        if ( $is_organization || 'Person' === $type ) {
            if ( ! empty( $profile['phone'] ) ) $schema['telephone'] = $profile['phone'];
            if ( ! empty( $profile['email'] ) ) $schema['email'] = $profile['email'];
            if ( ! empty( $profile['address'] ) ) $schema['address'] = array_filter( array(
                '@type' => 'PostalAddress', 'streetAddress' => $profile['street'] ?? $profile['address'],
                'addressLocality' => $profile['city'] ?? '', 'addressRegion' => $profile['state'] ?? '',
                'postalCode' => $profile['zip'] ?? '', 'addressCountry' => $profile['country'] ?? 'US',
            ), 'strlen' );
        }
        if ( $is_local && ! empty( $profile['hours'] ) ) $schema['openingHours'] = $profile['hours'];
        if ( $is_local && ! empty( $profile['price_range'] ) ) $schema['priceRange'] = $profile['price_range'];
        if ( $is_organization && ! empty( $profile['year_founded'] ) ) $schema['foundingDate'] = $profile['year_founded'];
        if ( ( $is_local || 'Service' === $type ) && ! empty( $profile['service_areas'] ) ) $schema['areaServed'] = array_map( function( $area ) { return array( '@type' => 'City', 'name' => $area ); }, $profile['service_areas'] );
        if ( ! empty( $profile['social_profiles'] ) ) $schema['sameAs'] = $profile['social_profiles'];
        $latitude = $profile['latitude'] ?? ''; $longitude = $profile['longitude'] ?? '';
        if ( $is_local && is_numeric( $latitude ) && is_numeric( $longitude ) && (float) $latitude >= -90 && (float) $latitude <= 90 && (float) $longitude >= -180 && (float) $longitude <= 180 ) $schema['geo'] = array( '@type' => 'GeoCoordinates', 'latitude' => (float) $latitude, 'longitude' => (float) $longitude );

        if ( 'Product' === $type ) {
            if ( ! empty( $profile['product_brand'] ) ) $schema['brand'] = array( '@type' => 'Brand', 'name' => $profile['product_brand'] );
            foreach ( array( 'sku'=>'sku', 'gtin'=>'gtin', 'mpn'=>'mpn', 'product_color'=>'color', 'product_material'=>'material', 'product_size'=>'size' ) as $field => $property ) if ( ! empty( $profile[ $field ] ) ) $schema[ $property ] = $profile[ $field ];
            if ( is_numeric( $profile['offer_price'] ?? '' ) && (float) $profile['offer_price'] >= 0 && preg_match( '/^[A-Z]{3}$/', strtoupper( (string) ( $profile['offer_currency'] ?? '' ) ) ) ) {
                $schema['offers'] = array( '@type' => 'Offer', 'price' => (float) $profile['offer_price'], 'priceCurrency' => strtoupper( $profile['offer_currency'] ) );
                $availability = self::schema_enum_url( $profile['availability'] ?? '', array( 'InStock','OutOfStock','PreOrder','BackOrder','Discontinued','LimitedAvailability','OnlineOnly','InStoreOnly','SoldOut','PreSale','Reserved' ) );
                $condition = self::schema_enum_url( $profile['condition'] ?? '', array( 'NewCondition','UsedCondition','RefurbishedCondition','DamagedCondition' ) );
                if ( $availability ) $schema['offers']['availability'] = $availability;
                if ( $condition ) $schema['offers']['itemCondition'] = $condition;
            }
        } elseif ( 'Person' === $type ) {
            if ( ! empty( $profile['job_title'] ) ) $schema['jobTitle'] = $profile['job_title'];
            if ( ! empty( $profile['works_for'] ) ) $schema['worksFor'] = array( '@type' => 'Organization', 'name' => $profile['works_for'] );
            if ( ! empty( $profile['bio'] ) ) $schema['description'] = $profile['bio'];
        }

        if ( in_array( $type, array( 'Restaurant','CafeOrCoffeeShop','Bakery','BarOrPub','FastFoodRestaurant' ), true ) ) {
            $cuisines = self::schema_text_list( $profile['cuisine'] ?? '' );
            if ( $cuisines ) $schema['servesCuisine'] = 1 === count( $cuisines ) ? $cuisines[0] : $cuisines;
            if ( ! empty( $profile['menu_url'] ) ) $schema['hasMenu'] = $profile['menu_url'];
            $reservations = strtolower( trim( (string) ( $profile['accepts_reservations'] ?? '' ) ) );
            if ( in_array( $reservations, array( 'yes','true','1' ), true ) ) $schema['acceptsReservations'] = true;
            elseif ( in_array( $reservations, array( 'no','false','0' ), true ) ) $schema['acceptsReservations'] = false;
            elseif ( filter_var( $reservations, FILTER_VALIDATE_URL ) ) $schema['acceptsReservations'] = esc_url_raw( $reservations );
            $star_rating = self::rating_schema( $profile['star_rating'] ?? '' );
            if ( $star_rating ) $schema['starRating'] = $star_rating;
        }
        if ( in_array( $type, array( 'MedicalBusiness','Dentist','Physician','Hospital','Pharmacy' ), true ) ) {
            $specialties = self::medical_specialty_values( $profile['medical_specialties'] ?? '' );
            if ( $specialties ) $schema['medicalSpecialty'] = 1 === count( $specialties ) ? $specialties[0] : $specialties;
        }

        $rating = self::aggregate_rating_schema( $profile );
        $review = self::review_schema( $profile );
        // Schema.org permits aggregateRating and review on Event. Person is
        // intentionally excluded because those properties are not valid there.
        if ( $rating && 'Person' !== $type ) $schema['aggregateRating'] = $rating;
        if ( $review && 'Person' !== $type ) $schema['review'] = $review;
        return array_filter( $schema, function( $value ) { return ! ( '' === $value || null === $value || array() === $value ); } );
    }

    /** A marketing category is not necessarily a schema.org @type. */
    public static function schema_type_for_profile( $profile ) {
        $label = trim( (string) ( $profile['business_type'] ?? '' ) );
        if ( 0 === strcasecmp( $label, 'Indoor Sports Club' ) ) return 'SportsClub';
        $label = preg_replace( '#^https?://(?:www\.)?schema\.org/#i', '', $label );
        $known = self::supported_profile_schema_types();
        foreach ( $known as $type ) {
            if ( 0 === strcasecmp( $label, $type ) ) return $type;
        }
        return ! empty( $profile['address'] ) ? 'LocalBusiness' : 'Organization';
    }

    public static function supported_profile_schema_types() {
        return array_values( array_unique( array(
            'Organization','LocalBusiness','Service','Product','Person','Event',
            'HomeAndConstructionBusiness','HousePainter','Electrician','Plumber','RoofingContractor','GeneralContractor','HVACBusiness','Locksmith','MovingCompany',
            'Restaurant','CafeOrCoffeeShop','Bakery','BarOrPub','FastFoodRestaurant',
            'Store','ClothingStore','FurnitureStore','HardwareStore','SportingGoodsStore',
            'MedicalBusiness','Dentist','Physician','Hospital','Pharmacy','HealthClub','DaySpa',
            'ProfessionalService','LegalService','AccountingService','FinancialService','RealEstateAgent',
            'AutomotiveBusiness','AutoRepair','AutoDealer','AutoPartsStore',
            'SportsClub','SportsActivityLocation','GolfCourse','School','EducationalOrganization','ChildCare',
            'HealthAndBeautyBusiness','BeautySalon','YogaStudio'
        ) ) );
    }

    /** Schema Service.provider accepts a person or a real organization/place provider. */
    public static function provider_schema_type_for_profile( $profile ) {
        $type = self::schema_type_for_profile( $profile );
        return in_array( $type, array( 'Service','Product','Event' ), true ) ? 'Organization' : $type;
    }

    private function local_business_schema_types() {
        return array_values( array_diff( self::supported_profile_schema_types(), array( 'Organization','Service','Product','Person','Event' ) ) );
    }

    /** Let reviewed, page-targeted Hub business schema replace the legacy profile block. */
    private function has_reviewed_home_business_schema() {
        $global = get_option( 'dhc_global_schemas', array() );
        if ( is_array( $global ) ) {
            foreach ( $global as $entry ) {
                if ( ! is_array( $entry ) || empty( $entry['markup'] ) ) continue;
                if ( ! empty( $entry['url'] ) ) {
                    $target_host = strtolower( (string) wp_parse_url( $entry['url'], PHP_URL_HOST ) );
                    $home_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
                    if ( '' === $target_host || preg_replace( '/^www\./', '', $target_host ) !== preg_replace( '/^www\./', '', $home_host ) ) continue;
                    $target = trim( (string) wp_parse_url( $entry['url'], PHP_URL_PATH ), '/' );
                    $home = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
                    if ( $target !== $home ) continue;
                }
                if ( $this->contains_business_entity( $entry['markup'] ) ) return true;
            }
        }
        $post_id = ( is_front_page() || is_singular() ) ? get_queried_object_id() : 0;
        if ( $post_id ) {
            // v1.19 stores API, raw, and guided schema in one canonical field.
            // Invalid JSON must not suppress the safe Brand Profile fallback.
            $canonical = get_post_meta( $post_id, '_d2_custom_schema', true );
            if ( ( is_string( $canonical ) || is_array( $canonical ) ) && $this->contains_business_entity( $canonical ) ) return true;

            $post_schemas = get_post_meta( $post_id, '_dhc_schema_markup', true );
            if ( is_array( $post_schemas ) ) {
                foreach ( $post_schemas as $entry ) {
                    if ( is_array( $entry ) && ! empty( $entry['markup'] ) && $this->contains_business_entity( $entry['markup'] ) ) return true;
                }
            }
        }
        return false;
    }

    private function contains_business_entity( $markup ) {
        if ( is_string( $markup ) ) $markup = json_decode( $markup, true );
        if ( ! is_array( $markup ) ) return false;
        if ( isset( $markup['@graph'] ) && is_array( $markup['@graph'] ) ) {
            $nodes = $markup['@graph'];
        } elseif ( isset( $markup['@type'] ) ) {
            $nodes = array( $markup );
        } elseif ( ! empty( $markup ) && array_keys( $markup ) === range( 0, count( $markup ) - 1 ) ) {
            $nodes = $markup;
        } else {
            $nodes = array( $markup );
        }
        foreach ( $nodes as $node ) {
            if ( ! is_array( $node ) ) continue;
            $types = isset( $node['@type'] ) ? (array) $node['@type'] : array();
            foreach ( $types as $type ) {
                if ( ! is_string( $type ) ) continue;
                $type = preg_replace( '#^https?://(?:www\.)?schema\.org/#i', '', $type );
                foreach ( $this->local_business_schema_types() as $known ) {
                    if ( 0 === strcasecmp( $type, $known ) ) return true;
                }
            }
        }
        return false;
    }

    private function build_faq_schema( $faqs ) {
        $entities = array();
        foreach ( $faqs as $faq ) {
            if ( ! is_array( $faq ) || empty( $faq['question'] ) ) continue;
            $entities[] = array(
                '@type'          => 'Question',
                'name'           => $faq['question'],
                'acceptedAnswer' => array(
                    '@type' => 'Answer',
                    'text'  => $faq['answer'] ?? '',
                ),
            );
        }

        return array(
            '@context'   => 'https://schema.org',
            '@type'      => 'FAQPage',
            'mainEntity' => $entities,
        );
    }

    private function build_service_schema( $profile, $services ) {
        $profile = self::normalize_business_profile( $profile );
        $provider = array(
            '@type' => self::provider_schema_type_for_profile( $profile ),
            'name' => $profile['business_name'] ?? get_bloginfo( 'name' ),
            'url' => ! empty( $profile['website_url'] ) ? $profile['website_url'] : home_url( '/' ),
        );
        if ( ! empty( $profile['address'] ) ) $provider['address'] = array(
            '@type' => 'PostalAddress', 'streetAddress' => $profile['street'] ?? $profile['address'],
            'addressLocality' => $profile['city'] ?? '', 'addressRegion' => $profile['state'] ?? '',
            'postalCode' => $profile['zip'] ?? '', 'addressCountry' => $profile['country'] ?? 'US',
        );
        if ( ! empty( $profile['social_profiles'] ) ) $provider['sameAs'] = $profile['social_profiles'];
        $items = array();
        foreach ( $services as $service ) {
            if ( ! is_array( $service ) ) {
                $service = array( 'name' => $service );
            }
            $item = array(
                '@type'       => 'Service',
                'name'        => $service['name'] ?? '',
                'provider'    => $provider,
            );
            if ( ! empty( $service['description'] ) ) {
                $item['description'] = $service['description'];
            }
            if ( ! empty( $service['price_range'] ) ) {
                $item['offers'] = array(
                    '@type'         => 'Offer',
                    'priceSpecification' => array(
                        '@type' => 'PriceSpecification',
                        'price' => $service['price_range'],
                    ),
                );
            }
            if ( ! empty( $profile['service_areas'] ) ) {
                $item['areaServed'] = array_map( function( $area ) {
                    return array( '@type' => 'City', 'name' => $area );
                }, $profile['service_areas'] );
            }
            $items[] = $item;
        }

        return array(
            '@context'    => 'https://schema.org',
            '@type'       => 'ItemList',
            'name'        => 'Services offered by ' . ( $profile['business_name'] ?? get_bloginfo( 'name' ) ),
            'itemListElement' => $items,
        );
    }

    /* ─── IndexNow Pinging ─── */

    public function ping_indexnow( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $this->post_is_indexable( $post ) ) return;
        $this->queue_indexnow_urls( array( $this->canonical_url_for_post( $post ) ), 'content' );
    }

    public function on_content_update( $post_id, $post, $update ) {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) return;
        if ( ! $post || ! in_array( (string) $post->post_type, array( 'page', 'post' ), true ) ) return;
        $type = get_post_type_object( $post->post_type );
        if ( ! $type || empty( $type->public ) || empty( $type->publicly_queryable ) ) return;
        $this->regenerate_static_files();
        if ( $this->post_is_indexable( $post ) ) $this->ping_indexnow( $post_id );
    }

    public function on_content_removed( $post_id ) {
        $post = get_post( $post_id );
        if ( $post && in_array( (string) $post->post_type, array( 'page', 'post' ), true ) ) {
            $type = get_post_type_object( $post->post_type );
            if ( $type && ! empty( $type->public ) && ! empty( $type->publicly_queryable ) ) {
                $url = get_permalink( $post_id );
                if ( $url ) $this->queue_indexnow_urls( array( $url ), 'content_removed' );
            }
        }
        $this->regenerate_static_files();
    }

    public function on_redirects_changed( $old_value, $value, $option = '' ) {
        $urls = array();
        foreach ( (array) $value as $redirect ) {
            $from = trim( (string) ( $redirect['from'] ?? '' ) );
            $to = trim( (string) ( $redirect['to'] ?? '' ) );
            if ( $from ) $urls[] = home_url( '/' . ltrim( $from, '/' ) );
            if ( $to ) $urls[] = preg_match( '#^https?://#i', $to ) ? $to : home_url( '/' . ltrim( $to, '/' ) );
        }
        $this->queue_indexnow_urls( $urls, 'redirects' );
    }

    private function indexnow_url_allowed( $url ) {
        $url = esc_url_raw( $url );
        if ( ! $url ) return false;
        $site_host = strtolower( preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
        $url_host = strtolower( preg_replace( '/^www\./', '', (string) wp_parse_url( $url, PHP_URL_HOST ) ) );
        if ( ! $url_host || $site_host !== $url_host ) return false;
        $post_id = url_to_postid( $url );
        if ( $post_id && ! $this->post_is_indexable( get_post( $post_id ) ) ) return false;
        return true;
    }

    public function queue_indexnow_urls( $urls, $source = 'content' ) {
        $queue = get_option( self::INDEXNOW_QUEUE_OPTION, array() );
        if ( ! is_array( $queue ) ) $queue = array();
        foreach ( (array) $urls as $url ) {
            $url = esc_url_raw( $url );
            if ( $this->indexnow_url_allowed( $url ) ) $queue[ untrailingslashit( strtolower( $url ) ) ] = $url;
        }
        $queue = array_slice( $queue, -2000, null, true );
        update_option( self::INDEXNOW_QUEUE_OPTION, $queue, false );
        if ( ! empty( $queue ) && ! wp_next_scheduled( self::INDEXNOW_CRON_HOOK ) ) {
            $last = intval( get_option( self::INDEXNOW_LAST_OPTION, 0 ) );
            wp_schedule_single_event( max( time() + 1, $last + 60 ), self::INDEXNOW_CRON_HOOK );
        }
        $this->log_activity( 'IndexNow queued ' . count( $queue ) . ' URL(s) (' . sanitize_key( $source ) . ')', 'hub' === sanitize_key( $source ) ? 'hub' : 'wordpress' );
        return count( $queue );
    }

    public function flush_indexnow_queue() {
        $last = intval( get_option( self::INDEXNOW_LAST_OPTION, 0 ) );
        if ( $last && time() - $last < 60 ) {
            if ( ! wp_next_scheduled( self::INDEXNOW_CRON_HOOK ) ) wp_schedule_single_event( $last + 60, self::INDEXNOW_CRON_HOOK );
            return array( 'queued' => count( (array) get_option( self::INDEXNOW_QUEUE_OPTION, array() ) ), 'deferred' => true );
        }
        $queue = get_option( self::INDEXNOW_QUEUE_OPTION, array() );
        if ( ! is_array( $queue ) || empty( $queue ) ) return array( 'queued' => 0, 'submitted' => 0 );
        $batch = array_slice( array_values( $queue ), 0, 100 );
        $remaining = array_slice( $queue, count( $batch ), null, true );
        $key = $this->get_indexnow_key();
        $payload = array(
            'host'        => wp_parse_url( home_url(), PHP_URL_HOST ),
            'key'         => $key,
            'keyLocation' => home_url( '/' . $key . '.txt' ),
            'urlList'     => $batch,
        );
        $response = wp_remote_post( 'https://api.indexnow.org/indexnow', array(
            'body' => wp_json_encode( $payload ), 'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
            'timeout' => 12, 'blocking' => true,
        ) );
        $code = is_wp_error( $response ) ? 0 : intval( wp_remote_retrieve_response_code( $response ) );
        $message = is_wp_error( $response ) ? $response->get_error_message() : trim( wp_strip_all_tags( wp_remote_retrieve_body( $response ) ) );
        update_option( 'dhc_indexnow_last_status', $code, false );
        if ( ! is_wp_error( $response ) && in_array( $code, array( 200, 202 ), true ) ) {
            update_option( self::INDEXNOW_QUEUE_OPTION, $remaining, false );
            update_option( self::INDEXNOW_LAST_OPTION, time(), false );
        } else {
            $remaining = $queue;
        }
        $this->log_activity( 'IndexNow response ' . ( $code ?: 'error' ) . ' for ' . count( $batch ) . ' URL(s)' . ( $message ? ': ' . substr( $message, 0, 160 ) : '' ) );
        $this->report_to_hub( 'indexnow_ping', array( 'urls' => $batch, 'status' => $code, 'time' => current_time( 'mysql' ) ) );
        if ( ! empty( $remaining ) && ! wp_next_scheduled( self::INDEXNOW_CRON_HOOK ) ) wp_schedule_single_event( time() + 60, self::INDEXNOW_CRON_HOOK );
        return array( 'queued' => count( $remaining ), 'submitted' => in_array( $code, array( 200, 202 ), true ) ? count( $batch ) : 0, 'status' => $code );
    }

    private function send_indexnow( $urls ) {
        return $this->queue_indexnow_urls( $urls, 'legacy' );
    }

    public function submit_all_public_urls( $source = 'admin' ) {
        $urls = array( home_url( '/' ) );
        $post_ids = get_posts( array(
            'post_type' => array( 'page', 'post' ), 'post_status' => 'publish',
            'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true,
        ) );
        foreach ( $post_ids as $post_id ) {
            $post = get_post( $post_id );
            if ( ! $this->post_is_indexable( $post ) ) continue;
            $url = $this->canonical_url_for_post( $post );
            if ( $url ) $urls[] = $url;
        }
        $urls = array_values( array_unique( array_filter( $urls ) ) );
        $count = $this->queue_indexnow_urls( $urls, $source );
        return array( 'queued' => $count, 'found' => count( $urls ) );
    }

    private function get_indexnow_key() {
        $key = get_option( 'dhc_indexnow_key' );
        if ( ! $key ) {
            $key = wp_generate_uuid4();
            $key = str_replace( '-', '', $key );
            update_option( 'dhc_indexnow_key', $key );
        }
        return $key;
    }

    public function serve_indexnow_key() {
        $key = $this->get_indexnow_key();
        $path = parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
        $uri = trim( (string) $path, '/' );
        if ( $uri === $key . '.txt' ) {
            global $wp_query;
            status_header( 200 );
            if ( isset( $wp_query ) && is_object( $wp_query ) ) $wp_query->is_404 = false;
            header( 'Content-Type: text/plain; charset=utf-8' );
            header( 'Cache-Control: public, max-age=86400' );
            header( 'Content-Length: ' . strlen( $key ) );
            echo $key;
            exit;
        }
    }

    /* ─── .well-known/ai-plugin.json ─── */

    public function serve_ai_plugin_json() {
        $uri = trim( $_SERVER['REQUEST_URI'], '/' );
        if ( $uri !== '.well-known/ai-plugin.json' ) return;

        $profile = get_option( 'dhc_business_profile', array() );
        $name    = $profile['business_name'] ?? get_bloginfo( 'name' );
        $desc    = $profile['description'] ?? get_bloginfo( 'description' );

        $plugin_json = array(
            'schema_version'     => 'v1',
            'name_for_human'     => $name,
            'name_for_model'     => sanitize_title( $name ),
            'description_for_human' => $desc,
            'description_for_model' => "Provides information about {$name}, including services offered, service areas, contact information, and frequently asked questions.",
            'auth'               => array( 'type' => 'none' ),
            'api'                => array(
                'type' => 'openapi',
                'url'  => home_url( '/llms-full.txt' ),
            ),
            'logo_url'           => $profile['logo_url'] ?? '',
            'contact_email'      => $profile['email'] ?? get_option( 'admin_email' ),
            'legal_info_url'     => home_url( '/privacy-policy/' ),
        );

        header( 'Content-Type: application/json; charset=utf-8' );
        echo wp_json_encode( $plugin_json, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
        exit;
    }

    /* ─── Robots.txt Entries ─── */

    public function add_robots_entries( $output, $public ) {
        // Remove the marker-bounded v1.21+ block. For the legacy unbounded
        // block, remove it only when the Yoast boundary is present; never eat
        // unrelated robots rules merely because another plugin changed order.
        $output = preg_replace( '/\n?# Dsquared Hub Connector - AI Discovery\n[\s\S]*?# \/Dsquared Hub Connector - AI Discovery\n?/i', "\n", (string) $output );
        $output = preg_replace( '/\n?# Dsquared Hub Connector[^\n]*AI Discovery[\s\S]*?(?=\n# START YOAST BLOCK)/i', "\n", (string) $output );
        $block  = "# Dsquared Hub Connector - AI Discovery\n";
        $block .= "User-agent: *\n";
        $block .= "Allow: /llms.txt\n";
        $block .= "Allow: /llms-full.txt\n";
        $block .= "Allow: /.well-known/ai-plugin.json\n\n";
        $block .= "# AI crawlers\n";
        foreach ( array( 'GPTBot', 'Google-Extended', 'PerplexityBot', 'ClaudeBot', 'Applebot-Extended', 'OAI-SearchBot', 'ChatGPT-User' ) as $agent ) {
            if ( preg_match( '/^\s*User-agent:\s*' . preg_quote( $agent, '/' ) . '\s*$/mi', (string) $output ) ) continue;
            $block .= "User-agent: {$agent}\nAllow: /\n\n";
        }
        $block .= "# /Dsquared Hub Connector - AI Discovery\n";
        return rtrim( $output ) . "\n\n" . $block;
    }

    /* ─── REST Routes ─── */

    public function register_routes() {
        register_rest_route( 'dsquared-hub/v1', '/ai-discovery/profile', array(
            'methods'  => 'POST',
            'callback' => array( $this, 'save_profile' ),
            'permission_callback' => array( $this, 'check_api_key' ),
        ) );

        register_rest_route( 'dsquared-hub/v1', '/ai-discovery/profile', array(
            'methods'  => 'GET',
            'callback' => array( $this, 'get_profile' ),
            'permission_callback' => array( $this, 'check_api_key' ),
        ) );

        register_rest_route( 'dsquared-hub/v1', '/ai-discovery/ping', array(
            'methods'  => 'POST',
            'callback' => array( $this, 'manual_ping' ),
            'permission_callback' => array( $this, 'check_api_key' ),
        ) );

        // Raw push — Hub sends verbatim llms.txt content, plugin writes it directly.
        register_rest_route( 'dsquared-hub/v1', '/ai-discovery/raw', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'write_raw_content' ),
            'permission_callback' => array( $this, 'check_api_key' ),
        ) );

        register_rest_route( 'dsquared-hub/v1', '/ai-discovery/llms', array(
            array( 'methods' => 'GET', 'callback' => array( $this, 'rest_get_llms' ), 'permission_callback' => array( $this, 'check_api_key' ) ),
            array( 'methods' => 'POST', 'callback' => array( $this, 'rest_save_llms' ), 'permission_callback' => array( $this, 'check_api_key' ) ),
        ) );
        register_rest_route( 'dsquared-hub/v1', '/ai-discovery/llms/regenerate', array(
            'methods' => 'POST', 'callback' => array( $this, 'rest_regenerate_llms' ), 'permission_callback' => array( $this, 'check_api_key' ),
        ) );
        register_rest_route( 'dsquared-hub/v1', '/ai-discovery/indexnow/submit', array(
            'methods' => 'POST', 'callback' => array( $this, 'rest_submit_indexnow' ), 'permission_callback' => array( $this, 'check_api_key' ),
        ) );
    }

    /**
     * Static callback used by the top-level /ai-discovery route registered in
     * class-dhc-rest.php. Delegates to the singleton instance's save_profile so
     * both routes share the same logic.
     */
    public static function handle_request( $request ) {
        $allowed = self::check_legacy_api_access( $request );
        if ( true !== $allowed ) return $allowed;
        return self::init()->save_profile( $request );
    }

    /** The legacy top-level route has the same billing gate as every module route. */
    public static function check_legacy_api_access( $request ) {
        $result = DHC_API_Key::authenticate_request( $request );
        if ( true !== $result ) return new WP_Error( 'dhc_unauthorized', 'A valid Dsquared Hub API key is required.', array( 'status' => 401 ) );
        if ( ! DHC_API_Key::is_module_available( 'ai_discovery' ) ) return new WP_Error( 'dhc_ai_discovery_unavailable', 'AI Discovery is not available on the current subscription.', array( 'status' => 403 ) );
        return true;
    }

    public function check_api_key( $request ) {
        $result = DHC_API_Key::authenticate_request( $request );
        if ( true !== $result ) return new WP_Error( 'dhc_unauthorized', 'A valid Dsquared Hub API key is required.', array( 'status' => 401 ) );
        if ( ! DHC_API_Key::is_module_available( 'ai_discovery' ) ) {
            return new WP_Error( 'dhc_ai_discovery_unavailable', 'AI Discovery is not available on the current subscription.', array( 'status' => 403 ) );
        }
        return true;
    }

    public function rest_get_llms( $request ) {
        $settings = $this->get_editor_settings();
        $rendered = $this->render_files( null, $settings );
        return new WP_REST_Response( array( 'success' => true, 'settings' => $settings, 'rendered' => $rendered,
            'validation' => get_option( self::VALIDATION_OPTION, array() ) ), 200 );
    }

    public function rest_save_llms( $request ) {
        $data = $request->get_json_params();
        $saved = $this->save_editor_settings( $data, 'hub' );
        if ( is_wp_error( $saved['files'] ) ) return $saved['files'];
        return new WP_REST_Response( array( 'success' => true, 'settings' => $saved['settings'], 'rendered' => $saved['rendered'],
            'validation' => $saved['validation'] ), 200 );
    }

    public function rest_regenerate_llms( $request ) {
        $files = $this->regenerate_static_files();
        if ( is_wp_error( $files ) ) return $files;
        $rendered = $this->render_files();
        $validation = $this->validate_rendered_files( $rendered, true );
        update_option( self::VALIDATION_OPTION, $validation, false );
        $this->log_activity( 'AI Discovery files regenerated (hub)', 'hub' );
        return new WP_REST_Response( array( 'success' => true, 'files' => array_map( 'basename', $files ), 'rendered' => $rendered, 'validation' => $validation ), 200 );
    }

    public function rest_submit_indexnow( $request ) {
        $data = $request->get_json_params();
        $urls = array_values( array_filter( array_map( 'esc_url_raw', (array) ( $data['urls'] ?? array() ) ) ) );
        $result = empty( $urls ) ? $this->submit_all_public_urls( 'hub' ) : array( 'queued' => $this->queue_indexnow_urls( $urls, 'hub' ), 'found' => count( $urls ) );
        return new WP_REST_Response( array_merge( array( 'success' => true ), $result ), 202 );
    }

    /**
     * Write verbatim llms.txt content sent from the Hub.
     *
     * Saves into the versioned editor option in manual mode, then regenerates
     * the same physical and dynamic output used by wp-admin and the REST API.
     */
    public function write_raw_content( $request ) {
        $data    = $request->get_json_params();
        $content = $data['content'] ?? '';
        if ( ! is_string( $content ) || trim( $content ) === '' ) {
            return new WP_Error( 'empty_content', 'content field is required', array( 'status' => 400 ) );
        }

        // Normalize line endings, ensure UTF-8, strip any null bytes.
        $content = str_replace( "\r\n", "\n", $content );
        $content = str_replace( "\r", "\n", $content );
        $content = preg_replace( '/\0/', '', $content );

        $saved = $this->save_editor_settings( array(
            'mode' => 'manual', 'custom_content' => $content,
            'links' => $this->get_editor_settings()['links'],
            'full_manual' => $this->get_editor_settings()['full_manual'],
            'full_custom_content' => $this->get_editor_settings()['full_custom_content'],
        ), 'hub' );
        if ( is_wp_error( $saved['files'] ) ) return $saved['files'];

        $this->log_activity( 'Raw llms.txt written (' . strlen( $content ) . ' bytes) via Hub push', 'hub' );

        if ( class_exists( 'DHC_Event_Logger' ) ) {
            DHC_Event_Logger::ai_discovery(
                'llms_txt_raw_pushed',
                array( 'bytes' => strlen( $content ), 'time' => current_time( 'mysql' ) ),
                'Raw llms.txt pushed from Hub'
            );
        }

        return new WP_REST_Response( array(
            'success' => true,
            'message' => 'llms.txt saved in manual mode (' . strlen( $content ) . ' bytes)',
            'url'     => home_url( '/llms.txt' ),
        ), 200 );
    }

    public function save_profile( $request ) {
        $data = $request->get_json_params();

        $allowed_fields = self::business_profile_fields();

        $profile = array();
        foreach ( $allowed_fields as $field ) {
            if ( isset( $data[ $field ] ) ) {
                $profile[ $field ] = $data[ $field ];
            }
        }

        // Merge with the current canonical profile so a Hub patch cannot erase
        // fields managed in wp-admin (and vice versa).
        $profile = self::save_business_profile_data( $profile );

        // Write physical llms.txt + llms-full.txt files in WP root.
        // This is the ONLY reliable way to serve these on hosts whose
        // nginx config short-circuits .txt requests with a try_files
        // =404 directive (which is most common WordPress nginx setups).
        // The dynamic handlers below stay as a fallback for hosts that
        // do route .txt to PHP.
        $static_result = $this->regenerate_static_files( $profile );
        $static_files = is_wp_error( $static_result ) ? array() : $static_result;
        if ( is_wp_error( $static_result ) ) {
            $this->log_activity( 'Static file write failed: ' . $static_result->get_error_message(), 'hub' );
        } else {
            $this->log_activity( 'Static llms.txt files written: ' . implode( ', ', $static_files ), 'hub' );
        }

        // Flush rewrite rules so llms.txt works
        flush_rewrite_rules();

        // Ping IndexNow for the homepage
        $this->send_indexnow( array( home_url( '/' ) ) );

        $this->log_activity( 'Business profile updated via Hub', 'hub' );

        // v1.6: Log to Hub via centralized event logger
        if ( class_exists( 'DHC_Event_Logger' ) ) {
            DHC_Event_Logger::ai_discovery(
                'profile_updated_via_hub',
                array( 'source' => 'rest_api', 'fields' => count( $profile ), 'time' => current_time( 'mysql' ) ),
                'Business profile updated via Hub REST API'
            );
        }

        return new WP_REST_Response( array(
            'success' => true,
            'message' => 'Business profile saved. AI discovery files generated.',
            'files'   => array(
                'llms_txt'      => home_url( '/llms.txt' ),
                'llms_full_txt' => home_url( '/llms-full.txt' ),
                'ai_plugin'     => home_url( '/.well-known/ai-plugin.json' ),
            ),
        ), 200 );
    }

    public function get_profile( $request ) {
        $profile = self::normalize_business_profile( get_option( 'dhc_business_profile', array() ) );
        return new WP_REST_Response( array(
            'success' => true,
            'profile' => $profile,
            'files'   => array(
                'llms_txt'      => home_url( '/llms.txt' ),
                'llms_full_txt' => home_url( '/llms-full.txt' ),
                'ai_plugin'     => home_url( '/.well-known/ai-plugin.json' ),
            ),
        ), 200 );
    }

    public function manual_ping( $request ) {
        $data = $request->get_json_params();
        $urls = $data['urls'] ?? array( home_url( '/' ) );
        $queued = $this->queue_indexnow_urls( $urls, 'hub' );

        return new WP_REST_Response( array(
            'success' => true,
            'message' => 'IndexNow queued ' . $queued . ' URL(s) for the next batch.',
            'queued'  => $queued,
        ), 202 );
    }

    /* ─── Hub Reporting ─── */

    private function report_to_hub( $event, $data ) {
        // v1.6: Use centralized event logger if available, fallback to direct reporting
        if ( class_exists( 'DHC_Event_Logger' ) ) {
            DHC_Event_Logger::ai_discovery( $event, $data );
            return;
        }

        // Legacy fallback
        $api_key = get_option( 'dhc_api_key' );
        $sub     = get_option( 'dhc_subscription', array() );
        $hub_url = $sub['hub_url'] ?? 'https://hub.dsquaredmedia.net';

        if ( ! $api_key ) return;

        wp_remote_post( $hub_url . '/api/plugin/event', array(
            'body'    => wp_json_encode( array(
                'event' => $event,
                'site'  => home_url( '/' ),
                'data'  => $data,
            ) ),
            'headers' => array(
                'Content-Type'  => 'application/json',
                'X-DHC-API-Key' => $api_key,
            ),
            'timeout'  => 10,
            'blocking' => false,
        ) );
    }

    /* ─── Activity Logging ─── */

    private function log_activity( $message, $origin = 'wordpress' ) {
		DHC_Event_Logger::activity( 'ai_discovery_activity', 'ai_discovery', $message, $origin );
    }
}
