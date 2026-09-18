<?php
/**
 * IdentityNormalizationMigrationStrategy
 *
 * Rewrites the identifiers already stored so they carry the canonical form
 * `SensitiveFieldRegistry` now applies on every write (#1313 PR 3).
 *
 * WHAT WAS WRONG, MEASURED PER STORE
 *
 * The hash function was always one -- salted SHA-256. What was not one thing
 * was the string handed to it, and the divergences are two, not five:
 *
 *   - `ffc_self_scheduling_appointments` wrote the e-mail through
 *     `sanitize_email()`, which strips invalid characters and does NOT
 *     lowercase, while the certificate, recruitment and reregistration paths
 *     all lowercased. Someone booking as `Joao@Escola.gov.br` therefore wrote
 *     an `email_hash` no other module's lookup could ever find.
 *   - `UserProfileService` hashed the CPF exactly as handed to it, so a
 *     reregistration storing the masked `123.456.789-09` -- what the form's own
 *     input mask produces -- wrote a hash of the punctuation.
 *
 * The other stores were already canonical **at today's write sites**, which is
 * not the same as saying their rows are. A row written by an older release
 * predates the sanitiser that makes that true, and nothing recorded which
 * release wrote which row. So the card walks every one of them: the cost of
 * looking is a decryption per row, and the cost of assuming is an identifier
 * nobody can find, with no way to tell the two apart afterwards.
 *
 * IDEMPOTENCE IS TESTED ON THE PLAINTEXT, NEVER ON THE CIPHERTEXT
 *
 * `Encryption::encrypt()` uses a random IV, so re-encrypting identical
 * plaintext yields a different ciphertext every time. A card that compared
 * ciphertexts would rewrite every row on every run and never reach 0 pending.
 * The test is `normalize( $plain ) === $plain`, plus `hash_equals()` on the
 * lookup hash -- both deterministic, so a second run writes nothing.
 *
 * THE HASH IS CHECKED EVEN WHEN THE PLAINTEXT IS ALREADY CANONICAL
 *
 * The issue's plan stops at the plaintext, and for every write path in the
 * tree that is enough: `encrypt_fields()` hashes the same string it encrypts.
 * But that is a property of the code as it stands, not of the rows, and the
 * defect class this card closes is precisely "a value written under a rule
 * nobody recorded". Re-deriving the hash costs one SHA-256 and no write when
 * it already agrees, so `0 pending` is allowed to mean the stronger thing.
 *
 * WHAT IT DELIBERATELY DOES NOT TOUCH
 *
 *   - **`ticket_hash`** (submissions) is hash-only: there is no ciphertext to
 *     decrypt, so a non-canonical ticket hash cannot be repaired from what was
 *     stored. Measured, it never needed to be -- `SubmissionHandler` uppercases
 *     and trims at the single write site, and always did.
 *   - **The reregistration `data` JSON.** #1313 lists it as a fourth store, and
 *     measuring it refutes that: it carries no hash and nothing searches it
 *     (there is no `data LIKE` or `JSON_EXTRACT` against that table anywhere).
 *     Its one downstream consumer is `sync_profile()`, which routes through
 *     `UserProfileService::write()` -- and that normalises on write since PR 1.
 *     So no lookup is broken by it and the store it feeds is corrected anyway;
 *     re-encrypting every body would be an unbounded rewrite for a cosmetic
 *     change to a display value.
 *   - **The two import staging tables**, whose `cpf_normalized` / `rf_normalized`
 *     are cleartext by design: in-flight data with a TTL, kept plain so
 *     validation can show an operator the malformed value they typed.
 *
 * THE RE-ARM IS KEYED ON THE RULES, NOT ON A KEY
 *
 * "Complete" here means "every row matched the canonical form as defined when
 * the card ran". Change a rule and that sentence stops being true, so the state
 * carries `SensitiveFieldRegistry::normalizer_fingerprint()` and a changed map
 * re-arms the card from zero -- the same shape the key-rotation card uses for
 * the encryption key, and for the same reason: the alternative is remembering,
 * and what is forgotten here is a row nobody can find.
 *
 * @package FreeFormCertificate
 * @since   6.26.0
 */

