<?php
/**
 * SubmissionLifecycleService
 *
 * Lifecycle / maintenance operations extracted from SubmissionHandler
 * (#591 phase-3, Sprint E5a). Behavior-preserving split: SubmissionHandler
 * keeps thin delegators of the same signature, so external callers and tests
 * are unaffected.
 *
 * @package FreeFormCertificate\Submissions
 * @since 6.7.7
 */

declare(strict_types=1);

namespace FreeFormCertificate\Submissions;

use FreeFormCertificate\Repositories\SubmissionRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collaborator that owns the submission lifecycle / maintenance operations.
 *
 * Holds a reference back to its owning SubmissionHandler and reads the
 * repository through {@see SubmissionHandler::get_repository()} at call-time,
 * so a repository swapped onto the handler after construction (e.g. a test
 * mock) is honored.
 */
class SubmissionLifecycleService {

	/**
	 * Owning handler.
	 *
	 * @var SubmissionHandler
	 */
	private $handler;

	/**
	 * Constructor.
	 *
	 * @param SubmissionHandler $handler Owning handler (source of the repository).
	 */
	public function __construct( SubmissionHandler $handler ) {
		$this->handler = $handler;
	}

	/**
	 * Repository accessor — always reads the handler's current repository.
	 *
	 * @return SubmissionRepository
	 */
	private function repository(): SubmissionRepository {
		return $this->handler->get_repository();
	}

	/**
	 * Trash submission
	 *
	 * @uses Repository::updateStatus()
	 * @param int $id ID.
	 */
	public function trash_submission( int $id ): bool {
		$result = $this->repository()->updateStatus( $id, 'trash' );

		if ( $result ) {
			/**
			 * Description.
			 *
			 * @since 4.6.4
			 */
			do_action( 'ffcertificate_submission_trashed', $id );
		}

		return (bool) $result;
	}

	/**
	 * Restore submission
	 *
	 * @uses Repository::updateStatus()
	 * @param int $id ID.
	 */
	public function restore_submission( int $id ): bool {
		$result = $this->repository()->updateStatus( $id, 'publish' );

		if ( $result ) {
			/**
			 * Description.
			 *
			 * @since 4.6.4
			 */
			do_action( 'ffcertificate_submission_restored', $id );
		}

		return (bool) $result;
	}

	/**
	 * Permanently delete submission
	 *
	 * @uses Repository::delete()
	 * @param int $id ID.
	 */
	public function delete_submission( int $id ): bool {
		/**
		 * Description.
		 *
		 * @since 4.6.4
		 */
		do_action( 'ffcertificate_before_submission_delete', $id );

		$result = $this->repository()->delete( $id );

		if ( $result ) {
			/**
			 * Description.
			 *
			 * @since 4.6.4
			 */
			do_action( 'ffcertificate_after_submission_delete', $id );
		}

		return (bool) $result;
	}

	/**
	 * Bulk trash submissions (optimized)
	 *
	 * @uses Repository::bulkUpdateStatus()
	 *
	 * @param array<int, int> $ids Array of submission IDs.
	 * @return int|false Number of rows affected or false on error
	 */
	public function bulk_trash_submissions( array $ids ) {
		if ( empty( $ids ) ) {
			return 0;
		}

		// Disable logging during bulk operation.
		if ( class_exists( '\FreeFormCertificate\Core\ActivityLog' ) ) {
			\FreeFormCertificate\Core\ActivityLog::disable_logging();
		}

		$result = $this->repository()->bulkUpdateStatus( $ids, 'trash' );

		// Re-enable logging.
		if ( class_exists( '\FreeFormCertificate\Core\ActivityLog' ) ) {
			\FreeFormCertificate\Core\ActivityLog::enable_logging();

			// Log single bulk operation.
			\FreeFormCertificate\Core\ActivityLog::log(
				'bulk_trash',
				\FreeFormCertificate\Core\ActivityLog::LEVEL_INFO,
				array(
					'count' => count( $ids ),
				)
			);
		}

		return $result;
	}

