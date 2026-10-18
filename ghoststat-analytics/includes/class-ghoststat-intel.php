<?php
/**
 * The Visitor Intelligence part of Dashboard → GhostStat: intent, frustration and attention, their trend,
 * who the visitors were, where they were in their journey and the pages where they struggled.
 *
 * Read from https://api.ghoststat.me/stats/intel with the site's read-only API key (see GhostStat_Api).
 *
 * @package GhostStat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the intelligence section and the "more in GhostStat" band.
 */
class GhostStat_Intel {

	/**
	 * A page in the GhostStat app for this site.
	 *
	 * @param string $path App path, e.g. /visitor-intelligence.
	 * @param array  $args Extra query arguments.
	 * @return string
	 */
	public static function app_url( $path, $args = array() ) {
		$args = array_merge( array( 's' => GhostStat_Options::get( 'site_key' ) ), $args );
		return add_query_arg( array_map( 'rawurlencode', $args ), 'https://app.ghoststat.me' . $path );
	}

	/**
	 * "See why in GhostStat →" link.
	 *
	 * @param string $url  Target.
	 * @param string $text Link text; defaults to "See why in GhostStat".
	 * @return string
	 */
	private static function why( $url, $text = '' ) {
		return '<a class="ghoststat-why" href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">'
			. esc_html( '' !== $text ? $text : __( 'See why in GhostStat', 'ghoststat-analytics' ) ) . ' <span aria-hidden="true">→</span></a>';
	}

	/**
	 * Persona name as GhostStat stores it → translated name.
	 *
	 * @param string $l Label.
	 * @return string
	 */
	public static function label_persona( $l ) {
		$map = array(
			'High-intent buyer'   => __( 'High-intent buyer', 'ghoststat-analytics' ),
			'Researcher'          => __( 'Researcher', 'ghoststat-analytics' ),
			'Skimmer'             => __( 'Skimmer', 'ghoststat-analytics' ),
			'Casual browser'      => __( 'Casual browser', 'ghoststat-analytics' ),
			'Frustrated visitor'  => __( 'Frustrated visitor', 'ghoststat-analytics' ),
			'Lost visitor'        => __( 'Lost visitor', 'ghoststat-analytics' ),
			'Hesitator'           => __( 'Hesitator', 'ghoststat-analytics' ),
			'Comparison shopper'  => __( 'Comparison shopper', 'ghoststat-analytics' ),
		);
		return isset( $map[ $l ] ) ? $map[ $l ] : $l;
	}

	/**
	 * Journey stage as GhostStat stores it → translated name.
	 *
	 * @param string $l Label.
	 * @return string
	 */
	public static function label_journey( $l ) {
		$map = array(
			'Exploration' => __( 'Exploring', 'ghoststat-analytics' ),
			'Evaluation'  => __( 'Evaluating', 'ghoststat-analytics' ),
			'Comparison'  => __( 'Comparing', 'ghoststat-analytics' ),
			'Commitment'  => __( 'Ready to act', 'ghoststat-analytics' ),
			'Confusion'   => __( 'Confused', 'ghoststat-analytics' ),
			'Frustration' => __( 'Frustrated', 'ghoststat-analytics' ),
		);
		return isset( $map[ $l ] ) ? $map[ $l ] : $l;
	}