namespace FreeFormCertificate\Migrations\Strategies;

use FreeFormCertificate\Core\ArrayValue;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Core\SensitiveFieldRegistry;
use FreeFormCertificate\Repositories\UserProfileRepository;
use WP_Error;

/*
 * NO file-level `phpcs:disable`, for the reason #1236 records on the sibling
 * strategy: the profile target reads `wp_usermeta`, a core table, so a
 * file-level `WordPress.DB.DirectDatabaseQuery` disable would claim a
 * justification this class does not have. Annotated per line instead.
 */

/**
 * Brings stored identifiers into the canonical form the registry now applies.
 */
class IdentityNormalizationMigrationStrategy implements MigrationStrategyInterface {

	/**
	 * Option holding cursors + rule fingerprint + completion.
	 */
	private const STATE_OPTION = 'ffc_identity_normalization_state';

	/**
	 * Rows per batch.
	 *
	 * Lower than the sibling card's 50 because every row here is decrypted
	 * field by field -- up to three `openssl_decrypt` calls plus an HMAC
	 * verification each -- on a page load an administrator is watching.
	 */
	private const BATCH_SIZE = 25;

	/**
	 * Target key: `ffc_submissions`.
	 */
	private const TARGET_SUBMISSION = 'submissions';

	/**
	 * Target key: `ffc_self_scheduling_appointments`.
	 */
	private const TARGET_APPOINTMENT = 'appointments';

	/**
	 * Target key: `ffc_recruitment_candidate`.
	 */
	private const TARGET_RECRUITMENT = 'recruitment_candidates';

	/**
	 * Target key: the sensitive user-profile meta and its identity index.
	 */
	private const TARGET_USER_PROFILE = 'user_profile';

	/**
	 * Encrypted usermeta key => the `ffc_user_profiles` column holding its hash.
	 *
	 * THE NAMES ARE LITERALS HERE ON PURPOSE, exactly as they are in
	 * `KeyRotationRemainingMigrationStrategy`: the source of truth is
	 * `UserProfileFieldMap`, in the UserDashboard module, and importing it
	 * would create a `Migrations > UserDashboard` edge that is not in
	 * `ModuleBoundaryTest`'s baseline. `IdentityNormalizationTargetsTest`
	 * lives outside the module graph and fails when the two disagree.
	 *
	 * `ffc_user_rg` is absent because it is not hash-searchable and has no
	 * canonical form -- there is nothing to normalise and nothing to look up.
	 *
	 * @var array<string, string>
	 */
	private const PROFILE_FIELDS = array(
		'cpf' => 'cpf_hash',
		'rf'  => 'rf_hash',
	);

	/**
	 * Usermeta key prefix the profile's encrypted values live under.
	 *
	 * Pinned for the same reason as the map above; the agreement test compares
	 * it with `UserManager::EXTENDED_META_PREFIX`.
	 */
	private const PROFILE_META_PREFIX = 'ffc_user_';

	/**
	 * Targets, in the order the batches walk them.
	 *
	 * @return array<int, string>
	 */
	private function targets(): array {
		return array(
			self::TARGET_SUBMISSION,
			self::TARGET_APPOINTMENT,
			self::TARGET_RECRUITMENT,
			self::TARGET_USER_PROFILE,
		);
	}

	/**
	 * The registry context a table target writes through.
	 *
	 * @param string $target Target key.
	 * @return string Empty for a target with no registry context.
	 */
	private function context_for( string $target ): string {
		switch ( $target ) {
			case self::TARGET_SUBMISSION:
				return SensitiveFieldRegistry::CONTEXT_SUBMISSION;
			case self::TARGET_APPOINTMENT:
				return SensitiveFieldRegistry::CONTEXT_APPOINTMENT;
			case self::TARGET_RECRUITMENT:
				return SensitiveFieldRegistry::CONTEXT_RECRUITMENT_CANDIDATE;
		}

		return '';
	}

