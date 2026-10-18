<?php
/**
 * Reads statistics from the GhostStat API with the site's read-only API key.
 *
 * Server to server (wp_remote_get), so the key never reaches a browser. Answers are cached for five
 * minutes per report and date range.
 *
 * @package GhostStat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin client for https://api.ghoststat.me/stats/*.
 */
class GhostStat_Api {

	const CACHE_TTL   = 300;
	const CACHE_GROUP = 'ghoststat_';

	/**
	 * Reports the plugin reads. Anything else is refused before a request is made.
	 *
	 * @var string[]
	 */
	private static $reports = array( 'overview', 'timeline', 'pages', 'referrers', 'countries', 'devices', 'browsers', 'realtime', 'intel' );

	/**
	 * API base URL. Filterable for staging setups.
	 *
	 * @return string
	 */
	public static function base() {
		return untrailingslashit( (string) apply_filters( 'ghoststat_api_base', 'https://api.ghoststat.me' ) );
	}

	/**
	 * Is an API key saved (format only)?
	 *
	 * @return bool
	 */
	public static function has_key() {
		return GhostStat_Options::is_api_key( GhostStat_Options::get( 'api_key' ) )
			&& GhostStat_Options::is_site_key( GhostStat_Options::get( 'site_key' ) );
	}

	/**
	 * One report for a date range.
	 *
	 * @param string $report Report name, see $reports.
	 * @param string $from   Y-m-d.
	 * @param string $to     Y-m-d.
	 * @param bool   $fresh  Skip the cache.
	 * @return array|WP_Error Decoded JSON, or an error with a message for the screen.
	 */
	public static function get( $report, $from = '', $to = '', $fresh = false ) {
		if ( ! in_array( $report, self::$reports, true ) ) {
			return new WP_Error( 'ghoststat_report', __( 'Unknown report.', 'ghoststat-analytics' ) );
		}
		if ( ! self::has_key() ) {
			return new WP_Error( 'ghoststat_no_key', __( 'Add your site key and an API key in Settings → GhostStat to see statistics here.', 'ghoststat-analytics' ) );
		}
		$site  = GhostStat_Options::get( 'site_key' );
		$args  = array( 'site' => $site );
		if ( self::is_date( $from ) && self::is_date( $to ) ) {
			$args['from'] = $from;
			$args['to']   = $to;
		}
		$cache = self::CACHE_GROUP . md5( $report . '|' . wp_json_encode( $args ) . '|' . GhostStat_Options::get( 'api_key' ) . '|' . (int) get_option( 'ghoststat_cache_gen', 0 ) );
		if ( ! $fresh && 'realtime' !== $report ) {
			$hit = get_transient( $cache );
			if ( is_array( $hit ) ) {
				return $hit;
			}
		}

		$url  = add_query_arg( $args, self::base() . '/stats/' . $report );
		$resp = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . GhostStat_Options::get( 'api_key' ),
					'Accept'        => 'application/json',
				),
			)
		);
		if ( is_wp_error( $resp ) ) {
			return new WP_Error( 'ghoststat_http', __( 'GhostStat could not be reached. Try again in a minute.', 'ghoststat-analytics' ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		if ( 401 === $code ) {
			return new WP_Error( 'ghoststat_key', __( 'GhostStat did not accept the API key. It may have been revoked, or it belongs to another site. Make a new one in the GhostStat app under Site settings → API keys.', 'ghoststat-analytics' ) );
		}
		if ( 404 === $code && is_array( $body ) && 'unknown_stat' === ( $body['error'] ?? '' ) ) {
			return new WP_Error( 'ghoststat_unknown_stat', __( 'This report is not available from GhostStat yet.', 'ghoststat-analytics' ) );
		}
		if ( 404 === $code ) {
			return new WP_Error( 'ghoststat_site', __( 'GhostStat does not know this site key. Check it in the GhostStat app under Install.', 'ghoststat-analytics' ) );
		}
		if ( 429 === $code ) {
			return new WP_Error( 'ghoststat_rate', __( 'Too many requests to GhostStat in the last hour. The numbers will be back shortly.', 'ghoststat-analytics' ) );
		}
		if ( 200 !== $code || ! is_array( $body ) ) {
			return new WP_Error( 'ghoststat_bad', __( 'GhostStat sent an unexpected answer. Try again in a minute.', 'ghoststat-analytics' ) );
		}
		if ( 'realtime' !== $report ) {
			set_transient( $cache, $body, self::CACHE_TTL );
		}
		return $body;
	}

	/**
	 * Does GhostStat know this site key? Uses the public tracker config endpoint (no API key needed).
	 *
	 * @param string $site_key Site key.
	 * @return bool|WP_Error
	 */
	public static function site_exists( $site_key ) {
		$resp = wp_remote_get( add_query_arg( 'site', rawurlencode( $site_key ), self::base() . '/collect/config' ), array( 'timeout' => 10 ) );
		if ( is_wp_error( $resp ) ) {
			return new WP_Error( 'ghoststat_http', __( 'GhostStat could not be reached. Try again in a minute.', 'ghoststat-analytics' ) );
		}
		return 200 === (int) wp_remote_retrieve_response_code( $resp );
	}

	/**
	 * Forget cached answers (after the key changes, or "Refresh"). Bumps a generation number that is
	 * part of every cache key, so it works the same with or without a persistent object cache.
	 */
	public static function flush() {
		update_option( 'ghoststat_cache_gen', (int) get_option( 'ghoststat_cache_gen', 0 ) + 1, false );
	}

	/**
	 * Y-m-d check.
	 *
	 * @param string $d Date.
	 * @return bool
	 */
	public static function is_date( $d ) {
		return is_string( $d ) && (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d );
	}
}
