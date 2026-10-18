<?php
/**
 * Front end: adds the GhostStat tracker (cdn.ghoststat.me/gs.js) to every page once a site key is set.
 *
 * @package GhostStat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueues gs.js with the site's key.
 */
class GhostStat_Tracker {

	const HANDLE = 'ghoststat-tracker';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_filter( 'script_loader_tag', array( __CLASS__, 'tag' ), 10, 2 );
	}

	/**
	 * Tracker URL. Filterable for staging setups.
	 *
	 * @return string
	 */
	public static function src() {
		return (string) apply_filters( 'ghoststat_tracker_src', 'https://cdn.ghoststat.me/gs.js' );
	}

	/**
	 * Should this request be counted?
	 *
	 * Logged-in users whose role is in "Don't count" are skipped, as are previews and the customizer.
	 *
	 * @return bool
	 */
	public static function should_track() {
		$site_key = GhostStat_Options::get( 'site_key' );
		if ( ! GhostStat_Options::is_site_key( $site_key ) ) {
			return false;
		}
		if ( is_preview() || is_customize_preview() ) {
			return false;
		}
		if ( is_user_logged_in() ) {
			$skip = (array) GhostStat_Options::get( 'exclude_roles' );
			$user = wp_get_current_user();
			if ( array_intersect( (array) $user->roles, $skip ) ) {
				return false;
			}
		}
		return (bool) apply_filters( 'ghoststat_should_track', true );
	}

	/**
	 * Enqueue the tracker in the head, deferred.
	 */
	public static function enqueue() {
		if ( ! self::should_track() ) {
			return;
		}
		// Version null: the tracker is served by GhostStat and always current; no ?ver= on its URL.
		wp_enqueue_script( self::HANDLE, self::src(), array(), null, array( 'strategy' => 'defer', 'in_footer' => false ) ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
	}

	/**
	 * Add the site key to our script tag: gs.js reads data-site and data-api from its own tag.
	 *
	 * @param string $tag    The script tag.
	 * @param string $handle Script handle.
	 * @return string
	 */
	public static function tag( $tag, $handle ) {
		if ( self::HANDLE !== $handle ) {
			return $tag;
		}
		// data-via: GhostStat sees which sites use this plugin (gs.js passes it on with its config request).
		$attrs = sprintf(
			' data-site="%s" data-api="%s" data-via="%s"',
			esc_attr( GhostStat_Options::get( 'site_key' ) ),
			esc_attr( GhostStat_Api::base() ),
			esc_attr( 'wordpress/' . GHOSTSTAT_VERSION )
		);
		return preg_replace( '/<script\b/', '<script' . $attrs, $tag, 1 );
	}
}
