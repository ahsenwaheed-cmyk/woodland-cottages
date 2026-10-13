<?php
/**
 * Plugin Name:  Woodland Cottages — booking requests
 * Description:  Keeps every booking request from the website as a record with a
 *               status, puts Approve / Decline buttons in the notification
 *               email, and sends the guest the right reply when you press one.
 * Version:      1.0
 *
 * WHERE THIS GOES
 *   wp-content/mu-plugins/woodland-bookings.php
 *   "mu" means must-use: WordPress loads it automatically. There is nothing to
 *   activate and it cannot be switched off by accident from the plugins screen.
 *   If the mu-plugins folder does not exist, create it.
 *
 * WHAT IT DOES NOT DO
 *   It does not touch how the form is received. Contact Form 7 still takes the
 *   submission and still sends both emails exactly as it does today. If every
 *   line of this file were deleted the form would carry on working. That is
 *   deliberate: the booking path is live and should not depend on this.
 *
 * THE ONE THING WORTH UNDERSTANDING
 *   The Approve and Decline links in your email do NOT act when they are
 *   opened. They open a page with a button on it, and the page is what acts.
 *   This is not politeness — Gmail, Outlook and most corporate mail scanners
 *   fetch every link in a message before you ever see it, to check it is safe.
 *   A link that approved a booking by being opened would approve every booking
 *   by itself, silently, within seconds of the email arriving. So: link opens a
 *   page, page has a button, button sends the reply.
 *
 * ONE DEPENDENCY, WRITTEN DOWN SO IT IS NOT BROKEN BY ACCIDENT
 *   The Approve / Decline links are the site root with a query string:
 *   https://woodlandcottage.pk/?wc_booking=12&wc_do=confirm&wc_token=...
 *   There is a Cloudflare rule that rewrites the site root to /stay/index.html
 *   so the cottage site is the homepage. That rule MUST be written to match the
 *   root only when there is no query string:
 *       http.request.uri.path eq "/" and http.request.uri.query eq ""
 *   If it is ever simplified to just the path, every Approve link will quietly
 *   serve the homepage instead and no booking will ever be answerable again.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ═══════════════════════════════════════════════════════════════════════════
   EVERYTHING YOU NEED TO EDIT IS IN THIS BLOCK
   ═══════════════════════════════════════════════════════════════════════════ */

/** The numeric post ID of the booking form. Contact → Contact Forms → the
 *  form → look at the address bar: post.php?post=84 → 84. Not the hash. */
const WC_BK_FORM_ID = 84;

/** Where the notification goes, and who the guest sees replies from. The
 *  sender MUST be an address on woodlandcottage.pk — Brevo signs mail for
 *  your domain and will refuse to sign a Gmail or Yahoo sender. */
const WC_BK_OWNER = 'hello@woodlandcottage.pk';
const WC_BK_FROM  = 'Woodland Cottages <booking@woodlandcottage.pk>';
const WC_BK_REPLY = 'hello@woodlandcottage.pk';

const WC_BK_WHATSAPP = '0346 9663367';
const WC_BK_WA_INTL  = '923469663367';   // for wa.me links

/** Check-in and check-out, as the confirmation email states them. These match
 *  the house rules page — change them in both places or not at all. */
const WC_BK_CHECKIN  = '2:00 pm – 7:00 pm';
const WC_BK_CHECKOUT = '11:00 am';

/** How much you ask for up front to hold a booking, as a percentage.
 *  NOTE: the policies page does not currently say what this is. Decide it,
 *  put it on the policies page, and set it here — a confirmation email that
 *  asks for an advance the published policy never mentions is a dispute
 *  waiting to happen. Set to 0 to leave the advance out of the email entirely
 *  and settle it by WhatsApp. */
const WC_BK_ADVANCE_PCT = 50;

/** Your account, pasted into the confirmation email. Leave the placeholders
 *  in and the email simply says the details will follow on WhatsApp, which is
 *  a safe default — it will not email a guest a half-filled bank block. */
