<?php
/**
 * Plugin Name:       GhostStat Analytics
 * Description:       Cookieless visitor intelligence. Adds the GhostStat tracker to your site and shows your visitors, their intent, frustration and attention, pages, sources and devices inside WordPress. Optionally reports installed plugins, themes and updates to GhostStat.
 * Version:           1.2.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            GhostStat
 * Author URI:        https://ghoststat.me/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ghoststat-analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GHOSTSTAT_VERSION', '1.2.0' );
define( 'GHOSTSTAT_INSIGHTS_KEY', 'gsp_K2N8soIfRBszFGgy5iAQmuvE' ); // public, write-only: lets this plugin report its own use (version, WordPress/PHP version) once a site key is saved.
define( 'GHOSTSTAT_FILE', __FILE__ );
define( 'GHOSTSTAT_DIR', plugin_dir_path( __FILE__ ) );
define( 'GHOSTSTAT_URL', plugin_dir_url( __FILE__ ) );

require_once GHOSTSTAT_DIR . 'includes/class-ghoststat-options.php';
require_once GHOSTSTAT_DIR . 'includes/class-ghoststat-tracker.php';
require_once GHOSTSTAT_DIR . 'includes/class-ghoststat-api.php';
require_once GHOSTSTAT_DIR . 'includes/class-ghoststat-charts.php';
require_once GHOSTSTAT_DIR . 'includes/class-ghoststat-intel.php';
require_once GHOSTSTAT_DIR . 'includes/class-ghoststat-software.php';
require_once GHOSTSTAT_DIR . 'includes/class-ghoststat-admin.php';
require_once GHOSTSTAT_DIR . 'includes/ghoststat-insights.php';

/*
 * Usage statistics for the plugin's developer, part of the GhostStat service: once a site key is saved (the site is
 * connected to GhostStat), this plugin tells GhostStat once a day that it is in use, with its version, the WordPress and
 * PHP version and the server's country (looked up by GhostStat, the IP address is not stored), and when it is activated,
 * deactivated, updated or deleted. No site address, no visitor or user data. Without a site key nothing is sent.
 * See readme.txt → External services.
 */
$GLOBALS['ghoststat_insights'] = GhostStat_Insights_1::wordpress(
	__FILE__,
	array(
		'key'     => GHOSTSTAT_INSIGHTS_KEY,
		'name'    => 'GhostStat Analytics',
		'version' => GHOSTSTAT_VERSION,
		'consent' => function () {
			return GhostStat_Options::is_site_key( (string) GhostStat_Options::get( 'site_key' ) );
		},
	)
);

GhostStat_Tracker::init();
GhostStat_Software::init();
register_deactivation_hook( __FILE__, array( 'GhostStat_Software', 'unschedule' ) );
if ( is_admin() ) {
	GhostStat_Admin::init();
}

/**
 * Settings link on the Plugins screen.
 *
 * @param string[] $links Existing action links.
 * @return string[]
 */
function ghoststat_action_links( $links ) {
	$url = admin_url( 'options-general.php?page=ghoststat-settings' );
	array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'ghoststat-analytics' ) . '</a>' );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'ghoststat_action_links' );