	/**
	 * Full table name for a table target.
	 *
	 * @param string $target Target key.
	 * @return string Empty for a target with no table of its own.
	 */
	private function table_for( string $target ): string {
		global $wpdb;

		switch ( $target ) {
			case self::TARGET_SUBMISSION:
				return $wpdb->prefix . 'ffc_submissions';
			case self::TARGET_APPOINTMENT:
				return $wpdb->prefix . 'ffc_self_scheduling_appointments';
			case self::TARGET_RECRUITMENT:
				return $wpdb->prefix . 'ffc_recruitment_candidate';
		}

		return '';
	}

	/**
	 * The fields of one context this card can actually repair.
	 *
	 * DERIVED FROM THE REGISTRY, NEVER LISTED. The registry already declares
	 * which column holds a field's ciphertext, which holds its hash, and which
	 * fields have a canonical form; repeating any of that here would be a
	 * second declaration to keep in step, which is the defect #1313 exists to
	 * remove rather than to reproduce.
	 *
	 * Two conditions, and both are load-bearing. A field needs a **canonical
	 * form**, or there is nothing to correct (`user_ip`, `phone`, `data`). And
	 * it needs an **encrypted column**, or the plaintext to correct it from was
	 * never stored -- which is exactly `ticket`, hash-only by design.
	 *
	 * @param string $context Registry context key.
	 * @return array<string, array{encrypted_column: ?string, hash_column: ?string}>
	 */
	private function repairable_fields( string $context ): array {
		$normalized = SensitiveFieldRegistry::normalized_field_keys();
		$out        = array();

		foreach ( SensitiveFieldRegistry::fields_for( $context ) as $field_key => $spec ) {
			if ( ! in_array( $field_key, $normalized, true ) ) {
				continue;
			}
			if ( null === $spec['encrypted_column'] || '' === $spec['encrypted_column'] ) {
				continue;
			}
			$out[ $field_key ] = $spec;
		}

		return $out;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $migration_key    Registry key.
	 * @param array<string, mixed> $migration_config Registry config.
	 * @return array<string, mixed>
	 */
	public function calculate_status( string $migration_key, array $migration_config ): array {
		unset( $migration_key, $migration_config );

		$total = $this->count_total();

		// A changed rule invalidates every row the card previously approved.
		if ( ! $this->fingerprint_matches() ) {
			return array(
				'total'       => $total,
				'migrated'    => 0,
				'pending'     => $total,
				'percent'     => ( $total > 0 ) ? 0.0 : 100.0,
				'is_complete' => ( 0 === $total ),
			);
		}

		if ( $this->is_completed() ) {
			return array(
				'total'       => $total,
				'migrated'    => $total,
				'pending'     => 0,
				'percent'     => 100.0,
				'is_complete' => true,
			);
		}

		$migrated = $this->count_migrated();
		$pending  = max( 0, $total - $migrated );

		return array(
			'total'       => $total,
			'migrated'    => $migrated,
			'pending'     => $pending,
			'percent'     => ( $total > 0 ) ? round( ( $migrated / $total ) * 100, 2 ) : 100.0,
			'is_complete' => ( 0 === $pending ),
		);
	}

	/**
	 * Rows carrying at least one repairable ciphertext, across every target.
	 *
	 * PROGRESS IS THE CURSOR, NOT THE CONTENT. Counting rows that genuinely
	 * need rewriting would mean decrypting the whole database to draw a
	 * progress bar. The card therefore reports how far it has WALKED, which is
	 * the same convention the key-rotation card uses, and the rows it walked
	 * and left alone are the majority by design.
	 *
	 * @return int
	 */
	private function count_total(): int {
		$total = 0;
		foreach ( $this->targets() as $target ) {
			$total += $this->count_target( $target, false );
		}

		return $total;
	}

	/**
	 * Rows already behind their target's cursor.
	 *
	 * @return int
	 */
	private function count_migrated(): int {
		$migrated = 0;
		foreach ( $this->targets() as $target ) {
			$migrated += $this->count_target( $target, true );
		}

		return $migrated;
	}

	/**
	 * Count one target, optionally only what the cursor has passed.
	 *
	 * @param string $target        Target key.
	 * @param bool   $behind_cursor Restrict to rows already walked.
	 * @return int
	 */
	private function count_target( string $target, bool $behind_cursor ): int {
		global $wpdb;

		if ( self::TARGET_USER_PROFILE === $target ) {
			return $this->count_user_profile( $behind_cursor );
		}

		$table = $this->table_for( $target );
		if ( '' === $table || ! $this->table_exists( $table ) ) {
			return 0;
		}

		$fields = $this->repairable_fields( $this->context_for( $target ) );
		if ( array() === $fields ) {
			return 0;
		}

		$clauses = array();
		$values  = array( $table );
		foreach ( $fields as $spec ) {
			$clauses[] = '%i IS NOT NULL';
			$values[]  = (string) $spec['encrypted_column'];
		}

		$where = '( ' . implode( ' OR ', $clauses ) . ' )';

		if ( $behind_cursor ) {
			$where   .= ' AND id <= %d';
			$values[] = $this->get_cursor( $target );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The interpolated fragment is built here from placeholders alone, one per declared column; every value, the table and the column names included, is bound through prepare(). A cached answer is exactly what a migration cursor must not take.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$where}", $values ) );
	}

	/**
	 * How many USERS carry a sensitive profile meta this card would consider.
	 *
	 * Distinct users rather than meta rows, for the reason the sibling card
	 * records: a user with two metas is one unit of work, and counting rows
	 * would make the status maths disagree with what a batch consumes.
	 *
	 * @param bool $behind_cursor Restrict to users already walked.
	 * @return int
	 */
	private function count_user_profile( bool $behind_cursor ): int {
		global $wpdb;

		$meta_keys    = $this->profile_meta_keys();
		$placeholders = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );

		$values   = array( $wpdb->usermeta );
		$values   = array_merge( $values, $meta_keys );
		$values[] = '%' . $wpdb->esc_like( Encryption::V2_PREFIX ) . '%';

		$cursor_sql = '';
		if ( $behind_cursor ) {
			$cursor_sql = ' AND user_id <= %d';
			$values[]   = $this->get_cursor( self::TARGET_USER_PROFILE );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fragments generated here from the key count plus a fixed cursor literal; every value, the table included, goes through prepare(). A migration read must not be cached.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT user_id) FROM %i WHERE meta_key IN ({$placeholders}) AND meta_value LIKE %s{$cursor_sql}", $values ) );
	}

