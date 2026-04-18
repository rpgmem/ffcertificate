<?php
/**
 * UserManager
 *
 * Manages user profile, data retrieval, and backward-compatible delegation
 * to CapabilityManager and UserCreator.
 *
 * Refactored in v4.12.2: user creation logic moved to UserCreator,
 * capability management moved to CapabilityManager.
 *
 * @package FreeFormCertificate\UserDashboard
 * @version 4.12.2 - Split into UserManager + CapabilityManager + UserCreator
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 * @since 3.1.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\UserDashboard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manager for user operations.
 */
class UserManager {

	use \FreeFormCertificate\Core\DatabaseHelperTrait;

	// =====================================================================.
	// Backward-compatible constant aliases → CapabilityManager.
	// =====================================================================.

	public const CONTEXT_CERTIFICATE = CapabilityManager::CONTEXT_CERTIFICATE;
	public const CONTEXT_APPOINTMENT = CapabilityManager::CONTEXT_APPOINTMENT;
	public const CONTEXT_AUDIENCE    = CapabilityManager::CONTEXT_AUDIENCE;

	public const CERTIFICATE_CAPABILITIES = CapabilityManager::CERTIFICATE_CAPABILITIES;
	public const APPOINTMENT_CAPABILITIES = CapabilityManager::APPOINTMENT_CAPABILITIES;
	public const AUDIENCE_CAPABILITIES    = CapabilityManager::AUDIENCE_CAPABILITIES;
	public const ADMIN_CAPABILITIES       = CapabilityManager::ADMIN_CAPABILITIES;
	public const FUTURE_CAPABILITIES      = CapabilityManager::FUTURE_CAPABILITIES;

	// =====================================================================.
	// Backward-compatible delegation → UserCreator.
	// =====================================================================.

	/**
	 * Get or create a WordPress user for the given credentials.
	 *
	 * @see UserCreator::get_or_create_user()
	 * @param string $cpf_rf_hash     CPF/RF hash.
	 * @param string $email           Email address.
	 * @param array<string, mixed>  $submission_data Submission data.
	 * @param string $context         Context.
	 * @param string $identifier_type Identifier type.
	 * @return int|\WP_Error User ID on success, WP_Error on failure
	 */
	public static function get_or_create_user( string $cpf_rf_hash, string $email, array $submission_data = array(), string $context = self::CONTEXT_CERTIFICATE, string $identifier_type = UserCreator::TYPE_AUTO ) {
		return UserCreator::get_or_create_user( $cpf_rf_hash, $email, $submission_data, $context, $identifier_type );
	}

	/**
	 * Generate a username from email and submission data.
	 *
	 * @see UserCreator::generate_username()
	 * @param string $email           Email address.
	 * @param array<string, mixed>  $submission_data Submission data.
	 */
	public static function generate_username( string $email, array $submission_data = array() ): string {
		return UserCreator::generate_username( $email, $submission_data );
	}

	// =====================================================================.
	// Backward-compatible delegation → CapabilityManager.
	// =====================================================================.

	/**
	 * Get all FFC capabilities.
	 *
	 * @see CapabilityManager::get_all_capabilities()
	 * @return array<int, string>
	 */
	public static function get_all_capabilities(): array {
		return CapabilityManager::get_all_capabilities();
	}

	/**
	 * Register role.
	 *
	 * @see CapabilityManager::register_role()
	 */
	public static function register_role(): void {
		CapabilityManager::register_role();
	}

	/**
	 * Remove role.
	 *
	 * @see CapabilityManager::remove_role()
	 */
	public static function remove_role(): void {
		CapabilityManager::remove_role();
	}

	/**
	 * Grant certificate capabilities.
	 *
	 * @see CapabilityManager::grant_certificate_capabilities()
	 * @param int $user_id User ID.
	 */
	public static function grant_certificate_capabilities( int $user_id ): void {
		CapabilityManager::grant_certificate_capabilities( $user_id );
	}