	/**
	 * Bulk restore submissions (optimized)
	 *
	 * @uses Repository::bulkUpdateStatus()
	 *
	 * @param array<int, int> $ids Array of submission IDs.
	 * @return int|false Number of rows affected or false on error
	 */
	public function bulk_restore_submissions( array $ids ) {
		if ( empty( $ids ) ) {
			return 0;
		}

		// Disable logging during bulk operation.
		if ( class_exists( '\FreeFormCertificate\Core\ActivityLog' ) ) {
			\FreeFormCertificate\Core\ActivityLog::disable_logging();
		}

		$result = $this->repository()->bulkUpdateStatus( $ids, 'publish' );

		// Re-enable logging.
		if ( class_exists( '\FreeFormCertificate\Core\ActivityLog' ) ) {
			\FreeFormCertificate\Core\ActivityLog::enable_logging();

			// Log single bulk operation.
			\FreeFormCertificate\Core\ActivityLog::log(
				'bulk_restore',
				\FreeFormCertificate\Core\ActivityLog::LEVEL_INFO,
				array(
					'count' => count( $ids ),
				)
			);
		}

		return $result;
	}

	/**
	 * Move submissions between forms, skipping conflicts.
	 *
	 * Wraps SubmissionRepository::moveBetweenForms with the same
	 * disable-logging-then-log-once pattern used by the other bulk methods,
	 * so a 50-row move produces a single `submission_moved` activity entry
	 * instead of 50 individual `data_modified` entries.
	 *
	 * @param int             $from_form_id Source form ID.
	 * @param int             $to_form_id   Target form ID.
	 * @param array<int, int> $ids          Submission IDs.
	 * @return array{moved: list<int>, conflicts: list<int>}
	 */
	public function move_submissions_between_forms( int $from_form_id, int $to_form_id, array $ids ): array {
		$activity_log_class = '\FreeFormCertificate\Core\ActivityLog';

		// Disable logging during bulk operation.
		if ( class_exists( $activity_log_class ) ) {
			\FreeFormCertificate\Core\ActivityLog::disable_logging();
		}

		$result = $this->repository()->moveBetweenForms( $from_form_id, $to_form_id, $ids );

		// Re-enable logging and emit a single audit entry.
		if ( class_exists( $activity_log_class ) ) {
			\FreeFormCertificate\Core\ActivityLog::enable_logging();
			\FreeFormCertificate\Core\ActivityLog::log(
				'submission_moved',
				\FreeFormCertificate\Core\ActivityLog::LEVEL_INFO,
				array(
					'from_form_id'   => $from_form_id,
					'to_form_id'     => $to_form_id,
					'requested'      => count( $ids ),
					'moved_count'    => count( $result['moved'] ),
					'conflict_count' => count( $result['conflicts'] ),
					'moved_ids'      => $result['moved'],
					'conflict_ids'   => $result['conflicts'],
				)
			);
		}

		return $result;
	}

	/**
	 * Bulk delete submissions permanently (optimized)
	 *
	 * @uses Repository::bulkDelete()
	 *
	 * @param array<int, int> $ids Array of submission IDs.
	 * @return int|false Number of rows deleted or false on error
	 */
	public function bulk_delete_submissions( array $ids ) {
		if ( empty( $ids ) ) {
			return 0;
		}

		// Disable logging during bulk operation (CRITICAL for performance).
		if ( class_exists( '\FreeFormCertificate\Core\ActivityLog' ) ) {
			\FreeFormCertificate\Core\ActivityLog::disable_logging();
		}

		$result = $this->repository()->bulkDelete( $ids );

		// Re-enable logging.
		if ( class_exists( '\FreeFormCertificate\Core\ActivityLog' ) ) {
			\FreeFormCertificate\Core\ActivityLog::enable_logging();

			// Log single bulk operation instead of N individual logs.
			\FreeFormCertificate\Core\ActivityLog::log(
				'bulk_delete',
				\FreeFormCertificate\Core\ActivityLog::LEVEL_WARNING,
				array(
					'count' => count( $ids ),
				)
			);
		}

		return $result;
	}

