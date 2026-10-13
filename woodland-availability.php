<?php
/**
 * Plugin Name:  Woodland Cottages — availability feed
 * Description:  Reads the iCal calendars from Airbnb (and any other channel)
 *               and serves the blocked dates as JSON, so the booking form on
 *               the website can refuse dates that are already taken.
 * Version:      1.0
 *
 * WHERE THIS GOES
 *   wp-content/mu-plugins/woodland-availability.php
 *   "mu" means must-use: WordPress loads it automatically. There is nothing
 *   to activate, and it cannot be switched off by accident from the plugins
 *   screen. If the mu-plugins folder does not exist, create it.
 *
 * WHY IT HAS TO BE SERVER-SIDE
 *   Airbnb's .ics endpoint sends no CORS headers, so a browser on
 *   woodlandcottage.pk is not allowed to read it directly — the request is
 *   made and then thrown away by the browser. WordPress has no such limit,
 *   so it fetches the file, caches it, and hands the website a clean answer.
 *
 * WHAT IT EXPOSES
 *   GET /wp-json/woodland/v1/availability
 *   → {"updated":"2026-09-22T10:00:00+00:00",
 *      "cottages":{"blue":["2026-10-03","2026-10-04"],"green":[]},
 *      "stale":false}
 *   Dates are the NIGHTS that are taken. A guest checking out on the 5th does
 *   not occupy the night of the 5th, so that date is not in the list.
 *
 * PRIVACY
 *   Only dates leave this endpoint. Guest names, prices and reservation codes
 *   are stripped out and never reach the website.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ┌───────────────────────────────────────────────────────────────────────┐
   │  YOUR CALENDAR LINKS                                                  │
   │                                                                       │
   │  Airbnb:  Calendar → pick the listing → Availability →                │
   │           "Connect calendars" → "Connect to another website" →        │
   │           copy the export link. It ends in .ics                       │
   │                                                                       │
   │  You can list more than one feed per cottage — Airbnb, Booking.com,   │
   │  Smoobu, anything that gives you an .ics link. They get merged, so a  │
   │  night blocked anywhere is blocked here.                              │
   │                                                                       │
   │  Treat these links as private. Anyone holding one can read your       │
   │  calendar. If one leaks, regenerate it on Airbnb.                     │
   └───────────────────────────────────────────────────────────────────────┘ */
const WC_FEEDS = array(
	'blue'  => array(
		// 'https://www.airbnb.com/calendar/ical/XXXXXXXX.ics?s=...',
	),
	'green' => array(
		// 'https://www.airbnb.com/calendar/ical/YYYYYYYY.ics?s=...',
	),
);

const WC_CACHE_KEY = 'woodland_availability_v1';
const WC_CACHE_TTL = 1800;   // 30 minutes. Airbnb itself only refreshes every 3 hours.

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'woodland/v1',
			'/availability',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => 'wc_availability_response',
			)
		);
	}
);

function wc_availability_response( WP_REST_Request $request ) {
	// ?refresh=1 skips the cache, for when you have just changed something.
	if ( ! $request->get_param( 'refresh' ) ) {
		$cached = get_transient( WC_CACHE_KEY );
		if ( is_array( $cached ) ) {
			return rest_ensure_response( $cached );
		}
	}

	$out    = array();
	$failed = false;

	foreach ( WC_FEEDS as $cottage => $urls ) {
		$dates = array();
		foreach ( $urls as $url ) {
			$ics = wc_fetch_ics( $url );
			if ( null === $ics ) {
				$failed = true;
				continue;
			}
			$dates = array_merge( $dates, wc_ics_blocked_nights( $ics ) );
		}
		$dates = array_values( array_unique( $dates ) );
		sort( $dates );
		$out[ $cottage ] = $dates;
	}

	$payload = array(
		'updated'  => gmdate( 'c' ),
		'cottages' => $out,
		// stale = at least one feed did not answer, so treat this as incomplete
		'stale'    => $failed,
	);

	// Cache a good answer for the full window, a partial one only briefly.
	set_transient( WC_CACHE_KEY, $payload, $failed ? 300 : WC_CACHE_TTL );

	return rest_ensure_response( $payload );
}

function wc_fetch_ics( $url ) {
	$res = wp_remote_get(
		$url,
		array(
			'timeout'    => 12,
			'user-agent' => 'WoodlandCottages/1.0 (+https://woodlandcottage.pk)',
		)
	);
	if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ) {
		return null;
	}
	$body = wp_remote_retrieve_body( $res );
	return ( false !== strpos( $body, 'BEGIN:VCALENDAR' ) ) ? $body : null;
}

/**
 * Pull the occupied nights out of an iCal file.
 *
 * Two things about the format matter here:
 *  - Long lines are folded: a continuation starts with a space or a tab and
 *    belongs to the line above. Unfold before parsing or dates get truncated.
 *  - For all-day events DTEND is EXCLUSIVE. A booking of DTSTART 3rd,
 *    DTEND 5th occupies the nights of the 3rd and the 4th — the guest leaves
 *    on the morning of the 5th and that night is free. Getting this wrong
 *    blocks one extra night on every single booking.
 */
function wc_ics_blocked_nights( $ics ) {
	$ics   = preg_replace( "/\r\n[ \t]/", '', str_replace( "\n", "\r\n", str_replace( "\r\n", "\n", $ics ) ) );
	$lines = preg_split( "/\r\n|\n/", $ics );

	$nights = array();
	$start  = null;
	$end    = null;
	$inside = false;

	foreach ( $lines as $line ) {
		if ( 0 === strpos( $line, 'BEGIN:VEVENT' ) ) {
			$inside = true;
			$start  = null;
			$end    = null;
			continue;
		}
		if ( 0 === strpos( $line, 'END:VEVENT' ) ) {
			if ( $start && $end ) {
				$cur = strtotime( $start . ' 00:00:00 UTC' );
				$fin = strtotime( $end . ' 00:00:00 UTC' );
				// hard stop so a malformed feed can never spin forever
				$guard = 0;
				while ( $cur < $fin && $guard < 1000 ) {
					$nights[] = gmdate( 'Y-m-d', $cur );
					$cur      += DAY_IN_SECONDS;
					$guard++;
				}
			}
			$inside = false;
			continue;
		}
		if ( ! $inside ) {
			continue;
		}
		if ( preg_match( '/^DTSTART[^:]*:(\d{8})/', $line, $m ) ) {
			$start = $m[1];
		} elseif ( preg_match( '/^DTEND[^:]*:(\d{8})/', $line, $m ) ) {
			$end = $m[1];
		}
	}

	return array_map(
		function ( $d ) {
			return substr( $d, 0, 4 ) . '-' . substr( $d, 4, 2 ) . '-' . substr( $d, 6, 2 );
		},
		array_map(
			function ( $d ) {
				return str_replace( '-', '', $d );
			},
			$nights
		)
	);
}