const WC_BK_BANK = <<<BANK
Account title   : [ACCOUNT TITLE]
Bank            : [BANK NAME]
Account / IBAN  : [IBAN]
BANK;

/* ═══════════════════════════════════════════════════════════════════════════
   Below here is machinery.
   ═══════════════════════════════════════════════════════════════════════════ */

const WC_BK_CPT = 'wc_booking';

/* ---------------------------------------------------------------------------
   1. Where the requests are kept
   ------------------------------------------------------------------------ */

add_action(
	'init',
	function () {
		register_post_type(
			WC_BK_CPT,
			array(
				'labels'          => array(
					'name'          => 'Bookings',
					'singular_name' => 'Booking',
					'menu_name'     => 'Bookings',
					'all_items'     => 'All requests',
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => true,
				'menu_icon'       => 'dashicons-calendar-alt',
				'menu_position'   => 26,
				'supports'        => array( 'title' ),
				'capability_type' => 'post',
				'map_meta_cap'    => true,
				'capabilities'    => array(
					// Records arrive from the website; nobody types one in by hand.
					'create_posts' => 'do_not_allow',
				),
			)
		);
	}
);

/**
 * The fields the website sends, in the order they should be read.
 * These names must match the FIELDS map in index.html and the tags in the
 * CF7 form. If an email arrives with blank lines, it is this list that is
 * out of step with one of those two.
 */
function wc_bk_fields() {
	return array(
		'cottage'   => 'Cottage',
		'checkin'   => 'Arriving',
		'checkout'  => 'Leaving',
		'nights'    => 'Nights',
		'guests'    => 'Guests',
		'your-name' => 'Name',
		'phone'     => 'WhatsApp',
		'your-email' => 'Email',
		'total'     => 'Estimate',
		'message'   => 'Notes',
	);
}

/* ---------------------------------------------------------------------------
   2. Catch the submission

   wpcf7_before_send_mail runs BEFORE either email is composed, which is what
   lets the Approve / Decline links exist in time to be put into the first one.
   ------------------------------------------------------------------------ */

add_action(
	'wpcf7_before_send_mail',
	function ( $contact_form ) {
		if ( (int) $contact_form->id() !== (int) WC_BK_FORM_ID ) {
			return;
		}

		$submission = class_exists( 'WPCF7_Submission' ) ? WPCF7_Submission::get_instance() : null;
		if ( ! $submission ) {
			return;
		}

		$posted = $submission->get_posted_data();
		if ( ! is_array( $posted ) ) {
			return;
		}

		$data = array();
		foreach ( array_keys( wc_bk_fields() ) as $key ) {
			$value = isset( $posted[ $key ] ) ? $posted[ $key ] : '';
			if ( is_array( $value ) ) {
				$value = implode( ', ', $value );
			}
			$data[ $key ] = sanitize_textarea_field( (string) $value );
		}

		$title = sprintf(
			'%s — %s — %s',
			$data['your-name'] ? $data['your-name'] : 'Unnamed guest',
			$data['cottage'] ? $data['cottage'] : 'Cottage not stated',
			$data['checkin'] ? $data['checkin'] : 'no date'
		);

		$post_id = wp_insert_post(
			array(
				'post_type'   => WC_BK_CPT,
				'post_status' => 'publish',
				'post_title'  => wp_strip_all_tags( $title ),
			),
			true
		);

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return;
		}

		foreach ( $data as $key => $value ) {
			update_post_meta( $post_id, '_wc_' . $key, $value );
		}
		update_post_meta( $post_id, '_wc_status', 'pending' );
		update_post_meta( $post_id, '_wc_token', wp_generate_password( 32, false, false ) );

		// Hand the id to the mail filter further down this same request.
		$GLOBALS['wc_bk_current'] = $post_id;
	},
	10,
	1
);

