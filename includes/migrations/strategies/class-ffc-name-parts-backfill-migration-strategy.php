<?php
/**
 * Name-parts backfill migration strategy.
 *
 * Splits the full name the plugin stores into WordPress's first and last name
 * on accounts that carry it whole in `first_name`, or not at all.
 *
 * @package FreeFormCertificate\Migrations\Strategies
 * @since 6.33.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Migrations\Strategies;

use FreeFormCertificate\Core\PersonName;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Repair for the whole name stored in `first_name`.
 *
 * Until 6.33.0 account creation and the display-name card wrote the full name
 * into `first_name` and left `last_name` empty, so WordPress's profile screen
 * showed "Maria da Silva" as a first name. Every write of the plugin's name now
 * derives both parts (Core\PersonName); this card fixes the accounts written
 * before.
 *
 * WHICH ACCOUNTS. Only those with a plugin profile, and only where the two
 * parts are still what the old code left: `last_name` empty, and `first_name`
 * empty or equal to the full name. Parts somebody filled in by hand are not
 * touched.
 *
 * WHICH NAME. The profile's name, unless it is empty or the login / address
 * WordPress falls back to -- profiles created before #1480 copied that
 * fallback -- and then the account's display name under the same test.
 *
 * WHY IT KEEPS A CURSOR. A one-word name splits into itself and an empty last
 * name, so the account still matches the predicate after it is processed;
 * without a cursor it would be selected on every batch (#1378). The batch
 * counts the accounts it CHANGED.
 */
class NamePartsBackfillMigrationStrategy implements MigrationStrategyInterface {

	/**
	 * Where the cursor lives. Listed in `uninstall.php`.
	 *
	 * @var string
	 */
	private const STATE_OPTION = 'ffc_name_parts_backfill_state';

	/**
	 * How many accounts one batch examines.
	 *
	 * @var int
	 */
	private const BATCH_SIZE = 100;

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
	 * Split the names of one batch of accounts.
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

		$rows = $this->next_candidates( $this->get_cursor(), $batch_size );

		if ( array() === $rows ) {
			return array(
				'success'   => true,
				'processed' => 0,
				'message'   => __( 'Every account whose name was stored whole has been examined.', 'ffcertificate' ),
			);
		}

		$changed = 0;

		foreach ( $rows as $row ) {
			$name = self::name_of( $row );
			if ( '' !== $name ) {
				$parts = PersonName::split( $name );
				if ( $parts['first'] !== $row['first_name'] || '' !== $parts['last'] ) {
					$this->write_parts( $row['user_id'], $parts['first'], $parts['last'] );
					++$changed;
				}
			}

			// Advanced whatever happened: a one-word name stays in the
			// predicate, and termination must not depend on the repair.
			$this->set_cursor( $row['user_id'] );
		}

