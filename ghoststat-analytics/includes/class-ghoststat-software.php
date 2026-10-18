<?php
/**
 * Software Development connector: tells GhostStat what is installed on this WordPress site and what changes.
 *
 * Off until a report key (gssw_…, made in the GhostStat app under Software Development → Connect) is saved.
 * Then it reports, server to server, to https://api.ghoststat.me/collect/software:
 * - the WordPress and PHP version, the themes and the plugins (name, version, active, auto-update, update
 *   available), once a day and after every change;
 * - each change as it happens: installed, activated, deactivated, updated (old → new), removed, theme switched,
 *   WordPress updated;
 * - only if the site owner switches it on: fatal PHP errors, with the plugin or theme they came from.
 *
 * Changes are queued and sent by WP-Cron a minute later, never during a page load. Nothing about visitors or
 * users is sent.
 *
 * @package GhostStat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Inventory, change queue, fatal-error capture and the report.
 */
class GhostStat_Software {

	const QUEUE      = 'ghoststat_sw_queue';
	const ERRORS     = 'ghoststat_sw_errors';
	const SNAPSHOT   = 'ghoststat_sw_snapshot';
	const STATUS     = 'ghoststat_sw_status';
	const HOOK_SEND  = 'ghoststat_sw_send';
	const HOOK_DAILY = 'ghoststat_sw_daily';
	const MAX_QUEUE  = 200;
	const MAX_ERRORS = 50;

	/**
	 * Hooks. Everything is a no-op until a report key is saved.
	 */
	public static function init() {
		add_action( self::HOOK_SEND, array( __CLASS__, 'send' ) );
		add_action( self::HOOK_DAILY, array( __CLASS__, 'send' ) );
		add_action( 'add_option_' . GhostStat_Options::OPTION, array( __CLASS__, 'on_settings_added' ), 10, 2 );
		add_action( 'update_option_' . GhostStat_Options::OPTION, array( __CLASS__, 'on_settings_saved' ), 10, 2 );
		if ( ! self::enabled() ) {
			return;
		}
		add_action( 'init', array( __CLASS__, 'ensure_daily' ) );
		add_action( 'activated_plugin', array( __CLASS__, 'on_activated' ), 10, 1 );
		add_action( 'deactivated_plugin', array( __CLASS__, 'on_deactivated' ), 10, 1 );
		add_action( 'deleted_plugin', array( __CLASS__, 'on_deleted' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'on_upgrader' ), 10, 2 );
		add_action( 'switch_theme', array( __CLASS__, 'on_switch_theme' ), 10, 3 );
		add_action( '_core_updated_successfully', array( __CLASS__, 'on_core_updated' ), 10, 1 );
		if ( GhostStat_Options::get( 'report_errors' ) ) {
			register_shutdown_function( array( __CLASS__, 'on_shutdown' ) );
		}
	}

	/**
	 * Is the connector on?
	 *
	 * @return bool
	 */
	public static function enabled() {
		return GhostStat_Options::is_report_key( GhostStat_Options::get( 'report_key' ) )
			&& GhostStat_Options::is_site_key( GhostStat_Options::get( 'site_key' ) );
	}

	/**
	 * Daily full report while the connector is on.
	 */
	public static function ensure_daily() {
		if ( ! wp_next_scheduled( self::HOOK_DAILY ) ) {
			wp_schedule_event( time() + 300, 'daily', self::HOOK_DAILY );
		}
	}

	/**
	 * Stop all scheduled reports (key removed, plugin deactivated or uninstalled).
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK_DAILY );
		wp_clear_scheduled_hook( self::HOOK_SEND );
	}

	/**
	 * Settings saved for the first time.
	 *
	 * @param string $option Option name.
	 * @param array  $value  New value.
	 */
	public static function on_settings_added( $option, $value ) {
		self::on_settings_saved( array(), $value );
	}

