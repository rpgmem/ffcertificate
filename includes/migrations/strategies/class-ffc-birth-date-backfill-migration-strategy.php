<?php
/**
 * Birth-date backfill migration strategy.
 *
 * Copies the birth date an account already gave through a reregistration onto
 * the canonical profile field, so the one place a scheduled job reads is filled
 * for people who answered before that place existed.
 *
 * @package FreeFormCertificate\Migrations\Strategies
 * @since 6.33.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Migrations\Strategies;

use FreeFormCertificate\Core\BirthDate;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\UserDashboard\UserProfileService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Backfill for the canonical `birth_date` profile field (#1538).
 *
 * WHERE THE VALUE WAS
 *
 * Before 6.33.0 the standard `data_nascimento` field declared no profile key,
 * so an answer landed in two places and neither is queryable: the
 * reregistration submission's `data` JSON, and the per-account snapshot in
 * `ffc_custom_fields_data` keyed `field_{id}` -- one id per audience, because
 * the standard fields are seeded once per audience. The forward path now writes
 * the profile field directly; this card is for the answers already given.
 *
 * WHICH ANSWER WINS
 *
 * An account in several audiences may hold several copies, and they can
 * disagree. The newest SUBMISSION wins, because it carries an order the
 * snapshot does not: the snapshot is one array with no timestamps, and the
 * highest field id only says which audience was seeded last. The snapshot is
 * read only for an account with no usable submission -- an operator who typed
 * the date on the user-edit screen, for instance.
 *
 * A stored value that is not a date is tried as ciphertext before it is given
 * up on: `data_nascimento` is seeded non-sensitive, but an operator can flag it
 * sensitive, and from then on both stores hold it encrypted.
 *
 * WHY IT KEEPS A CURSOR
 *
 * An account whose stored answers hold no usable date is examined, left alone,
 * and still matches the predicate -- so without a cursor it would be selected
 * again on every batch and the card would never finish (#1378). The cursor
 * advances over every account EXAMINED, and the batch counts the accounts it
 * actually FILLED, which is the number that has to reach zero for a card to be
 * trusted to stop. "Complete" therefore means every candidate was examined, not
 * that every account has a birth date; the message says which.
 */
class BirthDateBackfillMigrationStrategy implements MigrationStrategyInterface {

	/**
	 * Where the cursor lives. Listed in `uninstall.php`.
	 *
	 * @var string
	 */
	private const STATE_OPTION = 'ffc_birth_date_backfill_state';

	/**
	 * How many accounts one batch examines.
	 *
	 * @var int
	 */
	private const BATCH_SIZE = 100;

	/**
	 * The standard field this card reads. A stored value, so it is not renamed.
	 *
	 * @var string
	 */
	private const FIELD_KEY = 'data_nascimento';

	/**
	 * Snapshot usermeta key -- `CustomFieldReader::USER_META_KEY`, named here
	 * rather than imported so the migration does not depend on the
	 * reregistration module being loaded.
	 *
	 * @var string
	 */
	private const SNAPSHOT_META_KEY = 'ffc_custom_fields_data';

	/**
	 * The canonical meta key the predicate excludes.
	 *
	 * @var string
	 */
	private const CANONICAL_META_KEY = 'ffc_user_birth_date';

	/**
	 * Field ids of every `data_nascimento` row, memoised for one request.
	 *
	 * @var array<int, int>|null
	 */
	private ?array $field_ids = null;

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $migration_key    Migration identifier.
	 * @param array<string, mixed> $migration_config Migration configuration.
	 * @return array<string, mixed>
	 */
	public function calculate_status( string $migration_key, array $migration_config ): array {
		$total   = $this->count_candidates( 0 );
		$pending = $this->count_candidates( $this->get_cursor() );

		$examined = max( 0, $total - $pending );
		$percent  = ( $total > 0 ) ? ( $examined / $total ) * 100 : 100;

		return array(
			'total'       => $total,
			'migrated'    => $examined,
			'pending'     => $pending,
			'percent'     => round( $percent, 2 ),
			'is_complete' => ( 0 === $pending ),
		);
	}

