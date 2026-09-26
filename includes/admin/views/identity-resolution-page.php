<?php
/**
 * Identity Resolution page markup.
 *
 * Rendered from `IdentityResolutionPage::render_page()`, which owns the
 * capability gate and supplies `$ffc_identity_findings`. Markup only -- the
 * same carve-out `phpstan.neon.dist` and `phpunit.xml.dist` make for this
 * directory.
 *
 * @package FreeFormCertificate\Admin
 * @since 6.28.2
 *
 * @var array<int, array<string, mixed>> $ffc_identity_findings Findings from the check-digit scan.
 * @var array{type: string, text: string}|false                 $ffc_identity_outcome  Outcome of the last write, if any.
 * @var array{stores: int, examined: int, unreadable: int}       $ffc_identity_coverage What the scan actually read.
 * @var array<int, string>                                       $ffc_identity_capped   Checks that returned a full page.
 * @var int                                                      $ffc_identity_taken_at When the list was taken (unix).
 * @var bool                                                     $ffc_identity_may_split Whether the operator may open an account.
 * @var bool                                                     $ffc_identity_may_merge Whether the operator may merge two.
 * @var string                                                   $ffc_identity_export_url The audit CSV, or '' without the capability.
 * @var int                                                      $ffc_identity_resolved   Findings resolved since the queue was read.
 * @var callable                                                 $ffc_identity_facts      Account ids -> status, per-store counts and last activity.
 */

// No `declare(strict_types=1)` here on purpose: none of the 17 view and
// template files in this repository carries one. A view is `require`d into a
// caller's scope, and the directive is per-file, so one view under a
// different calling convention from every sibling is a trap for anybody
// moving markup between them.

// A VIEW DOES NOT INHERIT THE IMPORTS OF WHATEVER `require`d IT.
//
// `render_page()` is in `FreeFormCertificate\Admin` and this file declares no
// namespace, so every unqualified class name here resolves against the GLOBAL
// namespace. A name missing from this list is a fatal the moment the line
// runs -- and PHPStan cannot see it, because `phpstan.neon.dist` excludes
// `includes/admin/views` as markup. `ViewClassImportTest` is what watches
// this list now.
use FreeFormCertificate\Admin\IdentityQueuePanels;
use FreeFormCertificate\Admin\IdentityResolutionPage;
use FreeFormCertificate\Core\DateFormatter;
use FreeFormCertificate\Maintenance\IdentityConflictQuery;
use FreeFormCertificate\Maintenance\IdentityQueue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// TWO CAPS, AND ONLY ONE OF THEM WAS EVER REPORTED.
//
// This flag is the check-digit SCAN's own: `rf_check_digit_failures()` stops
// after `RF_SCAN_LIMIT` distinct stored values and emits a row saying so.
// `$ffc_identity_capped` is the other one -- each of the three checks behind
// this worklist is asked for `LIMIT` findings, and a check that returns a full
// page has more. That second cap said nothing at all until #1397, so a queue
// holding 140 findings of one kind looked exactly like one holding 100.
$ffc_identity_truncated = false;

foreach ( $ffc_identity_findings as $ffc_identity_finding ) {
	if ( ! empty( $ffc_identity_finding[ IdentityConflictQuery::COLUMN_SCAN_TRUNCATED ] ) ) {
		$ffc_identity_truncated = true;
		break;
	}
}

// ONE PANEL PER TIER, AND THE TIERS WITH NOTHING IN THEM ARE ABSENT.
//
// `IdentityQueuePanels` already dropped those and put the rest in the order
// of effort, so this is a lookup rather than a second opinion: the counters
// and the panels below are the same list.
$ffc_identity_by_tier = array();

foreach ( $ffc_identity_panels as $ffc_identity_panel ) {
	$ffc_identity_by_tier[ (string) $ffc_identity_panel['tier'] ] = $ffc_identity_panel;
}

// Every panel's own cursor, so a link that moves ONE panel carries the
// others where they already are. Derived from the panels rather than passed
// in, which is what stops the links from disagreeing with what is rendered.
$ffc_identity_cursors = array();
$ffc_identity_listed  = array();

foreach ( $ffc_identity_panels as $ffc_identity_panel ) {
	$ffc_identity_tier_key = (string) $ffc_identity_panel['tier'];

	$ffc_identity_cursors[ $ffc_identity_tier_key ] = (string) ( $ffc_identity_panel['current'][ IdentityQueue::COLUMN_KEY ] ?? '' );

	if ( ! empty( $ffc_identity_panel['list'] ) ) {
		$ffc_identity_listed[] = $ffc_identity_tier_key;
	}
}

/**
 * A screen URL with one panel moved and every other panel left alone.
 *
 * @param string             $tier   The panel to move.
 * @param string             $key    The finding to show, or '' to leave it.
 * @param array<int, string> $listed Tiers showing their whole list.
 * @return string
 */
$ffc_identity_url = static function ( $tier, $key, $listed ) use ( $ffc_identity_cursors ) {
	$at = $ffc_identity_cursors;

	if ( '' !== (string) $tier ) {
		$at[ (string) $tier ] = (string) $key;
	}

	$args = array(
		'page'                      => IdentityResolutionPage::MENU_SLUG,
		IdentityQueuePanels::ARG_AT => array_filter( $at ),
	);

	if ( array() !== $listed ) {
		$args[ IdentityQueuePanels::ARG_LIST ] = implode( ',', $listed );
	}

	return add_query_arg( $args, admin_url( 'edit.php?post_type=ffc_form' ) );
};

/**
 * What a panel renders: the one finding it is on, or all of them.
 *
 * @param array<string, mixed> $panel The panel.
 * @return array<int, array<string, mixed>>
 */
$ffc_identity_shown = static function ( $panel ) {
	return empty( $panel['list'] )
		? array( $panel['current'] )
		: (array) $panel['items'];
};

/**
 * An account as a person reads it: the name WordPress holds, then the id.
 *
 * The NAME is what makes a merge confirmable — "keep 5784 or 6092?" is not a
 * question anybody can answer. The document stays a hash prefix, because that
 * is the identifier and this screen never shows one.
 *
 * @param int $user_id The account.
 * @return string
 */
/**
 * A panel's header: what it is, where the operator is in it, and the way out.
 *
 * THE COUNTER IS `aria-live`, BECAUSE THE NAVIGATION IS OTHERWISE MUTE.
 *
 * Moving between findings replaces the panel's body and changes nothing a
 * screen reader announces on its own -- so somebody navigating by keyboard
 * would hear the link they activated and nothing about where they landed.
 *
 * @param array<string, mixed> $panel  The panel.
 * @param string               $label  Its name.
 * @param string               $note   One line on what the tier is.
 * @return void
 */
$ffc_identity_head = static function ( $panel, $label, $note ) use ( $ffc_identity_url, $ffc_identity_listed ) {
	$tier  = (string) $panel['tier'];
	$total = (int) $panel['total'];
	$list  = ! empty( $panel['list'] );
	// A FLOOR IS NOT A TOTAL, AND THIS IS WHERE THE NUMBER IS READ (#1466).
	//
	// The checks behind this panel reached their cap, so `$total` is `at
	// least` this many and not this many. The scan already reported the cap
	// in the banner at the top of the page -- but that banner speaks about
	// the SCAN, once, while the number is per category and further down, and
	// an operator who scrolled past it reads `32` as thirty-two.
	//
	// `IdentityQueuePanels` derives this per panel from the items' own
	// `COLUMN_CHECK`, so one capped check qualifies every tier it feeds
	// (`CHECK_MULTIPLE` feeds three) without anything here knowing which.
	$capped = ! empty( $panel['capped'] );

	// The toggle carries every OTHER listed tier plus or minus this one, so
	// one category can be scanned whole while the rest stay one at a time.
	$others = array_values( array_diff( $ffc_identity_listed, array( $tier ) ) );
	$toggle = $list ? $others : array_merge( $others, array( $tier ) );
	// The tier is part of the class, so the chip is coloured by what the
	// finding IS rather than by a decision taken in the markup. The four
	// values come from `IdentityQueuePanels::ORDER`, never from the request.
	$chip = 'ffc-identity-chip-' . preg_replace( '/[^a-z]/', '', $tier );
	?>
	<div class="ffc-identity-panel">
	<div class="ffc-identity-panel-head">
		<span class="ffc-identity-panel-chip <?php echo esc_attr( $chip ); ?>"><?php echo esc_html( $label ); ?></span>
		<h2 class="ffc-identity-panel-title screen-reader-text"><?php echo esc_html( $label ); ?></h2>
		<p class="ffc-identity-panel-note description"><?php echo esc_html( $note ); ?></p>
		<div class="ffc-identity-panel-nav">
			<?php if ( $list ) : ?>
				<span class="ffc-identity-panel-count" aria-live="polite">
					<?php
					printf(
						$capped
							/* translators: %s: how many findings this category holds, as a lower bound. */
							? esc_html( _n( 'at least %s finding', 'at least %s findings', $total, 'ffcertificate' ) )
							/* translators: %s: how many findings this category holds. */
							: esc_html( _n( '%s finding', '%s findings', $total, 'ffcertificate' ) ),
						esc_html( number_format_i18n( $total ) )
					);
					?>
				</span>
			<?php else : ?>
				<span class="ffc-identity-panel-count" aria-live="polite">
					<?php
					printf(
						$capped
							/* translators: 1: the finding being shown, 2: how many there are, as a lower bound. */
							? esc_html__( '%1$s of at least %2$s', 'ffcertificate' )
							/* translators: 1: the finding being shown, 2: how many there are. */
							: esc_html__( '%1$s of %2$s', 'ffcertificate' ),
						esc_html( number_format_i18n( (int) $panel['index'] + 1 ) ),
						esc_html( number_format_i18n( $total ) )
					);
					?>
				</span>
				<?php
				// AN ARROW, AND THE WORD IN THREE PLACES THE ARROW IS NOT.
				//
				// These two sit between the position ("3 of 14") and the link
				// to the list, so the direction is the whole message and the
				// words spend a third of the strip's width saying it twice.
				// Dropping to a glyph is only safe because the word survives
				// everywhere it was doing work: `aria-label` IS the accessible
				// name, so nothing changes for a screen reader; `title` draws
				// the tooltip the admin sheets already style on hover -- and
				// this sheet adds the `:focus-visible` half of that family,
				// which did not exist, so a keyboard reaches the same label
				// the mouse does (WCAG 1.4.13).
				//
				// What no tooltip reaches is touch, where neither hover nor
				// focus fires. That is carried by the glyph being a chevron
				// and by the 40px target, and it is the reason the label is
				// an `aria-label` rather than a `title` alone.
				?>
				<?php foreach ( array( 'previous', 'next' ) as $ffc_identity_step ) : ?>
					<?php
					$ffc_identity_step_label = 'previous' === $ffc_identity_step
						? __( 'Previous', 'ffcertificate' )
						: __( 'Next', 'ffcertificate' );
					// The chevron is a dashicon, the way every other glyph in
					// this plugin is -- and not a `content: '\f341'` rule,
					// which would need the font named in our own sheet and is
					// the shape `CssNamespaceAnchorTest` exists to bound.
					$ffc_identity_step_class = 'button button-secondary ffc-identity-panel-step ffc-identity-panel-step-' . $ffc_identity_step;
					$ffc_identity_step_icon  = 'previous' === $ffc_identity_step
						? 'dashicons-arrow-left-alt2'
						: 'dashicons-arrow-right-alt2';
					?>
					<?php if ( '' !== (string) $panel[ $ffc_identity_step ] ) : ?>
						<a class="<?php echo esc_attr( $ffc_identity_step_class ); ?>"
							aria-label="<?php echo esc_attr( $ffc_identity_step_label ); ?>"
							title="<?php echo esc_attr( $ffc_identity_step_label ); ?>"
							href="<?php echo esc_url( $ffc_identity_url( $tier, (string) $panel[ $ffc_identity_step ], $ffc_identity_listed ) ); ?>">
							<span class="dashicons <?php echo esc_attr( $ffc_identity_step_icon ); ?>" aria-hidden="true"></span>
						</a>
					<?php else : ?>
						<?php // Rendered and disabled rather than absent, so the controls do not move under the pointer as the operator walks the list. ?>
						<span class="<?php echo esc_attr( $ffc_identity_step_class ); ?> disabled"
							aria-disabled="true"
							aria-label="<?php echo esc_attr( $ffc_identity_step_label ); ?>"
							title="<?php echo esc_attr( $ffc_identity_step_label ); ?>">
							<span class="dashicons <?php echo esc_attr( $ffc_identity_step_icon ); ?>" aria-hidden="true"></span>
						</span>
					<?php endif; ?>
				<?php endforeach; ?>
			<?php endif; ?>
			<a class="ffc-identity-panel-toggle" href="<?php echo esc_url( $ffc_identity_url( '', '', $toggle ) ); ?>">
				<?php
				echo $list
					? esc_html__( 'One at a time', 'ffcertificate' )
					: esc_html__( 'See the list', 'ffcertificate' );
				?>
			</a>
		</div>
	</div>
	<div class="ffc-identity-panel-body">
	<?php
};

/**
 * Close the card a panel header opened.
 *
 * Paired with `$ffc_identity_head` rather than wrapping the body in it,
 * because each tier's body is markup that already exists and moving it into
 * a closure would put three tables through one more indirection for nothing.
 *
 * @return void
 */
$ffc_identity_foot = static function () {
	?>
	</div></div>
	<?php
};

