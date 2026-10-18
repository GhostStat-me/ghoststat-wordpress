<?php
/**
 * Stored settings: one option, `ghoststat_settings`.
 *
 * @package GhostStat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read and sanitize the plugin settings.
 */
class GhostStat_Options {

	const OPTION = 'ghoststat_settings';

	/**
	 * Defaults. Administrators are not counted unless the site owner turns that off.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'site_key'      => '',
			'api_key'       => '',
			'exclude_roles' => array( 'administrator' ),
			'report_key'    => '',
			'report_errors' => 0,
		);
	}

	/**
	 * All settings merged over the defaults.
	 *
	 * @return array
	 */
	public static function all() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return array_merge( self::defaults(), $saved );
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting name.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * A site key is 24 letters and digits (it is printed in the GhostStat snippet as data-site).
	 *
	 * @param string $key Raw value.
	 * @return bool
	 */
	public static function is_site_key( $key ) {
		return (bool) preg_match( '/^[A-Za-z0-9]{24}$/', (string) $key );
	}

	/**
	 * An API key from Site settings → API keys in the GhostStat app.
	 *
	 * @param string $key Raw value.
	 * @return bool
	 */
	public static function is_api_key( $key ) {
		return (bool) preg_match( '/^gsr_[A-Za-z0-9]{40}$/', (string) $key );
	}

	/**
	 * A report key from Software Development → Connect in the GhostStat app.
	 *
	 * @param string $key Raw value.
	 * @return bool
	 */
	public static function is_report_key( $key ) {
		return (bool) preg_match( '/^gssw_[A-Za-z0-9_-]{30,64}$/', (string) $key );
	}

	/**
	 * Sanitize callback for register_setting().
	 *
	 * Accepts a pasted snippet as well as a bare key: people copy the whole script tag.
	 *
	 * @param mixed $input Submitted values.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$old = self::all();
		$out = self::defaults();
		if ( ! is_array( $input ) ) {
			return $old;
		}

		$site = isset( $input['site_key'] ) ? trim( wp_unslash( (string) $input['site_key'] ) ) : '';
		if ( preg_match( '/data-site=["\']?([A-Za-z0-9]{24})/', $site, $m ) ) {
			$site = $m[1];
		}
		$site = sanitize_text_field( $site );
		if ( '' !== $site && ! self::is_site_key( $site ) ) {
			add_settings_error( self::OPTION, 'ghoststat_site_key', __( 'That does not look like a GhostStat site key. It is 24 letters and digits, shown in the GhostStat app under Install.', 'ghoststat-analytics' ) );
			$site = $old['site_key'];
		}
		$out['site_key'] = $site;

		$api = isset( $input['api_key'] ) ? sanitize_text_field( trim( wp_unslash( (string) $input['api_key'] ) ) ) : '';
		if ( '' === $api && ! empty( $input['api_key_keep'] ) ) {
			$api = $old['api_key']; // The field is shown masked; empty means "keep the saved key".
		}
		if ( ! empty( $input['api_key_remove'] ) ) {
			$api = '';
		}
		if ( '' !== $api && ! self::is_api_key( $api ) ) {
			add_settings_error( self::OPTION, 'ghoststat_api_key', __( 'That does not look like a GhostStat API key. It starts with gsr_ and is made in the GhostStat app under Site settings → API keys.', 'ghoststat-analytics' ) );
			$api = $old['api_key'];
		}
		if ( $api !== $old['api_key'] ) {
			GhostStat_Api::flush();
		}
		$out['api_key'] = $api;

		$rep = isset( $input['report_key'] ) ? sanitize_text_field( trim( wp_unslash( (string) $input['report_key'] ) ) ) : '';
		if ( '' === $rep && ! empty( $input['report_key_keep'] ) ) {
			$rep = $old['report_key']; // Shown masked; empty means "keep the saved key".
		}
		if ( ! empty( $input['report_key_remove'] ) ) {
			$rep = '';
		}
		if ( '' !== $rep && ! self::is_report_key( $rep ) ) {
			add_settings_error( self::OPTION, 'ghoststat_report_key', __( 'That does not look like a GhostStat report key. It starts with gssw_ and is made in the GhostStat app under Software Development → Connect.', 'ghoststat-analytics' ) );
			$rep = $old['report_key'];
		}
		$out['report_key']    = $rep;
		$out['report_errors'] = ( '' !== $rep && ! empty( $input['report_errors'] ) ) ? 1 : 0;

		$roles   = isset( $input['exclude_roles'] ) && is_array( $input['exclude_roles'] ) ? array_map( 'sanitize_key', wp_unslash( $input['exclude_roles'] ) ) : array();
		$known   = array_keys( wp_roles()->get_names() );
		$out['exclude_roles'] = array_values( array_intersect( $roles, $known ) );

		return $out;
	}
}
