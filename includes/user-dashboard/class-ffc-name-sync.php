<?php
/**
 * Keeps WordPress's first / last name and the plugin's full name in step.
 *
 * @package FreeFormCertificate\UserDashboard
 * @since   6.33.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\UserDashboard;

use FreeFormCertificate\Core\PersonName;
use FreeFormCertificate\Repositories\UserProfileRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress → plugin half of the name sync.
 *
 * The plugin stores one name, the full one (the profile's `display_name`), and
 * every write of it derives WordPress's `first_name` / `last_name` through the
 * profile service's mirrors. This class is the other direction: when
 * WordPress's two fields change, the full name becomes `first + " " + last`.
 *
 * WHO IT TOUCHES. Only accounts that already have a plugin profile. Otherwise
 * every administrator or editor who fixes their own name would be given a
 * plugin profile they never had.
 *
 * THE USER-EDIT SCREEN shows both at once -- WordPress's First / Last Name and
 * the plugin's "Name" field -- and saves them in the wrong order for a naive
 * listener: the plugin's section saves on `personal_options_update` /
 * `edit_user_profile_update`, BEFORE core writes the posted first / last
 * name, so core then overwrites the freshly derived parts with the values the
 * form was rendered with. So on that screen the field the operator actually
 * changed wins, and when both changed the plugin's name wins, because it is
 * the one stored. Off that screen (`wp_update_user()` from code, REST, WP-CLI)
 * a change to either part is applied to the full name.
 */
final class NameSync {

	/**
	 * Per-request state of a user-edit save, keyed by user id.
	 *
	 * @var array<int, array{first: string, last: string, full: string, posted_first: string, posted_last: string}>
	 */
	private static array $screen = array();

	/**
	 * Register the hooks.
	 *
	 * Priority 1 on the two screen hooks, so the snapshot is taken before
	 * AdminUserCustomFields saves the plugin's fields at 10. Priority 20 on
	 * `profile_update`, after core has written the user and after the
	 * capability restore at 5.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'personal_options_update', array( self::class, 'snapshot' ), 1 );
		add_action( 'edit_user_profile_update', array( self::class, 'snapshot' ), 1 );
		add_action( 'profile_update', array( self::class, 'on_profile_update' ), 20, 3 );
	}

	/**
	 * Remember the stored names and the posted parts before anything saves.
	 *
	 * Core has already checked the screen's nonce and the edit capability
	 * before these hooks fire; this reads the post only to compare.
	 *
	 * @param int $user_id The account being saved.
	 * @return void
	 */
	public static function snapshot( int $user_id ): void {
		$full = self::full_name( $user_id );
		if ( null === $full ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Core verified `update-user_{$user_id}` before firing this hook; the values are only compared, never stored from here.
		$posted_first = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['first_name'] ) ) : '';
		$posted_last  = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['last_name'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$parts = self::wp_parts( $user_id );

		self::$screen[ $user_id ] = array(
			'first'        => $parts['first'],
			'last'         => $parts['last'],
			'full'         => $full,
			'posted_first' => $posted_first,
			'posted_last'  => $posted_last,
		);
	}

	/**
	 * Reconcile after WordPress has written the account.
	 *
	 * @param int                  $user_id       The account.
	 * @param mixed                $old_user_data The account before the update (unused: its meta reads live).
	 * @param array<string, mixed> $userdata      The fields this update was given.
	 * @return void
	 */
	public static function on_profile_update( int $user_id, $old_user_data = null, array $userdata = array() ): void {
		unset( $old_user_data );

		// The plugin's own write mirrors through wp_update_user(), which lands
		// here; answering it would write again.
		if ( UserProfileService::is_writing() ) {
			return;
		}

		$full = self::full_name( $user_id );
		if ( null === $full ) {
			return;
		}

		if ( isset( self::$screen[ $user_id ] ) ) {
			$snap = self::$screen[ $user_id ];
			unset( self::$screen[ $user_id ] );

			$parts_changed = PersonName::join( $snap['posted_first'], $snap['posted_last'] ) !== PersonName::join( $snap['first'], $snap['last'] );
			$full_changed  = PersonName::normalize( $full ) !== PersonName::normalize( $snap['full'] );

			if ( $full_changed || ! $parts_changed ) {
				// Core has just written the stale parts the form was rendered
				// with over the ones derived from the new name: write them again.
				if ( $full_changed ) {
					self::write_full( $user_id, $full );
				}
				return;
			}

			self::write_full( $user_id, PersonName::join( $snap['posted_first'], $snap['posted_last'] ) );
			return;
		}

		if ( ! array_key_exists( 'first_name', $userdata ) && ! array_key_exists( 'last_name', $userdata ) ) {
			return;
		}

		$parts  = self::wp_parts( $user_id );
		$joined = PersonName::join( $parts['first'], $parts['last'] );
		if ( '' === $joined || PersonName::normalize( $full ) === $joined ) {
			return;
		}

		self::write_full( $user_id, $joined );
	}

	/**
	 * WordPress's first and last name, read through the account.
	 *
	 * @param int $user_id The account.
	 * @return array{first: string, last: string}
	 */
	private static function wp_parts( int $user_id ): array {
		$user = get_userdata( $user_id );

		return array(
			'first' => false !== $user ? (string) $user->first_name : '',
			'last'  => false !== $user ? (string) $user->last_name : '',
		);
	}

	/**
	 * The plugin's full name for an account, or null when it has no profile.
	 *
	 * @param int $user_id The account.
	 * @return string|null
	 */
	private static function full_name( int $user_id ): ?string {
		$row = ( new UserProfileRepository() )->findByUserId( $user_id );
		if ( null === $row ) {
			return null;
		}
		return $row['display_name'] ?? '';
	}

	/**
	 * Store the full name; the service re-derives both parts and the
	 * WordPress display name from it.
	 *
	 * @param int    $user_id The account.
	 * @param string $full    Full name.
	 * @return void
	 */
	private static function write_full( int $user_id, string $full ): void {
		if ( '' === $full ) {
			return;
		}
		UserProfileService::write( $user_id, array( 'display_name' => $full ) );
	}

	/**
	 * Forget the per-request state (tests).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$screen = array();
	}
}
