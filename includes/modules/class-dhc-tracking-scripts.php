<?php
/**
 * Privileged header/body/footer script manager with page and device rules.
 *
 * @package Dsquared_Hub_Connector
 * @since   1.23.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DHC_Tracking_Scripts {
	const OPTION = 'dhc_tracking_scripts';
	const LIMIT  = 100;

	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'output_head' ), 99 );
		add_action( 'wp_body_open', array( __CLASS__, 'output_body' ), 99 );
		add_action( 'wp_footer', array( __CLASS__, 'output_footer' ), 99 );
		add_action( 'wp_ajax_dhc_save_tracking_script', array( __CLASS__, 'ajax_save' ) );
		add_action( 'wp_ajax_dhc_delete_tracking_script', array( __CLASS__, 'ajax_delete' ) );
	}

	public static function get_scripts() {
		$rows = get_option( self::OPTION, array() );
		return is_array( $rows ) ? array_values( $rows ) : array();
	}

	private static function clean_code( $code ) {
		$code = (string) $code;
		// PHP is never accepted by this browser-side manager.
		$code = preg_replace( '/<\?(?:php|=)?|\?>/i', '', $code );
		return trim( $code );
	}

	private static function clean_rules( $value ) {
		$lines = preg_split( '/[\r\n,]+/', (string) $value );
		$out = array();
		foreach ( $lines as $line ) {
			$line = trim( sanitize_text_field( $line ) );
			if ( '' !== $line ) $out[] = $line;
		}
		return array_slice( array_values( array_unique( $out ) ), 0, 100 );
	}

	public static function ajax_save() {
		check_ajax_referer( 'dhc_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'unfiltered_html' ) || ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'You do not have permission to manage scripts.', 403 );
		}
		$rows = self::get_scripts();
		$id = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
		if ( '' === $id ) $id = wp_generate_uuid4();
		$row = array(
			'id'       => $id,
			'name'     => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
			'location' => in_array( $_POST['location'] ?? '', array( 'head', 'body', 'footer' ), true ) ? $_POST['location'] : 'head',
			'device'   => in_array( $_POST['device'] ?? '', array( 'all', 'desktop', 'mobile' ), true ) ? $_POST['device'] : 'all',
			'include'  => self::clean_rules( wp_unslash( $_POST['include'] ?? '' ) ),
			'exclude'  => self::clean_rules( wp_unslash( $_POST['exclude'] ?? '' ) ),
			'page_scope' => in_array( $_POST['page_scope'] ?? '', array( 'everywhere', 'home', 'posts', 'specific' ), true ) ? $_POST['page_scope'] : 'everywhere',
			'exclude_types' => array_values( array_intersect( array( 'page', 'post', 'product' ), array_map( 'sanitize_key', (array) ( $_POST['exclude_types'] ?? array() ) ) ) ),
			'ecommerce' => ! empty( $_POST['ecommerce'] ),
			'code'     => self::clean_code( wp_unslash( $_POST['code'] ?? '' ) ),
			'active'   => ! empty( $_POST['active'] ),
			'updated'  => current_time( 'mysql' ),
		);
		if ( '' === $row['name'] || '' === $row['code'] ) wp_send_json_error( 'Name and code are required.', 400 );
		$found = false;
		foreach ( $rows as $key => $existing ) {
			if ( isset( $existing['id'] ) && $existing['id'] === $id ) { $rows[ $key ] = $row; $found = true; break; }
		}
		if ( ! $found ) $rows[] = $row;
		$rows = array_slice( $rows, -self::LIMIT );
		update_option( self::OPTION, $rows, false );
		DHC_Event_Logger::log( 'tracking_script_saved', 'tracking_scripts', array( 'id' => $id, 'location' => $row['location'] ), 'Tracking script saved: ' . $row['name'] );
		wp_send_json_success( array( 'message' => 'Script saved.', 'script' => $row ) );
	}

	public static function ajax_delete() {
		check_ajax_referer( 'dhc_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'unfiltered_html' ) || ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden.', 403 );
		$id = sanitize_key( wp_unslash( $_POST['id'] ?? '' ) );
		$rows = array_values( array_filter( self::get_scripts(), function( $row ) use ( $id ) { return empty( $row['id'] ) || $row['id'] !== $id; } ) );
		update_option( self::OPTION, $rows, false );
		DHC_Event_Logger::log( 'tracking_script_deleted', 'tracking_scripts', array( 'id' => $id ), 'Tracking script deleted.' );
		wp_send_json_success( array( 'message' => 'Script deleted.' ) );
	}

	private static function rule_matches( $rule, $path ) {
		$rule = trim( (string) $rule );
		if ( '' === $rule || '*' === $rule ) return true;
		$pattern = preg_quote( $rule, '#' );
		$pattern = str_replace( '\\*', '.*', $pattern );
		return (bool) preg_match( '#^' . $pattern . '$#i', $path );
	}

	private static function should_output( $row ) {
		if ( empty( $row['active'] ) ) return false;
		$is_mobile = wp_is_mobile();
		if ( 'mobile' === ( $row['device'] ?? 'all' ) && ! $is_mobile ) return false;
		if ( 'desktop' === ( $row['device'] ?? 'all' ) && $is_mobile ) return false;
		$scope = $row['page_scope'] ?? 'everywhere';
		if ( 'home' === $scope && ! is_front_page() ) return false;
		if ( 'posts' === $scope && ! is_singular( 'post' ) ) return false;
		if ( is_singular() && in_array( get_post_type(), (array) ( $row['exclude_types'] ?? array() ), true ) ) return false;
		$path = wp_parse_url( home_url( add_query_arg( array(), $GLOBALS['wp']->request ?? '' ) ), PHP_URL_PATH );
		$path = '/' . ltrim( (string) $path, '/' );
		$include = isset( $row['include'] ) && is_array( $row['include'] ) ? $row['include'] : array();
		$exclude = isset( $row['exclude'] ) && is_array( $row['exclude'] ) ? $row['exclude'] : array();
		foreach ( $exclude as $rule ) if ( self::rule_matches( $rule, $path ) ) return false;
		if ( 'specific' === $scope && empty( $include ) ) return false;
		if ( empty( $include ) ) return true;
		foreach ( $include as $rule ) if ( self::rule_matches( $rule, $path ) ) return true;
		return false;
	}

	private static function output( $location ) {
		foreach ( self::get_scripts() as $row ) {
			if ( $location !== ( $row['location'] ?? 'head' ) || ! self::should_output( $row ) ) continue;
			echo "\n<!-- D2 Hub: " . esc_html( $row['name'] ?? 'Tracking script' ) . " -->\n";
			echo (string) ( $row['code'] ?? '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- privileged, validated raw tracking code.
			echo "\n<!-- /D2 Hub -->\n";
		}
	}

	public static function output_head() { self::output( 'head' ); }
	public static function output_body() { self::output( 'body' ); }
	public static function output_footer() { self::output( 'footer' ); }
}