	/**
	 * Fill one batch of accounts.
	 *
	 * @param string               $migration_key    Migration identifier.
	 * @param array<string, mixed> $migration_config Migration configuration.
	 * @param int                  $batch_number     Ignored; the cursor is the position.
	 * @return array<string, mixed>
	 */
	public function execute( string $migration_key, array $migration_config, int $batch_number = 0 ): array {
		$batch_size = isset( $migration_config['batch_size'] ) && is_numeric( $migration_config['batch_size'] )
			? max( 1, (int) $migration_config['batch_size'] )
			: self::BATCH_SIZE;

		$users = $this->next_candidates( $this->get_cursor(), $batch_size );

		if ( array() === $users ) {
			return array(
				'success'   => true,
				'processed' => 0,
				'message'   => __( 'Every account with a stored birth date has been examined.', 'ffcertificate' ),
			);
		}

		$filled = 0;

		foreach ( $users as $user_id ) {
			$iso = $this->birth_date_for( $user_id );

			if ( null !== $iso && $this->write_birth_date( $user_id, $iso ) ) {
				++$filled;
			}

			// Advanced whether or not a date was found -- see the class
			// docblock for why termination cannot depend on the repair.
			$this->set_cursor( $user_id );
		}

		return array(
			'success'   => true,
			'processed' => $filled,
			'message'   => sprintf(
				/* translators: 1: accounts examined. 2: accounts given a birth date. */
				__( 'Examined %1$d accounts and filled the birth date of %2$d; the rest hold no usable date.', 'ffcertificate' ),
				count( $users ),
				$filled
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $migration_key    Migration identifier.
	 * @param array<string, mixed> $migration_config Migration configuration.
	 * @return bool|\WP_Error
	 */
	public function can_run( string $migration_key, array $migration_config ) {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'birth_date_backfill';
	}

	/**
	 * The birth date one account already gave, in canonical form.
	 *
	 * @param int $user_id The account.
	 * @return string|null `Y-m-d`, or null when nothing stored is a date.
	 */
	private function birth_date_for( int $user_id ): ?string {
		foreach ( $this->submitted_values( $user_id ) as $value ) {
			$iso = $this->as_date( $value );
			if ( null !== $iso ) {
				return $iso;
			}
		}

		$snapshot = get_user_meta( $user_id, self::SNAPSHOT_META_KEY, true );
		if ( ! is_array( $snapshot ) ) {
			return null;
		}

		// Highest field id first: with no timestamp in the snapshot, the
		// audience seeded last is the only order there is.
		$ids = $this->field_ids();
		rsort( $ids );

		foreach ( $ids as $field_id ) {
			$iso = $this->as_date( $snapshot[ 'field_' . $field_id ] ?? null );
			if ( null !== $iso ) {
				return $iso;
			}
		}

		return null;
	}

	/**
	 * The `data_nascimento` answers of an account's submissions, newest first.
	 *
	 * @param int $user_id The account.
	 * @return array<int, mixed>
	 */
	private function submitted_values( int $user_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, for which WordPress exposes no API; a migration must see the live rows.
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT data FROM %i WHERE user_id = %d ORDER BY id DESC LIMIT 20',
				$wpdb->prefix . 'ffc_reregistration_submissions',
				$user_id
			)
		);

		$out = array();
		foreach ( (array) $rows as $raw ) {
			$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
			if ( is_array( $data ) && is_array( $data['fields'] ?? null ) && array_key_exists( self::FIELD_KEY, $data['fields'] ) ) {
				$out[] = $data['fields'][ self::FIELD_KEY ];
			}
		}

		return $out;
	}

	/**
	 * A stored value as a canonical date, decrypting it when it is not one.
	 *
	 * @param mixed $value Stored value.
	 * @return string|null
	 */
	private function as_date( $value ): ?string {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return null;
		}

		$iso = BirthDate::normalize( $value );
		if ( null !== $iso ) {
			return $iso;
		}

		$plain = $this->decrypt( $value );
		return null === $plain ? null : BirthDate::normalize( $plain );
	}

	/**
	 * Write the canonical field. A seam, so a test can assert the write
	 * without standing up the profile service.
	 *
	 * @param int    $user_id The account.
	 * @param string $iso     Canonical date.
	 * @return bool Whether the write landed.
	 */
	protected function write_birth_date( int $user_id, string $iso ): bool {
		return UserProfileService::write( $user_id, array( 'birth_date' => $iso ) );
	}

