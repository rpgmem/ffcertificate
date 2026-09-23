<?php
/**
 * Account search for the identity queue.
 *
 * Answers one question the identity-resolution screen could not ask before
 * committing: WHICH ACCOUNT WOULD BE ACCEPTED (#1397 sprint 3).
 *
 * Until now an operator typed an account number blind and learned from a
 * `WP_Error` whether the move was allowed. The rule that decides it is not
 * one an operator can run in their head -- it reads what both sides hold
 * across four tables -- so the only way to know was to try.
 *
 * THE VERDICT COMES FROM THE WRITE PATH, NOT FROM A COPY OF IT.
 *
 * {@see \FreeFormCertificate\Maintenance\IdentityRelink::relink()} is now
 * `moving()` + `verdict()` + the write, and this endpoint calls the same two
 * methods with the same arguments. A second implementation would agree on the
 * day it was written and drift the first time one side was corrected -- and
 * what it decides is whether one person's record lands under another person's
 * login, so the drift would be invisible and permanent.
 *
 * NOTHING STORED REACHES THE BROWSER. The response carries account ids,
 * display names and addresses -- which every admin screen shows -- plus hash
 * PREFIXES for the identifiers, never a CPF or an RF.
 *
 * @package FreeFormCertificate\Admin
 * @since   6.28.4
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

use FreeFormCertificate\Core\Capabilities;
use FreeFormCertificate\Core\RequestInput;
use FreeFormCertificate\Maintenance\IdentityAgreement;
use FreeFormCertificate\Maintenance\IdentityRelink;
use FreeFormCertificate\Repositories\UserProfileRepository;
use WP_Error;
use WP_User;
use WP_User_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Search accounts and say, per account, whether the move would be accepted.
 */
class IdentitySearchAjaxEndpoint {

	/**
	 * AJAX action, and the nonce the screen localises under it.
	 */
	public const AJAX_ACTION = 'ffc_identity_search_accounts';

	/**
	 * How many search results are evaluated.
	 *
	 * Each one costs a read of what that account holds across four tables, so
	 * this is a budget rather than a page size: a wider net would make the
	 * dialog slow at exactly the moment the operator is waiting for it. A
	 * search returning more says so, which is the {@see \FreeFormCertificate\Maintenance\IdentityAuditExportSource}
	 * rule -- a partial list must never read as a complete one.
	 */
	public const RESULT_LIMIT = 10;

	/**
	 * How many characters of a hash reach the browser.
	 */
	public const DISPLAY_PREFIX = 12;

	/**
	 * Register the hook.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( self::class, 'handle' ) );
	}

	/**
	 * Answer one search.
	 *
	 * @return void
	 */
	public static function handle(): void {
		check_ajax_referer( self::AJAX_ACTION, 'nonce' );

		if ( ! Capabilities::current_user_can_admin_or( IdentityResolutionPage::CAPABILITY ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You do not have permission to resolve identities.', 'ffcertificate' ) ),
				403
			);
		}

		$hash  = RequestInput::get_post_string( 'subject', '' );
		$field = RequestInput::get_post_string( 'field', 'rf' );
		$term  = RequestInput::get_post_string( 'q', '' );

		$relink = static::movements();
		$moving = $relink->moving( $hash, $field );

		// A refusal that does not depend on the target is shown INSTEAD of a
		// list: offering accounts for a move nothing can complete would have
		// the operator choose between candidates that are all equally wrong.
		if ( is_wp_error( $moving ) ) {
			wp_send_json_error(
				array(
					'message' => $moving->get_error_message(),
					'code'    => $moving->get_error_code(),
				),
				400
			);
		}

		$suggested = self::suggested( $moving );
		$found     = static::matching( $term, self::RESULT_LIMIT + 1 );
		$more      = count( $found ) > self::RESULT_LIMIT;
		$found     = array_slice( $found, 0, self::RESULT_LIMIT );

		wp_send_json_success(
			array(
				'field'      => $field,
				'prefix'     => substr( $hash, 0, self::DISPLAY_PREFIX ),
				'records'    => $moving['count'],
				'origin'     => $moving['origin'],
				'suggestion' => null === $suggested ? null : self::describe( $relink, $moving, $suggested ),
				'accounts'   => array_map(
					static function ( WP_User $user ) use ( $relink, $moving ) {
						return self::describe( $relink, $moving, $user );
					},
					$found
				),
				'truncated'  => $more,
			)
		);
	}

	/**
	 * The account most likely to be the answer, or null when none is.
	 *
	 * NOT "the account already holding THIS identifier", which is the shape
	 * the mockup drew and the rare case in practice: an account whose ROWS
	 * carry the moving hash makes the move ambiguous and `moving()` has
	 * already refused it. What is left, and what is usually the answer, is the
	 * account the index files under any identifier the moving records carry --
	 * typically the OTHER one, which is precisely the agreement the rule
	 * requires.
	 *
	 * @param array{identifiers: array<string, array<int, string>>, origin: int} $moving What `moving()` returned.
	 * @return WP_User|null
	 */
	private static function suggested( array $moving ): ?WP_User {
		$profiles = static::profiles();

		foreach ( IdentityAgreement::FIELDS as $field ) {
			foreach ( $moving['identifiers'][ $field ] ?? array() as $hash ) {
				foreach ( $profiles->findUserIdsByHash( $field . '_hash', (string) $hash ) as $user_id ) {
					if ( $moving['origin'] === $user_id ) {
						continue;
					}

					$user = get_userdata( $user_id );

					if ( $user instanceof WP_User ) {
						return $user;
					}
				}
			}
		}

		return null;
	}