	/**
	 * Grant appointment capabilities.
	 *
	 * @see CapabilityManager::grant_appointment_capabilities()
	 * @param int $user_id User ID.
	 */
	public static function grant_appointment_capabilities( int $user_id ): void {
		CapabilityManager::grant_appointment_capabilities( $user_id );
	}

	/**
	 * Grant audience capabilities.
	 *
	 * @see CapabilityManager::grant_audience_capabilities()
	 * @param int $user_id User ID.
	 */
	public static function grant_audience_capabilities( int $user_id ): void {
		CapabilityManager::grant_audience_capabilities( $user_id );
	}

	/**
	 * Check if has certificate access.
	 *
	 * @see CapabilityManager::has_certificate_access()
	 * @param int $user_id User ID.
	 */
	public static function has_certificate_access( int $user_id ): bool {
		return CapabilityManager::has_certificate_access( $user_id );
	}

	/**
	 * Check if has appointment access.
	 *
	 * @see CapabilityManager::has_appointment_access()
	 * @param int $user_id User ID.
	 */
	public static function has_appointment_access( int $user_id ): bool {
		return CapabilityManager::has_appointment_access( $user_id );
	}

	/**
	 * Get FFC capabilities assigned to a specific user.
	 *
	 * Get user ffc capabilities.
	 *
	 * Get user ffc capabilities.
	 *
	 * Get user ffc capabilities.
	 *
	 * Get user ffc capabilities.
	 *
	 * Get user ffc capabilities.
	 *
	 * Get user ffc capabilities.
	 *
	 * Get user ffc capabilities.
	 *
	 * Get user ffc capabilities.
	 *
	 * Get user ffc capabilities.
	 *
	 * Get user ffc capabilities.
	 *
	 * Get user ffc capabilities.
	 *
	 * Get user ffc capabilities.
	 *
	 * Get user ffc capabilities.
	 *
	 * Get user ffc capabilities.
	 *
	 * Get user ffc capabilities.
	 *
	 * Get user ffc capabilities.
	 *
	 * Get user ffc capabilities.
	 *
	 * Get user ffc capabilities.
	 *
	 * Get user ffc capabilities.
	 *
	 * Get user ffc capabilities.
	 *
	 * Get user ffc capabilities.
	 *
	 * Get user ffc capabilities.
	 *
	 * Get user ffc capabilities.
	 *
	 * Get user ffc capabilities.
	 *
	 * Get user ffc capabilities.
	 *
	 * Get user ffc capabilities.
	 *
	 * Get user ffc capabilities.
	 *
	 * Get user ffc capabilities.
	 *
	 * Get user ffc capabilities.
	 *
	 * Get user ffc capabilities.
	 *
	 * Get user ffc capabilities.
	 *
	 * Get user ffc capabilities.
	 *
	 * Get user ffc capabilities.
	 *
	 * Get user ffc capabilities.
	 *
	 * Get user ffc capabilities.
	 *
	 * @see CapabilityManager::get_user_ffc_capabilities()
	 * @param int $user_id User ID.
	 * @return array<string, bool>
	 */
	public static function get_user_ffc_capabilities( int $user_id ): array {
		return CapabilityManager::get_user_ffc_capabilities( $user_id );
	}

	/**
	 * Set user capability.
	 *
	 * @see CapabilityManager::set_user_capability()
	 * @param int    $user_id User ID.
	 * @param string $capability Capability.
	 * @param bool   $grant Grant.
	 */
	public static function set_user_capability( int $user_id, string $capability, bool $grant ): bool {
		return CapabilityManager::set_user_capability( $user_id, $capability, $grant );
	}

	// =====================================================================.
	// Profile & Data Retrieval (remain in UserManager)
	// =====================================================================.