/**
 * A store, as an operator reads it.
 *
 * THE QUERIES DO NOT AGREE ON THE SHAPE, AND THE SCREEN SHOWED BOTH.
 *
 * `IdentityConflictQuery::label()` strips the site prefix and the `ffc_`
 * before handing a store name out, so its findings say `submissions`.
 * `IdentityOrphanQuery` keys its rows by the FULL prefixed table, so its
 * findings say `wp_ffc_submissions`. Both reach this screen, and until this
 * was written both reached it raw -- which is how a card came to read
 * `self_scheduling_appointments|user_profiles`, a machine name joined by the
 * query's own internal separator.
 *
 * So this normalises first and then translates. A store nobody has mapped
 * still renders as its normalised self rather than as nothing: an unknown
 * name is worth less than a known one and worth far more than a blank.
 *
 * `user_profiles` is named for what it IS. It is the identity index, not a
 * record store, and listing it beside submissions without saying so invites
 * the reading that the person has a record there.
 *
 * @param string $store The store, in either shape.
 * @return string
 */
$ffc_identity_store_name = static function ( $store ) {
	$key = (string) $store;

	if ( isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] ) && isset( $GLOBALS['wpdb']->prefix ) ) {
		$prefix = (string) $GLOBALS['wpdb']->prefix;

		if ( '' !== $prefix && 0 === strpos( $key, $prefix ) ) {
			$key = substr( $key, strlen( $prefix ) );
		}
	}

	if ( 0 === strpos( $key, 'ffc_' ) ) {
		$key = substr( $key, 4 );
	}

	switch ( $key ) {
		case 'submissions':
			return __( 'submissions', 'ffcertificate' );
		case 'self_scheduling_appointments':
			return __( 'appointments', 'ffcertificate' );
		case 'recruitment_candidate':
			return __( 'candidacies', 'ffcertificate' );
		case 'user_profiles':
			return __( 'the identity index', 'ffcertificate' );
		default:
			return $key;
	}
};

/**
 * A list of stores, in the reader's own language.
 *
 * `wp_sprintf_l()` is WordPress's own list joiner, so the comma and the
 * "and" come from core's translations rather than from a separator invented
 * here -- which is the whole defect this replaces: the screen was printing
 * `IdentityConflictQuery::RELATED_SEPARATOR`, a `|` that exists to survive a
 * `GROUP_CONCAT` and was never meant to be read.
 *
 * @param string $joined The stores as the query joined them.
 * @return string
 */
$ffc_identity_store_list = static function ( $joined ) use ( $ffc_identity_store_name ) {
	$parts = array_filter( array_map( 'trim', explode( IdentityConflictQuery::RELATED_SEPARATOR, (string) $joined ) ) );

	if ( array() === $parts ) {
		return '';
	}

	return wp_sprintf_l( '%l', array_map( $ffc_identity_store_name, $parts ) );
};

$ffc_identity_named = static function ( $user_id ) {
	$user = get_userdata( (int) $user_id );
	$name = ( $user && '' !== (string) $user->display_name ) ? (string) $user->display_name : '';

	return '' === $name
		? sprintf( /* translators: %d: account id. */ __( 'Account #%d', 'ffcertificate' ), (int) $user_id )
		: sprintf( /* translators: 1: person's name, 2: account id. */ __( '%1$s (#%2$d)', 'ffcertificate' ), $name, (int) $user_id );
};

/**
 * One tier's name, as an operator reads it.
 *
 * @param string $tier The tier.
 * @return string
 */
$ffc_identity_tier_label = static function ( $tier ) {
	switch ( $tier ) {
		case IdentityQueue::TIER_MECHANICAL:
			return __( 'One is mistyped', 'ffcertificate' );
		case IdentityQueue::TIER_SHARED:
			return __( 'Two accounts, one number', 'ffcertificate' );
		case IdentityQueue::TIER_ISOLATED:
			return __( 'Numbers to correct', 'ffcertificate' );
		case IdentityQueue::TIER_MAILBOX:
			return __( 'One address, several people', 'ffcertificate' );
		default:
			return __( 'Needs a decision', 'ffcertificate' );
	}
};

/**
 * What the operator is being told to do about one tier.
 *
 * @param string $tier The tier.
 * @return string
 */