/* ---------------------------------------------------------------------------
   3. Put the buttons in the email you receive

   Defensive on purpose. If anything about this does not look the way it is
   expected to, the components are handed back untouched and the email goes
   out exactly as Contact Form 7 composed it. A broken booking notification is
   far worse than a notification without buttons in it.
   ------------------------------------------------------------------------ */

add_filter(
	'wpcf7_mail_components',
	function ( $components, $contact_form = null, $mail = null ) {
		if ( ! is_array( $components ) || empty( $GLOBALS['wc_bk_current'] ) ) {
			return $components;
		}
		if ( $contact_form && (int) $contact_form->id() !== (int) WC_BK_FORM_ID ) {
			return $components;
		}

		// Only the message addressed to you — never the guest's acknowledgement.
		$is_owner_mail = false;
		if ( is_object( $mail ) && method_exists( $mail, 'name' ) ) {
			$is_owner_mail = ( 'mail' === $mail->name() );
		} elseif ( ! empty( $components['recipient'] ) ) {
			$is_owner_mail = ( false !== stripos( $components['recipient'], WC_BK_OWNER ) );
		}
		if ( ! $is_owner_mail ) {
			return $components;
		}

		$post_id = (int) $GLOBALS['wc_bk_current'];
		$block   = "\n\n"
			. "----------------------------------------------------------\n"
			. "ANSWER THIS BOOKING\n\n"
			. "Yes, the cottage is free:\n"
			. wc_bk_manage_url( $post_id, 'confirm' ) . "\n\n"
			. "No, those nights are taken:\n"
			. wc_bk_manage_url( $post_id, 'decline' ) . "\n\n"
			. "Either link opens a page where you check the details and press a\n"
			. "button. Nothing is sent to the guest until you do.\n"
			. "----------------------------------------------------------\n";

		$components['body'] = (string) $components['body'] . $block;
		return $components;
	},
	10,
	3
);

function wc_bk_manage_url( $post_id, $action ) {
	return add_query_arg(
		array(
			'wc_booking' => (int) $post_id,
			'wc_do'      => $action,
			'wc_token'   => get_post_meta( $post_id, '_wc_token', true ),
		),
		home_url( '/' )
	);
}

/* ---------------------------------------------------------------------------
   4. The page those links open

   GET renders a page. Only POST changes anything. See the note at the top of
   this file about mail scanners if that distinction looks like fuss.
   ------------------------------------------------------------------------ */

