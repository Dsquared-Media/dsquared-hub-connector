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
        $policy .= '<li>' . esc_html__( 'Form submission data (name, email, phone) — only when the Form Submission Capture module is enabled. This data is processed in real-time for spam filtering and is NOT stored locally. Clean lead data is transmitted to the connected Dsquared Media Hub account.', 'dsquared-hub-connector' ) . '</li>';
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
        $policy .= '<p>' . esc_html__( 'Core Web Vitals data is retained locally for 90 days. Activity logs are limited to the 200 most recent entries. Form submission data is not stored locally — it is processed and forwarded in real-time. Monthly lead/spam counters (aggregate numbers only, no personal data) are retained for 12 months.', 'dsquared-hub-connector' ) . '</p>';

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
     * Export personal data (we don't store PII, so this returns empty)
     *
     * @param string $email_address
     * @param int    $page
     * @return array
     */
    public static function export_personal_data( $email_address, $page = 1 ) {
        return array(
            'data' => array(),
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
     * Erase personal data (we don't store PII, so nothing to erase)
     *
     * @param string $email_address
     * @param int    $page
     * @return array
     */
    public static function erase_personal_data( $email_address, $page = 1 ) {
        return array(
            'items_removed'  => false,
            'items_retained' => false,
            'messages'       => array(
                esc_html__( 'Dsquared Hub Connector does not store personal data locally.', 'dsquared-hub-connector' ),
            ),
            'done'           => true,
        );
    }
}
