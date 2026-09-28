<?php
/**
 * DHC_Schema — Module 2: Schema Markup Injector
 *
 * Receives structured data (JSON-LD) from the Hub's Schema Generator
 * and injects it into the appropriate WordPress pages/posts.
 * Stores schema per-post in post meta, or site-wide in options.
 *
 * @package Dsquared_Hub_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DHC_Schema {

    const META_KEY       = '_d2_custom_schema';
    const SOURCE_META_KEY = '_d2_custom_schema_source';
    const ERROR_META_KEY = '_d2_custom_schema_error';
    const GUIDED_META_KEY = '_d2_custom_schema_guided';
    const MODE_META_KEY  = '_d2_custom_schema_mode';
    const LEGACY_META_KEY = '_dhc_schema_markup';
    const GLOBAL_OPTION  = 'dhc_global_schemas';
    const NONCE_ACTION   = 'dhc_save_page_schema';
    const NONCE_NAME     = 'dhc_schema_nonce';

    /**
     * Initialize — hook into wp_head to output schema
     */
    public static function init() {
        add_action( 'wp_head', array( __CLASS__, 'output_schema' ), 99 );
        add_action( 'add_meta_boxes', array( __CLASS__, 'register_meta_box' ) );
        add_action( 'save_post', array( __CLASS__, 'save_meta_box' ), 10, 2 );
        add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
        add_filter( 'manage_pages_columns', array( __CLASS__, 'pages_column' ) );
        add_action( 'manage_pages_custom_column', array( __CLASS__, 'pages_column_value' ), 10, 2 );
    }

    /** Parse raw JSON-LD without double encoding it. */
    public static function validate_json( $raw ) {
        $raw = trim( (string) $raw );
        if ( '' === $raw ) return array( 'valid' => true, 'value' => null, 'error' => '' );
        $value = json_decode( $raw, true );
        if ( JSON_ERROR_NONE !== json_last_error() ) return array( 'valid' => false, 'value' => null, 'error' => json_last_error_msg() );
        if ( ! is_array( $value ) ) return array( 'valid' => false, 'value' => null, 'error' => 'Top-level JSON-LD must be an object or array.' );
        return array( 'valid' => true, 'value' => $value, 'error' => '' );
    }

    private static function schema_json( $value ) {
        return wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
    }

    private static function canonical_value( $post_id ) {
        $raw = get_post_meta( $post_id, self::META_KEY, true );
        if ( is_string( $raw ) && '' !== trim( $raw ) ) return $raw;
        if ( is_array( $raw ) ) return self::schema_json( $raw );
        $legacy = get_post_meta( $post_id, self::LEGACY_META_KEY, true );
        if ( ! is_array( $legacy ) ) return '';
        $nodes = array();
        foreach ( $legacy as $entry ) if ( isset( $entry['markup'] ) && is_array( $entry['markup'] ) ) $nodes[] = $entry['markup'];
        if ( 1 === count( $nodes ) ) return self::schema_json( $nodes[0] );
        return count( $nodes ) > 1 ? self::schema_json( array( '@context' => 'https://schema.org', '@graph' => $nodes ) ) : '';
    }

    private static function write_schema( $post_id, $raw, $source, $guided = null, $mode = 'raw' ) {
        update_post_meta( $post_id, self::META_KEY, (string) $raw );
        update_post_meta( $post_id, self::SOURCE_META_KEY, sanitize_key( $source ) );
        update_post_meta( $post_id, self::MODE_META_KEY, 'guided' === $mode ? 'guided' : 'raw' );
        if ( null !== $guided ) update_post_meta( $post_id, self::GUIDED_META_KEY, $guided );
        else delete_post_meta( $post_id, self::GUIDED_META_KEY );
        $checked = self::validate_json( $raw );
        if ( $checked['valid'] ) delete_post_meta( $post_id, self::ERROR_META_KEY );
        else update_post_meta( $post_id, self::ERROR_META_KEY, $checked['error'] );
        return $checked;
    }

    /** Resolve an exact local permalink, including a static front page. */
    public static function resolve_page_url( $page_url ) {
        $page_url = esc_url_raw( (string) $page_url );
        if ( '' === $page_url ) return 0;
        $requested = wp_parse_url( $page_url );
        $home = wp_parse_url( home_url( '/' ) );
        if ( empty( $requested['host'] ) || empty( $home['host'] ) || strtolower( preg_replace( '/^www\./', '', $requested['host'] ) ) !== strtolower( preg_replace( '/^www\./', '', $home['host'] ) ) ) return 0;
        $requested_path = untrailingslashit( isset( $requested['path'] ) ? $requested['path'] : '/' );
        $home_path = untrailingslashit( isset( $home['path'] ) ? $home['path'] : '/' );
        if ( $requested_path === $home_path ) return (int) get_option( 'page_on_front', 0 );
        $post_id = (int) DHC_Core::resolve_post_id_from_url( $page_url );
        if ( $post_id < 1 ) return 0;
        $permalink = wp_parse_url( get_permalink( $post_id ) );
        $permalink_path = untrailingslashit( isset( $permalink['path'] ) ? $permalink['path'] : '/' );
        return $permalink_path === $requested_path ? $post_id : 0;
    }

    /**
     * Handle incoming schema push from the Hub
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public static function handle_request( $request ) {
        if ( ! DHC_API_Key::is_module_available( 'schema' ) ) {
            return new WP_Error(
                'dhc_module_unavailable',
                'Schema Injector module is not available on your current subscription tier. Upgrade to Growth or Pro to access this feature.',
                array( 'status' => 403 )
            );
        }

        $schema      = $request->get_param( 'schema' );
        $post_id     = (int) $request->get_param( 'post_id' );
        $url         = $request->get_param( 'page_url' );
        if ( empty( $url ) ) $url = $request->get_param( 'url' );
        $schema_type = $request->get_param( 'schema_type' ) ?? 'custom';

        if ( empty( $schema ) ) {
            return new WP_Error(
                'dhc_missing_schema',
                'Schema markup data is required.',
                array( 'status' => 400 )
            );
        }

        $raw = is_string( $schema ) ? trim( $schema ) : self::schema_json( $schema );
        $checked = self::validate_json( $raw );
        if ( ! $checked['valid'] ) return new WP_Error( 'dhc_invalid_schema', 'Schema markup must be valid JSON: ' . $checked['error'], array( 'status' => 400 ) );

        // Resolve and verify the exact local page whenever a URL is supplied.
        if ( ! empty( $url ) ) {
            $url_post_id = self::resolve_page_url( $url );
            if ( $url_post_id < 1 ) return new WP_Error( 'dhc_schema_page_not_found', 'The page_url did not match a published page or post on this site.', array( 'status' => 404 ) );
            if ( $post_id > 0 && $post_id !== $url_post_id ) return new WP_Error( 'dhc_schema_page_mismatch', 'The supplied post_id does not match page_url.', array( 'status' => 409 ) );
            $post_id = $url_post_id;
        }
        if ( $post_id < 1 || ! in_array( get_post_type( $post_id ), array( 'page', 'post' ), true ) ) return new WP_Error( 'dhc_schema_page_not_found', 'The page_url did not match a published page or post on this site.', array( 'status' => 404 ) );
        self::write_schema( $post_id, self::schema_json( $checked['value'] ), 'api', null, 'raw' );
        self::log_action( 'schema_updated', $post_id, $schema_type );
        return new WP_REST_Response( array( 'success' => true, 'post_id' => $post_id, 'page_url' => get_permalink( $post_id ), 'source' => 'api', 'message' => 'Schema JSON-LD saved for this page.' ), 200 );
    }

    /**
     * Output schema markup in wp_head
     */
    public static function output_schema() {
        if ( ! is_singular( array( 'page', 'post' ) ) ) return;
        $raw = self::canonical_value( get_the_ID() );
        $checked = self::validate_json( $raw );
        if ( ! $checked['valid'] || null === $checked['value'] ) return;
        self::render_json_ld( $checked['value'], 'page-schema' );
    }

    /**
     * Render a JSON-LD script tag
     *
     * @param array|object $schema Schema data.
     * @param string       $id     Identifier for the script tag.
     */
    private static function render_json_ld( $schema, $id = '' ) {
        $json = wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
        if ( ! $json ) {
            return;
        }

        echo "\n<!-- Dsquared Hub Schema: " . esc_attr( $id ) . " -->\n";
        echo '<script type="application/ld+json">' . "\n";
        echo $json . "\n";
        echo '</script>' . "\n";
    }

    public static function register_meta_box() {
        add_meta_box( 'dhc-page-schema', 'Schema (JSON-LD)', array( __CLASS__, 'render_meta_box' ), array( 'page', 'post' ), 'normal', 'default' );
    }

    private static function supported_types() {
        return array( 'LocalBusiness', 'SportsActivityLocation', 'Organization', 'Article', 'FAQPage', 'Service', 'Other' );
    }

    private static function guided_from_schema( $value ) {
        if ( ! is_array( $value ) || isset( $value['@graph'] ) || empty( $value['@type'] ) || ! in_array( $value['@type'], self::supported_types(), true ) ) return false;
        $type = $value['@type'];
        $data = array( 'type' => $type, 'name' => isset( $value['name'] ) ? $value['name'] : ( isset( $value['headline'] ) ? $value['headline'] : '' ), 'description' => isset( $value['description'] ) ? $value['description'] : '', 'phone' => isset( $value['telephone'] ) ? $value['telephone'] : '' );
        $address = isset( $value['address'] ) && is_array( $value['address'] ) ? $value['address'] : array();
        $data['street'] = isset( $address['streetAddress'] ) ? $address['streetAddress'] : '';
        $data['city'] = isset( $address['addressLocality'] ) ? $address['addressLocality'] : '';
        $data['region'] = isset( $address['addressRegion'] ) ? $address['addressRegion'] : '';
        $data['postal'] = isset( $address['postalCode'] ) ? $address['postalCode'] : '';
        $data['country'] = isset( $address['addressCountry'] ) ? $address['addressCountry'] : '';
        $geo = isset( $value['geo'] ) && is_array( $value['geo'] ) ? $value['geo'] : array();
        $data['latitude'] = isset( $geo['latitude'] ) ? $geo['latitude'] : '';
        $data['longitude'] = isset( $geo['longitude'] ) ? $geo['longitude'] : '';
        $data['same_as'] = isset( $value['sameAs'] ) ? (array) $value['sameAs'] : array();
        $data['hours'] = isset( $value['openingHoursSpecification'] ) && is_array( $value['openingHoursSpecification'] ) ? $value['openingHoursSpecification'] : array();
        $parent = isset( $value['branchOf']['url'] ) ? $value['branchOf']['url'] : ( isset( $value['parentOrganization']['url'] ) ? $value['parentOrganization']['url'] : '' );
        $data['parent_id'] = $parent ? (int) url_to_postid( $parent ) : 0;
        $data['faqs'] = array();
        if ( 'FAQPage' === $type && ! empty( $value['mainEntity'] ) && is_array( $value['mainEntity'] ) ) foreach ( $value['mainEntity'] as $item ) {
            if ( isset( $item['name'], $item['acceptedAnswer']['text'] ) ) $data['faqs'][] = array( 'question' => $item['name'], 'answer' => $item['acceptedAnswer']['text'] );
        }
        return $data;
    }

    private static function field( $name, $label, $value = '', $type = 'text', $class = '' ) {
        echo '<label class="dhc-field ' . esc_attr( $class ) . '"><span>' . esc_html( $label ) . '</span><input type="' . esc_attr( $type ) . '"' . ( 'number' === $type ? ' step="any"' : '' ) . ' name="dhc_guided[' . esc_attr( $name ) . ']" value="' . esc_attr( $value ) . '"></label>';
    }

    public static function render_meta_box( $post ) {
        wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
        $raw = self::canonical_value( $post->ID );
        $checked = self::validate_json( $raw );
        $stored_guided = get_post_meta( $post->ID, self::GUIDED_META_KEY, true );
        $guided = is_array( $stored_guided ) ? $stored_guided : ( $checked['valid'] && $checked['value'] ? self::guided_from_schema( $checked['value'] ) : false );
        $compatible = is_array( $guided );
        if ( ! $compatible ) $guided = array( 'type' => 'LocalBusiness' );
        $mode = get_post_meta( $post->ID, self::MODE_META_KEY, true );
        if ( ! $compatible && $raw ) $mode = 'raw';
        if ( ! in_array( $mode, array( 'guided', 'raw' ), true ) ) $mode = 'guided';
        $source = get_post_meta( $post->ID, self::SOURCE_META_KEY, true );
        echo '<div class="dhc-schema-editor" data-mode="' . esc_attr( $mode ) . '"><div class="dhc-mode"><button type="button" data-mode="guided">Guided</button><button type="button" data-mode="raw">Raw JSON</button><span class="dhc-source">Source: ' . esc_html( 'api' === $source ? 'Dsquared Hub API' : ( $source ? ucfirst( $source ) : 'Not set' ) ) . '</span></div>';
        echo '<input type="hidden" class="dhc-mode-input" name="dhc_schema_mode" value="' . esc_attr( $mode ) . '">';
        if ( $raw && ! $compatible ) echo '<div class="notice notice-warning inline"><p>This page’s schema does not match the guided editor’s fields. Switch to Raw JSON to edit it directly. Guided mode will not replace it unless you save Guided changes.</p></div>';
        echo '<div class="dhc-guided"><div class="dhc-grid"><label class="dhc-field"><span>Schema type</span><select name="dhc_guided[type]">';
        foreach ( self::supported_types() as $type ) echo '<option value="' . esc_attr( $type ) . '"' . selected( isset( $guided['type'] ) ? $guided['type'] : '', $type, false ) . '>' . esc_html( $type ) . '</option>';
        echo '</select></label>';
        self::field( 'name', 'Name', isset( $guided['name'] ) ? $guided['name'] : '', 'text', 'dhc-common' );
        echo '<label class="dhc-field dhc-full dhc-common"><span>Description</span><textarea name="dhc_guided[description]">' . esc_textarea( isset( $guided['description'] ) ? $guided['description'] : '' ) . '</textarea></label>';
        self::field( 'phone', 'Phone', isset( $guided['phone'] ) ? $guided['phone'] : '', 'tel', 'dhc-location dhc-service' );
        self::field( 'street', 'Street address', isset( $guided['street'] ) ? $guided['street'] : '', 'text', 'dhc-location' );
        self::field( 'city', 'City', isset( $guided['city'] ) ? $guided['city'] : '', 'text', 'dhc-location' );
        self::field( 'region', 'State / region', isset( $guided['region'] ) ? $guided['region'] : '', 'text', 'dhc-location' );
        self::field( 'postal', 'Postal code', isset( $guided['postal'] ) ? $guided['postal'] : '', 'text', 'dhc-location' );
        self::field( 'country', 'Country', isset( $guided['country'] ) ? $guided['country'] : '', 'text', 'dhc-location' );
        self::field( 'latitude', 'Latitude', isset( $guided['latitude'] ) ? $guided['latitude'] : '', 'number', 'dhc-location dhc-lat' );
        self::field( 'longitude', 'Longitude', isset( $guided['longitude'] ) ? $guided['longitude'] : '', 'number', 'dhc-location dhc-lng' );
        echo '<p class="description dhc-full dhc-location">Use exact coordinates from <a href="https://maps.google.com" target="_blank" rel="noopener noreferrer">Google Maps</a>. Latitude and longitude must be entered together.</p>';
        echo '<label class="dhc-field dhc-full dhc-common"><span>sameAs URLs <small>(one per line)</small></span><textarea name="dhc_guided[same_as]">' . esc_textarea( implode( "\n", isset( $guided['same_as'] ) ? (array) $guided['same_as'] : array() ) ) . '</textarea></label>';
        echo '<label class="dhc-field dhc-full dhc-location"><span>branchOf / parent organization</span><select name="dhc_guided[parent_id]"><option value="0">None</option>';
        $parents = get_posts( array( 'post_type' => array( 'page', 'post' ), 'posts_per_page' => -1, 'post_status' => array( 'publish', 'draft' ), 'meta_key' => self::META_KEY, 'orderby' => 'title', 'order' => 'ASC', 'exclude' => array( $post->ID ) ) );
        foreach ( $parents as $parent ) echo '<option value="' . (int) $parent->ID . '"' . selected( isset( $guided['parent_id'] ) ? (int) $guided['parent_id'] : 0, $parent->ID, false ) . '>' . esc_html( get_the_title( $parent ) ) . '</option>';
        echo '</select></label></div>';
        self::render_hours( isset( $guided['hours'] ) ? $guided['hours'] : array() );
        self::render_faqs( isset( $guided['faqs'] ) ? $guided['faqs'] : array() );
        echo '<p class="dhc-coordinate-warning" role="alert"></p></div>';
        echo '<div class="dhc-raw"><label class="dhc-field"><span>JSON-LD</span><textarea name="dhc_schema_raw" rows="18" spellcheck="false" class="code">' . esc_textarea( $raw ) . '</textarea></label><p class="description">Accepts one JSON object, a JSON array, or an object containing an @graph array.</p></div></div>';
        self::editor_assets();
    }

    private static function render_hours( $hours ) {
        echo '<div class="dhc-repeater dhc-location"><strong>Opening hours</strong><div data-hours-list>';
        if ( empty( $hours ) ) $hours = array( array( 'dayOfWeek' => '', 'opens' => '', 'closes' => '' ) );
        foreach ( $hours as $i => $row ) {
            $day = isset( $row['dayOfWeek'] ) ? $row['dayOfWeek'] : '';
            echo '<div class="dhc-repeat-row"><select name="dhc_guided[hours][' . (int) $i . '][dayOfWeek]"><option value="">Day</option>';
            foreach ( array( 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday' ) as $choice ) echo '<option' . selected( $day, $choice, false ) . '>' . esc_html( $choice ) . '</option>';
            echo '</select><input type="time" name="dhc_guided[hours][' . (int) $i . '][opens]" value="' . esc_attr( isset( $row['opens'] ) ? $row['opens'] : '' ) . '"><input type="time" name="dhc_guided[hours][' . (int) $i . '][closes]" value="' . esc_attr( isset( $row['closes'] ) ? $row['closes'] : '' ) . '"></div>';
        }
        echo '</div><button type="button" class="button" data-add-hours>Add hours</button></div>';
    }

    private static function render_faqs( $faqs ) {
        echo '<div class="dhc-repeater dhc-faq"><strong>Questions and answers</strong><div data-faq-list>';
        if ( empty( $faqs ) ) $faqs = array( array( 'question' => '', 'answer' => '' ) );
        foreach ( $faqs as $i => $row ) echo '<div class="dhc-repeat-row"><input type="text" name="dhc_guided[faqs][' . (int) $i . '][question]" placeholder="Question" value="' . esc_attr( isset( $row['question'] ) ? $row['question'] : '' ) . '"><textarea name="dhc_guided[faqs][' . (int) $i . '][answer]" placeholder="Answer">' . esc_textarea( isset( $row['answer'] ) ? $row['answer'] : '' ) . '</textarea></div>';
        echo '</div><button type="button" class="button" data-add-faq>Add question</button></div>';
    }

    private static function editor_assets() {
        echo '<style>.dhc-mode{display:flex;gap:6px;align-items:center;margin-bottom:14px}.dhc-mode button{border:1px solid #dcdcde;background:#fff;padding:7px 12px;border-radius:4px}.dhc-schema-editor[data-mode="guided"] [data-mode="guided"],.dhc-schema-editor[data-mode="raw"] [data-mode="raw"]{background:#1d2327;color:#fff}.dhc-source{margin-left:auto;color:#646970}.dhc-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.dhc-field{display:grid;gap:5px}.dhc-field span{font-weight:600}.dhc-field input,.dhc-field select,.dhc-field textarea{width:100%;max-width:none}.dhc-full{grid-column:1/-1}.dhc-raw{display:none}.dhc-schema-editor[data-mode="raw"] .dhc-guided{display:none}.dhc-schema-editor[data-mode="raw"] .dhc-raw{display:block}.dhc-repeater{margin-top:16px}.dhc-repeat-row{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin:7px 0}.dhc-faq .dhc-repeat-row{grid-template-columns:1fr 2fr}.dhc-coordinate-warning{color:#b32d2e;font-weight:600}.dhc-faq{display:none}@media(max-width:782px){.dhc-grid{grid-template-columns:1fr}.dhc-full{grid-column:auto}}</style>';
        echo '<script>(function(){var root=document.querySelector(".dhc-schema-editor");if(!root)return;var mode=root.querySelector(".dhc-mode-input"),type=root.querySelector("select[name=\\"dhc_guided[type]\\"]"),lat=root.querySelector("input[name=\\"dhc_guided[latitude]\\"]"),lng=root.querySelector("input[name=\\"dhc_guided[longitude]\\"]"),warn=root.querySelector(".dhc-coordinate-warning");function show(){var t=type.value,location=t==="LocalBusiness"||t==="SportsActivityLocation",faq=t==="FAQPage",service=t==="Service";root.querySelectorAll(".dhc-location").forEach(function(n){n.style.display=location?"":"none"});root.querySelectorAll(".dhc-service").forEach(function(n){if(service)n.style.display=""});root.querySelectorAll(".dhc-faq").forEach(function(n){n.style.display=faq?"block":"none"})}function pair(){warn.textContent=((lat.value&&!lng.value)||(!lat.value&&lng.value))?"Latitude and longitude must be entered together.":""}root.querySelectorAll(".dhc-mode button").forEach(function(b){b.addEventListener("click",function(){root.dataset.mode=b.dataset.mode;mode.value=b.dataset.mode})});type.addEventListener("change",show);lat.addEventListener("input",pair);lng.addEventListener("input",pair);root.querySelector("[data-add-hours]").addEventListener("click",function(){var list=root.querySelector("[data-hours-list]"),i=list.children.length,row=list.firstElementChild.cloneNode(true);row.querySelectorAll("input,select").forEach(function(n){n.name=n.name.replace(/hours\\]\\[\\d+/,"hours]["+i);n.value=""});list.appendChild(row)});root.querySelector("[data-add-faq]").addEventListener("click",function(){var list=root.querySelector("[data-faq-list]"),i=list.children.length,row=list.firstElementChild.cloneNode(true);row.querySelectorAll("input,textarea").forEach(function(n){n.name=n.name.replace(/faqs\\]\\[\\d+/,"faqs]["+i);n.value=""});list.appendChild(row)});show();pair()})();</script>';
    }

    private static function clean( $value ) { return sanitize_text_field( is_scalar( $value ) ? (string) $value : '' ); }

    private static function build_guided_schema( $data ) {
        $type = isset( $data['type'] ) && in_array( $data['type'], self::supported_types(), true ) ? $data['type'] : 'LocalBusiness';
        if ( 'Other' === $type ) $type = 'Thing';
        $schema = array( '@context' => 'https://schema.org', '@type' => $type );
        $name = self::clean( isset( $data['name'] ) ? $data['name'] : '' );
        $description = sanitize_textarea_field( isset( $data['description'] ) ? $data['description'] : '' );
        if ( $name ) $schema[ 'Article' === $type ? 'headline' : 'name' ] = $name;
        if ( $description ) $schema['description'] = $description;
        $phone = self::clean( isset( $data['phone'] ) ? $data['phone'] : '' );
        if ( $phone ) $schema['telephone'] = $phone;
        if ( in_array( $type, array( 'LocalBusiness', 'SportsActivityLocation' ), true ) ) {
            $address = array( '@type' => 'PostalAddress' );
            foreach ( array( 'street' => 'streetAddress', 'city' => 'addressLocality', 'region' => 'addressRegion', 'postal' => 'postalCode', 'country' => 'addressCountry' ) as $input => $property ) { $value = self::clean( isset( $data[$input] ) ? $data[$input] : '' ); if ( $value ) $address[$property] = $value; }
            if ( count( $address ) > 1 ) $schema['address'] = $address;
            $lat = self::clean( isset( $data['latitude'] ) ? $data['latitude'] : '' ); $lng = self::clean( isset( $data['longitude'] ) ? $data['longitude'] : '' );
            if ( ( $lat && ! $lng ) || ( $lng && ! $lat ) ) return new WP_Error( 'dhc_coordinate_pair', 'Latitude and longitude must be entered together.' );
            if ( $lat && $lng ) $schema['geo'] = array( '@type' => 'GeoCoordinates', 'latitude' => (float) $lat, 'longitude' => (float) $lng );
            $hours = array();
            foreach ( isset( $data['hours'] ) ? (array) $data['hours'] : array() as $row ) if ( ! empty( $row['dayOfWeek'] ) && ! empty( $row['opens'] ) && ! empty( $row['closes'] ) ) $hours[] = array( '@type' => 'OpeningHoursSpecification', 'dayOfWeek' => self::clean( $row['dayOfWeek'] ), 'opens' => self::clean( $row['opens'] ), 'closes' => self::clean( $row['closes'] ) );
            if ( $hours ) $schema['openingHoursSpecification'] = $hours;
            $parent_id = isset( $data['parent_id'] ) ? (int) $data['parent_id'] : 0;
            if ( $parent_id ) $schema['branchOf'] = array( '@type' => 'Organization', 'url' => get_permalink( $parent_id ) );
        }
        $urls = preg_split( '/\r\n|\r|\n/', isset( $data['same_as'] ) ? (string) $data['same_as'] : '' );
        $urls = array_values( array_filter( array_map( 'esc_url_raw', $urls ) ) );
        if ( $urls ) $schema['sameAs'] = $urls;
        if ( 'FAQPage' === $type ) {
            $entities = array();
            foreach ( isset( $data['faqs'] ) ? (array) $data['faqs'] : array() as $row ) { $q = self::clean( isset( $row['question'] ) ? $row['question'] : '' ); $a = sanitize_textarea_field( isset( $row['answer'] ) ? $row['answer'] : '' ); if ( $q && $a ) $entities[] = array( '@type' => 'Question', 'name' => $q, 'acceptedAnswer' => array( '@type' => 'Answer', 'text' => $a ) ); }
            if ( $entities ) $schema['mainEntity'] = $entities;
        }
        return $schema;
    }

    public static function save_meta_box( $post_id, $post ) {
        if ( ! isset( $_POST[ self::NONCE_NAME ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) || ! in_array( $post->post_type, array( 'page', 'post' ), true ) ) return;
        $mode = isset( $_POST['dhc_schema_mode'] ) && 'raw' === $_POST['dhc_schema_mode'] ? 'raw' : 'guided';
        if ( 'raw' === $mode ) {
            $raw = isset( $_POST['dhc_schema_raw'] ) ? trim( wp_unslash( $_POST['dhc_schema_raw'] ) ) : '';
            self::write_schema( $post_id, $raw, 'manual', null, 'raw' );
            return;
        }
        $data = isset( $_POST['dhc_guided'] ) && is_array( $_POST['dhc_guided'] ) ? wp_unslash( $_POST['dhc_guided'] ) : array();
        $schema = self::build_guided_schema( $data );
        if ( is_wp_error( $schema ) ) { update_post_meta( $post_id, self::ERROR_META_KEY, $schema->get_error_message() ); return; }
        self::write_schema( $post_id, self::schema_json( $schema ), 'manual', $data, 'guided' );
    }

    public static function admin_notice() {
        if ( ! function_exists( 'get_current_screen' ) ) return;
        $screen = get_current_screen();
        if ( ! $screen || 'post' !== $screen->base || empty( $_GET['post'] ) ) return;
        $error = get_post_meta( (int) $_GET['post'], self::ERROR_META_KEY, true );
        if ( $error ) echo '<div class="notice notice-error"><p><strong>Schema (JSON-LD) was saved with a validation issue:</strong> ' . esc_html( $error ) . ' Invalid JSON-LD will not be printed on the public page.</p></div>';
    }

    public static function pages_column( $columns ) {
        $columns['dhc_schema'] = 'Schema';
        return $columns;
    }

    public static function pages_column_value( $column, $post_id ) {
        if ( 'dhc_schema' !== $column ) return;
        $raw = self::canonical_value( $post_id );
        if ( ! $raw ) { echo '<span aria-label="No schema">—</span>'; return; }
        $source = get_post_meta( $post_id, self::SOURCE_META_KEY, true );
        $error = get_post_meta( $post_id, self::ERROR_META_KEY, true );
        echo $error ? '<span style="color:#b32d2e">Needs review</span>' : '<span style="color:#008a20">Set</span>';
        echo '<br><small>' . esc_html( 'api' === $source ? 'Hub API' : 'Manual' ) . '</small>';
    }

    /**
     * Get all schemas for a post (used in admin UI)
     *
     * @param int $post_id Post ID.
     * @return array
     */
    public static function get_post_schemas( $post_id ) {
        $raw = self::canonical_value( $post_id );
        $checked = self::validate_json( $raw );
        return $checked['valid'] && $checked['value'] ? array( 'custom' => array( 'markup' => $checked['value'], 'source' => get_post_meta( $post_id, self::SOURCE_META_KEY, true ) ) ) : array();
    }

    /**
     * Delete a specific schema type from a post
     *
     * @param int    $post_id     Post ID.
     * @param string $schema_type Schema type to remove.
     * @return bool
     */
    public static function delete_post_schema( $post_id, $schema_type ) {
        delete_post_meta( $post_id, self::META_KEY );
        delete_post_meta( $post_id, self::SOURCE_META_KEY );
        delete_post_meta( $post_id, self::ERROR_META_KEY );
        delete_post_meta( $post_id, self::GUIDED_META_KEY );
        delete_post_meta( $post_id, self::MODE_META_KEY );
        return true;
    }

    /**
     * Log schema actions
     */
    private static function log_action( $action, $post_id, $schema_type ) {
        $log = get_option( 'dhc_activity_log', array() );
        if ( count( $log ) >= 50 ) {
            $log = array_slice( $log, -49 );
        }
        $log[] = array(
            'action'      => $action,
            'post_id'     => $post_id,
            'schema_type' => $schema_type,
            'time'        => current_time( 'mysql' ),
        );
        update_option( 'dhc_activity_log', $log );
    }
}
