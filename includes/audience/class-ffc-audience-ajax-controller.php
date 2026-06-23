<?php
/**
 * AudienceAjaxController
 *
 * The admin-ajax endpoints for the audience booking system (bookings,
 * conflicts, schedule slots, user search, per-user permissions, and the
 * custom-fields editor). Split out of {@see AudienceLoader} in the
 * frontend-audit Item 3 fragmentation so the loader handles bootstrap/hooks/
 * enqueue while this controller owns the request handlers. Pure code
 * movement — no behavior change. Uses the shared AjaxTrait for nonce,
 * permission, $_POST and exception helpers.
 *
 * @package FreeFormCertificate\Audience
 * @since 4.5.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Audience admin-ajax controller.
 */
final class AudienceAjaxController {

	use \FreeFormCertificate\Core\AjaxTrait;

	/**
	 * Register the wp_ajax_* hooks for the audience admin AJAX endpoints.
	 *
	 * @return void
	 */
	public function register(): void {
		// AJAX handlers.
		add_action( 'wp_ajax_ffc_audience_check_conflicts', array( $this, 'ajax_check_conflicts' ) );
		add_action( 'wp_ajax_ffc_audience_create_booking', array( $this, 'ajax_create_booking' ) );
		add_action( 'wp_ajax_ffc_audience_cancel_booking', array( $this, 'ajax_cancel_booking' ) );
		add_action( 'wp_ajax_ffc_audience_get_booking', array( $this, 'ajax_get_booking' ) );
		add_action( 'wp_ajax_ffc_audience_get_schedule_slots', array( $this, 'ajax_get_schedule_slots' ) );
		add_action( 'wp_ajax_ffc_search_users', array( $this, 'ajax_search_users' ) );
		add_action( 'wp_ajax_ffc_audience_get_environments', array( $this, 'ajax_get_environments' ) );
		add_action( 'wp_ajax_ffc_audience_add_user_permission', array( $this, 'ajax_add_user_permission' ) );
		add_action( 'wp_ajax_ffc_audience_update_user_permission', array( $this, 'ajax_update_user_permission' ) );
		add_action( 'wp_ajax_ffc_audience_remove_user_permission', array( $this, 'ajax_remove_user_permission' ) );

		// Custom fields AJAX.
		add_action( 'wp_ajax_ffc_save_custom_fields', array( $this, 'ajax_save_custom_fields' ) );
		add_action( 'wp_ajax_ffc_delete_custom_field', array( $this, 'ajax_delete_custom_field' ) );
		add_action( 'wp_ajax_ffc_replicate_field_options', array( $this, 'ajax_replicate_field_options' ) );
	}

	/**
	 * AJAX: Check for conflicts
	 *
	 * @return void
	 */
	public function ajax_check_conflicts(): void {
		try {
			$this->verify_ajax_nonce( 'ffc_admin_nonce' );
			$this->check_ajax_permission();

			$environment_id = $this->get_post_int( 'environment_id' );
			$booking_date   = $this->get_post_param( 'booking_date' );
			$start_time     = $this->get_post_param( 'start_time' );
			$end_time       = $this->get_post_param( 'end_time' );
			$audience_ids   = array_map( 'absint', $this->get_post_array( 'audience_ids' ) );
			$user_ids       = array_map( 'absint', $this->get_post_array( 'user_ids' ) );

			if ( ! $environment_id || ! $booking_date || ! $start_time || ! $end_time ) {
				wp_send_json_error( array( 'message' => __( 'Missing required parameters.', 'ffcertificate' ) ) );
			}

			// Check conflicts using service.
			if ( class_exists( '\FreeFormCertificate\Audience\AudienceConflictService' ) ) {
				$service   = new AudienceConflictService();
				$conflicts = $service->check_conflicts( $environment_id, $booking_date, $start_time, $end_time, $audience_ids, $user_ids );
				wp_send_json_success( array( 'conflicts' => $conflicts ) );
			}

			wp_send_json_error( array( 'message' => __( 'Service not available.', 'ffcertificate' ) ) );
		} catch ( \Throwable $e ) {
			$this->handle_ajax_exception( $e );
		}
	}

