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
	 * Most values one "reveal this page" request may name (#1863). A list
	 * page holds a few hundred masked values at most.
	 */
	private const PAGE_REVEAL_LIMIT = 500;

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Register AJAX handlers (ffc_load_template is handled by FormEditor).
		add_action( 'wp_ajax_ffc_search_user', array( $this, 'search_user' ) );
		add_action( 'wp_ajax_ffc_reveal_pii', array( $this, 'reveal_pii' ) );
		add_action( 'wp_ajax_ffc_reveal_pii_page', array( $this, 'reveal_pii_page' ) );
	}

	/**
	 * Per-record-type configuration for {@see reveal_pii()} and
	 * {@see reveal_pii_page()}. Each entry maps a record type to the surface
	 * cap that gates the endpoint, the module's PII cap handed to the shared
	 * policy, and the audit action written for every disclosure.
	 *
	 * @since 6.40.0 No unmasked role any more: everyone reveals (#1863).
	 * @return array<string, array<string, string>>
	 */
	private function pii_reveal_configs(): array {
		return array(
			'submission'     => array(
				'surface_cap'  => 'ffc_view_certificates',
				'pii_cap'      => 'ffc_view_certificates_pii',
				'audit_action' => 'submission_pii_revealed',
			),
			'appointment'    => array(
				'surface_cap'  => 'ffc_view_appointments',
				'pii_cap'      => 'ffc_view_appointments_pii',
				'audit_action' => 'appointment_pii_revealed',
			),
			// #1815.
			'reregistration' => array(
				'surface_cap'  => 'ffc_view_reregistration',
				'pii_cap'      => 'ffc_view_reregistration_pii',
				'audit_action' => 'reregistration_pii_revealed',
			),
			// The account email of a person listed on an audience screen
			// (#1824), behind the audience module's own PII cap since #1863.
			\FreeFormCertificate\Audience\AudienceAccountEmail::REVEAL_TYPE => array(
				'surface_cap'  => 'ffc_view_audiences',
				'pii_cap'      => \FreeFormCertificate\Audience\AudienceAccountEmail::PII_CAP,
				'audit_action' => 'audience_account_email_revealed',
			),
			// A sensitive audience custom field on the WordPress user-edit
			// screen (#1863). The surface is that screen, so the gate is
			// editing users; the record check adds `edit_user` for the id.
			\FreeFormCertificate\Admin\AdminUserCustomFields::REVEAL_TYPE => array(
				'surface_cap'  => 'edit_users',
				'pii_cap'      => \FreeFormCertificate\Admin\AdminUserCustomFields::PII_CAP,
				'audit_action' => 'user_profile_pii_revealed',
			),
			// A person listed by Date Messages' upcoming dates (#1863).
			\FreeFormCertificate\DateMessages\DateMessagesAdminPage::REVEAL_TYPE => array(
				'surface_cap'  => \FreeFormCertificate\DateMessages\DateMessagesAdminPage::VIEW_CAP,
				'pii_cap'      => \FreeFormCertificate\DateMessages\DateMessagesAdminPage::PII_CAP,
				'audit_action' => 'date_messages_pii_revealed',
			),
		);
	}

	/**
	 * Whether a reveal type serves a field. `account_email` is the email of
	 * the WP account a submission is linked to (#1824); an audience record is
	 * an account, so its only field is that account's email; a user profile
	 * serves its sensitive custom fields by id (`cf_<id>`), and a Date
	 * Messages person their name.
	 *
	 * @param string $type  Reveal type, a key of {@see pii_reveal_configs()}.
	 * @param string $field Field asked for.
	 * @return bool
	 */
	private function pii_reveal_serves( string $type, string $field ): bool {
		if ( \FreeFormCertificate\Admin\AdminUserCustomFields::REVEAL_TYPE === $type ) {
			return 1 === preg_match( '/^cf_[1-9][0-9]*$/', $field );
		}
		if ( \FreeFormCertificate\DateMessages\DateMessagesAdminPage::REVEAL_TYPE === $type ) {
			return 'name' === $field;
		}
		if ( \FreeFormCertificate\Audience\AudienceAccountEmail::REVEAL_TYPE === $type ) {
			return 'email' === $field;
		}
		if ( 'submission' === $type ) {
			return in_array( $field, array( 'cpf', 'rf', 'email', 'ip', 'account_email' ), true );
		}
		if ( 'appointment' === $type ) {
			return in_array( $field, array( 'cpf', 'rf', 'email', 'ip', 'phone' ), true );
		}
		return in_array( $field, array( 'cpf', 'rf', 'email', 'ip' ), true );
	}

	/**
	 * Load and decrypt the record whose PII a reveal targets.
	 *
	 * @param string $type Record type, a key of {@see pii_reveal_configs()}.
	 * @param int    $id   Record ID; for `audience_account`, `user_profile`
	 *                     and `date_messages_person`, the account ID.
	 * @return array<string, mixed>|null Decrypted row (with `cpf` / `rf` /
	 *                                   `email` / `user_ip` / `user_id` keys), or null when
	 *                                   the record is missing.
	 */
	private function load_pii_record( string $type, int $id ): ?array {
		if ( 'reregistration' === $type ) {
			return $this->load_reregistration_pii_record( $id );
		}

		if ( \FreeFormCertificate\Audience\AudienceAccountEmail::REVEAL_TYPE === $type ) {
			// Only an account an audience screen lists, so this type cannot
			// read any account's email by id.
			if ( ! \FreeFormCertificate\Audience\AudienceAccountEmail::is_listed( $id ) ) {
				return null;
			}
			$account = get_userdata( $id );
			if ( ! $account ) {
				return null;
			}
			return array(
				'user_id' => $id,
				'email'   => (string) $account->user_email,
			);
		}

		if ( \FreeFormCertificate\Admin\AdminUserCustomFields::REVEAL_TYPE === $type ) {
			// Only an account the current user may edit: the field is shown
			// on that account's edit screen and nowhere else.
			if ( $id <= 0 || ! current_user_can( 'edit_user', $id ) || ! get_userdata( $id ) ) {
				return null;
			}
			return array(
				'user_id' => $id,
				'fields'  => \FreeFormCertificate\Admin\AdminUserCustomFields::sensitive_values( $id ),
			);
		}

		if ( \FreeFormCertificate\DateMessages\DateMessagesAdminPage::REVEAL_TYPE === $type ) {
			// Only a person the upcoming-dates panel can list: one with a
			// birth date on the profile.
			$account = $id > 0 ? get_userdata( $id ) : false;
			if ( ! $account || ! \FreeFormCertificate\DateMessages\DateMessagesAdminPage::is_listed( $id ) ) {
				return null;
			}
			return array(
				'user_id' => $id,
				'name'    => (string) $account->display_name,
			);
		}

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
	 * Load the PII of a reregistration submission (#1815).
	 *
	 * The email is the submitter's account address (`user_email`), which is
	 * what the submissions list shows; a submission stores no email column of
	 * its own. CPF and RF come from the submission's `data` JSON, decrypted
	 * when stored as an envelope. There is no IP on a reregistration.
	 *
	 * @param int $id Submission ID.
	 * @return array<string, mixed>|null Row with `email` / `cpf` / `rf` /
	 *                                   `user_id` keys, or null when missing.
	 */
	private function load_reregistration_pii_record( int $id ): ?array {
		$sub = \FreeFormCertificate\Reregistration\ReregistrationSubmissionReader::get_by_id( $id );
		if ( ! $sub ) {
			return null;
		}

		$user_id = (int) $sub->user_id;
		$user    = $user_id > 0 ? get_userdata( $user_id ) : false;

		$data   = ! empty( $sub->data ) ? json_decode( (string) $sub->data, true ) : array();
		$fields = is_array( $data ) && is_array( $data['fields'] ?? null ) ? $data['fields'] : array();

		$record = array(
			'user_id' => $user_id,
			'email'   => $user ? (string) $user->user_email : '',
		);
		foreach ( array( 'cpf', 'rf' ) as $key ) {
			$value = isset( $fields[ $key ] ) && is_string( $fields[ $key ] ) ? $fields[ $key ] : '';
			if ( '' !== $value && \FreeFormCertificate\Core\Encryption::looks_like_envelope( $value ) ) {
				$value = (string) \FreeFormCertificate\Core\Encryption::decrypt( $value );
			}
			$record[ $key ] = $value;
		}
		return $record;
	}

	/**
	 * The clear, display-formatted value of one field of a loaded record.
	 *
	 * @param array<string, mixed> $record Record from {@see load_pii_record()}.
	 * @param string               $field  Field key.
	 * @return string '' when the record holds no value for it.
	 */
	private function pii_display_value( array $record, string $field ): string {
		switch ( $field ) {
			case 'cpf':
				return \FreeFormCertificate\Core\DocumentFormatter::format_cpf( (string) ( $record['cpf'] ?? '' ) );
			case 'rf':
				return \FreeFormCertificate\Core\DocumentFormatter::format_rf( (string) ( $record['rf'] ?? '' ) );
			case 'ip':
				return (string) ( $record['user_ip'] ?? '' );
			case 'phone':
				return (string) ( $record['phone'] ?? '' );
			case 'name':
				return (string) ( $record['name'] ?? '' );
			case 'account_email':
				// The linked account's email (#1824), gated by the
				// submission's policy: it is shown on the submission screen,
				// to whoever may reveal that submission's own email.
				$owner   = isset( $record['user_id'] ) ? (int) $record['user_id'] : 0;
				$account = $owner > 0 ? get_userdata( $owner ) : false;
				return $account ? (string) $account->user_email : '';
			case 'email':
				return (string) ( $record['email'] ?? '' );
			default:
				// A user-profile custom field, `cf_<id>`.
				$fields = is_array( $record['fields'] ?? null ) ? $record['fields'] : array();
				return (string) ( $fields[ (int) substr( $field, 3 ) ] ?? '' );
		}
	}

	/**
	 * Reveal one field of one record for the current user, without auditing.
	 *
	 * @param string $type  Record type, a key of {@see pii_reveal_configs()}.
	 * @param int    $id    Record ID.
	 * @param string $field Field key.
	 * @return array{value?: string, status: int, message?: string} `status`
	 *         200 with `value`, or the HTTP status and message of the refusal.
	 */
	private function reveal_one( string $type, int $id, string $field ): array {
		$configs = $this->pii_reveal_configs();
		$config  = $configs[ $type ];

		if ( ! $this->pii_reveal_serves( $type, $field ) ) {
			return array(
				'status'  => 400,
				'message' => __( 'Unsupported field.', 'ffcertificate' ),
			);
		}
		if ( $id <= 0 ) {
			return array(
				'status'  => 400,
				'message' => __( 'Invalid record.', 'ffcertificate' ),
			);
		}

		$record = $this->load_pii_record( $type, $id );
		if ( null === $record ) {
			return array(
				'status'  => 404,
				'message' => __( 'Record not found.', 'ffcertificate' ),
			);
		}
		$owner = isset( $record['user_id'] ) ? (int) $record['user_id'] : null;

		if ( \FreeFormCertificate\Core\PiiAccessPolicy::TIER_MASKED === \FreeFormCertificate\Core\PiiAccessPolicy::resolve( $config['pii_cap'], $owner ) ) {
			return array(
				'status'  => 403,
				'message' => __( 'You do not have permission to reveal this field.', 'ffcertificate' ),
			);
		}

		$display = $this->pii_display_value( $record, $field );
		if ( '' === $display ) {
			return array(
				'status'  => 404,
				'message' => __( 'No value to reveal.', 'ffcertificate' ),
			);
		}

		return array(
			'status' => 200,
			'value'  => $display,
		);
	}

	/**
	 * Reveal one decrypted PII field of a record, gated by the shared PII
	 * policy and audited (#739 §3.3, #1863). The `type` POST field selects
	 * the record domain (defaults to `submission` for backward compatibility);
	 * {@see pii_reveal_configs()} lists them.
	 *
	 * The admin surfaces render the masked value only; the plaintext is
	 * fetched here on demand so it never sits in the initial HTML. Returns
	 * 403 for the masked tier. Every reveal served writes an audit row, since
	 * a call is always a deliberate disclosure (#1655); since #1863 there is
	 * no staff tier that sees the value any other way.
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

		$id     = $this->get_post_int( 'submission_id' );
		$field  = $this->get_post_param( 'field' );
		$result = $this->reveal_one( $type, $id, $field );
		if ( 200 !== $result['status'] || ! isset( $result['value'] ) ) {
			wp_send_json_error( array( 'message' => $result['message'] ?? '' ), $result['status'] );
		}

		// Every served reveal is audited (#1655); ActivityLog honours the
		// Activity Log switch and its category, as every other entry does.
		\FreeFormCertificate\Core\ActivityLog::log(
			$config['audit_action'],
			\FreeFormCertificate\Core\ActivityLog::LEVEL_INFO,
			array( 'field_key' => $field ),
			get_current_user_id(),
			$id
		);

		wp_send_json_success(
			array(
				'field' => $field,
				'value' => $result['value'],
			)
		);
	}

	/**
	 * Reveal every masked value of a list page at once (#1863).
	 *
	 * `items` is a JSON list of `{type, id, field}`, one per reveal button
	 * the page drew. Each is checked exactly as {@see reveal_pii()} checks
	 * one -- surface cap, field, record, policy -- and one refused item never
	 * stops the rest. The audit is ONE row per record domain, naming every
	 * record revealed and the fields, so a page of fifty rows is one event
	 * rather than fifty.
	 *
	 * @return void
	 */
	public function reveal_pii_page(): void {
		$this->verify_ajax_nonce( 'ffc_reveal_pii_nonce' );

		$raw   = json_decode( \FreeFormCertificate\Core\RequestInput::get_post_string( 'items', '[]' ), true );
		$items = is_array( $raw ) ? $raw : array();
		if ( array() === $items ) {
			wp_send_json_error( array( 'message' => __( 'Nothing to reveal.', 'ffcertificate' ) ), 400 );
		}
		if ( count( $items ) > self::PAGE_REVEAL_LIMIT ) {
			wp_send_json_error( array( 'message' => __( 'Too many values to reveal at once.', 'ffcertificate' ) ), 400 );
		}

		$configs  = $this->pii_reveal_configs();
		$values   = array();
		$revealed = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$type  = is_string( $item['type'] ?? null ) ? $item['type'] : '';
			$id    = is_numeric( $item['id'] ?? null ) ? (int) $item['id'] : 0;
			$field = is_string( $item['field'] ?? null ) ? $item['field'] : '';
			if ( ! isset( $configs[ $type ] ) || ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( $configs[ $type ]['surface_cap'] ) ) {
				continue;
			}

			$result = $this->reveal_one( $type, $id, $field );
			if ( 200 !== $result['status'] || ! isset( $result['value'] ) ) {
				continue;
			}

			$values[] = array(
				'type'  => $type,
				'id'    => $id,
				'field' => $field,
				'value' => $result['value'],
			);

			$revealed[ $type ]['ids'][ $id ]       = $id;
			$revealed[ $type ]['fields'][ $field ] = $field;
		}

		if ( array() === $values ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to reveal these values.', 'ffcertificate' ) ), 403 );
		}

		foreach ( $revealed as $type => $what ) {
			\FreeFormCertificate\Core\ActivityLog::log(
				$configs[ $type ]['audit_action'],
				\FreeFormCertificate\Core\ActivityLog::LEVEL_INFO,
				array(
					'field_key'  => 'page',
					'fields'     => array_values( $what['fields'] ),
					'record_ids' => array_values( $what['ids'] ),
				),
				get_current_user_id(),
				0
			);
		}

		wp_send_json_success( array( 'values' => $values ) );
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
			// The picker tells accounts apart by name; the address is personal
			// data and travels masked like every other (#1863).
			'email'        => \FreeFormCertificate\Core\DocumentFormatter::mask_email( (string) $user->user_email ),
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
		$cpf_rf_hash = \FreeFormCertificate\Core\SensitiveFieldRegistry::hash_identifier( 'cpf', $cpf_rf_clean );
		$hash_column = 'rf' === \FreeFormCertificate\Core\DataSanitizer::classify_cpf_rf( $cpf_rf_clean ) ? 'rf_hash' : 'cpf_hash';

		$table = \FreeFormCertificate\Repositories\SubmissionRepository::get_submissions_table();

		// Search the specific split column.
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