		return array(
			'success'   => true,
			'processed' => $changed,
			'message'   => sprintf(
				/* translators: 1: accounts examined. 2: accounts whose first and last name were rewritten. */
				__( 'Examined %1$d accounts and split the name of %2$d; the rest have a one-word name or none.', 'ffcertificate' ),
				count( $rows ),
				$changed
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
		return 'name_parts_backfill';
	}

	/**
	 * The full name to split: the profile's, unless it is the login or
	 * address WordPress falls back to, then the display name's.
	 *
	 * @param array{user_id: int, profile_name: string, display_name: string, login: string, email: string, first_name: string} $row Candidate.
	 * @return string
	 */
	public static function name_of( array $row ): string {
		foreach ( array( $row['profile_name'], $row['display_name'] ) as $candidate ) {
			$name = PersonName::normalize( $candidate );
			if ( '' !== $name && $name !== $row['login'] && $name !== $row['email'] ) {
				return $name;
			}
		}
		return '';
	}

	/**
	 * Write both parts. A seam for tests.
	 *
	 * Through wp_update_user(), the API for WordPress's own fields. Its
	 * `profile_update` reaches the name sync, which finds the parts already
	 * make the profile's name -- or, where the profile still held the login
	 * fallback and the name came from the display name, corrects the profile.
	 *
	 * @param int    $user_id The account.
	 * @param string $first   First name.
	 * @param string $last    Last name.
	 * @return void
	 */
	protected function write_parts( int $user_id, string $first, string $last ): void {
		wp_update_user(
			array(
				'ID'         => $user_id,
				'first_name' => $first,
				'last_name'  => $last,
			)
		);
	}

	/**
	 * The predicate's SQL after `SELECT`, shared by the count and the page.
	 *
	 * @return string
	 */
	private function from_where(): string {
		global $wpdb;

		return ' FROM ' . $wpdb->users . ' u'
			. ' INNER JOIN %i p ON p.user_id = u.ID'
			. ' LEFT JOIN ' . $wpdb->usermeta . " fn ON fn.user_id = u.ID AND fn.meta_key = 'first_name'"
			. ' LEFT JOIN ' . $wpdb->usermeta . " ln ON ln.user_id = u.ID AND ln.meta_key = 'last_name'"
			. ' WHERE u.ID > %d'
			. " AND ( ln.meta_value IS NULL OR ln.meta_value = '' )"
			. " AND ( fn.meta_value IS NULL OR fn.meta_value = '' OR fn.meta_value = u.display_name OR fn.meta_value = p.display_name )";
	}

	/**
	 * How many accounts match the predicate beyond one cursor.
	 *
	 * @param int $after Only accounts with a greater id.
	 * @return int
	 */
	private function count_candidates( int $after ): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- `wp_users` and `wp_usermeta` are named in this block rather than under a file-level disable: the only interpolation is the predicate from from_where(), built from the core `$wpdb->users` / `$wpdb->usermeta` properties, and every value travels as a placeholder the sniff cannot see through the helper. A migration card must reflect the live rows, never a cache.
		$count = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Core tables, annotated per line; see above.
			$wpdb->prepare(
				'SELECT COUNT(*)' . $this->from_where(),
				$wpdb->prefix . 'ffc_user_profiles',
				$after
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		return (int) $count;
	}

	/**
	 * The next accounts matching the predicate, in id order.
	 *
	 * @param int $after Only accounts with a greater id.
	 * @param int $limit How many.
	 * @return array<int, array{user_id: int, profile_name: string, display_name: string, login: string, email: string, first_name: string}>
	 */
	private function next_candidates( int $after, int $limit ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- `wp_users` and `wp_usermeta` are named in this block rather than under a file-level disable: the only interpolation is the predicate from from_where(), built from the core `$wpdb->users` / `$wpdb->usermeta` properties, and every value travels as a placeholder the sniff cannot see through the helper. A migration card must reflect the live rows, never a cache.
		$found = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Core tables, annotated per line; see above.
			$wpdb->prepare(
				'SELECT u.ID AS user_id, p.display_name AS profile_name, u.display_name, u.user_login AS login, u.user_email AS email, fn.meta_value AS first_name'
				. $this->from_where()
				. ' ORDER BY u.ID ASC LIMIT %d',
				$wpdb->prefix . 'ffc_user_profiles',
				$after,
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$out = array();
		foreach ( (array) $found as $row ) {
			if ( ! is_array( $row ) || ! is_numeric( $row['user_id'] ?? null ) ) {
				continue;
			}
			$out[] = array(
				'user_id'      => (int) $row['user_id'],
				'profile_name' => is_string( $row['profile_name'] ?? null ) ? $row['profile_name'] : '',
				'display_name' => is_string( $row['display_name'] ?? null ) ? $row['display_name'] : '',
				'login'        => is_string( $row['login'] ?? null ) ? $row['login'] : '',
				'email'        => is_string( $row['email'] ?? null ) ? $row['email'] : '',
				'first_name'   => is_string( $row['first_name'] ?? null ) ? $row['first_name'] : '',
			);
		}
		return $out;
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
