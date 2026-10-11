<?php
/**
 * DHC_Privacy — WordPress Privacy Policy integration
 *
 * Hooks into WordPress's privacy policy page generator to disclose
 * what data the plugin collects, stores, and transmits.
 *
 * @package Dsquared_Hub_Connector
 * @since   1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DHC_Privacy {

    /**
     * Initialize privacy hooks
     */
    public static function init() {
        add_action( 'admin_init', array( __CLASS__, 'add_privacy_policy_content' ) );
        add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
        add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
    }

    /**
     * Add privacy policy content suggestion
     */
    public static function add_privacy_policy_content() {
        if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
            return;
        }

        $content = self::get_privacy_policy_text();
        wp_add_privacy_policy_content( 'Dsquared Hub Connector', $content );
    }

    /**
     * Get the privacy policy text
     *
     * @return string
     */
    private static function get_privacy_policy_text() {
        $policy = '<h2>' . esc_html__( 'Dsquared Hub Connector', 'dsquared-hub-connector' ) . '</h2>';

        $policy .= '<h3>' . esc_html__( 'What Data We Collect', 'dsquared-hub-connector' ) . '</h3>';
        $policy .= '<p>' . esc_html__( 'This plugin may collect the following data from site visitors:', 'dsquared-hub-connector' ) . '</p>';
        $policy .= '<ul>';
        $policy .= '<li>' . esc_html__( 'Core Web Vitals performance metrics (LCP, CLS, INP, TTFB, FCP) — anonymous, aggregated browser performance data collected when the Site Health Monitor module is enabled.', 'dsquared-hub-connector' ) . '</li>';
        $policy .= '<li>' . esc_html__( 'Form submission data — when Form Submission Capture is enabled, the plugin stores a local review inbox containing the newest 250 clean and spam submissions. A row can include the submitted name, email, phone, message, form name and provider, source URL, request IP address, spam status, and timestamp. Clean lead details are also transmitted to the connected Dsquared Media Hub account.', 'dsquared-hub-connector' ) . '</li>';
        $policy .= '</ul>';

        $policy .= '<h3>' . esc_html__( 'How We Use This Data', 'dsquared-hub-connector' ) . '</h3>';
        $policy .= '<p>' . esc_html__( 'Performance metrics are used to monitor website health and identify areas for improvement. Form submission data is used to track lead generation and filter spam. No data is sold to third parties.', 'dsquared-hub-connector' ) . '</p>';

        $policy .= '<h3>' . esc_html__( 'External Services', 'dsquared-hub-connector' ) . '</h3>';
        $policy .= '<p>' . esc_html__( 'This plugin communicates with the following external services:', 'dsquared-hub-connector' ) . '</p>';
        $policy .= '<ul>';
        $policy .= '<li>' . esc_html__( 'Dsquared Media Hub (hub.dsquaredmedia.net) — for API key validation, data synchronization, and plugin update checks.', 'dsquared-hub-connector' ) . '</li>';
        $policy .= '<li>' . esc_html__( 'IndexNow API (api.indexnow.org, bing.com, yandex.com) — for notifying search engines of content changes when the AI Discovery module is enabled.', 'dsquared-hub-connector' ) . '</li>';
        $policy .= '</ul>';

        $policy .= '<h3>' . esc_html__( 'Data Retention', 'dsquared-hub-connector' ) . '</h3>';
        $policy .= '<p>' . esc_html__( 'Core Web Vitals data is retained locally for 90 days. Activity logs and the form review inbox are each limited to the 250 most recent entries. Monthly lead/spam counters (aggregate numbers only, no personal data) are retained for 12 months. Disabling the module, a subscription lapse, or plugin deactivation stops new collection but keeps existing local records so an administrator can review or erase them. Uninstalling the plugin removes its local records.', 'dsquared-hub-connector' ) . '</p>';

        $policy .= '<h3>' . esc_html__( 'Access and Erasure', 'dsquared-hub-connector' ) . '</h3>';
        $policy .= '<p>' . esc_html__( 'WordPress personal-data export and erasure requests can find local form inbox rows by an exact email-address match. Erasure removes matching local rows. Clean leads may also exist in the connected Dsquared Media Hub account and must be handled through that account or its operator.', 'dsquared-hub-connector' ) . '</p>';

        $policy .= '<h3>' . esc_html__( 'Plugin Deactivation', 'dsquared-hub-connector' ) . '</h3>';
        $policy .= '<p>' . esc_html__( 'If the plugin is deactivated or the subscription expires, all data collection stops immediately. Your website continues to function normally. Uninstalling the plugin removes all locally stored data.', 'dsquared-hub-connector' ) . '</p>';

        return $policy;
    }

    /**
     * Register personal data exporter
     *
     * @param array $exporters
     * @return array
     */
    public static function register_exporter( $exporters ) {
        $exporters['dsquared-hub-connector'] = array(
            'exporter_friendly_name' => esc_html__( 'Dsquared Hub Connector', 'dsquared-hub-connector' ),
            'callback'               => array( __CLASS__, 'export_personal_data' ),
        );
        return $exporters;
    }

    /**
     * Export matching local form-inbox data.
     *
     * @param string $email_address
     * @param int    $page
     * @return array
     */
    public static function export_personal_data( $email_address, $page = 1 ) {
        if ( ! class_exists( 'DHC_Form_Capture' ) || 1 !== (int) $page ) {
            return array( 'data' => array(), 'done' => true );
        }

        $data = array();
        foreach ( DHC_Form_Capture::get_submissions_for_email( $email_address ) as $row ) {
            $item_data = array();
            $fields = array(
                'name'          => esc_html__( 'Name', 'dsquared-hub-connector' ),
                'email'         => esc_html__( 'Email', 'dsquared-hub-connector' ),
                'phone'         => esc_html__( 'Phone', 'dsquared-hub-connector' ),
                'message'       => esc_html__( 'Message', 'dsquared-hub-connector' ),
                'form_name'     => esc_html__( 'Form', 'dsquared-hub-connector' ),
                'form_plugin'   => esc_html__( 'Form provider', 'dsquared-hub-connector' ),
                'source_url'    => esc_html__( 'Source URL', 'dsquared-hub-connector' ),
                'ip_address'    => esc_html__( 'IP address', 'dsquared-hub-connector' ),
                'spam_reason'   => esc_html__( 'Spam reason', 'dsquared-hub-connector' ),
                'created_at'    => esc_html__( 'Submitted at', 'dsquared-hub-connector' ),
            );
            foreach ( $fields as $key => $label ) {
                if ( isset( $row[ $key ] ) && '' !== (string) $row[ $key ] ) {
                    $item_data[] = array( 'name' => $label, 'value' => (string) $row[ $key ] );
                }
            }
            $item_data[] = array(
                'name'  => esc_html__( 'Status', 'dsquared-hub-connector' ),
                'value' => ! empty( $row['spam'] ) ? esc_html__( 'Spam', 'dsquared-hub-connector' ) : esc_html__( 'Inbox', 'dsquared-hub-connector' ),
            );
            $item_data[] = array(
                'name'  => esc_html__( 'May have been forwarded to Hub', 'dsquared-hub-connector' ),
                'value' => ! array_key_exists( 'forwarded_to_hub', $row ) || ! empty( $row['forwarded_to_hub'] )
                    ? esc_html__( 'Yes', 'dsquared-hub-connector' )
                    : esc_html__( 'No', 'dsquared-hub-connector' ),
            );
            $data[] = array(
                'group_id'    => 'dsquared-hub-form-inbox',
                'group_label' => esc_html__( 'Dsquared Hub form inbox', 'dsquared-hub-connector' ),
                'item_id'     => 'dhc-form-' . sanitize_key( $row['id'] ?? md5( wp_json_encode( $row ) ) ),
                'data'        => $item_data,
            );
        }

        return array(
            'data' => $data,
            'done' => true,
        );
    }

    /**
     * Register personal data eraser
     *
     * @param array $erasers
     * @return array
     */
    public static function register_eraser( $erasers ) {
        $erasers['dsquared-hub-connector'] = array(
            'eraser_friendly_name' => esc_html__( 'Dsquared Hub Connector', 'dsquared-hub-connector' ),
            'callback'             => array( __CLASS__, 'erase_personal_data' ),
        );
        return $erasers;
    }

    /**
     * Erase matching local form-inbox data.
     *
     * @param string $email_address
     * @param int    $page
     * @return array
     */
    public static function erase_personal_data( $email_address, $page = 1 ) {
        if ( ! class_exists( 'DHC_Form_Capture' ) || 1 !== (int) $page ) {
            return array( 'items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true );
        }

        $result   = DHC_Form_Capture::erase_submissions_for_email( $email_address );
        $messages = array();
        if ( $result['removed'] ) {
            $messages[] = sprintf(
                esc_html__( 'Removed %d matching local form inbox record(s).', 'dsquared-hub-connector' ),
                (int) $result['removed']
            );
        }
        if ( $result['forwarded'] ) {
            $messages[] = esc_html__( 'A clean lead may also exist in the connected Dsquared Media Hub account and must be erased there separately.', 'dsquared-hub-connector' );
        }

        return array(
            'items_removed'  => (bool) $result['removed'],
            'items_retained' => (bool) $result['forwarded'],
            'messages'       => $messages,
            'done'           => true,
        );
    }
}
