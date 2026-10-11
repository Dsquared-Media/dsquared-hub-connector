<?php
/**
 * DHC_Event_Logger — Centralized event logging to the Hub
 *
 * Provides a single static method that any module can call to report
 * events to the Hub's /api/plugin/event endpoint. Also logs locally
 * to the dhc_activity_log option for the WP admin Activity Log tab.
 *
 * @package Dsquared_Hub_Connector
 * @since   1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DHC_Event_Logger {

    /** Register the small, bounded set of WordPress-wide audit hooks. */
    public static function init() {
        add_action( 'wp_login', array( __CLASS__, 'record_login' ), 10, 2 );
        add_action( 'save_post', array( __CLASS__, 'record_post_save' ), 100, 3 );
		add_action( 'transition_post_status', array( __CLASS__, 'record_status_change' ), 100, 3 );
		add_action( 'deleted_post', array( __CLASS__, 'record_post_delete' ), 10, 2 );
		add_action( 'add_attachment', array( __CLASS__, 'record_attachment_add' ) );
		add_action( 'delete_attachment', array( __CLASS__, 'record_attachment_delete' ) );
        add_action( 'profile_update', array( __CLASS__, 'record_profile_update' ), 10, 2 );
		add_action( 'user_register', array( __CLASS__, 'record_user_create' ) );
		add_action( 'delete_user', array( __CLASS__, 'record_user_delete' ), 10, 3 );
        add_action( 'activated_plugin', array( __CLASS__, 'record_plugin_activation' ) );
        add_action( 'deactivated_plugin', array( __CLASS__, 'record_plugin_deactivation' ) );
		add_action( 'switch_theme', array( __CLASS__, 'record_theme_switch' ), 10, 3 );
        add_action( 'upgrader_process_complete', array( __CLASS__, 'record_upgrade' ), 100, 2 );
    }

    /**
     * Log an event to the Hub and locally
     *
     * @param string $event  Event type identifier (e.g., 'schema_injected', 'content_decay_scan').
     * @param string $module Module slug (e.g., 'ai_discovery', 'content_decay').
     * @param array  $data   Additional event data.
     * @param string $local_message Human-readable message for local activity log.
     */
    public static function log( $event, $module = 'general', $data = array(), $local_message = '' ) {
        // Backwards compatibility with earlier two-argument calls where the
        // second argument was event data rather than a module name.
        if ( is_array( $module ) ) { $data = $module; $module = 'general'; }
        if ( ! self::collection_allowed() ) {
            return;
        }

        // 1. Send to Hub
        self::send_to_hub( $event, $module, $data );

        // 2. Log locally
        if ( ! empty( $local_message ) ) {
			self::log_local( $event, $module, $local_message, self::is_hub_request() ? 'hub' : 'wordpress', array() );
        }
    }

    /** Store one normalized, prepend-only activity row. */
    public static function activity( $event, $module, $message, $origin = 'wordpress', $actor = array() ) {
        if ( ! self::collection_allowed() ) {
            return;
        }
        self::log_local( $event, $module, $message, $origin, is_array( $actor ) ? $actor : array() );
    }

    /** Return activity rows whose stored email exactly matches the request. */
    public static function get_activity_for_email( $email_address ) {
        $email_address = strtolower( sanitize_email( $email_address ) );
        if ( '' === $email_address ) {
            return array();
        }

        $matches = array();
        $rows    = get_option( 'dhc_activity_log', array() );
        if ( ! is_array( $rows ) ) {
            return $matches;
        }

        foreach ( $rows as $index => $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $stored = strtolower( sanitize_email( $row['email'] ?? '' ) );
            if ( '' !== $stored && $stored === $email_address ) {
                $row['_privacy_index'] = $index;
                $matches[] = $row;
            }
        }
        return $matches;
    }

    /** Remove every activity row whose stored email exactly matches the request. */
    public static function erase_activity_for_email( $email_address ) {
        $email_address = strtolower( sanitize_email( $email_address ) );
        if ( '' === $email_address ) {
            return 0;
        }

        $rows = get_option( 'dhc_activity_log', array() );
        if ( ! is_array( $rows ) ) {
            return 0;
        }

        $kept    = array();
        $removed = 0;
        foreach ( $rows as $row ) {
            $stored = is_array( $row ) ? strtolower( sanitize_email( $row['email'] ?? '' ) ) : '';
            if ( '' !== $stored && $stored === $email_address ) {
                $removed++;
                continue;
            }
            $kept[] = $row;
        }

        if ( $removed ) {
            update_option( 'dhc_activity_log', $kept, false );
        }
        return $removed;
    }

    /** Fail closed unless the connector currently has an active subscription. */
    private static function collection_allowed() {
        if ( ! class_exists( 'DHC_API_Key' ) ) {
            return false;
        }
        $subscription = DHC_API_Key::validate();
        return is_array( $subscription ) && ! empty( $subscription['valid'] );
    }

    /**
     * Send event to Hub's /api/plugin/event endpoint
     *
     * @param string $event  Event type.
     * @param string $module Module slug.
     * @param array  $data   Event data.
     */
    private static function send_to_hub( $event, $module, $data ) {
        $api_key = get_option( 'dhc_api_key', '' );
        if ( empty( $api_key ) ) {
            return;
        }

        $hub_url = DHC_Heartbeat::get_hub_url();

        wp_remote_post( $hub_url . '/api/plugin/event', array(
            'body'    => wp_json_encode( array(
                'event'  => $event,
                'site'   => home_url( '/' ),
                'module' => $module,
                'data'   => $data,
            ) ),
            'headers' => array(
                'Content-Type'  => 'application/json',
                'X-DHC-API-Key' => $api_key,
            ),
            'timeout'  => 15,
            'blocking' => false,
        ) );
    }

    /**
     * Log event to local WordPress activity log
     *
     * @param string $event   Event type.
     * @param string $module  Module slug.
     * @param string $message Human-readable message.
     */
    private static function log_local( $event, $module, $message, $origin = 'wordpress', $actor = array() ) {
        $log = get_option( 'dhc_activity_log', array() );
		if ( ! is_array( $log ) ) $log = array();

        $user = wp_get_current_user();
		$is_hub = 'hub' === $origin;
		$default_user = $is_hub ? 'Dsquared Hub' : ( $user && $user->exists() ? $user->display_name : 'System' );
		$default_email = $is_hub ? 'hub@dsquaredmedia.net' : ( $user && $user->exists() ? $user->user_email : '' );
        array_unshift( $log, array(
            'action'  => $event,
            'module'  => $module,
            'message' => $message,
            'time'    => current_time( 'mysql' ),
			'origin'  => $is_hub ? 'Hub' : 'WordPress',
			'user'    => sanitize_text_field( array_key_exists( 'user', $actor ) ? $actor['user'] : $default_user ),
			'email'   => sanitize_email( array_key_exists( 'email', $actor ) ? $actor['email'] : $default_email ),
			'ip'      => sanitize_text_field( array_key_exists( 'ip', $actor ) ? $actor['ip'] : self::request_ip() ),
        ) );

        // Keep last 200 entries
        $log = array_slice( $log, 0, 250 );
        update_option( 'dhc_activity_log', $log );
    }

    private static function request_ip() {
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
        return filter_var( $ip, FILTER_VALIDATE_IP ) ? sanitize_text_field( $ip ) : '';
    }

	private static function is_hub_request() {
		if ( ! empty( $_SERVER['HTTP_X_DHC_API_KEY'] ) ) return true;
		$uri=isset($_SERVER['REQUEST_URI'])?wp_unslash($_SERVER['REQUEST_URI']):'';
		return defined('REST_REQUEST')&&REST_REQUEST&&false!==strpos($uri,'/wp-json/dsquared-hub/');
	}

    public static function record_login( $login, $user ) {
        self::activity( 'user_login', 'wordpress', 'Logged in.', 'wordpress' );
    }

    public static function record_post_save( $post_id, $post, $update ) {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! is_object( $post ) || 'auto-draft' === $post->post_status ) return;
        $type = get_post_type_object( $post->post_type );
        $label = $type ? $type->labels->singular_name : 'content';
        self::activity( $update ? 'content_updated' : 'content_created', 'wordpress', ( $update ? 'Updated ' : 'Created ' ) . strtolower( $label ) . ' “' . sanitize_text_field( $post->post_title ?: '(untitled)' ) . '”.', 'wordpress' );
    }

	public static function record_status_change($new_status,$old_status,$post){if($new_status===$old_status||!is_object($post)||'revision'===$post->post_type||'auto-draft'===$new_status)return;$type=get_post_type_object($post->post_type);$label=$type?$type->labels->singular_name:'content';self::activity('content_status_changed','wordpress','Changed '.strtolower($label).' “'.sanitize_text_field($post->post_title?:'(untitled)').'” from '.sanitize_key($old_status).' to '.sanitize_key($new_status).'.','wordpress');}
	public static function record_post_delete($post_id,$post){if(!is_object($post)||'revision'===$post->post_type)return;$type=get_post_type_object($post->post_type);$label=$type?$type->labels->singular_name:'content';self::activity('content_deleted','wordpress','Deleted '.strtolower($label).' “'.sanitize_text_field($post->post_title?:'(untitled)').'”.','wordpress');}
	public static function record_attachment_add($post_id){$post=get_post($post_id);self::activity('media_added','wordpress','Added media “'.sanitize_text_field($post?$post->post_title:(string)$post_id).'”.','wordpress');}
	public static function record_attachment_delete($post_id){$post=get_post($post_id);self::activity('media_deleted','wordpress','Deleted media “'.sanitize_text_field($post?$post->post_title:(string)$post_id).'”.','wordpress');}

    public static function record_profile_update( $user_id, $old_user_data ) {
        $changed = get_userdata( $user_id );
        self::activity( 'profile_updated', 'wordpress', 'Updated user profile “' . sanitize_text_field( $changed ? $changed->display_name : (string) $user_id ) . '”.', 'wordpress' );
    }
	public static function record_user_create($user_id){$changed=get_userdata($user_id);self::activity('user_created','wordpress','Created user “'.sanitize_text_field($changed?$changed->display_name:(string)$user_id).'”.','wordpress');}
	public static function record_user_delete($user_id,$reassign,$user){self::activity('user_deleted','wordpress','Deleted user “'.sanitize_text_field(is_object($user)?$user->display_name:(string)$user_id).'”.','wordpress');}

    public static function record_plugin_activation( $plugin ) { self::activity( 'plugin_activated', 'wordpress', 'Activated plugin “' . sanitize_text_field( $plugin ) . '”.', 'wordpress' ); }
    public static function record_plugin_deactivation( $plugin ) { self::activity( 'plugin_deactivated', 'wordpress', 'Deactivated plugin “' . sanitize_text_field( $plugin ) . '”.', 'wordpress' ); }
	public static function record_theme_switch($new_name,$new_theme,$old_theme){self::activity('theme_switched','wordpress','Switched theme from “'.sanitize_text_field(is_object($old_theme)?$old_theme->get('Name'):'unknown').'” to “'.sanitize_text_field($new_name).'”.','wordpress');}
    public static function record_upgrade( $upgrader, $options ) {
        if ( empty( $options['type'] ) ) return;
        self::activity( 'software_updated', 'wordpress', 'Completed a ' . sanitize_text_field( $options['type'] ) . ' update.', 'wordpress' );
    }

    /**
     * Convenience: Log an AI Discovery event
     *
     * @param string $event   Event type (e.g., 'schema_injected', 'llms_updated', 'profile_synced').
     * @param array  $data    Event data.
     * @param string $message Local log message.
     */
    public static function ai_discovery( $event, $data = array(), $message = '' ) {
        self::log( $event, 'ai_discovery', $data, $message );
    }

    /**
     * Convenience: Log a Content Decay event
     *
     * @param string $event   Event type.
     * @param array  $data    Event data.
     * @param string $message Local log message.
     */
    public static function content_decay( $event, $data = array(), $message = '' ) {
        self::log( $event, 'content_decay', $data, $message );
    }

    /**
     * Convenience: Log a Blog Writer / Auto-Post event
     *
     * @param string $event   Event type.
     * @param array  $data    Event data.
     * @param string $message Local log message.
     */
    public static function auto_post( $event, $data = array(), $message = '' ) {
        self::log( $event, 'auto_post', $data, $message );
    }

    /**
     * Convenience: Log a Meta Sync event
     *
     * @param string $event   Event type.
     * @param array  $data    Event data.
     * @param string $message Local log message.
     */
    public static function seo_meta( $event, $data = array(), $message = '' ) {
        self::log( $event, 'seo_meta_sync', $data, $message );
    }

    /**
     * Convenience: Log a Schema event
     *
     * @param string $event   Event type.
     * @param array  $data    Event data.
     * @param string $message Local log message.
     */
    public static function schema( $event, $data = array(), $message = '' ) {
        self::log( $event, 'schema_injector', $data, $message );
    }

    /**
     * Convenience: Log a Form Capture event
     *
     * @param string $event   Event type.
     * @param array  $data    Event data.
     * @param string $message Local log message.
     */
    public static function form_capture( $event, $data = array(), $message = '' ) {
        self::log( $event, 'form_capture', $data, $message );
    }

    /**
     * Convenience: Log a Site Health event
     *
     * @param string $event   Event type.
     * @param array  $data    Event data.
     * @param string $message Local log message.
     */
    public static function site_health( $event, $data = array(), $message = '' ) {
        self::log( $event, 'site_health', $data, $message );
    }
}