$ffc_identity_tier_note = static function ( $tier ) {
	switch ( $tier ) {
		case IdentityQueue::TIER_MECHANICAL:
			return __( 'The check digits identify which of the two is wrong, and the other one is this account\'s. No value has to be asked for.', 'ffcertificate' );
		case IdentityQueue::TIER_SHARED:
			return __( 'One number is stored against more than one account. That is a merge, not a correction: the decision is which account survives.', 'ffcertificate' );
		case IdentityQueue::TIER_MAILBOX:
			// THIS SENTENCE DENIED THE BUTTONS SITTING NEXT TO IT (#1461).
			//
			// It ended by stating that no verb was offered here and that the
			// operator should read the account and decide with HR -- true
			// while the tier was classified and then refused every verb.
			// #1461 gave it two, and four releases went by
			// with the panel telling the operator not to act while the cards
			// beside it offered move and split -- found in a screenshot of the
			// shipped screen, where the denial and the two buttons are visible
			// in one frame.
			//
			// `with HR` survives because it is still true and is the load
			// bearing half: the move makes the operator tick an
			// acknowledgement that HR confirmed whose the records are. What
			// goes is the claim that there is nowhere to put that answer.
			return __( 'The numbers on this account share one address and are not variants of each other, so the address does not identify one person — a shared mailbox, or an account submitting on behalf of others. The decision is per number and it is made with HR: each one moves to the account it belongs to, or splits onto a new one.', 'ffcertificate' );
		default:
			return __( 'The check digits do not single one out — none fails, or more than one does. Confirm with HR which number is this person\'s.', 'ffcertificate' );
	}
};
?>
<div class="wrap ffc-admin-page ffc-page-identities">
	<h1><?php esc_html_e( 'Identity Resolution', 'ffcertificate' ); ?></h1>

	<?php if ( is_array( $ffc_identity_outcome ) && ! empty( $ffc_identity_outcome['text'] ) ) : ?>
		<?php
		wp_admin_notice(
			esc_html( (string) $ffc_identity_outcome['text'] ),
			array(
				'type'               => (string) ( $ffc_identity_outcome['type'] ?? 'info' ),
				'additional_classes' => array( 'inline' ),
			)
		);
		?>
	<?php endif; ?>

	<p class="description">
		<?php esc_html_e( 'Accounts and stored numbers that do not agree with each other, sorted by how much of the answer is already known. The check digits are what sort them: they can say a number is wrong, and never what the right one is. Identifiers are shown as a hash prefix — the values are decrypted in memory to be checked and none of them leaves the scan.', 'ffcertificate' ); ?>
	</p>

	<?php
	// WHAT THE SCAN READ, STATED WHERE THE QUEUE IS WORKED.
	//
	// These three numbers used to be read inside the empty-queue branch
	// alone, so the only screen state that never stated its own coverage was
	// the state an operator actually works: a queue WITH findings. A partial
	// list whose completeness is unstated reads as a whole one -- the
	// `#1071` / `#1094` rule ("an empty result must never read as clean") one
	// step along, where what passes for clean is a list rather than a zero.
	//
	// The four empty-queue notices further down stay exactly as they are.
	// This strip states numbers; they say what an unread scan MEANS, and the
	// strip must never become a cheerier second answer to their question --
	// which is why its three verdicts are derived from the same readings
	// those branches test, and why the reassuring one is the narrowest.
	$ffc_identity_examined   = (int) ( $ffc_identity_coverage['examined'] ?? 0 );
	$ffc_identity_unreadable = (int) ( $ffc_identity_coverage['unreadable'] ?? 0 );
	$ffc_identity_stores     = (int) ( $ffc_identity_coverage['stores'] ?? 0 );

	// Read nothing: no store carries the columns this check needs, or every
	// value it found was unreadable. Both are what an encryption key that
	// does not match the data looks like, and neither is evidence about the
	// numbers themselves.
	$ffc_identity_read_none = 0 === $ffc_identity_stores
		|| ( $ffc_identity_examined > 0 && $ffc_identity_examined === $ffc_identity_unreadable );

	// Read part of it: something was skipped, or a cap cut the reading short.
	// `$ffc_identity_capped` is the per-check cap and `$ffc_identity_truncated`
	// the scan's own — two different limits, and either one makes the counters
	// below counts of what was read rather than of what there is.
	$ffc_identity_read_part = ! $ffc_identity_read_none
		&& ( $ffc_identity_unreadable > 0
			|| 0 === $ffc_identity_examined
			|| $ffc_identity_truncated
			|| array() !== $ffc_identity_capped );

	if ( $ffc_identity_read_none ) {
		$ffc_identity_scan_state = 'none';
	} elseif ( $ffc_identity_read_part ) {
		$ffc_identity_scan_state = 'partial';
	} else {
		$ffc_identity_scan_state = 'whole';
	}
	?>
	<div class="ffc-identity-scan ffc-identity-scan-<?php echo esc_attr( $ffc_identity_scan_state ); ?>">
		<p class="ffc-identity-scan-verdict">
			<?php if ( $ffc_identity_read_none ) : ?>
				<?php esc_html_e( 'This scan read nothing', 'ffcertificate' ); ?>
			<?php elseif ( $ffc_identity_read_part ) : ?>
				<?php esc_html_e( 'The scan read part of the data', 'ffcertificate' ); ?>
			<?php else : ?>
				<?php esc_html_e( 'The scan read the data', 'ffcertificate' ); ?>
			<?php endif; ?>
		</p>
		<p class="ffc-identity-scan-detail">
			<?php
			// THREE COUNTS, THREE PLURALS, AND ONE SENTENCE WOULD HAVE NEEDED
			// THE SAME FORM FOR ALL THREE.
			//
			// The first version of this was a single string carrying all
			// three numbers, which renders `1 valores distintos verificados`
			// in Portuguese and is wrong in every language that inflects.
			// `_n()` picks a form per NUMBER, so the three have to be three
			// calls -- and they are joined by the same separator rather than
			// by a fourth string, because what sits between them is
			// punctuation, not prose.
			printf(
				/* translators: %s: how many distinct stored values were checked. */
				esc_html( _n( '%s distinct value checked', '%s distinct values checked', $ffc_identity_examined, 'ffcertificate' ) ),
				esc_html( number_format_i18n( $ffc_identity_examined ) )
			);
			?>
			·
			<?php
			printf(
				/* translators: %s: how many stored values could not be read. */
				esc_html( _n( '%s could not be read', '%s could not be read', $ffc_identity_unreadable, 'ffcertificate' ) ),
				esc_html( number_format_i18n( $ffc_identity_unreadable ) )
			);
			?>
			·
			<?php
			printf(
				/* translators: %s: how many stores the scan read. */
				esc_html( _n( '%s store scanned', '%s stores scanned', $ffc_identity_stores, 'ffcertificate' ) ),
				esc_html( number_format_i18n( $ffc_identity_stores ) )
			);
			?>
			<?php if ( $ffc_identity_truncated ) : ?>
				· <?php esc_html_e( 'the scan reached its cap', 'ffcertificate' ); ?>
			<?php endif; ?>
			<?php if ( array() !== $ffc_identity_capped ) : ?>
				· <?php esc_html_e( 'a check returned a full page', 'ffcertificate' ); ?>
			<?php endif; ?>
		</p>
		<?php if ( $ffc_identity_taken_at > 0 ) : ?>
			<div class="ffc-identity-scan-when">
				<span>
					<?php
					printf(
						/* translators: %s: when the queue was read, as a date and time. */
						esc_html__( 'Read at %s and held still while you work it', 'ffcertificate' ),
						esc_html( DateFormatter::format_datetime( $ffc_identity_taken_at ) )
					);
					?>
				</span>
				<?php // A form, because reading again is a POST — which is also why it cannot sit inside the paragraph above: a form is not phrasing content, and nesting it in a `<p>` closes the paragraph early. ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( IdentityResolutionPage::RESCAN_NONCE ); ?>
					<input type="hidden" name="action" value="<?php echo esc_attr( IdentityResolutionPage::RESCAN_ACTION ); ?>">
					<button type="submit" class="button button-secondary">
						<?php esc_html_e( 'Read the queue again', 'ffcertificate' ); ?>
					</button>
				</form>
			</div>
		<?php endif; ?>
	</div>

	<?php
	// THE COUNTERS ARE THE PANELS, COUNTED — NEVER A SECOND OPINION.
	//
	// Both this strip and the bodies below read `$ffc_identity_panels`, which
	// `IdentityQueuePanels::build()` already emptied of the tiers holding
	// nothing. So a category absent here is absent below by construction, and
	// the two cannot drift into disagreeing about how many findings there are.
	//
	// The shared tier is counted even where the merge capability is absent
	// and its panel is therefore not drawn: the finding exists and is
	// somebody's to resolve, and a counter that hid it would report a shorter
	// queue to the operator least able to see why.
	$ffc_identity_remaining = count( $ffc_identity_orphans );

	foreach ( $ffc_identity_panels as $ffc_identity_panel ) {
		$ffc_identity_remaining += (int) $ffc_identity_panel['total'];
	}
	?>
	<?php if ( $ffc_identity_remaining > 0 ) : ?>
		<div class="ffc-identity-counts">
			<span class="ffc-identity-counts-total">
				<?php
				printf(
					/* translators: %s: how many findings the whole queue holds. */
					esc_html( _n( '%s finding left:', '%s findings left:', $ffc_identity_remaining, 'ffcertificate' ) ),
					esc_html( number_format_i18n( $ffc_identity_remaining ) )
				);
				?>
			</span>
			<?php foreach ( $ffc_identity_panels as $ffc_identity_panel ) : ?>
				<?php
				// The same derivation the panel heading makes, from the same
				// value: the tier decides the class, never the markup.
				$ffc_identity_tier = (string) $ffc_identity_panel['tier'];
				$ffc_identity_chip = 'ffc-identity-chip-' . preg_replace( '/[^a-z]/', '', $ffc_identity_tier );
				?>
				<span class="ffc-identity-count <?php echo esc_attr( $ffc_identity_chip ); ?>">
					<?php echo esc_html( $ffc_identity_tier_label( $ffc_identity_tier ) ); ?>
					<strong><?php echo esc_html( number_format_i18n( (int) $ffc_identity_panel['total'] ) ); ?></strong>
				</span>
			<?php endforeach; ?>
			<?php if ( array() !== $ffc_identity_orphans ) : ?>
				<span class="ffc-identity-count ffc-identity-chip-orphans">
					<?php esc_html_e( 'No account', 'ffcertificate' ); ?>
					<strong><?php echo esc_html( number_format_i18n( count( $ffc_identity_orphans ) ) ); ?></strong>
				</span>
			<?php endif; ?>
			<?php
			// WHAT HAS BEEN DONE, BESIDE WHAT IS LEFT, AND BOTH FROM THE SAME
			// INSTANT.
			//
			// The window is the held queue's own: `N left` counts the list
			// taken at `$ffc_identity_taken_at`, so the only number that can
			// sit beside it honestly is one measured from there. Reading the
			// queue again resets the two together, which is the gesture that
			// begins a new round. Zero is not rendered -- an operator who has
			// resolved nothing yet does not need to be told so.
			?>
			<?php if ( $ffc_identity_resolved > 0 ) : ?>
				<span class="ffc-identity-counts-done">
					<?php
					printf(
						/* translators: %s: how many findings this operator has resolved since the queue was read. */
						esc_html( _n( '%s resolved since this queue was read', '%s resolved since this queue was read', $ffc_identity_resolved, 'ffcertificate' ) ),
						esc_html( number_format_i18n( $ffc_identity_resolved ) )
					);
					?>
				</span>
			<?php endif; ?>
			<span class="description ffc-identity-counts-note">
				<?php esc_html_e( 'A category holding nothing does not appear — not here, and not below.', 'ffcertificate' ); ?>
			</span>
			<?php if ( '' !== $ffc_identity_export_url ) : ?>
				<a class="button button-secondary ffc-identity-counts-export" href="<?php echo esc_url( $ffc_identity_export_url ); ?>">
					<?php esc_html_e( 'Export CSV', 'ffcertificate' ); ?>
				</a>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php if ( array() !== $ffc_identity_capped ) : ?>
		<?php
		wp_admin_notice(
			esc_html(
				sprintf(
					/* translators: 1: how many findings each check returns at most, 2: comma-separated check names. */
					__( 'This queue lists at most %1$s findings per check, and %2$s returned a full page — so more exist than are listed. The counts below are of what was read, not of what there is.', 'ffcertificate' ),
					number_format_i18n( IdentityResolutionPage::LIMIT ),
					implode( ', ', array_map( 'strval', $ffc_identity_capped ) )
				)
			),
			array(
				'type'               => 'warning',
				'additional_classes' => array( 'inline' ),
			)
		);
		?>
	<?php endif; ?>

	<?php if ( $ffc_identity_truncated ) : ?>
		<?php
		wp_admin_notice(
			esc_html__( 'The scan reached its cap before reading every stored value, so this list is partial. Work it down and run the screen again.', 'ffcertificate' ),
			array(
				'type'               => 'warning',
				'additional_classes' => array( 'inline' ),
			)
		);
		?>
	<?php endif; ?>

	<?php
	// AN OFFERED BUTTON THAT ANSWERS 403 IS WORSE THAN AN ABSENT ONE.
	//
	// The shared panel IS the merge form -- there is nothing else in it -- so
	// an operator without the capability is shown the findings and a control
	// that refuses them. The panel goes; the findings stay countable, because
	// `IdentityQueuePanels` built them either way and the tier still exists.
	?>
	<?php if ( isset( $ffc_identity_by_tier[ IdentityQueue::TIER_SHARED ] ) && $ffc_identity_may_merge ) : ?>
		<?php
		$ffc_identity_panel = $ffc_identity_by_tier[ IdentityQueue::TIER_SHARED ];
		$ffc_identity_pairs = $ffc_identity_shown( $ffc_identity_panel );

		$ffc_identity_head(
			$ffc_identity_panel,
			$ffc_identity_tier_label( IdentityQueue::TIER_SHARED ),
			$ffc_identity_tier_note( IdentityQueue::TIER_SHARED )
		);
		?>
		<p class="description">
			<?php esc_html_e( 'One identifier is stored against two logins, so one of them is not that person\'s. Merge only when you know they are one person, and choose which login keeps the records. A merge is the one action no other can undo: afterwards nothing can tell which records came from where.', 'ffcertificate' ); ?>
		</p>

		<?php
		// ONE PAIR PER FORM, WHICH IS WHAT REMOVED THE PARTIAL RESULT.
		//
		// This was one form over a list of checkboxes, so an outcome could be
		// part merged and part refused. Each pair is now its own form, its own
		// nonce and its own transaction, and the stepper above shows one at a
		// time unless the operator asked for the list.
		?>
		<?php foreach ( $ffc_identity_pairs as $ffc_identity_pair ) : ?>
			<?php
			$ffc_identity_who = IdentityConflictQuery::parse_accounts(
				$ffc_identity_pair[ IdentityConflictQuery::COLUMN_RELATED ] ?? ''
			);
			$ffc_identity_id  = (string) ( $ffc_identity_pair['subject'] ?? '' );
			?>
			<?php if ( 2 !== count( $ffc_identity_who ) ) : ?>
				<p class="description">
					<?php
					// MORE THAN TWO IS NOT A PAIR, AND IT IS NOT OFFERED. A
					// merge takes one survivor and one absorbed account; three
					// logins sharing a number is three decisions, made two at
					// a time, and a control that hid that would invite one
					// sweep over all of them.
					printf(
						/* translators: %s: how many accounts share the identifier. */
						esc_html__( '%s accounts share this identifier, so it is not one pair. Resolve them two at a time.', 'ffcertificate' ),
						esc_html( number_format_i18n( count( $ffc_identity_who ) ) )
					);
					?>
				</p>
				<?php continue; ?>
			<?php endif; ?>
			<?php
			// THE EVIDENCE BEHIND THE CHOICE, WHERE THE CHOICE IS MADE (#1368).
			//
			// The preview below already states the per-store counts, and it
			// states them AFTER a click -- which is one click too late: the
			// operator picks a survivor at the radio, so the evidence belongs
			// at the radio. `account_activity` reached the audit CSV and the
			// Migrations table and never this form at all.
			//
			// Read for this pair only, through the same `account_facts()` the
			// export reads, so the screen and the CSV cannot disagree.
			$ffc_identity_pair_facts = $ffc_identity_facts( $ffc_identity_who );
			?>
			<div class="ffc-identity-pair">
				<p class="ffc-identity-pair-subject">
					<code><?php echo esc_html( substr( $ffc_identity_id, 0, IdentityQueue::DISPLAY_PREFIX ) ); ?></code>
					<span class="description">
						<?php echo esc_html( strtoupper( str_replace( '_hash', '', (string) ( $ffc_identity_pair['identifier_column'] ?? '' ) ) ) ); ?>
					</span>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ffc-identity-merge-one">
					<?php wp_nonce_field( IdentityResolutionPage::MERGE_NONCE . $ffc_identity_id ); ?>
					<input type="hidden" name="action" value="<?php echo esc_attr( IdentityResolutionPage::MERGE_ACTION ); ?>">
					<input type="hidden" name="ffc_key" value="<?php echo esc_attr( (string) ( $ffc_identity_pair[ IdentityQueue::COLUMN_KEY ] ?? '' ) ); ?>">
					<input type="hidden" name="ffc_subject" value="<?php echo esc_attr( $ffc_identity_id ); ?>">
					<input type="hidden" name="ffc_a" value="<?php echo esc_attr( (string) $ffc_identity_who[0] ); ?>">
					<input type="hidden" name="ffc_b" value="<?php echo esc_attr( (string) $ffc_identity_who[1] ); ?>">

					<fieldset class="ffc-identity-pair-choice">
						<legend><?php esc_html_e( 'Which login keeps the records', 'ffcertificate' ); ?></legend>
						<?php // NOTHING IS PROPOSED. The evidence says which login holds the records and when it was last used; which one is the person's real login is not a thing the data settles, and a screen that picked one would be asserting it. ?>
						<p class="description ffc-identity-pair-basis">
							<?php esc_html_e( 'The newer login is usually the accidental one — it exists because the resolver failed to match. What each holds and when it was last used is below; the choice is yours.', 'ffcertificate' ); ?>
						</p>
						<?php foreach ( $ffc_identity_who as $ffc_identity_account ) : ?>
							<?php
							$ffc_identity_fact = $ffc_identity_pair_facts[ (int) $ffc_identity_account ] ?? array();
							$ffc_identity_held = array();

							foreach ( (array) ( $ffc_identity_fact['rows'] ?? array() ) as $ffc_identity_store => $ffc_identity_n ) {
								$ffc_identity_held[] = sprintf(
									/* translators: 1: how many records. 2: the store holding them. */
									__( '%1$s in %2$s', 'ffcertificate' ),
									number_format_i18n( (int) $ffc_identity_n ),
									$ffc_identity_store_name( $ffc_identity_store )
								);
							}

							// `format_wallclock_date()`, NEVER `format_date()`.
							//
							// `activity_per_account()` already resolved the
							// moment to a site-local `Y-m-d` -- it has to,
							// because the four stores disagree about how a
							// moment is stored and only the rendered date
							// compares like for like across them. So what
							// arrives here is Category B and carries no
							// timezone semantics; `format_date()` would parse
							// it at UTC midnight and re-apply the site zone,
							// printing the PREVIOUS day anywhere west of UTC.
							$ffc_identity_seen = DateFormatter::format_wallclock_date(
								(string) ( $ffc_identity_fact['activity'] ?? '' )
							);
							?>
							<label>
								<input type="radio" name="ffc_keep" required
									class="ffc-identity-keep"
									value="<?php echo esc_attr( (string) $ffc_identity_account ); ?>">
								<a href="<?php echo esc_url( admin_url( 'user-edit.php?user_id=' . rawurlencode( (string) $ffc_identity_account ) ) ); ?>">
									<?php echo esc_html( $ffc_identity_named( $ffc_identity_account ) ); ?>
								</a>
							</label>
							<p class="description ffc-identity-pair-evidence">
								<?php if ( array() !== $ffc_identity_held ) : ?>
									<span class="ffc-identity-pair-holds">
										<?php
										printf(
											/* translators: %s: the per-store record counts, comma separated. */
											esc_html__( 'Holds %s', 'ffcertificate' ),
											esc_html( wp_sprintf_l( '%l', $ffc_identity_held ) )
										);
										?>
									</span>
								<?php else : ?>
									<?php // An account this audit named is named BECAUSE rows point at it, so no count means the rows sit in a store this install does not resolve -- never that the login is empty. ?>
									<span class="ffc-identity-pair-holds"><?php esc_html_e( 'No records in any store this install can read', 'ffcertificate' ); ?></span>
								<?php endif; ?>
								<?php if ( '' !== $ffc_identity_seen ) : ?>
									<span class="ffc-identity-pair-seen">
										<?php
										printf(
											/* translators: %s: the date the login was last active. */
											esc_html__( 'Last used %s', 'ffcertificate' ),
											esc_html( $ffc_identity_seen )
										);
										?>
									</span>
								<?php else : ?>
									<?php // Not "never used": only the stores carrying a date column are read, so an absent value is an absent reading. ?>
									<span class="ffc-identity-pair-seen"><?php esc_html_e( 'No activity date recorded', 'ffcertificate' ); ?></span>
								<?php endif; ?>
							</p>
						<?php endforeach; ?>
					</fieldset>

					<?php
					// THE PREVIEW IS THE WRITE'S OWN COUNT, ASKED FOR BEFORE
					// THE WRITE. Its region carries the fixed sentences as
					// data attributes, the way the correction preflight does,
					// so every string is escaped at the output point.
					?>
					<p>
						<button type="button" class="button button-secondary ffc-identity-preview"
							data-ffc-a="<?php echo esc_attr( (string) $ffc_identity_who[0] ); ?>"
							data-ffc-b="<?php echo esc_attr( (string) $ffc_identity_who[1] ); ?>"
							data-ffc-region="ffc-merge-preview-<?php echo esc_attr( $ffc_identity_id ); ?>"
							data-ffc-ack="ffc-merge-ack-<?php echo esc_attr( $ffc_identity_id ); ?>"
							data-ffc-go="ffc-merge-go-<?php echo esc_attr( $ffc_identity_id ); ?>">
							<?php esc_html_e( 'Show what would move', 'ffcertificate' ); ?>
						</button>
					</p>

					<div class="ffc-identity-preview-region" id="ffc-merge-preview-<?php echo esc_attr( $ffc_identity_id ); ?>"
						aria-live="polite"
						<?php /* translators: 1: the surviving login's name. 2: the emptied login's name. */ ?>
						data-heading="<?php esc_attr_e( '%1$s keeps the records. %2$s is emptied.', 'ffcertificate' ); ?>"
						<?php /* translators: %s: how many records would move. */ ?>
						data-total="<?php esc_attr_e( '%s records move.', 'ffcertificate' ); ?>"
						<?php /* translators: 1: how many records. 2: the store they sit in. */ ?>
						data-store="<?php esc_attr_e( '%1$s in %2$s', 'ffcertificate' ); ?>"
						<?php /* translators: 1: how many memberships, places or permissions. 2: the relationship table they sit in. */ ?>
						data-grant="<?php esc_attr_e( '%1$s in %2$s move with them.', 'ffcertificate' ); ?>"
						<?php /* translators: %s: how many rows are dropped because the surviving login already had that pairing. */ ?>
						data-grant-duplicate="<?php esc_attr_e( '%s dropped, because the surviving login already had it.', 'ffcertificate' ); ?>"
						<?php /* translators: %s: the identifiers the surviving login would gain, comma separated. */ ?>
						data-gains="<?php esc_attr_e( 'The surviving login also gains the %s it did not hold.', 'ffcertificate' ); ?>"
						<?php /* translators: 1: a login's name. 2: how many records it holds. */ ?>
						data-holds="<?php esc_attr_e( '%1$s holds %2$s records today.', 'ffcertificate' ); ?>"
						data-choose="<?php esc_attr_e( 'Choose which login keeps the records first.', 'ffcertificate' ); ?>"
						data-failed="<?php esc_attr_e( 'The preview could not be completed.', 'ffcertificate' ); ?>"></div>

					<?php
					// A FIELD, NOT A BROWSER DIALOG. A `confirm()` is not
					// evidence and does not survive a screen without
					// JavaScript; this posts, and the handler refuses without
					// it rather than doing nothing.
					?>
					<p>
						<label>
							<input type="checkbox" name="ffc_ack" value="1" required
								id="ffc-merge-ack-<?php echo esc_attr( $ffc_identity_id ); ?>">
							<?php esc_html_e( 'I confirm these are one person, and that this cannot be undone.', 'ffcertificate' ); ?>
						</label>
					</p>
					<p>
						<button type="submit" class="button button-primary"
							id="ffc-merge-go-<?php echo esc_attr( $ffc_identity_id ); ?>">
							<?php esc_html_e( 'Merge this pair', 'ffcertificate' ); ?>
						</button>
					</p>
				</form>
			</div>
		<?php endforeach; ?>
		<p class="description">
			<?php esc_html_e( 'The records, the identity index and the surviving login\'s certificate access move together, as one transaction. The emptied login is left in place — removing it is yours to do in Users, because deleting an account runs cleanup this tool does not own and cannot undo.', 'ffcertificate' ); ?>
		</p>

		<?php
		// THE OTHER ANSWER, AND OFTEN THE RIGHT ONE.
		//
		// Two logins sharing a number whose names do not resemble each other
		// are almost never one person twice; they are a wrong document on one
		// of them. A merge would consolidate two people. So the correction is
		// offered beside the merge, per person, and it is scoped to that one
		// login -- the same rewrite applied to the shared hash without a scope
		// would correct BOTH, which is the defect wearing a fix's clothes.
		//
		// OUTSIDE the merge form, because a form cannot contain another.
		?>
		<?php foreach ( $ffc_identity_pairs as $ffc_identity_pair ) : ?>
			<?php
			$ffc_identity_who = IdentityConflictQuery::parse_accounts(
				$ffc_identity_pair[ IdentityConflictQuery::COLUMN_RELATED ] ?? ''
			);
			?>
			<?php if ( 2 !== count( $ffc_identity_who ) ) : ?>
				<?php continue; ?>
			<?php endif; ?>
			<div class="ffc-identity-correct-instead">
				<h4><?php esc_html_e( 'Or correct the document on one of them', 'ffcertificate' ); ?></h4>
				<p class="description">
					<?php esc_html_e( 'If the two names do not look like the same person, one of these logins has the wrong number rather than a duplicate account. Correcting it on that login alone dissolves the finding and leaves both accounts standing. Confirm the number with HR first — the check digit says a number is wrong, never what the right one is.', 'ffcertificate' ); ?>
				</p>
				<?php foreach ( $ffc_identity_who as $ffc_identity_account ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ffc-identity-correct-one">
						<?php wp_nonce_field( IdentityResolutionPage::REPAIR_NONCE . (string) ( $ffc_identity_pair['subject'] ?? '' ) ); ?>
						<input type="hidden" name="action" value="<?php echo esc_attr( IdentityResolutionPage::REPAIR_ACTION ); ?>">
						<input type="hidden" name="ffc_key" value="<?php echo esc_attr( (string) ( $ffc_identity_pair[ IdentityQueue::COLUMN_KEY ] ?? '' ) ); ?>">
						<input type="hidden" name="ffc_subject" value="<?php echo esc_attr( (string) ( $ffc_identity_pair['subject'] ?? '' ) ); ?>">
						<input type="hidden" name="ffc_field" value="<?php echo esc_attr( str_replace( '_hash', '', (string) ( $ffc_identity_pair['identifier_column'] ?? 'rf' ) ) ); ?>">
						<?php // The scope. Without it the rewrite would reach the other login's rows too, since both carry this hash. ?>
						<input type="hidden" name="ffc_account_scope" value="<?php echo esc_attr( (string) $ffc_identity_account ); ?>">
						<label for="ffc-correct-<?php echo esc_attr( (string) ( $ffc_identity_pair['subject'] ?? '' ) . '-' . (string) $ffc_identity_account ); ?>">
							<?php echo esc_html( $ffc_identity_named( $ffc_identity_account ) ); ?>
						</label>
						<?php // `text` with `inputmode`, never `number`: a leading zero is a digit here, not formatting. ?>
						<input type="text" inputmode="numeric" pattern="[0-9]{7,11}" maxlength="11" size="12" required
							id="ffc-correct-<?php echo esc_attr( (string) ( $ffc_identity_pair['subject'] ?? '' ) . '-' . (string) $ffc_identity_account ); ?>"
							name="ffc_rf" placeholder="<?php esc_attr_e( 'The confirmed number', 'ffcertificate' ); ?>">
						<button type="submit" class="button button-secondary">
							<?php esc_html_e( 'Correct theirs', 'ffcertificate' ); ?>
						</button>
					</form>
				<?php endforeach; ?>
			</div>
		<?php endforeach; ?>
		<?php $ffc_identity_foot(); ?>
	<?php endif; ?>

	<?php
	// THREE PANELS OVER ONE CARD, because what differs between them is the
	// verb offered, not the shape of the finding: all three are an account
	// holding more than one identifier. The tier label moved into the panel
	// header, so the `What is known` column below now says it once per panel
	// rather than once per row.
	//
	// The third is the shared mailbox (#1368), and it is here rather than in
	// a panel of its own precisely because the finding is the same shape --
	// what makes it a tier is that the verb column is empty by decision. A
	// separate layout would have made that look like a different kind of
	// finding, when it is the same finding the screen refuses to act on.
	$ffc_identity_account_tiers = array( IdentityQueue::TIER_MECHANICAL, IdentityQueue::TIER_DECISION, IdentityQueue::TIER_MAILBOX );
	?>
	<?php foreach ( $ffc_identity_account_tiers as $ffc_identity_this_tier ) : ?>
		<?php if ( ! isset( $ffc_identity_by_tier[ $ffc_identity_this_tier ] ) ) : ?>
			<?php continue; ?>
		<?php endif; ?>
		<?php
		$ffc_identity_panel     = $ffc_identity_by_tier[ $ffc_identity_this_tier ];
		$ffc_identity_accountal = $ffc_identity_shown( $ffc_identity_panel );

		$ffc_identity_head(
			$ffc_identity_panel,
			$ffc_identity_tier_label( (string) $ffc_identity_this_tier ),
			$ffc_identity_tier_note( (string) $ffc_identity_this_tier )
		);
		?>
		<?php
		// A CARD PER FINDING, NOT A ROW PER FINDING (#1407 sprint 2).
		//
		// The table asked the operator to read four cells and work out what
		// they meant together: two hashes in one column, a verdict beside
		// each, an account in another, and the verb in a fourth. What the
		// check digits had already decided was derivable from it and stated
		// nowhere. The card states it in a sentence and shows the two hashes
		// as the before and after of the write the button performs.
		//
		// The FORMS ARE UNTOUCHED -- same fields, same nonces, same actions,
		// moved into a column of their own. This sprint is markup and CSS.
		?>
		<div class="ffc-identity-cards">
		<?php foreach ( $ffc_identity_accountal as $ffc_identity_item ) : ?>
			<?php
			$ffc_identity_tier = (string) ( $ffc_identity_item[ IdentityQueue::COLUMN_TIER ] ?? '' );

			// The two orientations of one finding, and they are mirror
			// images: an account holding several numbers names the account
			// in `subject`, while a number held by several accounts names
			// the NUMBER there and the accounts in `related`. Reading one
			// shape for both is how an account id gets printed as a hash.
			if ( IdentityQueue::TIER_SHARED === $ffc_identity_tier ) {
				$ffc_identity_who   = IdentityConflictQuery::parse_accounts( $ffc_identity_item[ IdentityConflictQuery::COLUMN_RELATED ] ?? '' );
				$ffc_identity_which = array( (string) ( $ffc_identity_item['subject'] ?? '' ) => '' );
			} else {
				$ffc_identity_who   = IdentityConflictQuery::parse_accounts( $ffc_identity_item['subject'] ?? '' );
				$ffc_identity_which = (array) ( $ffc_identity_item[ IdentityQueue::COLUMN_VERDICTS ] ?? array() );
			}

			// The kind, as the operator says it. `RF` and `CPF` are proper
			// nouns here, so they are uppercased rather than translated --
			// the criterion `CLAUDE.md` states for a domain acronym inside an
			// English sentence.
			$ffc_identity_kind  = strtoupper( str_replace( '_hash', '', (string) ( $ffc_identity_item['identifier_column'] ?? '' ) ) );
			$ffc_identity_wrong = (string) ( $ffc_identity_item[ IdentityQueue::COLUMN_WRONG ] ?? '' );
			$ffc_identity_right = (string) ( $ffc_identity_item[ IdentityQueue::COLUMN_RIGHT ] ?? '' );

			// MECHANICAL IS THE ONLY TIER WITH AN ORDER TO SHOW.
			//
			// `IdentityQueue::tiered()` sets `wrong` and `right` only where
			// exactly one identifier failed and every other one was READ --
			// so the pair, and the arrow between them, exist precisely when
			// the write has a direction. Everywhere else the hashes are a
			// set, and drawing an arrow over them would assert a decision
			// the check digits refused to make.
			$ffc_identity_directed = IdentityQueue::TIER_MECHANICAL === $ffc_identity_tier
				&& '' !== $ffc_identity_wrong
				&& '' !== $ffc_identity_right;
			?>
			<div class="ffc-identity-card">
				<div class="ffc-identity-card-main">
					<p class="ffc-identity-card-who">
						<?php if ( array() === $ffc_identity_who ) : ?>
							<span class="description"><?php esc_html_e( 'No account', 'ffcertificate' ); ?></span>
						<?php else : ?>
							<?php foreach ( $ffc_identity_who as $ffc_identity_account ) : ?>
								<?php // The NAME, then the number. A finding an operator has to act on is about a person, and `#4821` is not a person. ?>
								<a href="<?php echo esc_url( admin_url( 'user-edit.php?user_id=' . rawurlencode( (string) $ffc_identity_account ) ) ); ?>">
									<?php echo esc_html( $ffc_identity_named( $ffc_identity_account ) ); ?>
								</a>
							<?php endforeach; ?>
						<?php endif; ?>
					</p>

					<p class="ffc-identity-card-says">
						<?php if ( $ffc_identity_directed ) : ?>
							<?php
							printf(
								/* translators: %s: RF or CPF. */
								esc_html__( 'This account holds two %s. One fails its own check digit and the other is sound, so the right one is the one the account already has.', 'ffcertificate' ),
								esc_html( $ffc_identity_kind )
							);
							?>
						<?php elseif ( IdentityQueue::TIER_MAILBOX === $ffc_identity_tier ) : ?>
							<?php
							// THE COUNT IS THE EVIDENCE HERE, NOT A DETAIL.
							//
							// The reading turns on how many numbers one
							// address carries: at two it is a person who
							// typed twice, and at nine it is not. So the
							// sentence leads with the number rather than
							// mentioning it, and says what the screen cannot
							// decide instead of what an operator should do.
							printf(
								/* translators: 1: how many identifiers the account holds. 2: RF or CPF. */
								esc_html__( 'This account holds %1$s %2$s under one address, and they are not variants of each other. One address on that many numbers does not identify one person, so this is a shared mailbox or an account submitting for other people — which of the two, only the account itself says.', 'ffcertificate' ),
								esc_html( number_format_i18n( count( $ffc_identity_which ) ) ),
								esc_html( $ffc_identity_kind )
							);
							?>
							<?php
							// THE CAUTION CAME UP FROM THE IDENTIFIERS, WHERE IT REPEATED (#1464).
							//
							// It is about the CARD -- this account may legitimately hold
							// what it holds -- so it belongs beside the sentence that
							// describes the account, not above each route. Its own
							// sentence rather than folded into the one above, which is a
							// translated string saying something else and would have to
							// be retranslated to carry this.
							?>
							<?php esc_html_e( 'Read what it submitted before either route: the records may legitimately be its own.', 'ffcertificate' ); ?>
						<?php else : ?>
							<?php
							printf(
								/* translators: 1: how many identifiers the account holds. 2: RF or CPF. */
								esc_html__( 'This account holds %1$s %2$s, and the check digits do not single one out — none fails, or more than one does. The right value comes from HR.', 'ffcertificate' ),
								esc_html( number_format_i18n( count( $ffc_identity_which ) ) ),
								esc_html( $ffc_identity_kind )
							);
							?>
						<?php endif; ?>
					</p>

					<?php
					// THE HASHES, IN THE ORDER THE WRITE READS THEM.
					//
					// Ordered rather than iterated where there is a
					// direction, so the chip that goes away is first and the
					// chip that survives is second -- which is what makes the
					// arrow mean the same thing as the button.
					$ffc_identity_chips = $ffc_identity_directed
						? array(
							$ffc_identity_wrong => IdentityConflictQuery::VERDICT_INVALID,
							$ffc_identity_right => IdentityConflictQuery::VERDICT_VALID,
						)
						: $ffc_identity_which;
					$ffc_identity_first = true;
					?>
					<div class="ffc-identity-card-hashes">
						<?php foreach ( $ffc_identity_chips as $ffc_identity_hash => $ffc_identity_verdict ) : ?>
							<?php if ( $ffc_identity_directed && ! $ffc_identity_first ) : ?>
								<?php // `aria-hidden`, because the sentence above already says which of the two survives and each chip carries its own verdict in words. ?>
								<span class="ffc-identity-card-arrow" aria-hidden="true">&rarr;</span>
							<?php endif; ?>
							<?php
							$ffc_identity_first = false;

							if ( IdentityConflictQuery::VERDICT_INVALID === $ffc_identity_verdict ) {
								$ffc_identity_tone = 'bad';
								/* translators: %s: RF or CPF. */
								$ffc_identity_said = sprintf( __( '%s · fails its check digit', 'ffcertificate' ), $ffc_identity_kind );
							} elseif ( IdentityConflictQuery::VERDICT_VALID === $ffc_identity_verdict ) {
								$ffc_identity_tone = 'ok';
								/* translators: %s: RF or CPF. */
								$ffc_identity_said = sprintf( __( '%s · well formed', 'ffcertificate' ), $ffc_identity_kind );
							} elseif ( IdentityConflictQuery::VERDICT_UNREADABLE === $ffc_identity_verdict ) {
								// NOT A FAILURE, AND IT MUST NOT LOOK LIKE ONE.
								// A value nobody could read is a value nothing
								// is known about -- painting it red would make
								// the screen assert the opposite.
								$ffc_identity_tone = 'unknown';
								/* translators: %s: RF or CPF. */
								$ffc_identity_said = sprintf( __( '%s · could not be read', 'ffcertificate' ), $ffc_identity_kind );
							} elseif ( IdentityConflictQuery::VERDICT_ABSENT === $ffc_identity_verdict ) {
								$ffc_identity_tone = 'unknown';
								/* translators: %s: RF or CPF. */
								$ffc_identity_said = sprintf( __( '%s · not stored where this can read', 'ffcertificate' ), $ffc_identity_kind );
							} else {
								$ffc_identity_tone = 'plain';
								$ffc_identity_said = $ffc_identity_kind;
							}
							?>
							<span class="ffc-identity-card-hash ffc-identity-card-hash-<?php echo esc_attr( $ffc_identity_tone ); ?>">
								<code><?php echo esc_html( substr( (string) $ffc_identity_hash, 0, IdentityQueue::DISPLAY_PREFIX ) ); ?></code>
								<span class="ffc-identity-card-said"><?php echo esc_html( $ffc_identity_said ); ?></span>
							</span>
						<?php endforeach; ?>
					</div>

					<?php
					// THE STORES, AND DELIBERATELY NO RECORD COUNT.
					//
					// The mockup's card ends with a count of records. There
					// is none to show here: `ALIAS_ROW_COUNT` is produced only
					// by the RF check-digit scan, and neither
					// `multiple_identities()` nor `shared_identities()`
					// selects it. Widening those queries to invent one was
					// weighed and refused -- the count would be per hash
					// rather than per finding, which is not the number the
					// sentence above would imply. The stores are what say how
					// far the write reaches, and they are read rather than
					// derived.
					$ffc_identity_where = $ffc_identity_store_list( $ffc_identity_item[ IdentityConflictQuery::COLUMN_STORES ] ?? '' );
					?>
					<?php if ( '' !== $ffc_identity_where ) : ?>
						<p class="ffc-identity-card-where">
							<?php
							printf(
								/* translators: %s: the stores holding the records, comma separated. */
								esc_html__( 'Appears in %s', 'ffcertificate' ),
								esc_html( $ffc_identity_where )
							);
							?>
						</p>
					<?php endif; ?>
				</div>
				<div class="ffc-identity-card-act">
					<?php if ( IdentityQueue::TIER_MECHANICAL === $ffc_identity_tier ) : ?>
						<?php
						$ffc_identity_wrong = (string) ( $ffc_identity_item[ IdentityQueue::COLUMN_WRONG ] ?? '' );
						$ffc_identity_right = (string) ( $ffc_identity_item[ IdentityQueue::COLUMN_RIGHT ] ?? '' );
						?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( IdentityResolutionPage::CONSOLIDATE_NONCE . $ffc_identity_wrong ); ?>
							<input type="hidden" name="action" value="<?php echo esc_attr( IdentityResolutionPage::CONSOLIDATE_ACTION ); ?>">
							<input type="hidden" name="ffc_key" value="<?php echo esc_attr( (string) ( $ffc_identity_item[ IdentityQueue::COLUMN_KEY ] ?? '' ) ); ?>">
							<input type="hidden" name="ffc_subject" value="<?php echo esc_attr( $ffc_identity_wrong ); ?>">
							<?php
							// TWO HASHES AND NO VALUE.
							//
							// The number to write is the account's sound
							// identifier, which the service reads in
							// memory. Posting the value instead would put
							// a stored RF or CPF through the browser for
							// no reason -- and there is nothing here an
							// operator needs to read, which is what makes
							// this one click rather than a question.
							?>
							<input type="hidden" name="ffc_target" value="<?php echo esc_attr( $ffc_identity_right ); ?>">
							<input type="hidden" name="ffc_field" value="<?php echo esc_attr( str_replace( '_hash', '', (string) ( $ffc_identity_item['identifier_column'] ?? '' ) ) ); ?>">
							<?php
							// PRIMARY, BECAUSE THIS CARD HAS ONE VERB.
							//
							// The whole tier is "the account already holds the
							// right number" -- there is nothing to type and no
							// second path, so the only question is whether to
							// act. The decision tier below is deliberately the
							// opposite: two peers, neither promoted, because
							// promoting one of two destinations is the screen
							// answering a question it is asking.
							?>
							<button type="submit" class="button button-primary">
								<?php esc_html_e( 'Consolidate', 'ffcertificate' ); ?>
							</button>
						</form>
						<?php
						// THE SHARED MAILBOX GETS THE SAME TWO VERBS, AND NOT THE THIRD.
						//
						// #1368 withheld all three here, for a harm it named
						// precisely: verbs `that would write one person's number
						// onto another person's records`. That describes CONSOLIDATE,
						// which rewrites one identifier into another, and it is why
						// consolidate stays absent. It does not describe these two:
						// a move relocates records without touching the identifier,
						// and a split creates an account nobody else uses. The sweep
						// removed three verbs for a reason that justified one.
						//
						// The view's own refusal argued something different again --
						// that splitting `would detach records from an account that
						// may legitimately hold them`. That is a sound CAUTION and
						// was doing duty as a prohibition: the same sentence told
						// the operator to `decide with HR`, and then the screen had
						// nowhere to put the answer. What makes the decision
						// deliberate is the address the split makes them type and
						// the acknowledgement the move makes them tick, not the
						// absence of a button.
						//
						// `IdentityMailboxVerbsTest` holds the shape: these two and
						// never consolidate.
						?>
					<?php elseif ( IdentityQueue::TIER_DECISION === $ffc_identity_tier || IdentityQueue::TIER_MAILBOX === $ffc_identity_tier ) : ?>
						<?php
						$ffc_identity_field = str_replace( '_hash', '', (string) ( $ffc_identity_item['identifier_column'] ?? '' ) );
						?>
						<?php
						// ONE GROUP PER IDENTIFIER, BECAUSE THE COLUMN HOLDS
						// TWO VERBS PER IDENTIFIER AND SHOWED NEITHER WHOSE.
						//
						// This tier is an account holding several numbers, so
						// the column renders MOVE and SPLIT once each per
						// number -- eight controls for two numbers, with the
						// only clue to which belonged to which being the hash
						// inside one button's label. The heading says it
						// instead, and the sentence that explains the two
						// verbs sits with them rather than in a paragraph at
						// the foot of the panel.
						?>
						<?php foreach ( array_keys( $ffc_identity_which ) as $ffc_identity_move ) : ?>
							<div class="ffc-identity-card-verb">
								<p class="ffc-identity-card-verb-head">
									<code><?php echo esc_html( substr( (string) $ffc_identity_move, 0, IdentityQueue::DISPLAY_PREFIX ) ); ?></code>
									<span class="ffc-identity-card-said"><?php echo esc_html( $ffc_identity_kind ); ?></span>
								</p>
							<?php
							// TWO ROUTES TO ONE DESTINATION, EACH NAMED -- NOT A SENTENCE ABOUT BOTH (#1464).
							//
							// What stood here was `Move it to the account it belongs to,
							// or split it onto an account of its own`, printed above every
							// identifier of the account -- so a card with four numbers said
							// it four times, as if it were instructions for operating two
							// forms.
							//
							// The origin is one and the destination is one; where the origin
							// carries several records the destination is decided one
							// identifier at a time. So these are not two destinations
							// competing -- they are the two ways of reaching the single one,
							// and naming each route on the control that takes it is what the
							// repeated sentence was failing to do.
							//
							// The shared-mailbox caution left with it and did not disappear:
							// it is about the CARD, not about either route, so it sits once,
							// beside the sentence that describes the account.
							?>
							<p class="ffc-identity-card-route"><?php esc_html_e( 'To an account that already exists', 'ffcertificate' ); ?></p>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ffc-set-mb-2xs"
								id="ffc-relink-form-<?php echo esc_attr( (string) $ffc_identity_move ); ?>">
								<?php wp_nonce_field( IdentityResolutionPage::RELINK_NONCE . (string) $ffc_identity_move ); ?>
								<input type="hidden" name="action" value="<?php echo esc_attr( IdentityResolutionPage::RELINK_ACTION ); ?>">
								<input type="hidden" name="ffc_key" value="<?php echo esc_attr( (string) ( $ffc_identity_item[ IdentityQueue::COLUMN_KEY ] ?? '' ) ); ?>">
							<input type="hidden" name="ffc_subject" value="<?php echo esc_attr( (string) $ffc_identity_move ); ?>">
								<input type="hidden" name="ffc_field" value="<?php echo esc_attr( $ffc_identity_field ); ?>">
								<label class="screen-reader-text" for="ffc-relink-<?php echo esc_attr( (string) $ffc_identity_move ); ?>">
									<?php
									printf(
										/* translators: %s: the identifier's hash prefix. */
										esc_html__( 'Account to move the records carrying %s to', 'ffcertificate' ),
										esc_html( substr( (string) $ffc_identity_move, 0, IdentityQueue::DISPLAY_PREFIX ) )
									);
									?>
								</label>
								<?php
								// A numeric account id, typed — the operator
								// arrives from the audit export, which names
								// accounts by id and links to `user-edit.php`.
								//
								// `required` because each row carries its OWN
								// form: an empty field cannot mean "leave this
								// alone" when submitting is already the way to
								// act on one row, and a cleared number field
								// posts the empty string that `absint()` reads
								// as zero (#1114).
								?>
								<input type="number" inputmode="numeric" min="1" step="1" size="6" required
									id="ffc-relink-<?php echo esc_attr( (string) $ffc_identity_move ); ?>"
									name="ffc_account" placeholder="<?php esc_attr_e( 'Account #', 'ffcertificate' ); ?>">
								<?php
								// THE NUMBER FIELD STAYS, AND THAT IS THE POINT.
								//
								// The dialog writes into it rather than
								// replacing it, so the form posts the same
								// thing it always did and a screen without
								// JavaScript keeps the verb it had. What the
								// dialog adds is knowing the answer before
								// committing, which is not the same as being
								// the only way to give one.
								?>
								<button type="button" class="button button-secondary ffc-identity-find"
									data-ffc-subject="<?php echo esc_attr( (string) $ffc_identity_move ); ?>"
									data-ffc-field="<?php echo esc_attr( $ffc_identity_field ); ?>"
									data-ffc-input="ffc-relink-<?php echo esc_attr( (string) $ffc_identity_move ); ?>"
									data-ffc-split="ffc-split-form-<?php echo esc_attr( (string) $ffc_identity_move ); ?>"
									data-ffc-submit="ffc-relink-go-<?php echo esc_attr( (string) $ffc_identity_move ); ?>">
									<span class="dashicons dashicons-search" aria-hidden="true"></span>
									<?php esc_html_e( 'Search…', 'ffcertificate' ); ?>
								</button>
								<?php
								// THE HASH LEAVES THE LABEL AND STAYS IN THE NAME.
								//
								// It was in the label because nothing else on
								// the card said WHICH number a verb acted on --
								// and then #1407 gave each identifier its own
								// group with the hash in the heading directly
								// above, which made the label the second place
								// it was written.
								//
								// MEASURED, BECAUSE THE OBVIOUS REASON IS NOT
								// THE REAL ONE. This looked like it would fix
								// two groups wrapping to different heights; it
								// does not, and could not -- every hash is
								// truncated to the same length, so the two
								// labels were always the same width and the
								// groups always matched. What the shorter label
								// actually buys is a WRAP ROW, and only in a
								// band: rendered in Chromium at 1920 / 1600 /
								// 1440 the card is 153px either way, at 1100 it
								// is 211px with the hash and 171 without, and at
								// 960 and below it is 211 either way. One row
								// of twelve screens' worth of width.
								//
								// What it cannot simply lose is the DISTINCTION:
								// a screen reader announcing two bare "Move"
								// buttons gives an operator no way to tell them
								// apart, and a heading two elements away is not
								// part of either name. So the hash moves into
								// the name rather than out of it.
								?>
								<?php if ( IdentityQueue::TIER_MAILBOX === $ffc_identity_tier ) : ?>
									<?php
									// THE ACKNOWLEDGEMENT IS ON THE MOVE, NOT ON THE SPLIT.
									//
									// It sits on the verb that cannot be undone. After a
									// split the records are alone on an account nobody else
									// uses, so a wrong split is still a group somebody can
									// move onward; after a move they are mixed with another
									// person's and telling the two apart stops being
									// possible from the data -- the same reason the merge
									// refuses a disagreement rather than warning about one.
									//
									// The split needs none: the address it makes the
									// operator type is already its deliberateness gate,
									// which is what `IdentitySplit`'s own docblock argues.
									//
									// `required` is safe although the form can be barred,
									// because it is barred by `disabled` and a disabled
									// control is exempt from constraint validation -- the
									// #1114 rule, relied on here rather than re-derived.
									?>
									<?php
									// A CLASS, BECAUSE THE SHEET IT BELONGS IN ALREADY EXISTS.
									//
									// #1461 shipped this label bare, on a comment claiming
									// that not one `.ffc-identity-` rule existed anywhere.
									// There are 152 of them, in `ffc-admin.css`, and they
									// were there the whole time -- the search that said
									// otherwise had failed rather than found nothing, and
									// an error read as a clean result is the one mistake
									// this screen's guards exist to make impossible.
									//
									// Bare, the label had no alignment and no measure: the
									// checkbox sat on the first line and the sentence ran
									// the full width of the verb column, crossing the
									// button beneath it. Two lines of CSS fix it, next to
									// the rules that already size this column.
									?>
									<label class="ffc-identity-ack">
										<input type="checkbox" required name="ffc_acknowledged" value="1">
										<?php esc_html_e( 'HR confirmed these records are this person’s, not the shared account’s.', 'ffcertificate' ); ?>
									</label>
								<?php endif; ?>
								<button type="submit" class="button button-secondary"
									id="ffc-relink-go-<?php echo esc_attr( (string) $ffc_identity_move ); ?>">
									<?php esc_html_e( 'Move', 'ffcertificate' ); ?>
									<span class="screen-reader-text">
										<?php echo esc_html( substr( (string) $ffc_identity_move, 0, IdentityQueue::DISPLAY_PREFIX ) ); ?>
									</span>
								</button>
								<span class="ffc-identity-chosen" id="ffc-relink-chosen-<?php echo esc_attr( (string) $ffc_identity_move ); ?>" hidden></span>
								<?php if ( IdentityQueue::TIER_MAILBOX === $ffc_identity_tier ) : ?>
									<?php // Shown by the address field, which bars this verb rather than the other way round -- see the split form below for why the precedence is inverted here. ?>
									<span class="ffc-identity-move-barred description" hidden>
										<?php esc_html_e( 'An address for a new account is typed, so splitting takes precedence: a split can still be corrected afterwards and a move cannot. Clear the address to move instead.', 'ffcertificate' ); ?>
									</span>
								<?php endif; ?>
							</form>
							<?php // Only the split goes when the capability is absent: moving is the other verb on this identifier and stays available. ?>
							<?php if ( $ffc_identity_may_split ) : ?>
							<p class="ffc-identity-card-route"><?php esc_html_e( 'Or to a new account', 'ffcertificate' ); ?></p>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ffc-set-mb-xs"
								id="ffc-split-form-<?php echo esc_attr( (string) $ffc_identity_move ); ?>">
								<?php wp_nonce_field( IdentityResolutionPage::SPLIT_NONCE . (string) $ffc_identity_move ); ?>
								<input type="hidden" name="action" value="<?php echo esc_attr( IdentityResolutionPage::SPLIT_ACTION ); ?>">
								<input type="hidden" name="ffc_key" value="<?php echo esc_attr( (string) ( $ffc_identity_item[ IdentityQueue::COLUMN_KEY ] ?? '' ) ); ?>">
							<input type="hidden" name="ffc_subject" value="<?php echo esc_attr( (string) $ffc_identity_move ); ?>">
								<input type="hidden" name="ffc_field" value="<?php echo esc_attr( $ffc_identity_field ); ?>">
								<label class="screen-reader-text" for="ffc-split-<?php echo esc_attr( (string) $ffc_identity_move ); ?>">
									<?php
									printf(
										/* translators: %s: the identifier's hash prefix. */
										esc_html__( 'E-mail address for the new account holding %s', 'ffcertificate' ),
										esc_html( substr( (string) $ffc_identity_move, 0, IdentityQueue::DISPLAY_PREFIX ) )
									);
									?>
								</label>
								<?php
								// An address the OPERATOR supplies, because there
								// is none to inherit: WordPress requires
								// `user_email` to be unique and every production
								// finding reports both identifiers sharing the
								// address the existing account already uses.
								?>
								<?php
								// ON THE SHARED MAILBOX THE PRECEDENCE IS INVERTED, DELIBERATELY.
								//
								// Everywhere else, choosing a destination bars the split:
								// two destinations for one set of records is a mistake the
								// form should not express, and which half yields is
								// arbitrary there. Here it is not arbitrary. A split leaves
								// the records alone on a fresh account, so a wrong one is
								// still correctable; a move mixes them into somebody else's
								// and the data can no longer separate them. So the address
								// bars the move, and `data-ffc-prefer-split` is what tells
								// the search dialog to leave this split alone when a
								// destination is picked.
								//
								// The capability edge closes itself: this whole form is
								// inside `if ( $ffc_identity_may_split )`, so an operator
								// who cannot split has no address field, nothing bars the
								// move, and the precedence cannot strand them.
								?>
								<input type="email" size="22" required
									id="ffc-split-<?php echo esc_attr( (string) $ffc_identity_move ); ?>"
									<?php if ( IdentityQueue::TIER_MAILBOX === $ffc_identity_tier ) : ?>
									data-ffc-prefer-split="1"
									data-ffc-move="ffc-relink-form-<?php echo esc_attr( (string) $ffc_identity_move ); ?>"
									data-ffc-move-submit="ffc-relink-go-<?php echo esc_attr( (string) $ffc_identity_move ); ?>"
									<?php endif; ?>
									name="ffc_email" placeholder="<?php esc_attr_e( 'New account e-mail', 'ffcertificate' ); ?>">
								<button type="submit" class="button button-secondary">
									<?php esc_html_e( 'Split off', 'ffcertificate' ); ?>
								</button>
								<?php
								// Printed disabled-free and hidden; the dialog
								// shows it and disables the fields above when a
								// destination is chosen, because two destinations
								// for one set of records is a mistake the form
								// should not be able to express.
								?>
								<span class="ffc-identity-split-barred description" hidden>
									<?php esc_html_e( 'A destination account is chosen, so splitting onto a new one is not available. Clear the destination to split instead.', 'ffcertificate' ); ?>
								</span>
							</form>
							<?php endif; ?>
							</div>
						<?php endforeach; ?>
					<?php else : ?>
						<span class="description">
							<?php esc_html_e( 'Open the account — this one is not decided here.', 'ffcertificate' ); ?>
						</span>
					<?php endif; ?>
				</div>
			</div>
		<?php endforeach; ?>
		</div>
		<?php
		// WHAT SURVIVED OF THE FOUR-SENTENCE PARAGRAPH, AND WHY ONLY THIS (#1464).
		//
		// It explained all three verbs in the abstract, under every panel that
		// offers any -- three tiers, so up to three copies of the same four
		// sentences on one page. Three of those sentences described what a verb
		// does, which each control now says about the case in front of the
		// operator, in a line attached to itself.
		//
		// This one is the exception because it is a property of the SCREEN and
		// not of a verb: every write here is one transaction and none of them
		// prints a stored number. A card cannot claim that about the others,
		// so it stays, once.
		?>
		<p class="description">
			<?php esc_html_e( 'Every action on this screen runs as a single transaction, rolled back whole if any part of it refuses, and none of them shows a stored number.', 'ffcertificate' ); ?>
		</p>
		<?php if ( IdentityQueue::TIER_MAILBOX === $ffc_identity_this_tier ) : ?>
			<?php
			// The absence of a verb cannot be explained by a control that is
			// not there, so this half stays at the panel. The precedence left:
			// the card shows it, by promoting the verb that wins and barring
			// the other, which is more legible than a sentence about it.
			?>
			<p class="description">
				<?php esc_html_e( 'Consolidating is not offered on this panel: it rewrites one identifier into another, and where the identifiers belong to different people that writes one person’s number onto another person’s records. Moving and splitting both leave every number as it is.', 'ffcertificate' ); ?>
			</p>
		<?php endif; ?>
		<?php $ffc_identity_foot(); ?>
	<?php endforeach; ?>

	<?php
	$ffc_identity_rows = array();

	if ( isset( $ffc_identity_by_tier[ IdentityQueue::TIER_ISOLATED ] ) ) {
		$ffc_identity_panel = $ffc_identity_by_tier[ IdentityQueue::TIER_ISOLATED ];
		$ffc_identity_rows  = $ffc_identity_shown( $ffc_identity_panel );

		$ffc_identity_head(
			$ffc_identity_panel,
			$ffc_identity_tier_label( IdentityQueue::TIER_ISOLATED ),
			__( 'A stored RF whose own check digit does not match, and which no account-side finding above explains: somebody mistyped once on their only row, or the row belongs to a candidacy that carries no account until promotion. The correct value comes from HR.', 'ffcertificate' )
		);
	}
	?>

	<?php if ( array() === $ffc_identity_panels ) : ?>
		<?php
		// AN EMPTY LIST MEANS THREE DIFFERENT THINGS AND ONLY ONE IS GOOD NEWS.
		//
		// The scan returns failures, so it returns none when no store carries
		// the columns it needs, when nothing it read could be decrypted, and
		// when every value is genuinely fine. Reporting the third when it was
		// one of the first two is the `#1071` / `#1094` rule broken on a
		// screen instead of in a guard.
		// Read once, above, where the strip states them: three names for one
		// set of numbers is how a screen comes to disagree with itself.
		?>
		<?php if ( 0 === $ffc_identity_stores ) : ?>
			<?php
			wp_admin_notice(
				esc_html__( 'Nothing was scanned: no store on this install carries an RF in a form this check can read, which needs the hash, the ciphertext and a row id on the same table. This is not a clean result.', 'ffcertificate' ),
				array(
					'type'               => 'warning',
					'additional_classes' => array( 'inline' ),
				)
			);
			?>
		<?php elseif ( $ffc_identity_examined > 0 && $ffc_identity_examined === $ffc_identity_unreadable ) : ?>
			<?php
			wp_admin_notice(
				esc_html(
					sprintf(
						/* translators: %s: how many distinct stored values were found. */
						__( 'Found %s stored values and could not read any of them, so nothing was checked. That is what an encryption key which does not match this data looks like — this is not a clean result.', 'ffcertificate' ),
						number_format_i18n( $ffc_identity_examined )
					)
				),
				array(
					'type'               => 'error',
					'additional_classes' => array( 'inline' ),
				)
			);
			?>
		<?php elseif ( 0 === $ffc_identity_examined ) : ?>
			<?php
			// FOUR STATES, NOT THREE. The first pass at this fixed the two
			// obvious unread cases and left THIS one falling into the
			// reassuring branch, which then claimed zero values checked and
			// all of them sound -- vacuous, and it reads as reassurance.
			// (Phrased without quoting that sentence: a test below anchors on
			// it, and prose that repeats a literal is the same trap this
			// repository records for suppression scanners.) Zero examined is
			// not a clean result: it
			// says no row in any scanned store carries both a hash and a
			// ciphertext. On a fresh or test install that is ordinary; on an
			// install with submissions it is a signal.
			wp_admin_notice(
				esc_html(
					sprintf(
						/* translators: %s: how many stores were scanned. */
						__( 'No stored RF was found at all: none of the %s stores scanned holds a row with both a hash and a ciphertext, so there was nothing to check. Ordinary on an install that has not captured an RF yet — worth looking into on one that has.', 'ffcertificate' ),
						number_format_i18n( $ffc_identity_stores )
					)
				),
				array(
					'type'               => 'info',
					'additional_classes' => array( 'inline' ),
				)
			);
			?>
		<?php else : ?>
			<p class="description">
				<?php
				printf(
					/* translators: 1: values checked, 2: values that could not be read. */
					esc_html__( 'Nothing to resolve: %1$s stored values checked and every one satisfies its check digit. %2$s could not be read and were not checked.', 'ffcertificate' ),
					esc_html( number_format_i18n( $ffc_identity_examined ) ),
					esc_html( number_format_i18n( $ffc_identity_unreadable ) )
				);
				?>
			</p>
		<?php endif; ?>
	<?php elseif ( array() !== $ffc_identity_rows ) : ?>
		<?php
		// THE SAME CARD AS THE ACCOUNT TIERS, OVER A FINDING OF A DIFFERENT
		// SHAPE (#1407 sprint 3).
		//
		// What this tier has and they do not is a COUNT: the check-digit scan
		// selects `ALIAS_ROW_COUNT` and the row ids per store, so the card can
		// say how far a correction reaches. The account tiers carry no such
		// number and a test forbids inventing one there -- the two cards are
		// deliberately not identical, and making them match is the temptation
		// that rule exists against.
		//
		// The FORM IS UNTOUCHED: same fields, same nonce, same action, same
		// `data-ffc-*` hooks the preflight binds to.
		?>
		<div class="ffc-identity-cards">
		<?php foreach ( $ffc_identity_rows as $ffc_identity_row ) : ?>
			<?php
			$ffc_identity_accounts = IdentityConflictQuery::parse_accounts(
				$ffc_identity_row[ IdentityConflictQuery::COLUMN_RELATED ] ?? ''
			);
			$ffc_identity_by_store = IdentityConflictQuery::parse_row_ids(
				$ffc_identity_row[ IdentityConflictQuery::COLUMN_ROW_IDS ] ?? ''
			);
			// NOT `$ffc_identity_who`: the shared panel already uses that
			// name for its pair of ACCOUNTS, and PHP does not scope a
			// `foreach`. Two loops in one file holding different things
			// under one name is how a blind edit lands in the wrong form.
			$ffc_identity_person_names = $ffc_identity_names(
				(string) ( $ffc_identity_row['subject'] ?? '' ),
				str_replace( '_hash', '', (string) ( $ffc_identity_row['identifier_column'] ?? 'rf_hash' ) )
			);
			$ffc_identity_kind         = strtoupper( str_replace( '_hash', '', (string) ( $ffc_identity_row['identifier_column'] ?? 'rf_hash' ) ) );
			?>
			<div class="ffc-identity-card">
				<div class="ffc-identity-card-main">
					<p class="ffc-identity-card-who">
						<?php
						// FROM THE RECORD, NEVER FROM AN ACCOUNT.
						//
						// This tier's whole shape is a failure no account
						// explains, so there is often no account to ask: a
						// recruitment candidacy carries no WordPress user
						// until it is promoted. The stores hold the name
						// themselves.
						//
						// Three outcomes, and the last two are NOT the same.
						// A name; no name recorded, which a submission-only
						// finding always gives because that store keeps the
						// name inside its `data` JSON; or no store this can
						// read being present at all, which is not a result.
						?>
						<?php if ( array() !== $ffc_identity_person_names['names'] ) : ?>
							<?php echo esc_html( implode( ', ', array_map( 'strval', $ffc_identity_person_names['names'] ) ) ); ?>
							<?php if ( ! empty( $ffc_identity_person_names['capped'] ) ) : ?>
								<span class="description"><?php esc_html_e( 'and more', 'ffcertificate' ); ?></span>
							<?php endif; ?>
						<?php elseif ( empty( $ffc_identity_person_names['readable'] ) ) : ?>
							<span class="description">
								<?php esc_html_e( 'No store that records a name is installed, so this was not looked up.', 'ffcertificate' ); ?>
							</span>
						<?php else : ?>
							<span class="description">
								<?php esc_html_e( 'No name recorded beside these rows.', 'ffcertificate' ); ?>
							</span>
						<?php endif; ?>
					</p>

					<?php
					// NO SENTENCE ON THIS CARD, DELIBERATELY.
					//
					// The panel note above already says what this tier is, in
					// almost the same words -- and unlike the account tiers
					// there is nothing per-finding for a sentence to add: what
					// varies here is which accounts carry the value and how
					// many rows do, and both are shown as themselves below.
					// A card that repeats its own heading once per finding is
					// prose an operator learns to skip.
					?>
					<div class="ffc-identity-card-hashes">
						<span class="ffc-identity-card-hash ffc-identity-card-hash-bad">
							<code><?php echo esc_html( substr( (string) ( $ffc_identity_row['subject'] ?? '' ), 0, IdentityQueue::DISPLAY_PREFIX ) ); ?></code>
							<span class="ffc-identity-card-said">
								<?php
								printf(
									/* translators: %s: RF or CPF. */
									esc_html__( '%s · fails its check digit', 'ffcertificate' ),
									esc_html( $ffc_identity_kind )
								);
								?>
							</span>
						</span>
						<?php
						// THE ACCOUNTS ARE EVIDENCE HERE, NOT THE SUBJECT.
						//
						// A finding of this tier is about a stored VALUE. How
						// many logins happen to carry it is what decides
						// whether one corrected number can serve them all,
						// which is why it sits beside the hash rather than in
						// a column of its own.
						?>
						<?php if ( array() === $ffc_identity_accounts ) : ?>
							<span class="ffc-identity-card-said description"><?php esc_html_e( 'No account', 'ffcertificate' ); ?></span>
						<?php else : ?>
							<?php foreach ( $ffc_identity_accounts as $ffc_identity_account ) : ?>
								<a href="<?php echo esc_url( admin_url( 'user-edit.php?user_id=' . rawurlencode( (string) $ffc_identity_account ) ) ); ?>">
									<?php echo esc_html( $ffc_identity_named( $ffc_identity_account ) ); ?>
								</a>
							<?php endforeach; ?>
						<?php endif; ?>
					</div>

					<p class="ffc-identity-card-where">
						<?php
						// THE COUNT THIS TIER ACTUALLY HAS. `ALIAS_ROW_COUNT`
						// is selected by the check-digit scan, so it is read
						// rather than derived -- and it is per FINDING, which
						// is what makes it sayable in the same breath as the
						// stores.
						printf(
							/* translators: 1: the stores holding the records, comma separated. 2: how many records carry the value. */
							esc_html__( 'Appears in %1$s · %2$s records', 'ffcertificate' ),
							esc_html( $ffc_identity_store_list( $ffc_identity_row[ IdentityConflictQuery::COLUMN_STORES ] ?? '' ) ),
							esc_html( number_format_i18n( (int) ( $ffc_identity_row[ IdentityConflictQuery::ALIAS_ROW_COUNT ] ?? 0 ) ) )
						);
						?>
					</p>

					<?php
					// THE ROW IDS ARE THE ONLY HANDLE ON A RECORD WITH NO
					// ACCOUNT, so they stay -- and the truncation notice stays
					// with them, because a partial list of handles that does
					// not say it is partial is the `#1071` rule again.
					?>
					<p class="ffc-identity-card-rows">
						<?php foreach ( $ffc_identity_by_store as $ffc_identity_store => $ffc_identity_ids ) : ?>
							<span class="ffc-identity-card-rowset">
								<strong><?php echo esc_html( $ffc_identity_store_name( $ffc_identity_store ) ); ?></strong>
								<code><?php echo esc_html( implode( ', ', array_map( 'strval', $ffc_identity_ids ) ) ); ?></code>
							</span>
						<?php endforeach; ?>
						<?php if ( ! empty( $ffc_identity_row[ IdentityConflictQuery::COLUMN_ROW_IDS_TRUNCATED ] ) ) : ?>
							<span class="description"><?php esc_html_e( 'Too many rows to list.', 'ffcertificate' ); ?></span>
						<?php endif; ?>
					</p>
				</div>
				<div class="ffc-identity-card-act">
					<?php if ( count( $ffc_identity_accounts ) > 1 ) : ?>
						<span class="description">
							<?php esc_html_e( 'Names more than one account — the same wrong number was typed by more than one person, so one corrected value cannot serve it.', 'ffcertificate' ); ?>
						</span>
					<?php else : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( IdentityResolutionPage::REPAIR_NONCE . (string) ( $ffc_identity_row['subject'] ?? '' ) ); ?>
							<input type="hidden" name="action" value="<?php echo esc_attr( IdentityResolutionPage::REPAIR_ACTION ); ?>">
							<input type="hidden" name="ffc_key" value="<?php echo esc_attr( (string) ( $ffc_identity_row[ IdentityQueue::COLUMN_KEY ] ?? '' ) ); ?>">
							<input type="hidden" name="ffc_subject" value="<?php echo esc_attr( (string) ( $ffc_identity_row['subject'] ?? '' ) ); ?>">
							<?php
							// `text` with `inputmode`, never `number`: an RF is a
							// fixed-width identifier, and a number input drops a
							// leading zero -- which `rf_normalized varchar(7)` says
							// is a digit, not formatting. That is also why the
							// screen is outside `RequiredNumericInputTest`'s scope.
							?>
							<label class="screen-reader-text" for="ffc-rf-<?php echo esc_attr( (string) ( $ffc_identity_row['subject'] ?? '' ) ); ?>">
								<?php esc_html_e( 'Corrected RF', 'ffcertificate' ); ?>
							</label>
							<input type="text" inputmode="numeric" pattern="[0-9]{7}" maxlength="7" size="8" required
								id="ffc-rf-<?php echo esc_attr( (string) ( $ffc_identity_row['subject'] ?? '' ) ); ?>"
								name="ffc_rf" placeholder="<?php esc_attr_e( '7 digits', 'ffcertificate' ); ?>">
							<?php
							// THE FIELD IS NEVER PRE-FILLED AND NOTHING
							// STORED COMES BACK.
							//
							// The value travels browser -> server, which
							// is what a correction is. `Check` asks what
							// this number would do -- above all whether it
							// already belongs to somebody else, which is a
							// different finding rather than a failed
							// correction -- and the answer carries an
							// account, never an identifier.
							?>
							<button type="button" class="button button-secondary ffc-identity-check"
								data-ffc-subject="<?php echo esc_attr( (string) ( $ffc_identity_row['subject'] ?? '' ) ); ?>"
								data-ffc-field="<?php echo esc_attr( str_replace( '_hash', '', (string) ( $ffc_identity_row['identifier_column'] ?? 'rf' ) ) ); ?>"
								data-ffc-value="ffc-rf-<?php echo esc_attr( (string) ( $ffc_identity_row['subject'] ?? '' ) ); ?>"
								data-ffc-verdict="ffc-check-<?php echo esc_attr( (string) ( $ffc_identity_row['subject'] ?? '' ) ); ?>">
								<?php esc_html_e( 'Check', 'ffcertificate' ); ?>
							</button>
							<button type="submit" class="button button-primary">
								<?php esc_html_e( 'Correct', 'ffcertificate' ); ?>
							</button>
							<div class="ffc-identity-verdict" id="ffc-check-<?php echo esc_attr( (string) ( $ffc_identity_row['subject'] ?? '' ) ); ?>"
								aria-live="polite"
								<?php /* translators: %s: how many records the correction would rewrite. */ ?>
								data-allowed="<?php esc_attr_e( 'This correction rewrites %s records.', 'ffcertificate' ); ?>"
								<?php /* translators: %s: how many records the correction would rewrite. */ ?>
								data-consolidates="<?php esc_attr_e( 'This correction rewrites %s records and consolidates them with this account\'s other record.', 'ffcertificate' ); ?>"
								<?php /* translators: 1: the account's display name. 2: the account number. */ ?>
								data-holder="<?php esc_attr_e( 'That number belongs to %1$s (#%2$s). If that is the same person, this is a merge rather than a correction — open the account to check who they are.', 'ffcertificate' ); ?>"
								data-profile="<?php echo esc_attr( admin_url( 'user-edit.php?user_id=' ) ); ?>"
								data-open="<?php esc_attr_e( 'Open that account', 'ffcertificate' ); ?>"
								data-empty="<?php esc_attr_e( 'Enter the number HR confirmed first.', 'ffcertificate' ); ?>"
								data-failed="<?php esc_attr_e( 'The check could not be completed.', 'ffcertificate' ); ?>"></div>
						</form>
					<?php endif; ?>
				</div>
			</div>
		<?php endforeach; ?>
		</div>
		<?php $ffc_identity_foot(); ?>
	<?php endif; ?>

	<p class="description ffc-set-mt-10">
		<?php esc_html_e( 'A row naming no account is ordinary rather than an error: a recruitment candidacy carries no WordPress user until it is promoted, so its row ids are the only handle on it. Confirm the corrected number with HR before entering it: the check digit says a number is wrong, never what the right one is.', 'ffcertificate' ); ?>
	</p>

	<?php
	// ORPHANS — records with an identifier and no account (#1397 sprint 6).
	//
	// A LIST RATHER THAN A STEPPER, AND THE REASON IS NOT LAZINESS.
	//
	// The four panels above step through a HELD worklist: resolving one
	// removes that finding and the cursor keeps its meaning. An adoption
	// does not remove a finding from a held list -- it links rows, and the
	// next read simply finds fewer. So there is nothing for a cursor to hold
	// onto, and a stepper over a list that reshapes under it would be a
	// control that lies. The card chrome is the same, which is what keeps
	// the screen one screen.
	//
	// It is gated on the SPLIT capability: the action can open a WordPress
	// user, which is exactly what that capability was carved out for (#1397).
	?>
	<?php if ( $ffc_identity_may_split && array() !== $ffc_identity_orphans ) : ?>
		<?php
		// THE SAME CARD, WRITTEN OUT RATHER THAN THROUGH `$ffc_identity_head`.
		//
		// That closure's header carries a cursor and a `See the list` toggle,
		// and both are controls this panel cannot honour: it has no held
		// list to step through and is always the list. Calling it would print
		// a link that does nothing, which is worse than the four lines below.
		?>
		<div class="ffc-identity-panel">
		<div class="ffc-identity-panel-head">
			<span class="ffc-identity-panel-chip ffc-identity-chip-orphans"><?php esc_html_e( 'No account', 'ffcertificate' ); ?></span>
			<h2 class="ffc-identity-panel-title screen-reader-text"><?php esc_html_e( 'No account', 'ffcertificate' ); ?></h2>
			<p class="ffc-identity-panel-note description"><?php esc_html_e( 'Records carrying an identifier that belongs to no login at all.', 'ffcertificate' ); ?></p>
			<div class="ffc-identity-panel-nav">
				<span class="ffc-identity-panel-count">
					<?php
					printf(
						/* translators: %s: how many findings this category holds. */
						esc_html( _n( '%s finding', '%s findings', count( $ffc_identity_orphans ), 'ffcertificate' ) ),
						esc_html( number_format_i18n( count( $ffc_identity_orphans ) ) )
					);
					?>
				</span>
			</div>
		</div>
		<div class="ffc-identity-panel-body">
		<p class="description">
			<?php esc_html_e( 'These are usually candidacies that were never promoted, or records whose account was deleted. Linking one to an existing account uses the same rule as a move: the two must already agree on an identifier. Opening an account needs CPF, RF and an e-mail address together — an account opened from one identifier is one the resolver will fail to match on the next record carrying another.', 'ffcertificate' ); ?>
		</p>
		<?php if ( $ffc_identity_orphan_capped ) : ?>
			<div class="notice notice-warning inline">
				<p><?php esc_html_e( 'More orphaned records were found than are shown. Resolve these and read the queue again.', 'ffcertificate' ); ?></p>
			</div>
		<?php endif; ?>
		<?php
		// THE SAME CARD AGAIN, OVER THE ONE FINDING THAT IS ABOUT AN ABSENCE
		// (#1407 sprint 3).
		//
		// What this tier states that no other does is WHAT THE RECORD LACKS.
		// Showing only what is present leaves the operator to work out the
		// gap, and the gap is the whole reason `Open the account` may refuse:
		// it needs CPF, RF and an address together.
		//
		// BOTH FORMS ARE UNTOUCHED -- link and open, same fields, same
		// nonces, same actions, same `data-ffc-*` hooks the search dialog
		// binds to.
		?>
		<div class="ffc-identity-cards">
		<?php foreach ( $ffc_identity_orphans as $ffc_identity_orphan ) : ?>
			<div class="ffc-identity-card">
				<div class="ffc-identity-card-main">
					<p class="ffc-identity-card-who">
						<?php if ( array() !== $ffc_identity_orphan['names'] ) : ?>
							<?php echo esc_html( implode( ', ', array_map( 'strval', $ffc_identity_orphan['names'] ) ) ); ?>
						<?php else : ?>
							<span class="description"><?php esc_html_e( 'No name recorded beside these rows.', 'ffcertificate' ); ?></span>
						<?php endif; ?>
					</p>

					<p class="ffc-identity-card-says">
						<?php if ( array() !== $ffc_identity_orphan['accounts'] ) : ?>
							<?php esc_html_e( 'These records carry an identifier that belongs to no login — but an account already files it, so this one is a link to make rather than an account to open.', 'ffcertificate' ); ?>
						<?php else : ?>
							<?php esc_html_e( 'These records carry an identifier that belongs to no login, and no account files it either — so this one needs an account opened rather than a link made.', 'ffcertificate' ); ?>
						<?php endif; ?>
					</p>

					<div class="ffc-identity-card-hashes">
						<span class="ffc-identity-card-hash ffc-identity-card-hash-plain">
							<code><?php echo esc_html( substr( (string) $ffc_identity_orphan['hash'], 0, IdentityQueue::DISPLAY_PREFIX ) ); ?></code>
							<span class="ffc-identity-card-said"><?php echo esc_html( strtoupper( (string) $ffc_identity_orphan['field'] ) ); ?></span>
						</span>
						<?php foreach ( $ffc_identity_orphan['accounts'] as $ffc_identity_orphan_account ) : ?>
							<a href="<?php echo esc_url( admin_url( 'user-edit.php?user_id=' . rawurlencode( (string) $ffc_identity_orphan_account ) ) ); ?>">
								<?php echo esc_html( $ffc_identity_named( $ffc_identity_orphan_account ) ); ?>
							</a>
						<?php endforeach; ?>
					</div>

					<?php
					// WHAT IT HAS AND WHAT IT LACKS, BOTH STATED, AND STILL A
					// LIST: three identifiers with a state each is a list, and
					// flattening it into a sentence would lose the per-item
					// colour that makes the gap findable at a glance.
					?>
					<ul class="ffc-identity-orphan-has">
						<?php foreach ( array( 'cpf', 'rf', 'email' ) as $ffc_identity_kind ) : ?>
							<li class="<?php echo empty( $ffc_identity_orphan['has'][ $ffc_identity_kind ] ) ? 'ffc-identity-orphan-missing' : 'ffc-identity-orphan-present'; ?>">
								<?php
								printf(
									/* translators: 1: CPF, RF or e-mail. 2: whether the record carries it. */
									esc_html__( '%1$s — %2$s', 'ffcertificate' ),
									esc_html( 'email' === $ffc_identity_kind ? __( 'E-mail', 'ffcertificate' ) : strtoupper( $ffc_identity_kind ) ),
									empty( $ffc_identity_orphan['has'][ $ffc_identity_kind ] )
										? esc_html__( 'missing', 'ffcertificate' )
										: esc_html__( 'recorded', 'ffcertificate' )
								);
								?>
							</li>
						<?php endforeach; ?>
					</ul>

					<?php // The row ids are the only handle on a record no account names, so they stay exactly as the table showed them. ?>
					<p class="ffc-identity-card-rows">
						<?php foreach ( $ffc_identity_orphan['stores'] as $ffc_identity_orphan_store => $ffc_identity_orphan_ids ) : ?>
							<span class="ffc-identity-card-rowset">
								<strong><?php echo esc_html( $ffc_identity_store_name( $ffc_identity_orphan_store ) ); ?></strong>
								<code><?php echo esc_html( implode( ', ', array_map( 'strval', $ffc_identity_orphan_ids ) ) ); ?></code>
							</span>
						<?php endforeach; ?>
					</p>
				</div>
				<div class="ffc-identity-card-act">
					<?php
					// The MOVE form, unchanged from the decision tier: an
					// orphan's rows name no account, so the agreement rule
					// reads them against the target exactly as it does
					// there, and the Sprint 3 dialog serves it unaltered.
					?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ffc-set-mb-2xs">
						<?php wp_nonce_field( IdentityResolutionPage::RELINK_NONCE . (string) $ffc_identity_orphan['hash'] ); ?>
						<input type="hidden" name="action" value="<?php echo esc_attr( IdentityResolutionPage::RELINK_ACTION ); ?>">
						<input type="hidden" name="ffc_subject" value="<?php echo esc_attr( (string) $ffc_identity_orphan['hash'] ); ?>">
						<input type="hidden" name="ffc_field" value="<?php echo esc_attr( (string) $ffc_identity_orphan['field'] ); ?>">
						<label class="screen-reader-text" for="ffc-orphan-account-<?php echo esc_attr( (string) $ffc_identity_orphan['hash'] ); ?>">
							<?php esc_html_e( 'Account to link these records to', 'ffcertificate' ); ?>
						</label>
						<input type="number" inputmode="numeric" min="1" step="1" size="6" required
							id="ffc-orphan-account-<?php echo esc_attr( (string) $ffc_identity_orphan['hash'] ); ?>"
							name="ffc_account" placeholder="<?php esc_attr_e( 'Account #', 'ffcertificate' ); ?>">
						<button type="button" class="button button-secondary ffc-identity-find"
							data-ffc-subject="<?php echo esc_attr( (string) $ffc_identity_orphan['hash'] ); ?>"
							data-ffc-field="<?php echo esc_attr( (string) $ffc_identity_orphan['field'] ); ?>"
							data-ffc-input="ffc-orphan-account-<?php echo esc_attr( (string) $ffc_identity_orphan['hash'] ); ?>"
							data-ffc-split="ffc-orphan-open-<?php echo esc_attr( (string) $ffc_identity_orphan['hash'] ); ?>"
							data-ffc-submit="ffc-orphan-link-<?php echo esc_attr( (string) $ffc_identity_orphan['hash'] ); ?>">
							<span class="dashicons dashicons-search" aria-hidden="true"></span>
							<?php esc_html_e( 'Search…', 'ffcertificate' ); ?>
						</button>
						<button type="submit" class="button button-primary"
							id="ffc-orphan-link-<?php echo esc_attr( (string) $ffc_identity_orphan['hash'] ); ?>">
							<?php esc_html_e( 'Link', 'ffcertificate' ); ?>
						</button>
						<span class="ffc-identity-chosen" id="ffc-orphan-chosen-<?php echo esc_attr( (string) $ffc_identity_orphan['hash'] ); ?>" hidden></span>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
						id="ffc-orphan-open-<?php echo esc_attr( (string) $ffc_identity_orphan['hash'] ); ?>">
						<?php wp_nonce_field( IdentityResolutionPage::ADOPT_NONCE . (string) $ffc_identity_orphan['hash'] ); ?>
						<input type="hidden" name="action" value="<?php echo esc_attr( IdentityResolutionPage::ADOPT_ACTION ); ?>">
						<input type="hidden" name="ffc_subject" value="<?php echo esc_attr( (string) $ffc_identity_orphan['hash'] ); ?>">
						<?php
						// ALL THREE ARE TYPED, INCLUDING THE ONE ON RECORD.
						//
						// The record's own value is stored and this screen
						// never shows a stored identifier -- so a field
						// pre-filled from it is not available, and one left
						// empty would be a field whose meaning depends on
						// something invisible. The operator types what they
						// confirmed; the service checks the two numbers
						// against their check digits before opening
						// anything.
						?>
						<label class="screen-reader-text" for="ffc-orphan-cpf-<?php echo esc_attr( (string) $ffc_identity_orphan['hash'] ); ?>">
							<?php esc_html_e( 'CPF', 'ffcertificate' ); ?>
						</label>
						<input type="text" inputmode="numeric" maxlength="14" size="14" required
							id="ffc-orphan-cpf-<?php echo esc_attr( (string) $ffc_identity_orphan['hash'] ); ?>"
							name="ffc_cpf" placeholder="<?php esc_attr_e( 'CPF', 'ffcertificate' ); ?>">
						<label class="screen-reader-text" for="ffc-orphan-rf-<?php echo esc_attr( (string) $ffc_identity_orphan['hash'] ); ?>">
							<?php esc_html_e( 'RF', 'ffcertificate' ); ?>
						</label>
						<input type="text" inputmode="numeric" pattern="[0-9]{7}" maxlength="7" size="8" required
							id="ffc-orphan-rf-<?php echo esc_attr( (string) $ffc_identity_orphan['hash'] ); ?>"
							name="ffc_rf" placeholder="<?php esc_attr_e( 'RF', 'ffcertificate' ); ?>">
						<label class="screen-reader-text" for="ffc-orphan-email-<?php echo esc_attr( (string) $ffc_identity_orphan['hash'] ); ?>">
							<?php esc_html_e( 'E-mail address', 'ffcertificate' ); ?>
						</label>
						<input type="email" size="22" required
							id="ffc-orphan-email-<?php echo esc_attr( (string) $ffc_identity_orphan['hash'] ); ?>"
							name="ffc_email" placeholder="<?php esc_attr_e( 'E-mail', 'ffcertificate' ); ?>">
						<button type="submit" class="button button-secondary">
							<?php esc_html_e( 'Open the account', 'ffcertificate' ); ?>
						</button>
						<span class="ffc-identity-split-barred description" hidden>
							<?php esc_html_e( 'A destination account is chosen, so opening a new one is not available. Clear the destination to open one instead.', 'ffcertificate' ); ?>
						</span>
					</form>
				</div>
			</div>
		<?php endforeach; ?>
		</div>
		<p class="description">
			<?php esc_html_e( 'A record erased at the subject\'s request cannot appear here: the eraser clears the identifier hashes along with the ciphertexts, so nothing is left to match on. Adopting an orphan can never restore a link somebody asked to have removed.', 'ffcertificate' ); ?>
		</p>
		<?php $ffc_identity_foot(); ?>
	<?php endif; ?>

	<?php
	// THE DIALOG'S FIXED PROSE LIVES HERE, NOT IN THE SCRIPT.
	//
	// One shell, reused by whichever move form opened it, so the sentences
	// are escaped at the output point and reach the catalogue as ordinary
	// source strings. The script fills the two regions that carry data and
	// otherwise only shows and hides this.
	//
	// It is inside the `wrap`, which is what gives its rules the page anchor
	// `ffc-page-identities` (`CLAUDE.md`, "Page scope on the admin wrap").
	?>
	<div class="ffc-identity-dialog" id="ffc-identity-dialog" role="dialog" aria-modal="true" aria-labelledby="ffc-identity-dialog-title" hidden>
		<div class="ffc-identity-dialog-backdrop" data-ffc-dialog-dismiss></div>
		<div class="ffc-identity-dialog-panel">
			<div class="ffc-identity-dialog-head">
				<div class="ffc-identity-dialog-heading">
					<h2 id="ffc-identity-dialog-title"><?php esc_html_e( 'Choose the destination account', 'ffcertificate' ); ?></h2>
					<p class="ffc-identity-dialog-sub" id="ffc-identity-dialog-sub"></p>
				</div>
				<button type="button" class="ffc-identity-dialog-close" data-ffc-dialog-dismiss aria-label="<?php esc_attr_e( 'Close', 'ffcertificate' ); ?>">
					<span aria-hidden="true">&times;</span>
				</button>
			</div>
			<div class="ffc-identity-dialog-body">
				<?php
				// The two fixed sentences the script paints into this region
				// travel as data attributes rather than through
				// `wp_localize_script`, so every string in the dialog that
				// carries no runtime value is escaped at the output point and
				// reaches the catalogue from the same file as its neighbours.
				?>
				<div class="ffc-identity-dialog-suggestion" id="ffc-identity-dialog-suggestion"
					data-head="<?php esc_attr_e( 'Suggestion — an account already files one of these identifiers', 'ffcertificate' ); ?>"
					data-none="<?php esc_attr_e( 'No account files any identifier these records carry, so there is nothing to suggest. Search below.', 'ffcertificate' ); ?>"></div>
				<p class="ffc-identity-dialog-search">
					<label for="ffc-identity-dialog-q"><?php esc_html_e( 'Or search by name, e-mail or account number', 'ffcertificate' ); ?></label>
					<input type="search" id="ffc-identity-dialog-q" autocomplete="off" spellcheck="false">
				</p>
				<div class="ffc-identity-dialog-results" id="ffc-identity-dialog-results" aria-live="polite"
					data-truncated="<?php esc_attr_e( 'More accounts match than are shown. Narrow the search.', 'ffcertificate' ); ?>"></div>
				<p class="description ffc-identity-dialog-column">
					<?php esc_html_e( 'The badge on the right says whether the move would be accepted, before you confirm.', 'ffcertificate' ); ?>
				</p>
				<p class="ffc-identity-dialog-rule">
					<?php esc_html_e( 'This is the rule the service applies when writing: the two sides must already agree on one identifier, and where the destination holds none of that kind, it gains this one. A second identifier that disagrees refuses the whole move. Here the rule is read before the refusal rather than after it.', 'ffcertificate' ); ?>
				</p>
			</div>
			<div class="ffc-identity-dialog-foot">
				<button type="button" class="button button-primary" id="ffc-identity-dialog-confirm" disabled>
					<?php esc_html_e( 'Select this account', 'ffcertificate' ); ?>
				</button>
				<button type="button" class="button" data-ffc-dialog-dismiss>
					<?php esc_html_e( 'Cancel', 'ffcertificate' ); ?>
				</button>
				<span class="description"><?php esc_html_e( 'Selecting only chooses the destination. Writing asks for a confirmation afterwards.', 'ffcertificate' ); ?></span>
			</div>
		</div>
	</div>
</div>
