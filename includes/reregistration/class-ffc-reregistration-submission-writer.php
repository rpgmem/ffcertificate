<?php
/**
 * Reregistration Submission Writer
 *
 * Write-side of the reregistration-submission repository split (#563 backlog,
 * Sprint D2). Holds every INSERT / UPDATE and the workflow mutators (approve,
 * reject, return-to-draft, bulk operations, token provisioning). Reads live in
 * {@see ReregistrationSubmissionReader}. Callers depend on the reader (reads)
 * and this writer (writes) directly; the delegating façade was retired in
 * #563 B3-A.
 *
 * @since   6.12.0
 * @package FreeFormCertificate\Reregistration
 *
 * @phpstan-import-type ReregistrationSubmissionRow from ReregistrationSubmissionReader
 */

declare(strict_types=1);

namespace FreeFormCertificate\Reregistration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Every statement in this class runs against one of the plugin's own ffc_* tables, which WordPress exposes no API for. Caching is decided per read at the repository layer, not per statement (#1042).
/**
 * Write operations for reregistration submission records.
 *
 * @since 6.12.0
 *
 * @phpstan-import-type ReregistrationSubmissionRow from ReregistrationSubmissionReader
 */
class ReregistrationSubmissionWriter {
	use \FreeFormCertificate\Core\StaticRepositoryTrait;

	/**
	 * Cache group for reregistration submission queries.
	 *
	 * Must match {@see ReregistrationSubmissionReader::cache_group()} so writes
	 * invalidate the entries reads populate.
	 *
	 * @return string
	 */
	protected static function cache_group(): string {
		return 'ffc_rereg_submissions';
	}

	/**
	 * Get table name.
	 *
	 * @return string
	 */
	public static function get_table_name(): string {
		return self::db()->prefix . 'ffc_reregistration_submissions';
	}

	/**
	 * Ensure a submission has a magic_token, generating one if missing.
	 *
	 * @param object $submission Submission row object.
	 * @phpstan-param ReregistrationSubmissionRow $submission
	 * @return string The magic_token (existing or newly generated).
	 */
	public static function ensure_magic_token( object $submission ): string {
		if ( ! empty( $submission->magic_token ) ) {
			return $submission->magic_token;
		}

		$token = bin2hex( random_bytes( 32 ) );
		self::update( (int) $submission->id, array( 'magic_token' => $token ) );

		return $token;
	}

