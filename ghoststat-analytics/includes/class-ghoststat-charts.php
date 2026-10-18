<?php
/**
 * Charts drawn as inline SVG on the server: no chart library and nothing loaded from elsewhere.
 * Every value is escaped where it is put in; output goes through wp_kses with an SVG allow-list.
 *
 * @package GhostStat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Line chart, sparkline and bar list.
 */
class GhostStat_Charts {

	/**
	 * Tags and attributes the charts use.
	 *
	 * @return array
	 */
	public static function allowed() {
		$common = array( 'class' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'opacity' => true, 'stroke-linejoin' => true, 'stroke-linecap' => true, 'vector-effect' => true, 'stroke-dasharray' => true );
		return array(
			'svg'   => $common + array( 'viewbox' => true, 'preserveaspectratio' => true, 'role' => true, 'aria-label' => true, 'aria-hidden' => true, 'focusable' => true, 'width' => true, 'height' => true, 'xmlns' => true ),
			'g'     => $common,
			'path'  => $common + array( 'd' => true ),
			'line'  => $common + array( 'x1' => true, 'x2' => true, 'y1' => true, 'y2' => true ),
			'rect'  => $common + array( 'x' => true, 'y' => true, 'width' => true, 'height' => true, 'rx' => true ),
			'circle' => $common + array( 'cx' => true, 'cy' => true, 'r' => true ),
			'text'  => $common + array( 'x' => true, 'y' => true, 'text-anchor' => true, 'font-size' => true, 'dominant-baseline' => true ),
			'title' => array(),
			'div'   => array( 'class' => true, 'style' => true, 'title' => true, 'role' => true, 'aria-label' => true, 'aria-hidden' => true ),
			'span'  => array( 'class' => true, 'style' => true, 'title' => true ),
			'a'     => array( 'href' => true, 'class' => true, 'target' => true, 'rel' => true ),
			'p'     => array( 'class' => true ),
			'strong' => array(),
		);
	}

	/**
	 * Print chart markup.
	 *
	 * @param string $markup Built by this class.
	 */
	public static function out( $markup ) {
		echo wp_kses( $markup, self::allowed() );
	}

	/**
	 * A "nice" upper bound for the y axis: 1, 2 or 5 times a power of ten.
	 *
	 * @param float $max Largest value.
	 * @return float
	 */
	private static function nice( $max ) {
		if ( $max <= 0 ) {
			return 4;
		}
		$exp  = pow( 10, floor( log10( $max ) ) );
		$step = $max / $exp;
		$n    = $step <= 1 ? 1 : ( $step <= 2 ? 2 : ( $step <= 5 ? 5 : 10 ) );
		return $n * $exp;
	}

	/**
	 * Short number: 1234 → 1.2K.
	 *
	 * @param float $n Number.
	 * @return string
	 */
	public static function short( $n ) {
		$n = (float) $n;
		if ( $n >= 1000000 ) {
			return number_format_i18n( $n / 1000000, 1 ) . 'M';
		}
		if ( $n >= 10000 ) {
			return number_format_i18n( $n / 1000, 0 ) . 'K';
		}
		if ( $n >= 1000 ) {
			return number_format_i18n( $n / 1000, 1 ) . 'K';
		}
		return number_format_i18n( $n );
	}