	/**
	 * One score card: number out of 100, a meter, and the change against the period before.
	 *
	 * @param string   $key   intent | frustration | attention.
	 * @param string   $title Card title.
	 * @param string   $what  One line: what the score means.
	 * @param int|null $now   Score this period.
	 * @param int|null $prev  Score the period before.
	 * @return string
	 */
	private static function card( $key, $title, $what, $now, $prev ) {
		$up_is_good = 'frustration' !== $key;
		$out        = '<div class="ghoststat-score gs-' . esc_attr( $key ) . '">';
		$out       .= '<div class="k">' . esc_html( $title ) . '</div>';
		if ( null === $now ) {
			$out .= '<div class="v">—</div><div class="ghoststat-meter" aria-hidden="true"><span style="width:0%"></span></div>';
			$out .= '<div class="ghoststat-delta">' . esc_html__( 'No scored visits in this period yet.', 'ghoststat-analytics' ) . '</div>';
		} else {
			$out .= '<div class="v">' . esc_html( number_format_i18n( (int) $now ) ) . '<span class="of">/100</span></div>';
			$out .= '<div class="ghoststat-meter" role="img" aria-label="' . esc_attr(
				sprintf(
					/* translators: 1: score name, 2: score */
					__( '%1$s %2$s out of 100', 'ghoststat-analytics' ),
					$title,
					number_format_i18n( (int) $now )
				)
			) . '"><span style="width:' . esc_attr( max( 0, min( 100, (int) $now ) ) ) . '%"></span></div>';
			if ( null === $prev ) {
				$out .= '<div class="ghoststat-delta">' . esc_html__( 'No earlier period to compare with yet.', 'ghoststat-analytics' ) . '</div>';
			} else {
				$diff = (int) $now - (int) $prev;
				$cls  = 0 === $diff ? 'flat' : ( ( $diff > 0 ) === $up_is_good ? 'good' : 'bad' );
				$sign = $diff > 0 ? '+' : ( $diff < 0 ? '−' : '±' );
				$pts  = abs( $diff );
				/* translators: %s: change in points with sign, e.g. +4 */
				$text = sprintf( _n( '%s point against the period before', '%s points against the period before', $pts, 'ghoststat-analytics' ), $sign . number_format_i18n( $pts ) );
				$out .= '<div class="ghoststat-delta ' . esc_attr( $cls ) . '">' . esc_html( $text ) . '</div>';
			}
		}
		$out .= '<div class="ghoststat-what">' . esc_html( $what ) . '</div>';
		return $out . '</div>';
	}

