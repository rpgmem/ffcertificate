<?php
/**
 * AdminAjax Handlers
 * Handles AJAX requests from admin interface
 *
 * @package FreeFormCertificate\Admin
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin Ajax.
 */
class AdminAjax {

	use \FreeFormCertificate\Core\AjaxTrait;

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Register AJAX handlers (ffc_load_template is handled by FormEditor).
		add_action( 'wp_ajax_ffc_search_user', array( $this, 'search_user' ) );
		add_action( 'wp_ajax_ffc_reveal_pii', array( $this, 'reveal_pii' ) );
	}

	/**
	 * Per-record-type configuration for {@see reveal_pii()}. Each entry maps a
	 * record type to the surface cap that gates the endpoint, the PII cap +
	 * unmasked `_admin` role handed to the shared policy, and the audit action
	 * written for a `reveal`-tier disclosure.
	 *
	 * @return array<string, array<string, string>>
	 */
	private function pii_reveal_configs(): array {
		return array(
			'submission'  => array(
				'surface_cap'  => 'ffc_view_certificates',
				'pii_cap'      => 'ffc_view_certificates_pii',
				'admin_role'   => 'ffc_certificates_admin',
				'audit_action' => 'submission_pii_revealed',
			),
			'appointment' => array(
				'surface_cap'  => 'ffc_view_appointments',
				'pii_cap'      => 'ffc_view_appointments_pii',
				'admin_role'   => 'ffc_appointments_admin',
				'audit_action' => 'appointment_pii_revealed',
			),
		);
	}

	/**
	 * Load and decrypt the record whose PII a reveal targets.
	 *
	 * @param string $type Record type ('submission' | 'appointment').
	 * @param int    $id   Record ID.
	 * @return array<string, mixed>|null Decrypted row (with `cpf` / `rf` /
	 *                                   `email` / `user_id` keys), or null when
	 *                                   the record is missing.
	 */
	private function load_pii_record( string $type, int $id ): ?array {
		if ( 'appointment' === $type ) {
			$repo = new \FreeFormCertificate\Repositories\AppointmentRepository();
			$row  = $repo->findById( $id );
			if ( ! $row ) {
				return null;
			}
			return \FreeFormCertificate\Core\Encryption::decrypt_appointment( $row );
		}

		$handler = new \FreeFormCertificate\Submissions\SubmissionHandler();
		$sub     = $handler->get_submission( $id );
		if ( ! $sub ) {
			return null;
		}
		return (array) $sub;
	}

	/**
	 * Reveal one decrypted PII field (CPF / RF / email) of a certificate
	 * submission or self-scheduling appointment, gated by the #739 §3.3 PII
	 * policy and audited.
	 *
	 * The admin surfaces (submission edit page + list, appointment detail +
	 * list) render the masked value only; the plaintext is fetched here on
	 * demand so it never sits in the initial HTML for the `reveal` / `masked`
	 * tiers. Returns 403 for the masked tier; the `reveal` tier writes an audit
	 * row, the unmasked `_admin` tier does not. The `type` POST field selects
	 * the record domain (defaults to `submission` for backward compatibility).
	 *
	 * @return void
	 */
	public function reveal_pii(): void {
		$this->verify_ajax_nonce( 'ffc_reveal_pii_nonce' );

		$type    = $this->get_post_param( 'type' );
		$configs = $this->pii_reveal_configs();
		if ( ! isset( $configs[ $type ] ) ) {
			$type = 'submission';
		}
		$config = $configs[ $type ];

		// Surface gate — must at least be able to view the record's area.
		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( $config['surface_cap'] ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission.', 'ffcertificate' ) ), 403 );
		}

		$id    = $this->get_post_int( 'submission_id' );
		$field = $this->get_post_param( 'field' );
		if ( ! in_array( $field, array( 'cpf', 'rf', 'email' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Unsupported field.', 'ffcertificate' ) ) );
		}
		if ( $id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid record.', 'ffcertificate' ) ) );
		}

		$record = $this->load_pii_record( $type, $id );
		if ( null === $record ) {
			wp_send_json_error( array( 'message' => __( 'Record not found.', 'ffcertificate' ) ), 404 );
		}
		$owner = isset( $record['user_id'] ) ? (int) $record['user_id'] : null;

		$tier = \FreeFormCertificate\Core\PiiAccessPolicy::resolve(
			$config['pii_cap'],
			$config['admin_role'],
			$owner
		);
		if ( \FreeFormCertificate\Core\PiiAccessPolicy::TIER_MASKED === $tier ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to reveal this field.', 'ffcertificate' ) ), 403 );
		}

		// The record arrives already decrypted; render through DocumentFormatter
		// so it matches the unmasked display.
		switch ( $field ) {
			case 'cpf':
				$display = \FreeFormCertificate\Core\DocumentFormatter::format_cpf( (string) ( $record['cpf'] ?? '' ) );
				break;
			case 'rf':
				$display = \FreeFormCertificate\Core\DocumentFormatter::format_rf( (string) ( $record['rf'] ?? '' ) );
				break;
			default:
				$display = (string) ( $record['email'] ?? '' );
		}
		if ( '' === $display ) {
			wp_send_json_error( array( 'message' => __( 'No value to reveal.', 'ffcertificate' ) ), 404 );
		}

		// Audit only the `reveal` tier — the unmasked `_admin` role is exempt
		// to keep the per-field log free of high-trust noise.
		if ( \FreeFormCertificate\Core\PiiAccessPolicy::TIER_REVEAL === $tier ) {
			\FreeFormCertificate\Core\ActivityLog::log(
				$config['audit_action'],
				\FreeFormCertificate\Core\ActivityLog::LEVEL_INFO,
				array( 'field_key' => $field ),
				get_current_user_id(),
				$id
			);
		}

		wp_send_json_success(
			array(
				'field' => $field,
				'value' => $display,
			)
		);
	}

	/**
	 * Search for WordPress users
	 *
	 * Searches by name, email, ID, or CPF/RF (via submission lookup).
	 *
	 * @since 4.3.0
	 */
	public function search_user(): void {
		$this->verify_ajax_nonce( 'ffc_user_search_nonce' );
		// The user search (incl. CPF/RF lookup) backs the submission-edit page,
		// which is gated on `ffc_edit_certificates`. Match that surface cap via
		// the admin-or helper (preserving the WP-admin override) instead of the
		// trait default of `manage_options`, which locked out a certificates
		// delegate who could open the page but not use its user picker (#739).
		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_edit_certificates' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ffcertificate' ) ) );
		}

		$search_term = $this->get_post_param( 'search' );

		if ( strlen( $search_term ) < 2 ) {
			wp_send_json_error( array( 'message' => __( 'Please enter at least 2 characters.', 'ffcertificate' ) ) );
		}

		$users = array();

		// Check if search term is a numeric ID.
		if ( is_numeric( $search_term ) ) {
			$user = get_userdata( (int) $search_term );
			if ( $user ) {
				$users[] = $this->format_user_result( $user );
			}
		}

		// Search by email or name.
		$user_query_args = array(
			'search'         => '*' . $search_term . '*',
			'search_columns' => array( 'user_login', 'user_email', 'display_name', 'user_nicename' ),
			'number'         => 10,
			'orderby'        => 'display_name',
			'order'          => 'ASC',
		);

		$user_query  = new \WP_User_Query( $user_query_args );
		$found_users = $user_query->get_results();

		foreach ( $found_users as $user ) {
			// Avoid duplicates if ID search already found this user.
			$exists = false;
			foreach ( $users as $existing ) {
				if ( $existing['id'] === $user->ID ) {
					$exists = true;
					break;
				}
			}
			if ( ! $exists ) {
				$users[] = $this->format_user_result( $user );
			}
		}

		// Search by CPF/RF in submissions (if no users found by standard search).
		if ( empty( $users ) ) {
			$users = $this->search_user_by_cpf( $search_term );
		}

		if ( empty( $users ) ) {
			wp_send_json_error( array( 'message' => __( 'No users found.', 'ffcertificate' ) ) );
		}

		wp_send_json_success( array( 'users' => $users ) );
	}

	/**
	 * Format user data for AJAX response
	 *
	 * @param \WP_User $user WordPress user object.
	 * @return array<string, mixed> Formatted user data
	 */
	private function format_user_result( \WP_User $user ): array {
		return array(
			'id'           => $user->ID,
			'display_name' => $user->display_name,
			'email'        => $user->user_email,
			'avatar'       => get_avatar_url( $user->ID, array( 'size' => 32 ) ),
		);
	}

	/**
	 * Search for user by CPF/RF in submissions
	 *
	 * @param string $cpf_rf CPF/RF to search for.
	 * @return array<int, array<string, mixed>> Array of user results
	 */
	private function search_user_by_cpf( string $cpf_rf ): array {
		global $wpdb;

		// Clean CPF/RF (remove formatting).
		$cpf_rf_clean = \FreeFormCertificate\Core\DataSanitizer::normalize_cpf_rf( $cpf_rf );

		if ( strlen( $cpf_rf_clean ) < 6 ) {
			return array();
		}

		// Generate hash and classify by digit count.
		$cpf_rf_hash = \FreeFormCertificate\Core\Encryption::hash( $cpf_rf_clean );
		$hash_column = strlen( $cpf_rf_clean ) === 7 ? 'rf_hash' : 'cpf_hash';

		$table = \FreeFormCertificate\Repositories\SubmissionRepository::get_submissions_table();

		// Search the specific split column.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Column name from internal config, not user input.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Lookup keyed on a hashed identifier supplied per request — caching it would put PII-derived keys in the object cache for no reuse.
		$user_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT user_id FROM %i WHERE {$hash_column} = %s AND user_id IS NOT NULL LIMIT 1",
				$table,
				$cpf_rf_hash
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $user_id ) {
			return array();
		}

		$user = get_userdata( (int) $user_id );

		if ( ! $user ) {
			return array();
		}

		return array( $this->format_user_result( $user ) );
	}
}