	/**
	 * Visitors and pageviews per day.
	 *
	 * @param array $series Rows {d, pv, sess, vi} from /stats/timeline.
	 * @return string
	 */
	public static function timeline( $series ) {
		$w   = 1000;
		$h   = 280;
		$l   = 64;
		$r   = 10;
		$t   = 12;
		$b   = 28;
		$n   = count( $series );
		if ( $n < 1 ) {
			return '';
		}
		$max = 0;
		foreach ( $series as $p ) {
			$max = max( $max, (int) $p['pv'], (int) $p['vi'] );
		}
		$top = self::nice( $max );
		$cw  = $w - $l - $r;
		$ch  = $h - $t - $b;
		$x   = function ( $i ) use ( $n, $l, $cw ) {
			return $n > 1 ? $l + $cw * $i / ( $n - 1 ) : $l + $cw / 2;
		};
		$y   = function ( $v ) use ( $top, $t, $ch ) {
			return $t + $ch - $ch * $v / $top;
		};

		$svg = '<svg class="ghoststat-chart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="' . esc_attr__( 'Visitors and pageviews per day', 'ghoststat-analytics' ) . '">';
		for ( $g = 0; $g <= 4; $g++ ) {
			$gy   = $t + $ch * $g / 4;
			$svg .= '<line class="grid" x1="' . $l . '" x2="' . ( $w - $r ) . '" y1="' . esc_attr( round( $gy, 1 ) ) . '" y2="' . esc_attr( round( $gy, 1 ) ) . '"/>';
			$svg .= '<text class="axis" x="' . ( $l - 8 ) . '" y="' . esc_attr( round( $gy, 1 ) ) . '" text-anchor="end" dominant-baseline="middle">' . esc_html( self::short( $top * ( 4 - $g ) / 4 ) ) . '</text>';
		}

		foreach ( array( 'pv' => 'pageviews', 'vi' => 'visitors' ) as $k => $cls ) {
			$d = '';
			foreach ( $series as $i => $p ) {
				$d .= ( 0 === $i ? 'M' : 'L' ) . round( $x( $i ), 1 ) . ' ' . round( $y( (int) $p[ $k ] ), 1 );
			}
			if ( 'vi' === $k ) {
				$area = $d . 'L' . round( $x( $n - 1 ), 1 ) . ' ' . ( $t + $ch ) . 'L' . round( $x( 0 ), 1 ) . ' ' . ( $t + $ch ) . 'Z';
				$svg .= '<path class="area" d="' . esc_attr( $area ) . '"/>';
			}
			$svg .= '<path class="line ' . esc_attr( $cls ) . '" d="' . esc_attr( $d ) . '" vector-effect="non-scaling-stroke"/>';
		}

		// x labels: about six, always the first and last day.
		$every = max( 1, (int) ceil( $n / 6 ) );
		foreach ( $series as $i => $p ) {
			if ( 0 !== $i % $every && $i !== $n - 1 ) {
				continue;
			}
			if ( $i !== $n - 1 && $n - 1 - $i < $every / 2 ) {
				continue; // too close to the last label
			}
			$anchor = 0 === $i ? 'start' : ( $n - 1 === $i ? 'end' : 'middle' );
			$svg   .= '<text class="axis" x="' . esc_attr( round( $x( $i ), 1 ) ) . '" y="' . ( $h - 8 ) . '" text-anchor="' . $anchor . '">' . esc_html( date_i18n( 'j M', strtotime( $p['d'] . ' 12:00:00' ) ) ) . '</text>';
		}

		// one invisible column per day carries the hover text
		$col = $n > 1 ? $cw / ( $n - 1 ) : $cw;
		foreach ( $series as $i => $p ) {
			$tip  = sprintf(
				/* translators: 1: date, 2: number of visitors, 3: number of pageviews */
				__( '%1$s: %2$s visitors, %3$s pageviews', 'ghoststat-analytics' ),
				date_i18n( get_option( 'date_format' ), strtotime( $p['d'] . ' 12:00:00' ) ),
				number_format_i18n( (int) $p['vi'] ),
				number_format_i18n( (int) $p['pv'] )
			);
			$svg .= '<rect class="hit" x="' . esc_attr( round( max( $l, $x( $i ) - $col / 2 ), 1 ) ) . '" y="' . $t . '" width="' . esc_attr( round( $col, 1 ) ) . '" height="' . $ch . '"><title>' . esc_html( $tip ) . '</title></rect>';
		}
		$svg .= '</svg>';
		return $svg;
	}

	/**
	 * Small trend line for the dashboard widget.
	 *
	 * @param int[] $values Values per day.
	 * @return string
	 */
	public static function sparkline( $values ) {
		$n = count( $values );
		if ( $n < 2 ) {
			return '';
		}
		$w   = 300;
		$h   = 48;
		$max = max( 1, max( $values ) );
		$d   = '';
		foreach ( array_values( $values ) as $i => $v ) {
			$d .= ( 0 === $i ? 'M' : 'L' ) . round( $w * $i / ( $n - 1 ), 1 ) . ' ' . round( $h - 4 - ( $h - 8 ) * $v / $max, 1 );
		}
		$area = $d . 'L' . $w . ' ' . $h . 'L0 ' . $h . 'Z';
		return '<svg class="ghoststat-spark" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" aria-hidden="true" focusable="false">'
			. '<path class="area" d="' . esc_attr( $area ) . '"/><path class="line visitors" d="' . esc_attr( $d ) . '" vector-effect="non-scaling-stroke"/></svg>';
	}

