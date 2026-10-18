<?php
/**
 * wp-admin: Dashboard → GhostStat (statistics), Settings → GhostStat, dashboard widget, connection check.
 *
 * @package GhostStat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin screens.
 */
class GhostStat_Admin {

	const SLUG_STATS    = 'ghoststat';
	const SLUG_SETTINGS = 'ghoststat-settings';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'dashboard_widget' ) );
		add_action( 'admin_post_ghoststat_check', array( __CLASS__, 'handle_check' ) );
		add_action( 'admin_post_ghoststat_refresh', array( __CLASS__, 'handle_refresh' ) );
		add_action( 'admin_notices', array( __CLASS__, 'setup_notice' ) );
	}

	/**
	 * Capability to see the statistics. Settings always need manage_options.
	 *
	 * @return string
	 */
	public static function stats_cap() {
		return (string) apply_filters( 'ghoststat_stats_capability', 'manage_options' );
	}

	/**
	 * Menu: no top-level item. Statistics under Dashboard, settings under Settings.
	 */
	public static function menu() {
		add_dashboard_page( __( 'GhostStat', 'ghoststat-analytics' ), __( 'GhostStat', 'ghoststat-analytics' ), self::stats_cap(), self::SLUG_STATS, array( __CLASS__, 'page_stats' ) );
		add_options_page( __( 'GhostStat settings', 'ghoststat-analytics' ), __( 'GhostStat', 'ghoststat-analytics' ), 'manage_options', self::SLUG_SETTINGS, array( __CLASS__, 'page_settings' ) );
	}

	/**
	 * URL of the statistics page (Dashboard → GhostStat).
	 *
	 * @param array $args Extra query arguments.
	 * @return string
	 */
	public static function stats_url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG_STATS ), $args ), admin_url( 'index.php' ) );
	}

	/**
	 * URL of the settings page (Settings → GhostStat).
	 *
	 * @param array $args Extra query arguments.
	 * @return string
	 */
	public static function settings_url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG_SETTINGS ), $args ), admin_url( 'options-general.php' ) );
	}

	/**
	 * Settings API registration.
	 */
	public static function register_settings() {
		register_setting(
			'ghoststat',
			GhostStat_Options::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'GhostStat_Options', 'sanitize' ),
				'default'           => GhostStat_Options::defaults(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * CSS on our screens and the dashboard only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function assets( $hook ) {
		$ours = in_array( $hook, array( 'index.php', 'dashboard_page_' . self::SLUG_STATS, 'settings_page_' . self::SLUG_SETTINGS ), true );
		if ( ! $ours ) {
			return;
		}
		wp_enqueue_style( 'ghoststat-admin', GHOSTSTAT_URL . 'assets/admin.css', array(), GHOSTSTAT_VERSION );
	}

	/**
	 * Reminder on the Plugins screen until a site key is saved. Disappears once it is set.
	 */
	public static function setup_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'plugins' !== $screen->id || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( GhostStat_Options::is_site_key( GhostStat_Options::get( 'site_key' ) ) ) {
			return;
		}
		printf(
			'<div class="notice notice-info is-dismissible"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'GhostStat is active but not measuring yet: add your site key.', 'ghoststat-analytics' ),
			esc_url( self::settings_url() ),
			esc_html__( 'Open GhostStat settings', 'ghoststat-analytics' )
		);
	}

	/* --------------------------------------------------------------------- date ranges */

	/**
	 * Ranges offered on the statistics page.
	 *
	 * @return array days => label
	 */
	public static function ranges() {
		return array(
			7   => __( 'Last 7 days', 'ghoststat-analytics' ),
			30  => __( 'Last 30 days', 'ghoststat-analytics' ),
			90  => __( 'Last 90 days', 'ghoststat-analytics' ),
			365 => __( 'Last 12 months', 'ghoststat-analytics' ),
		);
	}

	/**
	 * From/to for N days ending today (site timezone; GhostStat itself uses the account's timezone).
	 *
	 * @param int $days Days.
	 * @return string[] [from, to]
	 */
	public static function span( $days ) {
		$tz   = wp_timezone();
		$to   = new DateTimeImmutable( 'now', $tz );
		$from = $to->modify( '-' . ( (int) $days - 1 ) . ' days' );
		return array( $from->format( 'Y-m-d' ), $to->format( 'Y-m-d' ) );
	}

	/* --------------------------------------------------------------------- statistics */

	/**
	 * Dashboard → GhostStat (statistics).
	 */
	public static function page_stats() {
		if ( ! current_user_can( self::stats_cap() ) ) {
			return;
		}
		// Read-only view parameter, whitelisted; no state changes here.
		$days = isset( $_GET['range'] ) ? absint( wp_unslash( $_GET['range'] ) ) : 30; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! array_key_exists( $days, self::ranges() ) ) {
			$days = 30;
		}
		list( $from, $to ) = self::span( $days );

		echo '<div class="wrap ghoststat-wrap">';
		echo '<h1 class="ghoststat-h1">' . esc_html__( 'GhostStat statistics', 'ghoststat-analytics' ) . '</h1>';

		if ( ! GhostStat_Api::has_key() ) {
			self::connect_box();
			echo '</div>';
			return;
		}

		$overview = GhostStat_Api::get( 'overview', $from, $to );
		if ( is_wp_error( $overview ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $overview->get_error_message() ) . '</p></div></div>';
			return;
		}

		// toolbar: range + refresh + open in GhostStat
		echo '<div class="ghoststat-toolbar">';
		echo '<nav class="ghoststat-ranges" aria-label="' . esc_attr__( 'Period', 'ghoststat-analytics' ) . '">';
		foreach ( self::ranges() as $d => $label ) {
			$url = self::stats_url( array( 'range' => $d ) );
			printf( '<a href="%s" class="%s"%s>%s</a>', esc_url( $url ), $d === $days ? 'current' : '', $d === $days ? ' aria-current="page"' : '', esc_html( $label ) );
		}
		echo '</nav><div class="ghoststat-actions">';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'ghoststat_refresh' );
		echo '<input type="hidden" name="action" value="ghoststat_refresh"><input type="hidden" name="range" value="' . esc_attr( $days ) . '">';
		echo '<button type="submit" class="button">' . esc_html__( 'Refresh', 'ghoststat-analytics' ) . '</button></form>';
		echo '<a class="button button-primary" href="' . esc_url( GhostStat_Intel::app_url( '/dashboard' ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open GhostStat', 'ghoststat-analytics' ) . '</a>';
		echo '</div></div>';

		// headline numbers
		$rt     = GhostStat_Api::get( 'realtime' );
		$online = is_wp_error( $rt ) ? null : (int) ( $rt['online'] ?? 0 );
		$cards  = array(
			array( __( 'Visitors', 'ghoststat-analytics' ), number_format_i18n( (int) ( $overview['uniques'] ?? 0 ) ) ),
			array( __( 'Visits', 'ghoststat-analytics' ), number_format_i18n( (int) ( $overview['sessions'] ?? 0 ) ) ),
			array( __( 'Pageviews', 'ghoststat-analytics' ), number_format_i18n( (int) ( $overview['pageviews'] ?? 0 ) ) ),
			array( __( 'Average visit', 'ghoststat-analytics' ), self::duration( (int) ( $overview['avg_visit_seconds'] ?? 0 ) ) ),
			array( __( 'Bounce rate', 'ghoststat-analytics' ), number_format_i18n( (float) ( $overview['bounce_rate'] ?? 0 ) ) . '%' ),
		);
		echo '<div class="ghoststat-cards">';
		foreach ( $cards as $c ) {
			echo '<div class="ghoststat-card"><div class="k">' . esc_html( $c[0] ) . '</div><div class="v">' . esc_html( $c[1] ) . '</div></div>';
		}
		if ( null !== $online ) {
			echo '<div class="ghoststat-card live"><div class="k"><span class="dot" aria-hidden="true"></span>' . esc_html__( 'Online now', 'ghoststat-analytics' ) . '</div><div class="v">' . esc_html( number_format_i18n( $online ) ) . '</div></div>';
		}
		echo '</div>';

		// timeline
		$tl = GhostStat_Api::get( 'timeline', $from, $to );
		echo '<div class="ghoststat-panel"><div class="ghoststat-panel-h"><h2>' . esc_html__( 'Visitors over time', 'ghoststat-analytics' ) . '</h2>';
		echo '<span class="ghoststat-legend"><span class="sw visitors"></span>' . esc_html__( 'Visitors', 'ghoststat-analytics' ) . '<span class="sw pageviews"></span>' . esc_html__( 'Pageviews', 'ghoststat-analytics' ) . '</span></div>';
		if ( is_wp_error( $tl ) ) {
			echo '<p class="ghoststat-empty">' . esc_html( $tl->get_error_message() ) . '</p>';
		} else {
			GhostStat_Charts::out( GhostStat_Charts::timeline( (array) ( $tl['series'] ?? array() ) ) );
		}
		echo '</div>';

		// Visitor Intelligence: intent, frustration, attention, personas, journey, struggling pages
		GhostStat_Intel::render( $from, $to );

		// breakdowns
		$lists = array(
			'pages'     => array( __( 'Top pages', 'ghoststat-analytics' ), null ),
			'referrers' => array( __( 'Top sources', 'ghoststat-analytics' ), array( __CLASS__, 'label_source' ) ),
			'countries' => array( __( 'Countries', 'ghoststat-analytics' ), array( __CLASS__, 'label_country' ) ),
			'devices'   => array( __( 'Devices', 'ghoststat-analytics' ), array( __CLASS__, 'label_device' ) ),
			'browsers'  => array( __( 'Browsers', 'ghoststat-analytics' ), null ),
		);
		echo '<div class="ghoststat-grid">';
		foreach ( $lists as $report => $meta ) {
			$data = GhostStat_Api::get( $report, $from, $to );
			if ( 'referrers' === $report && ! is_wp_error( $data ) ) {
				$data['rows'] = self::without_own_host( (array) ( $data['rows'] ?? array() ), (string) ( $overview['domain'] ?? '' ) );
			}
			echo '<div class="ghoststat-panel"><div class="ghoststat-panel-h"><h2>' . esc_html( $meta[0] ) . '</h2><span class="ghoststat-col">' . esc_html__( 'Visits', 'ghoststat-analytics' ) . '</span></div>';
			if ( is_wp_error( $data ) ) {
				echo '<p class="ghoststat-empty">' . esc_html( $data->get_error_message() ) . '</p>';
			} else {
				GhostStat_Charts::out( GhostStat_Charts::bars( (array) ( $data['rows'] ?? array() ), 10, $meta[1] ) );
			}
			echo '</div>';
		}
		echo '</div>';

		GhostStat_Intel::band();

		echo '<p class="ghoststat-foot">' . esc_html(
			sprintf(
				/* translators: 1: start date, 2: end date */
				__( 'Period %1$s – %2$s. Numbers refresh every five minutes. GhostStat is cookieless: no consent banner is needed for it.', 'ghoststat-analytics' ),
				date_i18n( get_option( 'date_format' ), strtotime( $from . ' 12:00:00' ) ),
				date_i18n( get_option( 'date_format' ), strtotime( $to . ' 12:00:00' ) )
			)
		) . '</p>';
		echo '</div>';
	}

	/**
	 * "Connect" box shown on the statistics page until both keys are saved.
	 */
	private static function connect_box() {
		echo '<div class="ghoststat-panel ghoststat-connect">';
		echo '<h2>' . esc_html__( 'Connect this site to GhostStat', 'ghoststat-analytics' ) . '</h2>';
		echo '<ol>';
		echo '<li>' . esc_html__( 'Sign in at app.ghoststat.me (or create a free account) and add this site.', 'ghoststat-analytics' ) . '</li>';
		echo '<li>' . esc_html__( 'Copy the site key from Install and paste it in Settings → GhostStat. From then on every page is measured.', 'ghoststat-analytics' ) . '</li>';
		echo '<li>' . esc_html__( 'To see the statistics here too, make an API key under Site settings → API keys and paste it in the same settings.', 'ghoststat-analytics' ) . '</li>';
		echo '</ol>';
		echo '<p><a class="button button-primary" href="' . esc_url( self::settings_url() ) . '">' . esc_html__( 'Open settings', 'ghoststat-analytics' ) . '</a> ';
		echo '<a class="button" href="' . esc_url( 'https://app.ghoststat.me/register' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Create a GhostStat account', 'ghoststat-analytics' ) . '</a></p>';
		echo '</div>';
	}

	/**
	 * Refresh button: forget cached numbers, back to the same period.
	 */
	public static function handle_refresh() {
		if ( ! current_user_can( self::stats_cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'ghoststat-analytics' ), 403 );
		}
		check_admin_referer( 'ghoststat_refresh' );
		GhostStat_Api::flush();
		$days = isset( $_POST['range'] ) ? absint( wp_unslash( $_POST['range'] ) ) : 30;
		if ( ! array_key_exists( $days, self::ranges() ) ) {
			$days = 30;
		}
		wp_safe_redirect( self::stats_url( array( 'range' => $days ) ) );
		exit;
	}

	/* --------------------------------------------------------------------- settings */

	/**
	 * Settings → GhostStat.
	 */
	public static function page_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o       = GhostStat_Options::all();
		$has_api = GhostStat_Options::is_api_key( $o['api_key'] );
		$name    = GhostStat_Options::OPTION;

		echo '<div class="wrap ghoststat-wrap">';
		echo '<h1 class="ghoststat-h1">' . esc_html__( 'GhostStat settings', 'ghoststat-analytics' ) . '</h1>';
		// No settings_errors() here: under Settings, WordPress core already prints them (options-head.php).
		self::check_result();

		echo '<form method="post" action="options.php" class="ghoststat-panel ghoststat-form">';
		settings_fields( 'ghoststat' );

		echo '<h2>' . esc_html__( 'Tracking', 'ghoststat-analytics' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'With a site key, the GhostStat tracker is added to every page of this site. It sets no cookies and stores nothing in the visitor\'s browser.', 'ghoststat-analytics' ) . '</p>';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="ghoststat-site-key">' . esc_html__( 'Site key', 'ghoststat-analytics' ) . '</label></th><td>';
		echo '<input type="text" class="regular-text code" id="ghoststat-site-key" name="' . esc_attr( $name ) . '[site_key]" value="' . esc_attr( $o['site_key'] ) . '" autocomplete="off" spellcheck="false">';
		echo '<p class="description">' . esc_html__( 'In the GhostStat app: Install. You can also paste the whole snippet; the key is taken out of it.', 'ghoststat-analytics' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( "Don't count", 'ghoststat-analytics' ) . '</th><td><fieldset><legend class="screen-reader-text">' . esc_html__( "Don't count", 'ghoststat-analytics' ) . '</legend>';
		foreach ( wp_roles()->get_names() as $role => $label ) {
			printf(
				'<label class="ghoststat-role"><input type="checkbox" name="%1$s[exclude_roles][]" value="%2$s"%3$s> %4$s</label>',
				esc_attr( $name ),
				esc_attr( $role ),
				checked( in_array( $role, (array) $o['exclude_roles'], true ), true, false ),
				esc_html( translate_user_role( $label ) )
			);
		}
		echo '<p class="description">' . esc_html__( 'Visits by signed-in users with these roles are not measured. Visitors who are not signed in are always measured.', 'ghoststat-analytics' ) . '</p></fieldset></td></tr>';
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Statistics in WordPress', 'ghoststat-analytics' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Optional. With a read-only API key, Dashboard → GhostStat and the dashboard widget show your numbers here. The key can only read this site\'s reports.', 'ghoststat-analytics' ) . '</p>';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="ghoststat-api-key">' . esc_html__( 'API key', 'ghoststat-analytics' ) . '</label></th><td>';
		if ( $has_api ) {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '[api_key_keep]" value="1">';
			echo '<input type="password" class="regular-text code" id="ghoststat-api-key" name="' . esc_attr( $name ) . '[api_key]" value="" placeholder="' . esc_attr( substr( $o['api_key'], 0, 8 ) . '…' . substr( $o['api_key'], -4 ) ) . '" autocomplete="new-password" spellcheck="false">';
			echo '<p class="description">' . esc_html__( 'A key is saved. Leave this empty to keep it, or paste a new one.', 'ghoststat-analytics' ) . '</p>';
			echo '<label class="ghoststat-role"><input type="checkbox" name="' . esc_attr( $name ) . '[api_key_remove]" value="1"> ' . esc_html__( 'Remove the saved key', 'ghoststat-analytics' ) . '</label>';
		} else {
			echo '<input type="password" class="regular-text code" id="ghoststat-api-key" name="' . esc_attr( $name ) . '[api_key]" value="" placeholder="gsr_…" autocomplete="new-password" spellcheck="false">';
			echo '<p class="description">' . esc_html__( 'In the GhostStat app: Site settings → API keys → Create key.', 'ghoststat-analytics' ) . '</p>';
		}
		echo '</td></tr></tbody></table>';

		$has_rep = GhostStat_Options::is_report_key( $o['report_key'] );
		echo '<h2>' . esc_html__( 'Software tracking', 'ghoststat-analytics' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Optional. With a report key, this site tells GhostStat which WordPress version, PHP version, themes and plugins it runs, and every install, update, activation and removal, once a day and right after each change. GhostStat draws those changes on your traffic, so you see what changed right before visits dropped.', 'ghoststat-analytics' ) . '</p>';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="ghoststat-report-key">' . esc_html__( 'Report key', 'ghoststat-analytics' ) . '</label></th><td>';
		if ( $has_rep ) {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '[report_key_keep]" value="1">';
			echo '<input type="password" class="regular-text code" id="ghoststat-report-key" name="' . esc_attr( $name ) . '[report_key]" value="" placeholder="' . esc_attr( substr( $o['report_key'], 0, 9 ) . '…' . substr( $o['report_key'], -4 ) ) . '" autocomplete="new-password" spellcheck="false">';
			echo '<p class="description">' . esc_html__( 'A key is saved. Leave this empty to keep it, or paste a new one.', 'ghoststat-analytics' ) . '</p>';
			echo '<label class="ghoststat-role"><input type="checkbox" name="' . esc_attr( $name ) . '[report_key_remove]" value="1"> ' . esc_html__( 'Remove the saved key and stop reporting', 'ghoststat-analytics' ) . '</label>';
		} else {
			echo '<input type="password" class="regular-text code" id="ghoststat-report-key" name="' . esc_attr( $name ) . '[report_key]" value="" placeholder="gssw_…" autocomplete="new-password" spellcheck="false">';
			if ( GhostStat_Options::is_site_key( $o['site_key'] ) ) {
				// Opens Software Development → Connect for exactly this site (?s= picks the site in the app).
				echo '<p class="ghoststat-getkey"><a class="button" href="' . esc_url( GhostStat_Intel::app_url( '/software-development' ) . '#connect' ) . '" target="_blank" rel="noopener">' . esc_html__( 'Get your report key', 'ghoststat-analytics' ) . '</a></p>';
				echo '<p class="description">' . esc_html__( 'Opens Software Development → Connect in GhostStat for this site. Press "Make a report key" there, copy the key and paste it here.', 'ghoststat-analytics' ) . '</p>';
			} else {
				echo '<p class="description">' . esc_html__( 'In the GhostStat app: Software Development → Connect → Make a report key.', 'ghoststat-analytics' ) . '</p>';
			}
		}
		echo '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Errors', 'ghoststat-analytics' ) . '</th><td>';
		echo '<label><input type="checkbox" name="' . esc_attr( $name ) . '[report_errors]" value="1"' . checked( ! empty( $o['report_errors'] ), true, false ) . '> ' . esc_html__( 'Also report fatal PHP errors, with the plugin or theme they came from', 'ghoststat-analytics' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Off by default. Only the error type, the first line of the message, the file (relative to WordPress) and the line are sent; GhostStat also removes mail addresses, IP addresses and tokens. Error capture must be switched on in GhostStat as well.', 'ghoststat-analytics' ) . '</p>';
		$st = get_option( GhostStat_Software::STATUS );
		if ( $has_rep && is_array( $st ) && ! empty( $st['at'] ) ) {
			echo '<p class="ghoststat-swstatus ' . ( ! empty( $st['ok'] ) ? 'ok' : 'bad' ) . '">' . esc_html(
				sprintf(
					/* translators: 1: date and time, 2: result */
					__( 'Last report %1$s: %2$s', 'ghoststat-analytics' ),
					wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $st['at'] ),
					(string) $st['message']
				)
			) . '</p>';
		}
		echo '</td></tr></tbody></table>';
		submit_button();
		echo '</form>';

		// connection check (separate form: it changes nothing)
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ghoststat-panel ghoststat-check">';
		wp_nonce_field( 'ghoststat_check' );
		echo '<input type="hidden" name="action" value="ghoststat_check">';
		echo '<h2>' . esc_html__( 'Check the connection', 'ghoststat-analytics' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Asks GhostStat whether it knows the site key and, if saved, whether the API key works. With a report key it also sends a software report right away.', 'ghoststat-analytics' ) . '</p>';
		submit_button( __( 'Check now', 'ghoststat-analytics' ), 'secondary', 'submit', false );
		echo '</form>';

		self::privacy_box();
		echo '</div>';
	}

	/**
	 * What the plugin sends where (also in the readme).
	 */
	private static function privacy_box() {
		echo '<div class="ghoststat-panel ghoststat-privacy"><h2>' . esc_html__( 'What is sent to GhostStat', 'ghoststat-analytics' ) . '</h2><ul>';
		echo '<li>' . esc_html__( 'Your visitors\' browsers load the tracker from cdn.ghoststat.me and send page address, referrer, page title, screen size, language and browser details to api.ghoststat.me, plus whatever the GhostStat features you switched on measure (for example heatmaps). No cookies, no local storage, no fingerprinting.', 'ghoststat-analytics' ) . '</li>';
		echo '<li>' . esc_html__( 'This WordPress site asks api.ghoststat.me for your reports, with your API key, only when someone opens the statistics page or the dashboard widget.', 'ghoststat-analytics' ) . '</li>';
		echo '<li>' . esc_html__( 'Only with a report key: this WordPress site sends api.ghoststat.me its WordPress and PHP version and the name, version and status of each plugin and theme, once a day and after each change. With error reporting on, also fatal PHP errors. Never anything about visitors or users.', 'ghoststat-analytics' ) . '</li>';
		echo '<li>' . esc_html__( 'With a site key saved: once a day this WordPress site tells api.ghoststat.me that GhostStat Analytics is in use, with the plugin, WordPress and PHP version and a random install ID, and when the plugin is activated, deactivated, updated or deleted. This helps us see which versions are in use. No site address, no visitor or user data. Remove the site key to stop it.', 'ghoststat-analytics' ) . '</li>';
		echo '</ul><p><a href="' . esc_url( 'https://ghoststat.me/privacy' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Privacy policy', 'ghoststat-analytics' ) . '</a> · <a href="' . esc_url( 'https://ghoststat.me/terms' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Terms of service', 'ghoststat-analytics' ) . '</a></p></div>';
	}

	/**
	 * Run the connection check and go back to Settings.
	 */
	public static function handle_check() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'ghoststat-analytics' ), 403 );
		}
		check_admin_referer( 'ghoststat_check' );
		$o      = GhostStat_Options::all();
		$result = array();
		if ( ! GhostStat_Options::is_site_key( $o['site_key'] ) ) {
			$result[] = array( 'error', __( 'No site key saved yet.', 'ghoststat-analytics' ) );
		} else {
			$exists = GhostStat_Api::site_exists( $o['site_key'] );
			if ( is_wp_error( $exists ) ) {
				$result[] = array( 'error', $exists->get_error_message() );
			} elseif ( $exists ) {
				$result[] = array( 'success', __( 'Site key OK: GhostStat knows this site and the tracker is on every page.', 'ghoststat-analytics' ) );
			} else {
				$result[] = array( 'error', __( 'GhostStat does not know this site key. Check it in the GhostStat app under Install.', 'ghoststat-analytics' ) );
			}
		}
		if ( GhostStat_Options::is_api_key( $o['api_key'] ) && GhostStat_Options::is_site_key( $o['site_key'] ) ) {
			$ov = GhostStat_Api::get( 'overview', '', '', true );
			if ( is_wp_error( $ov ) ) {
				$result[] = array( 'error', $ov->get_error_message() );
			} else {
				$result[] = array(
					'success',
					sprintf(
						/* translators: %s: number of pageviews */
						__( 'API key OK: %s pageviews in the last 30 days.', 'ghoststat-analytics' ),
						number_format_i18n( (int) ( $ov['pageviews'] ?? 0 ) )
					),
				);
			}
		}
		if ( GhostStat_Software::enabled() ) {
			$sw       = GhostStat_Software::send();
			$result[] = array( ! empty( $sw['ok'] ) ? 'success' : 'error', $sw['message'] );
		}
		set_transient( 'ghoststat_check_' . get_current_user_id(), $result, 120 );
		wp_safe_redirect( self::settings_url( array( 'ghoststat_checked' => 1 ) ) );
		exit;
	}

	/**
	 * Show the last check's result once.
	 */
	private static function check_result() {
		$key = 'ghoststat_check_' . get_current_user_id();
		$res = get_transient( $key );
		if ( ! is_array( $res ) ) {
			return;
		}
		delete_transient( $key );
		foreach ( $res as $r ) {
			$cls = 'success' === $r[0] ? 'notice-success' : 'notice-error';
			echo '<div class="notice ' . esc_attr( $cls ) . ' inline ghoststat-checkmsg"><p>' . esc_html( $r[1] ) . '</p></div>';
		}
	}

	/* --------------------------------------------------------------------- dashboard widget */

	/**
	 * Register the widget for users who may see statistics.
	 */
	public static function dashboard_widget() {
		if ( ! current_user_can( self::stats_cap() ) ) {
			return;
		}
		wp_add_dashboard_widget( 'ghoststat_widget', __( 'GhostStat — last 7 days', 'ghoststat-analytics' ), array( __CLASS__, 'render_widget' ) );
	}

	/**
	 * Widget body.
	 */
	public static function render_widget() {
		if ( ! GhostStat_Api::has_key() ) {
			echo '<p>' . esc_html__( 'Add an API key in Settings → GhostStat to see your visitors here.', 'ghoststat-analytics' ) . '</p>';
			echo '<p><a class="button" href="' . esc_url( self::settings_url() ) . '">' . esc_html__( 'Open settings', 'ghoststat-analytics' ) . '</a></p>';
			return;
		}
		list( $from, $to ) = self::span( 7 );
		$ov = GhostStat_Api::get( 'overview', $from, $to );
		if ( is_wp_error( $ov ) ) {
			echo '<p class="ghoststat-empty">' . esc_html( $ov->get_error_message() ) . '</p>';
			return;
		}
		$tl     = GhostStat_Api::get( 'timeline', $from, $to );
		$series = is_wp_error( $tl ) ? array() : (array) ( $tl['series'] ?? array() );
		echo '<div class="ghoststat-widget">';
		echo '<div class="ghoststat-wnums">';
		echo '<div><div class="v">' . esc_html( number_format_i18n( (int) ( $ov['uniques'] ?? 0 ) ) ) . '</div><div class="k">' . esc_html__( 'Visitors', 'ghoststat-analytics' ) . '</div></div>';
		echo '<div><div class="v">' . esc_html( number_format_i18n( (int) ( $ov['pageviews'] ?? 0 ) ) ) . '</div><div class="k">' . esc_html__( 'Pageviews', 'ghoststat-analytics' ) . '</div></div>';
		echo '<div><div class="v">' . esc_html( number_format_i18n( (float) ( $ov['bounce_rate'] ?? 0 ) ) ) . '%</div><div class="k">' . esc_html__( 'Bounce rate', 'ghoststat-analytics' ) . '</div></div>';
		echo '</div>';
		GhostStat_Charts::out( GhostStat_Charts::sparkline( array_map( 'intval', wp_list_pluck( $series, 'vi' ) ) ) );
		$pages = GhostStat_Api::get( 'pages', $from, $to );
		echo '<h3>' . esc_html__( 'Top pages', 'ghoststat-analytics' ) . '</h3>';
		if ( is_wp_error( $pages ) ) {
			echo '<p class="ghoststat-empty">' . esc_html( $pages->get_error_message() ) . '</p>';
		} else {
			GhostStat_Charts::out( GhostStat_Charts::bars( (array) ( $pages['rows'] ?? array() ), 5 ) );
		}
		echo '<p class="ghoststat-wfoot"><a href="' . esc_url( self::stats_url() ) . '">' . esc_html__( 'All statistics', 'ghoststat-analytics' ) . ' →</a></p>';
		echo '</div>';
	}

	/* --------------------------------------------------------------------- labels */

	/**
	 * 26 seconds → "26 s", 95 → "1 min 35 s".
	 *
	 * @param int $s Seconds.
	 * @return string
	 */
	public static function duration( $s ) {
		$s = max( 0, (int) $s );
		if ( $s < 60 ) {
			/* translators: %s: seconds */
			return sprintf( __( '%s s', 'ghoststat-analytics' ), number_format_i18n( $s ) );
		}
		/* translators: 1: minutes, 2: seconds */
		return sprintf( __( '%1$s min %2$s s', 'ghoststat-analytics' ), number_format_i18n( floor( $s / 60 ) ), number_format_i18n( $s % 60 ) );
	}

	/**
	 * Clicks from one page of the site to another show up as the site's own host; that is not a source.
	 *
	 * @param array  $rows   Referrer rows.
	 * @param string $domain The site's domain in GhostStat.
	 * @return array
	 */
	public static function without_own_host( $rows, $domain ) {
		$domain = strtolower( preg_replace( '/^www\./', '', $domain ) );
		$home   = strtolower( preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
		return array_values(
			array_filter(
				$rows,
				function ( $r ) use ( $domain, $home ) {
					$h = strtolower( preg_replace( '/^www\./', '', (string) $r['label'] ) );
					return '' === $h || ( $h !== $domain && $h !== $home );
				}
			)
		);
	}

	/**
	 * Referrer host → readable source.
	 *
	 * @param string $l Label from the API.
	 * @return string
	 */
	public static function label_source( $l ) {
		return '(none)' === $l ? __( 'Direct / unknown', 'ghoststat-analytics' ) : $l;
	}

	/**
	 * Country code → name (needs PHP intl; otherwise the code).
	 *
	 * @param string $l Two-letter code.
	 * @return string
	 */
	public static function label_country( $l ) {
		if ( preg_match( '/^[A-Z]{2}$/', $l ) && class_exists( 'Locale' ) ) {
			$name = Locale::getDisplayRegion( '-' . $l, determine_locale() );
			if ( $name && $name !== $l ) {
				return $name;
			}
		}
		return $l;
	}

	/**
	 * Device type → translated word.
	 *
	 * @param string $l Label from the API.
	 * @return string
	 */
	public static function label_device( $l ) {
		$map = array(
			'mobile'  => __( 'Phone', 'ghoststat-analytics' ),
			'desktop' => __( 'Desktop', 'ghoststat-analytics' ),
			'tablet'  => __( 'Tablet', 'ghoststat-analytics' ),
		);
		return isset( $map[ $l ] ) ? $map[ $l ] : $l;
	}
}