	/**
	 * The usermeta keys holding the profile's repairable ciphertext.
	 *
	 * @return array<int, string>
	 */
	private function profile_meta_keys(): array {
		$keys = array();
		foreach ( array_keys( self::PROFILE_FIELDS ) as $field_key ) {
			$keys[] = self::PROFILE_META_PREFIX . $field_key;
		}

		return $keys;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $migration_key    Registry key.
	 * @param array<string, mixed> $migration_config Registry config.
	 * @param int                  $batch_number     Batch counter.
	 * @return array<string, mixed>
	 */
	public function execute( string $migration_key, array $migration_config, int $batch_number = 0 ): array {
		unset( $migration_key, $migration_config, $batch_number );

		$can_run = $this->can_run( '', array() );
		if ( $can_run instanceof WP_Error ) {
			return array(
				'success' => false,
				'message' => $can_run->get_error_message(),
			);
		}

		$this->stamp_fingerprint();

		$result = array(
			'processed' => 0,
			'errors'    => array(),
		);

		// One target per batch: the first with rows ahead of its own cursor.
		// Mixing targets inside a batch would make the cursor ambiguous and
		// stop it resuming from where it left off.
		foreach ( $this->targets() as $target ) {
			if ( $this->count_target( $target, false ) <= $this->count_target( $target, true ) ) {
				continue;
			}

			$result = ( self::TARGET_USER_PROFILE === $target )
				? $this->migrate_user_profile_batch()
				: $this->migrate_table_batch( $target );
			break;
		}

		$status = $this->calculate_status( '', array() );
		if ( 0 === $status['pending'] && empty( $result['errors'] ) ) {
			$this->mark_completed();
		}

		return array(
			'success'   => true,
			'processed' => $result['processed'],
			'pending'   => $status['pending'],
			'has_more'  => $status['pending'] > 0,
			'errors'    => $result['errors'],
		);
	}

	/**
	 * Walk one batch of a table target.
	 *
	 * The three table targets share this method rather than each having their
	 * own: what differs between a submission, an appointment and a candidate is
	 * the table name and the column map, and the registry already declares
	 * both. Three copies would be three places for the next field to be
	 * forgotten in two of them.
	 *
	 * @param string $target Target key.
	 * @return array{processed: int, errors: array<int, string>}
	 */
	private function migrate_table_batch( string $target ): array {
		global $wpdb;

		$table  = $this->table_for( $target );
		$fields = $this->repairable_fields( $this->context_for( $target ) );
		$cursor = $this->get_cursor( $target );
		$errors = array();

		if ( '' === $table || ! $this->table_exists( $table ) || array() === $fields ) {
			return array(
				'processed' => 0,
				'errors'    => $errors,
			);
		}

		$columns = array( 'id' );
		foreach ( $fields as $spec ) {
			$columns[] = (string) $spec['encrypted_column'];
			if ( null !== $spec['hash_column'] ) {
				$columns[] = (string) $spec['hash_column'];
			}
		}

		// The column list is placeholders, not interpolated names: `%i` escapes
		// an identifier the way `%s` escapes a value, so the statement carries
		// no column name of its own even though the set is built here.
		$select   = implode( ', ', array_fill( 0, count( $columns ), '%i' ) );
		$values   = $columns;
		$values[] = $table;
		$values[] = $cursor;
		$values[] = self::BATCH_SIZE;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The interpolated fragment is placeholders alone, one per column the registry declares; every value, the columns and the table included, is bound through prepare() from the array below it.
		$sql = $wpdb->prepare( "SELECT {$select} FROM %i WHERE id > %d ORDER BY id ASC LIMIT %d", $values );

		/**
		 * Explicit row typing, the idiom the row-reading classes use: without
		 * it `get_results()` is `mixed` to the analyser and the "Row shapes
		 * (level 9)" gate rejects every offset access.
		 *
		 * @var list<array<string, string|null>>|null $rows
		 */
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $sql is the prepared statement built on the line above; a migration cursor must not read a cached answer.
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		if ( ! is_array( $rows ) || array() === $rows ) {
			// Nothing ahead of the cursor: park it at the end so the status
			// maths reports complete instead of stalling one row short.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads the plugin's own ffc_* table to park the cursor at the end; WordPress exposes no API for it and a migration must not read a cached maximum.
			$max_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(id), 0) FROM %i', $table ) );
			if ( $max_id > $cursor ) {
				$this->set_cursor( $target, $max_id );
			}

			return array(
				'processed' => 0,
				'errors'    => $errors,
			);
		}

		$processed = 0;
		$last_id   = $cursor;

		foreach ( $rows as $row ) {
			$last_id = (int) ( $row['id'] ?? 0 );
			$updates = $this->corrections_for_row( $fields, $row, $target, $last_id, $errors );

			if ( array() === $updates ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Write against the plugin's own ffc_* table inside a migration; WordPress exposes no API for it.
			$updated = $wpdb->update( $table, $updates, array( 'id' => $last_id ) );

			if ( false === $updated ) {
				$errors[] = $this->write_failure_message( $target, $last_id );
				continue;
			}

			++$processed;
		}

		$this->set_cursor( $target, $last_id );

		return array(
			'processed' => $processed,
			'errors'    => $errors,
		);
	}

	/**
	 * The column writes one row needs, or an empty array when it needs none.
	 *
	 * @param array<string, array{encrypted_column: ?string, hash_column: ?string}> $fields Registry specs.
	 * @param array<string, string|null>                                            $row    The row as read.
	 * @param string                                                                $target Target key, for messages.
	 * @param int                                                                   $id     Row id, for messages.
	 * @param array<int, string>                                                    $errors Collected errors, by reference.
	 * @return array<string, string>
	 */
	private function corrections_for_row( array $fields, array $row, string $target, int $id, array &$errors ): array {
		$updates = array();

		foreach ( $fields as $field_key => $spec ) {
			$column = (string) $spec['encrypted_column'];
			$stored = $row[ $column ] ?? null;

			if ( ! is_string( $stored ) || '' === $stored ) {
				continue;
			}

			$plain = Encryption::decrypt( $stored );
			if ( null === $plain || '' === $plain ) {
				$errors[] = sprintf(
					/* translators: 1: column name, 2: target name, 3: row ID */
					__( 'Could not decrypt %1$s on %2$s row %3$d — left untouched (the key may be unrecoverable).', 'ffcertificate' ),
					$column,
					$target,
					$id
				);
				continue;
			}

			$canonical = SensitiveFieldRegistry::normalize( $field_key, $plain );

			// A value that normalises to nothing carried no identifier at all —
			// a CPF column holding only punctuation. Rewriting it to the empty
			// string would give every such row one shared searchable hash,
			// which is the opposite of an identifier, so it is reported and
			// left exactly as it is.
			if ( '' === $canonical ) {
				$errors[] = sprintf(
					/* translators: 1: column name, 2: target name, 3: row ID */
					__( '%1$s on %2$s row %3$d holds no identifier once canonicalised — left untouched for a human to look at.', 'ffcertificate' ),
					$column,
					$target,
					$id
				);
				continue;
			}

			if ( $canonical !== $plain ) {
				$encrypted = Encryption::encrypt( $canonical );
				if ( null === $encrypted ) {
					continue;
				}
				$updates[ $column ] = $encrypted;
			}

			if ( null === $spec['hash_column'] ) {
				continue;
			}

			$hash = SensitiveFieldRegistry::hash_identifier( $field_key, $canonical );
			if ( null === $hash ) {
				continue;
			}

			$current = $row[ (string) $spec['hash_column'] ] ?? null;
			if ( ! is_string( $current ) || ! hash_equals( $hash, $current ) ) {
				$updates[ (string) $spec['hash_column'] ] = $hash;
			}
		}

		return $updates;
	}

	/**
	 * The message for a row the database refused to write.
	 *
	 * IT NAMES THE COLLISION, BECAUSE THE COLLISION IS THE POINT.
	 * `ffc_recruitment_candidate` carries `UNIQUE KEY uq_cpf_hash` and
	 * `uq_rf_hash`, so two candidates whose stored CPFs differ only in
	 * punctuation collapse onto one hash the moment both are canonicalised, and
	 * the second write is rejected. That is not a migration defect -- it is two
	 * rows for one person, which the punctuation had been hiding, and #1313
	 * wants the count of exactly those before anyone designs a merge policy.
	 * Reporting it per row and carrying on is what produces that count.
	 *
	 * @param string $target Target key.
	 * @param int    $id     Row id.
	 * @return string
	 */
	private function write_failure_message( string $target, int $id ): string {
		if ( self::TARGET_RECRUITMENT === $target ) {
			return sprintf(
				/* translators: %d: candidate row ID */
				__( 'Candidate %d could not be canonicalised: another candidate already carries the same CPF or RF once punctuation is removed. Two rows for one person — merge them, then re-run.', 'ffcertificate' ),
				$id
			);
		}

		return sprintf(
			/* translators: 1: target name, 2: row ID */
			__( 'Could not write the canonicalised identifiers for %1$s row %2$d.', 'ffcertificate' ),
			$target,
			$id
		);
	}

	/**
	 * Walk one batch of users' sensitive profile meta.
	 *
	 * Paged by USER rather than by meta row: a user carries up to two encrypted
	 * metas, and paging by row while advancing a `user_id` cursor would leave
	 * behind whatever fell on the far side of a page boundary, with the cursor
	 * already past it.
	 *
	 * @return array{processed: int, errors: array<int, string>}
	 */
	private function migrate_user_profile_batch(): array {
		global $wpdb;

		$cursor = $this->get_cursor( self::TARGET_USER_PROFILE );
		$errors = array();

		$meta_keys    = $this->profile_meta_keys();
		$placeholders = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );

		$values   = array( $wpdb->usermeta );
		$values   = array_merge( $values, $meta_keys );
		$values[] = '%' . $wpdb->esc_like( Encryption::V2_PREFIX ) . '%';
		$values[] = $cursor;
		$values[] = self::BATCH_SIZE;

		/**
		 * A column of ids, typed explicitly because `get_col()` is `mixed`.
		 *
		 * @var list<string>|null $ids
		 */
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Placeholders generated here from the key count; every value, the table included, goes through prepare(). A migration cursor must not read a cached answer.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT user_id FROM %i WHERE meta_key IN ({$placeholders}) AND meta_value LIKE %s AND user_id > %d ORDER BY user_id ASC LIMIT %d", $values ) );

		if ( ! is_array( $ids ) || array() === $ids ) {
			return array(
				'processed' => 0,
				'errors'    => $errors,
			);
		}

		$processed  = 0;
		$last_id    = $cursor;
		$repository = new UserProfileRepository();

		foreach ( $ids as $raw_id ) {
			$user_id = (int) $raw_id;
			$last_id = $user_id;
			$index   = array();

			foreach ( self::PROFILE_FIELDS as $field_key => $hash_column ) {
				$meta_key = self::PROFILE_META_PREFIX . $field_key;
				$stored   = get_user_meta( $user_id, $meta_key, true );

				// The prefix is a cost filter, as on the sibling card: a
				// plaintext meta cannot be decrypted anyway, and this avoids
				// the call rather than the failure.
				if ( ! is_string( $stored ) || 0 !== strpos( $stored, Encryption::V2_PREFIX ) ) {
					continue;
				}

				$plain = Encryption::decrypt( $stored );
				if ( null === $plain || '' === $plain ) {
					$errors[] = sprintf(
						/* translators: 1: meta key, 2: user ID */
						__( 'Could not decrypt %1$s for user %2$d — left untouched (the key may be unrecoverable).', 'ffcertificate' ),
						$meta_key,
						$user_id
					);
					continue;
				}

				$canonical = SensitiveFieldRegistry::normalize( $field_key, $plain );

				if ( '' === $canonical ) {
					$errors[] = sprintf(
						/* translators: 1: meta key, 2: user ID */
						__( '%1$s for user %2$d holds no identifier once canonicalised — left untouched for a human to look at.', 'ffcertificate' ),
						$meta_key,
						$user_id
					);
					continue;
				}

				if ( $canonical !== $plain ) {
					$encrypted = Encryption::encrypt( $canonical );
					if ( null === $encrypted ) {
						continue;
					}
					update_user_meta( $user_id, $meta_key, $encrypted );
				}

				$hash = SensitiveFieldRegistry::hash_identifier( $field_key, $canonical );
				if ( null !== $hash ) {
					$index[ $hash_column ] = $hash;
				}
			}

			if ( array() !== $index && $this->index_needs_update( $repository, $user_id, $index ) ) {
				$repository->upsertForUserId( $user_id, $index );
			}

			++$processed;
		}

		$this->set_cursor( self::TARGET_USER_PROFILE, $last_id );

		return array(
			'processed' => $processed,
			'errors'    => $errors,
		);
	}

