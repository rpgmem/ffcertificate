<?php
/**
 * Identity merge
 *
 * Consolidates two accounts that turned out to be one person, for the shared
 * tier of the identity queue: one identifier, two logins (#1386).
 *
 * @package FreeFormCertificate\Maintenance
 * @since 6.28.3
 */

declare(strict_types=1);

namespace FreeFormCertificate\Maintenance;

use FreeFormCertificate\Core\ActivityLog;
use FreeFormCertificate\Repositories\UserProfileRepository;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Every statement here targets the plugin's own ffc_* tables, for which WordPress exposes no API, and a merge must read and write the live rows: a cached answer would be precisely wrong.
/**
 * Move one account's records onto another the operator chose to keep.
 */
class IdentityMerge {

	/**
	 * Stores carrying records beside a `user_id`.
	 *
	 * @var array<int, string>
	 */
	private const STORES = array(
		'ffc_submissions',
		'ffc_self_scheduling_appointments',
		'ffc_recruitment_candidate',
	);

	/**
	 * Merge one account into another.
	 *
	 * WHY THIS IS NOT A RELINK, THOUGH IT SHARES THE RULE
	 *
	 * A relink moves the rows carrying ONE identifier and refuses when they
	 * name two accounts -- which is exactly the shared tier, and refusing it
	 * is right there: one identifier on two logins is not a question of where
	 * records go, it is a question of which login survives. That question has
	 * no answer the data can give, so the operator gives it, and this moves
	 * everything the losing account holds rather than one identifier's worth.
	 *
	 * THE SAME AGREEMENT RULE, BETWEEN TWO ACCOUNTS
	 *
	 * They must carry the same value for at least one identifier, and no
	 * second value that disagrees; where the survivor holds nothing of a kind
	 * the other has, it gains it, so a merge of an account with only a CPF and
	 * one with only an RF leaves the survivor holding both (#1386, decision
	 * 2). An absent value is never agreement.
	 *
	 * THE LOSING ACCOUNT IS NOT DELETED
	 *
	 * Deleting a WordPress user fires `deleted_user`, whose cleanup has its
	 * own SET-NULL and DELETE policy, and it cannot be undone. The records
	 * move, the emptied login is reported, and removing it stays the
	 * operator's own action in WordPress.
	 *
	 * @param int $survivor The account the operator chose to keep.
	 * @param int $absorbed The account whose records move.
	 * @param int $actor    Who decided it, for the log.
	 * @return array{moved: array<string, int>, gained: array<int, string>, emptied: int}|WP_Error
	 */
	public function merge( int $survivor, int $absorbed, int $actor = 0 ): array|WP_Error {
		global $wpdb;

		if ( $survivor <= 0 || $absorbed <= 0 ) {
			return new WP_Error(
				'ffc_identity_merge_no_pair',
				__( 'A merge needs both accounts: the one to keep and the one whose records move.', 'ffcertificate' )
			);
		}

		if ( $survivor === $absorbed ) {
			return new WP_Error(
				'ffc_identity_merge_same_account',
				__( 'Those are the same account, so there is nothing to merge.', 'ffcertificate' )
			);
		}

		$theirs = IdentityAgreement::held_by( $absorbed );
		$ours   = IdentityAgreement::held_by( $survivor );

		if ( array() === $theirs ) {
			return new WP_Error(
				'ffc_identity_merge_nothing_to_move',
				__( 'That account holds no identifier this can read, so there is nothing to merge from it.', 'ffcertificate' )
			);
		}

		$agreement = IdentityAgreement::between( $theirs, $ours );

		if ( array() !== $agreement['conflicts'] ) {
			return new WP_Error(
				'ffc_identity_merge_conflict',
				sprintf(
					/* translators: %s: the identifiers that disagree, comma separated. */
					__( 'Those accounts hold different values for: %s. Correct the identifier first — merging across a disagreement makes one person out of two, and nothing afterwards can tell them apart again.', 'ffcertificate' ),
					implode( ', ', array_map( 'strtoupper', $agreement['conflicts'] ) )
				)
			);
		}

		if ( array() === $agreement['matches'] ) {
			return new WP_Error(
				'ffc_identity_merge_no_agreement',
				__( 'Those accounts share no identifier, and an absent value is not agreement. Correct an identifier first so the two agree.', 'ffcertificate' )
			);
		}

		$moved = array();

		// One transaction across as many as five tables -- the four record
		// stores and both index rows. Rolled back whole on any refusal, which
		// assumes InnoDB, as every ffc_* table is.
		$wpdb->query( 'START TRANSACTION' );

		foreach ( self::STORES as $suffix ) {
			$table = $wpdb->prefix . $suffix;

			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				continue;
			}

			$done = $wpdb->update(
				$table,
				array( 'user_id' => $survivor ),
				array( 'user_id' => $absorbed ),
				array( '%d' ),
				array( '%d' )
			);

			if ( false === $done ) {
				$wpdb->query( 'ROLLBACK' );

				return new WP_Error(
					'ffc_identity_merge_failed',
					sprintf(
						/* translators: %s: the store whose write failed. */
						__( 'The store %s refused the merge, so nothing was changed.', 'ffcertificate' ),
						$table
					)
				);
			}

			$moved[ $table ] = (int) $done;
		}

