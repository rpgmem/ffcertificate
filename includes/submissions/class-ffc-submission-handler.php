<?php
/**
 * SubmissionHandler v3.3.0
 * Complete refactored version with Repository Pattern
 *
 * @package FreeFormCertificate\Submissions
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 * @since 3.0.1 Optimized bulk operations (single query + suspended logging)
 * @since 3.0.0 Repository Pattern integration
 * @since 2.10.0 Encryption & LGPD support
 */

declare(strict_types=1);

namespace FreeFormCertificate\Submissions;

use FreeFormCertificate\Repositories\SubmissionRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handler for submission operations.
 */
class SubmissionHandler {

	/**
	 * Repository.
	 *
	 * @var SubmissionRepository
	 */
	private $repository;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->repository = new SubmissionRepository();
	}

	/**
	 * Get the submission repository instance
	 *
	 * @return SubmissionRepository
	 */
	public function get_repository(): SubmissionRepository {
		return $this->repository;
	}

	/**
	 * Generate unique magic token
	 */
	private function generate_magic_token(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Generate unique auth code
	 */
	private function generate_unique_auth_code(): string {
		return \FreeFormCertificate\Core\AuthCodeService::generate_globally_unique_auth_code();
	}

	/**
	 * Get submission by ID
	 *
	 * @uses Repository::findById()
	 *
	 * @param int $id ID.
	 * @return array<string, mixed>|null
	 */
	public function get_submission( int $id ) {
		$submission = $this->repository->findById( $id );

		if ( ! $submission ) {
			return null;
		}

		return $this->decrypt_submission_data( $submission );
	}

	/**
	 * Get submission by magic token
	 *
	 * @uses Repository::findByToken()
	 *
	 * @param string $token Token.
	 * @return array<string, mixed>|null
	 */
	public function get_submission_by_token( string $token ) {
		$clean_token = preg_replace( '/[^a-f0-9]/i', '', $token ) ?? '';

		if ( strlen( $clean_token ) !== 32 ) {
			return null;
		}

		$submission = $this->repository->findByToken( $clean_token );

		if ( ! $submission ) {
			return null;
		}

		return $this->decrypt_submission_data( $submission );
	}

	/**
	 * Process submission (main method)
	 *
	 * @uses Repository::insert()
	 *
	 * @param int                  $form_id         Form ID.
	 * @param string               $form_title      Form title.
	 * @param array<string, mixed> $submission_data Submission data (passed by reference).
	 * @param string               $user_email      User email.
	 * @param array<string, mixed> $fields_config   Fields configuration.
	 * @param array<string, mixed> $form_config     Form configuration.
	 * @return int|\WP_Error
	 */
	public function process_submission( int $form_id, string $form_title, array &$submission_data, string $user_email, array $fields_config, array $form_config ) {
		/**
		 * Fires before a submission is saved to the database.
		 *
		 * @since 4.6.4
		 * @param int    $form_id         Form ID.
		 * @param array<string, mixed>  $submission_data Submission data (passed by reference via the method).
		 * @param string $user_email      User email.
		 * @param array<string, mixed>  $form_config     Form configuration.
		 */
		do_action( 'ffcertificate_before_submission_save', $form_id, $submission_data, $user_email, $form_config );

		// 1. Generate auth code if not present.
		if ( empty( $submission_data['auth_code'] ) ) {
			$submission_data['auth_code'] = $this->generate_unique_auth_code();
		}

		// 2. Clean mandatory fields.
		$clean_auth_code = \FreeFormCertificate\Core\DocumentFormatter::clean_auth_code( $submission_data['auth_code'] );

		$clean_cpf_rf = null;
		if ( isset( $submission_data['cpf_rf'] ) && ! empty( $submission_data['cpf_rf'] ) ) {
			$clean_cpf_rf = preg_replace( '/[^0-9]/', '', (string) $submission_data['cpf_rf'] );
		}

		// 2b. Classify identifier as CPF or RF by digit length.
		$clean_cpf = null;
		$clean_rf  = null;
		if ( ! empty( $clean_cpf_rf ) ) {
			$id_len = strlen( $clean_cpf_rf );
			if ( 11 === $id_len ) {
				$clean_cpf = $clean_cpf_rf;
			} elseif ( 7 === $id_len ) {
				$clean_rf = $clean_cpf_rf;
			} else {
				// Unknown length — default to CPF (most common).
				$clean_cpf = $clean_cpf_rf;
			}
		}

		// 3. Generate magic token.
		$magic_token = $this->generate_magic_token();

		// 4. Extract extra data.
		$mandatory_keys = array( 'email', 'cpf_rf', 'auth_code', 'ffc_lgpd_consent' );
		$extra_data     = array_diff_key( $submission_data, array_flip( $mandatory_keys ) );

		$data_json = wp_json_encode( $extra_data );
		if ( 'null' === $data_json || false === $data_json || empty( $data_json ) ) {
			$data_json = '{}';
		}

		// 5. Get user IP.
		$user_ip = \FreeFormCertificate\Core\Utils::get_user_ip();

		// 5b. Extract ticket value (for hash-based lookup)
		$ticket_value = isset( $extra_data['ticket'] ) ? strtoupper( trim( (string) $extra_data['ticket'] ) ) : null;

		// 6. Encryption via SensitiveFieldRegistry — single policy source for
		// every field this handler treats as sensitive.
		$encrypted = \FreeFormCertificate\Core\SensitiveFieldRegistry::encrypt_fields(
			\FreeFormCertificate\Core\SensitiveFieldRegistry::CONTEXT_SUBMISSION,
			array(
				'email'   => $user_email,
				'cpf'     => $clean_cpf,
				'rf'      => $clean_rf,
				'user_ip' => $user_ip,
				'ticket'  => $ticket_value,
				// The JSON "{}" means no extra data — skip encryption instead
				// of writing a ciphertext for an empty payload.
				'data'    => '{}' === $data_json ? null : $data_json,
			)
		);

		$email_encrypted   = $encrypted['email_encrypted'] ?? null;
		$email_hash        = $encrypted['email_hash'] ?? null;
		$cpf_encrypted_val = $encrypted['cpf_encrypted'] ?? null;
		$cpf_hash_val      = $encrypted['cpf_hash'] ?? null;
		$rf_encrypted_val  = $encrypted['rf_encrypted'] ?? null;
		$rf_hash_val       = $encrypted['rf_hash'] ?? null;
		$ticket_hash       = $encrypted['ticket_hash'] ?? null;
		$ip_encrypted      = $encrypted['user_ip_encrypted'] ?? null;
		$data_encrypted    = $encrypted['data_encrypted'] ?? null;

		// 7. LGPD Consent.
		$consent_given = isset( $submission_data['ffc_lgpd_consent'] ) && '1' === $submission_data['ffc_lgpd_consent'] ? 1 : 0;
		$consent_date  = $consent_given ? current_time( 'mysql' ) : null;
		$consent_text  = $consent_given ? __( 'User agreed to Privacy Policy and data storage', 'ffcertificate' ) : null;

		// 8. Link to WordPress user (v3.1.0)
		$lookup_cpf_hash = $cpf_hash_val ?? $rf_hash_val;
		$identifier_type = ! empty( $cpf_hash_val ) ? 'cpf' : ( ! empty( $rf_hash_val ) ? 'rf' : 'auto' );
		$user_id         = null;
		if ( ! empty( $lookup_cpf_hash ) && ! empty( $user_email ) ) {
			// Load User Manager if not already loaded.
			if ( ! class_exists( '\FreeFormCertificate\UserDashboard\UserManager' ) ) {
				$user_manager_file = FFC_PLUGIN_DIR . 'includes/user-dashboard/class-ffc-user-manager.php';
				if ( file_exists( $user_manager_file ) ) {
					require_once $user_manager_file;
				}
			}

			if ( class_exists( '\FreeFormCertificate\UserDashboard\UserManager' ) ) {
				$user_result = \FreeFormCertificate\UserDashboard\UserManager::get_or_create_user(
					$lookup_cpf_hash,
					$user_email,
					$submission_data,
					\FreeFormCertificate\UserDashboard\UserManager::CONTEXT_CERTIFICATE,
					$identifier_type
				);

				if ( ! is_wp_error( $user_result ) ) {
					$user_id = $user_result;
				}
			}
		}

		// 9. Prepare insert data.
		$insert_data = array(
			'form_id'           => $form_id,
			'user_id'           => $user_id,  // v3.1.0: Link to WordPress user.
			'submission_date'   => current_time( 'mysql' ),
			'auth_code'         => $clean_auth_code,
			'status'            => 'publish',
			'magic_token'       => $magic_token,
			'email_encrypted'   => $email_encrypted,
			'email_hash'        => $email_hash,
			'cpf_encrypted'     => $cpf_encrypted_val,
			'cpf_hash'          => $cpf_hash_val,
			'rf_encrypted'      => $rf_encrypted_val,
			'rf_hash'           => $rf_hash_val,
			'ticket_hash'       => $ticket_hash,
			'user_ip_encrypted' => $ip_encrypted,
			'data_encrypted'    => $data_encrypted,
			'consent_given'     => $consent_given,
			'consent_date'      => $consent_date,
			'consent_text'      => $consent_text,
		);

		// Plaintext data column — only if encryption NOT configured.
		if ( class_exists( '\FreeFormCertificate\Core\Encryption' ) && \FreeFormCertificate\Core\Encryption::is_configured() ) {
			$insert_data['data'] = null;
		} else {
			$insert_data['data'] = $data_json;
		}

		// 9. Insert using repository.
		$submission_id = $this->repository->insert( $insert_data );

		if ( ! $submission_id ) {
			return new \WP_Error( 'db_error', __( 'Error saving submission to the database.', 'ffcertificate' ) );
		}

		/**
		 * Fires after a submission is saved to the database.
		 *
		 * @since 4.6.4
		 * @param int    $submission_id   Newly created submission ID.
		 * @param int    $form_id         Form ID.
		 * @param array<string, mixed>  $submission_data Original submission data.
		 * @param string $user_email      User email.
		 */
		do_action( 'ffcertificate_after_submission_save', $submission_id, $form_id, $submission_data, $user_email );

		return $submission_id;
	}

	/**
	 * Update submission - FIXED v3.0.1
	 *
	 * @uses Repository::updateWithEditTracking()
	 *
	 * @param int                  $id         Submission ID.
	 * @param string               $new_email  New email.
	 * @param array<string, mixed> $clean_data Clean data.
	 */
	public function update_submission( int $id, string $new_email, array $clean_data ): bool {
		/**
		 * Fires before a submission is updated.
		 *
		 * @since 4.6.4
		 * @param int    $id         Submission ID.
		 * @param string $new_email  New email value.
		 * @param array<string, mixed>  $clean_data Sanitized submission data.
		 */
		do_action( 'ffcertificate_before_submission_update', $id, $new_email, $clean_data );

		$update_data = array();
		$has_data    = ! empty( $clean_data );
		$data_json   = null;

		if ( $has_data ) {
			// Remove edit tracking from JSON data (should be in columns).
			unset( $clean_data['is_edited'], $clean_data['edited_at'] );
			$data_json = wp_json_encode( $clean_data, JSON_UNESCAPED_UNICODE );
		}

		// Route sensitive fields through the registry.
		$encrypted   = \FreeFormCertificate\Core\SensitiveFieldRegistry::encrypt_fields(
			\FreeFormCertificate\Core\SensitiveFieldRegistry::CONTEXT_SUBMISSION,
			array(
				'email' => '' !== $new_email ? $new_email : null,
				'data'  => $has_data ? ( $data_json ? $data_json : '{}' ) : null,
			)
		);
		$update_data = array_merge( $update_data, $encrypted );

		// When data is updated and encryption is active, the plaintext column
		// must be NULLed out; otherwise store the plaintext json.
		if ( $has_data ) {
			if ( isset( $encrypted['data_encrypted'] ) ) {
				$update_data['data'] = null;
			} else {
				$update_data['data'] = $data_json;
			}
		}

		$result = $this->repository->updateWithEditTracking( $id, $update_data );

		if ( false !== $result ) {
			/**
			 * Fires after a submission is updated.
			 *
			 * @since 4.6.4
			 * @param int   $id          Submission ID.
			 * @param array $update_data Data that was updated.
			 */
			do_action( 'ffcertificate_after_submission_update', $id, $update_data );
		}

		return (bool) $result;  // Convert int|false to bool.
	}

	/**
	 * Update user link for a submission
	 *
	 * @since 4.3.0
	 * @param int      $id Submission ID.
	 * @param int|null $user_id WordPress user ID or null to unlink.
	 * @return bool True on success
	 */
	public function update_user_link( int $id, ?int $user_id ): bool {
		$update_data = array(
			'user_id'   => $user_id,
			'edited_at' => current_time( 'mysql' ),
			'edited_by' => get_current_user_id(),
		);

		$result = $this->repository->update( $id, $update_data );

		if ( false !== $result && class_exists( '\FreeFormCertificate\Core\ActivityLog' ) ) {
			$action = $user_id ? 'user_linked' : 'user_unlinked';
			\FreeFormCertificate\Core\ActivityLog::log(
				'submission',
				$action,
				array(
					'submission_id' => $id,
					'user_id'       => $user_id,
					'admin_id'      => get_current_user_id(),
				)
			);
		}

		return (bool) $result;
	}

	/**
	 * Decrypt submission data.
	 * Uses Encryption::decrypt_field() for each sensitive field.
	 *
	 * @param mixed $submission Submission.
	 * @return array<string, mixed>
	 */
	public function decrypt_submission_data( $submission ): array {
		if ( ! $submission || ! class_exists( '\FreeFormCertificate\Core\Encryption' ) ) {
			return $submission;
		}

		$submission['email']   = \FreeFormCertificate\Core\Encryption::decrypt_field( $submission, 'email' );
		$submission['user_ip'] = \FreeFormCertificate\Core\Encryption::decrypt_field( $submission, 'user_ip' );
		$submission['data']    = \FreeFormCertificate\Core\Encryption::decrypt_field( $submission, 'data' );

		// Decrypt split cpf/rf columns.
		$cpf_val = \FreeFormCertificate\Core\Encryption::decrypt_field( $submission, 'cpf' );
		$rf_val  = \FreeFormCertificate\Core\Encryption::decrypt_field( $submission, 'rf' );

		if ( ! empty( $cpf_val ) ) {
			$submission['cpf_rf'] = $cpf_val;
			$submission['cpf']    = $cpf_val;
			$submission['rf']     = null;
		} elseif ( ! empty( $rf_val ) ) {
			$submission['cpf_rf'] = $rf_val;
			$submission['rf']     = $rf_val;
			$submission['cpf']    = null;
		} else {
			$submission['cpf_rf'] = '';
			$submission['cpf']    = null;
			$submission['rf']     = null;
		}

		return $submission;
	}

	/**
	 * Trash submission
	 *
	 * @uses Repository::updateStatus()
	 * @param int $id ID.
	 */
	public function trash_submission( int $id ): bool {
		$result = $this->repository->updateStatus( $id, 'trash' );

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
	 * Restore submission.
	 *
	 * Restore submission.
	 *
	 * Restore submission.
	 *
	 * Restore submission.
	 *
	 * Restore submission.
	 *
	 * @uses Repository::updateStatus()
	 * @param int $id ID.
	 */
	public function restore_submission( int $id ): bool {
		$result = $this->repository->updateStatus( $id, 'publish' );

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
	 * Delete submission.
	 *
	 * Delete submission.
	 *
	 * Delete submission.
	 *
	 * Delete submission.
	 *
	 * Delete submission.
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

		$result = $this->repository->delete( $id );

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
	 * Bulk trash submissions.
	 *
	 * Bulk trash submissions.
	 *
	 * Bulk trash submissions.
	 *
	 * Bulk trash submissions.
	 *
	 * Bulk trash submissions.
	 *
	 * Bulk trash submissions.
	 *
	 * Bulk trash submissions.
	 *
	 * Bulk trash submissions.
	 *
	 * Bulk trash submissions.
	 *
	 * Bulk trash submissions.
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

		$result = $this->repository->bulkUpdateStatus( $ids, 'trash' );

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

		$result = $this->repository->bulkUpdateStatus( $ids, 'publish' );

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

		$result = $this->repository->bulkDelete( $ids );

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
	 * Delete all submissions for a form
	 *
	 * @uses Repository::deleteByFormId()
	 */
	/**
	 * Delete all submissions (optionally by form_id)
	 *
	 * @param int|null $form_id Form ID to delete from, or null for all forms.
	 * @param bool     $reset_auto_increment Reset ID counter to 1.
	 * @return int Number of rows deleted
	 */
	public function delete_all_submissions( ?int $form_id = null, bool $reset_auto_increment = false ): int {
		global $wpdb;
		$table = \FreeFormCertificate\Core\Utils::get_submissions_table();

		if ( $form_id ) {
			// Delete from specific form using repository.
			$result = $this->repository->deleteByFormId( $form_id );

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
		$table = \FreeFormCertificate\Core\Utils::get_submissions_table();

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
		$table = \FreeFormCertificate\Core\Utils::get_submissions_table();

		$cleanup_days = absint( get_option( 'ffc_cleanup_days', 0 ) );

		if ( $cleanup_days <= 0 ) {
			return 0;
		}

		$cutoff_ts   = strtotime( "-{$cleanup_days} days" );
		$cutoff_date = gmdate( 'Y-m-d H:i:s', $cutoff_ts ? $cutoff_ts : time() );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM %i WHERE submission_date < %s AND status = 'publish'",
				$table,
				$cutoff_date
			)
		);

		if ( $deleted && class_exists( '\FreeFormCertificate\Core\ActivityLog' ) ) {
			\FreeFormCertificate\Core\ActivityLog::log(
				'data_cleanup',
				\FreeFormCertificate\Core\ActivityLog::LEVEL_INFO,
				array(
					'deleted_count' => $deleted,
					'cutoff_date'   => $cutoff_date,
				)
			);
		}

		return false !== $deleted ? (int) $deleted : 0;
	}

	/**
	 * Ensure magic token exists
	 *
	 * @param int $submission_id Submission ID.
	 */
	public function ensure_magic_token( int $submission_id ): string {
		$submission = $this->repository->findById( $submission_id );

		// If submission not found, return empty string.
		if ( ! $submission ) {
			return '';
		}

		// If token already exists, return it.
		if ( ! empty( $submission['magic_token'] ) ) {
			return $submission['magic_token'];
		}

		// Generate new token.
		$magic_token = $this->generate_magic_token();

		// Save to database.
		$this->repository->update(
			$submission_id,
			array(
				'magic_token' => $magic_token,
			)
		);

		return $magic_token;
	}
}
