<?php
/**
 * Preflight for a corrected identifier.
 *
 * Answers, without writing anything, what typing this number would do
 * (#1397 sprint 4).
 *
 * THE REFUSAL AN OPERATOR MOST NEEDS IS THE ONE THAT IS NOT A FAILURE.
 *
 * `IdentityRepair` refuses a correction whose value already belongs to
 * ANOTHER account, because completing it would merge two identities rather
 * than fix a typo. That refusal is correct and it is also the answer: the
 * records almost certainly belong to that account, and moving them there is
 * a verb this screen already has. Today the operator reads a sentence saying
 * the value belongs to somebody and has to go and find out whom.
 *
 * So this runs `IdentityRepair::plan()` — the repair's own checks, minus the
 * write — and, on a collision, names the account that holds the value.
 *
 * WHAT IT DELIBERATELY DOES NOT OFFER, AND THE PROOF.
 *
 * The obvious next step looks like "move the records to that account", and
 * `IdentityRelink` can never accept it. On a collision the target holds the
 * CONFIRMED value in field F while the moving records hold the WRONG value in
 * that same F — two different non-empty values, which `IdentityAgreement`
 * calls a conflict, which refuses the whole move. That is true of every
 * collision by construction and not of some of them, so computing the verdict
 * per finding would be a control that is always disabled.
 *
 * What the refusal's own sentence says is therefore the whole answer: this is
 * a MERGE decision, not a correction. Naming the account is what this adds —
 * the operator could not learn it from the sentence, and the number is one
 * every admin screen shows.
 *
 * WHAT CROSSES THE WIRE, AND WHAT DOES NOT.
 *
 * The confirmed value travels browser → server, which is what a correction
 * is; nothing stored ever travels back. The response carries account ids,
 * display names and row counts, a hash PREFIX, and the refusal's own
 * sentence. It never carries the hash of the value typed, nor any stored
 * identifier.
 *
 * It is not a new oracle: the caller already holds `ffc_manage_identities`
 * and could learn the same thing by attempting the repair. What this removes
 * is having to attempt it to find out.
 *
 * @package FreeFormCertificate\Admin
 * @since   6.28.4
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

use FreeFormCertificate\Core\Capabilities;
use FreeFormCertificate\Core\RequestInput;
use FreeFormCertificate\Maintenance\IdentityRepair;
use WP_Error;
use WP_User;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Say what a correction would do before it is committed.
 */
class IdentityPreflightAjaxEndpoint {

	/**
	 * AJAX action, and the nonce the screen localises under it.
	 */
	public const AJAX_ACTION = 'ffc_identity_preflight_correction';

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
	 * Answer one preflight.
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

		$hash      = RequestInput::get_post_string( 'subject', '' );
		$field     = RequestInput::get_post_string( 'field', 'rf' );
		$confirmed = RequestInput::get_post_string( 'value', '' );

		$plan = static::repairs()->plan( $hash, $confirmed, $field );

		if ( $plan instanceof WP_Error ) {
			wp_send_json_success( self::refused( $plan, $hash ) );
		}

		wp_send_json_success(
			array(
				'allowed'      => true,
				'prefix'       => substr( $hash, 0, self::DISPLAY_PREFIX ),
				'rows'         => self::rows_in( $plan['found']['rows'] ),
				'account'      => $plan['account'],
				// True when the confirmed value is this same account's other
				// record: the correction consolidates one person's duplicate
				// rather than renaming a number. Different blast radius, so
				// the screen says which it is.
				'consolidates' => $plan['consolidates'],
				'code'         => '',
				'message'      => '',
			)
		);
	}

	/**
	 * A refusal, and — where it is a collision — who holds the value and
	 * whether the records could simply be moved to them.
	 *
	 * Every other refusal is passed through word for word. A preflight that
	 * paraphrased would be a second vocabulary for the same decisions.
	 *
	 * @param WP_Error $plan What `plan()` refused with.
	 * @param string   $hash The finding's hash.
	 * @return array<string, mixed>
	 */
	private static function refused( WP_Error $plan, string $hash ): array {
		$code = (string) $plan->get_error_code();

		$out = array(
			'allowed'      => false,
			'prefix'       => substr( $hash, 0, self::DISPLAY_PREFIX ),
			'rows'         => 0,
			'account'      => 0,
			'consolidates' => false,
			'code'         => $code,
			'message'      => (string) $plan->get_error_message(),
		);

		if ( 'ffc_identity_repair_collision' !== $code ) {
			return $out;
		}

		$data  = (array) $plan->get_error_data();
		$owner = (int) ( $data['account'] ?? 0 );

		// A colliding row that names nobody — an unpromoted candidacy — is
		// still a refusal, and there is no account to offer. Saying "somebody
		// holds it" and offering no next step is the honest answer there.
		if ( $owner <= 0 ) {
			return $out;
		}

		$user = get_userdata( $owner );

		$out['holder'] = array(
			'id'    => $owner,
			'name'  => $user instanceof WP_User ? (string) $user->display_name : '',
			'email' => $user instanceof WP_User ? (string) $user->user_email : '',
		);

		return $out;
	}

	/**
	 * How many rows a plan would rewrite, across every store.
	 *
	 * @param array<string, array<int, int>> $rows Per-store row ids.
	 * @return int
	 */
	private static function rows_in( array $rows ): int {
		$count = 0;

		foreach ( $rows as $ids ) {
			$count += count( $ids );
		}

		return $count;
	}

	/**
	 * The repair, as a seam a test can stand in for.
	 *
	 * @return IdentityRepair
	 */
	protected static function repairs(): IdentityRepair {
		return new IdentityRepair();
	}
}