	/**
	 * Bar list: label, bar, number.
	 *
	 * @param array    $rows     Rows {label, sessions, hits}.
	 * @param int      $limit    Rows to show.
	 * @param callable $labeler  Optional label formatter (returns plain text).
	 * @return string
	 */
	public static function bars( $rows, $limit = 10, $labeler = null ) {
		$rows = array_values( (array) $rows );
		// The API orders by hits; the list shows visits, so order by what is shown.
		usort(
			$rows,
			function ( $a, $b ) {
				return (int) $b['sessions'] - (int) $a['sessions'];
			}
		);
		$rows = array_slice( $rows, 0, $limit );
		if ( ! $rows ) {
			return '<p class="ghoststat-empty">' . esc_html__( 'No data for this period yet.', 'ghoststat-analytics' ) . '</p>';
		}
		$max = 1;
		foreach ( $rows as $r ) {
			$max = max( $max, (int) $r['sessions'] );
		}
		$out = '<div class="ghoststat-bars">';
		foreach ( $rows as $r ) {
			$label = $labeler ? call_user_func( $labeler, (string) $r['label'] ) : (string) $r['label'];
			$pct   = round( 100 * (int) $r['sessions'] / $max, 1 );
			$out  .= '<div class="row" title="' . esc_attr( (string) $r['label'] ) . '">'
				. '<span class="fill" style="width:' . esc_attr( $pct ) . '%"></span>'
				. '<span class="label">' . esc_html( $label ) . '</span>'
				. '<span class="num">' . esc_html( number_format_i18n( (int) $r['sessions'] ) ) . '</span></div>';
		}
		$out .= '</div>';
		return $out;
	}

