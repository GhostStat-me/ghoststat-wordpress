<?php
/**
 * Uninstall: remove the plugin's settings, cached reports, queued software reports and scheduled events. Statistics stay in your GhostStat account.
 *
 * @package GhostStat
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Usage statistics: if this site was reporting (site key saved), tell GhostStat the plugin was deleted, then forget the install ID.
require_once __DIR__ . '/includes/ghoststat-insights.php';
$ghoststat_ins_opt = 'ghoststat_insights_' . substr( md5( 'gsp_K2N8soIfRBszFGgy5iAQmuvE' ), 0, 12 );
GhostStat_Insights_1::start(
	array(
		'key'      => 'gsp_K2N8soIfRBszFGgy5iAQmuvE',
		'name'     => 'GhostStat Analytics',
		'platform' => 'wordpress',
		'get'      => function ( $k ) use ( $ghoststat_ins_opt ) {
			$a = get_option( $ghoststat_ins_opt, array() );
			return is_array( $a ) && array_key_exists( $k, $a ) ? $a[ $k ] : null;
		},
		'set'      => function () {},
		// only if this site was reporting (it had a site key, so an install ID was made)
		'consent'  => function () use ( $ghoststat_ins_opt ) {
			$a = get_option( $ghoststat_ins_opt, array() );
			return is_array( $a ) && ! empty( $a['install'] );
		},
	)
)->uninstalled();
delete_option( $ghoststat_ins_opt );

delete_option( 'ghoststat_settings' );
delete_option( 'ghoststat_cache_gen' );
foreach ( array( 'ghoststat_sw_queue', 'ghoststat_sw_errors', 'ghoststat_sw_snapshot', 'ghoststat_sw_status' ) as $ghoststat_option ) {
	delete_option( $ghoststat_option );
}
wp_clear_scheduled_hook( 'ghoststat_sw_daily' );
wp_clear_scheduled_hook( 'ghoststat_sw_send' );

// Cached reports (transients named ghoststat_<md5>). Without this, a reinstall within five minutes could show old numbers.
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time cleanup on uninstall; transients have no delete-by-prefix API.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_ghoststat_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_ghoststat_' ) . '%'
	)
);