	/**
	 * Accounts matching what the operator typed.
	 *
	 * An all-digits term is taken as an account NUMBER as well as a search --
	 * the operator arrives from the audit export, which names accounts by id,
	 * and `WP_User_Query`'s search does not look at `ID`.
	 *
	 * An empty term returns nothing rather than everybody: a list of every
	 * account on the site is not a search result, and each row costs a read.
	 *
	 * It is `protected` for the same reason {@see self::movements()} is: it is
	 * the one part of this endpoint that is a plain WordPress call rather than
	 * a decision, so a test stands in for it and spends its assertions on the
	 * verdicts instead of on `WP_User_Query`.
	 *
	 * @param string $term  What was typed.
	 * @param int    $limit How many to return.
	 * @return array<int, WP_User>
	 */
	protected static function matching( string $term, int $limit ): array {
		$term = trim( $term );

		if ( '' === $term ) {
			return array();
		}

		$out = array();

		if ( ctype_digit( $term ) ) {
			$by_id = get_userdata( (int) $term );

			if ( $by_id instanceof WP_User ) {
				$out[ $by_id->ID ] = $by_id;
			}
		}

		$query = new WP_User_Query(
			array(
				'search'         => '*' . $term . '*',
				'search_columns' => array( 'user_login', 'user_email', 'user_nicename', 'display_name' ),
				'number'         => $limit,
				'fields'         => 'all',
				'orderby'        => 'display_name',
			)
		);

		foreach ( $query->get_results() as $user ) {
			if ( $user instanceof WP_User && ! isset( $out[ $user->ID ] ) ) {
				$out[ $user->ID ] = $user;
			}
		}

		return array_values( $out );
	}

	/**
	 * One account, with the verdict the write would reach.
	 *
	 * `allowed` is false whenever `verdict()` returns a `WP_Error`, and the
	 * reason shown is that error's own message -- so a refusal the operator
	 * reads here is, word for word, the refusal they would have received.
	 *
	 * @param IdentityRelink                                                     $relink The write path.
	 * @param array{identifiers: array<string, array<int, string>>, origin: int} $moving What `moving()` returned.
	 * @param WP_User                                                            $user   The candidate.
	 * @return array{id: int, name: string, email: string, allowed: bool, label: string, reason: string, code: string}
	 */
	private static function describe( IdentityRelink $relink, array $moving, WP_User $user ): array {
		$verdict = $relink->verdict( $moving, (int) $user->ID );

		if ( $verdict instanceof WP_Error ) {
			return array(
				'id'      => (int) $user->ID,
				'name'    => (string) $user->display_name,
				'email'   => (string) $user->user_email,
				'allowed' => false,
				'label'   => self::refusal_label( (string) $verdict->get_error_code() ),
				'reason'  => (string) $verdict->get_error_message(),
				'code'    => (string) $verdict->get_error_code(),
			);
		}

		$matched = array_map( 'strtoupper', $verdict['matches'] );
		$gained  = array_map( 'strtoupper', array_keys( $verdict['gaps'] ) );

		if ( array() === $gained ) {
			$label = sprintf(
				/* translators: %s: the identifiers that agree, comma separated. */
				__( 'Agrees by %s', 'ffcertificate' ),
				implode( ', ', $matched )
			);
		} else {
			$label = sprintf(
				/* translators: 1: the identifiers that agree. 2: the identifiers the account would gain. */
				__( 'Agrees by %1$s — gains the %2$s', 'ffcertificate' ),
				implode( ', ', $matched ),
				implode( ', ', $gained )
			);
		}

		return array(
			'id'      => (int) $user->ID,
			'name'    => (string) $user->display_name,
			'email'   => (string) $user->user_email,
			'allowed' => true,
			'label'   => $label,
			'reason'  => __( 'The two already agree, so the move is allowed.', 'ffcertificate' ),
			'code'    => '',
		);
	}

	/**
	 * A badge short enough to sit beside an account, per refusal.
	 *
	 * The full sentence travels beside it as `reason`; this is what fits in
	 * the column. An unrecognised code falls back to a plain refusal rather
	 * than to an empty badge -- a candidate that cannot be chosen must never
	 * look like one that can.
	 *
	 * @param string $code The `WP_Error` code.
	 * @return string
	 */
	private static function refusal_label( string $code ): string {
		switch ( $code ) {
			case 'ffc_identity_relink_unchanged':
				return __( 'Already holds these records', 'ffcertificate' );
			case 'ffc_identity_relink_conflict':
				return __( 'Holds a different value — refused', 'ffcertificate' );
			case 'ffc_identity_relink_no_agreement':
				return __( 'Shares no identifier — refused', 'ffcertificate' );
			default:
				return __( 'Refused', 'ffcertificate' );
		}
	}

	/**
	 * The write path, as a seam a test can stand in for.
	 *
	 * @return IdentityRelink
	 */
	protected static function movements(): IdentityRelink {
		return new IdentityRelink();
	}

	/**
	 * The identity index, as a seam a test can stand in for.
	 *
	 * @return UserProfileRepository
	 */
	protected static function profiles(): UserProfileRepository {
		return new UserProfileRepository();
	}
}