	/**
	 * Intent, frustration and attention per day (0–100). Days without scored visits leave a gap.
	 *
	 * @param array $series Rows {d, intent, frustration, attention, scored} from /stats/intel.
	 * @return string
	 */
	public static function triad( $series ) {
		$w = 1000;
		$h = 240;
		$l = 64; // same as the visitors chart: room for "100" at the phone's larger axis font
		$r = 10;
		$t = 12;
		$b = 40; // keeps the dates clear of the 0 line on a phone
		$n = count( $series );
		if ( $n < 1 ) {
			return '';
		}
		$cw = $w - $l - $r;
		$ch = $h - $t - $b;
		$x  = function ( $i ) use ( $n, $l, $cw ) {
			return $n > 1 ? $l + $cw * $i / ( $n - 1 ) : $l + $cw / 2;
		};
		$y  = function ( $v ) use ( $t, $ch ) {
			return $t + $ch - $ch * max( 0, min( 100, (float) $v ) ) / 100;
		};

		$svg = '<svg class="ghoststat-triad" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="' . esc_attr__( 'Intent, frustration and attention per day', 'ghoststat-analytics' ) . '">';
		foreach ( array( 0, 25, 50, 75, 100 ) as $g ) {
			$gy   = round( $y( $g ), 1 );
			$svg .= '<line class="grid" x1="' . $l . '" x2="' . ( $w - $r ) . '" y1="' . esc_attr( $gy ) . '" y2="' . esc_attr( $gy ) . '"/>';
			$svg .= '<text class="axis" x="' . ( $l - 8 ) . '" y="' . esc_attr( $gy ) . '" text-anchor="end" dominant-baseline="middle">' . esc_html( number_format_i18n( $g ) ) . '</text>';
		}
		foreach ( array( 'intent', 'frustration', 'attention' ) as $k ) {
			$d    = '';
			$move = true;
			foreach ( $series as $i => $p ) {
				if ( null === $p[ $k ] || '' === $p[ $k ] ) {
					$move = true; // a day without scored visits: break the line
					continue;
				}
				$d   .= ( $move ? 'M' : 'L' ) . round( $x( $i ), 1 ) . ' ' . round( $y( $p[ $k ] ), 1 );
				$move = false;
			}
			if ( '' !== $d ) {
				$svg .= '<path class="line gs-' . esc_attr( $k ) . '" d="' . esc_attr( $d ) . '" vector-effect="non-scaling-stroke"/>';
			}
			foreach ( $series as $i => $p ) {
				if ( null !== $p[ $k ] && '' !== $p[ $k ] && $n <= 45 ) {
					$svg .= '<circle class="dot gs-' . esc_attr( $k ) . '" cx="' . esc_attr( round( $x( $i ), 1 ) ) . '" cy="' . esc_attr( round( $y( $p[ $k ] ), 1 ) ) . '" r="3"/>';
				}
			}
		}
		$every = max( 1, (int) ceil( $n / 6 ) );
		foreach ( $series as $i => $p ) {
			if ( ( 0 !== $i % $every && $i !== $n - 1 ) || ( $i !== $n - 1 && $n - 1 - $i < $every / 2 ) ) {
				continue;
			}
			$anchor = 0 === $i ? 'start' : ( $n - 1 === $i ? 'end' : 'middle' );
			$svg   .= '<text class="axis" x="' . esc_attr( round( $x( $i ), 1 ) ) . '" y="' . ( $h - 8 ) . '" text-anchor="' . $anchor . '">' . esc_html( date_i18n( 'j M', strtotime( $p['d'] . ' 12:00:00' ) ) ) . '</text>';
		}
		$col = $n > 1 ? $cw / ( $n - 1 ) : $cw;
		foreach ( $series as $i => $p ) {
			$day = date_i18n( get_option( 'date_format' ), strtotime( $p['d'] . ' 12:00:00' ) );
			if ( empty( $p['scored'] ) ) {
				/* translators: %s: date */
				$tip = sprintf( __( '%s: no scored visits', 'ghoststat-analytics' ), $day );
			} else {
				$tip = sprintf(
					/* translators: 1: date, 2: intent score, 3: frustration score, 4: attention score, 5: number of visits */
					__( '%1$s: intent %2$s, frustration %3$s, attention %4$s (%5$s visits)', 'ghoststat-analytics' ),
					$day,
					number_format_i18n( (int) $p['intent'] ),
					number_format_i18n( (int) $p['frustration'] ),
					number_format_i18n( (int) $p['attention'] ),
					number_format_i18n( (int) $p['scored'] )
				);
			}
			$svg .= '<rect class="hit" x="' . esc_attr( round( max( $l, $x( $i ) - $col / 2 ), 1 ) ) . '" y="' . $t . '" width="' . esc_attr( round( $col, 1 ) ) . '" height="' . $ch . '"><title>' . esc_html( $tip ) . '</title></rect>';
		}
		return $svg . '</svg>';
	}

	/**
	 * Share bars: label, bar, percentage (personas, journey stages).
	 *
	 * @param array    $rows    Rows {label, visits, share}.
	 * @param callable $labeler Label formatter (plain text).
	 * @param string   $cls     Extra class for the colour.
	 * @return string
	 */
	public static function shares( $rows, $labeler = null, $cls = '' ) {
		$rows = array_values( (array) $rows );
		if ( ! $rows ) {
			return '<p class="ghoststat-empty">' . esc_html__( 'No scored visits in this period yet.', 'ghoststat-analytics' ) . '</p>';
		}
		$out = '<div class="ghoststat-shares ' . esc_attr( $cls ) . '">';
		foreach ( $rows as $r ) {
			$label = $labeler ? call_user_func( $labeler, (string) $r['label'] ) : (string) $r['label'];
			$pct   = max( 0, min( 100, (float) $r['share'] ) );
			$out  .= '<div class="row" title="' . esc_attr(
				sprintf(
					/* translators: 1: label, 2: number of visits */
					__( '%1$s: %2$s visits', 'ghoststat-analytics' ),
					$label,
					number_format_i18n( (int) $r['visits'] )
				)
			) . '">'
				. '<span class="fill" style="width:' . esc_attr( $pct ) . '%"></span>'
				. '<span class="label">' . esc_html( $label ) . '</span>'
				. '<span class="num">' . esc_html( number_format_i18n( $pct, $pct < 10 ? 1 : 0 ) ) . '%</span></div>';
		}
		return $out . '</div>';
	}
}
