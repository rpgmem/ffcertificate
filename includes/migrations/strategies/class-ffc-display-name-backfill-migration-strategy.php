<?php
/**
 * Display-name backfill migration strategy.
 *
 * Fills `display_name` and `first_name` on accounts that were created with
 * neither, from the name their own submission already carries.
 *
 * @package FreeFormCertificate\Migrations\Strategies
 * @since 6.30.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Migrations\Strategies;

use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Core\SubmitterName;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The submission read targets the plugin's own ffc_submissions table, for which WordPress exposes no API, and a migration must see the live rows: a cached read would name a person the records no longer name.
/**
 * Repair for accounts created without a name (#1480).
 *
 * WHAT WENT WRONG, because the shape of the defect is what bounds the repair.
 *
 * `UserCreator::sync_user_metadata()` sets `display_name` and `first_name`
 * from the submission's answers, and there is no name COLUMN on
 * `ffc_submissions` -- the name arrives under one of several per-form keys. Its
 * list of those keys was five long where the admin screens, the field
 * sanitizer and `UserManager` used six, missing `participante`. So for a form
 * keyed that way, every screen showed the person's name while the account
 * created from that very submission got neither field, and WordPress fell back
 * to writing the login into `display_name`.
 *
 * The list is one place now, so no new account is created this way. This card
 * is for the accounts already created.
 *
 * WHY THE PREDICATE IS TWO CONDITIONS AND NOT ONE
 *
 * `display_name` equal to the login is what WordPress leaves when none was
 * supplied -- a simple comparison, and on its own a wrong inference: somebody
 * may want their login as their display name. What separates the two is
 * `first_name`, because the method above writes both in one movement. An
 * account holding one and not the other was touched by something else, and
 * this leaves it alone.
 *
 * The residual is stated rather than hidden: an account whose owner
 * deliberately set the display name to the login AND has no first name is
 * indistinguishable from an affected one. In this population the login is
 * derived from the address, so it is nearly certainly the fallback -- nearly,
 * which is why the repair only ever REPLACES a name nobody chose with one the
 * person themselves typed into a form.
 *
 * WHY IT KEEPS A CURSOR, when the card beside it does not
 *
 * `CertificateCapabilityBackfillMigrationStrategy` is cursor-free because
 * every account it repairs leaves its pending set by that repair. This one
 * cannot claim that: an account whose submissions carry no name under any known
 * key is examined, left alone, and still matches the predicate. Cursor-free, it
 * would be re-selected on every batch and the card would never stop -- which is
 * exactly how that card came to process 1,848 records over ten accounts
 * (#1378). The cursor advances over every account EXAMINED, so termination does
 * not depend on the repair succeeding.
 *
 * It also means what "complete" measures here is accounts examined, not
 * accounts named. The card's description says so, because a progress bar that
 * reads a hundred per cent while somebody still has no name would be claiming
 * something it never measured.
 */
class DisplayNameBackfillMigrationStrategy implements MigrationStrategyInterface {

