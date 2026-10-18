<?php
/**
 * Identity resolution labels
 *
 * The words the identity-resolution screen prints for a store, an account, an
 * identifier and a tier (#1925).
 *
 * They were closures at the top of the view, which kept them out of PHPStan
 * and out of the coverage scope -- `includes/admin/views` is carved out of
 * both as markup. They are translation and mapping, not markup, so they live
 * here, where both gates read them.
 *
 * @package FreeFormCertificate\Admin
 * @since 6.41.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

use FreeFormCertificate\Maintenance\IdentityConflictQuery;
use FreeFormCertificate\Maintenance\IdentityQueue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Names and sentences for the identity-resolution screen.
 */
final class IdentityResolutionLabels {

	/**
	 * A store, as an operator reads it.
	 *
	 * THE QUERIES DO NOT AGREE ON THE SHAPE, AND THE SCREEN SHOWED BOTH.
	 *
	 * `IdentityStores::label()` strips the site prefix and the `ffc_`
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
	 * @param mixed $store The store, in either shape.
	 * @return string
	 */
	public static function store_name( $store ): string {
		$key = is_scalar( $store ) ? (string) $store : '';

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
	}

	/**
	 * A list of stores, in the reader's own language.
	 *
	 * `wp_sprintf_l()` is WordPress's own list joiner, so the comma and the
	 * "and" come from core's translations rather than from a separator invented
	 * here -- which is the whole defect this replaces: the screen was printing
	 * `IdentityConflictQuery::RELATED_SEPARATOR`, a `|` that exists to survive a
	 * `GROUP_CONCAT` and was never meant to be read.
	 *
	 * @param mixed $joined The stores as the query joined them.
	 * @return string
	 */
	public static function store_list( $joined ): string {
		$parts = array_filter( array_map( 'trim', explode( IdentityConflictQuery::RELATED_SEPARATOR, is_scalar( $joined ) ? (string) $joined : '' ) ) );

		if ( array() === $parts ) {
			return '';
		}

		return (string) wp_sprintf_l( '%l', array_map( array( self::class, 'store_name' ), $parts ) );
	}

	/**
	 * An account as a person reads it: the name WordPress holds, then the id.
	 *
	 * The NAME is what makes a merge confirmable — "keep 5784 or 6092?" is not a
	 * question anybody can answer. The document stays a hash prefix, because that
	 * is the identifier and this screen never shows one.
	 *
	 * @param int|string $user_id The account.
	 * @return string
	 */
	public static function account( $user_id ): string {
		$user = get_userdata( (int) $user_id );
		$name = ( $user && '' !== (string) $user->display_name ) ? (string) $user->display_name : '';

		return '' === $name
			? sprintf( /* translators: %d: account id. */ __( 'Account #%d', 'ffcertificate' ), (int) $user_id )
			: sprintf( /* translators: 1: person's name, 2: account id. */ __( '%1$s (#%2$d)', 'ffcertificate' ), $name, (int) $user_id );
	}

	/**
	 * An identifier column's name, or '' for a column it does not know.
	 *
	 * The two names stay UNTRANSLATED because they are not English words: `RF` is
	 * *Registro Funcional* and `CPF` *Cadastro de Pessoa Física*, proper nouns an
	 * operator reads the same way in either language, the way `CLAUDE.md` records
	 * for the domain terms the database stores.
	 *
	 * @param mixed $column The finding's `identifier_column`.
	 * @return string
	 */
	public static function identifier_name( $column ): string {
		switch ( is_scalar( $column ) ? (string) $column : '' ) {
			case 'rf_hash':
				return 'RF';
			case 'cpf_hash':
				return 'CPF';
			default:
				return '';
		}
	}

	/**
	 * The label above the "corrected number" box, naming which identifier (#1486).
	 *
	 * It said "Corrected RF" while the scan behind it was RF-only. The scan reads
	 * CPF too now, so a fixed label would name the wrong identifier on half the
	 * findings -- to a screen reader, which is the only place this label is read.
	 *
	 * An unknown column falls back to the neutral sentence rather than guessing a
	 * name, because a wrong name is worse than no name here.
	 *
	 * @param mixed $column The finding's `identifier_column`.
	 * @return string
	 */
	public static function corrected_label( $column ): string {
		$name = self::identifier_name( $column );

		if ( '' === $name ) {
			return __( 'The corrected number', 'ffcertificate' );
		}

		/* translators: %s: an identifier's name, e.g. RF -- a proper noun, untranslated. */
		return sprintf( __( 'Corrected %s', 'ffcertificate' ), $name );
	}

	/**
	 * The class that colours a tier's chip.
	 *
	 * The tier is part of the class, so the chip is coloured by what the
	 * finding IS rather than by a decision taken in the markup. The values come
	 * from `IdentityQueuePanels::ORDER`, never from the request, and the filter
	 * is what keeps a value that did not from reaching an attribute.
	 *
	 * @param string $tier The tier.
	 * @return string
	 */
	public static function chip_class( string $tier ): string {
		return 'ffc-identity-chip-' . preg_replace( '/[^a-z]/', '', $tier );
	}

	/**
	 * One tier's name, as an operator reads it.
	 *
	 * @param string $tier The tier.
	 * @return string
	 */
	public static function tier_label( string $tier ): string {
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
	}

	/**
	 * What the operator is being told to do about one tier.
	 *
	 * @param string $tier The tier.
	 * @return string
	 */
	public static function tier_note( string $tier ): string {
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
	}
}
