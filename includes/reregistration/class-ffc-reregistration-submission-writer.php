<?php
/**
 * Reregistration Submission Writer
 *
 * Write-side of the reregistration-submission repository split (#563 backlog,
 * Sprint D2). Holds every INSERT / UPDATE and the workflow mutators (approve,
 * reject, return-to-draft, bulk operations, token provisioning). Reads live in
 * {@see ReregistrationSubmissionReader}; {@see ReregistrationSubmissionRepository}
 * remains the public façade that delegates to both.
 *
 * @since   6.11.3
 * @package FreeFormCertificate\Reregistration
 *
 * @phpstan-import-type ReregistrationSubmissionRow from ReregistrationSubmissionRepository
 */

declare(strict_types=1);

namespace FreeFormCertificate\Reregistration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Write operations for reregistration submission records.
 *
 * @since 6.11.3
 *
 * @phpstan-import-type ReregistrationSubmissionRow from ReregistrationSubmissionRepository
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
	 * Create.
	 *
	 * Create.
	 *
	 * Create.
	 *
	 * Create.
	 *
	 * Create.
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
	 * @param int $id          Submission ID.
	 * @param int $reviewer_id Admin user ID performing the action.
	 * @return bool
	 */
	public static function return_to_draft( int $id, int $reviewer_id ): bool {
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
	 * @param array<int> $ids         Submission IDs.
	 * @param int        $reviewer_id Admin user ID performing the action.
	 * @return int Number of submissions returned to draft.
	 */
	public static function bulk_return_to_draft( array $ids, int $reviewer_id ): int {
		$count = 0;
		foreach ( $ids as $id ) {
			if ( self::return_to_draft( (int) $id, $reviewer_id ) ) {
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
	 * Create pending submissions for all affected users of a reregistration.
	 *
	 * Skips users who already have a submission for this reregistration.
	 *
	 * @param int        $reregistration_id Reregistration ID.
	 * @param array<int> $audience_ids      Audience IDs.
	 * @return int Number of submissions created.
	 */
	public static function create_for_audience_members( int $reregistration_id, array $audience_ids ): int {
		$user_ids = ReregistrationRepository::get_user_ids_for_audiences( $audience_ids );
		$created  = 0;

		foreach ( $user_ids as $user_id ) {
			// Check if submission already exists.
			$existing = ReregistrationSubmissionReader::get_by_reregistration_and_user( $reregistration_id, $user_id );
			if ( $existing ) {
				continue;
			}

			$result = self::create(
				array(
					'reregistration_id' => $reregistration_id,
					'user_id'           => $user_id,
					'status'            => 'pending',
				)
			);

			if ( $result ) {
				++$created;
			}
		}

		return $created;
	}
}