		// THE SURVIVOR GAINS WHAT IT DID NOT HAVE.
		//
		// The gaps are the identifiers the absorbed account held and this one
		// did not, which is the half of the rule that makes a merge of a
		// CPF-only and an RF-only account leave one login holding both.
		if ( array() !== $agreement['gaps'] ) {
			$index = array();

			foreach ( $agreement['gaps'] as $field => $hash ) {
				$index[ $field . '_hash' ] = $hash;
			}

			if ( ! $this->reindex( $survivor, $index ) ) {
				$wpdb->query( 'ROLLBACK' );

				return new WP_Error(
					'ffc_identity_merge_index_failed',
					__( 'The records were moved but the identity index refused the same change, so nothing was changed. The two must never disagree.', 'ffcertificate' )
				);
			}
		}

		// THE EMPTIED ACCOUNT STOPS CLAIMING WHAT IT NO LONGER HOLDS.
		//
		// Its records are gone, so an index still naming its identifiers would
		// resolve a person to a login holding nothing of theirs -- which is
		// worse than an empty slot, because it answers confidently. The LOGIN
		// stays; only what it claims is cleared.
		if ( ! $this->clear_index( $absorbed ) ) {
			$wpdb->query( 'ROLLBACK' );

			return new WP_Error(
				'ffc_identity_merge_index_failed',
				__( 'The records were moved but the identity index refused the same change, so nothing was changed. The two must never disagree.', 'ffcertificate' )
			);
		}

		$wpdb->query( 'COMMIT' );

		// Records that became this account's are useless behind an account
		// that cannot read them -- #1367's defect, one account at a time.
		do_action( 'ffc_grant_certificate_capabilities', $survivor );

		ActivityLog::log(
			'identity_accounts_merged',
			ActivityLog::LEVEL_WARNING,
			array(
				'kept'    => $survivor,
				'emptied' => $absorbed,
				'stores'  => $moved,
				'matched' => $agreement['matches'],
				'gained'  => array_keys( $agreement['gaps'] ),
			),
			$actor
		);

		return array(
			'moved'   => $moved,
			'gained'  => array_keys( $agreement['gaps'] ),
			'emptied' => $absorbed,
		);
	}

	/**
	 * Point the identity index at what the survivor now holds.
	 *
	 * @param int                   $user_id The account.
	 * @param array<string, string> $data    Index columns to write.
	 * @return bool
	 */
	protected function reindex( int $user_id, array $data ): bool {
		return ( new UserProfileRepository() )->upsertForUserId( $user_id, $data );
	}

	/**
	 * Clear what the emptied account claims, leaving the login itself alone.
	 *
	 * @param int $user_id The emptied account.
	 * @return bool
	 */
	protected function clear_index( int $user_id ): bool {
		global $wpdb;

		$profiles = $wpdb->prefix . 'ffc_user_profiles';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $profiles ) ) !== $profiles ) {
			return true;
		}

		$done = $wpdb->update(
			$profiles,
			array(
				'cpf_hash' => null,
				'rf_hash'  => null,
			),
			array( 'user_id' => $user_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return false !== $done;
	}
}
// phpcs:enable WordPress.DB.DirectDatabaseQuery