	/**
	 * Create a submission record.
	 *
	 * @param array<string, mixed> $data Submission data.
	 * @return int|false Submission ID or false.
	 */
	public static function create( array $data ) {
		$wpdb  = self::db();
		$table = self::get_table_name();

		$defaults = array(
			'reregistration_id' => 0,
			'user_id'           => 0,
			'data'              => null,
			'status'            => 'pending',
			'submitted_at'      => null,
			'reviewed_at'       => null,
			'reviewed_by'       => null,
			'notes'             => null,
		);
		$data     = wp_parse_args( $data, $defaults );

		$insert_data   = array(
			'reregistration_id' => (int) $data['reregistration_id'],
			'user_id'           => (int) $data['user_id'],
			'status'            => $data['status'],
		);
		$insert_format = array( '%d', '%d', '%s' );

		if ( null !== $data['data'] ) {
			$insert_data['data'] = is_string( $data['data'] ) ? $data['data'] : wp_json_encode( $data['data'] );
			$insert_format[]     = '%s';
		}

		if ( null !== $data['submitted_at'] ) {
			$insert_data['submitted_at'] = $data['submitted_at'];
			$insert_format[]             = '%s';
		}

		if ( null !== $data['notes'] ) {
			$insert_data['notes'] = sanitize_textarea_field( $data['notes'] );
			$insert_format[]      = '%s';
		}

		$result = $wpdb->insert( $table, $insert_data, $insert_format );

		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Update a submission.
	 *
	 * @param int                  $id   Submission ID.
	 * @param array<string, mixed> $data Update data.
	 * @return bool
	 */
	public static function update( int $id, array $data ): bool {
		$wpdb  = self::db();
		$table = self::get_table_name();

		unset( $data['id'], $data['reregistration_id'], $data['user_id'], $data['created_at'] );

		if ( empty( $data ) ) {
			return false;
		}

		$update_data = array();
		$format      = array();

		$field_formats = array(
			'data'         => '%s',
			'status'       => '%s',
			// `submitted_at`/`reviewed_at` are unix UTC int since 6.6.0 (#249 sub-escopos b/d).
			'submitted_at' => '%d',
			'reviewed_at'  => '%d',
			'reviewed_by'  => '%d',
			'notes'        => '%s',
			'auth_code'    => '%s',
			'magic_token'  => '%s',
		);

		foreach ( $data as $key => $value ) {
			if ( ! isset( $field_formats[ $key ] ) ) {
				continue;
			}

			if ( 'data' === $key && ! is_string( $value ) ) {
				$value = wp_json_encode( $value );
			}

			if ( 'notes' === $key && null !== $value ) {
				$value = sanitize_textarea_field( $value );
			}

			$update_data[ $key ] = $value;
			$format[]            = $field_formats[ $key ];
		}

		if ( empty( $update_data ) ) {
			return false;
		}

		$result = $wpdb->update(
			$table,
			$update_data,
			array( 'id' => $id ),
			$format,
			array( '%d' )
		);

		static::cache_delete( "id_{$id}" );

		return false !== $result;
	}

	/**
	 * Approve a submission.
	 *
	 * @param int $id          Submission ID.
	 * @param int $reviewer_id Reviewer user ID.
	 * @return bool
	 */
	public static function approve( int $id, int $reviewer_id ): bool {
		$result = self::update(
			$id,
			array(
				'status'      => 'approved',
				'reviewed_at' => time(),
				'reviewed_by' => $reviewer_id,
			)
		);

		static::cache_delete( "id_{$id}" );

		return $result;
	}

	/**
	 * Reject a submission.
	 *
	 * @param int    $id          Submission ID.
	 * @param int    $reviewer_id Reviewer user ID.
	 * @param string $notes       Rejection reason.
	 * @return bool
	 */
	public static function reject( int $id, int $reviewer_id, string $notes = '' ): bool {
		$result = self::update(
			$id,
			array(
				'status'      => 'rejected',
				'reviewed_at' => time(),
				'reviewed_by' => $reviewer_id,
				'notes'       => $notes,
			)
		);

		static::cache_delete( "id_{$id}" );

		return $result;
	}

	/**
	 * Return a submission to draft (in_progress) so the user can revise it.
	 *
	 * Clears the review metadata and resets submitted_at so the user
	 * sees it as an editable draft again.
	 *
	 * @param int $id Submission ID.
	 * @return bool
	 */
	public static function return_to_draft( int $id ): bool {
		$wpdb  = self::db();
		$table = self::get_table_name();

		$result = $wpdb->update(
			$table,
			array(
				'status'       => 'in_progress',
				'submitted_at' => null,
				'reviewed_at'  => null,
				'reviewed_by'  => null,
				'notes'        => null,
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		static::cache_delete( "id_{$id}" );

		return false !== $result;
	}

	/**
	 * Bulk return multiple submissions to draft.
	 *
	 * @param array<int> $ids Submission IDs.
	 * @return int Number of submissions returned to draft.
	 */
	public static function bulk_return_to_draft( array $ids ): int {
		$count = 0;
		foreach ( $ids as $id ) {
			if ( self::return_to_draft( (int) $id ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Bulk approve multiple submissions.
	 *
	 * @param array<int> $ids         Submission IDs.
	 * @param int        $reviewer_id Reviewer user ID.
	 * @return int Number of approved submissions.
	 */
	public static function bulk_approve( array $ids, int $reviewer_id ): int {
		$count = 0;
		foreach ( $ids as $id ) {
			if ( self::approve( (int) $id, $reviewer_id ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * How many rows the seeding sends per INSERT (#1234).
	 *
	 * Five hundred is the same page size #772's export contract uses: large
	 * enough that the number of round trips to the database stops mattering,
	 * small enough not to hit `max_allowed_packet` on a row of three integers.
	 *
	 * @var int
	 */
	public const SEED_CHUNK_SIZE = 500;

	/**
	 * Create pending submissions for all affected users of a reregistration.
	 *
	 * Skips users who already have a submission for this reregistration.
	 *
	 * IN BATCHES, NOT ROW BY ROW (#1234)
	 *
	 * The previous shape was a SELECT (does it exist?) plus an INSERT per user:
	 * ten thousand members cost twenty thousand queries INSIDE AN ADMIN REQUEST
	 * -- the one that saves the campaign. Now it is `ceil( N / 500 )` INSERTs,
	 * and the same ten thousand members cost twenty.
	 *
	 * WHY THE `IGNORE` REPLACES THE CHECK RATHER THAN MERELY HIDING IT
	 *
	 * The table carries `UNIQUE KEY idx_reregistration_user (reregistration_id,
	 * user_id)`, so "skip whoever already has a submission" was already the
	 * database's rule -- the per-row SELECT only repeated it in PHP, and
	 * repeated it with a window: between reading and inserting, a second click
	 * on the Save button could insert the same row. The constraint closes that
	 * window; the check did not.
	 *
	 * `INSERT IGNORE` downgrades EVERY error to a warning, which is normally a
	 * reason not to use it. Here no other error is possible: the three columns
	 * written are two integers this method itself converts and the `pending`
	 * literal written here. There is no user text to truncate.
	 *
	 * The count returned is still of rows CREATED: `affected_rows` of a
	 * multi-row INSERT counts the ones that went in, not the ignored ones.
	 *
	 * @param int        $reregistration_id Reregistration ID.
	 * @param array<int> $audience_ids      Audience IDs.
	 * @return int Number of submissions created.
	 */
	public static function create_for_audience_members( int $reregistration_id, array $audience_ids ): int {
		$user_ids = ReregistrationRepository::get_user_ids_for_audiences( $audience_ids );

		// `get_user_ids_for_audiences()` concatenates the members of several
		// audiences and already deduplicates, but returns what the audience
		// reader handed over -- which may come as a string from the driver.
		// Normalising here is what allows trusting the `%d` below and the batch
		// sizes.
		$user_ids = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', $user_ids ),
					static function ( int $user_id ): bool {
						return $user_id > 0;
					}
				)
			)
		);

		if ( empty( $user_ids ) ) {
			return 0;
		}

		$wpdb    = self::db();
		$table   = self::get_table_name();
		$created = 0;

		foreach ( array_chunk( $user_ids, self::SEED_CHUNK_SIZE ) as $chunk ) {
			$rows = implode( ',', array_fill( 0, count( $chunk ), '(%d, %d, %s)' ) );
			$sql  = "INSERT IGNORE INTO %i (reregistration_id, user_id, status) VALUES {$rows}";

			$args = array( $table );
			foreach ( $chunk as $user_id ) {
				$args[] = $reregistration_id;
				$args[] = $user_id;
				// The same initial state `create()` applies by default.
				$args[] = 'pending';
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The VALUES list is assembled from %d/%s placeholders alone; the values travel as arguments of prepare().
			$prepared = $wpdb->prepare( $sql, $args );
			if ( ! is_string( $prepared ) ) {
				continue;
			}

			// `query()` returns `int|bool`; `false > 0` is already false, so the
			// `is_int()` below changes no behaviour -- it exists for PHPStan,
			// which at level 8 does not narrow the `bool` from a comparison.
			//
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching -- The argument is the string prepare() returned above; the sniff does not follow a prepared string through an assignment.
			$affected = $wpdb->query( $prepared );
			if ( is_int( $affected ) && $affected > 0 ) {
				$created += $affected;
			}
		}

		return $created;
	}

	/**
	 * Stamps the REMINDER's send on a submission.
	 *
	 * PER ITEM, not in a batch like {@see self::mark_invited()} -- the
	 * difference is deliberate and comes from the call path. The invitation is
	 * fired by an operator's click, who sees the screen and can react; the
	 * reminder runs on wp-cron, INSIDE A VISITOR'S REQUEST, over a set that may
	 * hold thousands of rows and a synchronous `wp_mail()` per row. That is
	 * exactly the path that times out partway through -- the defect #1232
	 * describes.
	 *
	 * Stamping at the end means a timeout leaves NOTHING marked, and everyone
	 * who already received an email receives it again on the next run. Stamping
	 * per item, an interruption leaves marked exactly who already received it,
	 * and the next run resumes from where it stopped.
	 *
	 * Category A (unix UTC) per `CLAUDE.md` -- `time()`, never
	 * `current_time()`.
	 *
	 * @param int $submission_id The submission's ID.
	 * @return bool
	 */
	public static function mark_reminded( int $submission_id ): bool {
		if ( $submission_id <= 0 ) {
			return false;
		}

		$wpdb = self::db();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A write to the plugin's own `ffc_*` table, for which WordPress exposes no API; the cache invalidation comes just below.
		$result = $wpdb->update(
			self::get_table_name(),
			array( 'reminder_sent_at' => time() ),
			array( 'id' => $submission_id ),
			array( '%d' ),
			array( '%d' )
		);

		if ( false === $result ) {
			return false;
		}

		static::cache_delete( "id_{$submission_id}" );

		return true;
	}

	/**
	 * Stamp the invitation timestamp on the submissions that were just emailed.
	 *
	 * Written in one statement rather than per row: the caller loops to send,
	 * and a failed send in the middle must not leave half the batch marked as
	 * invited while the other half gets a second email on the next click.
	 *
	 * Category A (unix UTC) per CLAUDE.md — `time()`, never `current_time()`.
	 *
	 * @param array<int, int> $submission_ids Submission IDs.
	 * @return int Rows updated.
	 */
	public static function mark_invited( array $submission_ids ): int {
		$ids = array_values( array_filter( array_map( 'intval', $submission_ids ) ) );
		if ( empty( $ids ) ) {
			return 0;
		}

		$wpdb         = self::db();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The `{$placeholders}` is `%d` repeated by `array_fill()` above, not request data; every value goes through `prepare()`.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- The query is `prepare()`'s output held in a variable, which is how `ReregistrationRepository::expire_overdue()` does it for the same reason: the return has to be tested before going to `query()`.
		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The sniff counts the literal's placeholders and does not know `prepare()` accepts a single array of arguments, which is how the table and the ids arrive.
		$sql = $wpdb->prepare(
			"UPDATE %i SET invited_at = %d WHERE id IN ({$placeholders})",
			array_merge( array( self::get_table_name(), time() ), $ids )
		);

		// `prepare()` returns `string|null`, and `query()` only accepts a string
		// -- the same guard `expire_overdue()` uses for the same reason.
		if ( ! is_string( $sql ) ) {
			return 0;
		}

		$result = $wpdb->query( $sql );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		// Same invalidation the other mutators do: one key per row. There is no
		// group flush on the trait, and inventing one here would be a second
		// way to do what every sibling already does per id.
		foreach ( $ids as $id ) {
			static::cache_delete( "id_{$id}" );
		}

		return is_numeric( $result ) ? (int) $result : 0;
	}
}