	/**
	 * AJAX: Create booking
	 *
	 * @return void
	 */
	public function ajax_create_booking(): void {
		$this->verify_ajax_nonce( 'ffc_admin_nonce' );
		$this->check_ajax_permission();

		// Booking creation is handled by AudienceBookingService.
		// This is a placeholder - actual implementation in Phase 6.
		wp_send_json_error( array( 'message' => __( 'Not implemented yet.', 'ffcertificate' ) ) );
	}

	/**
	 * AJAX: Cancel booking
	 *
	 * @return void
	 */
	public function ajax_cancel_booking(): void {
		try {
			$this->verify_ajax_nonce( 'ffc_admin_nonce' );
			$this->check_ajax_permission();

			$booking_id = $this->get_post_int( 'booking_id' );
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified via verify_ajax_nonce() above.
			$reason = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';

			if ( ! $booking_id ) {
				wp_send_json_error( array( 'message' => __( 'Invalid booking ID.', 'ffcertificate' ) ) );
			}

			$booking = AudienceBookingReader::get_by_id( $booking_id );
			if ( ! $booking ) {
				wp_send_json_error( array( 'message' => __( 'Booking not found.', 'ffcertificate' ) ) );
			}

			if ( 'cancelled' === $booking->status ) {
				wp_send_json_error( array( 'message' => __( 'Booking is already cancelled.', 'ffcertificate' ) ) );
			}

			$result = AudienceBookingWriter::cancel( $booking_id, $reason );
			if ( ! $result ) {
				wp_send_json_error( array( 'message' => __( 'Failed to cancel booking.', 'ffcertificate' ) ) );
			}

			do_action( 'ffcertificate_audience_booking_cancelled', $booking_id, $reason );

			wp_send_json_success( array( 'message' => __( 'Booking cancelled successfully.', 'ffcertificate' ) ) );
		} catch ( \Throwable $e ) {
			$this->handle_ajax_exception( $e );
		}
	}

	/**
	 * AJAX: Get booking details
	 *
	 * @return void
	 */
	public function ajax_get_booking(): void {
		try {
			$this->verify_ajax_nonce( 'ffc_admin_nonce' );
			$this->check_ajax_permission();

			$booking_id = $this->get_post_int( 'booking_id' );
			if ( ! $booking_id ) {
				wp_send_json_error( array( 'message' => __( 'Invalid booking ID.', 'ffcertificate' ) ) );
			}

			$booking = AudienceBookingReader::get_by_id( $booking_id );
			if ( ! $booking ) {
				wp_send_json_error( array( 'message' => __( 'Booking not found.', 'ffcertificate' ) ) );
			}

			// Get creator name.
			$creator      = get_userdata( (int) $booking->created_by );
			$creator_name = $creator ? $creator->display_name : __( 'Unknown', 'ffcertificate' );

			// Format audiences.
			$audiences = array();
			if ( ! empty( $booking->audiences ) ) {
				foreach ( $booking->audiences as $aud ) {
					$audiences[] = array(
						'id'   => $aud->audience_id ?? $aud->id ?? 0,
						'name' => $aud->name ?? $aud->audience_name ?? '',
					);
				}
			}

			// Format users.
			$users = array();
			if ( ! empty( $booking->users ) ) {
				foreach ( $booking->users as $u ) {
					$user_data = get_userdata( (int) $u );
					if ( $user_data ) {
						$users[] = array(
							'id'    => $user_data->ID,
							'name'  => $user_data->display_name,
							'email' => $user_data->user_email,
						);
					}
				}
			}

			wp_send_json_success(
				array(
					'id'               => $booking->id,
					'booking_date'     => $booking->booking_date,
					'start_time'       => $booking->start_time,
					'end_time'         => $booking->end_time,
					'is_all_day'       => (int) ( $booking->is_all_day ?? 0 ),
					'environment_name' => $booking->environment_name,
					'description'      => $booking->description,
					'booking_type'     => $booking->booking_type,
					'status'           => $booking->status,
					'cancel_reason'    => $booking->cancel_reason ?? '',
					'created_by'       => $creator_name,
					'created_at'       => $booking->created_at,
					'audiences'        => $audiences,
					'users'            => $users,
				)
			);
		} catch ( \Throwable $e ) {
			$this->handle_ajax_exception( $e );
		}
	}