	/**
	 * Settings saved: a new report key sends the first report a few seconds later; no key stops all reports.
	 *
	 * @param array $old Old value.
	 * @param array $new New value.
	 */
	public static function on_settings_saved( $old, $new ) {
		$old_key = is_array( $old ) && isset( $old['report_key'] ) ? (string) $old['report_key'] : '';
		$new_key = is_array( $new ) && isset( $new['report_key'] ) ? (string) $new['report_key'] : '';
		if ( ! GhostStat_Options::is_report_key( $new_key ) ) {
			self::unschedule();
			return;
		}
		if ( $new_key !== $old_key ) {
			wp_clear_scheduled_hook( self::HOOK_SEND );
			wp_schedule_single_event( time() + 5, self::HOOK_SEND );
			if ( ! wp_next_scheduled( self::HOOK_DAILY ) ) {
				wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::HOOK_DAILY );
			}
		}
	}

	/* --------------------------------------------------------------------- inventory */

	/**
	 * Who made a change.
	 *
	 * @return string
	 */
	private static function actor() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'cli';
		}
		if ( wp_doing_cron() ) {
			return 'auto';
		}
		return 'user';
	}

	/**
	 * The plugin's folder name (or file name for a single-file plugin): what wordpress.org calls the slug.
	 *
	 * @param string $file Plugin file relative to the plugins folder.
	 * @return string
	 */
	public static function plugin_slug( $file ) {
		$dir = dirname( (string) $file );
		return '.' === $dir ? basename( (string) $file, '.php' ) : $dir;
	}

	/**
	 * Everything installed right now.
	 *
	 * @return array {platform, runtime, inventory}
	 */
	public static function inventory() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		wp_clean_plugins_cache( false );
		$plugins = get_plugins();
		$active  = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		$upd_p   = get_site_transient( 'update_plugins' );
		$items   = array();
		foreach ( $plugins as $file => $data ) {
			$new    = ( is_object( $upd_p ) && isset( $upd_p->response[ $file ]->new_version ) ) ? (string) $upd_p->response[ $file ]->new_version : null;
			$known  = is_object( $upd_p ) && ( isset( $upd_p->response[ $file ] ) || isset( $upd_p->no_update[ $file ] ) );
			$items[] = array(
				'type'        => 'plugin',
				'slug'        => self::plugin_slug( $file ),
				'name'        => wp_strip_all_tags( (string) $data['Name'] ),
				'version'     => (string) $data['Version'],
				'active'      => in_array( $file, $active, true ),
				'update'      => $new,
				'author'      => wp_strip_all_tags( (string) $data['AuthorName'] ),
				'origin'      => $known ? 'wordpress.org' : null,
			);
		}
		foreach ( get_mu_plugins() as $file => $data ) {
			$items[] = array(
				'type'    => 'plugin',
				'slug'    => 'mu-' . basename( (string) $file, '.php' ),
				'name'    => wp_strip_all_tags( (string) $data['Name'] ) ? wp_strip_all_tags( (string) $data['Name'] ) : basename( (string) $file ),
				'version' => (string) $data['Version'],
				'active'  => true,
				'origin'  => 'must-use',
			);
		}
		$current = get_stylesheet();
		$parent  = get_template();
		$upd_t   = get_site_transient( 'update_themes' );
		foreach ( wp_get_themes() as $slug => $theme ) {
			$items[] = array(
				'type'        => 'theme',
				'slug'        => (string) $slug,
				'name'        => wp_strip_all_tags( (string) $theme->get( 'Name' ) ),
				'version'     => (string) $theme->get( 'Version' ),
				'active'      => $slug === $current || $slug === $parent,
				'update'      => ( is_object( $upd_t ) && isset( $upd_t->response[ $slug ]['new_version'] ) ) ? (string) $upd_t->response[ $slug ]['new_version'] : null,
				'author'      => wp_strip_all_tags( (string) $theme->get( 'Author' ) ),
			);
		}
		$core_new = null;
		$upd_c    = get_site_transient( 'update_core' );
		if ( is_object( $upd_c ) && ! empty( $upd_c->updates ) && is_array( $upd_c->updates ) ) {
			foreach ( $upd_c->updates as $u ) {
				if ( isset( $u->response, $u->current ) && 'upgrade' === $u->response ) {
					$core_new = (string) $u->current;
					break;
				}
			}
		}
		return array(
			'platform'  => array( 'name' => 'wordpress', 'version' => get_bloginfo( 'version' ), 'update' => $core_new ),
			'runtime'   => array( 'name' => 'php', 'version' => PHP_VERSION ),
			'inventory' => $items,
		);
	}

	/**
	 * Versions as last reported (slug => version), to give an update its "from" version.
	 *
	 * @return array
	 */
	private static function snapshot() {
		$s = get_option( self::SNAPSHOT, array() );
		return is_array( $s ) ? $s : array();
	}

	/**
	 * Remember the versions now installed.
	 *
	 * @param array $inv Result of inventory().
	 */
	private static function save_snapshot( $inv ) {
		$s = array( 'core|wordpress' => (string) $inv['platform']['version'] );
		foreach ( $inv['inventory'] as $it ) {
			$s[ $it['type'] . '|' . $it['slug'] ] = (string) $it['version'];
		}
		update_option( self::SNAPSHOT, $s, false );
	}

	/* --------------------------------------------------------------------- change events */

	/**
	 * Queue one change and make sure a report goes out soon.
	 *
	 * @param array $event Event fields.
	 */
	private static function queue( $event ) {
		$q = get_option( self::QUEUE, array() );
		$q = is_array( $q ) ? $q : array();
		$q[] = array_merge( array( 'at' => gmdate( 'c' ), 'actor' => self::actor() ), $event );
		if ( count( $q ) > self::MAX_QUEUE ) {
			$q = array_slice( $q, -self::MAX_QUEUE );
		}
		update_option( self::QUEUE, $q, false );
		self::soon();
	}

	/**
	 * Schedule a report in a minute (once).
	 */
	private static function soon() {
		if ( ! wp_next_scheduled( self::HOOK_SEND ) ) {
			wp_schedule_single_event( time() + 60, self::HOOK_SEND );
		}
	}

	/**
	 * Plugin header data for one plugin file.
	 *
	 * @param string $file Plugin file.
	 * @return array {name, version}
	 */
	private static function plugin_info( $file ) {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$path = WP_PLUGIN_DIR . '/' . $file;
		if ( ! is_file( $path ) ) {
			return array( 'name' => self::plugin_slug( $file ), 'version' => null );
		}
		$d = get_plugin_data( $path, false, false );
		return array( 'name' => wp_strip_all_tags( (string) $d['Name'] ), 'version' => (string) $d['Version'] );
	}

	/**
	 * activated_plugin.
	 *
	 * @param string $file Plugin file.
	 */
	public static function on_activated( $file ) {
		$i = self::plugin_info( $file );
		self::queue( array( 'action' => 'activated', 'type' => 'plugin', 'slug' => self::plugin_slug( $file ), 'name' => $i['name'], 'to' => $i['version'] ) );
	}

	/**
	 * deactivated_plugin.
	 *
	 * @param string $file Plugin file.
	 */
	public static function on_deactivated( $file ) {
		$i = self::plugin_info( $file );
		self::queue( array( 'action' => 'deactivated', 'type' => 'plugin', 'slug' => self::plugin_slug( $file ), 'name' => $i['name'], 'to' => $i['version'] ) );
	}

	/**
	 * deleted_plugin.
	 *
	 * @param string $file    Plugin file.
	 * @param bool   $deleted Whether it was deleted.
	 */
	public static function on_deleted( $file, $deleted ) {
		if ( ! $deleted ) {
			return;
		}
		$snap = self::snapshot();
		$slug = self::plugin_slug( $file );
		self::queue( array( 'action' => 'removed', 'type' => 'plugin', 'slug' => $slug, 'from' => $snap[ 'plugin|' . $slug ] ?? null ) );
	}

	/**
	 * Installs and updates of plugins, themes and WordPress itself.
	 *
	 * @param WP_Upgrader $upgrader Upgrader.
	 * @param array       $extra    {type, action, plugins|themes|plugin|theme}.
	 */
	public static function on_upgrader( $upgrader, $extra ) {
		$type   = isset( $extra['type'] ) ? (string) $extra['type'] : '';
		$action = isset( $extra['action'] ) ? (string) $extra['action'] : '';
		$snap   = self::snapshot();
		if ( 'plugin' === $type ) {
			wp_clean_plugins_cache( false );
			$files = array();
			if ( ! empty( $extra['plugins'] ) ) {
				$files = (array) $extra['plugins'];
			} elseif ( ! empty( $extra['plugin'] ) ) {
				$files = array( (string) $extra['plugin'] );
			} elseif ( 'install' === $action && is_object( $upgrader ) && method_exists( $upgrader, 'plugin_info' ) ) {
				$f = $upgrader->plugin_info();
				if ( $f ) {
					$files = array( $f );
				}
			}
			foreach ( $files as $file ) {
				$i    = self::plugin_info( $file );
				$slug = self::plugin_slug( $file );
				self::queue(
					array(
						'action' => 'install' === $action ? 'installed' : 'updated',
						'type'   => 'plugin',
						'slug'   => $slug,
						'name'   => $i['name'],
						'from'   => 'install' === $action ? null : ( $snap[ 'plugin|' . $slug ] ?? null ),
						'to'     => $i['version'],
					)
				);
			}
		} elseif ( 'theme' === $type ) {
			$slugs = ! empty( $extra['themes'] ) ? (array) $extra['themes'] : ( ! empty( $extra['theme'] ) ? array( (string) $extra['theme'] ) : array() );
			if ( ! $slugs && 'install' === $action && is_object( $upgrader ) && method_exists( $upgrader, 'theme_info' ) ) {
				$t = $upgrader->theme_info();
				if ( $t ) {
					$slugs = array( $t->get_stylesheet() );
				}
			}
			foreach ( $slugs as $slug ) {
				$t = wp_get_theme( $slug );
				self::queue(
					array(
						'action' => 'install' === $action ? 'installed' : 'updated',
						'type'   => 'theme',
						'slug'   => (string) $slug,
						'name'   => $t->exists() ? wp_strip_all_tags( (string) $t->get( 'Name' ) ) : (string) $slug,
						'from'   => 'install' === $action ? null : ( $snap[ 'theme|' . $slug ] ?? null ),
						'to'     => $t->exists() ? (string) $t->get( 'Version' ) : null,
					)
				);
			}
		}
		// WordPress itself: see on_core_updated(). Translations: not reported.
	}

	/**
	 * switch_theme.
	 *
	 * @param string   $name      New theme name.
	 * @param WP_Theme $new_theme New theme.
	 * @param WP_Theme $old_theme Old theme.
	 */
	public static function on_switch_theme( $name, $new_theme, $old_theme ) {
		if ( $old_theme instanceof WP_Theme && $old_theme->exists() ) {
			self::queue( array( 'action' => 'deactivated', 'type' => 'theme', 'slug' => $old_theme->get_stylesheet(), 'name' => wp_strip_all_tags( (string) $old_theme->get( 'Name' ) ), 'to' => (string) $old_theme->get( 'Version' ) ) );
		}
		if ( $new_theme instanceof WP_Theme ) {
			self::queue( array( 'action' => 'activated', 'type' => 'theme', 'slug' => $new_theme->get_stylesheet(), 'name' => wp_strip_all_tags( (string) $new_theme->get( 'Name' ) ), 'to' => (string) $new_theme->get( 'Version' ) ) );
		}
	}

	/**
	 * WordPress itself was updated.
	 *
	 * @param string $version New version.
	 */
	public static function on_core_updated( $version ) {
		$snap = self::snapshot();
		self::queue( array( 'action' => 'updated', 'type' => 'core', 'slug' => 'wordpress', 'name' => 'WordPress', 'from' => $snap['core|wordpress'] ?? null, 'to' => (string) $version ) );
	}

	/* --------------------------------------------------------------------- fatal errors (opt-in) */

	/**
	 * Which plugin or theme a file belongs to.
	 *
	 * @param string $file Absolute path.
	 * @return array {type, slug, file}
	 */
	public static function owner( $file ) {
		$file = wp_normalize_path( (string) $file );
		$rel  = $file;
		$root = wp_normalize_path( ABSPATH );
		if ( 0 === strpos( $file, $root ) ) {
			$rel = substr( $file, strlen( $root ) );
		}
		$plugins = wp_normalize_path( WP_PLUGIN_DIR ) . '/';
		$mu      = wp_normalize_path( WPMU_PLUGIN_DIR ) . '/';
		$themes  = wp_normalize_path( get_theme_root() ) . '/';
		if ( 0 === strpos( $file, $plugins ) ) {
			$part = explode( '/', substr( $file, strlen( $plugins ) ) );
			$slug = count( $part ) > 1 ? $part[0] : basename( $part[0], '.php' );
			return array( 'type' => 'plugin', 'slug' => $slug, 'file' => 'wp-content/plugins/' . substr( $file, strlen( $plugins ) ) );
		}
		if ( 0 === strpos( $file, $mu ) ) {
			return array( 'type' => 'plugin', 'slug' => 'mu-' . basename( $file, '.php' ), 'file' => 'wp-content/mu-plugins/' . substr( $file, strlen( $mu ) ) );
		}
		if ( 0 === strpos( $file, $themes ) ) {
			$part = explode( '/', substr( $file, strlen( $themes ) ) );
			return array( 'type' => 'theme', 'slug' => $part[0], 'file' => 'wp-content/themes/' . substr( $file, strlen( $themes ) ) );
		}
		if ( 0 === strpos( $rel, 'wp-includes/' ) || 0 === strpos( $rel, 'wp-admin/' ) ) {
			return array( 'type' => 'core', 'slug' => 'wordpress', 'file' => $rel );
		}
		return array( 'type' => null, 'slug' => null, 'file' => basename( $file ) );
	}

	/**
	 * At the end of every request: was it a fatal error? Then remember it (grouped, counted).
	 */
	public static function on_shutdown() {
		$e = error_get_last();
		if ( ! is_array( $e ) || ! in_array( (int) $e['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ), true ) ) {
			return;
		}
		try {
			$own = self::owner( (string) $e['file'] );
			$msg = (string) $e['message'];
			$msg = str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $msg ) ); // no absolute paths
			$msg = substr( (string) strtok( $msg, "\n" ), 0, 500 );                              // first line: no stack trace
			$sig = md5( $own['file'] . '|' . (int) $e['line'] . '|' . preg_replace( '/\d+/', '#', $msg ) );
			$all = get_option( self::ERRORS, array() );
			$all = is_array( $all ) ? $all : array();
			if ( isset( $all[ $sig ] ) ) {
				$all[ $sig ]['count']++;
				$all[ $sig ]['at'] = gmdate( 'c' );
			} elseif ( count( $all ) < self::MAX_ERRORS ) {
				$all[ $sig ] = array(
					'at'      => gmdate( 'c' ),
					'level'   => 'fatal',
					'message' => $msg,
					'type'    => $own['type'],
					'slug'    => $own['slug'],
					'file'    => $own['file'],
					'line'    => (int) $e['line'],
					'count'   => 1,
				);
			}
			update_option( self::ERRORS, $all, false );
			self::soon();
		} catch ( \Throwable $t ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- never make a failing request worse.
		}
	}

	/* --------------------------------------------------------------------- the report */

	/**
	 * Send the report now: inventory, queued changes and (if on) errors. Called by WP-Cron and by "Check now".
	 *
	 * @return array {ok, code, message}
	 */
	public static function send() {
		if ( ! self::enabled() ) {
			return array( 'ok' => false, 'code' => 0, 'message' => __( 'No report key saved.', 'ghoststat-analytics' ) );
		}
		$inv    = self::inventory();
		$queue  = get_option( self::QUEUE, array() );
		$queue  = is_array( $queue ) ? array_values( $queue ) : array();
		$errors = GhostStat_Options::get( 'report_errors' ) ? get_option( self::ERRORS, array() ) : array();
		$errors = is_array( $errors ) ? array_values( $errors ) : array();
		$body   = array(
			'schema'    => 1,
			'agent'     => array( 'name' => 'ghoststat-wordpress', 'version' => GHOSTSTAT_VERSION ),
			'platform'  => $inv['platform'],
			'runtime'   => $inv['runtime'],
			'inventory' => $inv['inventory'],
			'complete'  => true,
			'events'    => $queue,
			'errors'    => $errors,
		);
		$url  = add_query_arg( 'site', rawurlencode( GhostStat_Options::get( 'site_key' ) ), GhostStat_Api::base() . '/collect/software' );
		$resp = wp_remote_post(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . GhostStat_Options::get( 'report_key' ),
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $resp ) ) {
			return self::status( false, 0, __( 'GhostStat could not be reached. The report is sent again later.', 'ghoststat-analytics' ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$json = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		if ( 200 === $code ) {
			// Only what was sent is cleared: changes queued meanwhile stay for the next report.
			$left = get_option( self::QUEUE, array() );
			$left = is_array( $left ) ? array_slice( array_values( $left ), count( $queue ) ) : array();
			update_option( self::QUEUE, $left, false );
			if ( $errors ) {
				update_option( self::ERRORS, array(), false );
			}
			self::save_snapshot( $inv );
			$msg = sprintf(
				/* translators: %s: number of plugins and themes */
				__( 'Report sent: %s plugins and themes.', 'ghoststat-analytics' ),
				number_format_i18n( count( $inv['inventory'] ) )
			);
			if ( is_array( $json ) && ! empty( $json['errors_ignored'] ) ) {
				$msg .= ' ' . __( 'Errors were not stored: switch on error capture in GhostStat too (Software Development → Errors).', 'ghoststat-analytics' );
			}
			return self::status( true, 200, $msg );
		}
		if ( 401 === $code ) {
			return self::status( false, 401, __( 'GhostStat did not accept the report key. It may have been replaced; make a new one under Software Development → Connect.', 'ghoststat-analytics' ) );
		}
		if ( 403 === $code ) {
			return self::status( false, 403, __( 'Software Development is not switched on for this site in GhostStat.', 'ghoststat-analytics' ) );
		}
		if ( 429 === $code ) {
			return self::status( false, 429, __( 'Too many reports in the last hour. The next one goes out later.', 'ghoststat-analytics' ) );
		}
		return self::status( false, $code, __( 'GhostStat sent an unexpected answer. The report is sent again later.', 'ghoststat-analytics' ) );
	}

	/**
	 * Remember the last report's outcome for the settings screen.
	 *
	 * @param bool   $ok      Success.
	 * @param int    $code    HTTP status.
	 * @param string $message Text for the screen.
	 * @return array
	 */
	private static function status( $ok, $code, $message ) {
		$s = array( 'ok' => $ok, 'code' => $code, 'message' => $message, 'at' => time() );
		update_option( self::STATUS, $s, false );
		return $s;
	}
}