	/**
	 * Where the cursor lives.
	 *
	 * One option holding the cursor and nothing else, the shape
	 * `ffc_identity_index_backfill_state` uses -- and listed in
	 * `uninstall.php` beside it, since the fresh-install gate reads that file
	 * as the enforced manifest of this plugin's footprint.
	 *
	 * @var string
	 */
	private const STATE_OPTION = 'ffc_display_name_backfill_state';

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
		$total   = $this->count_nameless( 0 );
		$pending = $this->count_nameless( $this->get_cursor() );

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
	 * Name one batch of accounts from their own submissions.
	 *
	 * @param string               $migration_key    Migration identifier.
	 * @param array<string, mixed> $migration_config Migration configuration.
	 * @param int                  $batch_number     Ignored; the cursor is the position.
	 * @return array<string, mixed>
	 */
	public function execute( string $migration_key, array $migration_config, int $batch_number = 0 ): array {
		// Read after `ffcertificate_migrations_registry` has run, so a filter
		// can put anything here; `(int) 'x'` is 0 and a batch of 0 would report
		// completion having done nothing (#1060).
		$batch_size = isset( $migration_config['batch_size'] ) && is_numeric( $migration_config['batch_size'] )
			? max( 1, (int) $migration_config['batch_size'] )
			: self::BATCH_SIZE;

		$users = $this->next_nameless( $this->get_cursor(), $batch_size );

		if ( array() === $users ) {
			return array(
				'success'   => true,
				'processed' => 0,
				'message'   => __( 'Every account this card can name has been examined.', 'ffcertificate' ),
			);
		}

		$named = 0;

		foreach ( $users as $user_id ) {
			if ( '' !== $this->name_for( $user_id ) ) {
				++$named;
			}

			// ADVANCED WHETHER OR NOT A NAME WAS FOUND, which is the whole
			// reason there is a cursor: an account whose answers name nobody
			// stays in the predicate forever, and re-reading it on every batch
			// is the loop this card exists not to repeat.
			$this->set_cursor( $user_id );
		}

		return array(
			'success'   => true,
			'processed' => count( $users ),
			'message'   => sprintf(
				/* translators: 1: how many accounts were examined. 2: how many of them were given a name. */
				__( 'Examined %1$d accounts and named %2$d of them; the rest carry no name under any key this reads.', 'ffcertificate' ),
				count( $users ),
				$named
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
		return 'display_name_backfill';
	}

	/**
	 * Give one account the name its own submission carries.
	 *
	 * IT WRITES WHAT IS STORED, WITHOUT RE-NORMALISING.
	 *
	 * The forward path does not normalise either: capitalisation is applied by
	 * the field sanitizer at submission time, and `participante` was in ITS
	 * list, so the stored answer is already in the form the account would have
	 * received. Normalising here would make a repaired account differ from one
	 * created today, for no reason a reader could find.
	 *
	 * @param int $user_id The account.
	 * @return string The name written, or '' when the answers name nobody.
	 */
	private function name_for( int $user_id ): string {
		foreach ( $this->answers_of( $user_id ) as $answers ) {
			$name = SubmitterName::from( $answers );

			if ( '' === $name ) {
				continue;
			}

			$this->write_name( $user_id, $name );

			return $name;
		}

		return '';
	}

	/**
	 * Every set of answers the account submitted, newest first.
	 *
	 * Newest first because a person's name can change between submissions --
	 * married name, a correction -- and the most recent one they typed is the
	 * one they would recognise.
	 *
	 * @param int $user_id The account.
	 * @return array<int, array<mixed, mixed>> Decoded answers, in order.
	 */
	private function answers_of( int $user_id ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'ffc_submissions';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT data, data_encrypted FROM %i WHERE user_id = %d ORDER BY id DESC LIMIT 20',
				$table,
				$user_id
			),
			ARRAY_A
		);

		$out = array();

		foreach ( (array) $rows as $row ) {
			$row = (array) $row;

			// THE ENCRYPTED COPY FIRST, because it is the one kept current: the
			// plaintext column is what installs held before the answers were
			// encrypted, and a row carrying both has the ciphertext as the
			// answer.
			foreach ( array( 'data_encrypted', 'data' ) as $column ) {
				$raw = $row[ $column ] ?? '';

				if ( ! is_string( $raw ) || '' === $raw ) {
					continue;
				}

				if ( 'data_encrypted' === $column ) {
					$raw = (string) $this->decrypt( $raw );
				}

				$answers = json_decode( $raw, true );

				if ( is_array( $answers ) ) {
					$out[] = $answers;

					break;
				}
			}
		}

		return $out;
	}

	/**
	 * Write the name onto the account.
	 *
	 * A seam, so a test can assert what would be written without standing up
	 * the dozen core functions `wp_update_user()` reaches.
	 *
	 * @param int    $user_id The account.
	 * @param string $name    The name to write.
	 * @return void
	 */
	protected function write_name( int $user_id, string $name ): void {
		wp_update_user(
			array(
				'ID'           => $user_id,
				'display_name' => $name,
				'first_name'   => $name,
			)
		);
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
	 * How many accounts match the predicate beyond one cursor.
	 *
	 * @param int $after Only accounts with a greater id.
	 * @return int
	 */
	private function count_nameless( int $after ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . $wpdb->users . ' u'
				. ' LEFT JOIN ' . $wpdb->usermeta . " fn ON fn.user_id = u.ID AND fn.meta_key = 'first_name'"
				. ' WHERE u.ID > %d'
				. " AND ( u.display_name = u.user_login OR u.display_name = u.user_email OR u.display_name = '' )"
				. " AND ( fn.meta_value IS NULL OR fn.meta_value = '' )"
				. ' AND EXISTS ( SELECT 1 FROM %i s WHERE s.user_id = u.ID )',
				$after,
				$wpdb->prefix . 'ffc_submissions'
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
	private function next_nameless( int $after, int $limit ): array {
		global $wpdb;

		$found = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT u.ID FROM ' . $wpdb->users . ' u'
				. ' LEFT JOIN ' . $wpdb->usermeta . " fn ON fn.user_id = u.ID AND fn.meta_key = 'first_name'"
				. ' WHERE u.ID > %d'
				. " AND ( u.display_name = u.user_login OR u.display_name = u.user_email OR u.display_name = '' )"
				. " AND ( fn.meta_value IS NULL OR fn.meta_value = '' )"
				. ' AND EXISTS ( SELECT 1 FROM %i s WHERE s.user_id = u.ID )'
				. ' ORDER BY u.ID ASC LIMIT %d',
				$after,
				$wpdb->prefix . 'ffc_submissions',
				$limit
			)
		);

		$out = array();

		foreach ( (array) $found as $user_id ) {
			$out[] = (int) $user_id;
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

		// A non-numeric cursor restarts the walk rather than fataling: the
		// pass is idempotent, so restarting costs a re-read and never a wrong
		// write.
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
// phpcs:enable WordPress.DB.DirectDatabaseQuery