	/**
	 * Decryption as a seam, so a batch can be driven without a key.
	 *
	 * @param string $cipher Stored ciphertext.
	 * @return string|null
	 */
	protected function decrypt( string $cipher ): ?string {
		return class_exists( Encryption::class ) ? Encryption::decrypt( $cipher ) : null;
	}

	/**
	 * Ids of every `data_nascimento` field row, across audiences.
	 *
	 * @return array<int, int>
	 */
	private function field_ids(): array {
		if ( null !== $this->field_ids ) {
			return $this->field_ids;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, for which WordPress exposes no API; a migration must see the live rows.
		$found = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE field_key = %s',
				$wpdb->prefix . 'ffc_custom_fields',
				self::FIELD_KEY
			)
		);

		$this->field_ids = array_map( 'intval', (array) $found );

		return $this->field_ids;
	}

	/**
	 * How many accounts match the predicate beyond one cursor.
	 *
	 * The predicate: no canonical birth date yet, and at least one store this
	 * card reads -- a reregistration submission or a field snapshot.
	 *
	 * @param int $after Only accounts with a greater id.
	 * @return int
	 */
	private function count_candidates( int $after ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- `wp_users` and `wp_usermeta` are named per line rather than under a file-level disable: the only interpolation is the core `$wpdb->users` / `$wpdb->usermeta` properties, and every value travels as a placeholder. A migration card must reflect the live rows, never a cache.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . $wpdb->users . ' u WHERE u.ID > %d'
				. ' AND NOT EXISTS ( SELECT 1 FROM ' . $wpdb->usermeta . ' b WHERE b.user_id = u.ID AND b.meta_key = %s )'
				. ' AND ( EXISTS ( SELECT 1 FROM ' . $wpdb->usermeta . ' s WHERE s.user_id = u.ID AND s.meta_key = %s )'
				. '       OR EXISTS ( SELECT 1 FROM %i r WHERE r.user_id = u.ID ) )',
				$after,
				self::CANONICAL_META_KEY,
				self::SNAPSHOT_META_KEY,
				$wpdb->prefix . 'ffc_reregistration_submissions'
			)
		);
	}

	/**
	 * The next accounts matching the predicate, in id order.
	 *
	 * @param int $after Only accounts with a greater id.
	 * @param int $limit How many.
	 * @return array<int, int>
	 */
	private function next_candidates( int $after, int $limit ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- `wp_users` and `wp_usermeta` are named per line rather than under a file-level disable: the only interpolation is the core `$wpdb->users` / `$wpdb->usermeta` properties, and every value travels as a placeholder. A migration card must reflect the live rows, never a cache.
		$found = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT u.ID FROM ' . $wpdb->users . ' u WHERE u.ID > %d'
				. ' AND NOT EXISTS ( SELECT 1 FROM ' . $wpdb->usermeta . ' b WHERE b.user_id = u.ID AND b.meta_key = %s )'
				. ' AND ( EXISTS ( SELECT 1 FROM ' . $wpdb->usermeta . ' s WHERE s.user_id = u.ID AND s.meta_key = %s )'
				. '       OR EXISTS ( SELECT 1 FROM %i r WHERE r.user_id = u.ID ) )'
				. ' ORDER BY u.ID ASC LIMIT %d',
				$after,
				self::CANONICAL_META_KEY,
				self::SNAPSHOT_META_KEY,
				$wpdb->prefix . 'ffc_reregistration_submissions',
				$limit
			)
		);

		return array_map( 'intval', (array) $found );
	}

	/**
	 * The cursor, or zero when the walk has not started.
	 *
	 * @return int
	 */
	private function get_cursor(): int {
		$state  = get_option( self::STATE_OPTION, array() );
		$cursor = is_array( $state ) ? ( $state['cursor'] ?? null ) : null;

		return is_numeric( $cursor ) ? (int) $cursor : 0;
	}

	/**
	 * Advance the cursor.
	 *
	 * @param int $user_id The account just examined.
	 * @return void
	 */
	private function set_cursor( int $user_id ): void {
		update_option( self::STATE_OPTION, array( 'cursor' => $user_id ), false );
	}
}