	/**
	 * Delete all submissions (optionally by form_id)
	 *
	 * @uses Repository::deleteByFormId()
	 *
	 * @param int|null $form_id Form ID to delete from, or null for all forms.
	 * @param bool     $reset_auto_increment Reset ID counter to 1.
	 * @return int Number of rows deleted
	 */
	public function delete_all_submissions( ?int $form_id = null, bool $reset_auto_increment = false ): int {
		global $wpdb;
		$table = \FreeFormCertificate\Repositories\SubmissionRepository::get_submissions_table();

		if ( $form_id ) {
			// Delete from specific form using repository.
			$result = $this->repository()->deleteByFormId( $form_id );

			// Reset AUTO_INCREMENT if table is empty and requested.
			if ( $reset_auto_increment ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
				if ( 0 === $count ) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
					$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i AUTO_INCREMENT = 1', $table ) );
				}
			}

			return (int) $result;
		}

		// Delete ALL submissions from ALL forms.
		$result = false;

		if ( $reset_auto_increment ) {
			// TRUNCATE resets AUTO_INCREMENT automatically.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$result = $wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $table ) );

			// Also reset migration counters when resetting auto increment.
			// This ensures migration panel shows correct stats after cleanup.
			if ( false !== $result ) {
				$this->reset_migration_counters();
			}
		} else {
			// DELETE keeps AUTO_INCREMENT.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) );
		}

		return false !== $result ? (int) $result : 0;  // Convert false to 0, ensure int.
	}

	/**
	 * Reset all migration completion flags
	 * Called when all submissions are deleted and counter is reset
	 *
	 * @return void
	 */
	private function reset_migration_counters(): void {
		global $wpdb;

		// Delete all migration completion flags.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE option_name LIKE %s',
				$wpdb->options,
				'ffc_migration_%_completed'
			)
		);

		// Clear object cache for affected options.
		wp_cache_delete( 'alloptions', 'options' );
	}

	/**
	 * Reset AUTO_INCREMENT counter
	 *
	 * @return bool Query result
	 */
	public function reset_submission_counter(): bool {
		global $wpdb;
		$table = \FreeFormCertificate\Repositories\SubmissionRepository::get_submissions_table();

		// Get current max ID.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$max_id = $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(id) FROM %i', $table ) );

		if ( null === $max_id ) {
			// Table is empty, reset to 1.
			$next_id = 1;
		} else {
			// Table has data, set to max_id + 1.
			$next_id = intval( $max_id ) + 1;
		}

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		return $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i AUTO_INCREMENT = %d', $table, $next_id ) );
	}

	/**
	 * Run data cleanup (old submissions)
	 *
	 * @return int Number of deleted submissions
	 */
	public function run_data_cleanup(): int {
		global $wpdb;
		$table = \FreeFormCertificate\Repositories\SubmissionRepository::get_submissions_table();

		$cleanup_days = absint( get_option( 'ffc_cleanup_days', 0 ) );

		if ( $cleanup_days <= 0 ) {
			return 0;
		}

		$cutoff_ts = strtotime( "-{$cleanup_days} days" );
		if ( false === $cutoff_ts ) {
			$cutoff_ts = time();
		}

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query(
			$wpdb->prepare(
				// `submission_date` is unix UTC int since 6.6.0 (#249 sub-escopo a).
				"DELETE FROM %i WHERE submission_date < %d AND status = 'publish'",
				$table,
				$cutoff_ts
			)
		);

		if ( $deleted && class_exists( '\FreeFormCertificate\Core\ActivityLog' ) ) {
			\FreeFormCertificate\Core\ActivityLog::log(
				'data_cleanup',
				\FreeFormCertificate\Core\ActivityLog::LEVEL_WARNING,
				array(
					'deleted_count' => $deleted,
					'cutoff_ts'     => $cutoff_ts,
				)
			);
		}

		return false !== $deleted ? (int) $deleted : 0;
	}
}