	/**
	 * AJAX: Get schedule slots for a date range
	 *
	 * @return void
	 */
	public function ajax_get_schedule_slots(): void {
		$this->verify_ajax_nonce( 'ffc_admin_nonce' );
		$this->check_ajax_permission();

		// Slot retrieval is handled by AudienceScheduleService.
		// This is a placeholder - actual implementation in Phase 5.
		wp_send_json_error( array( 'message' => __( 'Not implemented yet.', 'ffcertificate' ) ) );
	}

	/**
	 * AJAX: Search users for member selection
	 *
	 * @return void
	 */
	public function ajax_search_users(): void {
		try {
			$this->verify_ajax_nonce( 'ffc_search_users' );
			$this->check_ajax_permission();

			$query = $this->get_post_param( 'query' );

			if ( strlen( $query ) < 2 ) {
				wp_send_json_success( array() );
			}

			$users = get_users(
				array(
					'search'         => '*' . $query . '*',
					'search_columns' => array( 'user_login', 'user_email', 'display_name' ),
					'number'         => 20,
					'orderby'        => 'display_name',
				)
			);

			$results = array();
			foreach ( $users as $user ) {
				$results[] = array(
					'id'    => $user->ID,
					'name'  => $user->display_name,
					'email' => $user->user_email,
				);
			}

			wp_send_json_success( $results );
		} catch ( \Throwable $e ) {
			$this->handle_ajax_exception( $e );
		}
	}

	/**
	 * AJAX: Get environments by schedule ID
	 *
	 * @return void
	 */
	public function ajax_get_environments(): void {
		try {
			$this->verify_ajax_nonce( 'ffc_admin_nonce' );
			$this->check_ajax_permission();

			$schedule_id = $this->get_post_int( 'schedule_id' );

			if ( $schedule_id <= 0 ) {
				wp_send_json_success( array() );
			}

			$environments = AudienceEnvironmentRepository::get_by_schedule( $schedule_id );

			$results = array();
			foreach ( $environments as $env ) {
				$results[] = array(
					'id'   => $env->id,
					'name' => $env->name,
				);
			}

			wp_send_json_success( $results );
		} catch ( \Throwable $e ) {
			$this->handle_ajax_exception( $e );
		}
	}

	/**
	 * AJAX: Add user permission to a schedule
	 *
	 * @return void
	 */
	public function ajax_add_user_permission(): void {
		try {
			$this->verify_ajax_nonce( 'ffc_schedule_permissions', '_wpnonce' );
			$this->check_ajax_permission();

			$schedule_id = $this->get_post_int( 'schedule_id' );
			$user_id     = $this->get_post_int( 'user_id' );

			if ( ! $schedule_id || ! $user_id ) {
				wp_send_json_error( array( 'message' => __( 'Missing required parameters.', 'ffcertificate' ) ) );
			}

			$schedule = AudienceScheduleRepository::get_by_id( $schedule_id );
			if ( ! $schedule ) {
				wp_send_json_error( array( 'message' => __( 'Calendar not found.', 'ffcertificate' ) ) );
			}

			$user = get_userdata( $user_id );
			if ( ! $user ) {
				wp_send_json_error( array( 'message' => __( 'User not found.', 'ffcertificate' ) ) );
			}

			$existing = AudienceScheduleRepository::get_user_permissions( $schedule_id, $user_id );
			if ( $existing ) {
				wp_send_json_error( array( 'message' => __( 'User already has access to this calendar.', 'ffcertificate' ) ) );
			}

			$result = AudienceScheduleRepository::set_user_permissions(
				$schedule_id,
				$user_id,
				array(
					'can_book'               => 1,
					'can_cancel_others'      => 0,
					'can_override_conflicts' => 0,
				)
			);

			if ( ! $result ) {
				wp_send_json_error( array( 'message' => __( 'Error adding user permissions.', 'ffcertificate' ) ) );
			}

			ob_start();
			?>
			<tr data-user-id="<?php echo esc_attr( (string) $user_id ); ?>">
				<td>
					<strong><?php echo esc_html( $user->display_name ); ?></strong>
					<br><span class="description"><?php echo esc_html( $user->user_email ); ?></span>
				</td>
				<td>
					<input type="checkbox" class="ffc-perm-toggle" data-perm="can_book" checked>
				</td>
				<td>
					<input type="checkbox" class="ffc-perm-toggle" data-perm="can_cancel_others">
				</td>
				<td>
					<input type="checkbox" class="ffc-perm-toggle" data-perm="can_override_conflicts">
				</td>
				<td>
					<button type="button" class="button button-small button-link-delete ffc-remove-user-btn"><?php esc_html_e( 'Remove', 'ffcertificate' ); ?></button>
				</td>
			</tr>
			<?php
			$html = ob_get_clean();

			wp_send_json_success( array( 'html' => $html ) );
		} catch ( \Throwable $e ) {
			$this->handle_ajax_exception( $e );
		}
	}

