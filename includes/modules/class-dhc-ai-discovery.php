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

    public static function init() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
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

        // Ping IndexNow on content changes
        add_action( 'publish_post', array( $this, 'ping_indexnow' ), 20, 1 );
        add_action( 'publish_page', array( $this, 'ping_indexnow' ), 20, 1 );
        add_action( 'save_post', array( $this, 'on_content_update' ), 20, 3 );

        // REST endpoint for saving business profile from Hub
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );

        // Add .well-known/ai-plugin.json
        add_action( 'template_redirect', array( $this, 'serve_ai_plugin_json' ), 1 );

        // Generate IndexNow key file
        add_action( 'template_redirect', array( $this, 'serve_indexnow_key' ), 1 );

        // Add robots.txt entries
        add_filter( 'robots_txt', array( $this, 'add_robots_entries' ), 10, 2 );
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

        $files = array(
            ABSPATH . 'llms.txt'      => $this->generate_llms_summary( $profile ),
            ABSPATH . 'llms-full.txt' => $this->generate_llms_full( $profile ),
        );

        foreach ( $files as $path => $content ) {
            $ok = @file_put_contents( $path, $content );
            if ( $ok !== false ) {
                $written[] = $path;
                @chmod( $path, 0644 );
            }
        }

        if ( empty( $written ) ) {
            return new WP_Error( 'write_failed', 'Could not write to ABSPATH. Check WP-root write permissions.' );
        }
        return $written;
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
        if ( $is_full ) {
            echo $this->generate_llms_full( $profile );
        } else {
            echo $this->generate_llms_summary( $profile );
        }
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

        header( 'Content-Type: text/plain; charset=utf-8' );
        header( 'X-Robots-Tag: noindex' );

        if ( $is_llms_full ) {
            echo $this->generate_llms_full( $profile );
        } else {
            echo $this->generate_llms_summary( $profile );
        }
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

    /* ─── Generate llms.txt (summary) ─── */

    private function generate_llms_summary( $profile ) {
        // Format mirrors the Hub's Business Profile "Generate LLM Files"
        // output exactly (# name, > description, ## Type, ## Services,
        // ## Service Areas, ## Contact, ## Hours) so the live /llms.txt
        // matches what the user sees in the Hub, field for field.
        $name     = $profile['business_name'] ?? get_bloginfo( 'name' );
        $desc     = $profile['description'] ?? get_bloginfo( 'description' );
        $type     = $profile['business_type'] ?? '';
        $url      = home_url( '/' );
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
            $output .= "## Services\n";
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
            $output .= "- Website: {$url}\n\n";
        }

        if ( $hours ) {
            $output .= "## Hours\n{$hours}\n\n";
        }

        $output .= "## More Information\n";
        $output .= "- Full details: " . home_url( '/llms-full.txt' ) . "\n";

        return rtrim( $output ) . "\n";
    }

    /* ─── Generate llms-full.txt (detailed) ─── */

    private function generate_llms_full( $profile ) {
        $name     = $profile['business_name'] ?? get_bloginfo( 'name' );
        $desc     = $profile['description'] ?? get_bloginfo( 'description' );
        $url      = home_url( '/' );
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
        $output .= "- Website: {$url}\n";
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

        // Top pages
        $output .= "## Key Pages\n\n";
        $pages = get_pages( array( 'number' => 20, 'sort_column' => 'menu_order' ) );
        foreach ( $pages as $page ) {
            $output .= "- [{$page->post_title}](" . get_permalink( $page->ID ) . ")\n";
        }
        $output .= "\n";

        // Recent posts
        $posts = get_posts( array( 'numberposts' => 10, 'post_status' => 'publish' ) );
        if ( ! empty( $posts ) ) {
            $output .= "## Recent Articles\n\n";
            foreach ( $posts as $post ) {
                $date    = date( 'm/d/Y', strtotime( $post->post_date ) );
                $output .= "- [{$post->post_title}](" . get_permalink( $post->ID ) . ") — {$date}\n";
            }
            $output .= "\n";
        }

        $output .= "---\n";
        $output .= "Last updated: " . date( 'm/d/Y' ) . "\n";
        $output .= "Generated by Dsquared Hub Connector v" . DHC_VERSION . "\n";

        return $output;
    }

    /* ─── AI Schema Injection ─── */

    public function inject_ai_schema() {
        $profile = get_option( 'dhc_business_profile', array() );
        if ( empty( $profile ) ) return;

        // LocalBusiness schema
        $schema = $this->build_local_business_schema( $profile );
        echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) . '</script>' . "\n";

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

    private function build_local_business_schema( $profile ) {
        $schema = array(
            '@context' => 'https://schema.org',
            '@type'    => $profile['business_type'] ?? 'LocalBusiness',
            'name'     => $profile['business_name'] ?? get_bloginfo( 'name' ),
            'url'      => home_url( '/' ),
            'description' => $profile['description'] ?? get_bloginfo( 'description' ),
        );

        if ( ! empty( $profile['phone'] ) ) {
            $schema['telephone'] = $profile['phone'];
        }
        if ( ! empty( $profile['email'] ) ) {
            $schema['email'] = $profile['email'];
        }
        if ( ! empty( $profile['address'] ) ) {
            $schema['address'] = array(
                '@type'           => 'PostalAddress',
                'streetAddress'   => $profile['street'] ?? $profile['address'],
                'addressLocality' => $profile['city'] ?? '',
                'addressRegion'   => $profile['state'] ?? '',
                'postalCode'      => $profile['zip'] ?? '',
                'addressCountry'  => $profile['country'] ?? 'US',
            );
        }
        if ( ! empty( $profile['hours'] ) ) {
            $schema['openingHours'] = $profile['hours'];
        }
        if ( ! empty( $profile['logo_url'] ) ) {
            $schema['logo'] = $profile['logo_url'];
            $schema['image'] = $profile['logo_url'];
        }
        if ( ! empty( $profile['years_in_business'] ) ) {
            $schema['foundingDate'] = $profile['years_in_business'];
        }
        if ( ! empty( $profile['service_areas'] ) ) {
            $schema['areaServed'] = array_map( function( $area ) {
                return array( '@type' => 'City', 'name' => $area );
            }, $profile['service_areas'] );
        }
        if ( ! empty( $profile['social_profiles'] ) ) {
            $schema['sameAs'] = $profile['social_profiles'];
        }

        return $schema;
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
        $items = array();
        foreach ( $services as $service ) {
            if ( ! is_array( $service ) ) {
                $service = array( 'name' => $service );
            }
            $item = array(
                '@type'       => 'Service',
                'name'        => $service['name'] ?? '',
                'provider'    => array(
                    '@type' => 'LocalBusiness',
                    'name'  => $profile['business_name'] ?? get_bloginfo( 'name' ),
                ),
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
        if ( ! $post || $post->post_status !== 'publish' ) return;

        $url = get_permalink( $post_id );
        $this->send_indexnow( array( $url ) );
    }

    public function on_content_update( $post_id, $post, $update ) {
        if ( ! $update || $post->post_status !== 'publish' ) return;
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) return;

        // Debounce: don't ping more than once per 5 minutes per post
        $last_ping = get_post_meta( $post_id, '_dhc_last_indexnow_ping', true );
        if ( $last_ping && ( time() - intval( $last_ping ) ) < 300 ) return;

        update_post_meta( $post_id, '_dhc_last_indexnow_ping', time() );

        $url = get_permalink( $post_id );
        $this->send_indexnow( array( $url ) );
    }

    private function send_indexnow( $urls ) {
        $key = $this->get_indexnow_key();
        $host = wp_parse_url( home_url(), PHP_URL_HOST );

        $payload = array(
            'host'        => $host,
            'key'         => $key,
            'keyLocation' => home_url( '/' . $key . '.txt' ),
            'urlList'     => $urls,
        );

        // Ping Bing/Yandex via IndexNow
        $endpoints = array(
            'https://api.indexnow.org/indexnow',
            'https://www.bing.com/indexnow',
            'https://yandex.com/indexnow',
        );

        foreach ( $endpoints as $endpoint ) {
            wp_remote_post( $endpoint, array(
                'body'    => wp_json_encode( $payload ),
                'headers' => array( 'Content-Type' => 'application/json' ),
                'timeout' => 10,
                'blocking' => false,
            ) );
        }

        // Log activity
        $this->log_activity( 'IndexNow pinged for ' . count( $urls ) . ' URL(s)' );

        // Report to Hub
        $this->report_to_hub( 'indexnow_ping', array(
            'urls'  => $urls,
            'time'  => current_time( 'mysql' ),
        ) );
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
        $uri = trim( $_SERVER['REQUEST_URI'], '/' );
        if ( $uri === $key . '.txt' ) {
            header( 'Content-Type: text/plain' );
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
        // Augment the site's existing robots.txt (Yoast/Rank Math/WP core still
        // own the User-agent:* rules and the Sitemap line) with EXPLICIT access
        // grants for the AI crawlers we care about — nothing else.
        //
        // We intentionally do NOT emit floating "Allow: /llms.txt" /
        // "/llms-full.txt" / "/.well-known/ai-plugin.json" lines: with no
        // User-agent group of their own they attached to whichever group
        // preceded our block (usually "User-agent: *"), which is fragile, and
        // they were redundant anyway — every AI crawler below already gets
        // "Allow: /", and no sane robots config disallows those paths. Dropping
        // them keeps our addition syntactically valid and minimal.
        //
        // We add NO Crawl-delay directive (Googlebot ignores it and it trips a
        // Search Console warning); if a connected site shows "Crawl-delay",
        // that comes from Yoast/another plugin, not from this connector.
        $groups = array( 'GPTBot', 'Google-Extended', 'PerplexityBot', 'ClaudeBot', 'Applebot-Extended' );
        $output .= "\n# Dsquared Hub Connector — AI crawler access\n";
        foreach ( $groups as $ua ) {
            $output .= "User-agent: {$ua}\n";
            $output .= "Allow: /\n";
        }
        return $output;
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
    }

    public function check_api_key( $request ) {
        $result = DHC_API_Key::authenticate_request( $request );
        return ( true === $result );
    }

    public function save_profile( $request ) {
        $data = $request->get_json_params();

        $allowed_fields = array(
            'business_name', 'business_type', 'description', 'phone', 'email',
            'address', 'street', 'city', 'state', 'zip', 'country',
            'hours', 'logo_url', 'years_in_business',
            'services', 'service_areas', 'faqs', 'certifications',
            'brands', 'unique_selling_points', 'social_profiles',
        );

        $profile = array();
        foreach ( $allowed_fields as $field ) {
            if ( isset( $data[ $field ] ) ) {
                $profile[ $field ] = $data[ $field ];
            }
        }

        // Save to both option names for compatibility
        update_option( 'dhc_business_profile', $profile );
        update_option( 'dhc_ai_business_profile', $profile );

        // Write physical llms.txt + llms-full.txt files in WP root.
        // This is the ONLY reliable way to serve these on hosts whose
        // nginx config short-circuits .txt requests with a try_files
        // =404 directive (which is most common WordPress nginx setups).
        // The dynamic handlers below stay as a fallback for hosts that
        // do route .txt to PHP.
        $static_result = $this->regenerate_static_files( $profile );
        $static_files = is_wp_error( $static_result ) ? array() : $static_result;
        if ( is_wp_error( $static_result ) ) {
            $this->log_activity( 'Static file write failed: ' . $static_result->get_error_message() );
        } else {
            $this->log_activity( 'Static llms.txt files written: ' . implode( ', ', $static_files ) );
        }

        // Flush rewrite rules so llms.txt works
        flush_rewrite_rules();

        // Ping IndexNow for the homepage
        $this->send_indexnow( array( home_url( '/' ) ) );

        $this->log_activity( 'Business profile updated via Hub' );

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
        $profile = get_option( 'dhc_business_profile', array() );
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
        $this->send_indexnow( $urls );

        return new WP_REST_Response( array(
            'success' => true,
            'message' => 'IndexNow ping sent for ' . count( $urls ) . ' URL(s).',
        ), 200 );
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

    private function log_activity( $message ) {
        $log = get_option( 'dhc_activity_log', array() );
        array_unshift( $log, array(
            'message' => $message,
            'module'  => 'ai-discovery',
            'time'    => current_time( 'mysql' ),
        ) );
        $log = array_slice( $log, 0, 200 );
        update_option( 'dhc_activity_log', $log );
    }
}
