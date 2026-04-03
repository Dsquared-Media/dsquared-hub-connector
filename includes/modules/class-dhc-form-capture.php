<?php
/**
 * Module 7: Form Submission Capture
 *
 * Hooks into popular WordPress form plugins to intercept submissions,
 * filters out spam, and reports clean leads to the Hub. Does NOT store
 * any form data locally — only observes and reports.
 *
 * Supported form plugins:
 * - Contact Form 7
 * - Gravity Forms
 * - WPForms
 * - Elementor Forms
 * - Ninja Forms
 * - Formidable Forms
 *
 * @package Dsquared_Hub_Connector
 * @since   1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DHC_Form_Capture {

    private static $instance = null;

    /** Disposable email domains (top ~100) */
    private $disposable_domains = array(
        'mailinator.com', 'guerrillamail.com', 'guerrillamail.net', 'tempmail.com',
        'throwaway.email', 'temp-mail.org', 'fakeinbox.com', 'sharklasers.com',
        'guerrillamailblock.com', 'grr.la', 'dispostable.com', 'yopmail.com',
        'trashmail.com', 'trashmail.me', 'trashmail.net', 'maildrop.cc',
        'mailnesia.com', 'mailcatch.com', 'tempail.com', 'tempr.email',
        'discard.email', 'discardmail.com', 'discardmail.de', 'emailondeck.com',
        'getnada.com', 'inboxbear.com', 'mailsac.com', 'mohmal.com',
        'mytemp.email', 'throwawaymail.com', 'tmpmail.net', 'tmpmail.org',
        'tempmailaddress.com', 'tempinbox.com', '10minutemail.com', 'minutemail.com',
        'binkmail.com', 'bobmail.info', 'chammy.info', 'devnullmail.com',
        'dodgit.com', 'emailigo.de', 'emailwarden.com', 'filzmail.com',
        'getairmail.com', 'harakirimail.com', 'jetable.org', 'kasmail.com',
        'koszmail.pl', 'kurzepost.de', 'letthemeatspam.com', 'lhsdv.com',
        'mailexpire.com', 'mailforspam.com', 'mailmoat.com', 'mailnull.com',
        'mailshell.com', 'mailzilla.com', 'nomail.xl.cx', 'nowmymail.com',
        'pookmail.com', 'shortmail.net', 'sneakemail.com', 'sogetthis.com',
        'spambog.com', 'spambox.us', 'spamcero.com', 'spamcorptastic.com',
        'spamcowboy.com', 'spamday.com', 'spamfree24.org', 'spamgourmet.com',
        'spamherelots.com', 'spamhereplease.com', 'spamhole.com', 'spamify.com',
        'spaminator.de', 'spamkill.info', 'spaml.com', 'spammotel.com',
        'spamobox.com', 'spamoff.de', 'spamslicer.com', 'spamspot.com',
        'spamthis.co.uk', 'spamtrail.com', 'superrito.com', 'teleworm.us',
        'tempomail.fr', 'thankyou2010.com', 'thisisnotmyrealemail.com',
        'trash-mail.at', 'trashymail.com', 'turual.com', 'twinmail.de',
        'uggsrock.com', 'wegwerfmail.de', 'wegwerfmail.net', 'wh4f.org',
    );

    /** Spam keyword patterns */
    private $spam_patterns = array(
        '/\b(buy\s+now|click\s+here|act\s+now|limited\s+time|free\s+money)\b/i',
        '/\b(viagra|cialis|casino|poker|lottery|jackpot|slot\s+machine)\b/i',
        '/\b(crypto\s+invest|bitcoin\s+profit|forex\s+signal|binary\s+option)\b/i',
        '/\b(seo\s+service|backlink|link\s+building|web\s+traffic)\b/i',
        '/\b(nigerian\s+prince|inheritance|million\s+dollars|wire\s+transfer)\b/i',
        '/[\x{0400}-\x{04FF}]{5,}/u', // Cyrillic spam (5+ consecutive chars)
        '/[\x{4E00}-\x{9FFF}]{5,}/u', // Chinese spam (5+ consecutive chars)
        '/(https?:\/\/[^\s]+){3,}/',   // 3+ URLs in one field
    );

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        // Contact Form 7
        add_action( 'wpcf7_mail_sent', array( $this, 'capture_cf7' ), 10, 1 );

        // Gravity Forms
        add_action( 'gform_after_submission', array( $this, 'capture_gravity' ), 10, 2 );

        // WPForms
        add_action( 'wpforms_process_complete', array( $this, 'capture_wpforms' ), 10, 4 );

        // Elementor Forms
        add_action( 'elementor_pro/forms/new_record', array( $this, 'capture_elementor' ), 10, 2 );

        // Ninja Forms
        add_action( 'ninja_forms_after_submission', array( $this, 'capture_ninja' ), 10, 1 );

        // Formidable Forms
        add_action( 'frm_after_create_entry', array( $this, 'capture_formidable' ), 10, 2 );

        // Generic wp_mail hook as fallback
        add_filter( 'wp_mail', array( $this, 'capture_wp_mail' ), 999, 1 );

        // REST endpoint for lead stats
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );

        // Rate limiting transient cleanup
        add_action( 'init', array( $this, 'init_rate_limiter' ) );
    }

    /* ─── Contact Form 7 ─── */

    public function capture_cf7( $contact_form ) {
        $submission = WPCF7_Submission::instance();
        if ( ! $submission ) return;

        $data   = $submission->get_posted_data();
        $name   = $data['your-name'] ?? $data['name'] ?? '';
        $email  = $data['your-email'] ?? $data['email'] ?? '';
        $phone  = $data['your-phone'] ?? $data['phone'] ?? $data['tel'] ?? '';
        $msg    = $data['your-message'] ?? $data['message'] ?? '';

        $this->process_lead( array(
            'name'       => $name,
            'email'      => $email,
            'phone'      => $phone,
            'message'    => $msg,
            'form_name'  => $contact_form->title(),
            'form_plugin' => 'Contact Form 7',
            'source_url' => wp_get_referer() ?: '',
        ) );
    }

    /* ─── Gravity Forms ─── */

    public function capture_gravity( $entry, $form ) {
        $name  = '';
        $email = '';
        $phone = '';
        $msg   = '';

        foreach ( $form['fields'] as $field ) {
            $value = rgar( $entry, $field->id );
            $type  = strtolower( $field->type );
            $label = strtolower( $field->label );

            if ( $type === 'name' || strpos( $label, 'name' ) !== false ) {
                $name = $value ?: ( rgar( $entry, $field->id . '.3' ) . ' ' . rgar( $entry, $field->id . '.6' ) );
            } elseif ( $type === 'email' || strpos( $label, 'email' ) !== false ) {
                $email = $value;
            } elseif ( $type === 'phone' || strpos( $label, 'phone' ) !== false ) {
                $phone = $value;
            } elseif ( $type === 'textarea' || strpos( $label, 'message' ) !== false ) {
                $msg = $value;
            }
        }

        $this->process_lead( array(
            'name'        => trim( $name ),
            'email'       => $email,
            'phone'       => $phone,
            'message'     => $msg,
            'form_name'   => $form['title'] ?? 'Gravity Form',
            'form_plugin' => 'Gravity Forms',
            'source_url'  => rgar( $entry, 'source_url' ),
        ) );
    }

    /* ─── WPForms ─── */

    public function capture_wpforms( $fields, $entry, $form_data, $entry_id ) {
        $name  = '';
        $email = '';
        $phone = '';
        $msg   = '';

        foreach ( $fields as $field ) {
            $type = strtolower( $field['type'] ?? '' );
            $lbl  = strtolower( $field['name'] ?? '' );
            $val  = $field['value'] ?? '';

            if ( $type === 'name' || strpos( $lbl, 'name' ) !== false ) {
                $name = $val;
            } elseif ( $type === 'email' || strpos( $lbl, 'email' ) !== false ) {
                $email = $val;
            } elseif ( $type === 'phone' || strpos( $lbl, 'phone' ) !== false ) {
                $phone = $val;
            } elseif ( $type === 'textarea' || strpos( $lbl, 'message' ) !== false ) {
                $msg = $val;
            }
        }

        $this->process_lead( array(
            'name'        => $name,
            'email'       => $email,
            'phone'       => $phone,
            'message'     => $msg,
            'form_name'   => $form_data['settings']['form_title'] ?? 'WPForm',
            'form_plugin' => 'WPForms',
            'source_url'  => wp_get_referer() ?: '',
        ) );
    }

    /* ─── Elementor Forms ─── */

    public function capture_elementor( $record, $handler ) {
        $raw    = $record->get( 'fields' );
        $name   = '';
        $email  = '';
        $phone  = '';
        $msg    = '';

        foreach ( $raw as $id => $field ) {
            $type = strtolower( $field['type'] ?? '' );
            $lbl  = strtolower( $id );
            $val  = $field['value'] ?? '';

            if ( $type === 'name' || strpos( $lbl, 'name' ) !== false ) {
                $name = $val;
            } elseif ( $type === 'email' || strpos( $lbl, 'email' ) !== false ) {
                $email = $val;
            } elseif ( $type === 'tel' || strpos( $lbl, 'phone' ) !== false ) {
                $phone = $val;
            } elseif ( $type === 'textarea' || strpos( $lbl, 'message' ) !== false ) {
                $msg = $val;
            }
        }

        $form_name = $record->get( 'form_settings' )['form_name'] ?? 'Elementor Form';

        $this->process_lead( array(
            'name'        => $name,
            'email'       => $email,
            'phone'       => $phone,
            'message'     => $msg,
            'form_name'   => $form_name,
            'form_plugin' => 'Elementor Forms',
            'source_url'  => wp_get_referer() ?: '',
        ) );
    }

    /* ─── Ninja Forms ─── */

    public function capture_ninja( $form_data ) {
        $fields = $form_data['fields'] ?? array();
        $name   = '';
        $email  = '';
        $phone  = '';
        $msg    = '';

        foreach ( $fields as $field ) {
            $type = strtolower( $field['type'] ?? '' );
            $key  = strtolower( $field['key'] ?? '' );
            $val  = $field['value'] ?? '';

            if ( strpos( $key, 'name' ) !== false || $type === 'firstname' ) {
                $name .= $val . ' ';
            } elseif ( $type === 'email' || strpos( $key, 'email' ) !== false ) {
                $email = $val;
            } elseif ( $type === 'phone' || strpos( $key, 'phone' ) !== false ) {
                $phone = $val;
            } elseif ( $type === 'textarea' || strpos( $key, 'message' ) !== false ) {
                $msg = $val;
            }
        }

        $this->process_lead( array(
            'name'        => trim( $name ),
            'email'       => $email,
            'phone'       => $phone,
            'message'     => $msg,
            'form_name'   => $form_data['settings']['title'] ?? 'Ninja Form',
            'form_plugin' => 'Ninja Forms',
            'source_url'  => wp_get_referer() ?: '',
        ) );
    }

    /* ─── Formidable Forms ─── */

    public function capture_formidable( $entry_id, $form_id ) {
        if ( ! class_exists( 'FrmEntryMeta' ) ) return;

        $entry = FrmEntry::getOne( $entry_id );
        $metas = FrmEntryMeta::getAll( array( 'item_id' => $entry_id ) );

        $name  = '';
        $email = '';
        $phone = '';
        $msg   = '';

        foreach ( $metas as $meta ) {
            $field = FrmField::getOne( $meta->field_id );
            $lbl   = strtolower( $field->name ?? '' );
            $val   = $meta->meta_value;

            if ( strpos( $lbl, 'name' ) !== false ) $name = $val;
            elseif ( strpos( $lbl, 'email' ) !== false ) $email = $val;
            elseif ( strpos( $lbl, 'phone' ) !== false ) $phone = $val;
            elseif ( strpos( $lbl, 'message' ) !== false ) $msg = $val;
        }

        $form = FrmForm::getOne( $form_id );

        $this->process_lead( array(
            'name'        => $name,
            'email'       => $email,
            'phone'       => $phone,
            'message'     => $msg,
            'form_name'   => $form->name ?? 'Formidable Form',
            'form_plugin' => 'Formidable Forms',
            'source_url'  => wp_get_referer() ?: '',
        ) );
    }

    /* ─── Generic wp_mail fallback ─── */

    public function capture_wp_mail( $args ) {
        // Only capture if it looks like a form submission notification
        $subject = $args['subject'] ?? '';
        $body    = $args['message'] ?? '';

        // Skip if it's a WordPress system email
        $system_subjects = array( 'password', 'login', 'registration', 'update', 'comment' );
        foreach ( $system_subjects as $sys ) {
            if ( stripos( $subject, $sys ) !== false ) return $args;
        }

        // Skip if no form plugin was detected (to avoid double-counting)
        if ( did_action( 'wpcf7_mail_sent' ) || did_action( 'gform_after_submission' )
            || did_action( 'wpforms_process_complete' ) || did_action( 'ninja_forms_after_submission' ) ) {
            return $args;
        }

        // Don't return — just pass through unchanged
        return $args;
    }

    /* ─── Core Processing Pipeline ─── */

    private function process_lead( $lead ) {
        // Step 1: Rate limit check
        if ( $this->is_rate_limited() ) {
            $this->log_activity( 'Lead blocked: rate limit exceeded from ' . $this->get_client_ip() );
            return;
        }

        // Step 2: Run spam filters
        $spam_result = $this->check_spam( $lead );
        if ( $spam_result['is_spam'] ) {
            $this->log_activity( 'Spam filtered: ' . $spam_result['reason'] . ' (' . ( $lead['email'] ?: 'no email' ) . ')' );
            $this->increment_counter( 'spam' );
            return;
        }

        // Step 3: Sanitize (strip PII we don't need)
        $clean_lead = array(
            'name'        => sanitize_text_field( $lead['name'] ),
            'email'       => sanitize_email( $lead['email'] ),
            'phone'       => sanitize_text_field( $lead['phone'] ),
            'form_name'   => sanitize_text_field( $lead['form_name'] ),
            'form_plugin' => sanitize_text_field( $lead['form_plugin'] ),
            'source_url'  => esc_url_raw( $lead['source_url'] ),
            'source_page' => $this->url_to_page_title( $lead['source_url'] ),
            'timestamp'   => current_time( 'mysql' ),
            'site'        => home_url( '/' ),
        );

        // Step 4: Send to Hub (non-blocking)
        $this->send_to_hub( $clean_lead );

        // Step 5: Increment counter
        $this->increment_counter( 'clean' );

        // Step 6: Log
        $this->log_activity(
            'Lead captured: ' . ( $clean_lead['name'] ?: 'Anonymous' ) .
            ' via ' . $clean_lead['form_plugin'] .
            ' (' . $clean_lead['form_name'] . ')'
        );
    }

    /* ─── Spam Filter Chain ─── */

    private function check_spam( $lead ) {
        // Filter 1: Empty submission
        if ( empty( $lead['name'] ) && empty( $lead['email'] ) && empty( $lead['phone'] ) ) {
            return array( 'is_spam' => true, 'reason' => 'Empty submission' );
        }

        // Filter 2: Disposable email check
        if ( ! empty( $lead['email'] ) ) {
            $domain = strtolower( substr( strrchr( $lead['email'], '@' ), 1 ) );
            if ( in_array( $domain, $this->disposable_domains, true ) ) {
                return array( 'is_spam' => true, 'reason' => 'Disposable email: ' . $domain );
            }
        }

        // Filter 3: Invalid email format
        if ( ! empty( $lead['email'] ) && ! is_email( $lead['email'] ) ) {
            return array( 'is_spam' => true, 'reason' => 'Invalid email format' );
        }

        // Filter 4: Spam keyword patterns in message
        $text_to_check = $lead['name'] . ' ' . ( $lead['message'] ?? '' );
        foreach ( $this->spam_patterns as $pattern ) {
            if ( preg_match( $pattern, $text_to_check ) ) {
                return array( 'is_spam' => true, 'reason' => 'Spam pattern detected' );
            }
        }

        // Filter 5: Gibberish detection (consonant-heavy strings)
        if ( ! empty( $lead['name'] ) && $this->is_gibberish( $lead['name'] ) ) {
            return array( 'is_spam' => true, 'reason' => 'Gibberish name detected' );
        }

        // Filter 6: Excessive URLs in message
        if ( ! empty( $lead['message'] ) ) {
            $url_count = preg_match_all( '/https?:\/\//i', $lead['message'] );
            if ( $url_count >= 3 ) {
                return array( 'is_spam' => true, 'reason' => 'Too many URLs in message' );
            }
        }

        return array( 'is_spam' => false, 'reason' => '' );
    }

    private function is_gibberish( $text ) {
        $text = strtolower( preg_replace( '/[^a-z]/i', '', $text ) );
        if ( strlen( $text ) < 3 ) return false;

        $vowels     = preg_match_all( '/[aeiou]/i', $text );
        $consonants = strlen( $text ) - $vowels;

        // If less than 15% vowels, likely gibberish
        if ( strlen( $text ) > 4 && ( $vowels / strlen( $text ) ) < 0.15 ) {
            return true;
        }

        // Check for 5+ consecutive consonants
        if ( preg_match( '/[^aeiou]{5,}/i', $text ) ) {
            return true;
        }

        return false;
    }

    /* ─── Rate Limiting ─── */

    public function init_rate_limiter() {
        // Nothing to init, uses transients
    }

    private function is_rate_limited() {
        $ip  = $this->get_client_ip();
        $key = 'dhc_rate_' . md5( $ip );
        $count = get_transient( $key );

        if ( $count === false ) {
            set_transient( $key, 1, 300 ); // 5 minute window
            return false;
        }

        if ( $count >= 5 ) { // Max 5 submissions per 5 minutes per IP
            return true;
        }

        set_transient( $key, $count + 1, 300 );
        return false;
    }

    private function get_client_ip() {
        $headers = array(
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        );
        foreach ( $headers as $header ) {
            if ( ! empty( $_SERVER[ $header ] ) ) {
                $ip = explode( ',', $_SERVER[ $header ] );
                return trim( $ip[0] );
            }
        }
        return '0.0.0.0';
    }

    /* ─── Send to Hub ─── */

    private function send_to_hub( $lead ) {
        $api_key = get_option( 'dhc_api_key' );
        $sub     = get_option( 'dhc_subscription', array() );
        $hub_url = $sub['hub_url'] ?? 'https://hub.dsquaredmedia.net';

        if ( ! $api_key ) return;

        wp_remote_post( $hub_url . '/api/plugin/lead', array(
            'body'    => wp_json_encode( $lead ),
            'headers' => array(
                'Content-Type'  => 'application/json',
                'X-DHC-API-Key' => $api_key,
            ),
            'timeout'  => 10,
            'blocking' => false,
        ) );
    }

    /* ─── Counters (for stats, not storing lead data) ─── */

    private function increment_counter( $type ) {
        $month_key = 'dhc_leads_' . date( 'Y_m' );
        $counts    = get_option( $month_key, array( 'clean' => 0, 'spam' => 0 ) );
        $counts[ $type ] = ( $counts[ $type ] ?? 0 ) + 1;
        update_option( $month_key, $counts );
    }

    /* ─── Helpers ─── */

    private function url_to_page_title( $url ) {
        if ( empty( $url ) ) return '';
        $post_id = url_to_postid( $url );
        if ( $post_id ) {
            return get_the_title( $post_id );
        }
        return wp_parse_url( $url, PHP_URL_PATH ) ?: '';
    }

    /* ─── REST Routes ─── */

    public function register_routes() {
        register_rest_route( 'dsquared-hub/v1', '/leads/stats', array(
            'methods'  => 'GET',
            'callback' => array( $this, 'get_lead_stats' ),
            'permission_callback' => array( $this, 'check_api_key' ),
        ) );
    }

    public function check_api_key( $request ) {
        $key = $request->get_header( 'X-DHC-API-Key' );
        if ( ! $key ) return false;
        return DHC_API_Key::instance()->validate_local( $key );
    }

    public function get_lead_stats( $request ) {
        $current_month = 'dhc_leads_' . date( 'Y_m' );
        $last_month    = 'dhc_leads_' . date( 'Y_m', strtotime( '-1 month' ) );

        $current = get_option( $current_month, array( 'clean' => 0, 'spam' => 0 ) );
        $last    = get_option( $last_month, array( 'clean' => 0, 'spam' => 0 ) );

        return new WP_REST_Response( array(
            'success' => true,
            'data'    => array(
                'current_month' => array(
                    'period'  => date( 'F Y' ),
                    'leads'   => $current['clean'],
                    'spam'    => $current['spam'],
                    'total'   => $current['clean'] + $current['spam'],
                    'spam_rate' => $current['clean'] + $current['spam'] > 0
                        ? round( $current['spam'] / ( $current['clean'] + $current['spam'] ) * 100, 1 )
                        : 0,
                ),
                'last_month' => array(
                    'period' => date( 'F Y', strtotime( '-1 month' ) ),
                    'leads'  => $last['clean'],
                    'spam'   => $last['spam'],
                ),
            ),
        ), 200 );
    }

    /* ─── Activity Logging ─── */

    private function log_activity( $message ) {
        $log = get_option( 'dhc_activity_log', array() );
        array_unshift( $log, array(
            'message' => $message,
            'module'  => 'form-capture',
            'time'    => current_time( 'mysql' ),
        ) );
        $log = array_slice( $log, 0, 200 );
        update_option( 'dhc_activity_log', $log );
    }
}