	/**
	 * AJAX: Update a single user permission on a schedule
	 *
	 * @return void
	 */
	public function ajax_update_user_permission(): void {
		try {
			$this->verify_ajax_nonce( 'ffc_schedule_permissions', '_wpnonce' );
			$this->check_ajax_permission();

			$schedule_id = $this->get_post_int( 'schedule_id' );
			$user_id     = $this->get_post_int( 'user_id' );
			$permission  = $this->get_post_param( 'permission' );
			$value       = $this->get_post_int( 'value' );

			if ( ! $schedule_id || ! $user_id || ! $permission ) {
				wp_send_json_error( array( 'message' => __( 'Missing required parameters.', 'ffcertificate' ) ) );
			}

			$allowed_permissions = array( 'can_book', 'can_cancel_others', 'can_override_conflicts' );
			if ( ! in_array( $permission, $allowed_permissions, true ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid permission.', 'ffcertificate' ) ) );
			}

			$existing = AudienceScheduleRepository::get_user_permissions( $schedule_id, $user_id );
			if ( ! $existing ) {
				wp_send_json_error( array( 'message' => __( 'User does not have access to this calendar.', 'ffcertificate' ) ) );
			}

			$perms                = array(
				'can_book'               => (int) $existing->can_book,
				'can_cancel_others'      => (int) $existing->can_cancel_others,
				'can_override_conflicts' => (int) $existing->can_override_conflicts,
			);
			$perms[ $permission ] = $value ? 1 : 0;

			$result = AudienceScheduleRepository::set_user_permissions( $schedule_id, $user_id, $perms );

			if ( ! $result ) {
				wp_send_json_error( array( 'message' => __( 'Error updating permission.', 'ffcertificate' ) ) );
			}

			wp_send_json_success();
		} catch ( \Throwable $e ) {
			$this->handle_ajax_exception( $e );
		}
	}

	/**
	 * AJAX: Save custom fields for an audience (create/update/reorder)
	 *
	 * @since 4.11.0
	 * @return void
	 */
	public function ajax_save_custom_fields(): void {
		try {
			$this->verify_ajax_nonce( 'ffc_admin_nonce' );
			$this->check_ajax_permission( 'ffc_manage_custom_fields' );

			$audience_id = $this->get_post_int( 'audience_id' );
            // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in $this->verify_ajax_nonce() above; JSON decoded and sanitized per-field below.
			$fields_json = isset( $_POST['fields'] ) ? wp_unslash( $_POST['fields'] ) : '[]';
			$fields      = json_decode( $fields_json, true );

			if ( ! is_array( $fields ) ) {
				\FreeFormCertificate\Core\Debug::log_audience(
					'json_decode failed in ajax_save_custom_fields',
					array(
						'json_error' => json_last_error_msg(),
					)
				);
				wp_send_json_error( array( 'message' => __( 'Invalid field data format.', 'ffcertificate' ) ) );
			}

			if ( ! $audience_id ) {
				wp_send_json_error( array( 'message' => __( 'Invalid data.', 'ffcertificate' ) ) );
			}

			$audience = AudienceReader::get_by_id( $audience_id );
			if ( ! $audience ) {
				wp_send_json_error( array( 'message' => __( 'Audience not found.', 'ffcertificate' ) ) );
			}

			$saved_ids = array();
			$errors    = array();

			foreach ( $fields as $index => $field_data ) {
				$field_id = $field_data['id'] ?? null;
				$is_new   = ! $field_id || strpos( (string) $field_id, 'new_' ) === 0;

				$label = sanitize_text_field( $field_data['label'] ?? '' );
				if ( empty( $label ) ) {
					$errors[] = sprintf(
						/* translators: %d: field position */
						__( 'Field #%d: label is required.', 'ffcertificate' ),
						$index + 1
					);
					continue;
				}

				// Build field_options JSON.
				$options = array();
				if ( ! empty( $field_data['choices'] ) ) {
					$choices            = array_map( 'sanitize_text_field', $field_data['choices'] );
					$choices            = array_values(
						array_filter(
							$choices,
							function ( $c ) {
								return '' !== $c;
							}
						)
					);
					$options['choices'] = $choices;
				}
				if ( ! empty( $field_data['help_text'] ) ) {
					$options['help_text'] = sanitize_text_field( $field_data['help_text'] );
				}
				// dependent_select groups (e.g. divisao_setor division→sector map).
				if ( isset( $field_data['groups'] ) && is_array( $field_data['groups'] ) ) {
					$groups = $this->sanitize_dependent_groups( $field_data['groups'] );
					if ( ! empty( $groups ) ) {
						$options['groups'] = $groups;
					}
				}
				// acknowledgment rich-text block (display-only field). Only
				// stored when non-empty so a bulk save never wipes an existing
				// notice (mirrors the standard-field wipe guard below).
				if ( isset( $field_data['html'] ) && '' !== trim( (string) $field_data['html'] ) ) {
					$options['html'] = wp_kses_post( (string) $field_data['html'] );
				}

				// Build validation_rules JSON.
				$rules = array();
				if ( ! empty( $field_data['format'] ) ) {
					$format = sanitize_text_field( $field_data['format'] );
					if ( in_array( $format, \FreeFormCertificate\Reregistration\CustomFieldRepository::VALIDATION_FORMATS, true ) ) {
						$rules['format'] = $format;
						if ( 'custom_regex' === $format ) {
							$rules['custom_regex']         = $field_data['custom_regex'] ?? '';
							$rules['custom_regex_message'] = sanitize_text_field( $field_data['custom_regex_message'] ?? '' );
						}
					}
				}

				$data = array(
					'audience_id'       => $audience_id,
					'field_label'       => $label,
					'field_key'         => sanitize_key( $field_data['key'] ?? '' ),
					'field_type'        => sanitize_text_field( $field_data['type'] ?? 'text' ),
					'field_group'       => sanitize_text_field( $field_data['group'] ?? '' ),
					'field_profile_key' => isset( $field_data['profile_key'] ) && '' !== $field_data['profile_key']
						? sanitize_key( (string) $field_data['profile_key'] )
						: null,
					'field_mask'        => isset( $field_data['mask'] ) && '' !== $field_data['mask']
						? sanitize_text_field( (string) $field_data['mask'] )
						: null,
					'is_sensitive'      => ! empty( $field_data['is_sensitive'] ) ? 1 : 0,
					'field_options'     => ! empty( $options ) ? $options : null,
					'validation_rules'  => ! empty( $rules ) ? $rules : null,
					'sort_order'        => $index,
					'is_required'       => ! empty( $field_data['is_required'] ) ? 1 : 0,
					'is_active'         => isset( $field_data['is_active'] ) ? (int) $field_data['is_active'] : 1,
				);

				// Standard fields are locked: admin can only toggle
				// active/label/group/sort/required, PLUS the field's option
				// lists (select choices / dependent_select groups). Type, key,
				// mask, profile_key, etc. stay immutable for standard fields.
				if ( ! $is_new ) {
					$existing = \FreeFormCertificate\Reregistration\CustomFieldRepository::get_by_id( (int) $field_id );
					if ( $existing && isset( $existing->field_source ) && 'standard' === $existing->field_source ) {
						$allowed = array(
							'field_label',
							'field_group',
							'sort_order',
							'is_required',
							'is_active',
						);
						// Unlock option-list editing — but only when the payload
						// actually carries options. Empty options never overwrite,
						// so a bulk save whose row had no (or an unpopulated)
						// options editor can't null an existing list.
						if ( null !== $data['field_options'] ) {
							$data['field_options'] = $this->preserve_dependent_labels( $existing, $data['field_options'] );
							$allowed[]             = 'field_options';
						}
						$data = array_intersect_key( $data, array_flip( $allowed ) );
					}
				}

				if ( $is_new ) {
					// New fields are always marked as custom; standard fields.
					// are only ever created via the seeder.
					$data['field_source'] = 'custom';
					$new_id               = \FreeFormCertificate\Reregistration\CustomFieldRepository::create( $data );
					if ( $new_id ) {
						$saved_ids[] = $new_id;
					} else {
						$errors[] = sprintf(
							/* translators: %s: field label */
							__( 'Failed to create field "%s".', 'ffcertificate' ),
							$label
						);
					}
				} else {
					$result = \FreeFormCertificate\Reregistration\CustomFieldRepository::update( (int) $field_id, $data );
					if ( false !== $result ) {
						$saved_ids[] = (int) $field_id;
					} else {
						$errors[] = sprintf(
							/* translators: %s: field label */
							__( 'Failed to update field "%s".', 'ffcertificate' ),
							$label
						);
					}
				}
			}

			if ( ! empty( $errors ) ) {
				wp_send_json_error(
					array(
						'message'   => implode( ' ', $errors ),
						'saved_ids' => $saved_ids,
					)
				);
			}

			wp_send_json_success(
				array(
					'message'   => __( 'Custom fields saved successfully.', 'ffcertificate' ),
					'saved_ids' => $saved_ids,
				)
			);
		} catch ( \Throwable $e ) {
			$this->handle_ajax_exception( $e );
		}
	}

	/**
	 * AJAX: Replicate a parent audience's standard-field option lists
	 * (departments / unions / work schedules…) to all its descendants.
	 *
	 * @return void
	 */
	public function ajax_replicate_field_options(): void {
		try {
			$this->verify_ajax_nonce( 'ffc_admin_nonce' );
			$this->check_ajax_permission( 'ffc_manage_custom_fields' );

			$audience_id = $this->get_post_int( 'audience_id' );
			if ( ! $audience_id ) {
				wp_send_json_error( array( 'message' => __( 'Invalid data.', 'ffcertificate' ) ) );
			}

			$audience = AudienceReader::get_by_id( $audience_id );
			if ( ! $audience ) {
				wp_send_json_error( array( 'message' => __( 'Audience not found.', 'ffcertificate' ) ) );
			}

			if ( ! class_exists( '\FreeFormCertificate\Reregistration\ReregistrationStandardFieldsSeeder' ) ) {
				wp_send_json_error( array( 'message' => __( 'Reregistration module unavailable.', 'ffcertificate' ) ) );
			}

			$updated = \FreeFormCertificate\Reregistration\ReregistrationStandardFieldsSeeder::replicate_field_options_to_descendants( $audience_id );

			wp_send_json_success(
				array(
					/* translators: %d: number of descendant fields updated */
					'message' => sprintf( _n( 'Replicated to %d field across descendants.', 'Replicated to %d fields across descendants.', $updated, 'ffcertificate' ), $updated ),
					'updated' => $updated,
				)
			);
		} catch ( \Throwable $e ) {
			$this->handle_ajax_exception( $e );
		}
	}

	/**
	 * Sanitize a dependent_select groups map (division => list of sectors).
	 *
	 * Every key and leaf runs through `sanitize_text_field`; empty division
	 * names are dropped and sectors are de-duplicated.
	 *
	 * @param array<int|string, mixed> $raw Decoded groups payload.
	 * @return array<string, array<string>>
	 */
	private function sanitize_dependent_groups( array $raw ): array {
		$clean = array();
		foreach ( $raw as $division => $sectors ) {
			$name = sanitize_text_field( (string) $division );
			if ( '' === $name || ! is_array( $sectors ) ) {
				continue;
			}
			$list = array();
			foreach ( $sectors as $sector ) {
				$sector_name = sanitize_text_field( (string) $sector );
				if ( '' !== $sector_name && ! in_array( $sector_name, $list, true ) ) {
					$list[] = $sector_name;
				}
			}
			$clean[ $name ] = $list;
		}
		return $clean;
	}

	/**
	 * Carry over a dependent_select field's display labels
	 * (`parent_label` / `child_label`) from its existing field_options when
	 * the incoming payload only updates `groups`. The editor edits the map,
	 * not the labels, so they'd otherwise be lost on save.
	 *
	 * @param object               $existing Existing field row.
	 * @param array<string, mixed> $options  New options being written.
	 * @return array<string, mixed>
	 */
	private function preserve_dependent_labels( object $existing, array $options ): array {
		if ( ! isset( $options['groups'] ) ) {
			return $options;
		}
		$existing_raw  = $existing->field_options ?? null;
		$existing_opts = is_string( $existing_raw ) ? json_decode( $existing_raw, true ) : $existing_raw;
		if ( is_array( $existing_opts ) ) {
			foreach ( array( 'parent_label', 'child_label' ) as $label_key ) {
				if ( isset( $existing_opts[ $label_key ] ) && ! isset( $options[ $label_key ] ) ) {
					$options[ $label_key ] = $existing_opts[ $label_key ];
				}
			}
		}
		return $options;
	}

	/**
	 * AJAX: Delete a custom field
	 *
	 * @since 4.11.0
	 * @return void
	 */
	public function ajax_delete_custom_field(): void {
		try {
			$this->verify_ajax_nonce( 'ffc_admin_nonce' );
			// Deleting a field definition is destructive — gated by the dedicated
			// delete cap (GAP E), not the broader manage cap.
			$this->check_ajax_permission( 'ffc_delete_custom_fields' );

			$field_id = $this->get_post_int( 'field_id' );
			if ( ! $field_id ) {
				wp_send_json_error( array( 'message' => __( 'Invalid field ID.', 'ffcertificate' ) ) );
			}

			$field = \FreeFormCertificate\Reregistration\CustomFieldRepository::get_by_id( $field_id );
			if ( ! $field ) {
				wp_send_json_error( array( 'message' => __( 'Field not found.', 'ffcertificate' ) ) );
			}

			// Standard fields cannot be deleted, only deactivated.
			if ( isset( $field->field_source ) && 'standard' === $field->field_source ) {
				wp_send_json_error(
					array(
						'message' => __( 'Standard fields cannot be deleted. Deactivate instead.', 'ffcertificate' ),
					)
				);
			}

			$result = \FreeFormCertificate\Reregistration\CustomFieldRepository::delete( $field_id );
			if ( ! $result ) {
				wp_send_json_error( array( 'message' => __( 'Failed to delete field.', 'ffcertificate' ) ) );
			}

			wp_send_json_success( array( 'message' => __( 'Field deleted successfully.', 'ffcertificate' ) ) );
		} catch ( \Throwable $e ) {
			$this->handle_ajax_exception( $e );
		}
	}

	/**
	 * AJAX: Remove user permission from a schedule
	 *
	 * @return void
	 */
	public function ajax_remove_user_permission(): void {
		try {
			$this->verify_ajax_nonce( 'ffc_schedule_permissions', '_wpnonce' );
			$this->check_ajax_permission();

			$schedule_id = $this->get_post_int( 'schedule_id' );
			$user_id     = $this->get_post_int( 'user_id' );

			if ( ! $schedule_id || ! $user_id ) {
				wp_send_json_error( array( 'message' => __( 'Missing required parameters.', 'ffcertificate' ) ) );
			}

			$result = AudienceScheduleRepository::remove_user_permissions( $schedule_id, $user_id );

			if ( ! $result ) {
				wp_send_json_error( array( 'message' => __( 'Error removing user access.', 'ffcertificate' ) ) );
			}

			wp_send_json_success();
		} catch ( \Throwable $e ) {
			$this->handle_ajax_exception( $e );
		}
	}
}