	/**
	 * Whether the identity index already says what this batch computed.
	 *
	 * Written only when it changes, as the key-rotation card does: a row
	 * already canonical costs one SELECT and no write, which is what keeps a
	 * second run of the card free.
	 *
	 * @param UserProfileRepository $repository The repository.
	 * @param int                   $user_id    WordPress user ID.
	 * @param array<string, string> $index      Hash column => hash.
	 * @return bool
	 */
	private function index_needs_update( UserProfileRepository $repository, int $user_id, array $index ): bool {
		$row = $repository->findByUserId( $user_id );

		foreach ( $index as $column => $hash ) {
			$stored  = null !== $row ? ( $row[ $column ] ?? null ) : null;
			$current = is_string( $stored ) ? $stored : '';
			if ( ! hash_equals( $hash, $current ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $migration_key    Registry key.
	 * @param array<string, mixed> $migration_config Registry config.
	 * @return true|WP_Error
	 */
	public function can_run( string $migration_key, array $migration_config ) {
		unset( $migration_key, $migration_config );

		if ( ! $this->encryption_available() ) {
			return new WP_Error(
				'encryption_not_configured',
				__( 'Encryption is not configured, so the stored identifiers cannot be read. See Settings → Advanced → Encryption Key Health.', 'ffcertificate' )
			);
		}

		return true;
	}

	/**
	 * Whether the decrypt/encrypt round trip is available.
	 *
	 * A seam for the same reason the sibling card has one: this path WRITES
	 * ciphertext, so a caller that skipped the gate would not merely fail, and
	 * reading the flag through an overridable method lets a test drive the
	 * guarded code without defining process-wide encryption constants.
	 *
	 * @return bool
	 */
	protected function encryption_available(): bool {
		return class_exists( Encryption::class ) && Encryption::is_configured();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function get_name(): string {
		return __( 'Canonicalise Stored Identifiers', 'ffcertificate' );
	}

	/**
	 * Whether a table exists.
	 *
	 * @param string $table Full table name.
	 * @return bool
	 */
	private function table_exists( string $table ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema probe inside a migration: the answer must reflect the live schema, so caching would be precisely wrong.
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/**
	 * Stored state.
	 *
	 * @return array<string, mixed>
	 */
	private function get_state(): array {
		$state = get_option( self::STATE_OPTION, array() );

		return is_array( $state ) ? $state : array();
	}

	/**
	 * Persist state.
	 *
	 * @param array<string, mixed> $state New state.
	 * @return void
	 */
	private function put_state( array $state ): void {
		update_option( self::STATE_OPTION, $state, false );
	}

	/**
	 * The cursor for one target.
	 *
	 * @param string $target Target key.
	 * @return int
	 */
	private function get_cursor( string $target ): int {
		$cursors = ArrayValue::array( $this->get_state(), 'cursors' );

		return isset( $cursors[ $target ] ) && is_numeric( $cursors[ $target ] ) ? (int) $cursors[ $target ] : 0;
	}

	/**
	 * Advance the cursor for one target.
	 *
	 * @param string $target Target key.
	 * @param int    $value  New cursor.
	 * @return void
	 */
	private function set_cursor( string $target, int $value ): void {
		$state              = $this->get_state();
		$cursors            = ArrayValue::array( $state, 'cursors' );
		$cursors[ $target ] = $value;
		$state['cursors']   = $cursors;

		$this->put_state( $state );
	}

	/**
	 * Whether the stored fingerprint still matches the declared rules.
	 *
	 * @return bool
	 */
	private function fingerprint_matches(): bool {
		$state = $this->get_state();

		return array_key_exists( 'fingerprint', $state )
			&& hash_equals( ArrayValue::string( $state, 'fingerprint' ), SensitiveFieldRegistry::normalizer_fingerprint() );
	}

	/**
	 * Stamp the active rule fingerprint, resetting progress when it changed.
	 *
	 * @return void
	 */
	private function stamp_fingerprint(): void {
		if ( $this->fingerprint_matches() ) {
			return;
		}

		$this->put_state(
			array(
				'fingerprint' => SensitiveFieldRegistry::normalizer_fingerprint(),
				'cursors'     => array(),
			)
		);
	}

	/**
	 * Whether the run is latched complete.
	 *
	 * @return bool
	 */
	private function is_completed(): bool {
		$state = $this->get_state();

		return ! empty( $state['completed'] );
	}

	/**
	 * Latch completion.
	 *
	 * @return void
	 */
	private function mark_completed(): void {
		$state              = $this->get_state();
		$state['completed'] = true;

		$this->put_state( $state );
	}
}