	/**
	 * The section. Shows nothing when the report is not available (an older GhostStat server).
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 */
	public static function render( $from, $to ) {
		$d = GhostStat_Api::get( 'intel', $from, $to );
		if ( is_wp_error( $d ) && 'ghoststat_unknown_stat' === $d->get_error_code() ) {
			return;
		}
		$vi = self::app_url( '/visitor-intelligence' );

		echo '<section class="ghoststat-intel" aria-labelledby="ghoststat-intel-h">';
		echo '<div class="ghoststat-intel-head"><div><span class="ghoststat-eyebrow">' . esc_html__( 'Visitor Intelligence', 'ghoststat-analytics' ) . '</span>';
		echo '<h2 id="ghoststat-intel-h">' . esc_html__( 'Why visitors buy, and why they leave', 'ghoststat-analytics' ) . '</h2>';
		echo '<p>' . esc_html__( 'GhostStat reads behaviour, not cookies, and scores every visit for intent, frustration and attention.', 'ghoststat-analytics' ) . '</p></div>';
		echo self::why( $vi, __( 'Open Visitor Intelligence', 'ghoststat-analytics' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_url/esc_html above.
		echo '</div>';

		if ( is_wp_error( $d ) ) {
			echo '<div class="ghoststat-panel"><p class="ghoststat-empty">' . esc_html( $d->get_error_message() ) . '</p></div></section>';
			return;
		}
		$tr = (array) ( $d['triad'] ?? array() );
		$pv = (array) ( $d['previous'] ?? array() );
		$g  = function ( $a, $k ) {
			return isset( $a[ $k ] ) && null !== $a[ $k ] ? (int) $a[ $k ] : null;
		};

		// 1. the three scores
		$cards  = self::card( 'intent', __( 'Intent', 'ghoststat-analytics' ), __( 'How much visitors want what you offer.', 'ghoststat-analytics' ), $g( $tr, 'intent' ), $g( $pv, 'intent' ) );
		$cards .= self::card( 'frustration', __( 'Frustration', 'ghoststat-analytics' ), __( 'How hard your site made it. Lower is better.', 'ghoststat-analytics' ), $g( $tr, 'frustration' ), $g( $pv, 'frustration' ) );
		$cards .= self::card( 'attention', __( 'Attention', 'ghoststat-analytics' ), __( 'How much of your pages people really read.', 'ghoststat-analytics' ), $g( $tr, 'attention' ), $g( $pv, 'attention' ) );
		echo '<div class="ghoststat-scores">' . wp_kses( $cards, GhostStat_Charts::allowed() ) . '</div>';
		$scored = (int) ( $tr['scored'] ?? 0 );
		/* translators: %s: number of visits */
		$based = sprintf( _n( 'Based on %s scored visit in this period.', 'Based on %s scored visits in this period.', $scored, 'ghoststat-analytics' ), number_format_i18n( $scored ) );
		echo '<p class="ghoststat-scored">' . esc_html( $based ) . ' ' . self::why( $vi ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- why() escapes.

		// 2. the trend
		echo '<div class="ghoststat-panel"><div class="ghoststat-panel-h"><h2>' . esc_html__( 'Intent, frustration and attention over time', 'ghoststat-analytics' ) . '</h2>';
		echo '<span class="ghoststat-legend"><span class="sw gs-intent"></span>' . esc_html__( 'Intent', 'ghoststat-analytics' ) . '<span class="sw gs-frustration"></span>' . esc_html__( 'Frustration', 'ghoststat-analytics' ) . '<span class="sw gs-attention"></span>' . esc_html__( 'Attention', 'ghoststat-analytics' ) . '</span></div>';
		$series = (array) ( $d['series'] ?? array() );
		$any    = false;
		foreach ( $series as $p ) {
			if ( ! empty( $p['scored'] ) ) {
				$any = true;
				break;
			}
		}
		if ( $any ) {
			GhostStat_Charts::out( GhostStat_Charts::triad( $series ) );
		} else {
			echo '<p class="ghoststat-empty">' . esc_html__( 'No scored visits in this period yet.', 'ghoststat-analytics' ) . '</p>';
		}
		echo '<div class="ghoststat-panel-f">' . self::why( $vi, __( 'See the visits behind a change in GhostStat', 'ghoststat-analytics' ) ) . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- why() escapes.

		// 3. who and where
		echo '<div class="ghoststat-intel-grid">';
		echo '<div class="ghoststat-panel"><div class="ghoststat-panel-h"><h2>' . esc_html__( 'Who your visitors were', 'ghoststat-analytics' ) . '</h2><span class="ghoststat-col">' . esc_html__( 'Share of visits', 'ghoststat-analytics' ) . '</span></div>';
		GhostStat_Charts::out( GhostStat_Charts::shares( (array) ( $d['personas'] ?? array() ), array( __CLASS__, 'label_persona' ), 'personas' ) );
		echo '<div class="ghoststat-panel-f">' . self::why( $vi ) . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- why() escapes.
		echo '<div class="ghoststat-panel"><div class="ghoststat-panel-h"><h2>' . esc_html__( 'Where they were in their journey', 'ghoststat-analytics' ) . '</h2><span class="ghoststat-col">' . esc_html__( 'Share of visits', 'ghoststat-analytics' ) . '</span></div>';
		GhostStat_Charts::out( GhostStat_Charts::shares( (array) ( $d['journey'] ?? array() ), array( __CLASS__, 'label_journey' ), 'journey' ) );
		echo '<div class="ghoststat-panel-f">' . self::why( $vi ) . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- why() escapes.
		echo '</div>';

		// 4. pages where visitors struggled
		echo '<div class="ghoststat-panel ghoststat-struggle"><div class="ghoststat-panel-h"><h2>' . esc_html__( 'Pages where visitors struggle most', 'ghoststat-analytics' ) . '</h2><span class="ghoststat-col">' . esc_html__( 'Frustration · visits', 'ghoststat-analytics' ) . '</span></div>';
		$pages = (array) ( $d['pages'] ?? array() );
		if ( ! $pages ) {
			echo '<p class="ghoststat-empty">' . esc_html__( 'No page stands out: visitors are not struggling anywhere in particular.', 'ghoststat-analytics' ) . '</p>';
		} else {
			echo '<ol class="ghoststat-pagelist">';
			foreach ( $pages as $p ) {
				$f = max( 0, min( 100, (int) $p['frustration'] ) );
				echo '<li><span class="ghoststat-path" title="' . esc_attr( (string) $p['path'] ) . '">' . esc_html( (string) $p['path'] ) . '</span>';
				echo '<span class="ghoststat-mini" aria-hidden="true"><span style="width:' . esc_attr( $f ) . '%"></span></span>';
				echo '<span class="ghoststat-fnum">' . esc_html( number_format_i18n( $f ) ) . '</span>';
				echo '<span class="ghoststat-visits">' . esc_html( number_format_i18n( (int) $p['visits'] ) ) . '</span>';
				echo self::why( self::app_url( '/ux-auditor', array( 'page' => (string) $p['path'] ) ), __( 'See why', 'ghoststat-analytics' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- why() escapes.
				echo '</li>';
			}
			echo '</ol>';
		}
		echo '</div>';
		echo '</section>';
	}

	/**
	 * Band at the bottom of the statistics page: what else the GhostStat app shows.
	 */
	public static function band() {
		$items = array(
			array( 'heatmaps', __( 'Heatmaps', 'ghoststat-analytics' ), __( 'What people click, read and skip, on your real pages.', 'ghoststat-analytics' ), '/heatmaps' ),
			array( 'recordings', __( 'Session recordings', 'ghoststat-analytics' ), __( 'Watch where a visit went wrong. Every input is masked.', 'ghoststat-analytics' ), '/session-recordings' ),
			array( 'funnels', __( 'Funnels', 'ghoststat-analytics' ), __( 'Find the step that costs you sales, and who left there.', 'ghoststat-analytics' ), '/funnels' ),
			array( 'software', __( 'Software Development', 'ghoststat-analytics' ), __( 'Every plugin and theme update, drawn on your traffic.', 'ghoststat-analytics' ), '/software-development' ),
		);
		echo '<section class="ghoststat-band" aria-labelledby="ghoststat-band-h"><div class="ghoststat-band-text">';
		echo '<span class="ghoststat-eyebrow">' . esc_html__( 'More in GhostStat', 'ghoststat-analytics' ) . '</span>';
		echo '<h2 id="ghoststat-band-h">' . esc_html__( 'One visitor. Every signal. One place.', 'ghoststat-analytics' ) . '</h2>';
		echo '<p>' . esc_html__( 'This page shows the essentials. In GhostStat every click, scroll, path and purchase lands on the same visitor, so you read a story instead of a spreadsheet.', 'ghoststat-analytics' ) . '</p>';
		echo '<a class="button button-primary" href="' . esc_url( self::app_url( '/dashboard' ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open GhostStat', 'ghoststat-analytics' ) . '</a>';
		echo '</div><ul class="ghoststat-band-list">';
		foreach ( $items as $it ) {
			echo '<li class="' . esc_attr( $it[0] ) . '"><a href="' . esc_url( self::app_url( $it[3] ) ) . '" target="_blank" rel="noopener noreferrer"><strong>' . esc_html( $it[1] ) . '</strong><span>' . esc_html( $it[2] ) . '</span></a></li>';
		}
		echo '<li class="guardian"><a href="' . esc_url( 'https://ghoststat.me/#guardian' ) . '" target="_blank" rel="noopener noreferrer"><strong>' . esc_html__( 'GhostStat Guardian app', 'ghoststat-analytics' ) . '</strong><span>' . esc_html__( 'What broke, what it costs and what to do next, on your phone.', 'ghoststat-analytics' ) . '</span></a></li>';
		echo '</ul></section>';
	}
}