	/**
	 * Get user profile from ffc_user_profiles
	 *
	 * Get profile.
	 *
	 * Get profile.
	 *
	 * Get profile.
	 *
	 * Get profile.
	 *
	 * Get profile.
	 *
	 * @since 4.9.4
	 * @param int $user_id WordPress user ID.
	 * @return array<string, mixed> Profile data
	 */
	public static function get_profile( int $user_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'ffc_user_profiles';

		if ( self::table_exists( $table ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$profile = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE user_id = %d',
					$table,
					$user_id
				),
				ARRAY_A
			);

			if ( $profile ) {
				return $profile;
			}
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return array();
		}

		return array(
			'user_id'      => $user_id,
			'display_name' => $user->display_name,
			'phone'        => '',
			'department'   => '',
			'organization' => '',
			'notes'        => '',
			'preferences'  => null,
			'created_at'   => $user->user_registered,
			'updated_at'   => $user->user_registered,
		);
	}

	/**
	 * Update user profile in ffc_user_profiles
	 *
	 * @since 4.9.4
	 * @param int                  $user_id WordPress user ID.
	 * @param array<string, mixed> $data    Profile fields to update.
	 * @return bool True on success
	 */
	public static function update_profile( int $user_id, array $data ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'ffc_user_profiles';

		if ( ! self::table_exists( $table ) ) {
			return false;
		}

		$allowed     = array( 'display_name', 'phone', 'department', 'organization', 'notes' );
		$update_data = array();
		$formats     = array();

		foreach ( $allowed as $field ) {
			if ( isset( $data[ $field ] ) ) {
				$update_data[ $field ] = sanitize_text_field( $data[ $field ] );
				$formats[]             = '%s';
			}
		}

		if ( isset( $data['preferences'] ) && is_array( $data['preferences'] ) ) {
			$update_data['preferences'] = wp_json_encode( $data['preferences'] );
			$formats[]                  = '%s';
		}

		if ( empty( $update_data ) ) {
			return false;
		}

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE user_id = %d',
				$table,
				$user_id
			)
		);

		if ( $exists ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->update( $table, $update_data, array( 'user_id' => $user_id ), $formats, array( '%d' ) );
		} else {
			$update_data['user_id']    = $user_id;
			$update_data['created_at'] = current_time( 'mysql' );
			$formats[]                 = '%d';
			$formats[]                 = '%s';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$result = $wpdb->insert( $table, $update_data, $formats );
		}

		if ( isset( $data['display_name'] ) ) {
			wp_update_user(
				array(
					'ID'           => $user_id,
					'display_name' => sanitize_text_field( $data['display_name'] ),
				)
			);
		}

		return false !== $result;
	}

	/**
	 * User profile fields that live in wp_ffc_user_profiles.
	 *
	 * Keys not in this list are stored as individual usermeta entries
	 * under the pattern ffc_user_{key}.
	 *
	 * @var array<int, string>
	 */
	private const PROFILE_TABLE_KEYS = array( 'display_name', 'phone', 'department', 'organization', 'notes' );

	/**
	 * Usermeta key prefix for extended profile fields.
	 */
	private const EXTENDED_META_PREFIX = 'ffc_user_';

	/**
	 * Update extended user profile.
	 *
	 * Splits the payload between the ffc_user_profiles table (for columns it
	 * supports) and wp_usermeta (for everything else, keyed by
	 * ffc_user_{profile_key}). Keys listed in $sensitive_keys are encrypted
	 * via the Encryption helper before being persisted to usermeta.
	 *
	 * Intended to be called from the dynamic reregistration data processor
	 * when syncing approved submission values back onto the user profile.
	 *
	 * @since 4.13.0
	 * @param int                  $user_id        WordPress user ID.
	 * @param array<string, mixed> $data           profile_key => value pairs.
	 * @param array<int, string>   $sensitive_keys profile_keys whose values must be encrypted.
	 * @return bool True if at least one value was persisted successfully.
	 */
	public static function update_extended_profile( int $user_id, array $data, array $sensitive_keys = array() ): bool {
		if ( empty( $data ) ) {
			return false;
		}

		$table_payload    = array();
		$usermeta_payload = array();

		foreach ( $data as $key => $value ) {
			if ( ! is_string( $key ) || '' === $key ) {
				continue;
			}

			if ( in_array( $key, self::PROFILE_TABLE_KEYS, true ) ) {
				$table_payload[ $key ] = $value;
			} else {
				$usermeta_payload[ $key ] = $value;
			}
		}

		$success = false;

		// 1. Profile table columns → delegate to update_profile().
		if ( ! empty( $table_payload ) ) {
			$success = self::update_profile( $user_id, $table_payload );
		}

		// 2. Extended keys → wp_usermeta (encrypted when sensitive).
		$sensitive_map = array_flip( $sensitive_keys );
		foreach ( $usermeta_payload as $key => $value ) {
			$meta_key = self::EXTENDED_META_PREFIX . sanitize_key( $key );

			if ( is_scalar( $value ) ) {
				$scalar_value = (string) $value;
			} else {
				$value_json   = wp_json_encode( $value );
				$scalar_value = $value_json ? $value_json : '';
			}

			if ( isset( $sensitive_map[ $key ] ) ) {
				if ( '' === $scalar_value || null === $scalar_value ) {
					delete_user_meta( $user_id, $meta_key );
					continue;
				}

				if ( ! class_exists( '\FreeFormCertificate\Core\Encryption' ) ) {
					continue;
				}

				$encrypted = \FreeFormCertificate\Core\Encryption::encrypt( $scalar_value );
				if ( null === $encrypted ) {
					continue;
				}

				update_user_meta( $user_id, $meta_key, $encrypted );

				// Store a lookup hash for indexed searches (e.g. find user by CPF).
				$hash = \FreeFormCertificate\Core\Encryption::hash( $scalar_value );
				if ( null !== $hash ) {
					update_user_meta( $user_id, $meta_key . '_hash', $hash );
				}

				$success = true;
				continue;
			}

			update_user_meta( $user_id, $meta_key, sanitize_text_field( $scalar_value ) );
			$success = true;
		}

		return $success;
	}

	/**
	 * Get extended user profile, decrypting sensitive keys.
	 *
	 * Reads values from the ffc_user_profiles table (for standard columns)
	 * and from wp_usermeta (for everything else). Sensitive keys are
	 * transparently decrypted.
	 *
	 * @since 4.13.0
	 * @param int                $user_id        WordPress user ID.
	 * @param array<int, string> $extra_keys     Non-table keys to fetch from usermeta.
	 * @param array<int, string> $sensitive_keys Keys whose stored values are encrypted.
	 * @return array<string, mixed>
	 */
	public static function get_extended_profile( int $user_id, array $extra_keys = array(), array $sensitive_keys = array() ): array {
		$profile = self::get_profile( $user_id );

		$sensitive_map = array_flip( $sensitive_keys );

		foreach ( $extra_keys as $key ) {
			if ( ! is_string( $key ) || '' === $key ) {
				continue;
			}
			if ( in_array( $key, self::PROFILE_TABLE_KEYS, true ) ) {
				continue; // Already in $profile.
			}

			$meta_key = self::EXTENDED_META_PREFIX . sanitize_key( $key );
			$raw      = get_user_meta( $user_id, $meta_key, true );

			if ( '' === $raw || null === $raw ) {
				$profile[ $key ] = '';
				continue;
			}

			if ( isset( $sensitive_map[ $key ] ) && class_exists( '\FreeFormCertificate\Core\Encryption' ) ) {
				$decrypted       = \FreeFormCertificate\Core\Encryption::decrypt( (string) $raw );
				$profile[ $key ] = null !== $decrypted ? $decrypted : '';
			} else {
				$profile[ $key ] = $raw;
			}
		}

		return $profile;
	}

	/**
	 * Get user's CPF/RF (masked) — first found
	 *
	 * @param int $user_id WordPress user ID.
	 * @return string|null Masked CPF/RF or null
	 */
	public static function get_user_cpf_masked( int $user_id ): ?string {
		$cpfs = self::get_user_cpfs_masked( $user_id );
		return ! empty( $cpfs ) ? $cpfs[0] : null;
	}

	/**
	 * Get all user's CPF/RF values (masked)
	 *
	 * @since 4.3.0
	 * @param int $user_id WordPress user ID.
	 * @return array<int, string> Array of masked CPF/RF values
	 */
	public static function get_user_cpfs_masked( int $user_id ): array {
		global $wpdb;
		$submissions_table  = \FreeFormCertificate\Core\Utils::get_submissions_table();
		$appointments_table = $wpdb->prefix . 'ffc_self_scheduling_appointments';

		// Query both submissions and appointments tables so users who only.
		// have self-scheduling appointments still get their CPF/RF displayed.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT cpf_encrypted, rf_encrypted FROM %i
             WHERE user_id = %d
             AND (cpf_encrypted IS NOT NULL OR rf_encrypted IS NOT NULL)
             UNION ALL
             SELECT cpf_encrypted, rf_encrypted FROM %i
             WHERE user_id = %d
             AND (cpf_encrypted IS NOT NULL OR rf_encrypted IS NOT NULL)',
				$submissions_table,
				$user_id,
				$appointments_table,
				$user_id
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return array();
		}

		$cpfs_masked = array();

		foreach ( $rows as $row ) {
			try {
				// Prefer split columns.
				$plain = null;
				if ( ! empty( $row['cpf_encrypted'] ) ) {
					$plain = \FreeFormCertificate\Core\Encryption::decrypt( $row['cpf_encrypted'] );
				} elseif ( ! empty( $row['rf_encrypted'] ) ) {
					$plain = \FreeFormCertificate\Core\Encryption::decrypt( $row['rf_encrypted'] );
				}

				if ( ! empty( $plain ) ) {
					$masked = \FreeFormCertificate\Core\DocumentFormatter::mask_cpf( $plain );
					if ( ! empty( $masked ) && ! in_array( $masked, $cpfs_masked, true ) ) {
						$cpfs_masked[] = $masked;
					}
				}
			} catch ( \Exception $e ) {
				if ( class_exists( '\FreeFormCertificate\Core\Debug' ) ) {
					\FreeFormCertificate\Core\Debug::log_user_manager(
						'Failed to decrypt CPF/RF',
						array(
							'user_id' => $user_id,
							'error'   => $e->getMessage(),
						)
					);
				}
				continue;
			}
		}

		return $cpfs_masked;
	}

	/**
	 * Get user's identifiers (CPFs and RFs) masked and typed
	 *
	 * @since 4.13.0
	 * @param int $user_id WordPress user ID.
	 * @return array{cpfs: array<int, string>, rfs: array<int, string>}
	 */
	public static function get_user_identifiers_masked( int $user_id ): array {
		global $wpdb;
		$submissions_table  = \FreeFormCertificate\Core\Utils::get_submissions_table();
		$appointments_table = $wpdb->prefix . 'ffc_self_scheduling_appointments';

		// Query both submissions and appointments tables.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT cpf_encrypted, rf_encrypted FROM %i
             WHERE user_id = %d
             AND (cpf_encrypted IS NOT NULL OR rf_encrypted IS NOT NULL)
             UNION ALL
             SELECT cpf_encrypted, rf_encrypted FROM %i
             WHERE user_id = %d
             AND (cpf_encrypted IS NOT NULL OR rf_encrypted IS NOT NULL)',
				$submissions_table,
				$user_id,
				$appointments_table,
				$user_id
			),
			ARRAY_A
		);

		$cpfs = array();
		$rfs  = array();

		if ( empty( $rows ) ) {
			return array(
				'cpfs' => $cpfs,
				'rfs'  => $rfs,
			);
		}

		foreach ( $rows as $row ) {
			try {
				if ( ! empty( $row['cpf_encrypted'] ) ) {
					$plain = \FreeFormCertificate\Core\Encryption::decrypt( $row['cpf_encrypted'] );
					if ( ! empty( $plain ) ) {
						$masked = \FreeFormCertificate\Core\DocumentFormatter::mask_cpf( $plain );
						if ( ! empty( $masked ) && ! in_array( $masked, $cpfs, true ) ) {
							$cpfs[] = $masked;
						}
					}
				} elseif ( ! empty( $row['rf_encrypted'] ) ) {
					$plain = \FreeFormCertificate\Core\Encryption::decrypt( $row['rf_encrypted'] );
					if ( ! empty( $plain ) ) {
						$masked = \FreeFormCertificate\Core\DocumentFormatter::mask_cpf( $plain );
						if ( ! empty( $masked ) && ! in_array( $masked, $rfs, true ) ) {
							$rfs[] = $masked;
						}
					}
				}
			} catch ( \Exception $e ) {
				continue;
			}
		}

		return array(
			'cpfs' => $cpfs,
			'rfs'  => $rfs,
		);
	}

	/**
	 * Get all emails used by a user in submissions
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array<int, string> Array of emails
	 */
	public static function get_user_emails( int $user_id ): array {
		global $wpdb;
		$submissions_table  = \FreeFormCertificate\Core\Utils::get_submissions_table();
		$appointments_table = $wpdb->prefix . 'ffc_self_scheduling_appointments';

		// Query both submissions and appointments tables.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$encrypted_emails = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT email_encrypted FROM %i
             WHERE user_id = %d
             AND email_encrypted IS NOT NULL
             AND email_encrypted != ''
             UNION ALL
             SELECT email_encrypted FROM %i
             WHERE user_id = %d
             AND email_encrypted IS NOT NULL
             AND email_encrypted != ''",
				$submissions_table,
				$user_id,
				$appointments_table,
				$user_id
			)
		);

		if ( empty( $encrypted_emails ) ) {
			$user = get_user_by( 'id', $user_id );
			return $user ? array( $user->user_email ) : array();
		}

		$emails = array();

		foreach ( $encrypted_emails as $encrypted ) {
			try {
				$email = \FreeFormCertificate\Core\Encryption::decrypt( $encrypted );
				if ( is_email( $email ) ) {
					$emails[] = $email;
				}
			} catch ( \Exception $e ) {
				continue;
			}
		}

		$user = get_user_by( 'id', $user_id );
		if ( $user && is_email( $user->user_email ) ) {
			$emails[] = $user->user_email;
		}

		return array_unique( $emails );
	}

	/**
	 * Get all distinct names used by a user in submissions
	 *
	 * @since 4.3.0
	 * @param int $user_id WordPress user ID.
	 * @return array<int, string> Array of names
	 */
	public static function get_user_names( int $user_id ): array {
		global $wpdb;
		$table = \FreeFormCertificate\Core\Utils::get_submissions_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$submissions = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT data FROM %i
             WHERE user_id = %d
             AND data IS NOT NULL
             AND data != ''",
				$table,
				$user_id
			)
		);

		if ( empty( $submissions ) ) {
			$user = get_user_by( 'id', $user_id );
			return $user ? array( $user->display_name ) : array();
		}

		$names                = array();
		$possible_name_fields = array( 'nome_completo', 'nome', 'name', 'full_name', 'ffc_nome', 'participante' );

		foreach ( $submissions as $data_json ) {
			$data = json_decode( $data_json, true );

			if ( ! is_array( $data ) ) {
				continue;
			}

			foreach ( $possible_name_fields as $field ) {
				if ( ! empty( $data[ $field ] ) && is_string( $data[ $field ] ) ) {
					$name = trim( $data[ $field ] );
					if ( ! empty( $name ) && ! in_array( $name, $names, true ) ) {
						$names[] = $name;
					}
					break;
				}
			}
		}

		if ( empty( $names ) ) {
			$user = get_user_by( 'id', $user_id );
			if ( $user && ! empty( $user->display_name ) ) {
				$names[] = $user->display_name;
			}
		}

		return $names;
	}
}