add_action(
	'template_redirect',
	function () {
		if ( empty( $_GET['wc_booking'] ) && empty( $_POST['wc_booking'] ) ) {
			return;
		}

		$is_post  = ( 'POST' === strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : 'GET' ) );
		$post_id  = (int) ( $is_post ? $_POST['wc_booking'] : $_GET['wc_booking'] );
		$token_in = (string) ( $is_post
			? ( isset( $_POST['wc_token'] ) ? wp_unslash( $_POST['wc_token'] ) : '' )
			: ( isset( $_GET['wc_token'] ) ? wp_unslash( $_GET['wc_token'] ) : '' ) );
		$action   = (string) ( $is_post
			? ( isset( $_POST['wc_do'] ) ? wp_unslash( $_POST['wc_do'] ) : '' )
			: ( isset( $_GET['wc_do'] ) ? wp_unslash( $_GET['wc_do'] ) : '' ) );

		$post = get_post( $post_id );
		if ( ! $post || WC_BK_CPT !== $post->post_type ) {
			wc_bk_page( 'Not found', '<p>That booking link does not match anything here.</p>', 404 );
		}

		$token = (string) get_post_meta( $post_id, '_wc_token', true );
		if ( ! $token || ! hash_equals( $token, $token_in ) ) {
			wc_bk_page( 'Link not valid', '<p>That link has expired or was mistyped. Open the booking from the Bookings screen in WordPress instead.</p>', 403 );
		}

		if ( ! in_array( $action, array( 'confirm', 'decline' ), true ) ) {
			wc_bk_page( 'Nothing to do', '<p>That link is incomplete.</p>', 400 );
		}

		$status = get_post_meta( $post_id, '_wc_status', true );

		if ( 'pending' !== $status ) {
			$when = get_post_meta( $post_id, '_wc_decided', true );
			wc_bk_page(
				'Already answered',
				'<p>This request was marked <b>' . esc_html( $status ) . '</b>'
				. ( $when ? ' on ' . esc_html( $when ) : '' ) . '.</p>'
				. '<p>The guest has already been written to. Nothing was sent again.</p>'
				. wc_bk_summary_html( $post_id )
			);
		}

		if ( ! $is_post ) {
			wc_bk_confirm_form( $post_id, $action, $token );
		}

		/* ---- this is the only place anything actually happens ---- */
		$note = isset( $_POST['wc_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['wc_note'] ) ) : '';

		update_post_meta( $post_id, '_wc_status', 'confirm' === $action ? 'confirmed' : 'declined' );
		update_post_meta( $post_id, '_wc_decided', current_time( 'j F Y, g:i a' ) );
		update_post_meta( $post_id, '_wc_note', $note );

		/* The token is deliberately NOT regenerated here. What stops a second
		   press doing anything is the status check above, which is also what
		   lets the page say "already answered, nothing was sent again" instead
		   of "link not valid" — which is what a double-tap on a phone would
		   otherwise produce, and which reads like a fault. */

		$sent = wc_bk_send_guest_mail( $post_id, $action, $note );

		wc_bk_page(
			'confirm' === $action ? 'Booking confirmed' : 'Guest told the dates are taken',
			'<p>' . ( $sent
				? 'The guest has been emailed.'
				: '<b>The email did not go.</b> Check WP Mail SMTP, then reply to the guest by hand — the wording is on the Bookings screen.' )
			. '</p>' . wc_bk_summary_html( $post_id )
		);
	}
);

function wc_bk_confirm_form( $post_id, $action, $token ) {
	$name  = get_post_meta( $post_id, '_wc_your-name', true );
	$email = get_post_meta( $post_id, '_wc_your-email', true );

	$heading = 'confirm' === $action
		? 'Confirm this booking?'
		: 'Tell them the dates are taken?';

	$what = 'confirm' === $action
		? 'Pressing the button emails ' . esc_html( $name ) . ' to say the cottage is held for them, with the check-in times'
			. ( WC_BK_ADVANCE_PCT > 0 ? ' and the advance' : '' ) . '.'
		: 'Pressing the button emails ' . esc_html( $name ) . ' to say those nights are gone, and invites them to take other dates.';

	$ph = 'confirm' === $action
		? 'Anything to add — a late arrival, a cook for the first evening, directions.'
		: 'Offer them something. "The 14th to the 17th is free, and Green Cottage is open for your original dates."';

	$body = '<p class="lede">' . $what . '</p>'
		. wc_bk_summary_html( $post_id )
		. '<form method="post">'
		. '<input type="hidden" name="wc_booking" value="' . (int) $post_id . '">'
		. '<input type="hidden" name="wc_do" value="' . esc_attr( $action ) . '">'
		. '<input type="hidden" name="wc_token" value="' . esc_attr( $token ) . '">'
		. '<label for="wc_note">Add a line to the email <span>optional</span></label>'
		. '<textarea id="wc_note" name="wc_note" rows="4" placeholder="' . esc_attr( $ph ) . '"></textarea>'
		. '<button type="submit">' . ( 'confirm' === $action ? 'Send the confirmation' : 'Send it' ) . '</button>'
		. '<p class="small">Goes to ' . esc_html( $email ) . '. Nothing has been sent yet.</p>'
		. '</form>';

	wc_bk_page( $heading, $body );
}

function wc_bk_summary_html( $post_id ) {
	$rows = '';
	foreach ( wc_bk_fields() as $key => $label ) {
		$value = get_post_meta( $post_id, '_wc_' . $key, true );
		if ( '' === $value || null === $value ) {
			continue;
		}
		if ( 'total' === $key ) {
			$value = 'PKR ' . $value;
		}
		$rows .= '<tr><th>' . esc_html( $label ) . '</th><td>' . nl2br( esc_html( $value ) ) . '</td></tr>';
	}
	return '<table class="sum">' . $rows . '</table>';
}

/**
 * A page of its own. The theme is not loaded on purpose: this has to render
 * identically whatever happens to the WordPress site around it, including on
 * a phone, in a hurry, from an email.
 */
function wc_bk_page( $title, $body_html, $code = 200 ) {
	status_header( $code );
	nocache_headers();
	header( 'Content-Type: text/html; charset=utf-8' );
	header( 'X-Robots-Tag: noindex, nofollow', true );
	?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $title ); ?> — Woodland Cottages</title>
<style>
  :root{--ink:#07120C;--ink2:#0B1A12;--beige:#EFE9DC;--dim:#9AA294;--lime:#A9C68B;--limeink:#0E1A0A;--line:rgba(239,233,220,.16)}
  *{box-sizing:border-box;margin:0;padding:0}
  body{background:var(--ink);color:var(--beige);font:300 17px/1.6 Georgia,'Times New Roman',serif;padding:clamp(1.2rem,5vw,4rem)}
  .wrap{max-width:38rem;margin:0 auto}
  .eyebrow{font:400 10px/1 -apple-system,BlinkMacSystemFont,'Helvetica Neue',sans-serif;letter-spacing:.3em;text-transform:uppercase;color:var(--lime)}
  h1{font-size:clamp(1.9rem,6vw,2.7rem);font-weight:400;line-height:1.1;letter-spacing:-.02em;margin:1.2rem 0 1.4rem}
  .lede{color:#D8D1C1;margin-bottom:1.8rem}
  table.sum{width:100%;border-collapse:collapse;margin:1.6rem 0 2rem;background:var(--ink2);border:1px solid var(--line);border-radius:18px;overflow:hidden}
  .sum th,.sum td{padding:.75rem 1.1rem;text-align:left;vertical-align:top;border-bottom:1px solid var(--line)}
  .sum tr:last-child th,.sum tr:last-child td{border-bottom:0}
  .sum th{width:9.5rem;font:400 9.5px/1.6 -apple-system,BlinkMacSystemFont,sans-serif;letter-spacing:.22em;text-transform:uppercase;color:var(--dim)}
  label{display:block;font:400 9.5px/1 -apple-system,BlinkMacSystemFont,sans-serif;letter-spacing:.26em;text-transform:uppercase;color:var(--dim);margin-bottom:.7rem}
  label span{opacity:.6}
  textarea{width:100%;font:300 17px/1.45 Georgia,serif;color:var(--beige);background:rgba(239,233,220,.045);
           border:1px solid var(--line);border-radius:18px;padding:.9rem 1rem;outline:none;resize:vertical}
  textarea:focus{border-color:var(--lime)}
  button{margin-top:1.3rem;width:100%;cursor:pointer;background:var(--lime);color:var(--limeink);border:0;border-radius:999px;
         padding:1.15rem 2rem;font:400 11px/1 -apple-system,BlinkMacSystemFont,sans-serif;letter-spacing:.24em;text-transform:uppercase}
  .small{margin-top:1.1rem;font-size:.86rem;color:var(--dim);text-align:center}
  a{color:var(--lime)}
  .foot{margin-top:2.6rem;padding-top:1.4rem;border-top:1px solid var(--line);font-size:.86rem;color:var(--dim)}
</style>
</head><body><div class="wrap">
<span class="eyebrow">Woodland Cottages</span>
<h1><?php echo esc_html( $title ); ?></h1>
<?php echo $body_html; // already escaped piece by piece above ?>
<p class="foot">Galiyat, Northern Pakistan &middot; <?php echo esc_html( WC_BK_WHATSAPP ); ?></p>
</div></body></html>
	<?php
	exit;
}

/* ---------------------------------------------------------------------------
   5. What the guest receives

   Plain text on purpose. It reaches the inbox far more reliably than HTML,
   and it reads the same on every phone.
   ------------------------------------------------------------------------ */

function wc_bk_send_guest_mail( $post_id, $action, $note ) {
	$get = function ( $k ) use ( $post_id ) {
		return (string) get_post_meta( $post_id, '_wc_' . $k, true );
	};

	$to = $get( 'your-email' );
	if ( ! is_email( $to ) ) {
		return false;
	}

	$name    = $get( 'your-name' );
	$cottage = $get( 'cottage' );
	$first   = $name ? (string) strtok( $name, ' ' ) : '';
	$first   = trim( $first ) ? trim( $first ) : ( $name ? $name : 'there' );

	if ( 'confirm' === $action ) {
		$subject = 'Your stay is confirmed — Woodland Cottages';

		$money = '';
		if ( $get( 'total' ) ) {
			$money .= "  Total       PKR " . $get( 'total' ) . "\n";
		}

		$advance = '';
		if ( WC_BK_ADVANCE_PCT > 0 ) {
			$bank = ( false === strpos( WC_BK_BANK, '[' ) )
				? "\n" . WC_BK_BANK . "\n\nSend us the transfer receipt on WhatsApp and you are booked."
				: "\nWe will send you the account details on WhatsApp.";
			$advance = "\nTo hold it we ask for " . WC_BK_ADVANCE_PCT . "% up front, with the balance settled on arrival.\n"
				. $bank . "\n";
		}

		$body = "Dear " . $first . ",\n\n"
			. "Good news — " . $cottage . " is free for your dates and we have put it aside for you.\n\n"
			. "  Arriving    " . $get( 'checkin' ) . ", " . WC_BK_CHECKIN . "\n"
			. "  Leaving     " . $get( 'checkout' ) . ", by " . WC_BK_CHECKOUT . "\n"
			. "  Nights      " . $get( 'nights' ) . "\n"
			. "  Guests      " . $get( 'guests' ) . "\n"
			. $money
			. $advance
			. "\nWe will write again a few days before you travel with directions, what the\n"
			. "road is like at that time of year, and what to bring.\n";
	} else {
		$subject = 'About your dates — Woodland Cottages';
		$body    = "Dear " . $first . ",\n\n"
			. "Thank you for asking about " . $cottage . ". I am sorry — those nights are\n"
			. "already taken.\n\n";

		/* If you offered them something on the way through, that IS the rest of
		   the message — the generic "tell us when" paragraph underneath it would
		   only read as though nobody had looked. One or the other, never both. */
		if ( $note ) {
			$body .= "We would still like to have you.\n\n" . $note . "\n";
			$note  = '';
		} else {
			$body .= "We would still like to have you. If other dates could work, tell us roughly\n"
				. "when and we will find you something — or say the word and we will let you\n"
				. "know the moment these nights come free.\n";
		}
	}

	if ( $note ) {
		$body .= "\n" . $note . "\n";
	}

	$body .= "\nIf it is easier to talk it through, WhatsApp us on " . WC_BK_WHATSAPP . ".\n\n"
		. "Warmly,\n"
		. "Woodland Cottages\n"
		. "Galiyat, Northern Pakistan\n"
		. WC_BK_REPLY . "  ·  " . WC_BK_WHATSAPP . "\n";

	$headers = array(
		'From: ' . WC_BK_FROM,
		'Reply-To: ' . WC_BK_REPLY,
		'Content-Type: text/plain; charset=UTF-8',
	);

	$sent = wp_mail( $to, $subject, $body, $headers );
	update_post_meta( $post_id, '_wc_guest_mail', $sent ? 'sent' : 'failed' );
	update_post_meta( $post_id, '_wc_guest_body', $body );
	return (bool) $sent;
}

/* ---------------------------------------------------------------------------
   6. The Bookings screen
   ------------------------------------------------------------------------ */

add_filter(
	'manage_' . WC_BK_CPT . '_posts_columns',
	function ( $cols ) {
		return array(
			'cb'       => isset( $cols['cb'] ) ? $cols['cb'] : '',
			'title'    => 'Guest',
			'wc_stay'  => 'Stay',
			'wc_reach' => 'Reach them',
			'wc_state' => 'Status',
			'date'     => 'Received',
		);
	}
);

add_action(
	'manage_' . WC_BK_CPT . '_posts_custom_column',
	function ( $col, $post_id ) {
		$get = function ( $k ) use ( $post_id ) {
			return (string) get_post_meta( $post_id, '_wc_' . $k, true );
		};
		if ( 'wc_stay' === $col ) {
			echo esc_html( $get( 'checkin' ) . ' → ' . $get( 'checkout' ) );
			echo '<br><span style="color:#666">' . esc_html( $get( 'nights' ) . ' · ' . $get( 'guests' ) . ' guests · PKR ' . $get( 'total' ) ) . '</span>';
		} elseif ( 'wc_reach' === $col ) {
			$phone = preg_replace( '/\D/', '', $get( 'phone' ) );
			if ( $phone ) {
				$intl = ( '0' === substr( $phone, 0, 1 ) ) ? '92' . substr( $phone, 1 ) : $phone;
				echo '<a href="https://wa.me/' . esc_attr( $intl ) . '">' . esc_html( $get( 'phone' ) ) . '</a><br>';
			}
			echo '<a href="mailto:' . esc_attr( $get( 'your-email' ) ) . '">' . esc_html( $get( 'your-email' ) ) . '</a>';
		} elseif ( 'wc_state' === $col ) {
			$status = $get( 'status' );
			$colour = array(
				'pending'   => '#9a6700',
				'confirmed' => '#1a7f37',
				'declined'  => '#82071e',
			);
			echo '<b style="color:' . esc_attr( isset( $colour[ $status ] ) ? $colour[ $status ] : '#555' ) . '">'
				. esc_html( ucfirst( $status ? $status : 'unknown' ) ) . '</b>';
			if ( 'pending' === $status ) {
				echo '<br><a href="' . esc_url( wc_bk_manage_url( $post_id, 'confirm' ) ) . '">Confirm</a> &nbsp;|&nbsp; '
					. '<a href="' . esc_url( wc_bk_manage_url( $post_id, 'decline' ) ) . '">Decline</a>';
			} elseif ( 'failed' === $get( 'guest_mail' ) ) {
				echo '<br><b style="color:#82071e">Guest email failed</b>';
			}
		}
	},
	10,
	2
);

/** The whole request, under the title, when you open one. */
add_action(
	'add_meta_boxes',
	function () {
		add_meta_box(
			'wc_bk_detail',
			'The request',
			function ( $post ) {
				echo '<style>.wcbk th{text-align:left;width:9rem;padding:.4rem .6rem .4rem 0;color:#666;font-weight:400}'
					. '.wcbk td{padding:.4rem 0}.wcbk pre{white-space:pre-wrap;background:#f6f7f7;padding:1rem;border-radius:6px}</style>';
				echo '<table class="wcbk">';
				foreach ( wc_bk_fields() as $key => $label ) {
					$v = get_post_meta( $post->ID, '_wc_' . $key, true );
					if ( '' === $v ) {
						continue;
					}
					echo '<tr><th>' . esc_html( $label ) . '</th><td>' . nl2br( esc_html( $v ) ) . '</td></tr>';
				}
				echo '</table>';
				$body = get_post_meta( $post->ID, '_wc_guest_body', true );
				if ( $body ) {
					echo '<p><b>What the guest was sent</b></p><pre>' . esc_html( $body ) . '</pre>';
				}
			},
			WC_BK_CPT,
			'normal',
			'high'
		);
	}
);
