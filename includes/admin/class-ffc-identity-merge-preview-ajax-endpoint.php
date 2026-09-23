<?php
/**
 * Preview for a merge.
 *
 * Says what consolidating two logins would move, before the operator
 * acknowledges it (#1397 sprint 5).
 *
 * A MERGE IS THE ONE VERB NO OTHER UNDOES.
 *
 * Afterwards nothing can say which record came from which login — that is
 * provenance, and no amount of later knowledge recovers it. So the
 * confirmation has to be specific rather than solemn: who stays, who empties,
 * and HOW MANY records move, per store.
 *
 * THE COUNT COMES FROM THE WRITE'S OWN RESOLUTION.
 *
 * `IdentityMerge::merge()` is now `plan()` plus the write, and this calls
 * `plan()`. A count taken anywhere else would be a second answer to "which
 * rows move" — and the operator would be acknowledging the one that is not
 * the one that runs.
 *
 * It also reports how much each side holds, which is the evidence behind
 * "keep the login that is in use" (#1368). Evidence, never a decision: the
 * data cannot say which login is the person's real one, which is why the
 * operator chooses.
 *
 * Nothing stored reaches the browser: account ids, display names and counts.
 *
 * @package FreeFormCertificate\Admin
 * @since   6.28.4
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

use FreeFormCertificate\Core\Capabilities;
use FreeFormCertificate\Core\RequestInput;
use FreeFormCertificate\Maintenance\IdentityMerge;
use WP_Error;
use WP_User;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Say what a merge would move before it is acknowledged.
 */
class IdentityMergePreviewAjaxEndpoint {

	/**
	 * AJAX action, and the nonce the screen localises under it.
	 */
	public const AJAX_ACTION = 'ffc_identity_preview_merge';

	/**
	 * Register the hook.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( self::class, 'handle' ) );
	}

	/**
	 * Answer one preview.
	 *
	 * @return void
	 */
	public static function handle(): void {
		check_ajax_referer( self::AJAX_ACTION, 'nonce' );

		// The MERGE capability, not the queue's: a preview names how much one
		// login holds, which is exactly what somebody without the verb has no
		// business asking for one account at a time.
		if ( ! Capabilities::current_user_can_admin_or( IdentityResolutionPage::MERGE_CAPABILITY ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You do not have permission to merge identities.', 'ffcertificate' ) ),
				403
			);
		}

		$survivor = absint( RequestInput::get_post_string( 'survivor', '0' ) );
		$absorbed = absint( RequestInput::get_post_string( 'absorbed', '0' ) );

		$plan = static::mergers()->plan( $survivor, $absorbed );

		if ( $plan instanceof WP_Error ) {
			wp_send_json_success(
				array(
					'allowed' => false,
					'code'    => (string) $plan->get_error_code(),
					'message' => (string) $plan->get_error_message(),
				)
			);
		}

		wp_send_json_success(
			array(
				'allowed'  => true,
				'code'     => '',
				'message'  => '',
				'survivor' => self::named( $survivor, $plan['holds']['survivor'] ),
				'absorbed' => self::named( $absorbed, $plan['holds']['absorbed'] ),
				'total'    => $plan['total'],
				'stores'   => self::per_store( $plan['counts'] ),
				// What the survivor gains that it did not hold, which is the
				// half of the rule an operator cannot see from the screen.
				'gains'    => array_map( 'strtoupper', array_keys( $plan['gaps'] ) ),
				'matched'  => array_map( 'strtoupper', $plan['matches'] ),
			)
		);
	}

	/**
	 * One account, with how much it holds.
	 *
	 * @param int $user_id The account.
	 * @param int $records How many records sit under it.
	 * @return array{id: int, name: string, records: int}
	 */
	private static function named( int $user_id, int $records ): array {
		$user = get_userdata( $user_id );

		return array(
			'id'      => $user_id,
			'name'    => $user instanceof WP_User ? (string) $user->display_name : '',
			'records' => $records,
		);
	}

	/**
	 * Per-store counts, keyed by the store's table name with the prefix off.
	 *
	 * The PREFIX goes, because it is the install's and says nothing; the store
	 * name stays, because "12 records" without saying of what is a number an
	 * operator cannot check against anything.
	 *
	 * A store holding NOTHING is reported as zero rather than dropped: an
	 * absent row and a row reading zero are the same fact, but only the second
	 * says it was looked at.
	 *
	 * @param array<string, int> $counts Per-table counts.
	 * @return array<int, array{store: string, records: int}>
	 */
	private static function per_store( array $counts ): array {
		global $wpdb;

		$out = array();

		foreach ( $counts as $table => $records ) {
			$out[] = array(
				'store'   => (string) preg_replace( '/^' . preg_quote( (string) $wpdb->prefix, '/' ) . '/', '', (string) $table ),
				'records' => (int) $records,
			);
		}

		return $out;
	}

	/**
	 * The merge, as a seam a test can stand in for.
	 *
	 * @return IdentityMerge
	 */
	protected static function mergers(): IdentityMerge {
		return new IdentityMerge();
	}
}
